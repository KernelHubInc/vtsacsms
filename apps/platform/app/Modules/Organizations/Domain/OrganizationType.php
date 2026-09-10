<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

enum OrganizationType: string
{
    case Platform = 'platform';
    case ChargePointOperator = 'charge_point_operator';
    case SiteHost = 'site_host';
    case Fleet = 'fleet';
    case Vendor = 'vendor';
    case ServiceContractor = 'service_contractor';
}
