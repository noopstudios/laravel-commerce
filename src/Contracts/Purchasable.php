<?php

namespace Noop\Commerce\Contracts;

interface Purchasable
{
    /**
     * Attributes of the order line, for $order->addItem($product, 2); a `quantity` key is the default when no quantity is passed.
     *
     * @return array<string, mixed>
     */
    public function toOrderItem(): array;
}
