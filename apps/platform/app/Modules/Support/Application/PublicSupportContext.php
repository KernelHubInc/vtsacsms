<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\PublicSupportTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Closure;

final readonly class PublicSupportContext
{
    public function run(string $correlationId, Closure $operation): mixed
    {
        return app(CurrentTenant::class)->run(new TenantContext(app(PublicSupportTenant::class)->id(), ActorType::Platform, null, $correlationId), $operation);
    }
}
