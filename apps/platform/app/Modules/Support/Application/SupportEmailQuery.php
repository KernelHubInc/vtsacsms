<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\Identity\Application\VerifiedDriverEmailQuery;
use App\Modules\Support\Domain\Models\SupportTicket;

final readonly class SupportEmailQuery
{
    /** @return array{ticket_number:string,priority:string}|null */
    public function escalation(string $ticketId): ?array
    {
        $ticket = SupportTicket::query()->select(['ticket_number', 'priority'])->find($ticketId);

        return $ticket === null ? null : ['ticket_number' => (string) $ticket->ticket_number, 'priority' => (string) $ticket->priority];
    }

    /** @return array{email:string,ticket_number:string,subject:string,body:string,status:string}|null */
    public function message(string $ticketId, ?string $messageId): ?array
    {
        $ticket = SupportTicket::query()->find($ticketId);
        if ($ticket === null) {
            return null;
        }
        $email = $ticket->requester_user_id === null
            ? ($ticket->contact_verified_at !== null && ($ticket->contact_expires_at === null || $ticket->contact_expires_at->isFuture()) ? $ticket->contact_email : null)
            : app(VerifiedDriverEmailQuery::class)->forUserId((int) $ticket->requester_user_id);
        if (! is_string($email) || $email === '') {
            return null;
        }
        $body = 'We have received your support request. Our team will review it and respond by email. Keep your ticket reference for follow-up.';
        if ($messageId !== null) {
            $message = $ticket->messages()->whereKey($messageId)->where('is_internal', false)->first();
            if ($message === null) {
                return null;
            }
            $body = (string) $message->body;
        }

        return ['email' => $email, 'ticket_number' => (string) $ticket->ticket_number, 'subject' => (string) $ticket->subject,
            'body' => $body, 'status' => (string) $ticket->status];
    }
}
