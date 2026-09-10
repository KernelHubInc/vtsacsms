<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

enum MembershipStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
}
