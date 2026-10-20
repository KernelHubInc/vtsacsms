<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

enum ChargingAccountRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case BillingManager = 'billing_manager';
    case FleetManager = 'fleet_manager';
    case Driver = 'driver';

    /** @return list<PermissionKey> */
    public function permissions(): array
    {
        $view = [PermissionKey::ChargingAccountView];

        return [...$view, ...match ($this) {
            self::Owner => [PermissionKey::ChargingMembersManage, PermissionKey::ChargingVehiclesManage,
                PermissionKey::ChargingWalletView, PermissionKey::ChargingWalletFund, PermissionKey::ChargingLimitsManage,
                PermissionKey::ChargingAccountCharge, PermissionKey::ChargingHistoryView, PermissionKey::ChargingSessionControl],
            self::Admin => [PermissionKey::ChargingMembersManage, PermissionKey::ChargingVehiclesManage,
                PermissionKey::ChargingAccountCharge, PermissionKey::ChargingHistoryView, PermissionKey::ChargingSessionControl],
            self::BillingManager => [PermissionKey::ChargingWalletView, PermissionKey::ChargingWalletFund,
                PermissionKey::ChargingHistoryView],
            self::FleetManager => [PermissionKey::ChargingVehiclesManage, PermissionKey::ChargingAccountCharge,
                PermissionKey::ChargingHistoryView, PermissionKey::ChargingSessionControl],
            self::Driver => [PermissionKey::ChargingAccountCharge],
        }];
    }
}
