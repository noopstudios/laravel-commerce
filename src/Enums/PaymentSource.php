<?php

namespace Noop\Commerce\Enums;

enum PaymentSource: string
{
    case Eupago = 'eupago';
    case Manual = 'manual';
}
