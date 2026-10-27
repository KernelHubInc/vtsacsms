<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use DomainException;

final readonly class PortalReportAccess
{
    public function __construct(
        private AuthorizationService $authorization,
        private AccessibleSitesQuery $sites,
    ) {}

    /** @return array<string, string> */
    public static function titles(): array
    {
        return [
            'sessions' => 'Charging sessions',
            'assets' => 'Sites and charging stations',
            'finance' => 'Payment reconciliation',
            'vehicles' => 'Vehicle register',
            'vehicle_sessions' => 'Vehicle charging details',
            'vehicle_usage' => 'Vehicle usage summary',
            'vehicle_models' => 'Brand and model comparison',
        ];
    }

    public function canExport(User $user, string $type): bool
    {
        if (! array_key_exists($type, self::titles())) {
            return false;
        }

        foreach ($this->permissions($type) as $permission) {
            if (! $this->authorization->holdsAnywhere($user, $permission)) {
                return false;
            }
        }

        return true;
    }

    public function assertCanExport(User $user, string $type): void
    {
        if (! $this->canExport($user, $type)) {
            throw new DomainException('You are not authorized to export this report.');
        }
    }

    /** @return list<string> */
    public function siteIds(User $user, string $type): array
    {
        $this->assertCanExport($user, $type);

        $query = $this->sites->for($user, PermissionKey::ReportingExport);
        foreach ($this->permissions($type) as $permission) {
            $query->whereIn('sites.id', $this->sites->for($user, $permission)->select('id'));
        }

        return array_values($query->orderBy('sites.id')->pluck('sites.id')->map(strval(...))->all());
    }

    public function scopeFingerprint(User $user, string $type): string
    {
        $scope = implode('|', $this->siteIds($user, $type));
        if (self::isVehicleReport($type)) {
            $scope .= $this->canReadWholeRegister($user, $type) ? '|tenant-register' : '|site-register';
        }

        return hash('sha256', $scope);
    }

    public static function isVehicleReport(string $type): bool
    {
        return in_array($type, ['vehicles', 'vehicle_sessions', 'vehicle_usage', 'vehicle_models'], true);
    }

    public function canReadWholeRegister(User $user, string $type): bool
    {
        foreach ($this->permissions($type) as $permission) {
            if (! $this->authorization->allows($user, $permission)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<PermissionKey> */
    private function permissions(string $type): array
    {
        return array_values(array_unique([
            PermissionKey::ReportingView, PermissionKey::ReportingExport, $this->dataPermission($type),
            ...(self::isVehicleReport($type) ? [PermissionKey::AssetView] : []),
        ], SORT_REGULAR));
    }

    private function dataPermission(string $type): PermissionKey
    {
        return match ($type) {
            'sessions', 'vehicles', 'vehicle_sessions', 'vehicle_usage', 'vehicle_models' => PermissionKey::ChargingSessionView,
            'assets' => PermissionKey::LocationView,
            'finance' => PermissionKey::PaymentView,
            default => throw new DomainException('Unsupported report export type.'),
        };
    }
}
