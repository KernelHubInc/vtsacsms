<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

enum ScopeType: string
{
    case Tenant = 'tenant';
    case Organization = 'organization';
    case Site = 'site';
    case Location = 'location';
    case AssetGroup = 'asset_group';
    case Warehouse = 'warehouse';
    case SupportQueue = 'support_queue';
    case Finance = 'finance';
}
