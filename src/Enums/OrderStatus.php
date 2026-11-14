<?php

namespace Noop\Commerce\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case PendingPayment = 'pending_payment';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
