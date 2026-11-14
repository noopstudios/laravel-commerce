<?php

namespace Noop\Commerce\Filament\Resources\Payments\Pages;

use Filament\Resources\Pages\ViewRecord;
use Noop\Commerce\Filament\Actions\ApplyPaymentAction;
use Noop\Commerce\Filament\Actions\ResolveReviewAction;
use Noop\Commerce\Filament\Actions\VoidPaymentAction;
use Noop\Commerce\Filament\Resources\Payments\PaymentResource;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ApplyPaymentAction::make(),
            ResolveReviewAction::make(),
            VoidPaymentAction::make(),
        ];
    }
}
