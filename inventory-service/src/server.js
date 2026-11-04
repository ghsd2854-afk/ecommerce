'use strict';

const path = require('path');
const grpc = require('@grpc/grpc-js');
const protoLoader = require('@grpc/proto-loader');
const { waitForDatabase, migrateAndSeed, pool } = require('./db');
const inventory = require('./inventory');

// The same .proto file the Laravel app was generated from (mounted from /proto).
const PROTO_PATH = process.env.PROTO_PATH
  || path.resolve(__dirname, '../../proto/inventory/v1/inventory.proto');
const PORT = process.env.GRPC_PORT || '50051';

// Demo only: makes every call slow, to show what the app does when the service does not answer in time.
const SIMULATED_DELAY_MS = Number(process.env.SIMULATED_DELAY_MS || 0);

const packageDefinition = protoLoader.loadSync(PROTO_PATH, {
  keepCase: true,
  longs: Number,
  enums: String,
  defaults: true,
  oneofs: true,
});
const { inventory: { v1 } } = grpc.loadPackageDefinition(packageDefinition);

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Wraps a handler: optional delay, logging, and mapping errors to gRPC status codes. */
function handler(name, fn) {
  return async (call, callback) => {
    const started = Date.now();
    try {
      if (SIMULATED_DELAY_MS > 0) {
        await sleep(SIMULATED_DELAY_MS);
      }
      if (call.cancelled) {
        console.log(`[grpc] ${name} skipped: caller already gave up (deadline exceeded)`);
        return callback({ code: grpc.status.CANCELLED, details: 'Caller cancelled the request.' });
      }

      const response = await fn(call);
      console.log(`[grpc] ${name} ${JSON.stringify(call.request)} -> ${JSON.stringify(response)} (${Date.now() - started}ms)`);
      callback(null, response);
    } catch (err) {
      if (err instanceof inventory.ValidationError) {
        console.log(`[grpc] ${name} invalid argument: ${err.message}`);
        return callback({ code: grpc.status.INVALID_ARGUMENT, details: err.message });
      }
      console.error(`[grpc] ${name} failed:`, err);
      callback({ code: grpc.status.INTERNAL, details: 'Inventory service internal error.' });
    }
  };
}

const service = {
  GetStock: handler('GetStock', async (call) => {
    const quantity = await inventory.getStock(call.request.product_id);
    return { product_id: call.request.product_id, available_quantity: quantity };
  }),

  ReserveStock: handler('ReserveStock', async (call) => {
    const { reservation_id: reservationId, items } = call.request;
    const result = await inventory.reserve(reservationId, items, () => call.cancelled);
    return { reservation_id: reservationId, ...result };
  }),

  ReleaseStock: handler('ReleaseStock', async (call) => {
    return inventory.release(call.request.reservation_id);
  }),
};

async function main() {
  await waitForDatabase();
  await migrateAndSeed();

  const server = new grpc.Server();
  server.addService(v1.InventoryService.service, service);

  server.bindAsync(`0.0.0.0:${PORT}`, grpc.ServerCredentials.createInsecure(), (err, port) => {
    if (err) {
      console.error('[grpc] failed to start:', err);
      process.exit(1);
    }
    console.log(`[grpc] inventory service listening on ${port}` +
      (SIMULATED_DELAY_MS ? ` (simulated delay ${SIMULATED_DELAY_MS}ms)` : ''));
  });

  const shutdown = () => {
    console.log('[grpc] shutting down...');
    server.tryShutdown(() => pool.end().finally(() => process.exit(0)));
  };
  process.on('SIGTERM', shutdown);
  process.on('SIGINT', shutdown);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
