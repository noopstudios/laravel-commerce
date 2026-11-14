<?php

namespace Noop\Commerce\Filament\Resources\Payments\Pages;

use Filament\Resources\Pages\ListRecords;
use Noop\Commerce\Filament\Resources\Payments\PaymentResource;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;
}
