/**
 * Requirement 3: k6 load test (the same script BEFORE and AFTER the optimizations).
 *
 * Normally started by:  .\demo.cmd perf      (runs it twice: baseline, then optimized)
 * Manually (from the project folder):
 *   docker compose --profile tools run --rm k6 run -e LABEL=after /perf/k6/load-test.js
 *
 * Options (-e NAME=value):  LABEL (name of the result file)  VUS (default 20)  DURATION (default 30s)
 *
 * Traffic mix (what a real shop receives - mostly reads):
 *   30%  product list (random page, sort and category)         public, cached, ETag
 *   10%  product list again with If-None-Match (returning user) public -> 304 when unchanged
 *   10%  categories                                             public, cached
 *   20%  product details (+ live stock over gRPC)               public, cached + live stock
 *   15%  "my orders" of a customer with ~150 orders             private, replica, eager loading
 *   10%  admin: all orders, 50 per page                         N+1 visible without eager loading
 *    5%  admin: sales report of the last 30 days                index + cache
 *
 * Requires the big data:  docker compose exec app php artisan perf:seed
 */
import http from 'k6/http';
import { check } from 'k6';
import { Rate } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://nginx/api/v1';
const LABEL = __ENV.LABEL || 'run';
const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' };
const SORTS = ['newest', 'price_asc', 'price_desc', 'name'];
const RESULTS = __ENV.RESULTS_DIR || '/perf/results';

// Share of "returning visitor" requests answered with 304 Not Modified (no body sent).
const notModified = new Rate('etag_304_rate');

export const options = {
  scenarios: {
    shop: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 20),
      duration: __ENV.DURATION || '30s',
    },
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  thresholds: {
    // A run with errors measures errors, not the application: mark it as failed.
    http_req_failed: ['rate<0.01'],
    // Per-endpoint statistics in the summary (tag "name").
    'http_req_duration{name:product_list}': ['max>=0'],
    'http_req_duration{name:product_list_revalidate}': ['max>=0'],
    'http_req_duration{name:categories}': ['max>=0'],
    'http_req_duration{name:product_details}': ['max>=0'],
    'http_req_duration{name:my_orders}': ['max>=0'],
    'http_req_duration{name:admin_orders}': ['max>=0'],
    'http_req_duration{name:sales_report}': ['max>=0'],
  },
};

function login(email, password) {
  const res = http.post(`${BASE}/auth/login`, JSON.stringify({ email, password }), { headers: JSON_HEADERS });
  if (res.status !== 200) {
    throw new Error(`login ${email} failed: ${res.status} ${res.body}`);
  }
  return res.json('access_token');
}

/** Runs once before the test: tokens + the range of product ids. */
export function setup() {
  const mode = http.get(`${BASE}/products?per_page=1`, { headers: { Accept: 'application/json' } });
  if (mode.status === 429) {
    throw new Error('429: the API rate limit is active. Run: php artisan perf:mode --load-test=on');
  }
  const total = mode.json('meta.total');
  const maxId = mode.json('data.0.id');
  if (total < 10000) {
    throw new Error(`only ${total} products: run "docker compose exec app php artisan perf:seed" first`);
  }
  const categories = http.get(`${BASE}/products/categories`, { headers: { Accept: 'application/json' } }).json('data');

  return {
    maxId,
    categories,
    admin: login('admin@shop.test', 'Admin12345'),
    customer: login('perf-1@shop.test', 'Password123'),
  };
}

const rand = (n) => Math.floor(Math.random() * n);
const pick = (list) => list[rand(list.length)];

function productListUrl(data) {
  // Most visitors look at the first pages; a few go deeper.
  const page = Math.random() < 0.8 ? 1 + rand(5) : 1 + rand(200);
  const category = Math.random() < 0.5 ? `&category=${encodeURIComponent(pick(data.categories))}` : '';
  return `${BASE}/products?page=${page}&sort=${pick(SORTS)}${category}`;
}

// Per virtual user: the ETag of each URL it already received (like a browser cache).
const etags = {};

function get(url, name, token, extraHeaders = {}) {
  const headers = { Accept: 'application/json', ...extraHeaders };
  if (token) headers.Authorization = `Bearer ${token}`;
  const res = http.get(url, { headers, tags: { name } });
  check(res, { [`${name} ok`]: (r) => r.status === 200 || r.status === 304 });
  return res;
}

export default function (data) {
  const r = Math.random() * 100;

  if (r < 30) {
    const url = productListUrl(data);
    const res = get(url, 'product_list');
    if (res.headers.Etag) etags[url] = res.headers.Etag;
  } else if (r < 40) {
    // Returning visitor: asks "changed?" with the ETag it has (if any).
    const known = Object.keys(etags);
    const url = known.length ? pick(known) : productListUrl(data);
    const res = get(url, 'product_list_revalidate', null, etags[url] ? { 'If-None-Match': etags[url] } : {});
    notModified.add(res.status === 304);
  } else if (r < 50) {
    get(`${BASE}/products/categories`, 'categories');
  } else if (r < 70) {
    // Popular products are viewed more often (80% of the views on 1% of the catalog).
    const id = Math.random() < 0.8 ? data.maxId - rand(Math.ceil(data.maxId / 100)) : 1 + rand(data.maxId);
    get(`${BASE}/products/${id}`, 'product_details');
  } else if (r < 85) {
    get(`${BASE}/orders?page=${1 + rand(3)}`, 'my_orders', data.customer);
  } else if (r < 95) {
    get(`${BASE}/orders?per_page=50&page=${1 + rand(5)}`, 'admin_orders', data.admin);
  } else {
    get(`${BASE}/orders/stats?days=30`, 'sales_report', data.admin);
  }
}

/** Saves the full summary as JSON (compared later by perf/compare.js) + the usual text output. */
export function handleSummary(summary) {
  return {
    [`${RESULTS}/${LABEL}.json`]: JSON.stringify(summary, null, 2),
    stdout: textSummary(summary),
  };
}

function textSummary(summary) {
  const m = summary.metrics;
  const d = m.http_req_duration.values;
  const lines = [
    '',
    `=== k6 result: ${LABEL} ===`,
    `requests      ${m.http_reqs.values.count}  (${m.http_reqs.values.rate.toFixed(1)} req/s)`,
    `failed        ${(m.http_req_failed.values.rate * 100).toFixed(2)} %`,
    `latency (ms)  p50 ${d.med.toFixed(1)}   p95 ${d['p(95)'].toFixed(1)}   p99 ${d['p(99)'].toFixed(1)}   max ${d.max.toFixed(1)}`,
    '',
  ];
  return lines.join('\n');
}
