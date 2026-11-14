<?php

namespace Noop\Commerce\Enums;

enum BillingFlow: string
{
    /** Circuit A: the invoice-receipt is issued once paid. */
    case PayFirst = 'pay_first';
    /** Circuit B: the invoice is issued first, receipts follow each payment. */
    case InvoiceFirst = 'invoice_first';

    public function getLabel(): string
    {
        return match ($this) {
            self::PayFirst => __('Pay first (invoice-receipt)'),
            self::InvoiceFirst => __('Invoice first (invoice and receipts)'),
        };
    }
}
