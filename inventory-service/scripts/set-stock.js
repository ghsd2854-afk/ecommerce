'use strict';

// Admin helper (not part of the gRPC contract): set or show stock in the inventory database.
//   node scripts/set-stock.js                 -> show all stock
//   node scripts/set-stock.js <productId> <quantity>
const { pool } = require('../src/db');

async function main() {
  const [productId, quantity] = process.argv.slice(2).map(Number);

  if (process.argv.length > 2) {
    if (!Number.isInteger(productId) || productId <= 0 || !Number.isInteger(quantity) || quantity < 0) {
      console.error('Usage: node scripts/set-stock.js <productId> <quantity>');
      process.exit(1);
    }
    await pool.query(
      `INSERT INTO stock (product_id, quantity) VALUES ($1, $2)
       ON CONFLICT (product_id) DO UPDATE SET quantity = EXCLUDED.quantity, updated_at = now()`,
      [productId, quantity],
    );
    console.log(`Product ${productId}: stock set to ${quantity}`);
  }

  const { rows } = await pool.query('SELECT product_id, quantity FROM stock ORDER BY product_id');
  console.table(rows.map((r) => ({ product_id: Number(r.product_id), quantity: r.quantity })));
}

main()
  .catch((err) => {
    console.error(err.message);
    process.exitCode = 1;
  })
  .finally(() => pool.end());
