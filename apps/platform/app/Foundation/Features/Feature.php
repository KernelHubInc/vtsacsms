<?php

declare(strict_types=1);

namespace App\Foundation\Features;

enum Feature: string
{
    case Ocpp = 'ocpp';
    case RemoteCharging = 'remote_charging';
    case RealPayments = 'real_payments';
    case Settlements = 'settlements';
    case Ocpi = 'ocpi';
    case StoreLocator = 'store_locator';
    case Inventory = 'inventory';
    case Maintenance = 'maintenance';
    case Procurement = 'procurement';
    case DemoMode = 'demo_mode';
    case SimulatedCharging = 'simulated_charging';
    case SimulatedPayments = 'simulated_payments';

    public function label(): string
    {
        return match ($this) {
            self::Ocpp => 'OCPP connectivity',
            self::RemoteCharging => 'Remote charging',
            self::RealPayments => 'Real payments',
            self::Settlements => 'Real settlements',
            self::Ocpi => 'OCPI roaming',
            self::StoreLocator => 'Store locator',
            self::Inventory => 'Inventory',
            self::Maintenance => 'Maintenance',
            self::Procurement => 'Procurement',
            self::DemoMode => 'Demo mode',
            self::SimulatedCharging => 'Simulated charging',
            self::SimulatedPayments => 'Simulated payments',
        };
    }
}
