<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Notifications\Application\SupportEmailRequests;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Support\Domain\Models\SupportTicketMessage;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SupportTicketService
{
    public function __construct(private AuditRecorder $audit, private CurrentTenant $tenant) {}

    public function requestAccountDeletion(User $verifiedRequester): void
    {
        if ($verifiedRequester->public_id === null || $this->tenant->get()->actorId !== $verifiedRequester->public_id) {
            throw new AuthorizationException;
        }

        $existing = SupportTicket::query()
            ->where('requester_user_id', $verifiedRequester->getKey())
            ->where('category', SupportTicket::ACCOUNT_DELETION)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->exists();
        if ($existing) {
            return;
        }

        $ticket = SupportTicket::query()->create([
            'ticket_number' => 'PRIV-'.Str::ulid(),
            'requester_user_id' => $verifiedRequester->getKey(),
            'category' => SupportTicket::ACCOUNT_DELETION,
            'subject' => 'Account deletion request',
            'description' => 'The requester confirmed ownership of the account email. Review this request through the approved privacy process before taking any action on the account or its data.',
        ]);
        $this->audit->record(new AuditEntry('support.account_deletion.requested', 'support_ticket', (string) $ticket->getKey(), AuditResult::Succeeded));
    }

    public function respond(User $actor, SupportTicket $ticket, string $body, bool $isInternal, ?string $idempotencyKey = null): SupportTicketMessage
    {
        Gate::forUser($actor)->authorize('update', $ticket);

        Validator::make(['body' => $body, 'key' => $idempotencyKey], ['body' => ['required', 'string', 'max:5000'], 'key' => ['nullable', 'string', 'max:160']])->validate();

        return DB::transaction(function () use ($actor, $ticket, $body, $isInternal, $idempotencyKey): SupportTicketMessage {
            $ticket = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $ticket);
            if (! $isInternal && config('support.enabled') && app(SupportEmailQuery::class)->message((string) $ticket->getKey(), null) === null) {
                throw ValidationException::withMessages(['body' => 'No verified email is available. Save an internal note or ask the customer to submit a verified request.']);
            }
            $key = $idempotencyKey ?? (string) Str::ulid();
            $message = SupportTicketMessage::query()->firstOrCreate(['support_ticket_id' => $ticket->getKey(), 'idempotency_key' => $key], [
                'tenant_id' => $ticket->tenant_id,
                'support_ticket_id' => $ticket->getKey(),
                'author_user_id' => $actor->getKey(),
                'body' => $body,
                'is_internal' => $isInternal,
            ]);

            if (! $message->wasRecentlyCreated) {
                abort_unless((int) $message->author_user_id === (int) $actor->getKey() && $message->body === $body && $message->is_internal === $isInternal, 409);

                return $message;
            }
            if (! $isInternal && config('support.enabled')) {
                app(SupportEmailRequests::class)->enqueue((string) $ticket->getKey(), (string) $message->getKey());
                $this->guardReopening($ticket);
                $ticket->forceFill(['open_fingerprint' => $ticket->request_fingerprint, 'status' => 'pending_customer', 'resolved_at' => null, 'contact_expires_at' => null]);
                $this->saveState($ticket);
            } else {
                $ticket->touch();
            }
            $this->audit->record(new AuditEntry(
                'support.ticket.responded',
                'support_ticket',
                (string) $ticket->getKey(),
                AuditResult::Succeeded,
                after: ['message_id' => (string) $message->getKey(), 'is_internal' => $isInternal],
            ));

            return $message;
        });
    }

    public function escalate(User $actor, SupportTicket $ticket): SupportTicket
    {
        Gate::forUser($actor)->authorize('update', $ticket);

        return DB::transaction(function () use ($actor, $ticket): SupportTicket {
            $ticket = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $ticket);
            $this->guardReopening($ticket);
            $before = ['status' => $ticket->status, 'escalation_level' => $ticket->escalation_level];
            $ticket->forceFill([
                'status' => 'escalated',
                'open_fingerprint' => $ticket->request_fingerprint,
                'resolved_at' => null,
                'contact_expires_at' => null,
                'escalation_level' => min(99, (int) $ticket->escalation_level + 1),
            ]);
            $this->saveState($ticket);

            $this->audit->record(new AuditEntry(
                'support.ticket.escalated',
                'support_ticket',
                (string) $ticket->getKey(),
                AuditResult::Succeeded,
                before: $before,
                after: ['status' => $ticket->status, 'escalation_level' => $ticket->escalation_level],
            ));

            return $ticket->refresh();
        });
    }

    public function changeStatus(User $actor, SupportTicket $ticket, string $status): void
    {
        Gate::forUser($actor)->authorize('update', $ticket);
        abort_if($ticket->category === SupportTicket::ACCOUNT_DELETION, 422);
        Validator::make(compact('status'), ['status' => ['required', 'in:open,in_progress,pending_customer,resolved,closed']])->validate();
        DB::transaction(function () use ($actor, $ticket, $status): void {
            $ticket = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $ticket);
            $before = (string) $ticket->status;
            $resolved = in_array($status, ['resolved', 'closed'], true);
            if (! $resolved) {
                $this->guardReopening($ticket);
            }
            $ticket->forceFill(['open_fingerprint' => $resolved ? null : $ticket->request_fingerprint, 'status' => $status, 'resolved_at' => $resolved ? ($ticket->resolved_at ?? now('UTC')) : null,
                'contact_expires_at' => $resolved && $ticket->contact_email !== null
                    ? ($ticket->contact_expires_at ?? now('UTC')->addDays((int) config('support.contact_retention_days', 90))) : null]);
            $this->saveState($ticket);
            $this->audit->record(new AuditEntry('support.ticket.status_changed', 'support_ticket', (string) $ticket->getKey(), AuditResult::Succeeded,
                before: ['status' => $before], after: ['status' => $status]));
        });
    }

    private function saveState(SupportTicket $ticket): void
    {
        try {
            $ticket->save();
        } catch (UniqueConstraintViolationException) {
            // Another request can open the same issue after the preliminary reopening check.
            throw ValidationException::withMessages(['status' => 'An identical request is already open. Continue that conversation instead.']);
        }
    }

    private function guardReopening(SupportTicket $ticket): void
    {
        if ($ticket->request_fingerprint !== null && SupportTicket::query()->where('open_fingerprint', $ticket->request_fingerprint)->whereKeyNot($ticket->getKey())->exists()) {
            throw ValidationException::withMessages(['status' => 'An identical request is already open. Continue that conversation instead.']);
        }
    }

    public function resolveAccountDeletion(User $actor, SupportTicket $ticket): void
    {
        Gate::forUser($actor)->authorize('update', $ticket);
        abort_unless($ticket->category === SupportTicket::ACCOUNT_DELETION, 404);

        DB::transaction(function () use ($ticket): void {
            $locked = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['resolved', 'closed'], true)) {
                return;
            }
            $locked->forceFill(['status' => 'resolved', 'resolved_at' => now('UTC')])->save();
            $this->audit->record(new AuditEntry('support.account_deletion.resolved', 'support_ticket', (string) $locked->getKey(), AuditResult::Succeeded));
        });
    }
}
