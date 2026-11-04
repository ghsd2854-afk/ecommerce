<?php

return [

   'host' => env('INVENTORY_GRPC_HOST', 'inventory:50051'),

    'timeout_ms' => (int) env('INVENTORY_GRPC_TIMEOUT_MS', 2000),

    'simulate_order_save_failure' => (bool) env('SIMULATE_ORDER_SAVE_FAILURE', false),

];
