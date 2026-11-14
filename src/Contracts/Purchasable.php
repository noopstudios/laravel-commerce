<?php

namespace Noop\Commerce\Contracts;

interface Purchasable
{
    /**
     * Attributes of the order line, for $order->addItem($product, 2).
     *
     * @return array<string, mixed>
     */
    public function toOrderItem(): array;
}
