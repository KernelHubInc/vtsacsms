<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Support\Domain\Models\SupportTicketMessage;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

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

    public function respond(User $actor, SupportTicket $ticket, string $body, bool $isInternal): SupportTicketMessage
    {
        Gate::forUser($actor)->authorize('update', $ticket);

        return DB::transaction(function () use ($actor, $ticket, $body, $isInternal): SupportTicketMessage {
            $message = SupportTicketMessage::query()->create([
                'tenant_id' => $ticket->tenant_id,
                'support_ticket_id' => $ticket->getKey(),
                'author_user_id' => $actor->getKey(),
                'body' => $body,
                'is_internal' => $isInternal,
            ]);

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

        return DB::transaction(function () use ($ticket): SupportTicket {
            $before = ['status' => $ticket->status, 'escalation_level' => $ticket->escalation_level];
            $ticket->forceFill([
                'status' => 'escalated',
                'escalation_level' => min(99, (int) $ticket->escalation_level + 1),
            ])->save();

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
