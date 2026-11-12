# سيناريو المقابلة: المتطلب الأول والثاني والثالث

> المتطلب الثالث: القسم **هـ** بآخر الملف، والشرح الكامل بـ `docs/REQ3-AR.md`.

الهدف: عرض كل بند من البوربوينت بسلاسة، وكل حالة بأمر واحد أو بنقرة.

---

## أ) قبل المقابلة (10 دقائق، ولازم تخلص قبل ما تفوت)

1. **Docker Desktop** شغّال، ومكتوب تحت يسار **Engine running**.
2. بالـ PowerShell، جوا مجلد المشروع:
   ```powershell
   cd C:\ecommerce-platform
   docker compose up -d
   docker compose ps
   ```
   لازم يكونوا `Up`: التطبيق، وnginx، وdb، وdb-replica، وredis، وinventory، وinventory-db. أما `shop_db_replica_setup` فبيكون `Exited (0)` (مهمة بتخلص وبتطلع)، و`shop_phpmyadmin` اختياري.
3. **مرة وحدة بس** (أول مرة بعد إضافة السكريبت)، ابنِ صورة العرض:
   ```powershell
   docker compose --profile tools build demo
   ```
4. **رجّع المخزون للبداية، وتأكد إنو الاختبارات خضرا:**
   ```powershell
   .\demo.cmd reset
   .\demo.cmd tests
   ```
5. **سخّن الصفحات** حتى ما يكون أول فتح بطيء:
   ```powershell
   $urls = "/products","/products/1","/login","/register","/cart","/orders","/orders/1","/notifications","/admin/products","/admin/orders","/api/v1/products","/api/v1/products/1","/api/v1/products/categories"
   1..3 | % { foreach ($u in $urls) { curl.exe -o NUL -s "http://127.0.0.1:8000$u" } }
   ```
6. **افتح مسبقاً:**

   | الشاشة | شو فيها |
   |---|---|
   | المتصفح، تبويب 1 | `http://127.0.0.1:8000/products` (بدون تسجيل دخول) |
   | المتصفح، تبويب 2 (نافذة خاصة Incognito) | `http://127.0.0.1:8000/login`، مسجّل كأدمن |
   | Postman | الكوليكشن مفتوحة على الفولدرات |
   | PhpStorm | المشروع مفتوح، ومعه الملفات: `proto/inventory/v1/inventory.proto` و `OrderController.php` و `inventory-service/src/inventory.js` |
   | PowerShell 1 | بمجلد المشروع، للأوامر |
   | PowerShell 2 | `docker compose logs -f inventory`، وفيه بتبيّن نداءات gRPC وهي عم تصير |
   | HeidiSQL | اتصال بـ MySQL (3307) واتصال بـ PostgreSQL (5433) |

> **لا تشغّل `demo ratelimit` قبل المقابلة**، لأنه بيقفل تسجيل الدخول لمدة دقيقة.

---

## ب) أثناء المقابلة (حوالي 12 دقيقة)

### 1. نظرة عامة (دقيقة)
- **PowerShell:** `docker compose ps`. خمس حاويات: التطبيق، وnginx، وMySQL، وخدمة المستودع، وPostgreSQL.
- **PhpStorm:** مجلد `src/Modules`، فيه 5 وحدات، ولكل وحدة `Providers` و `Contracts` و `Events`.
- **جملة:** "Modular Monolith بلغة PHP/Laravel، وخدمة مستودع مستقلة بلغة Node.js، والتواصل بينهم عبر gRPC."

### 2. المتطلب الأول: الوظائف من الواجهة (3 دقائق)
| الخطوة | وين | البند بالبوربوينت |
|---|---|---|
| المنتجات: بحث، تصفية بالتصنيف، تغيير "بالصفحة" لـ 4 وتقليب الصفحات | تبويب 1 | عرض المنتجات، التصفية، تقسيم الصفحات |
| تسجيل دخول كزبون `customer@shop.test` | تبويب 1 | تسجيل المستخدمين، JWT |
| فتح منتج: الصورة والسعر و"متوفر: N" | تبويب 1 | عرض المنتج مع صورته + الكمية من المستودع |
| "أضف إلى السلة" لمنتجين، ثم "تأكيد الطلب" | تبويب 1 | إنشاء طلب |
| "طلباتي"، ثم تفاصيل الطلب | تبويب 1 | عرض طلب، والزبون يرى طلباته فقط |
| أدمن: إضافة منتج **مع صورة** | تبويب 2، إدارة المنتجات | إضافة منتج مع صورته |
| أدمن: تغيير حالة الطلب لـ "تم الشحن" | تبويب 2، إدارة الطلبات | المسؤول وحده يغيّر الحالة |
| الزبون: الإشعارات، "تغيّرت حالة طلبك..." | تبويب 1 | الإشعارات ومتابعة حالة الطلب |

(اختياري، إذا طلب التسجيل الجديد: إنشاء حساب، ثم جيب الكود بـ
`docker compose exec app sh -c "grep -a 'Your code is' storage/logs/laravel.log | tail -n 1"`، ثم التأكيد.)

### 3. المتطلب الأول: الإثبات (دقيقة)
```powershell
.\demo.cmd auth
```
بثواني بيطلع: طلب غيره **403**، تغيير الحالة من زبون **403**، إضافة أو حذف منتج من زبون **403**، بلا توكن **401**، توكن مزوّر **401**، والأدمن بيوصل لكل شي.

```powershell
.\demo.cmd api
```
بيطلع: الإصدار بالمسار `/api/v1`، والـ `/api/products` القديم 404، وتقسيم الصفحات، والتصفية، وأخطاء JSON موحدة (422 و404 و405)، وترويسات الأمان.

**Postman (التوثيق):** افتح الكوليكشن وبيّن الفولدرات، وطلب واحد مع الـ Body والرد. وإذا طلب، Runner على **فولدر 5**.

### 4. المتطلب الثاني (4 دقائق)
خلي نافذة **PowerShell 2** (لوغ المستودع) ظاهرة.

| الأمر | شو بيبيّن | البند بالبوربوينت |
|---|---|---|
| (PhpStorm) افتح `inventory.proto` | العقد المشترك: 3 عمليات | العقد: ملف proto يعرّف ثلاث عمليات |
| `.\demo.cmd stock` | الكمية من gRPC، و409 للكمية غير الكافية، و"الكل أو لا شيء" | عرض تفاصيل المنتج، إنشاء الطلب، الكمية غير كافية |
| `.\demo.cmd down` | بيوقف المستودع، فصفحة المنتج شغالة والطلب **503** فوراً، وبعدين بيرجّعه | المستودع متوقف، وماذا يرى المستخدم |
| `.\demo.cmd slow` | تأخير 5 ثواني، فالطلب **504** بعد ثانيتين بالضبط | مهلة محددة لكل نداء |
| `.\demo.cmd savefail` | فشل الحفظ بعد الحجز، فـ **500** والكمية رجعت | تم الحجز ثم فشل الحفظ: تُرجع الكمية |
| `.\demo.cmd race` | 10 طلبات بنفس اللحظة على آخر قطعة، فبينجح **واحد بس** | Race Condition |
| (HeidiSQL) جدول `stock` بـ PostgreSQL، وجدول `orders` بـ MySQL | كل خدمة إلها قاعدة بيانات | قاعدة بيانات خاصة لكل خدمة |

### 5. الاختبارات الآلية (دقيقة)
```powershell
.\demo.cmd tests
```
أكتر من 30 اختبار أخضر: الصلاحيات، وتصميم الـ API، والمتطلب الثاني، والاختبار المعماري للوحدات.

### 6. آخر شي
```powershell
.\demo.cmd ratelimit
```
6 محاولات دخول غلط، فالمحاولة الأخيرة **429**.

**وإذا سأل عن Git:** افتح الريبو، ثم Pull requests، ثم Closed. بيبيّن طلبات الدمج ومين راجعها ودمجها.

---

## ج) قائمة تحقق: كل بنود البوربوينت

| السلايد | البند | وين بينعرض |
|---|---|---|
| 2 | تسجيل المستخدمين | الواجهة: تسجيل + OTP + دخول، و`demo auth` |
| 2 | إضافة المنتجات مع صورها وعرضها | الواجهة: إدارة المنتجات + صفحة المنتج |
| 2 | إنشاء الطلبات | الواجهة: السلة / اطلب الآن |
| 2 | إدارة المخزون | خدمة المستودع: `demo stock`، و`set-stock.js` |
| 2 | متابعة حالة الطلب | الواجهة: الأدمن بيغيّر، والزبون بيشوف الحالة والإشعار |
| 5 | أربع وحدات + Docker | `docker compose ps` + `src/Modules` |
| 6 | REST: منتج مع صورة، عرض، تصفية، صفحات، طلب (إنشاء، عرض، تغيير الحالة) | الواجهة + `demo api` + Postman |
| 6 | إصدار في المسار، رموز حالة، صيغة أخطاء موحدة | `demo api` |
| 6 | توثيق يمكن تجربته | Postman |
| 7 | الزبون يرى طلباته فقط، والمسؤول وحده يغيّر الحالة | `demo auth` |
| 7 | JWT، وتحديد محاولات الدخول، وترويسات الأمان | `demo auth`، و`demo ratelimit`، و`demo api` |
| 8 | **إثبات:** الوصول لطلب غيره أو عملية مو من صلاحيته يُرفض | `demo auth` + Postman فولدر 5 + `AuthorizationTest` |
| 10 | خدمة بلغة مختلفة، إلها قاعدة بيانات خاصة، وتواصل عبر gRPC | PhpStorm `inventory-service` + HeidiSQL + لوغ المستودع |
| 11 | 3 عمليات بالعقد | `inventory.proto` |
| 11 | عرض تفاصيل المنتج مع الكمية | الواجهة + `demo stock` |
| 11 | إنشاء الطلب بطلب حجز واحد (موافقة أو رفض) | `demo stock` |
| 12 | الكمية غير كافية | `demo stock` |
| 12 | المستودع متوقف أو بطيء (مهلة) | `demo down` + `demo slow` |
| 12 | فشل الحفظ بعد الحجز | `demo savefail` |
| 13 | **إثبات:** Race Condition + ماذا يرى المستخدم عند توقف المستودع | `demo race` + `demo down` |
| 14 | Git: فرع محمي، طلبات دمج مع مراجعة | صفحة الريبو |

---

## د) إذا صار شي بالمقابلة

| المشكلة | الحل السريع |
|---|---|
| تسجيل الدخول بيعطي "محاولات كثيرة" | استنى دقيقة. سكريبت العرض بيستعمل توكنات محفوظة، فما بيتأثر |
| `demo stock` طلع فيه منتج 2 ما في منه كمية | `.\demo.cmd reset` |
| صفحة بطيئة أول مرة | طبيعي، أعد فتحها، وسخّن قبل المقابلة |
| `service "app" is not running` | Docker Desktop وقف: افتحه، ثم `docker compose up -d` |
| `demo tests` بيقول Configuration is cached | استعمل `.\demo.cmd tests` (بيمسح الكاش لحاله) مو `php artisan test` مباشرة |

---

## هـ) مقابلة المتطلب الثالث (الأداء)

### قبل المقابلة (مرة وحدة، ~5 دقائق)
```powershell
docker compose up -d
docker compose ps                      # db-replica و redis لازم Up، و db-replica-setup لازم Exited (0)
docker compose --profile tools pull k6  # صورة k6 (مرة وحدة)
.\demo.cmd seed                        # 300 ألف طلب (إذا ما عملتها قبل)، ~1-3 دقائق
.\demo.cmd tests                       # كل الاختبارات خضرا
.\demo.cmd perf                        # القياس قبل/بعد (~1.5 دقيقة)، والجدول بينحفظ بـ perf\results\report.md
.\demo.cmd mode                        # تأكد إنو كل شي ON وحد الطلبات 60
```
افتح مسبقاً:
- `perf\results\report.md` بـ PhpStorm (Ctrl+Shift+V لعرضه كجدول).
- `docs\REQ3-AR.md`.
- phpMyAdmin على `http://127.0.0.1:8090` (السيرفر `db-replica`).
- المتصفح على صفحة منتج، مع DevTools ← Network.

### أثناء المقابلة (~10 دقائق)
| # | شو بتعمل | شو بتقول |
|---|---|---|
| 1 | `docker compose ps` | "4 طبقات: المتصفح (ETag)، والخادم (Redis)، والقاعدة (فهارس + N+1)، ونسخة قراءة MySQL" |
| 2 | `.\demo.cmd explain` | "حطينا 300 ألف طلب و100 ألف منتج، وقسنا بـ EXPLAIN: قبل `type=ALL` و`filesort`، بعد الفهرس 12 سطر بدل 100 ألف" + سؤال الفهارس والكتابة |
| 3 | `.\demo.cmd nplus1` | "52 استعلام صاروا 3" |
| 4 | `.\demo.cmd cache` | "التانية من Redis، والتعديل بيبيّن فوراً بفضل رقم النسخة، والكمية ما بتنخزّن" + جدول يجوز/لا يجوز |
| 5 | `.\demo.cmd etag` + المتصفح F5 ← Network ← 304 | "0 بايت لما ما تغيّر شي" |
| 6 | `.\demo.cmd replica` | "النسخة واقفة عمداً، والمستخدم شاف تعديله فوراً (GTID)، والنسخة لسا فيها القديم" |
| 7 | `perf\results\report.md` (أو `.\demo.cmd perf` إذا في وقت) | p50 و p95 و p99 وطلب بالثانية، قبل وبعد، + شرح السلوك (القسم 5 بـ REQ3-AR) |
| 8 | PhpStorm: `ProductCache.php`، `ReadYourWrites.php`، `ETag.php`، `setup-replica.sh` | إذا طلب يشوف الكود |

### قائمة تحقق المتطلب الثالث
| البند | وين بينعرض |
|---|---|
| بيانات كبيرة (مئات آلاف الطلبات) | `demo seed` + `demo replica` (عدد الأسطر) |
| EXPLAIN وتسريع الاستعلامات البطيئة | `demo explain` |
| حل مشكلة N+1 | `demo nplus1` + `PerformanceTest` |
| Read Replica + المستخدم بيشوف شو أنشأ هلق | `demo replica` |
| Redis مع TTL وإبطال عند التعديل، شو يجوز وشو لأ | `demo cache` + `config/performance.php` + `ProductCache.php` |
| ETag ← 304 | `demo etag` + المتصفح + Postman فولدر 7 |
| k6 قبل وبعد (p50 و p95 و p99 وطلب بالثانية) | `demo perf` / `demo perfsteps` + `report.md` |
| شرح السلوك | REQ3-AR القسم 5 |
| تعديل منتج بيبيّن فوراً رغم الكاش والنسخة | `demo cache` + `demo replica` + `PerformanceTest` |
| متى تبطئ الفهارس | REQ3-AR القسم 3 د |

### إذا صار شي
| المشكلة | الحل |
|---|---|
| `db-replica-setup` طالع `Exited (1)` | `docker compose logs db-replica-setup`، بعدين `.\demo.cmd resync` |
| `demo perf` بيقول "only N products" | `.\demo.cmd seed` |
| k6 بيقول 429 | `docker compose exec app php artisan perf:mode optimized "--load-test=off"`، بعدين أعد `demo perf` (هو بيرفع الحد لحالو) |
| بقيت التحسينات طافية بعد ما وقف القياس بالنص | `.\demo.cmd mode` لتشوف، بعدين `docker compose exec app php artisan perf:mode optimized "--load-test=off"` |
| بدك ترجّع المتجر نضيف بعد المقابلة | `.\demo.cmd clean` |
