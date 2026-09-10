<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Eloquent;

use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Domain\Exceptions\TenantMismatch;
use Illuminate\Database\Eloquent\Model;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $tenantId = app(CurrentTenant::class)->get()->tenantId;
            $modelTenantId = $model->getAttribute('tenant_id');

            if ($modelTenantId === null) {
                $model->setAttribute('tenant_id', $tenantId);

                return;
            }

            if (! hash_equals($tenantId, (string) $modelTenantId)) {
                throw new TenantMismatch;
            }
        });

        static::updating(function (Model $model): void {
            self::assertModelTenant($model);

            if ($model->isDirty('tenant_id')) {
                throw new TenantMismatch;
            }
        });

        static::deleting(self::assertModelTenant(...));
    }

    private static function assertModelTenant(Model $model): void
    {
        $tenantId = app(CurrentTenant::class)->get()->tenantId;

        if (! hash_equals($tenantId, (string) $model->getAttribute('tenant_id'))) {
            throw new TenantMismatch;
        }
    }
}
