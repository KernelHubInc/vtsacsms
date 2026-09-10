<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

enum PaymentIntentState: string
{
    case Created = 'created';
    case RequiresPaymentMethod = 'requires_payment_method';
    case AuthorizationPending = 'authorization_pending';
    case RequiresAction = 'requires_action';
    case Authorized = 'authorized';
    case CapturePending = 'capture_pending';
    case PartiallyCaptured = 'partially_captured';
    case Captured = 'captured';
    case CancelPending = 'cancel_pending';
    case Canceled = 'canceled';
    case Failed = 'failed';
    case Expired = 'expired';
    case RefundPending = 'refund_pending';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
}
