<?php

namespace Noop\Commerce\Filament\Resources\Orders\Pages;

use Filament\Resources\Pages\ListRecords;
use Noop\Commerce\Filament\Resources\Orders\OrderResource;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
}
