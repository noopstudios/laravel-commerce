<?php

namespace Noop\Commerce\Enums;

enum PaymentSource: string
{
    case Eupago = 'eupago';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Eupago => 'EuPago',
            self::Manual => __('Recorded by hand'),
        };
    }
}
