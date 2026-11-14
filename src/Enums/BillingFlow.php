<?php

namespace Noop\Commerce\Enums;

enum BillingFlow: string
{
    /** Circuit A: the invoice-receipt is issued once paid. */
    case PayFirst = 'pay_first';
    /** Circuit B: the invoice is issued first, receipts follow each payment. */
    case InvoiceFirst = 'invoice_first';
}
