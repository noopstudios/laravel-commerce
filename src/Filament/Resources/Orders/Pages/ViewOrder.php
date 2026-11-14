<?php

namespace Noop\Commerce\Filament\Resources\Orders\Pages;

use Filament\Resources\Pages\ViewRecord;
use Noop\Commerce\Filament\Actions\CancelOrderAction;
use Noop\Commerce\Filament\Actions\IssueDocumentsAction;
use Noop\Commerce\Filament\Actions\MbwayAction;
use Noop\Commerce\Filament\Actions\MultibancoAction;
use Noop\Commerce\Filament\Actions\ProformaAction;
use Noop\Commerce\Filament\Actions\RecordPaymentAction;
use Noop\Commerce\Filament\Resources\Orders\OrderResource;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RecordPaymentAction::make(),
            MultibancoAction::make(),
            MbwayAction::make(),
            IssueDocumentsAction::make(),
            ProformaAction::make(),
            CancelOrderAction::make(),
        ];
    }
}
