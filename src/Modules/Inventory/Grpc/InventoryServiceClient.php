<?php
// gRPC client stub for inventory.v1.InventoryService (same shape grpc_php_plugin generates).
// Source contract: proto/inventory/v1/inventory.proto

namespace Modules\Inventory\Grpc;

class InventoryServiceClient extends \Grpc\BaseStub
{
    /**
     * @param string $hostname hostname:port of the inventory service
     * @param array $opts channel options (e.g. 'credentials')
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null)
    {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * How many units of this product are left?
     *
     * @return \Grpc\UnaryCall<\Modules\Inventory\Grpc\GetStockResponse>
     */
    public function GetStock(GetStockRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest(
            '/inventory.v1.InventoryService/GetStock',
            $argument,
            ['\Modules\Inventory\Grpc\GetStockResponse', 'decode'],
            $metadata,
            $options
        );
    }

    /**
     * Reserve the quantities of all the items of one order (all-or-nothing).
     *
     * @return \Grpc\UnaryCall<\Modules\Inventory\Grpc\ReserveStockResponse>
     */
    public function ReserveStock(ReserveStockRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest(
            '/inventory.v1.InventoryService/ReserveStock',
            $argument,
            ['\Modules\Inventory\Grpc\ReserveStockResponse', 'decode'],
            $metadata,
            $options
        );
    }

    /**
     * Give back what a reservation took.
     *
     * @return \Grpc\UnaryCall<\Modules\Inventory\Grpc\ReleaseStockResponse>
     */
    public function ReleaseStock(ReleaseStockRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest(
            '/inventory.v1.InventoryService/ReleaseStock',
            $argument,
            ['\Modules\Inventory\Grpc\ReleaseStockResponse', 'decode'],
            $metadata,
            $options
        );
    }
}
