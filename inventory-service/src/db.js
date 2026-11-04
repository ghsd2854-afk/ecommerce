'use strict';

const { Pool } = require('pg');
const seed = require('./seed-data.json');

// The inventory service owns its own database (PostgreSQL).
// The main application never connects to it directly: it only talks to this service over gRPC.
const pool = new Pool({
  host: process.env.DB_HOST || 'inventory-db',
  port: Number(process.env.DB_PORT || 5432),
  database: process.env.DB_DATABASE || 'inventory',
  user: process.env.DB_USERNAME || 'inventory',
  password: process.env.DB_PASSWORD || 'secret',
  max: Number(process.env.DB_POOL_SIZE || 10),
});

const SCHEMA = `
  CREATE TABLE IF NOT EXISTS stock (
    product_id  BIGINT PRIMARY KEY,
    quantity    INTEGER NOT NULL CHECK (quantity >= 0),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
  );

  CREATE TABLE IF NOT EXISTS reservations (
    id           VARCHAR(64) PRIMARY KEY,
    status       VARCHAR(16) NOT NULL CHECK (status IN ('reserved', 'released')),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    released_at  TIMESTAMPTZ NULL
  );

  CREATE TABLE IF NOT EXISTS reservation_items (
    reservation_id  VARCHAR(64) NOT NULL REFERENCES reservations(id) ON DELETE CASCADE,
    product_id      BIGINT NOT NULL,
    quantity        INTEGER NOT NULL CHECK (quantity > 0),
    PRIMARY KEY (reservation_id, product_id)
  );
`;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function waitForDatabase(retries = 30) {
  for (let attempt = 1; attempt <= retries; attempt++) {
    try {
      await pool.query('SELECT 1');
      return;
    } catch (err) {
      console.log(`[db] not ready yet (attempt ${attempt}/${retries}): ${err.message}`);
      await sleep(2000);
    }
  }
  throw new Error('Inventory database is not reachable.');
}

async function migrateAndSeed() {
  await pool.query(SCHEMA);

  const { rows } = await pool.query('SELECT COUNT(*)::int AS count FROM stock');
  if (rows[0].count > 0) {
    return;
  }

  // Initial stock for the products created by the Laravel DatabaseSeeder (ids 1..8).
  for (const [productId, quantity] of Object.entries(seed)) {
    await pool.query(
      'INSERT INTO stock (product_id, quantity) VALUES ($1, $2) ON CONFLICT (product_id) DO NOTHING',
      [Number(productId), quantity],
    );
  }
  console.log(`[db] seeded stock for ${Object.keys(seed).length} products`);
}

module.exports = { pool, waitForDatabase, migrateAndSeed };
