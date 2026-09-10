<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Payments\Application\Providers\PaymentProviderRegistry;
use App\Modules\Payments\Application\Providers\VerifiedWebhook;
use App\Modules\Payments\Domain\Models\FinanceReview;
use App\Modules\Payments\Domain\Models\PaymentIntent;
use App\Modules\Payments\Domain\Models\PaymentProviderConfig;
use App\Modules\Payments\Domain\Models\PaymentWebhookReceipt;
use App\Modules\Payments\Domain\PaymentIntentState;
use Illuminate\Support\Facades\DB;

final readonly class PaymentWebhookService
{
    public function __construct(private PaymentProviderRegistry $providers, private OutboxRecorder $outbox) {}

    public function handle(PaymentProviderConfig $configuration, string $rawBody, string $signature): WebhookHandlingResult
    {
        $event = $this->providers->for($configuration)->verifyWebhook($rawBody, $signature);
        $existing = PaymentWebhookReceipt::query()->where('provider_config_id', $configuration->getKey())
            ->where('provider_event_id', $event->eventId)->first();
        if ($existing !== null) {
            return new WebhookHandlingResult('duplicate');
        }

        return DB::transaction(function () use ($configuration, $rawBody, $event): WebhookHandlingResult {
            $receipt = PaymentWebhookReceipt::query()->create([
                'provider_config_id' => $configuration->getKey(), 'provider_event_id' => $event->eventId,
                'event_type' => $event->eventType, 'provider_resource_reference' => $event->resourceReference,
                'provider_created_at' => $event->createdAt, 'body_hash' => hash('sha256', $rawBody),
                'normalized_payload' => $event->normalizedPayload, 'outcome' => 'received',
            ]);
            if ($event->resourceReference === null) {
                $receipt->forceFill(['outcome' => 'ignored', 'ignored_reason' => 'resource_reference_missing', 'processed_at' => now('UTC')])->save();

                return new WebhookHandlingResult('ignored');
            }
            $intent = PaymentIntent::query()->where('provider_config_id', $configuration->getKey())
                ->where('provider_intent_reference', $event->resourceReference)->lockForUpdate()->first();
            if ($intent === null) {
                $receipt->forceFill(['outcome' => 'review_required', 'ignored_reason' => 'payment_intent_not_found', 'processed_at' => now('UTC')])->save();
                FinanceReview::query()->create(['source_type' => 'payment_webhook', 'source_id' => $receipt->getKey(),
                    'reason_code' => 'unmatched_webhook', 'status' => 'open', 'severity' => 'warning',
                    'evidence' => ['event_type' => $event->eventType, 'provider_resource_reference' => $event->resourceReference], 'opened_at' => now('UTC')]);

                return new WebhookHandlingResult('review_required');
            }
            $before = $intent->state;
            $this->applyMonotonicStatus($intent, $event);
            $receipt->forceFill(['outcome' => $before === $intent->state ? 'processed_no_change' : 'processed', 'processed_at' => now('UTC')])->save();
            $this->outbox->record('payments.intent.status_changed.v1', 'payment_intent', (string) $intent->getKey(), [
                'state' => $intent->state->value, 'provider_event_id' => $event->eventId,
                'provider_status' => $event->normalizedPayload['status'] ?? null,
                'amount_authorized_minor' => (int) $intent->amount_authorized_minor,
                'amount_captured_minor' => (int) $intent->amount_captured_minor,
                'currency' => $intent->currency,
            ]);

            return new WebhookHandlingResult('processed', (string) $intent->getKey());
        });
    }

    private function applyMonotonicStatus(PaymentIntent $intent, VerifiedWebhook $event): void
    {
        $status = $event->normalizedPayload['status'] ?? null;
        $amount = is_int($event->normalizedPayload['amount_minor'] ?? null) ? $event->normalizedPayload['amount_minor'] : null;
        $terminal = in_array($intent->state, [PaymentIntentState::Refunded, PaymentIntentState::Canceled], true);
        if ($terminal) {
            return;
        }
        $changes = ['aggregate_version' => $intent->aggregate_version + 1];
        if (in_array($status, ['authorized', 'requires_capture'], true)) {
            if ((int) $intent->amount_captured_minor > 0) {
                return;
            }
            $changes['state'] = PaymentIntentState::Authorized;
            $changes['amount_authorized_minor'] = max((int) $intent->amount_authorized_minor, $amount ?? (int) $intent->amount_requested_minor);
            $changes['authorized_at'] = $intent->authorized_at ?? now('UTC');
        } elseif (in_array($status, ['captured', 'succeeded'], true)) {
            $captured = max((int) $intent->amount_captured_minor, $amount ?? (int) $intent->amount_requested_minor);
            $changes['amount_authorized_minor'] = max((int) $intent->amount_authorized_minor, $captured);
            $changes['amount_captured_minor'] = $captured;
            $changes['state'] = $captured >= (int) $intent->amount_authorized_minor ? PaymentIntentState::Captured : PaymentIntentState::PartiallyCaptured;
            $changes['captured_at'] = $intent->captured_at ?? now('UTC');
        } elseif ($status === 'partially_refunded') {
            $changes['state'] = PaymentIntentState::PartiallyRefunded;
        } elseif ($status === 'refunded') {
            $changes['state'] = PaymentIntentState::Refunded;
            $changes['amount_refunded_minor'] = max((int) $intent->amount_refunded_minor, $amount ?? (int) $intent->amount_captured_minor);
        } elseif ($status === 'requires_action') {
            if (in_array($intent->state, [PaymentIntentState::Created, PaymentIntentState::AuthorizationPending], true)) {
                $changes['state'] = PaymentIntentState::RequiresAction;
            } else {
                return;
            }
        } elseif ($status === 'canceled') {
            if ((int) $intent->amount_captured_minor > 0) {
                return;
            }
            $changes['state'] = PaymentIntentState::Canceled;
            $changes['canceled_at'] = now('UTC');
        } else {
            return;
        }
        $intent->forceFill($changes)->save();
    }
}
