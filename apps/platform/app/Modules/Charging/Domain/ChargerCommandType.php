<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain;

use App\Modules\Organizations\Domain\PermissionKey;

enum ChargerCommandType: string
{
    case RemoteStart = 'remote_start';
    case RemoteStop = 'remote_stop';
    case UnlockConnector = 'unlock_connector';
    case RestartCharger = 'restart_charger';

    public function isStationControl(): bool
    {
        return in_array($this, [self::UnlockConnector, self::RestartCharger], true);
    }

    public function permission(): PermissionKey
    {
        return match ($this) {
            self::UnlockConnector => PermissionKey::ChargingConnectorUnlock,
            self::RestartCharger => PermissionKey::ChargingStationRestart,
            default => PermissionKey::ChargingRemoteCommand,
        };
    }
}
