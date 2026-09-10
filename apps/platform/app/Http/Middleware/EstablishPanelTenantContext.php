<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Application\PanelAccessService;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EstablishPanelTenantContext
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private PanelAccessService $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $panelId = Filament::getCurrentPanel()?->getId();

        if (! $user instanceof User || ! is_string($panelId)) {
            throw new AuthorizationException;
        }

        $eligibleTenant = $this->access->tenantIdFor($user, $panelId);
        $selectedTenant = (string) $request->session()->get("filament.{$panelId}.tenant_id", '');
        $tenantId = $selectedTenant !== '' && $selectedTenant === $eligibleTenant
            ? $selectedTenant
            : $eligibleTenant;

        if ($tenantId === null || $user->public_id === null) {
            throw new AuthorizationException;
        }

        $request->session()->put("filament.{$panelId}.tenant_id", $tenantId);
        $this->currentTenant->establish(new TenantContext(
            tenantId: $tenantId,
            actorType: ActorType::Human,
            actorId: $user->public_id,
            correlationId: (string) $request->attributes->get('correlation_id'),
        ));

        // Livewire's persistent middleware pipeline returns before component hydration finishes.
        if (request()->hasHeader('X-Livewire')) {
            return $next($request);
        }

        try {
            return $next($request);
        } finally {
            $this->currentTenant->clear();
        }
    }
}
