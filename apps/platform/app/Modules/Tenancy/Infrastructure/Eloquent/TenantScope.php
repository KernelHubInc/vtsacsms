<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Eloquent;

use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** @implements Scope<Model> */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentTenant::class)->getOrNull();

        if ($context === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->tenantId);
    }
}
