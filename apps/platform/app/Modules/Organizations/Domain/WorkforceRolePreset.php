<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

enum WorkforceRolePreset: string
{
    case PlatformAdministrator = 'preset-platform-administrator';
    case OperatorAdministrator = 'preset-operator-administrator';
    case StationManager = 'preset-station-manager';
    case StationViewer = 'preset-station-viewer';
    case FinanceViewer = 'preset-finance-viewer';

    public function label(): string
    {
        return match ($this) {
            self::PlatformAdministrator => 'Platform Administrator',
            self::OperatorAdministrator => 'Operator Administrator',
            self::StationManager => 'Station Manager',
            self::StationViewer => 'Station Viewer',
            self::FinanceViewer => 'Finance Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PlatformAdministrator => 'Onboard operators and manage staff access for this tenant.',
            self::OperatorAdministrator => 'Manage staff access, station operations and revenue for an operator.',
            self::StationManager => 'Manage stations and operating hours, monitor sessions and report maintenance issues.',
            self::StationViewer => 'View station status and charging activity.',
            self::FinanceViewer => 'View charging revenue and permitted finance records.',
        };
    }

    public function supportsScope(ScopeType $scope): bool
    {
        return match ($this) {
            self::PlatformAdministrator => $scope === ScopeType::Tenant,
            self::OperatorAdministrator => $scope === ScopeType::Organization,
            self::StationManager, self::StationViewer => in_array($scope, [ScopeType::Organization, ScopeType::Site], true),
            self::FinanceViewer => in_array($scope, [ScopeType::Tenant, ScopeType::Organization, ScopeType::Site], true),
        };
    }

    /** @return list<PermissionKey> */
    public function permissions(): array
    {
        $stationView = [PermissionKey::OperatorPanelAccess, PermissionKey::LocationView, PermissionKey::AssetView,
            PermissionKey::ChargingSessionView, PermissionKey::ReportingView];
        $stationManage = [...$stationView, PermissionKey::LocationManage, PermissionKey::AssetManage,
            PermissionKey::MaintenanceView, PermissionKey::MaintenanceDispatch];
        $finance = [...$stationView, PermissionKey::PaymentView, PermissionKey::BillingView, PermissionKey::SettlementView];
        $people = [PermissionKey::MembershipView, PermissionKey::MembershipManage, PermissionKey::InvitationManage,
            PermissionKey::RoleView, PermissionKey::RoleAssign];

        return match ($this) {
            self::PlatformAdministrator => [PermissionKey::PlatformPanelAccess, PermissionKey::OrganizationView,
                PermissionKey::OrganizationManage, ...$people, ...$stationManage,
                PermissionKey::PaymentView, PermissionKey::BillingView, PermissionKey::SettlementView],
            self::OperatorAdministrator => [...$people, ...$stationManage, PermissionKey::PaymentView, PermissionKey::BillingView, PermissionKey::SettlementView],
            self::StationManager => $stationManage,
            self::StationViewer => $stationView,
            self::FinanceViewer => $finance,
        };
    }
}
