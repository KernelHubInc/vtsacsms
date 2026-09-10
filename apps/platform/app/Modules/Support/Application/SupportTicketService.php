<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Support\Domain\Models\SupportTicketMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class SupportTicketService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditRecorder $audit,
    ) {}

    public function respond(User $actor, SupportTicket $ticket, string $body, bool $isInternal): SupportTicketMessage
    {
        $this->authorize($actor);

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
        $this->authorize($actor);

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

    private function authorize(User $actor): void
    {
        if (! $this->authorization->holdsAnywhere($actor, PermissionKey::SupportManage)) {
            throw new AuthorizationException;
        }
    }
}
