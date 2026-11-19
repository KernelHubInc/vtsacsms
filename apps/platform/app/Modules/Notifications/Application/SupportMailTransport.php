<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

final readonly class SupportMailTransport
{
    public function ready(): bool
    {
        return app()->environment('testing') || $this->external((string) config('mail.default'));
    }

    /** @param list<string> $visited */
    private function external(string $mailer, array $visited = []): bool
    {
        if (in_array($mailer, $visited, true)) {
            return false;
        }
        $transport = config("mail.mailers.{$mailer}.transport");
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = config("mail.mailers.{$mailer}.mailers", []);
            if (! is_array($children) || $children === []) {
                return false;
            }
            foreach ($children as $child) {
                if (! is_string($child) || ! $this->external($child, [...$visited, $mailer])) {
                    return false;
                }
            }

            return true;
        }

        return is_string($transport) && in_array($transport, ['smtp', 'ses', 'ses-v2', 'postmark', 'resend'], true);
    }
}
