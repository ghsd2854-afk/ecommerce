# شرح المشروع: المتطلب الأول والمتطلب الثاني

> **المتطلب الثالث (الأداء):** شرحه كامل بملف منفصل `docs/REQ3-AR.md`.

مشروع تطبيقات الإنترنت · منصة تجارة إلكترونية · الفصل الدراسي 2026

---

## 1) المتطلب الأول (API Design & Security): شو كان محقق وشو تعدّل

### أ) جدول التحقق بنداً بنداً

| البند المطلوب (من الصور والبوربوينت) | الحالة بالمشروع الأصلي | وين بالكود |
|---|---|---|
| اللغة والإطار بحرية | ✅ PHP 8.4 + Laravel 13 | `src/` |
| أربع وحدات: المستخدمون، المنتجات، الطلبات، الإشعارات | ✅ | `src/Modules/{Users,Products,Orders,Notifications}` |
| التشغيل كامل بـ Docker بحاويات منفصلة | ✅ app + nginx + mysql | `docker-compose.yml`, `Dockerfile` |
| إضافة منتج مع صورته | ✅ (form-data, jpg/png/webp, 2MB) | `ProductController@store` |
| عرض المنتجات | ✅ | `GET /products` |
| تصفية حسب التصنيف | ✅ `?category=` (+ بحث، سعر، ترتيب) | `ProductController@index` |
| تقسيم الصفحات | ✅ `?page=&per_page=` | `paginate()` |
| إنشاء طلب / عرض طلب / تغيير حالة الطلب | ✅ | `OrderController` |
| **إصدار في المسار (versioning)** | ❌ **ناقص**: المسارات كانت `/api/products` | ✔ صار `/api/v1/...` |
| رموز حالة صحيحة | ✅ 200/201/204/401/403/404/405/409/422/429 | |
| صيغة موحدة للأخطاء | ✅ `{"message": "..."}` (+ `errors` للتحقق) | `bootstrap/app.php` |
| **توثيق يمكن تجربته بسهولة (Postman/Swagger)** | ❌ **ناقص**: الـ README بيذكر `docs/ecommerce-api.postman_collection.json` بس المجلد مو موجود بالمشروع، ونص الشرح نفسه بيقول "لسا ما اختبرناه" | ✔ انبنت الكوليكشن كاملة |
| الزبون يرى طلباته فقط | ✅ فلترة `user_id` + 403 على طلب غيره | `OrderController@index/show` |
| المسؤول وحده يغيّر حالة الطلب | ✅ `role:admin` | `Orders/Routes/api.php` |
| مصادقة JWT | ✅ مع refresh و logout (blacklist) | `config/auth.php`, `AuthController` |
| تحديد محاولات تسجيل الدخول | ✅ 5 بالدقيقة | `Users/Routes/api.php` |
| ترويسات الأمان الأساسية | ✅ | `SecurityHeaders` |
| **إثبات**: الوصول لطلب غيره أو عملية مو من صلاحيته ← يُرفض | ⚠️ الكود صحيح بس ما في ملف إثبات جاهز | ✔ فولدر PROOF بالكوليكشن |

**الخلاصة:** الكود الأساسي للمتطلب الأول كان منيح وتقريباً كامل. النواقص الحقيقية كانت: **الإصدار بالمسار**، **ملف التوثيق (الكوليكشن)**، و**الإثبات الجاهز**، ومعهم كم مشكلة صغيرة لازم تنصلح (بالقسم الجاي).

### ب) التعديلات يلي انعملت على المتطلب الأول ولييش

1. **الإصدار بالمسار `/api/v1`** (`app/Providers/ModulesServiceProvider.php`):
   كل مسارات الوحدات صارت تحت `api/v1`. الفكرة: إذا بدنا نغيّر شكل الـ API بالمستقبل بطريقة بتكسر الزبائن القدام، منعمل `v2` وبيضل `v1` شغال.
   وتعدّل `SecurityHeaders` ليطبّق `Cache-Control: no-store` على `api/*/auth/*`.

2. **`nginx.conf`: إضافة `client_max_body_size 3m`**: هي مشكلة حقيقية. nginx افتراضياً بيرفض أي طلب أكبر من 1MB، يعني صورة حجمها 1.5MB (مسموحة عنا لحد 2MB) كانت رح ترجع **413 بصفحة HTML من nginx**، مو JSON وما بتوصل لـ Laravel أصلاً. هيك كانت رح تخرّب "صيغة الأخطاء الموحدة" ورفع الصور.
   وانضاف كمان `server_tokens off` لحتى ما ينكشف إصدار nginx (تقوية أمان).

3. **`'user_id' => 'integer'` بموديلي `Order` و `UserNotification`**: فحص الملكية مكتوب `$order->user_id !== $user->id` (مقارنة صارمة). إذا رجعت قاعدة البيانات الرقم كنص (بيصير ببعض إعدادات PDO)، صاحب الطلب نفسه كان رح ياخد 403. التحويل لعدد بيخلي الفحص مضمون.

4. **`distinct` على `items.*.product_id`** بـ `StoreOrderRequest`: بيمنع تكرار نفس المنتج بنفس الطلب.

5. **الكوليكشن** `docs/ecommerce-api.postman_collection.json` (61 طلب، مشروحة بالقسم 4).

6. **README** تحدّث بالمسارات الجديدة وبالمتطلب الثاني.

> ملاحظة: أي مكان بتستعملوا فيه `http://localhost:8000/api/...` لازم يصير `http://localhost:8000/api/v1/...`

---

## 2) المتطلب الثاني (Inter-Service Communication): شو انعمل خطوة خطوة

### أ) البنية

```
 Postman ──REST /api/v1──► Laravel (PHP) ──gRPC──► خدمة المستودع (Node.js)
                              │                          │
                          MySQL (shop)           PostgreSQL (inventory)
                     قاعدة بيانات التطبيق       قاعدة بيانات المستودع
```

- **خدمة متعددة اللغات (Polyglot):** التطبيق الأساسي PHP/Laravel، والمستودع **Node.js** ← لغة وإطار مختلفين. ✔
- **لكل خدمة قاعدة بيانات خاصة:** Laravel على MySQL، والمستودع على **PostgreSQL** بحاوية منفصلة. Laravel ما بيتصل بقاعدة المستودع أبداً. ✔
- **التواصل عن طريق gRPC بشكل مباشر** وعبر العقد فقط. ✔

### ب) الملفات الجديدة

| الملف | شو هو |
|---|---|
| `proto/inventory/v1/inventory.proto` | **العقد المشترك** (المصدر الوحيد للطرفين) |
| `inventory-service/src/server.js` | خادم gRPC (Node.js، `@grpc/grpc-js`) |
| `inventory-service/src/inventory.js` | منطق العمليات الثلاث + معالجة الـ Race Condition |
| `inventory-service/src/db.js` | الاتصال بـ PostgreSQL + إنشاء الجداول + مخزون أولي |
| `inventory-service/src/seed-data.json` | المخزون الأولي للمنتجات 1..8 (المنتج 8 فيه قطعة وحدة) |
| `inventory-service/scripts/set-stock.js` | أداة لعرض/تعديل المخزون (مو جزء من العقد) |
| `inventory-service/scripts/race-test.js` | **إثبات الـ Race Condition** |
| `inventory-service/Dockerfile` | صورة الخدمة |
| `src/Modules/Inventory/Grpc/*` | كلاسات PHP **مولّدة من ملف الـ proto** + الـ client stub |
| `src/Modules/Inventory/Contracts/InventoryGateway.php` | واجهة (interface) بيستعملها باقي التطبيق |
| `src/Modules/Inventory/Services/GrpcInventoryGateway.php` | التنفيذ عبر gRPC مع **مهلة (deadline)** لكل نداء |
| `src/Modules/Inventory/Exceptions/InventoryUnavailableException.php` | الخدمة واقفة/بطيئة ← رد JSON واضح 503/504 |
| `src/config/inventory.php` | العنوان والمهلة وخيار المحاكاة |
| `src/Modules/Orders/Database/Migrations/2026_11_02_..._add_reservation_id_to_orders_table.php` | حفظ رقم الحجز مع الطلب |

والملفات المعدّلة: `OrderController` (تدفق إنشاء الطلب)، `ProductController@show` + `ProductResource` (عرض الكمية)، `AppServiceProvider` (ربط الواجهة بالتنفيذ)، `Order` (حقل `reservation_id`)، `Dockerfile` (إضافة امتداد grpc لـ PHP)، `docker-compose.yml` (حاويتين جداد)، `composer.json` (حزم `grpc/grpc` و `google/protobuf`)، `.env.example`.

### ج) العقد: ملف proto فيه ثلاث عمليات (بالضبط متل السلايد 11)

| العملية | معناها | المدخلات ← المخرجات |
|---|---|---|
| `GetStock` | **معرفة الكمية**: كم بقي من هالمنتج؟ | `product_id` ← `available_quantity` |
| `ReserveStock` | **حجز الكمية**: احجز كميات كل منتجات الطلب | `reservation_id` + قائمة `items` ← `success`, `message`, `shortages` |
| `ReleaseStock` | **إرجاع الكمية**: رجّع ما حُجز إذا فشل الطلب | `reservation_id` ← `released`, `message` |

ليش في `reservation_id`؟ Laravel بيولّد UUID لكل طلب وبيبعته مع الحجز. هيك:
- إذا انعاد نفس الحجز مرتين (retry) ما بينحجز مرتين (idempotent).
- لما بدنا نرجّع الكمية، منعرف بالضبط شو نرجّع.

### د) التدفق الأول: عرض تفاصيل المنتج (السلايد 11)

`GET /api/v1/products/{id}` ← `ProductController@show`:
1. التطبيق بيسأل المستودع `GetStock(product_id)`.
2. بيعرض الكمية مع تفاصيل المنتج:
```json
{ "data": { "id": 8, "name": "Bluetooth Speaker", "price": "44.00",
            "stock": { "available_quantity": 1, "in_stock": true } } }
```
3. إذا المستودع واقف: المنتج **بيضل ينعرض** (200) بس:
```json
"stock": { "available_quantity": null, "in_stock": null, "message": "Stock information is temporarily unavailable." }
```

### هـ) التدفق الثاني: إنشاء الطلب (السلايد 11) — `OrderController@store`

1. Laravel بيتحقق من المدخلات (Validation).
2. **طلب gRPC واحد** `ReserveStock` لحجز كميات **كل** منتجات الطلب (الكل أو لا شيء).
3. **رفض** (كمية غير كافية) ← **409** برسالة واضحة و **لا يُنشأ الطلب**:
```json
{ "message": "Not enough stock for: Bluetooth Speaker (requested 3, available 1). The order was not created.",
  "shortages": [ { "product_id": 8, "requested": 3, "available": 1 } ] }
```
4. **موافقة** ← بيكمّل التطبيق وبيحفظ الطلب بقاعدة بياناته (Transaction) مع `reservation_id` ← **201**.
5. إذا **فشل الحفظ بعد الحجز** ← بينادي `ReleaseStock` وبترجع الكمية للمستودع ← **500** برسالة واضحة.

### و) حالات الفشل (السلايد 12)

| الحالة | شو بيصير بالكود | شو بيشوف المستخدم |
|---|---|---|
| **الكمية غير كافية** | `ReserveStock` بيرجع `success=false` + `shortages` | **409** "Not enough stock for: ... The order was not created." |
| **المستودع متوقف** | gRPC بيرجع `UNAVAILABLE` (code 14) فوراً | **503** "The inventory service is currently unavailable. Nothing was changed, please try again later." |
| **المستودع بطيء** | كل نداء إلو **deadline = 2000ms** (`INVENTORY_GRPC_TIMEOUT_MS`)، بعدها gRPC بيرجع `DEADLINE_EXCEEDED` (code 4) | **504** بعد ثانيتين بالضبط (مو بلا نهاية) "did not respond within 2000 ms" |
| **فشل الحفظ بعد الحجز** | `catch` حوالين الـ Transaction ← `ReleaseStock(reservation_id)` | **500** "The order could not be saved. The reserved stock was returned to the inventory." |

تفصيل ذكي بحالة البطء: ممكن الحجز يوصل للمستودع وينفّذ **بعد** ما التطبيق استسلم. لهيك:
- التطبيق بعد ما يرد 504 بيبعت `ReleaseStock` (بعد إرسال الرد للمستخدم، لحتى ما يتأخر).
- المستودع، إذا الطلب انتهت مهلته (`call.cancelled`)، ما بينفذه أصلاً.
- إذا وصل `ReleaseStock` قبل `ReserveStock`، المستودع بيسجّل علامة "released"، فلما يوصل الحجز المتأخر بيترفض وما بياخد مخزون.

### ز) الـ Race Condition (السلايد 13): وكيف انحلت

**المشكلة:** 10 زبائن بيطلبوا آخر قطعة بنفس اللحظة. لو الكود كان "اقرأ الكمية ← إذا ≥ 1 ← انقص"، كل الطلبات بتقرا 1 بنفس الوقت وبتنجح كلها ← مخزون سالب.

**الحل** (`inventory-service/src/inventory.js`): كل منتج بينحجز **بأمر SQL ذري واحد**:
```sql
UPDATE stock SET quantity = quantity - $2
 WHERE product_id = $1 AND quantity >= $2
```
PostgreSQL بيقفل السطر (row lock). الطلب التاني بيستنى لحتى يخلص الأول، وبعدين بيعيد فحص الشرط `quantity >= 1` على القيمة الجديدة (0) فما بيطابق ← يُرفض. والنتيجة: **طلب واحد بس بينجح**.
وكمان: `CHECK (quantity >= 0)` بالجدول كحماية إضافية، والمنتجات بتنحجز **بترتيب `product_id`** لمنع الـ deadlock، وكل الطلب ضمن Transaction واحدة (الكل أو لا شيء).

---

## 3) كيف تطبّق التعديلات على نسختك الشغالة (خطوة بخطوة)

> نسختك فيها `.env` و `vendor`؛ ما تمسحهم.

1. **انسخ الملفات الجديدة والمعدّلة** من المشروع المرفق فوق نسختك (نفس المسارات):
   - بالجذر: `Dockerfile`, `docker-compose.yml`, `nginx.conf`, `README.md`، والمجلدات `proto/`, `inventory-service/`, `docs/`
   - بـ `src/`: `composer.json`, `.env.example`, `config/inventory.php`, `app/Providers/AppServiceProvider.php`, `app/Providers/ModulesServiceProvider.php`, `app/Http/Middleware/SecurityHeaders.php`, مجلد `Modules/Inventory/` كامل، `Modules/Orders/Http/Controllers/OrderController.php`, `Modules/Orders/Http/Requests/StoreOrderRequest.php`, `Modules/Orders/Models/Order.php`, `Modules/Orders/Database/Migrations/2026_11_02_100000_add_reservation_id_to_orders_table.php`, `Modules/Products/Http/Controllers/ProductController.php`, `Modules/Products/Http/Resources/ProductResource.php`, `Modules/Notifications/Models/UserNotification.php`

2. **زيد هالأسطر بآخر `src/.env` تبعك:**
```
INVENTORY_GRPC_HOST=inventory:50051
INVENTORY_GRPC_TIMEOUT_MS=2000
SIMULATE_ORDER_SAVE_FAILURE=false
```

3. **ابنِ وشغّل** (PowerShell داخل مجلد المشروع). أول بناء بياخد وقت (عدة دقائق) لأنه بيترجم امتداد gRPC لـ PHP، وبعدها بينحفظ بالكاش:
```powershell
docker compose down
docker compose up -d --build
```

4. **ثبّت حزم gRPC لـ PHP** (مرة وحدة، بتحدّث `composer.lock`):
```powershell
docker compose exec app composer update grpc/grpc google/protobuf
```

5. **الـ migration الجديدة وتنظيف الكاش:**
```powershell
docker compose exec app php artisan migrate
docker compose exec app php artisan optimize:clear
```

6. **تأكد إنو كل شي شغال:**
```powershell
docker compose ps
docker compose logs inventory
```
لازم تشوف بلوغ المستودع: `[db] seeded stock for 8 products` و `[grpc] inventory service listening on 50051`.
وبالمتصفح: `http://localhost:8000/api/v1/products/8` ← لازم يطلع `"stock": {"available_quantity": 1, ...}`.

> **إذا عملت `migrate:fresh --seed`** (يعني المنتجات انمسحت وانعملت من جديد) والمخزون مو مطابق: صفّر قاعدة المستودع كمان:
> `docker compose down` ثم `docker volume rm ecommerce-platform_inventorydata` (اسم الـ volume بيبدأ باسم مجلدك؛ شوفه بـ `docker volume ls`) ثم `docker compose up -d`.

**منتج جديد ضافه الأدمن** ما إلو مخزون (الكمية 0) لحتى تعطيه:
```powershell
docker compose exec inventory node scripts/set-stock.js 9 100
docker compose exec inventory node scripts/set-stock.js          # عرض كل المخزون
```

---

## 4) الكوليكشن (Postman) وكيف تستعملها

**الاستيراد:** Postman ← Import ← `docs/ecommerce-api.postman_collection.json`.
المتغير `base_url` جاهز = `http://localhost:8000/api/v1`. التوكنات بتنحفظ تلقائياً بمتغيرات الكوليكشن.

| الفولدر | المحتوى | طريقة التشغيل |
|---|---|---|
| **0 - Setup** | تسجيل دخول الأدمن والزبونين وحفظ توكناتهم | **دايماً أول شي** |
| **1 - Auth** | تسجيل، OTP، تحقق، نسيت كلمة المرور، me، refresh، logout، me بعد logout = 401 | يدوي: حط كود الـ OTP بمتغير `otp_code` |
| **2 - Products** | عرض، تقسيم صفحات، تصفية، بحث وترتيب، تفاصيل مع المخزون، 404، 422، إضافة مع صورة، تعديل، حذف | Runner |
| **3 - Orders & Notifications** | إنشاء طلب، حذف منتج داخل طلب = 409، طلباتي، الأدمن يشوف الكل ويغيّر الحالة، الإشعارات | Runner |
| **4 - Requirement 2** | المخزون قبل/بعد الطلب (نقص 2)، كمية غير كافية = 409، الكل أو لا شيء | Runner |
| **5 - PROOF Requirement 1** | زبون يقرأ طلب غيره = 403، زبون يغيّر الحالة = 403، زبون يضيف/يحذف منتج = 403، إشعار غيره = 403، بلا توكن = 401، توكن مزوّر = 401، ترويسات الأمان، 405، **محاولات دخول غلط لحد 429** | Runner |
| **6 - PROOF Requirement 2** | المستودع واقف = 503، تفاصيل المنتج بتضل شغالة، بطيء = 504، فشل الحفظ = 500 والمخزون رجع | يدوي: كل طلب مكتوب بوصفه شو تعمل قبله |

**تشغيل الإثبات بالـ Collection Runner:** كليك يمين على الكوليكشن ← Run collection ← اختار الفولدرات **0، 2، 3، 4، 5** بهالترتيب ← Run ← خد Screenshot للنتيجة (كلها أخضر).

> طلب "Create product with image": من تبويب Body اختار ملف صورة لحقل `image` (اختياري، بدونه بينجح كمان).
> بعد فولدر 5 (اختبار الـ 429) **استنى دقيقة** قبل أي تسجيل دخول جديد.

---

## 5) سيناريو العرض قدام المعيد (المتطلب الثاني)

### إثبات 1: الـ Race Condition (عدة طلبات بنفس اللحظة على آخر قطعة)
```powershell
docker compose exec inventory node scripts/race-test.js
```
السكريبت: بيسجّل دخول الزبونين، بيحط مخزون المنتج 8 = **1**، وبيبعت **10 طلبات بنفس اللحظة** على `POST /api/v1/orders` (عن طريق Laravel، يعني المسار الكامل: REST ← Laravel ← gRPC ← المستودع). النتيجة المتوقعة:
```
Created: 1 | Rejected (409 insufficient stock): 9 | Other: 0
Final stock of product 8: 0
PASS: exactly one order got the last unit, stock never went below zero.
```
لعدد أكبر: `docker compose exec inventory node scripts/race-test.js --requests=30`
(Postman Runner بيبعت الطلبات وحدة ورا وحدة، فما بيصلح لإثبات التزامن؛ لهيك السكريبت.)

### إثبات 2: شو بيشوف المستخدم لما تتوقف خدمة المستودع
```powershell
docker compose stop inventory
```
- بـ Postman: فولدر 6 ← **A** (إنشاء طلب) ← **503** فوراً برسالة واضحة، وما انعمل طلب.
- **B** (تفاصيل المنتج) ← **200**، المنتج ظاهر و `available_quantity: null` مع رسالة.
- باقي الموقع (تسجيل دخول، عرض المنتجات، الطلبات القديمة) **بيضل شغال**.
```powershell
docker compose start inventory
```

### إثبات 3: المستودع بطيء (المهلة)
```powershell
$env:INVENTORY_DELAY_MS=5000; docker compose up -d inventory
```
- فولدر 6 ← **C** ← **504** بعد **ثانيتين** تقريباً (مو 5) لأن المهلة 2000ms. وزمن الرد ظاهر بـ Postman.
```powershell
$env:INVENTORY_DELAY_MS=0; docker compose up -d inventory
```

### إثبات 4: فشل حفظ الطلب بعد الحجز ← إرجاع الكمية
1. بـ `src/.env`: `SIMULATE_ORDER_SAVE_FAILURE=true` ثم `docker compose exec app php artisan config:clear`
2. فولدر 6 ← **D1** (المخزون قبل) ← **D2** (الطلب ← **500** "reserved stock was returned") ← **D3** (المخزون نفسه ما تغيّر).
3. بلوغ المستودع بيبين الحجز والإرجاع: `docker compose logs inventory --tail 20`
4. رجّع `SIMULATE_ORDER_SAVE_FAILURE=false` و `config:clear`.

> خيار المحاكاة هاد بيشتغل **بس** إذا `APP_ENV=local`، يعني مستحيل يأثر على نسخة إنتاجية.

### إثبات إضافي (اختياري): العقد مباشرة بـ Postman gRPC
Postman ← New ← **gRPC** ← العنوان `localhost:50051` ← Import `.proto` ← اختار `proto/inventory/v1/inventory.proto` ← جرّب `GetStock` بـ `{"product_id": 8}`. هيك بتبيّن إنو الخدمة مستقلة وبتحكي بس عبر العقد.

---

## 6) أسئلة متوقعة بالمقابلة مع أجوبتها

**ليش gRPC مو REST بين الخدمتين؟**
عقد صارم مكتوب بملف proto بيتولّد منه كود الطرفين (ما في أخطاء بأسماء الحقول)، رسائل ثنائية (Protobuf) أصغر وأسرع من JSON، HTTP/2، ودعم أصلي للـ deadline ورموز حالة موحدة (UNAVAILABLE, DEADLINE_EXCEEDED...).

**كيف ولّدتو كود PHP من الـ proto؟**
`protoc -I proto --php_out=src proto/inventory/v1/inventory.proto` بيولّد كلاسات الرسائل بـ `src/Modules/Inventory/Grpc`. الـ Node ما بيحتاج توليد: `@grpc/proto-loader` بيقرا نفس الملف وقت التشغيل.

**ليش طلب حجز واحد لكل منتجات الطلب، مو طلب لكل منتج؟**
لأنه لو حجزنا منتج منتج ونجح الأول وفشل التاني، لازم نرجع الأول يدوياً. بطلب واحد وTransaction وحدة بالمستودع، يا كلشي بينحجز يا ولا شي.

**شو بيصير إذا تأخر المستودع؟**
كل نداء إلو مهلة 2 ثانية (`'timeout'` بالميكروثانية بخيارات gRPC). بعدها المستخدم بياخد 504 واضح، والتطبيق بيبعت إلغاء للحجز احتياطاً.

**ليش كل خدمة إلها قاعدة بيانات؟**
لتكون الخدمة مستقلة فعلاً: بتتطور وبتتوسع لحالها، وأي تعديل بجداولها ما بيكسر التطبيق، والوصول للمخزون بيمر من مكان واحد (العقد) فبيبقى منطق الحجز والقفل بمكان واحد.

**كيف منعتو الـ Race Condition؟ وليش ما استعملتو `SELECT` ثم `UPDATE`؟**
لأن بين القراءة والكتابة في فجوة بتسمح لطلبين يقروا نفس القيمة. `UPDATE ... WHERE quantity >= n` أمر ذري: القاعدة بتقفل السطر وبتعيد فحص الشرط، فبينجح واحد بس.

**ليش في `reservation_id`؟** للـ idempotency (إعادة المحاولة ما بتحجز مرتين)، ولنعرف شو نرجّع بـ `ReleaseStock`، ومحفوظ مع الطلب للتتبع.

**ليش `InventoryGateway` interface؟** باقي التطبيق ما بيعرف إنو في gRPC؛ لو بدنا نغيّر طريقة التواصل أو نعمل نسخة وهمية للاختبار، منغيّر التنفيذ بس.

---

## 7) ملاحظات قبل التسليم و Git

- بـ `.env` للتسليم: `APP_DEBUG=false`، وما تحطو `.env` ولا `vendor` ولا `node_modules` ولا `.idea` ولا `storage/logs` ولا `database/database.sqlite` بالـ zip/المستودع. (الـ rar المرسل كان فيه `.idea` و `database.sqlite` و `laravel.log`.)
- `composer.lock` بعد خطوة `composer update grpc/grpc google/protobuf` **لازم ينرفع على Git** لحتى باقي الفريق يعمل `composer install` بس.
- حسب السلايد 14: مستودع Git واحد، فرع رئيسي محمي، وكل عضو عندو طلب دمج (PR) واحد على الأقل بكل متطلب. تقسيم مقترح للمتطلب الثاني:
  1. العقد `proto/` + توليد كلاسات PHP
  2. خدمة المستودع (`inventory-service/`) + Docker
  3. التكامل بـ Laravel (`Modules/Inventory` + `OrderController` + `ProductController`)
  4. الكوليكشن + سكريبت الـ Race + README

---

## 8) شو انختبر فعلياً وشو لازم تجربه عندك

**انختبر وشغّال:**
- خدمة المستودع كاملة على PostgreSQL حقيقية: العمليات الثلاث، التحقق من المدخلات، الحجز المكرر (idempotency)، الإرجاع المكرر، الكمية غير الكافية مع "الكل أو لا شيء"، رفض الحجز المتأخر بعد الإلغاء.
- **الـ Race Condition:** 20 طلب حجز متزامن على آخر قطعة ← **1 نجح و 19 انرفضوا**، والمخزون صار 0.
- المهلة: نداء بمهلة ثانية على خدمة بطيئة ← `DEADLINE_EXCEEDED`، والخدمة ما نفّذت الحجز، والمخزون ما تغيّر.
- **التوافق بين PHP و Node:** رسالة حجز بنتها كلاسات PHP المولّدة فكّها Node صح، ورد Node فكّته PHP صح.
- منطق `GrpcInventoryGateway` (تحويل الرد، تمرير المهلة 2,000,000 ميكروثانية، تحويل UNAVAILABLE و DEADLINE_EXCEEDED لاستثناء واضح).
- كل ملفات PHP بدون أخطاء صياغة، وكل سكريبتات اختبار الكوليكشن صحيحة.

**ما قدرت شغّلو هون (ما في Docker بالبيئة عندي):** تشغيل Laravel كامل مع امتداد gRPC. لهيك جرّب عندك بالترتيب: الخطوات بالقسم 3، بعدين Runner للفولدرات 0،2،3،4،5، بعدين سكريبت الـ race، بعدين سيناريوهات فولدر 6. وإذا طلع أي خطأ، ابعتلي نص الخطأ أو `docker compose logs app inventory`.

---

## 9) الواجهات (Blade): التشغيل والعرض

### الفكرة
- الصفحات Blade بتنعرض من Laravel على روابط عادية (`/products`, `/login`...)، وما فيها أي منطق ولا بتلمس قاعدة البيانات.
- كل صفحة فيها JavaScript بينادي الـ API نفسها `/api/v1/...` بـ `fetch` (نفس طلبات Postman)، والتوكن (JWT) بينحفظ بالمتصفح بعد تسجيل الدخول وبينبعت مع كل طلب.
- يعني كل قواعد الأمان (401/403/429) لسا عم تتطبق بالـ API. الواجهة بس بتعرض النتيجة، مع رقم الحالة ورسالة الخطأ.
- **الباك إند ما تغيّر فيه ولا سطر.**

### الملفات الجديدة
| الملف | شو هو |
|---|---|
| `src/routes/web.php` | روابط الصفحات (بدّل الملف القديم يلي كان بس بيعرض welcome) |
| `src/resources/views/layouts/app.blade.php` | القالب العام: الشريط العلوي، الرسائل، الفوتر |
| `src/resources/views/pages/*.blade.php` | 12 صفحة |
| `src/public/js/shop.js` | دالة `api()` لمناداة الـ API، الجلسة (JWT)، السلة، الشريط العلوي، عرض الأخطاء |
| `src/public/css/shop.css` | التنسيق (عربي RTL) |

### الصفحات
| الرابط | الوظيفة | الـ APIs |
|---|---|---|
| `/login` | تسجيل الدخول (+ رابط للتأكيد إذا الحساب مو مؤكد) | auth/login |
| `/register` ← `/verify-email` | حساب جديد ثم إدخال كود OTP، وإعادة إرسال الكود | auth/register, verify-email, resend-otp |
| `/forgot-password` | إرسال كود ثم كلمة مرور جديدة | auth/forgot-password, reset-password |
| `/products` | بحث، تصفية بالتصنيف، ترتيب، تقسيم صفحات | products, products/categories |
| `/products/{id}` | التفاصيل + **الكمية من المستودع (gRPC)** + "اطلب الآن" + "أضف إلى السلة" | products/{id}, orders |
| `/cart` | طلب واحد لعدة منتجات (حجز واحد للكل) | orders |
| `/orders`, `/orders/{id}` | طلباتي (والأدمن بيشوف الكل) وتفاصيل الطلب | orders, orders/{id} |
| `/notifications` | الإشعارات، تعليم كمقروء، تعليم الكل | notifications/* |
| `/admin/products` | إضافة/تعديل منتج مع صورة، وحذف | products POST/PATCH/DELETE |
| `/admin/orders` | تغيير حالة الطلب | orders/{id}/status |
| الشريط العلوي | الاسم والدور، عدد الإشعارات غير المقروءة، عدد السلة، تسجيل الخروج | auth/me, auth/logout |

### التشغيل
ما في داعي تعيد بناء Docker، لأنه مجلد `src` مربوط مع الحاوية مباشرة. انسخ الملفات وبعدين:
```powershell
docker compose exec app php artisan optimize:clear
```
وافتح `http://localhost:8000`.

### العرض قدام المعيد من الواجهة
1. **المنتجات:** بحث، تصفية بالتصنيف، تقسيم صفحات (غيّر "بالصفحة" لـ 4).
2. **الزبون** (`customer@shop.test`): افتح منتج ← بيظهر "متوفر: N قطعة" من خدمة المستودع ← أضف منتجين للسلة ← تأكيد الطلب ← صفحة الطلب ← الإشعارات.
3. **كمية غير كافية:** المنتج 8 (فيه قطعة وحدة) ← اطلب 3 ← رسالة **409** "Not enough stock".
4. **طلب غيرك:** سجّل خروج وادخل بـ `customer2@shop.test` ← افتح `http://localhost:8000/orders/1` ← **403**.
5. **صلاحيات الأدمن:** الزبون بيفتح `/admin/orders` ويحاول يغيّر حالة ← **403**. نفس الشي لإضافة منتج من `/admin/products`.
6. **الأدمن** (`admin@shop.test`): بيغيّر حالة الطلب ← الزبون بيوصله إشعار. بيضيف منتج مع صورة. ولما يحذف منتج داخل بطلبات ← **409**.
7. **المستودع واقف:** `docker compose stop inventory` ← صفحة المنتج بتضل شغالة وبتقول "معلومات المخزون غير متاحة"، وزر الطلب بيعطي **503**. بعدها `docker compose start inventory`.
8. **محاولات دخول غلط:** 6 مرات ورا بعض ← **429**. استنى دقيقة بعدها.
9. **الـ Race Condition:** هاد بيضل من السكريبت (`docker compose exec inventory node scripts/race-test.js`)، وبعدها افتح `/admin/orders` بتلاقي طلب واحد بس انعمل للمنتج 8.

> ملاحظة أمان للمقابلة: إخفاء أزرار الأدمن بالواجهة مو حماية. الحماية الحقيقية بالـ API، ولهيك صفحات الأدمن بتنفتح للزبون عمداً للتجربة، والـ API هي يلي بترفض بـ 403.

---

## 10) تطبيق قواعد الـ Modular (بعد ملاحظة المعيد)

### الفكرة بجملة
كل وحدة **بتملك بياناتها**، والوحدات التانية بتوصلها **بس عبر واجهتها العامة**: `Contracts` و `Events` و `Exceptions`. أبداً مو عبر الموديل أو السيرفس أو الجدول تبعها.

### شو تغيّر
| القاعدة | قبل | بعد |
|---|---|---|
| كل وحدة بتسجّل حالها | `ModulesServiceProvider` عام بيحمّل الكل، والربط بـ `AppServiceProvider` | `Modules/<X>/Providers/<X>ServiceProvider.php` لكل وحدة (مساراتها، migrations، config، الربط، الأحداث)، و `bootstrap/providers.php` بس بيعدّهم |
| Orders ← Products | `Product::whereIn(...)` و `belongsTo(Product)` | `ProductCatalog::findMany()` بيرجّع `ProductSummary` (id، اسم، سعر) |
| التحقق من المنتج | `exists:products,id` (جدول وحدة تانية) | `after()` بـ `StoreOrderRequest` عبر `ProductCatalog` |
| عرض الطلب | بيجيب اسم المنتج من جدول المنتجات | **Snapshot**: `order_items.product_name` + `unit_price` بينحفظوا لحظة الطلب |
| Orders ← Notifications | `app(NotificationService::class)->notify(...)` | Orders بتطلق `OrderPlaced` و `OrderStatusChanged`، و Notifications بتسمعهم (`Listeners`) |
| Notifications ← Users | `User::where('role','admin')` | `UserDirectory::adminIds()` |
| Orders ← Users | `$user->isAdmin()` (موديل Users) | `Gate::denies('admin')` (صلاحية بتعرّفها Users) |
| حذف منتج داخل بطلبات | Products بتعتمد على خطأ المفتاح الأجنبي من جدول `order_items` | Products بتطلق `ProductDeleting`، و Orders بتفحص جدولها هي وبترفض بـ `ProductInUseException`، فبيرجع **409** متل قبل |
| المفاتيح الأجنبية بين الوحدات | `orders.user_id→users`، `order_items.product_id→products`، `user_notifications.user_id→users` | انشالوا (migrations جديدة)، وضلت الأعمدة أرقام عليها index |
| Inventory | ربطه بـ `AppServiceProvider` وإعداده بـ `config/inventory.php` | `InventoryServiceProvider` + `Modules/Inventory/Config/inventory.php` |
| Seeders | `DatabaseSeeder` بيعرف موديلات كل الوحدات | `UsersSeeder` و `ProductsSeeder` جوا وحداتهم، و `DatabaseSeeder` بس بينادي عليهم |

**الـ API ما تغيّرت:** نفس الروابط ونفس الردود، والكوليكشن والواجهات بيضلوا شغالين.

### الإثبات قدام المعيد
```powershell
docker compose exec app php artisan test
```
اختبار `tests/Unit/ModuleBoundariesTest.php` فيه 3 قواعد:
1. ممنوع أي وحدة تستعمل من وحدة تانية غير `Contracts` و `Events` و `Exceptions`.
2. ممنوع أي وحدة تقرأ جدول وحدة تانية (`DB::table` أو `join` أو `exists:` أو `unique:`).
3. كل وحدة إلها Provider ومسجّلة بـ `bootstrap/providers.php`.

لما جرّبته على **الكود القديم فشل** وطلّع 7 مخالفات، وعلى الكود الجديد **نجح**.

### أسئلة متوقعة
- **ليش شلتو المفاتيح الأجنبية؟** لأنها بتربط جداول وحدتين ببعض على مستوى قاعدة البيانات، فما بتقدر تفصل وحدة أو تنقلها لقاعدة أو خدمة تانية. القاعدة بين الوحدات صارت تنفرض عبر الـ API، متل حدث `ProductDeleting`.
- **ليش الطلب بيحفظ اسم المنتج وسعره؟** لأنه الطلب لازم يعكس شو اشترى الزبون **وقتها**، حتى لو تغيّر السعر أو الاسم بعدين. وهيك Orders ما بتحتاج تقرأ جدول المنتجات.
- **ليش أحداث بدل نداء مباشر للإشعارات؟** لحتى Orders ما تعتمد على Notifications. منقدر نضيف أو نشيل مستمعين (إيميل، SMS، تحليلات) بدون ما نلمس Orders. وبالمتطلب الرابع منحوّل المستمعين لـ Queue بسهولة.
- **ليش `app/Http/Middleware` لسا برّا الوحدات؟** لأنها بنية مشتركة لكل التطبيق (Shared Kernel)، متل ترويسات الأمان والتحقق من الدور، مو منطق وحدة معيّنة.

---

## 11) المتطلب الثالث
الشرح الكامل بـ `docs/REQ3-AR.md`: الطبقات الأربع، ووين كل شي بالكود، والأوامر، والأرقام، والأسئلة المتوقعة.
وسيناريو المقابلة بـ `docs/INTERVIEW-AR.md` القسم **هـ**.
