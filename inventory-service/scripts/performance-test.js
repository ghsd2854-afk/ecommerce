'use strict';

/**
 * HTTP performance benchmark for the public product-list endpoint.
 *
 * Examples:
 *   docker compose exec inventory node scripts/performance-test.js
 *   docker compose exec inventory node scripts/performance-test.js --requests=200 --concurrency=20
 *   docker compose exec inventory node scripts/performance-test.js --etag
 *
 * Run once with PERFORMANCE_PRODUCT_CACHE_ENABLED=false (baseline), then true (Redis).
 * Do not compare runs while other load is being generated.
 */

const args = Object.fromEntries(
  process.argv.slice(2).map((arg) => arg.replace(/^--/, '').split('=')),
);

const API = process.env.API_URL || 'http://nginx/api/v1/products?per_page=50&sort=newest';
const REQUESTS = Math.max(1, Number(args.requests || 100));
const CONCURRENCY = Math.max(1, Math.min(REQUESTS, Number(args.concurrency || 10)));
const WARMUP = Math.max(0, Number(args.warmup || 10));
const USE_ETAG = args.etag === true || args.etag === 'true';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function percentile(sorted, p) {
  if (!sorted.length) return 0;
  const index = Math.min(sorted.length - 1, Math.ceil(p * sorted.length) - 1);
  return sorted[index];
}

async function request(etag) {
  const headers = { Accept: 'application/json' };
  if (USE_ETAG && etag) headers['If-None-Match'] = etag;

  const start = process.hrtime.bigint();
  const response = await fetch(API, { headers });
  const elapsed = Number(process.hrtime.bigint() - start) / 1e6;

  if (response.status !== 304) {
    await response.arrayBuffer();
  }

  if (!response.ok && response.status !== 304) {
    throw new Error(`HTTP ${response.status}`);
  }

  return {
    ms: elapsed,
    status: response.status,
    etag: response.headers.get('etag') || etag || null,
    bytes: Number(response.headers.get('content-length') || 0),
  };
}

async function main() {
  console.log(`URL: ${API}`);
  console.log(`Warmup: ${WARMUP} | Requests: ${REQUESTS} | Concurrency: ${CONCURRENCY} | ETag: ${USE_ETAG}`);

  let currentEtag = null;
  for (let i = 0; i < WARMUP; i++) {
    const result = await request(currentEtag);
    currentEtag = result.etag || currentEtag;
  }

  const results = [];
  const started = Date.now();
  let next = 0;

  async function worker() {
    while (true) {
      const index = next++;
      if (index >= REQUESTS) return;
      const result = await request(currentEtag);
      if (result.etag) currentEtag = result.etag;
      results[index] = result;
    }
  }

  await Promise.all(Array.from({ length: CONCURRENCY }, () => worker()));

  const elapsedSeconds = (Date.now() - started) / 1000;
  const times = results.map((r) => r.ms).sort((a, b) => a - b);
  const average = times.reduce((sum, value) => sum + value, 0) / times.length;
  const statuses = results.reduce((acc, r) => {
    acc[r.status] = (acc[r.status] || 0) + 1;
    return acc;
  }, {});

  console.log('\n========== PERFORMANCE RESULTS ==========');
  console.log(`Requests completed : ${results.length}`);
  console.log(`Average latency    : ${average.toFixed(2)} ms`);
  console.log(`p95 latency        : ${percentile(times, 0.95).toFixed(2)} ms`);
  console.log(`p99 latency        : ${percentile(times, 0.99).toFixed(2)} ms`);
  console.log(`Min / Max          : ${times[0].toFixed(2)} / ${times[times.length - 1].toFixed(2)} ms`);
  console.log(`Throughput         : ${(REQUESTS / elapsedSeconds).toFixed(2)} requests/sec`);
  console.log(`HTTP statuses      : ${JSON.stringify(statuses)}`);
  console.log('=========================================');
}

main().catch((error) => {
  console.error(`Benchmark failed: ${error.message}`);
  process.exitCode = 1;
});
