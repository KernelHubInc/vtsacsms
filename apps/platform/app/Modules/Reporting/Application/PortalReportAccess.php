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

        $query = $this->sites->for($user, PermissionKey::ReportingExport)
            ->whereIn('sites.id', $this->sites->for($user, PermissionKey::ReportingView)->select('id'))
            ->whereIn('sites.id', $this->sites->for($user, $this->dataPermission($type))->select('id'));

        return array_values($query->orderBy('sites.id')->pluck('sites.id')->map(strval(...))->all());
    }

    public function scopeFingerprint(User $user, string $type): string
    {
        return hash('sha256', implode('|', $this->siteIds($user, $type)));
    }

    /** @return list<PermissionKey> */
    private function permissions(string $type): array
    {
        return [PermissionKey::ReportingView, PermissionKey::ReportingExport, $this->dataPermission($type)];
    }

    private function dataPermission(string $type): PermissionKey
    {
        return match ($type) {
            'sessions' => PermissionKey::ChargingSessionView,
            'assets' => PermissionKey::LocationView,
            'finance' => PermissionKey::PaymentView,
            default => throw new DomainException('Unsupported report export type.'),
        };
    }
}
