<?php

use Noop\Commerce\Models\Order;

return [

    // Prefix of the order number: PREFIX-<id>.
    'order_prefix' => env('COMMERCE_ORDER_PREFIX', 'ORD'),

    'models' => [
        'order' => Order::class,
    ],

    // Gate abilities of the panel resources; null never grants.
    'permissions' => [
        'view' => 'view orders',
        'manage' => 'manage orders',
    ],

];
