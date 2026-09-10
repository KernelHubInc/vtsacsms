<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use Filament\Actions\EditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class AuditedEditAction
{
    public static function make(string $auditAction, string $targetType): EditAction
    {
        return EditAction::make()
            ->using(function (Model $record, array $data) use ($auditAction, $targetType): Model {
                return DB::transaction(function () use ($auditAction, $data, $record, $targetType): Model {
                    $before = $record->attributesToArray();
                    $record->fill($data)->save();

                    app(AuditRecorder::class)->record(new AuditEntry(
                        $auditAction,
                        $targetType,
                        (string) $record->getKey(),
                        AuditResult::Succeeded,
                        before: $before,
                        after: $record->fresh()->attributesToArray(),
                    ));

                    return $record;
                });
            });
    }
}
