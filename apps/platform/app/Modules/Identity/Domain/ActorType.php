<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum ActorType: string
{
    case Human = 'human';
    case Service = 'service';
    case Platform = 'platform';
}
