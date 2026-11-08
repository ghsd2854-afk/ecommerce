'use strict';

/**
 * Interview demo: shows each scenario of Requirements 1 and 2 against the RUNNING
 * application in a few seconds, with no manual login / logout.
 *
 * Normally started through demo.ps1 / demo.cmd in the project root, e.g.:
 *   .\demo.cmd auth
 * which runs:
 *   docker compose --profile tools run --rm demo node scripts/demo.js auth
 *
 * Scenarios: auth | api | stock | down | slow | savefail | ratelimit
 */
const fs = require('fs');

const API = process.env.API_URL || 'http://nginx/api/v1';
const ROOT = API.replace(/\/api\/v1\/?$/, '');
const CACHE = process.env.DEMO_CACHE || '/cache/tokens.json';

const ACCOUNTS = {
  admin: { email: 'admin@shop.test', password: 'Admin12345' },
  customer: { email: 'customer@shop.test', password: 'Customer12345' },
  customer2: { email: 'customer2@shop.test', password: 'Customer12345' },
};

/* ---------------- output ---------------- */
const color = (code) => (text) => `\x1b[${code}m${text}\x1b[0m`;
const green = color('32');
const red = color('31');
const yellow = color('33');
const cyan = color('36');
const bold = color('1');
const dim = color('2');

let passed = 0;
let failed = 0;

function title(text) {
  console.log('\n' + bold(cyan('=== ' + text + ' ===')));
}

function info(text) {
  console.log(dim('    ' + text));
}

/** Prints one check: expected vs actual HTTP status, plus the API's message. */
function check(label, res, expected, extra = '') {
  const ok = Array.isArray(expected) ? expected.includes(res.status) : res.status === expected;
  if (ok) passed++; else failed++;
  const status = `[${res.status}${res.body && res.body.code ? ' ' + res.body.code : ''}]`;
  const msg = res.body && res.body.message ? ` -> "${res.body.message}"` : '';
  const time = res.ms !== undefined ? dim(` (${res.ms} ms)`) : '';
  console.log(`  ${ok ? green('PASS') : red('FAIL')} ${status.padEnd(28)} ${label}${msg}${time}${extra ? '  ' + extra : ''}`);
  if (!ok) console.log(red(`        expected ${expected}, got ${res.status}`));
  return ok;
}

function assert(label, condition, detail = '') {
  if (condition) passed++; else failed++;
  console.log(`  ${condition ? green('PASS') : red('FAIL')} ${''.padEnd(28)} ${label}${detail ? '  ' + dim(detail) : ''}`);
  return condition;
}

/* ---------------- HTTP ---------------- */
async function call(method, path, { token, body, raw = false } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = `Bearer ${token}`;
  const started = Date.now();
  const response = await fetch((raw ? ROOT : API) + path, {
    method, headers, body: body === undefined ? undefined : JSON.stringify(body),
  });
  const text = await response.text();
  let parsed = null;
  try { parsed = JSON.parse(text); } catch { parsed = null; }
  return { status: response.status, body: parsed, headers: response.headers, ms: Date.now() - started };
}

/* ---------------- tokens (cached so the login rate limit is not used up) ---------------- */
function readCache() {
  try { return JSON.parse(fs.readFileSync(CACHE, 'utf8')); } catch { return {}; }
}

function writeCache(tokens) {
  try { fs.writeFileSync(CACHE, JSON.stringify(tokens)); } catch { /* cache is optional */ }
}

async function login(name) {
  const cache = readCache();
  if (cache[name]) {
    const me = await call('GET', '/auth/me', { token: cache[name] });
    if (me.status === 200) return cache[name];
  }
  for (let attempt = 1; attempt <= 3; attempt++) {
    const res = await call('POST', '/auth/login', { body: ACCOUNTS[name] });
    if (res.status === 200) {
      cache[name] = res.body.access_token;
      writeCache(cache);
      return cache[name];
    }
    if (res.status === 429) {
      const wait = Number(res.headers.get('retry-after') || 60);
      console.log(yellow(`    login rate limit reached (5/min), waiting ${wait}s...`));
      await new Promise((r) => setTimeout(r, wait * 1000));
      continue;
    }
    throw new Error(`Login failed for ${name}: ${res.status} ${res.body && res.body.message}`);
  }
  throw new Error(`Could not log in as ${name}`);
}

async function tokens() {
  return {
    admin: await login('admin'),
    customer: await login('customer'),
    customer2: await login('customer2'),
  };
}

async function stockOf(productId) {
  const res = await call('GET', `/products/${productId}`);
  return res.body && res.body.data && res.body.data.stock ? res.body.data.stock.available_quantity : null;
}

/* ---------------- scenarios ---------------- */
const scenarios = {
  /** Requirement 1: every user only reaches what they are allowed to. */
  async auth() {
    title('Requirement 1 - Authorization (JWT, roles, ownership)');
    const t = await tokens();
    info('Logged in as admin, customer and customer2 (JWT).');

    const me = await call('GET', '/auth/me', { token: t.customer });
    check('customer token identifies the user and role', me, 200, dim(`role=${me.body && me.body.role}`));

    const created = await call('POST', '/orders', { token: t.customer, body: { items: [{ product_id: 1, quantity: 1 }] } });
    if (!check('customer creates an order', created, 201)) return;
    const orderId = created.body.data.id;
    info(`Order #${orderId} belongs to customer.`);

    check('customer reads his own order', await call('GET', `/orders/${orderId}`, { token: t.customer }), 200);
    check('customer2 reads customer\'s order  -> refused', await call('GET', `/orders/${orderId}`, { token: t.customer2 }), 403);

    const list2 = await call('GET', '/orders', { token: t.customer2 });
    assert('customer2 order list does not contain customer\'s order',
      list2.status === 200 && !list2.body.data.some((o) => o.id === orderId));

    check('customer changes an order status  -> refused', await call('PATCH', `/orders/${orderId}/status`, { token: t.customer, body: { status: 'delivered' } }), 403);
    check('customer creates a product  -> refused', await call('POST', '/products', { token: t.customer, body: { name: 'X', price: 1, category: 'X' } }), 403);
    check('customer deletes a product  -> refused', await call('DELETE', '/products/1', { token: t.customer }), 403);

    check('admin reads any order', await call('GET', `/orders/${orderId}`, { token: t.admin }), 200);
    check('admin changes the order status', await call('PATCH', `/orders/${orderId}/status`, { token: t.admin, body: { status: 'confirmed' } }), 200);

    const notes = await call('GET', '/notifications', { token: t.customer });
    assert('customer received a notification about the new status',
      notes.status === 200 && notes.body.data.some((n) => n.message.includes(`#${orderId} is now confirmed`)));

    check('request without a token  -> refused', await call('GET', '/orders'), 401);
    check('request with a forged token  -> refused', await call('GET', '/orders', { token: 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIiwicm9sZSI6ImFkbWluIn0.forged' }), 401);
  },

  /** Requirement 1: REST design, versioning, unified errors, security headers. */
  async api() {
    title('Requirement 1 - REST design, errors and security headers');
    const t = await tokens();

    check('versioned path /api/v1/products', await call('GET', '/products'), 200);
    check('unversioned path /api/products does not exist', await call('GET', '/api/products', { raw: true }), 404);

    const page = await call('GET', '/products?per_page=3&page=1');
    assert('pagination (3 per page)', page.status === 200 && page.body.data.length <= 3 && page.body.meta.last_page >= 1,
      `page ${page.body.meta.current_page}/${page.body.meta.last_page}, total ${page.body.meta.total}`);

    const audio = await call('GET', '/products?category=Audio');
    assert('filter by category = Audio', audio.status === 200 && audio.body.data.every((p) => p.category === 'Audio'),
      audio.body.data.map((p) => p.name).join(', '));

    const invalid = await call('POST', '/orders', { token: t.customer, body: { items: [{ product_id: 999999, quantity: 0 }] } });
    check('validation error', invalid, 422, dim(`fields: ${Object.keys((invalid.body && invalid.body.errors) || {}).join(', ')}`));
    const notFound = await call('GET', '/products/999999');
    check('unknown product', notFound, 404);
    const wrongMethod = await call('PUT', '/orders', { token: t.customer });
    check('wrong HTTP method', wrongMethod, 405);
    const noToken = await call('GET', '/orders');
    check('no token', noToken, 401);
    const forbidden = await call('POST', '/products', { token: t.customer, body: { name: 'X', price: 1, category: 'X' } });
    check('customer creates a product', forbidden, 403);

    title('Requirement 1 - Unified error format {status, code, message}');
    for (const r of [invalid, notFound, wrongMethod, noToken, forbidden]) {
      const b = r.body || {};
      assert(`${r.status}: same shape, body status = HTTP status, no stack trace`,
        b.status === r.status && typeof b.code === 'string' && typeof b.message === 'string' && !('trace' in b),
        JSON.stringify({ status: b.status, code: b.code, message: b.message, ...(b.errors ? { errors: '...' } : {}) }));
    }

    const res = await call('GET', '/products');
    ['x-content-type-options', 'x-frame-options', 'content-security-policy', 'referrer-policy'].forEach((h) =>
      assert(`security header ${h}`, !!res.headers.get(h), res.headers.get(h) || 'missing'));
  },

  /** Requirement 2: stock over gRPC, single all-or-nothing reservation. */
  async stock() {
    title('Requirement 2 - Stock from the inventory service (gRPC)');
    const t = await tokens();

    const details = await call('GET', '/products/8');
    check('product details include stock from the inventory service', details, 200,
      dim(`available_quantity=${details.body && details.body.data.stock.available_quantity}`));

    const s8 = await stockOf(8);
    const tooMany = await call('POST', '/orders', { token: t.customer, body: { items: [{ product_id: 8, quantity: s8 + 1 }] } });
    check(`order ${s8 + 1} units of product 8 (only ${s8} left)  -> rejected`, tooMany, 409);

    title('Requirement 2 - All or nothing (one ReserveStock call for the whole order)');
    const s1 = await stockOf(1);
    const mixed = await call('POST', '/orders', {
      token: t.customer,
      body: { items: [{ product_id: 1, quantity: 1 }, { product_id: 8, quantity: s8 + 1 }] },
    });
    check('order: product 1 (available) + product 8 (not enough)  -> rejected', mixed, 409);
    const s1After = await stockOf(1);
    assert(`product 1 stock unchanged (${s1} -> ${s1After}): nothing was reserved`, s1After === s1);

    const s2 = await stockOf(2);
    if (s2 >= 2) {
      const ok = await call('POST', '/orders', { token: t.customer, body: { items: [{ product_id: 2, quantity: 2 }] } });
      check('order 2 units of product 2  -> accepted', ok, 201);
      const s2After = await stockOf(2);
      assert(`product 2 stock decreased by 2 (${s2} -> ${s2After})`, s2After === s2 - 2);
    } else {
      info(`product 2 has only ${s2} units, skipping the successful-order check`);
    }
  },

  /** Requirement 2: inventory service stopped (demo.ps1 stops it first). */
  async down() {
    title('Requirement 2 - Inventory service STOPPED');
    const t = await tokens();
    const details = await call('GET', '/products/1');
    check('product details still work', details, 200,
      dim(`stock: ${JSON.stringify(details.body && details.body.data.stock)}`));
    const order = await call('POST', '/orders', { token: t.customer, body: { items: [{ product_id: 1, quantity: 1 }] } });
    check('create order  -> clear error, answered immediately', order, 503);
  },

  /** Requirement 2: inventory service too slow (demo.ps1 adds a 5 s delay first). */
  async slow() {
    title('Requirement 2 - Inventory service TOO SLOW (5 s delay, 2 s deadline)');
    const t = await tokens();
    const order = await call('POST', '/orders', { token: t.customer, body: { items: [{ product_id: 3, quantity: 1 }] } });
    check('create order  -> clear error at the deadline', order, 504);
    assert(`user waited ~2 s, not 5 s (${order.ms} ms)`, order.ms < 4000);
  },

  /** Requirement 2: saving fails after the reservation (demo.ps1 switches the simulation on). */
  async savefail() {
    title('Requirement 2 - Saving the order FAILS after the stock was reserved');
    const t = await tokens();
    const before = await stockOf(5);
    const order = await call('POST', '/orders', { token: t.customer, body: { items: [{ product_id: 5, quantity: 3 }] } });
    check('create order  -> clear error', order, 500);
    const after = await stockOf(5);
    assert(`reserved quantity returned to the inventory (stock ${before} -> ${after})`, after === before);
  },

  /** Requirement 1: login rate limiting. */
  async ratelimit() {
    title('Requirement 1 - Login rate limiting (5 attempts / minute)');
    let last;
    for (let i = 1; i <= 6; i++) {
      last = await call('POST', '/auth/login', { body: { email: 'customer@shop.test', password: 'WrongPassword1' } });
      console.log(`    attempt ${i}: [${last.status}] ${last.body && last.body.message}`);
      if (last.status === 429) break;
    }
    check('brute force on customer@shop.test is blocked', last, 429);

    const correct = await call('POST', '/auth/login', { body: ACCOUNTS.customer });
    check('customer@shop.test with the CORRECT password is also refused for 1 minute', correct, 429);

    const other = await call('POST', '/auth/login', { body: ACCOUNTS.customer2 });
    check('another user (customer2) from the same address still logs in', other, 200);
    info('Only the attacked account is blocked (limit per account + address).');
  },
};

async function main() {
  const name = process.argv[2];
  const list = name === 'all' ? ['auth', 'api', 'stock'] : [name];
  if (!list.every((n) => scenarios[n])) {
    console.log(`Usage: node scripts/demo.js <${Object.keys(scenarios).join('|')}|all>`);
    process.exit(2);
  }
  for (const n of list) await scenarios[n]();
  console.log('\n' + bold(`Result: ${green(passed + ' passed')}, ${failed ? red(failed + ' failed') : '0 failed'}`));
  process.exitCode = failed ? 1 : 0;
}

main().catch((err) => {
  console.error(red(err.message));
  process.exitCode = 1;
});
