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

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::PendingPayment => __('Pending payment'),
            self::PartiallyPaid => __('Partially paid'),
            self::Paid => __('Paid'),
            self::Expired => __('Expired'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft, self::Cancelled, self::Expired => 'gray',
            self::PendingPayment => 'warning',
            self::PartiallyPaid => 'info',
            self::Paid => 'success',
        };
    }
}
