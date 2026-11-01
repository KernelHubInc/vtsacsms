<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Models\User;
use App\Modules\Charging\Application\ChargingBudgets;
use App\Modules\Integrations\Application\MapConfigurationResolver;
use App\Modules\Integrations\Application\MapSettingsData;
use App\Modules\Integrations\Application\MapSettingsManager;
use App\Modules\Integrations\Domain\MapProvider;
use App\Modules\Integrations\Domain\MapSurface;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Payments\Application\Qr\TopupLimits;
use App\Modules\Tariffs\Application\PlatformFees;
use App\Modules\Tenancy\Application\CurrentTenant;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use UnitEnum;

final class SystemSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'Platform governance';

    protected static ?string $navigationLabel = 'System settings';

    protected string $view = 'filament.platform.pages.system-settings';

    /** @var array<string, mixed> */
    public array $mapSettings = [];

    public string $topupMinimum = '';

    public string $topupMaximum = '';

    /** @var list<array{amount:string,enabled:bool}> */
    public array $topupPresets = [];

    public bool $topupAllowCustom = true;

    /** @var array<string, mixed> */
    public array $budgetSettings = [];

    /** @var array<string, mixed> */
    public array $feeSettings = [];

    #[Locked]
    public string $tenantId = '';

    public function mount(MapConfigurationResolver $maps, TopupLimits $limits, CurrentTenant $tenant): void
    {
        $this->tenantId = $tenant->get()->tenantId;
        $budget = app(ChargingBudgets::class)->current();
        $this->budgetSettings = [
            'minimum' => $this->money($budget['minimum_minor']), 'default' => $this->money($budget['default_minor']),
            'maximum' => $this->money($budget['maximum_minor']), 'presets' => array_map($this->money(...), $budget['presets']),
            'allow_custom_amount' => $budget['allow_custom_amount'],
        ];
        $fee = app(PlatformFees::class)->current();
        $this->feeSettings = ['enabled' => $fee['enabled'], 'amount' => $this->money($fee['amount_minor']),
            'waiver_threshold' => $this->money($fee['waiver_threshold_minor']),
            'tax_rate' => $fee['tax_rate_basis_points'] === null ? '' : $this->money($fee['tax_rate_basis_points']),
            'beneficiary' => $fee['beneficiary'] ?? '', 'effective_at' => ''];
        $amounts = $limits->settings();
        $this->topupMinimum = $this->money($amounts['minimum_minor']);
        $this->topupMaximum = $this->money($amounts['maximum_minor']);
        $this->topupPresets = array_map(fn (array $preset): array => [
            'amount' => $this->money($preset['amount_minor']), 'enabled' => $preset['enabled'],
        ], $amounts['presets']);
        $this->topupAllowCustom = $amounts['allow_custom_amount'];
        $default = $maps->forSurface(MapSurface::Default);
        $raw = $maps->rawSettings() ?? [];

        $this->mapSettings = [
            'default_provider' => $raw['default_provider'] ?? $default->requestedProvider->value,
            'admin_provider' => $raw['admin_provider'] ?? $maps->forSurface(MapSurface::Admin)->requestedProvider->value,
            'operator_provider' => $raw['operator_provider'] ?? $maps->forSurface(MapSurface::Operator)->requestedProvider->value,
            'user_web_provider' => $raw['user_web_provider'] ?? $maps->forSurface(MapSurface::UserWeb)->requestedProvider->value,
            'public_provider' => $raw['public_provider'] ?? $maps->forSurface(MapSurface::Public)->requestedProvider->value,
            'mobile_provider' => $raw['mobile_provider'] ?? $maps->forSurface(MapSurface::Mobile)->requestedProvider->value,
            'default_latitude' => $raw['default_latitude'] ?? $default->defaultLatitude,
            'default_longitude' => $raw['default_longitude'] ?? $default->defaultLongitude,
            'default_zoom' => $raw['default_zoom'] ?? $default->defaultZoom,
            'minimum_zoom' => $raw['minimum_zoom'] ?? $default->minimumZoom,
            'maximum_zoom' => $raw['maximum_zoom'] ?? $default->maximumZoom,
            'tile_url_template' => $raw['tile_url_template'] ?? $default->tileUrlTemplate,
            'tile_attribution' => $raw['tile_attribution'] ?? $default->tileAttribution,
            'tile_maximum_native_zoom' => $raw['tile_maximum_native_zoom'] ?? $default->tileMaximumNativeZoom,
            'clustering_enabled' => $raw['clustering_enabled'] ?? $default->clusteringEnabled,
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::TenantSettingsView);
    }

    public function saveMapSettings(MapSettingsManager $settings): void
    {
        abort_unless($this->canManage(), 403);

        $validated = $this->validate([
            'mapSettings.default_provider' => ['required', 'in:openstreetmap,google'],
            'mapSettings.admin_provider' => ['required', 'in:openstreetmap,google'],
            'mapSettings.operator_provider' => ['required', 'in:openstreetmap,google'],
            'mapSettings.user_web_provider' => ['required', 'in:openstreetmap,google'],
            'mapSettings.public_provider' => ['required', 'in:openstreetmap,google'],
            'mapSettings.mobile_provider' => ['required', 'in:openstreetmap,google'],
            'mapSettings.default_latitude' => ['required', 'numeric', 'between:-90,90'],
            'mapSettings.default_longitude' => ['required', 'numeric', 'between:-180,180'],
            'mapSettings.default_zoom' => ['required', 'integer', 'between:0,22'],
            'mapSettings.minimum_zoom' => ['required', 'integer', 'between:0,22'],
            'mapSettings.maximum_zoom' => ['required', 'integer', 'between:0,22', 'gte:mapSettings.minimum_zoom'],
            'mapSettings.tile_url_template' => ['required', 'string', 'max:1000'],
            'mapSettings.tile_attribution' => ['required', 'string', 'max:500'],
            'mapSettings.tile_maximum_native_zoom' => ['required', 'integer', 'between:0,22'],
            'mapSettings.clustering_enabled' => ['required', 'boolean'],
        ]);

        $settings->update(MapSettingsData::fromArray($validated['mapSettings']));

        Notification::make()
            ->title('Map settings saved')
            ->body('The provider cache was refreshed. Missing Google configuration will continue to fall back safely.')
            ->success()
            ->send();
    }

    public function saveTopupLimits(TopupLimits $limits, CurrentTenant $tenant): void
    {
        abort_unless($this->canManage(), 403);
        abort_unless($this->tenantId === $tenant->get()->tenantId, 409);

        $this->validate([
            'topupMinimum' => ['required', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'],
            'topupMaximum' => ['required', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'],
            'topupPresets' => ['present', 'array', 'list'],
            'topupPresets.*' => ['required', 'array:amount,enabled'],
            'topupPresets.*.amount' => ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'],
            'topupPresets.*.enabled' => ['required', 'boolean'],
            'topupAllowCustom' => ['required', 'boolean'],
        ], [
            'topupPresets.*.amount.required' => 'Enter a PHP amount or remove this preset.',
            'topupPresets.*.amount.regex' => 'Enter a PHP amount with no more than two decimal places.',
        ]);

        /** @var User $user */
        $user = auth()->user();
        $limits->update($user, $this->minor($this->topupMinimum), $this->minor($this->topupMaximum),
            array_map(fn (array $preset): array => ['amount_minor' => $this->minor($preset['amount']), 'enabled' => (bool) $preset['enabled']], $this->topupPresets),
            $this->topupAllowCustom);

        Notification::make()
            ->title('Wallet top-up settings saved')
            ->body('Android and the browser app will load these settings when Wallet refreshes or Add money opens. Existing payment requests keep their original amount.')
            ->success()
            ->send();
    }

    public function addTopupPreset(): void
    {
        abort_unless($this->canManage(), 403);
        $this->topupPresets[] = ['amount' => '', 'enabled' => true];
    }

    public function removeTopupPreset(int $index): void
    {
        abort_unless($this->canManage(), 403);
        $presets = $this->topupPresets;
        unset($presets[$index]);
        $this->topupPresets = array_values($presets);
        $this->resetValidation();
    }

    public function moveTopupPreset(int $index, int $direction): void
    {
        abort_unless($this->canManage(), 403);
        if (! in_array($direction, [-1, 1], true) || ! isset($this->topupPresets[$index], $this->topupPresets[$index + $direction])) {
            return;
        }
        [$this->topupPresets[$index], $this->topupPresets[$index + $direction]] = [$this->topupPresets[$index + $direction], $this->topupPresets[$index]];
        $this->resetValidation();
    }

    public function saveChargingBudgets(ChargingBudgets $budgets, CurrentTenant $tenant): void
    {
        abort_unless($this->canManage(), 403);
        abort_unless($this->tenantId === $tenant->get()->tenantId, 409);
        $money = ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'];
        $this->validate([
            'budgetSettings.minimum' => $money, 'budgetSettings.default' => $money, 'budgetSettings.maximum' => $money,
            'budgetSettings.presets' => ['present', 'array', 'list'], 'budgetSettings.presets.*' => $money,
            'budgetSettings.allow_custom_amount' => ['required', 'boolean'],
        ]);
        /** @var User $user */
        $user = auth()->user();
        $budgets->update($user, [
            'minimum_minor' => $this->minor($this->budgetSettings['minimum']),
            'default_minor' => $this->minor($this->budgetSettings['default']),
            'maximum_minor' => $this->minor($this->budgetSettings['maximum']),
            'presets' => array_map($this->minor(...), $this->budgetSettings['presets']),
            'allow_custom_amount' => $this->budgetSettings['allow_custom_amount'],
        ]);
        Notification::make()->title('Charging budgets saved')->body('New quotes use these settings. Existing sessions keep their reserved budget.')->success()->send();
    }

    public function addBudgetPreset(): void
    {
        abort_unless($this->canManage(), 403);
        $this->budgetSettings['presets'][] = '';
    }

    public function removeBudgetPreset(int $index): void
    {
        abort_unless($this->canManage(), 403);
        unset($this->budgetSettings['presets'][$index]);
        $this->budgetSettings['presets'] = array_values($this->budgetSettings['presets']);
        $this->resetValidation();
    }

    public function publishPlatformFee(PlatformFees $fees, CurrentTenant $tenant): void
    {
        abort_unless($this->canManage(), 403);
        abort_unless($this->tenantId === $tenant->get()->tenantId, 409);
        $money = ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'];
        $this->validate([
            'feeSettings.enabled' => ['required', 'boolean'], 'feeSettings.amount' => $money,
            'feeSettings.waiver_threshold' => $money,
            'feeSettings.tax_rate' => ['nullable', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/'],
            'feeSettings.beneficiary' => ['nullable', 'string', 'max:120'],
            'feeSettings.effective_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ]);
        /** @var User $user */
        $user = auth()->user();
        $fees->publish($user, [
            'enabled' => $this->feeSettings['enabled'], 'amount_minor' => $this->minor($this->feeSettings['amount']),
            'waiver_threshold_minor' => $this->minor($this->feeSettings['waiver_threshold']),
            'tax_rate_basis_points' => empty($this->feeSettings['tax_rate']) && $this->feeSettings['tax_rate'] !== '0'
                ? null : $this->minor($this->feeSettings['tax_rate']),
            'beneficiary' => trim($this->feeSettings['beneficiary']) ?: null,
            'effective_at' => $this->feeSettings['effective_at'] === '' ? now('UTC')->toISOString()
                : CarbonImmutable::parse($this->feeSettings['effective_at'], 'Asia/Manila')->utc()->toISOString(),
        ]);
        Notification::make()->title('Platform fee policy published')->body('The effective date applies to new quotes only. Existing sessions keep their price.')->success()->send();
    }

    private function minor(string $amount): int
    {
        $parts = explode('.', $amount);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function money(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    public function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->allows($user, PermissionKey::TenantSettingsManage);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $maps = app(MapConfigurationResolver::class);
        $admin = $maps->forSurface(MapSurface::Admin);

        return [
            'scheduledFees' => app(PlatformFees::class)->scheduled(),
            'activeFee' => app(PlatformFees::class)->current(),
            'providerOptions' => collect(MapProvider::cases())
                ->mapWithKeys(fn (MapProvider $provider): array => [$provider->value => $provider->label()])
                ->all(),
            'mapStatus' => [
                'google' => $admin->googleBrowserReady ? 'Configured' : 'Not configured',
                'fallback' => $admin->fallbackActive ? 'Provider fallback active' : 'Not active',
                'resolved' => $admin->provider->label(),
            ],
            'settings' => [
                ['name' => 'Application timezone', 'value' => (string) config('app.timezone'), 'source' => 'deployment'],
                ['name' => 'Queue connection', 'value' => (string) config('queue.default'), 'source' => 'deployment'],
                ['name' => 'Private storage disk', 'value' => (string) config('filesystems.default'), 'source' => 'deployment'],
                ['name' => 'Google Maps browser readiness', 'value' => $admin->googleBrowserReady ? 'Configured' : 'Not configured', 'source' => 'secret injection'],
                ['name' => 'OCPP gateway', 'value' => config('services.ocpp_gateway.base_url') ? 'Configured' : 'Not configured', 'source' => 'environment'],
            ],
        ];
    }
}
