# QaNetwork

> منصة مركزية لبيع كروت وخدمات شبكات الإنترنت، وإدارة البائعين والمحافظ
> والعمولات والتسويات والتكامل مع مزودي الشبكات بصورة آمنة وقابلة
> للتوسع.

**حالة المشروع الحالية:** تأسيس النواة المالية ودورة البيع والمصالحة
الآمنة والـQueue/Scheduler مكتمل ومغطى بالاختبارات.\
**آخر Baseline مؤكد:** `89 passed / 549 assertions`\
**آخر Commit مؤكد:** `81297e2` ---
`Schedule automatic sale reconciliation`

------------------------------------------------------------------------

## 1. ما هو QaNetwork؟

QaNetwork ليس مجرد موقع لعرض كروت الإنترنت، وليس مخزنًا مركزيًا لملايين
الكروت غير المباعة.

الفكرة الأساسية هي بناء **منصة وسيطة مركزية** تربط بين:

1.  **إدارة QaNetwork**.
2.  **مالكي الشبكات / مزودي الخدمة**.
3.  **الشبكات التابعة لكل مالك**.
4.  **البائعين ونقاط البيع**.
5.  **العميل النهائي**.
6.  **MikroTik Hotspot**.
7.  **MikroTik User Manager**.
8.  **واجهات API خارجية لمزودين آخرين**.
9.  **خدمات SMS والتسليم**.
10. **أنظمة الدفع الإلكتروني مستقبلًا**.

الهدف أن يستطيع البائع بيع كرت من شبكة متصلة بالمنصة، بينما تتولى
QaNetwork بصورة ذرية وآمنة:

-   التحقق من البائع.
-   التحقق من الشبكة والمنتج.
-   التحقق من الرصيد.
-   حجز المبلغ.
-   طلب كرت واحد فقط من المزود.
-   التعامل مع timeout والحالات غير المؤكدة.
-   منع البيع المكرر.
-   تثبيت البيانات المالية.
-   حفظ بيانات الكرت بأمان.
-   خصم البائع.
-   إثبات مستحق المزود وربح المنصة.
-   تسليم الكرت.
-   المصالحة التلقائية عند فقدان نتيجة طلب المزود.

------------------------------------------------------------------------

# 2. المبدأ التجاري الأساسي

## لا نخزن الكروت غير المباعة مركزيًا

QaNetwork لا يقوم افتراضيًا باستيراد عشرات أو مئات الآلاف من أسماء
المستخدمين وكلمات المرور مسبقًا.

بدلًا من ذلك:

``` text
البائع يطلب منتجًا
        │
        ▼
QaNetwork يتحقق من الطلب والرصيد
        │
        ▼
يحجز المبلغ
        │
        ▼
يطلب كرتًا واحدًا من الشبكة/المزود
        │
        ▼
يحفظ الكرت المباع فقط
        │
        ▼
يثبت المحاسبة
        │
        ▼
يسلم الكرت للعميل
```

وهذا يقلل:

-   تسريب بيانات الكروت.
-   تضخم قاعدة البيانات.
-   مشاكل مزامنة المخزون.
-   تعارض الكروت بين النظام والمزود.
-   الحاجة لنقل مخزون غير مباع إلى QaNetwork.

------------------------------------------------------------------------

# 3. الأطراف في النظام

## 3.1 مدير النظام

يدير المنصة ككل، بما في ذلك:

-   مالكو الشبكات.
-   الشبكات.
-   البائعون.
-   المنتجات.
-   الاتصالات بالمزودين.
-   قواعد الأسعار والعمولات.
-   الإيداعات.
-   التسويات.
-   العمليات غير المحسومة.
-   المراجعة اليدوية.
-   التقارير.
-   السجلات والتدقيق.

## 3.2 مالك الشبكة

يمكن أن يمتلك شبكة واحدة أو عدة شبكات.

العلاقة المقصودة:

``` text
NetworkOwner
    ├── Network A
    │    ├── Connections
    │    └── Products
    │
    ├── Network B
    │    ├── Connections
    │    └── Products
    │
    └── Network C
```

## 3.3 البائع

لديه محفظة مسبقة الدفع.

لا يسمح له النظام ببيع ما يتجاوز الرصيد المتاح، ويشاهد فقط المعلومات
التجارية الخاصة به مثل:

-   القيمة الاسمية.
-   عمولته.
-   صافي الخصم.
-   حالة العملية.

ولا يجب أن تعرض API الخاصة بالبائع:

-   تكلفة المزود.
-   عمولة المنصة.
-   ربح المنصة.
-   أسرار اتصال الشبكة.

## 3.4 العميل النهائي

يحصل على الكرت عبر وسيلة التسليم المختارة، مثل:

-   الشاشة.
-   SMS.
-   وسائل أخرى مستقبلًا.

------------------------------------------------------------------------

# 4. التقنية الحالية

النواة الحالية مبنية على:

-   **Laravel 13.33.0**
-   **PHP 8.5.1** في بيئة التطوير الحالية
-   **SQLite** محليًا للتطوير
-   **Database Queue**
-   **Database Cache**
-   Laravel Scheduler
-   Git
-   Termux على Android في بيئة التطوير الحالية

الاستضافة الأولية المستهدفة:

-   Hostinger Shared Hosting

مع تصميم يسمح بالانتقال لاحقًا إلى:

-   VPS
-   Supervisor/systemd
-   Redis Queue
-   Cloud infrastructure
-   أكثر من Queue Worker

دون إعادة كتابة منطق الأعمال.

------------------------------------------------------------------------

# 5. الوحدات الرئيسية

## 5.1 الشبكات

تم إنشاء أساس:

-   `network_owners`
-   `networks`
-   `network_connections`
-   `network_products`

### NetworkConnection

يمثل طريقة اتصال QaNetwork بالشبكة أو المزود.

من أهم المفاهيم:

-   `driver`
-   بيانات الاتصال.
-   تفعيل/تعطيل الاتصال.
-   الاتصال الأساسي.
-   credentials مشفرة.

المبدأ:

> أسرار المزود لا تظهر للبائع ولا تحفظ كنص مكشوف في السجلات.

## 5.2 المنتجات

`network_products` تمثل المنتجات الموحدة داخل QaNetwork.

مثال:

``` text
اسم المنتج: 1 جيجا - 24 ساعة
القيمة الاسمية: 1000 YER
```

لكن التنفيذ عند المزود يمكن أن يختلف.

في Hotspot قد يرتبط المنتج بـHotspot Profile.

وفي User Manager قد يرتبط بـProfile/Package مختلف.

وفي API خارجي قد يكون:

``` text
external_product_id = 572
```

وهذا الفصل مقصود حتى لا يعتمد منطق البيع على تقنية المزود.

------------------------------------------------------------------------

# 6. المحافظ والدفعات

تم بناء أساس مالي للبائع يشمل:

-   `sellers`
-   `seller_wallets`
-   `seller_deposits`
-   `seller_ledger_entries`

## 6.1 الإيداع

الإيداع يمر بحالة، مثل:

``` text
pending
approved
rejected
cancelled
```

رفع إيصال لا يعني إضافة الرصيد تلقائيًا.

المبدأ:

``` text
رفع إيصال
   ↓
Pending
   ↓
مراجعة/بوابة دفع
   ↓
Approved
   ↓
Ledger Credit
```

## 6.2 الخدمات المالية الموجودة

تم بناء خدمات مثل:

-   `ApproveSellerDepositService`
-   `RejectSellerDepositService`
-   `AdjustSellerWalletService`
-   `ReverseSellerLedgerEntryService`

## 6.3 لا تعديل صامت للرصيد

الأصل المحاسبي هو الـLedger.

لا نريد:

``` text
wallet.balance = wallet.balance + 5000
```

كعملية أعمال بلا سجل.

بل يجب أن يكون لكل تغيير مالي سبب وقيد قابل للتتبع.

------------------------------------------------------------------------

# 7. نموذج العمولة

مثال:

``` text
القيمة الاسمية          1000
مستحق المزود             800
عمولة البائع              150
عمولة/دخل المنصة           50
-----------------------------
المجموع                  1000
```

صافي ما يخصم من البائع:

``` text
1000 - 150 = 850
```

تم اعتماد فكرة **Financial Snapshot** عند البيع.

أي أن البيع يحتفظ بالأرقام التي كانت سارية وقت العملية، حتى لو تغيرت
الأسعار أو العمولات لاحقًا.

المعادلة الأساسية الحالية:

``` text
provider_amount
+ seller_commission
+ platform_commission
= face_value
```

والأموال تستخدم `decimal(20,4)`، وليس `float/double`.

------------------------------------------------------------------------

# 8. دورة البيع

تم إنشاء جداول:

-   `sales`
-   `sale_financials`
-   `provider_transactions`
-   `sold_cards`
-   `sale_reservations`

## 8.1 Sale

يمثل العملية التجارية الرئيسية.

يحتوي على مفاهيم مثل:

-   Seller
-   Wallet
-   Network
-   Product
-   Reference
-   Idempotency Key
-   Currency
-   Delivery
-   Status

## 8.2 SaleFinancial

Snapshot مالي لا يتغير بتغير الأسعار المستقبلية.

## 8.3 SaleReservation

يمثل حجز رصيد البائع قبل الاتصال بالمزود.

## 8.4 ProviderTransaction

يمثل محاولة العملية لدى المزود، مع:

-   Internal transaction ID.
-   Provider transaction ID.
-   Idempotency key.
-   Attempt count.
-   Provider status.
-   Reconciliation tracking.

## 8.5 SoldCard

يحفظ الكرت الذي تم بيعه فقط.

بيانات الاعتماد الحساسة تحفظ مشفرة.

------------------------------------------------------------------------

# 9. لماذا نحجز الرصيد أولًا؟

لا يجوز إرسال طلب شراء للمزود ثم اكتشاف أن البائع لا يملك رصيدًا.

الدورة الصحيحة:

``` text
Validate
   ↓
Reserve Seller Balance
   ↓
Provider Purchase
   ↓
Provider Confirmed
   ↓
Capture Reservation
   ↓
Complete Sale
```

وفي حالة فشل صريح من المزود:

``` text
Provider FAILED
      ↓
Release Reservation
```

أما في:

``` text
TIMEOUT
UNKNOWN
```

فلا نحرر الرصيد مباشرة، لأن المزود ربما نفذ البيع بالفعل ولكن الرد ضاع.

------------------------------------------------------------------------

# 10. حالات البيع والمزود

التصميم يستوعب حالات مثل:

``` text
CREATED
VALIDATING
BALANCE_RESERVED
PROCESSING_PROVIDER
PROVIDER_CONFIRMED
ACCOUNTING_POSTED
COMPLETED
DELIVERY_SENT

FAILED
TIMEOUT
UNKNOWN_PROVIDER_STATE
RECONCILIATION_REQUIRED
REVERSED
```

القاعدة الأهم:

> Timeout ليس Failure.

فإذا انتهت مهلة HTTP، لا نطلب كرتًا ثانيًا تلقائيًا.

------------------------------------------------------------------------

# 11. Provider Adapter Architecture

تم بناء عقد موحد `ProviderAdapter`.

الغرض أن يكون محرك البيع مستقلًا عن نوع الشبكة.

العقد يغطي مفاهيم:

-   `healthCheck`
-   `getBalance`
-   `getProducts`
-   `checkAvailability`
-   `purchaseCard`
-   `checkTransaction`

المخطط:

``` text
                    ProviderAdapter
                          │
        ┌─────────────────┼──────────────────┐
        │                 │                  │
        ▼                 ▼                  ▼
 MikroTik Hotspot   MikroTik UserManager   External API
```

وبذلك لا يحتوي `ProcessSaleService` على منطق مثل:

``` text
if MikroTik ...
if UserManager ...
if Provider X ...
```

بل يتعامل مع العقد الموحد.

------------------------------------------------------------------------

# 12. MikroTik: الخطة الكاملة

سيتم دعم النوعين:

## 12.1 MikroTik Hotspot

Driver مقترح:

``` text
mikrotik_hotspot
```

يقوم الـAdapter بتحويل منتج QaNetwork إلى إعدادات Hotspot المناسبة.

مثلًا:

``` text
QaNetwork Product
1 GB / 24 Hours
       ↓
MikroTikHotspotAdapter
       ↓
Hotspot Profile
       ↓
Create one user
```

## 12.2 MikroTik User Manager

Driver مقترح:

``` text
mikrotik_user_manager
```

يتم التعامل معه Adapter مستقلًا، مع بقاء دورة البيع والمحاسبة نفسها.

## 12.3 لماذا Adapterان؟

لأن Hotspot وUser Manager ليسا الشيء نفسه.

الفصل يمنع تكوين Adapter ضخم مليء بالشروط، ويسمح باختبار كل تنفيذ بصورة
مستقلة.

## 12.4 معرف العملية

عند إنشاء المستخدم يجب ربطه بمعرف ثابت من QaNetwork قدر الإمكان.

الغرض:

إذا حدث:

``` text
purchase request
      ↓
MikroTik created user
      ↓
network timeout
      ↓
QaNetwork did not receive response
```

فلا ننشئ مستخدمًا جديدًا.

بل:

``` text
checkTransaction(internal_transaction_id)
```

يبحث عن أثر العملية السابقة.

------------------------------------------------------------------------

# 13. مزودو API الخارجيون

MikroTik ليس الخيار الوحيد.

التصميم يستوعب:

``` text
provider_http_api
radius
custom_provider_x
custom_provider_y
```

كل Adapter يحول استجابة المزود إلى DTOs وحالات QaNetwork الموحدة.

------------------------------------------------------------------------

# 14. Registry

تم بناء `ProviderAdapterRegistry`.

وظيفته اختيار الـAdapter الصحيح حسب `network_connections.driver`.

مثال مفاهيمي:

``` text
driver = mikrotik_hotspot
        ↓
MikroTikHotspotAdapter
```

أو:

``` text
driver = mikrotik_user_manager
        ↓
MikroTikUserManagerAdapter
```

أو:

``` text
driver = provider_xyz
        ↓
ProviderXAdapter
```

الـRegistry موجود، لكن **تسجيل Adapters إنتاجية حقيقية ما زال ضمن العمل
القادم**.

------------------------------------------------------------------------

# 15. تنفيذ عملية المزود

تم بناء `PrepareProviderTransactionService`.

وظيفته تجهيز Provider Transaction قبل الاتصال الخارجي.

ثم `ExecuteProviderPurchaseService` ينفذ الاتصال.

من خصائص التصميم الحالية:

1.  قفل/تحقق قاعدة البيانات.
2.  تسجيل أن العملية بدأت.
3.  الخروج من DB transaction قبل الاتصال الخارجي.
4.  استدعاء المزود.
5.  العودة وتثبيت النتيجة.
6.  عدم تحرير الرصيد عشوائيًا.
7.  عدم تخزين رسالة Exception خام حساسة.

------------------------------------------------------------------------

# 16. تثبيت الكرت قبل المحاسبة

إذا أكد المزود نجاح البيع، يجب حفظ credentials المشفرة أولًا قبل اعتبار
المحاسبة مكتملة.

السبب:

إذا تم الخصم ثم تعطل النظام قبل حفظ الكرت، يصبح لدينا بيع مدفوع بلا كرت
قابل للاسترجاع.

لذلك التصميم الحالي يركز على:

``` text
Provider CONFIRMED
       ↓
Persist encrypted sold card
       ↓
Capture financial reservation
       ↓
Complete sale
```

------------------------------------------------------------------------

# 17. Finalization

تم بناء:

-   `FinalizeConfirmedSaleService`
-   `FinalizeFailedSaleService`

### Confirmed

يقوم بتثبيت العملية المالية وإكمال البيع بعد وجود دليل مؤكد من المزود
والكرت المحفوظ.

### Failed

الفشل الصريح فقط يسمح بتحرير الحجز وفق دورة الفشل.

------------------------------------------------------------------------

# 18. ProcessSaleService

هو Orchestrator رئيسي لدورة البيع.

يربط:

``` text
Reservation
   ↓
Prepare Provider Transaction
   ↓
Execute Provider Purchase
   ↓
Confirmed / Failed / Uncertain
   ↓
Finalize where safe
```

ويحترم idempotency والحالات السابقة.

------------------------------------------------------------------------

# 19. المصالحة Reconciliation

هذه من أهم أجزاء QaNetwork.

المشكلة:

``` text
QaNetwork ---- purchase ----> Provider
                             creates card
QaNetwork <---- X ----------- response lost
```

لا نستطيع معرفة النجاح من timeout وحده.

الحل:

``` text
checkTransaction()
```

وليس:

``` text
purchaseCard() مرة أخرى
```

تم بناء:

-   `ReconcileProviderTransactionService`
-   `ReconcileSaleService`
-   `ReconcileSaleJob`

والاختبارات تحتوي حمايات صريحة تؤكد أن المصالحة لا تستدعي
`purchaseCard()`.

------------------------------------------------------------------------

# 20. تتبع محاولات المصالحة

تمت إضافة:

-   `reconciliation_attempt_count`
-   `last_reconciliation_at`
-   `manual_review_required_at`

بعد عدد محدد من المحاولات غير الحاسمة، تنتقل العملية إلى المراجعة
اليدوية بدل الدوران بلا نهاية.

الحد الحالي:

``` text
5 reconciliation attempts
```

------------------------------------------------------------------------

# 21. Backoff

تم بناء Backoff للمصالحة:

``` text
Attempt 1 → 60 seconds
Attempt 2 → 300 seconds
Attempt 3 → 900 seconds
Attempt 4 → 1800 seconds
Attempt 5 → Manual Review
```

هذا يمنع ضرب API المزود باستمرار.

------------------------------------------------------------------------

# 22. منع تكرار Jobs

`ReconcileSaleJob` يستخدم:

-   `ShouldBeUnique`
-   `WithoutOverlapping`

والمفتاح مرتبط بـ`Sale ID`.

تم اختبار ذلك فعليًا باستخدام Database Queue الحقيقي:

``` text
dispatch SALE-X
dispatch SALE-X
```

والنتيجة المؤكدة:

``` text
first_delta  = 1
second_delta = 0
```

أي أن الـdispatch الثاني لنفس البيع لم ينشئ صف Job جديدًا.

------------------------------------------------------------------------

# 23. أمر اكتشاف المصالحات

تم إنشاء:

``` bash
php artisan sales:reconcile
```

وظيفته:

-   اكتشاف Provider Transactions المؤهلة.
-   إرسال `ReconcileSaleJob`.
-   لا يشتري كرتًا.
-   لا يخصم رصيدًا.
-   لا يحرر حجزًا مباشرة.
-   لا يستدعي API المزود مباشرة.

تمت إضافة اختبارات مستقلة له.

------------------------------------------------------------------------

# 24. Scheduler

تم تسجيل:

``` php
Schedule::command('sales:reconcile')
    ->everyMinute()
    ->withoutOverlapping(5);
```

وبالتالي Laravel يرى:

``` text
* * * * * php artisan sales:reconcile
```

حماية `withoutOverlapping()` هنا تخص أمر الاكتشاف نفسه.

أما حماية كل Sale فتتم داخل Job.

------------------------------------------------------------------------

# 25. Queue

الإعداد الحالي:

``` text
QUEUE_CONNECTION=database
```

وتم التحقق من وجود:

-   `jobs`
-   `failed_jobs`
-   `cache`
-   `cache_locks`

كما أن:

``` text
cache.default = database
```

وهذا مهم لأقفال uniqueness/overlap.

------------------------------------------------------------------------

# 26. Worker

تم اختبار:

``` bash
php artisan queue:work database \
  --queue=reconciliation,default \
  --stop-when-empty \
  --tries=1 \
  --timeout=60 \
  --max-time=50
```

واشتغل وخرج بأمان عندما كان الـQueue فارغًا.

الإعداد الحالي:

``` text
queue retry_after = 90 seconds
worker timeout     = 60 seconds
```

وبالتالي `timeout < retry_after`.

عند بناء HTTP Client للمزود ستكون مهلة HTTP أقصر بوضوح من مهلة الـJob.

------------------------------------------------------------------------

# 27. Hostinger Shared Hosting

الخطة الأولية لا تفترض وجود Supervisor دائم.

سيتم استخدام Cron لتشغيل:

## Scheduler

مفهوميًا:

``` bash
cd /ABSOLUTE/PATH/TO/qanetwork && php artisan schedule:run
```

## Queue Worker

مفهوميًا:

``` bash
cd /ABSOLUTE/PATH/TO/qanetwork && \
php artisan queue:work database \
  --queue=reconciliation,default \
  --stop-when-empty \
  --tries=1 \
  --timeout=60 \
  --max-time=50
```

**لا تستخدم مسار Termux في Hostinger.**

يجب اكتشاف:

-   المسار المطلق للمشروع.
-   مسار PHP الصحيح على الخادم.
-   تردد Cron المتاح في الخطة.

وعند الانتقال إلى VPS يمكن استبدال التشغيل الدوري بـWorker دائم تحت
Supervisor/systemd.

------------------------------------------------------------------------

# 28. الأمن

القواعد الأساسية:

## Credentials

-   بيانات اتصال المزود مشفرة.
-   بيانات الكرت المباع مشفرة.
-   لا تظهر للبائع إلا البيانات المسموح بها.
-   لا تسجل credentials في logs.

## Exceptions

لا تحفظ رسائل Exceptions الخام القادمة من المزود إذا كان من الممكن أن
تحتوي معلومات حساسة.

الفحوص الحالية تؤكد عدم استخدام مسار unsafe المعروف في خدمات المزود.

## HTTPS

الإنتاج يجب أن يستخدم HTTPS.

## Secrets

الأسرار لا توضع داخل Git.

## Idempotency

كل عملية حساسة يجب أن تكون قابلة للتكرار الآمن دون مضاعفة الأثر المالي
أو شراء كرت ثانٍ.

------------------------------------------------------------------------

# 29. ما تم إنجازه

حتى آخر checkpoint مؤكد تم إنجاز:

-   بنية مالكي الشبكات.
-   الشبكات.
-   اتصالات الشبكات.
-   المنتجات.
-   البائعين.
-   المحافظ.
-   الإيداعات.
-   Ledger مالي.
-   اعتماد/رفض الإيداع.
-   تعديلات مالية مسجلة.
-   أساس reversal.
-   جداول البيع.
-   Financial Snapshot.
-   حجز رصيد البيع.
-   Capture للحجز.
-   Release الآمن.
-   Provider transaction lifecycle.
-   Provider Adapter contract.
-   Provider Adapter Registry.
-   Purchase DTOs/statuses.
-   Prepare provider transaction.
-   Execute provider purchase.
-   حفظ الكرت المشفر.
-   Finalize confirmed sale.
-   Finalize failed sale.
-   ProcessSale orchestration.
-   Reconciliation service.
-   Reconciliation orchestration.
-   تتبع محاولات المصالحة.
-   Queue Job للمصالحة.
-   Backoff.
-   Manual review threshold.
-   `ShouldBeUnique`.
-   `WithoutOverlapping`.
-   اختبار uniqueness باستخدام Database Queue الحقيقي.
-   `sales:reconcile`.
-   اختبارات الأمر.
-   Laravel Scheduler.
-   Database Queue.
-   Database Cache Locks.
-   اختبار Queue Worker.

آخر Baseline مؤكد:

``` text
89 tests passed
549 assertions
```

------------------------------------------------------------------------

# 30. سجل Checkpoints المهمة

من الـcommits المؤكدة في مسار التطوير:

``` text
5ca03b8  Initial foundation
f60217b  Build network provider core schema
eb7431f  Build seller wallet and ledger foundation
50eb9d3  Add atomic seller deposit approval service
a6eae96  Complete seller financial ledger foundation
5f48b3   Build sales transaction core schema
a6ec580  Build sale financial snapshot and reservation lifecycle
46f8edd  Build provider purchase and sale finalization workflow
7f24ed3  Harden provider reconciliation and confirmed sale recovery
683bfb3  Add idempotent sale provider orchestration
b349f73  Add safe sale reconciliation orchestration
1caa173  Track provider reconciliation attempts
690af03  Add queued sale reconciliation with safe backoff
d078c5a  Add safe reconciliation discovery command
81297e2  Schedule automatic sale reconciliation
```

------------------------------------------------------------------------

# 31. ما لم يتم بعد

المشروع **ليس جاهزًا للإنتاج الكامل حتى الآن**.

أهم الأجزاء القادمة:

## Provider Integration

-   MikroTik shared connection layer.
-   RouterOS client abstraction.
-   MikroTik Hotspot Adapter.
-   MikroTik User Manager Adapter.
-   External HTTP Provider Adapter pattern.
-   Adapter registration في Registry.
-   Provider credentials validation.
-   Connection health testing.
-   HTTP/network timeout policy.

## Products

-   Mapping بين QaNetwork Product وHotspot Profile.
-   Mapping مع User Manager.
-   مزامنة/قراءة منتجات المزود عند الحاجة.
-   Availability rules.

## API

-   Authentication.
-   Seller endpoints.
-   Admin endpoints.
-   Network owner endpoints.
-   Sale endpoint.
-   Deposit endpoints.
-   Reporting endpoints.
-   Strict response resources لإخفاء الحقول الداخلية.

## Delivery

-   SMS provider abstraction.
-   SMS queue.
-   retry مستقل للتسليم.
-   منع إعادة البيع عند فشل SMS.

## Accounting

-   Provider payable ledger.
-   Platform revenue ledger.
-   settlement workflows.
-   business-specific sale reversal.
-   reconciliation between accounting ledgers.

## Admin

-   Dashboard.
-   manual review queue.
-   provider health.
-   failed jobs.
-   unsettled sales.
-   seller management.
-   network configuration.

## Security

-   RBAC.
-   rate limiting.
-   audit log.
-   secret management.
-   production hardening.

## Deployment

-   Production database.
-   `.env`.
-   migrations.
-   PHP path.
-   project path.
-   Cron.
-   queue processing.
-   backups.
-   monitoring.
-   logs/alerts.

------------------------------------------------------------------------

# 32. خطة MikroTik القادمة

المرحلة القادمة تقسم إلى طبقات.

## المرحلة A --- Shared MikroTik Infrastructure

إنشاء طبقة مشتركة تحتوي على:

-   Connection configuration.
-   Host/IP.
-   API port.
-   TLS policy.
-   Username.
-   Password/secret.
-   connect timeout.
-   request timeout.
-   sanitized exceptions.
-   RouterOS client interface.

لا نربط المحاسبة بهذه الطبقة.

## المرحلة B --- Fake RouterOS Client

قبل الاتصال بجهاز حقيقي، نبني Fake/Mock قابلًا للاختبار.

نختبر:

-   نجاح الاتصال.
-   فشل authentication.
-   timeout.
-   malformed response.
-   disconnect.
-   duplicate request.

## المرحلة C --- Hotspot Adapter

تنفيذ:

``` text
healthCheck
getBalance
getProducts
checkAvailability
purchaseCard
checkTransaction
```

وفق ما ينطبق على Hotspot.

عملية البيع:

``` text
Product
  ↓
Profile mapping
  ↓
Generate deterministic/safe credentials
  ↓
Create one Hotspot user
  ↓
Attach QaNetwork transaction marker
  ↓
Read back / verify
  ↓
Return CONFIRMED
```

## المرحلة D --- User Manager Adapter

Adapter منفصل يطبق العقد نفسه، لكن بأوامر User Manager المناسبة.

## المرحلة E --- Real Device Integration Tests

على جهاز MikroTik تجريبي:

1.  health check.
2.  read-only tests.
3.  profile discovery.
4.  test user creation.
5.  transaction lookup.
6.  intentional timeout simulation.
7.  reconciliation.
8.  cleanup.

لا نبدأ باختبار مالي حقيقي مباشرة.

------------------------------------------------------------------------

# 33. API خارجي لمزود آخر

سيكون بإمكاننا إضافة Adapter مثل:

``` text
ProviderXAdapter
```

دون تعديل:

-   Wallet.
-   Reservation.
-   SaleFinancial.
-   ProcessSaleService.
-   ReconcileSaleService.

وهذا هو الهدف الأساسي من Provider abstraction.

------------------------------------------------------------------------

# 34. حالات الفشل التي يجب أن يتحملها النظام

QaNetwork يجب أن يتعامل مع:

-   انقطاع الإنترنت.
-   API timeout.
-   المزود غير متاح.
-   رد JSON غير صالح.
-   credentials خاطئة.
-   product غير موجود.
-   product غير متاح.
-   الرصيد غير كافٍ.
-   duplicate seller request.
-   duplicate queue dispatch.
-   worker crash.
-   application crash بعد تأكيد المزود.
-   application crash قبل المحاسبة.
-   response lost after provider success.
-   SMS failure.
-   cron overlap.
-   queue retry.
-   manual review.

ولا يجوز أن تؤدي أي من هذه الحالات تلقائيًا إلى:

-   شراء كرتين.
-   خصم البائع مرتين.
-   تحرير رصيد عملية ربما نجحت.
-   فقدان credentials المؤكدة.
-   تسريب أسرار المزود.

------------------------------------------------------------------------

# 35. قواعد لا يجوز كسرها

1.  **لا نستخدم float للأموال.**
2.  **لا نعدل الرصيد بلا Ledger.**
3.  **لا نعتبر timeout فشلًا صريحًا.**
4.  **لا نكرر purchaseCard أثناء reconciliation.**
5.  **لا نظهر provider cost للبائع.**
6.  **لا نسجل credentials في logs.**
7.  **لا نخزن مخزونًا ضخمًا من الكروت غير المباعة بلا ضرورة.**
8.  **لا ننفذ network call داخل DB transaction طويلة.**
9.  **لا نعتبر receipt اعتمادًا ماليًا.**
10. **لا نغيّر snapshot المالي لبيع قديم بسبب تغيير السعر الحالي.**
11. **لا نفعل Cron الإنتاج قبل جاهزية Adapter الإنتاجي.**
12. **لا نخلط فشل التسليم بفشل البيع.**

------------------------------------------------------------------------

# 36. التسليم Delivery

التسليم مرحلة منفصلة عن البيع.

مثال:

``` text
Sale COMPLETED
      ↓
Delivery Pending
      ↓
SMS
      ↓
DELIVERY_SENT
```

إذا فشل SMS:

``` text
Sale remains COMPLETED
Delivery retries
```

ولا يحدث:

``` text
purchase another card
```

------------------------------------------------------------------------

# 37. التوسع المستقبلي

التصميم يستهدف نموًا كبيرًا:

-   مئات/آلاف الشبكات.
-   آلاف/عشرات آلاف المنتجات.
-   آلاف البائعين.
-   ملايين المبيعات.

ومع الانتقال إلى VPS يمكن تطوير البنية إلى:

``` text
Web/API nodes
     │
     ├── Redis
     ├── Queue Workers
     ├── Scheduler
     ├── Database
     └── Monitoring
```

مع بقاء Domain/Application logic الأساسي.

------------------------------------------------------------------------

# 38. التسلسل المقترح من الآن

``` text
[منجز]
Core schema
   ↓
Seller finance
   ↓
Sales lifecycle
   ↓
Provider abstraction
   ↓
Idempotent provider purchase
   ↓
Reconciliation
   ↓
Queue
   ↓
Unique jobs
   ↓
Scheduler
   ↓

[التالي]
Shared MikroTik client layer
   ↓
Hotspot Adapter
   ↓
User Manager Adapter
   ↓
Real MikroTik read-only tests
   ↓
Controlled purchase test
   ↓
External provider adapter pattern
   ↓
Seller/Admin API
   ↓
SMS delivery
   ↓
Provider/platform accounting ledgers
   ↓
Admin/manual review
   ↓
Security hardening
   ↓
Hostinger staging
   ↓
Cron + Queue
   ↓
End-to-end staging
   ↓
Production
```

------------------------------------------------------------------------

# 39. Production Checklist

قبل فتح النظام للبيع الحقيقي:

-   [ ] Production `.env`
-   [ ] `APP_ENV=production`
-   [ ] `APP_DEBUG=false`
-   [ ] APP_KEY آمن
-   [ ] HTTPS
-   [ ] Production DB
-   [ ] Backup policy
-   [ ] migrations
-   [ ] queue database/production queue configured
-   [ ] cache locking
-   [ ] provider adapters registered
-   [ ] MikroTik/API credentials encrypted
-   [ ] bounded network timeouts
-   [ ] health checks
-   [ ] seller authentication
-   [ ] RBAC
-   [ ] rate limiting
-   [ ] audit
-   [ ] SMS retry
-   [ ] manual review UI
-   [ ] failed job monitoring
-   [ ] scheduler trigger
-   [ ] queue consumer
-   [ ] reconciliation tested
-   [ ] duplicate purchase test
-   [ ] duplicate debit test
-   [ ] provider timeout test
-   [ ] backup restore test
-   [ ] controlled end-to-end sale

------------------------------------------------------------------------

# 40. تعريف النجاح

عملية البيع الناجحة في QaNetwork يجب أن تحقق جميع الآتي:

``` text
Exactly one commercial sale
Exactly one provider card
Exactly one seller debit
Exactly one financial snapshot
Exactly one durable sold-card record
Correct provider payable
Correct seller commission
Correct platform revenue
Recoverable provider uncertainty
Retryable delivery
Complete audit trail
No credential leakage
```

------------------------------------------------------------------------

# 41. الحالة الحالية باختصار

QaNetwork تجاوز مرحلة "إنشاء جداول بيع".

النواة الحالية أصبحت تحتوي على **دورة مالية + دورة مزود + idempotency +
durable card persistence + reconciliation + queue + backoff +
uniqueness + scheduler**.

الجزء الحاسم التالي ليس إضافة Cron أو واجهة جميلة؛ بل **بناء طبقة
الاتصال الإنتاجية بالمزودين**، وأولها:

``` text
MikroTik Common Layer
       ├── Hotspot
       └── User Manager
```

ثم اختبارها على جهاز حقيقي بصورة مضبوطة قبل السماح ببيع فعلي.

------------------------------------------------------------------------

## License

يحدد لاحقًا قبل النشر العام أو توزيع المصدر.
