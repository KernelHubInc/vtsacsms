<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

enum ProviderOperation: string
{
    case HostedCheckout = 'hosted_checkout';
    case Tokenize = 'tokenize';
    case Authorize = 'authorize';
    case IncrementAuthorization = 'increment_authorization';
    case Capture = 'capture';
    case Void = 'void';
    case Refund = 'refund';
    case Retrieve = 'retrieve';
}
