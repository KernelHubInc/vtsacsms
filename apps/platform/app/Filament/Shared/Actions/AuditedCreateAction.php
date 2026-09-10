<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use Filament\Actions\CreateAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class AuditedCreateAction
{
    public static function make(string $auditAction, string $targetType): CreateAction
    {
        return CreateAction::make()
            ->using(function (array $data, string $model) use ($auditAction, $targetType): Model {
                return DB::transaction(function () use ($auditAction, $data, $model, $targetType): Model {
                    /** @var Model $record */
                    $record = $model::query()->create($data);

                    app(AuditRecorder::class)->record(new AuditEntry(
                        $auditAction,
                        $targetType,
                        (string) $record->getKey(),
                        AuditResult::Succeeded,
                        after: $record->attributesToArray(),
                    ));

                    return $record;
                });
            });
    }
}
