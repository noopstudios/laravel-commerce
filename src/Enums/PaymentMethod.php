<?php

namespace Noop\Commerce\Enums;

/**
 * The value is the key used in PaymentData::$method, which the host maps to Moloni payment methods.
 */
enum PaymentMethod: string
{
    case Multibanco = 'multibanco';
    case Mbway = 'mbway';
    case Transfer = 'transfer';
    case Cash = 'cash';

    public function getLabel(): string
    {
        return match ($this) {
            self::Multibanco => 'Multibanco',
            self::Mbway => 'MB WAY',
            self::Transfer => __('Bank transfer'),
            self::Cash => __('Cash'),
        };
    }
}
