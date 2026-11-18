<?php

return [

    // Address of the inventory gRPC service (docker-compose service name + port).
    'host' => env('INVENTORY_GRPC_HOST', 'inventory:50051'),

    // Deadline for every gRPC call, in milliseconds.
    'timeout_ms' => (int) env('INVENTORY_GRPC_TIMEOUT_MS', 2000),

    // DEMO ONLY (works only when APP_ENV=local): make saving the order fail AFTER the stock
    // was reserved, to show that the reserved quantity is returned to the inventory.
    'simulate_order_save_failure' => (bool) env('SIMULATE_ORDER_SAVE_FAILURE', false),

];
