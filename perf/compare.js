'use strict';
/**
 * Requirement 3: BEFORE / AFTER table from the k6 result files.
 *
 *   node perf/compare.js before after            (files perf/results/before.json, after.json)
 *   node perf/compare.js baseline +indexes +eager +cache +etag   (one column per step)
 *
 * Prints the table and writes it to perf/results/report.md (ready for the report / slides).
 */
const fs = require('fs');
const path = require('path');

const dir = process.env.RESULTS_DIR || path.join(__dirname, 'results');
const labels = process.argv.slice(2);
if (labels.length < 1) {
  console.log('Usage: node compare.js <label> [label...]   (files in perf/results/<label>.json)');
  process.exit(2);
}

const runs = labels.map((label) => {
  const file = path.join(dir, `${label}.json`);
  if (!fs.existsSync(file)) {
    console.error(`missing ${file}: run the k6 test with LABEL=${label} first`);
    process.exit(1);
  }
  return { label, metrics: JSON.parse(fs.readFileSync(file, 'utf8')).metrics };
});

const ENDPOINTS = [
  ['ALL REQUESTS', 'http_req_duration'],
  ['product list', 'http_req_duration{name:product_list}'],
  ['product list + If-None-Match', 'http_req_duration{name:product_list_revalidate}'],
  ['categories', 'http_req_duration{name:categories}'],
  ['product details (+gRPC stock)', 'http_req_duration{name:product_details}'],
  ['my orders (customer)', 'http_req_duration{name:my_orders}'],
  ['all orders, 50/page (admin)', 'http_req_duration{name:admin_orders}'],
  ['sales report (admin)', 'http_req_duration{name:sales_report}'],
];

const ms = (v) => (v === undefined ? '-' : `${v.toFixed(1)}`);
const lines = [];
const out = (s = '') => { lines.push(s); console.log(s); };

out('# Requirement 3 - load test results (k6)');
out('');
out(`Runs: ${runs.map((r) => r.label).join(' -> ')}   |   same script, same data, same machine`);
out('');
if (runs.length >= 2) {
  const a = runs[0].metrics;
  const b = runs[runs.length - 1].metrics;
  out(`## الجدول المطلوب (${runs[0].label} -> ${runs[runs.length - 1].label})`);
  out('');
  out('| المقياس | قبل التحسين | بعد التحسين |');
  out('|---|---:|---:|');
  out(`| زمن الاستجابة p50 (ms) | ${ms(a.http_req_duration.values.med)} | ${ms(b.http_req_duration.values.med)} |`);
  out(`| زمن الاستجابة p95 (ms) | ${ms(a.http_req_duration.values['p(95)'])} | ${ms(b.http_req_duration.values['p(95)'])} |`);
  out(`| زمن الاستجابة p99 (ms) | ${ms(a.http_req_duration.values['p(99)'])} | ${ms(b.http_req_duration.values['p(99)'])} |`);
  out(`| عدد الطلبات في الثانية | ${a.http_reqs.values.rate.toFixed(1)} | ${b.http_reqs.values.rate.toFixed(1)} |`);
  out('');
}
out('## Overall');
out('');
out(`| metric | ${runs.map((r) => r.label).join(' | ')} |`);
out(`|---|${runs.map(() => '---:').join('|')}|`);
const overall = [
  ['requests / second', (m) => m.http_reqs.values.rate.toFixed(1)],
  ['total requests', (m) => String(m.http_reqs.values.count)],
  ['p50 latency (ms)', (m) => ms(m.http_req_duration.values.med)],
  ['p95 latency (ms)', (m) => ms(m.http_req_duration.values['p(95)'])],
  ['p99 latency (ms)', (m) => ms(m.http_req_duration.values['p(99)'])],
  ['max latency (ms)', (m) => ms(m.http_req_duration.values.max)],
  ['failed requests', (m) => `${(m.http_req_failed.values.rate * 100).toFixed(2)} %`],
  ['304 Not Modified (returning visitors)', (m) => (m.etag_304_rate ? `${(m.etag_304_rate.values.rate * 100).toFixed(0)} %` : '-')],
];
for (const [name, fn] of overall) {
  out(`| ${name} | ${runs.map((r) => fn(r.metrics)).join(' | ')} |`);
}

out('');
out('## Per endpoint: p50 / p95 / p99 (ms)');
out('');
out(`| endpoint | ${runs.map((r) => r.label).join(' | ')} |`);
out(`|---|${runs.map(() => '---:').join('|')}|`);
for (const [name, key] of ENDPOINTS) {
  out(`| ${name} | ${runs.map((r) => {
    const v = r.metrics[key] && r.metrics[key].values;
    return v ? `${ms(v.med)} / ${ms(v['p(95)'])} / ${ms(v['p(99)'])}` : '-';
  }).join(' | ')} |`);
}

const first = runs[0].metrics;
const last = runs[runs.length - 1].metrics;
const p95 = (m, key = 'http_req_duration') => (m[key] ? m[key].values['p(95)'] : undefined);
const rps = (m) => m.http_reqs.values.rate;
const times = (a, b) => (a && b ? `x${(a / b).toFixed(1)}` : '-');

out('');
out(`Throughput x${(rps(last) / rps(first)).toFixed(1)}, `
  + `p95 x${(p95(first) / p95(last)).toFixed(1)} lower `
  + `(${runs[0].label} -> ${runs[runs.length - 1].label}).`);

// ---------- methodology ----------
out('');
out('## طريقة القياس');
out('');
out('- الأداة: k6، والسكريبت نفسه (`perf/k6/load-test.js`) بكل التشغيلات، على البيانات نفسها (`php artisan perf:seed`) والجهاز نفسه.');
out('- بين التشغيلات تغيّر شيء واحد فقط: `php artisan perf:mode` (قبل = كل التحسينات معطّلة، بعد = كلها مفعّلة).');
out('- مستخدمون افتراضيون ثابتون (constant VUs): كل مستخدم يرسل طلبه التالي فور وصول الرد، لذلك النظام الأسرع يخدم طلبات أكثر في الثانية.');
out('- حد الطلبات (rate limit) مرفوع أثناء القياس فقط، والتشغيل الذي فيه 1% أخطاء أو أكثر لا يُعتمد.');

// ---------- explanation of the observed behaviour ----------
const CAUSES = {
  'product list': 'الفهارس (price) و(category, price) و(name) أزالت قراءة الجدول كاملاً وترتيبه (filesort)، ثم أصبحت الصفحات المتكررة تُقرأ من Redis دون أي استعلام.',
  'product list + If-None-Match': 'ETag: الصفحة التي لم تتغير يُرد عليها بـ 304 بدون جسم، فيوفَّر نقل البيانات على الشبكة.',
  'categories': 'Redis: استعلام DISTINCT على كل المنتجات لم يعد يُنفّذ مع كل طلب.',
  'product details (+gRPC stock)': 'بيانات المنتج من Redis، لكن الكمية تُطلب مباشرة من خدمة المستودع عبر gRPC في كل مرة (لا تُخزَّن عمداً لأنها تتغير مع كل طلب)، لذلك زمن هذا الطلب تحدده خدمة المستودع ولا يتحسن كثيراً.',
  'my orders (customer)': 'بيانات شخصية لا تُخزَّن: تُقرأ من نسخة القراءة مع eager loading (استعلامان بدل 1 + N). الفهرس (user_id) كان موجوداً أصلاً.',
  'all orders, 50/page (admin)': 'إزالة N+1 (52 استعلاماً صارت 3) وتخزين العدد الكلي للطلبات 30 ثانية بدل COUNT(*) على مئات آلاف الأسطر في كل صفحة.',
  'sales report (admin)': 'الفهرس المغطّي (created_at, status, total) قرأ أيام الفترة فقط بدل كل الطلبات، ثم التقرير من Redis لمدة 60 ثانية.',
};

out('');
out('## شرح السلوك الملاحظ');
out('');
out(`- **الإنتاجية:** من ${rps(first).toFixed(1)} إلى ${rps(last).toFixed(1)} طلب/ثانية (${times(rps(last), rps(first))}).`
  + ` p95 من ${ms(p95(first))} إلى ${ms(p95(last))} ms، وp99 من ${ms(first.http_req_duration.values['p(99)'])} إلى ${ms(last.http_req_duration.values['p(99)'])} ms.`);
const med = (m, key = 'http_req_duration') => (m[key] ? m[key].values.med : undefined);
if (p95(first) / p95(last) > med(first) / med(last) && p95(first) / p95(last) > 1.3) {
  out('- **p95 وp99 تحسّنا أكثر من p50:** الطلبات البطيئة جداً (قراءة الجدول كاملاً، N+1، التقرير) كانت تحجز المعالج وقاعدة البيانات فتؤخر كل ما خلفها. بعد إزالتها اختفى "الذيل" البطيء.');
}
// Saturated machine: even the cached / trivial endpoint (categories) waits in the queue.
const saturated = (med(last, 'http_req_duration{name:categories}') || 0) > 300;
if (saturated) {
  out('- **تنبيه - الجهاز مشبع:** حتى الطلبات المخزّنة في Redis (التصنيفات) أخذت أكثر من 300 ms، أي أن الطلبات تنتظر دورها على المعالج'
    + ' (عدد المستخدمين الافتراضيين أكبر مما يحتمله الجهاز). الزمن المقاس هنا هو زمن الانتظار وليس زمن المعالجة، فتختفي فروق التحسين.'
    + ' أعيدوا القياس بعدد أقل من المستخدمين: `$env:VUS="5"; .\\demo.cmd perf` أو أعطوا Docker ذاكرة ومعالجات أكثر.');
}
for (const [name, key] of ENDPOINTS.slice(1)) {
  const a = p95(first, key);
  const b = p95(last, key);
  if (a === undefined || b === undefined) continue;
  const improved = a / b >= 1.2;
  const change = improved ? `p95 أقل ${times(a, b)}` : 'لم يظهر تحسّن واضح في هذا القياس';
  out(`- **${name}:** p95 من ${ms(a)} إلى ${ms(b)} ms (${change}). ${improved ? '' : 'المتوقع: '}${CAUSES[name] || ''}`);
}
if (last.etag_304_rate) {
  out(`- **ETag:** ${(last.etag_304_rate.values.rate * 100).toFixed(0)}% من طلبات الزائر العائد أصبحت 304 بحجم 0 بايت.`
    + ' لا يرفع ETag عدد الطلبات في الثانية كثيراً لأن الخادم ما زال يجهّز الرد ليحسب البصمة؛ فائدته توفير الشبكة (مهم للموبايل والإنترنت البطيء).');
}
out('- **ملاحظة عن الاختبار:** مع مستخدمين ثابتين، عندما يصبح الخادم أسرع يرسل المستخدمون طلبات أكثر في الثانية، فيبقى المعالج مشغولاً.'
  + ' لذلك قد لا ينخفض زمن الطلبات غير المخزّنة (مثل "طلباتي")، لكن النظام يخدم عدداً أكبر بكثير منها في الوقت نفسه.');

if (runs.length > 2) {
  out('');
  out('### أثر كل تحسين وحده (قِس - حسّن شيئاً واحداً - قِس)');
  out('');
  out('| الخطوة | طلب/ثانية | p95 (ms) | التغيّر عن الخطوة السابقة |');
  out('|---|---:|---:|---|');
  runs.forEach((r, i) => {
    const prev = i ? runs[i - 1].metrics : null;
    out(`| ${r.label} | ${rps(r.metrics).toFixed(1)} | ${ms(p95(r.metrics))} | ${prev
      ? `طلب/ثانية ${times(rps(r.metrics), rps(prev))}، p95 ${times(p95(prev), p95(r.metrics))} أقل` : 'نقطة البداية'} |`);
  });
}

const bad = runs.filter((r) => r.metrics.http_req_failed.values.rate >= 0.01);
if (bad.length) {
  out('');
  out(`WARNING: ${bad.map((r) => r.label).join(', ')} had >= 1% failed requests: that run is NOT valid (check 429 / errors).`);
}

fs.writeFileSync(path.join(dir, 'report.md'), lines.join('\n') + '\n');
console.log(`\n(saved to perf/results/report.md)`);
