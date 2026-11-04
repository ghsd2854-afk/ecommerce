'use strict';

/**
 * Race-condition proof (Requirement 2).
 * Puts exactly ONE unit of a product in stock, then fires many order requests
 * at the main application AT THE SAME MOMENT. Expected: exactly one 201, all others 409.
 *
 * Run (from the project folder):
 *   docker compose exec inventory node scripts/race-test.js
 *   docker compose exec inventory node scripts/race-test.js --product=8 --requests=20
 */
const { pool } = require('../src/db');

const args = Object.fromEntries(
  process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')),
);
const API = process.env.API_URL || 'http://nginx/api/v1';
const PRODUCT_ID = Number(args.product || 8);
const REQUESTS = Number(args.requests || 10);
const CUSTOMERS = [
  { email: 'customer@shop.test', password: 'Customer12345' },
  { email: 'customer2@shop.test', password: 'Customer12345' },
];

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function login({ email, password }) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    const res = await fetch(`${API}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ email, password }),
    });
    if (res.status === 429) {
      const wait = Number(res.headers.get('retry-after') || 60);
      console.log(`Login rate limit reached, waiting ${wait}s...`);
      await sleep(wait * 1000);
      continue;
    }
    const body = await res.json();
    if (!res.ok) throw new Error(`Login failed for ${email}: ${res.status} ${body.message}`);
    return body.access_token;
  }
  throw new Error('Could not log in.');
}

async function stockOf(productId) {
  const { rows } = await pool.query('SELECT quantity FROM stock WHERE product_id = $1', [productId]);
  return rows.length ? rows[0].quantity : 0;
}

async function main() {
  console.log(`API: ${API}`);
  const tokens = [];
  for (const customer of CUSTOMERS) tokens.push(await login(customer));

  await pool.query(
    `INSERT INTO stock (product_id, quantity) VALUES ($1, 1)
     ON CONFLICT (product_id) DO UPDATE SET quantity = 1, updated_at = now()`,
    [PRODUCT_ID],
  );
  console.log(`Stock of product ${PRODUCT_ID} set to 1 (the last unit).`);
  console.log(`Sending ${REQUESTS} order requests at the same moment...\n`);

  const requests = Array.from({ length: REQUESTS }, (_, i) => {
    const started = Date.now();
    return fetch(`${API}/orders`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${tokens[i % tokens.length]}`,
      },
      body: JSON.stringify({ items: [{ product_id: PRODUCT_ID, quantity: 1 }] }),
    }).then(async (res) => ({
      n: i + 1,
      customer: CUSTOMERS[i % CUSTOMERS.length].email,
      status: res.status,
      message: (await res.json().catch(() => ({}))).message || 'Order created',
      ms: Date.now() - started,
    }));
  });

  const results = await Promise.all(requests);
  console.table(results);

  const created = results.filter((r) => r.status === 201).length;
  const rejected = results.filter((r) => r.status === 409).length;
  const finalStock = await stockOf(PRODUCT_ID);

  console.log(`Created: ${created} | Rejected (409 insufficient stock): ${rejected} | Other: ${REQUESTS - created - rejected}`);
  console.log(`Final stock of product ${PRODUCT_ID}: ${finalStock}`);
  console.log(created === 1 && finalStock === 0
    ? '\nPASS: exactly one order got the last unit, stock never went below zero.'
    : '\nFAIL: unexpected result.');
}

main()
  .catch((err) => {
    console.error(err.message);
    process.exitCode = 1;
  })
  .finally(() => pool.end());
