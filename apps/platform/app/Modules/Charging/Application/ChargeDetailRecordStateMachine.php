<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Charging\Domain\ChargeDetailRecordState;
use App\Modules\Charging\Domain\Models\ChargeDetailRecord;
use App\Modules\Integrations\Application\OutboxRecorder;
use DomainException;

final readonly class ChargeDetailRecordStateMachine
{
    public function __construct(
        private StateTransitionRecorder $transitions,
        private OutboxRecorder $outbox,
    ) {}

    /** @param array<string, mixed> $evidence */
    public function transition(
        ChargeDetailRecord $cdr,
        ChargeDetailRecordState $next,
        string $reason,
        array $evidence = [],
    ): void {
        $current = $cdr->state;
        if ($current === $next) {
            return;
        }
        if (! $current->canTransitionTo($next)) {
            throw new DomainException("CDR cannot transition from {$current->value} to {$next->value}.");
        }

        $cdr->forceFill(['state' => $next])->save();
        $this->transitions->record('cdr', (string) $cdr->getKey(), $current->value, $next->value, $reason, $evidence);
        $this->outbox->record(
            'charging.cdr.'.$next->value.'.v1',
            'charge_detail_record',
            (string) $cdr->getKey(),
            ['session_id' => (string) $cdr->session_id, 'state' => $next->value, 'reason' => $reason],
        );
    }
}
