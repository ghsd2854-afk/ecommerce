<?php

namespace Modules\Inventory\Providers;

use App\Providers\ModuleServiceProvider;
use Grpc\ChannelCredentials;
use Modules\Inventory\Contracts\InventoryGateway;
use Modules\Inventory\Grpc\InventoryServiceClient;
use Modules\Inventory\Services\GrpcInventoryGateway;

/**
 * The Inventory module is the app's client of the external inventory service.
 * Other modules only see the InventoryGateway contract; gRPC is an internal detail.
 */
class InventoryServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/Config/inventory.php', 'inventory');

        $this->app->singleton(InventoryGateway::class, function () {
            $client = new InventoryServiceClient(config('inventory.host'), [
                'credentials' => ChannelCredentials::createInsecure(),
            ]);

            return new GrpcInventoryGateway($client, (int) config('inventory.timeout_ms'));
        });
    }
}
