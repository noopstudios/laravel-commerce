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
}
