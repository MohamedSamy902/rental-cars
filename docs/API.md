# وثائق API — RPM Rental Cars

| البند | القيمة |
|--------|--------|
| الإصدار | ١٫١ |
| التاريخ | ٢٩ أكتوبر ٢٠٢٦ |
| القاعدة | `https://{host}/api` |
| المصادقة | Laravel Sanctum — Bearer Token |
| اللغة | رسائل الخطأ والعقود عربية |
| العملة | مبالغ صحيحة بالجنيه (قروش = لا — أعداد صحيحة) |

> مصدر الحقيقة للتنفيذ: `backend/routes/api.php` + Controllers تحت `backend/app/Http/Controllers/Api/`.  
> البيزنس يُنفَّذ في Services فقط — انظر `docs/معايير-المشروع-الملزمة.md` قسم ز + ن.

---

## ١) اتفاقيات عامة

### ١٫١ Headers
```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer {token}   # لكل المسارات عدا POST /login
```

### ١٫٢ أشكال الاستجابة
| الحالة | المعنى | الشكل الشائع |
|--------|---------|---------------|
| `200` | نجاح | `{ "data": ..., "message"?: "..." }` |
| `201` | إنشاء | نفس أعلاه |
| `401` | غير مصادق | `{ "message": "..." }` |
| `403` | ممنوع / فيتشر مطفأ | `{ "message": "..." }` |
| `404` | غير موجود | |
| `422` | فاليديشن أو قاعدة بيزنس | `{ "message": "...", "errors"?: { "field": ["..."] } }` |

### ١٫٣ Pagination (حيث ينطبق)
```json
{
  "data": [ ... ],
  "meta": { "current_page": 1, "last_page": 3, "total": 120 }
}
```
Query شائع: `?per_page=50&page=1&search=...`

### ١٫٤ المبالغ والتواريخ
- المبالغ: **integer** بالجنيه المصري (بدون كسور).
- التواريخ: ISO-8601 أو `YYYY-MM-DD` / `datetime-local` حسب الحقل.
- الحالات: قيم إنجليزية ثابتة في JSON (`pending`, `active`…) مع تسميات عربية في الواجهة.

---

## ٢) المصادقة والصلاحيات

### `POST /login` — عام
**Body**
```json
{ "email": "admin@rpm.local", "password": "password" }
```
**Response `200`**
```json
{
  "token": "1|...",
  "user": {
    "id": 1,
    "name": "مدير النظام",
    "email": "admin@rpm.local",
    "role": "مدير أعلى",
    "role_name": "super_admin",
    "permissions": ["vehicles.view", "contracts.approve", "..."],
    "is_integrator": true
  }
}
```

### `GET /me` — مصادق
نفس كائن `user` أعلاه.

### `POST /logout` — مصادق
يلغي التوكن الحالي. `{ "message": "تم تسجيل الخروج." }`

**ملاحظات صلاحيات**
- الكتالوج: `App\Support\PermissionCatalog` (مفاتيح `module.action`).
- `is_integrator = true` فقط لدور `super_admin` (لوحة المُدمج).
- حالياً الصلاحيات تُرجَع للفرونت؛ **لا يوجد middleware API يفرضها على كل مسار** (فجوة أمنية موثَّقة في المراجعة).

---

## ٣) لوحة اليوم

### `GET /dashboard`
```json
{
  "center_name": "...",
  "occupancy_pct": 40,
  "fleet_total": 4,
  "fleet_status": { "available": 1, "reserved": 1, "rented": 1, "maintenance": 1, "out_of_service": 0 },
  "kpis": {
    "available": 1, "rented": 1, "returning_today": 1,
    "expected_revenue": 0, "customers": 3,
    "active_contracts": 1, "open_work_orders": 1,
    "open_violations": 0, "low_stock": 0
  },
  "tasks": [{ "id": 1, "title": "...", "subtitle": "...", "status": "active", "at": "..." }],
  "important_tasks": [
    {
      "id": "contract_pending:12",
      "rule": "contract_pending",
      "severity": "critical",
      "title": "اعتماد حجز معلّق · C-12",
      "subtitle": "عميل · ماركة موديل · لوحة",
      "href": "/contracts/12",
      "due_on": "2026-11-05"
    }
  ],
  "fleet_prep": { "horizon_days": 5, "days": [], "totals": {} },
  "daily_cash": {
    "date": "2026-11-08",
    "totals": { "in": 1500, "out": 500, "net": 1000, "count": 3 },
    "entries": [
      {
        "id": "payment:1",
        "source": "payment",
        "direction": "in",
        "amount": 1500,
        "title": "قبض إيجار / دفعة عقد",
        "subtitle": "…",
        "method": "نقدي",
        "at": "…",
        "href": "/contracts/12"
      }
    ]
  },
  "unread_notifications": 1
}
```

`fleet_prep.groups.maintenance` ليوم «اليوم» = كل أوامر الشغل المفتوحة/`in_progress`؛ للأيام التالية = المجدولة بـ `next_service_on`.

صفوف `pickups` / `returns` وأعدادها تُحذف بلا `contracts.view`. `customer` و`customer_phone` يُصفَّران بلا `customers.view`. صلاحية العملاء وحدها لا تُظهر مواعيد العقود. عنوان صيانة «تبديل بسبب عطل» يُخفي رقم العقد بلا `contracts.view`. `kpis.returning_today` يكون `null` بلا `contracts.view`.

هوية المالك والمبلغ الشهري على العربية: الاسم/الهاتف/`owner_id` مع `owners.view`؛ المبلغ مع `owners.view` أو `reports.view` أو `accounting.view`. تاريخا عقد المالك يبقيان.

`daily_cash` سجل حركة فلوس اليوم (دفعات + مصروفات) حسب صلاحيات `payments.view`/`accounting.view` و`expenses.view` — أو `null` إن لا صلاحية. بلا `contracts.view` يُحذف رقم العقد وعبارة `عقد #المعرّف` من التفصيل (تبقى «دفعة عقد»)، ويصبح `href` للعقد `null`. رابط قبض/رد التأمين هو `/contracts/{contract_id}` وليس معرّف التأمين.

`important_tasks` قائمة مهام تشغيلية للوحة اليوم (حد أقصى ٢٠)، مرتّبة بالحرج ثم التاريخ، ومفلترة بصلاحيات المستخدم الحالي:

| `rule` | صلاحية | متى يظهر |
|--------|--------|----------|
| `contract_overdue` / `contract_pending` / `contract_pickup` / `contract_return` | `contracts.view` | استلام متأخر · حجز معلّق · تسليم/استلام اليوم |
| `work_order_open` / `maintenance_due` | `maintenance.view` أو `vehicles.view` | أمر شغل مفتوح · صيانة مستحقة خلال ٧ أيام |
| `license_expiring` | `vehicles.view` | رخصة تسيير ≤ ٣٠ يوماً |
| `sim_recharge` | `vehicles.view` | شحن شريحة تتبع ≤ ١٤ يوماً |
| `owner_agreement_end` | `vehicles.view` أو `owners.view` | عقد مالك ≤ ٣٠ يوماً |
| `violation_open` | `violations.view` | مخالفة غير مدفوعة/مؤكّدة |
| `low_stock` | `inventory.view` | صنف تحت حد التنبيه |

---

## ٤) الإعدادات

### `GET /settings/tenant`
يعيد صف `tenant_settings` كاملاً:
- `center_name`, `logo_path`, `print_footer`
- `multi_branch`, `inventory_mode` (`unified` | `per_branch`)
- `colors` (object)
- `feature_flags` (object boolean)
- `operational` (object تشغيل يومي)

### `PUT /settings/tenant`
**Body (جزئي مسموح)**
```json
{
  "center_name": "مركز آر بي إم للتأجير",
  "print_footer": "نسخة داخلية",
  "multi_branch": false,
  "inventory_mode": "unified",
  "colors": { "brand": "#0D9488" },
  "feature_flags": { "showroom": true, "petty_cash": true },
  "operational": {
    "default_daily_km": 200,
    "excess_km_rate": 5,
    "currency": "EGP",
    "block_expired_license": true,
    "block_expired_vehicle_docs": true,
    "require_handover_signature": true,
    "require_return_signature_always": false
  }
}
```
**Response:** `{ "data": {...}, "message": "تم حفظ إعدادات المركز." }`

> فيتشر فلاج / تعدد فروع / ألوان = لوحة المُدمج فقط في الواجهة.  
> API لا يفرّق بعد بين مُدمج ومدير معرض على هذا المسار (فجوة موثَّقة).

---

## ٥) الموظفون والصلاحيات

### مستخدمو النظام
| Method | Path | وصف |
|--------|------|-----|
| GET | `/staff` | قائمة + `summary` + pagination — `?search=&active=&role_id=` |
| POST | `/staff` | إنشاء مستخدم |
| PUT | `/staff/{user}` | تحديث |

**POST /staff Body** — `phone` إلزامي · `email` اختياري (`null` مسموح)
```json
{
  "name": "أحمد",
  "phone": "0100...",
  "email": null,
  "password": "secret123",
  "role_id": 1,
  "employee_id": null,
  "is_active": true,
  "session_minutes": 480,
  "create_employee": true,
  "job_title": "مبيعات",
  "hired_on": "2026-01-01",
  "base_salary": 5000
}
```

### الموظفون (HR سجل تشغيلي)
| Method | Path |
|--------|------|
| GET | `/employees/lookup` | `{id,name,job_title}` نشطون فقط |
| GET | `/employees` | قائمة مصفحة `?search=&status=` |
| GET | `/employees/{employee}` | ملف كامل (حساب · سلف · عهدة · رواتب · تقييمات) — واجهة `/staff/:id` |
| POST | `/employees` | إنشاء |
| PUT | `/employees/{employee}` | تحديث |

### الأدوار
| Method | Path |
|--------|------|
| GET | `/roles` | أدوار + كتالوج صلاحيات مجمّع حسب module |
| POST | `/roles` | `{ "name", "label" }` |
| PUT | `/roles/{role}/permissions` | `{ "permission_ids": [1,2,3] }` |

---

## ٦) المركبات

| Method | Path | وصف |
|--------|------|-----|
| GET | `/vehicles` | قائمة + summary — `status=license_expiring` (٣٠) · `sim_recharge` (١٤) · `owner_agreement_expiring` (٣٠) · حقول `license_expires_on` · `tracker_sim_*` · `owner_agreement_*` · `summary.*` |
| GET | `/vehicles/catalogs` | ماركات + بيانات الإضافة + `next_code` |
| POST | `/vehicles/brands` | إضافة ماركة للقائمة الرئيسية من نموذج العربية |
| POST | `/vehicles` | إضافة (كود تلقائي · لوحة مقسومة · مالك مضمّن اختياري) |
| GET | `/vehicles/{vehicle}` | تفاصيل + accessories + documents |
| PUT | `/vehicles/{vehicle}` | تعديل (الكود الداخلي غير قابل للتغيير) · عند تغيير رخصة التسيير (تاريخ/صورة) تُؤرشف السابقة تلقائياً ثم تُكتب الجديدة |
| DELETE | `/vehicles/{vehicle}` | أرشفة ناعمة (apiResource) |
| POST | `/vehicles/{vehicle}/documents` | مستند (`type` · `document_number` · وش/ضهر · تواريخ) |
| PUT | `/vehicles/{vehicle}/documents/{id}` | تحديث؛ رخصة تسيير مع `archive_previous` تؤرشف السجل القديم (يشمل رقم الرخصة) |
| GET | `/vehicles/{vehicle}/document-history` | سجل الرخص/المستندات المؤرشفة |
| POST | `/vehicles/{vehicle}/accessories` | مستلزم |

**حالات مشتقة `status`:** `available` | `reserved` | `rented` | `maintenance` | `out_of_service`

**POST /vehicles — حقول أساسية**
`plate_letters` + `plate_numbers` (أو `plate` موحّد للتوافق) · `brand` · `model` · `year` · `category` · `fuel_type` · `odometer` · `daily_rate` · `default_deposit`  
الكود: يُولَّد تلقائياً إن لم يُرسل.  
اختياري: `chassis_number`, `engine_number`, `tracker_*`, `color`, `transmission`, `notes`, `is_active`, `owner_id`, `owner_monthly_amount`, `owner_agreement_starts_on`, `owner_agreement_ends_on`, `owner_inline{full_name,phone,…}`, `addon_ids[]`, `license{has_license,document_number,issued_on,expires_on,file_path,file_path_back,notes}` — عند ربط مالك تُرجع الاستجابة أيضاً `owner_agreement` (عقد إدارة وتشغيل متعدد الصفحات مملوء ببيانات المركز/المالك/العربية) للطباعة. `GET /vehicles/{id}/owner-agreement/print`  
**ملغى:** `rental_priority` · قائمة الانتظار · `fuel_level` في الفحص.

**POST documents**
```json
{ "type": "رخصة تسيير", "file_path": "manual/...", "issued_on": "2025-01-01", "expires_on": "2027-01-01", "notes": null }
```

**PUT documents (تجديد رخصة)**
```json
{ "type": "رخصة تسيير", "issued_on": "2026-01-01", "expires_on": "2027-01-01", "archive_previous": true }
```

**POST accessories**
```json
{ "name": "طفاية حريق", "default_qty": 1, "required_each_rental": true }
```

### `GET /qr?type=vehicle&id={id}` أو `type=customer`
```json
{ "data": { "qr": "https://.../qr/vehicles/1", "label": "هيونداي إلنترا · أ ب ج 1234" } }
```

أصل الرابط = `PUBLIC_APP_URL`، أو `APP_URL` إن لم يكن `localhost` / `127.0.0.1`. لا يُؤخذ من ترويسة الطلب. `type=customer` يبقى خلف `customers.view`.

عند إنشاء عقد أو تسجيل دفعة جزئية على عقد: إن بقي رصيد تُرسل الواجهة `balance_due_on` (تاريخ تحصيل الباقي) إلزامياً. الـ API يقبل الحقل اختيارياً للتوافق مع المسارات القديمة. عند السداد الكامل يُمسح الحقل تلقائياً ويتوقف تنبيه الاستحقاق.

### `GET /public/vehicles/{id}/qr-card` (عام — بلا توكن)
بطاقة الزائر بعد مسح QR: مركز (`name` · `tagline` · `phone` · `address` · لوجو عام إن وُجد · سوشيال) + `current_renter` (اسم/هاتف) عند عقد `active` فقط، وإلا `is_rented: false` بلا رسالة «غير مؤجرة». إن نقص الهاتف/العنوان في الإعدادات تُستخدم قيم `config/rpm.php` الاحتياطية. يتضمن `credit` (اسم المطوّر + واتساب). بلا أرقام قومية ولا مبالغ.

---

## ٧) العملاء

| Method | Path |
|--------|------|
| GET | `/customers` | `?search=&status=active|blacklist&per_page=` |
| POST | `/customers` | تسجيل |
| GET | `/customers/{customer}` | |
| PUT | `/customers/{customer}` | تعديل / قائمة سوداء |
| DELETE | `/customers/{customer}` | أرشفة ناعمة |

**POST Body**
`full_name`, `phone`, `birth_date`, `license_number`, `license_expires_on`  
اختياري: `phone_alt`, `email`, `national_id`, `national_id_expires_on`, `passport_number`, `passport_expires_on`, `address`, `notes`, `national_id_image_path`, `license_image_path`

> مسارات الصور تُملأ عادةً من `POST /media` (مثل `/media/{id}/file`) — التخزين محلي خاص بلا CDN خارجي.

**PUT قائمة سوداء**
```json
{ "status": "blacklist", "blacklist_reason": "تأخير سداد متكرر" }
```
إعادة تنشيط: `{ "status": "active" }`

> إنشاء عقد يرفض العميل بحالة `blacklist` أو `blacklisted`.

---

## ٨) العقود والحجوزات (دورة الحياة)

| Method | Path | الحالة المتوقعة |
|--------|------|------------------|
| GET | `/contracts` | قائمة + summary — `?status=&search=` |
| POST | `/contracts` | إنشاء → `pending` |
| GET | `/contracts/{id}` | تفاصيل + deposit + inspections |
| POST | `/contracts/{id}/approve` | → `approved` + تثبيت أسعار |
| ~~POST `/contracts/{id}/pricing`~~ | **ملغى** — لا تعديل أسعار بعد الإنشاء |
| POST | `/contracts/{id}/handover` | → `active` + فحص تسليم |
| POST | `/contracts/{id}/return` | → `completed` + إجمالي نهائي |
| POST | `/contracts/{id}/cancel` | → `cancelled` (قبل التسليم) |
| POST | `/contracts/{id}/extend` | عقد تمديد جديد |
| POST | `/contracts/{id}/swap` | إغلاق قديم + عقد بديل |
| POST | `/contracts/{id}/deposit` | قبض تأمين |
| POST | `/contracts/{id}/deposit/deduct` | خصم من التأمين |
| POST | `/contracts/{id}/deposit/return` | رد متبقي |
| GET | `/contracts/{id}/print` | بيانات الطباعة |

### POST /contracts
```json
{
  "customer_id": 1,
  "vehicle_id": 2,
  "guarantor_customer_id": null,
  "starts_at": "2026-11-01T10:00:00",
  "planned_ends_at": "2026-11-05T10:00:00",
  "daily_rate": 1000,
  "daily_km_allowance": 200,
  "excess_km_rate": 5,
  "deposit": 3000,
  "min_days": 2,
  "discount": 0,
  "other_fees": 0,
  "other_fees_description": null,
  "notes": null
}
```

### POST …/approve
بدون body. يتحقق من:
- رخصة عميل غير منتهية (إن فُعّل)
- مستندات سيارة غير منتهية (إن فُعّل)
- الرخصة/المستند لا تنتهي قبل نهاية العقد المخططة
- لا تعارض حجز

### POST …/handover
```json
{
  "odometer_out": 18200,
  "notes": null,
  "signature_data": "data:image/png;base64,...",
  "accessories": [
    { "name": "مثلث تحذير", "expected_qty": 1, "actual_qty": 1, "ok": true }
  ],
  "damages": [
    { "position": "الصدام الأمامي", "description": "خدش قديم" }
  ]
}
```
التوقيع إلزامي افتراضياً (`operational.require_handover_signature`). بلا `fuel_level`.

### POST …/return
```json
{
  "odometer_in": 18600,
  "returned_at": null,
  "damage_fee": 100,
  "notes": null,
  "signature_data": "...",
  "accessories": [],
  "damages": [{ "position": "الباب", "description": "خدش جديد" }]
}
```
التوقيع إلزامي عند أضرار / نقص مستلزمات / `damage_fee > 0` أو إن فُعّل الإلزام الدائم.

**الإجمالي النهائي (Service):**  
`إيجار المدة − خصم + كم زيادة + أضرار + رسوم تأمين تشغيل + رسوم أخرى`  
الوديعة **خارج** الإجمالي. لا فرق وقود ولا غرامة تأخير منفصلة (التأخير = أيام إيجار موسومة).

### POST …/cancel
```json
{ "reason": "طلب العميل" }
```

### POST …/extend
```json
{ "planned_ends_at": "2026-11-10T10:00:00", "starts_at": null }
```
ينشئ **عقداً جديداً** مربوطاً بالأصل.

### POST …/swap
```json
{
  "replacement_vehicle_id": 3,
  "odometer_in": 18600,
  "odometer_out": 5000,
  "planned_ends_at": null,
  "damage_fee": 0
}
```

### وديعة
```json
// deposit
{ "amount": 3000, "method": "cash", "receipt_number": "R-1", "collection_ref": null }
// deduct
{ "reason": "أضرار", "amount": 100 }
// return
{ "method": "cash", "receipt_number": "R-RET-1" }
```

### GET …/print
```json
{
  "data": {
    "tenant": { "center_name": "...", "print_footer": "..." },
    "contract": { /* علاقات كاملة: customer, vehicle.documents, accessories, deposit, inspections.damages */ }
  }
}
```

---

## ٩) وضع المعرض · إخطار القسم · السجل

### `GET /showroom`
يتطلب `feature_flags.showroom !== false`.  
يعيد السيارات **المتاحة فقط** بلا أسعار:
```json
{
  "data": {
    "center_name": "...",
    "vehicles": [
      { "id": 1, "brand": "هيونداي", "model": "إلنترا", "year": 2023, "category": "اقتصادي", "fuel_type": "بنزين", "status": "available", "available": true }
    ]
  }
}
```

### `GET /department-notice`
Query: `filter=all|one|today|week|range`  
- `one` → `customer_id`  
- `range` → `from` & `to` (dates)  
يعيد `statements[]` لطباعة **بيان سيارة مؤجرة** (مستأجر · قومي · نوع/رقم السيارة · مدة · تواريخ · عنوان · ملاحظات) مع ترويسة المفوض والهواتف.  
يتطلب `feature_flags.department_notice !== false`.

### `GET /audit-logs`
آخر سجلات `audit_logs` بجمل عربية + خيارات فلترة.

Query:
- `limit` (1–200، افتراضي 50)
- `user_id` — مستخدم نفّذ الحركة
- `action` — مفتاح نوع الحدث (مثل `handover` · `created`)
- `date_from` / `date_to` — تاريخ `YYYY-MM-DD` على `created_at`

الاستجابة: `{ data: [{ …, summary, user_name, user_role_title, user_phrase }], meta: { users: [{id,name,role_title,label}], actions: [{value,label}], filters } }`.

`summary` بصيغة عربية: «قام {الدور/المسمى} ({الاسم}) ب…» — يُفضَّل `employee.job_title` ثم تسمية الدور السردية.

---

## ١٠) العهدة النقدية

| Method | Path |
|--------|------|
| GET | `/petty-cash` |
| GET | `/petty-cash/{account}` | ملف عهدة: `account` + `summary` (حد · تغذية · منصرف · مرتجع · رصيد) + `ledger[]` موحّد زمنياً (تغذية/مصروف/صيانة/تسوية) مع عربية وروابط وأرصدة بعد الحركة |
| POST | `/petty-cash` | `{ employee_id, limit_amount, balance? }` |
| POST | `/petty-cash/{account}/move` | `{ type: topup\|spend\|adjust\|return, amount, notes? }` |

`spend` و`return` يرفضان إن المبلغ > الرصيد (`422`). `return` = رد/تسوية نقدية تخصم من الرصيد.

---

## ١١) الحسابات والدفعات

| Method | Path |
|--------|------|
| GET | `/accounting/overview` | شهور · حسابات · مدفوعات |
| GET | `/accounting/months/{year}/{month}` | دفتر الشهر: إيجار · رسوم · مصروفات · تأمينات · مدفوعات عملاء · رواتب · مخالفات متابعة · سجل إقفال |
| POST | `/accounting/lock-month` | `{ year, month }` |
| POST | `/accounting/reopen-month` | `{ year, month, reason }` |
| GET | `/accounting/payments` | آخر ١٠٠ |
| POST | `/accounting/payments` | حركة |

**POST payment**
```json
{
  "amount": 500,
  "method": "cash",
  "direction": "in",
  "paid_at": null,
  "reference": null,
  "link_type": "contract",
  "link_id": 1,
  "notes": null
}
```

### `GET /reports/summary`
KPIs: ذمم · مدفوعات · عقود · أسطول · إيجار حسب الشهر · مخالفات مفتوحة · `occupancy_pct` (أيام مؤجّرة ÷ سعة الفترة) · …

### تقارير P1 (١٥٫٦)
| Method | Path | ملاحظة |
|--------|------|--------|
| GET | `/reports/profitability-compare?month=YYYY-MM` | مقارنة ربحية شهر بشهر (أسطول + سيارات) |
| GET | `/reports/trial-balance?from=&to=` | ميزان مراجعة |
| GET | `/reports/income-statement?from=&to=` | قائمة دخل |
| GET | `/reports/payment-methods?from=&to=` | أرصدة طرق الدفع |
| GET | `/reports/customers/{customer}/statement` | كشف عميل + `credit_balance` |
| GET | `/reports/petty-cash-balances` | أرصدة العهدة |

`GET /dashboard` يضمّن `occupancy_pct` بنفس المعادلة و`profitability_compare` جاهزة لضغطة واحدة.

---

## ١٢) الصيانة والمخزون

### أوامر الشغل
| Method | Path |
|--------|------|
| GET | `/work-orders?status=` |
| POST | `/work-orders` | فتح |
| POST | `/work-orders/{id}/close` | إغلاق + FIFO |

**POST work-order**
```json
{
  "vehicle_id": 1,
  "workshop": "ورشة النور",
  "opened_at": "2026-10-30T14:30:00",
  "opened_on": "2026-10-30",
  "odometer": 89000,
  "puts_in_maintenance": true,
  "next_service_on": "2027-04-30",
  "next_service_odometer": null,
  "force_after_resolution": false,
  "labor_cost": 0,
  "notes": null,
  "lines": [
    {
      "description": "زيت",
      "part_source": "stock",
      "inventory_item_id": 1,
      "quantity": 4,
      "unit_price": 200,
      "from_petty_cash": false
    },
    {
      "description": "قطعة مشتراة",
      "part_source": "purchase",
      "quantity": 1,
      "unit_price": 850,
      "from_petty_cash": true,
      "petty_cash_employee_id": 2,
      "petty_cash_amount": 850
    }
  ]
}
```

### المخزون
| Method | Path |
|--------|------|
| GET | `/inventory` | أصناف + `remaining` + `is_low` |
| POST | `/inventory/items` | صنف |
| PUT | `/inventory/items/{id}` | تعديل صنف (اسم · كود اختياري · تصنيف · وحدة · حد تنبيه) |
| POST | `/inventory/batches` | دفعة استلام |

**Batch:** `inventory_item_id`, `received_on`, `quantity`, `unit_cost` (**مطلوب ≥ ١**)، اختياري `supplier`, `payment_method`, `from_petty_cash`, `reference`, `notes`

---

## ١٣) المخالفات (متابعة تشغيلية)

| Method | Path |
|--------|------|
| GET | `/violations` |
| POST | `/violations` | تسجيل مع إسناد حيازة تلقائي إن أمكن |
| PUT | `/violations/{id}` | تعديل (تاريخ · عقد · مبلغ · مكان · حالة · مرفق) |
| POST | `/violations/{id}/confirm` | تأكيد سداد دون قيد |

```json
{
  "vehicle_id": 1,
  "occurred_at": "2026-10-20T12:00:00",
  "location": "القاهرة",
  "amount": 200,
  "notes": null,
  "contract_id": null
}
```

---

## ١٤) الوسائط (صور وفيديو)

رفع محلي على قرص `local` الخاص (`storage/app/private`) — مناسب لـ cPanel · بلا S3/CDN خارجي افتراضياً.

| Method | Path | الوصف |
|--------|------|--------|
| POST | `/media` | رفع ملف (`multipart/form-data`) |
| GET | `/media/{id}` | بيانات الملف |
| GET | `/media/{id}/file` | بث الملف (مصادق · inline) |
| DELETE | `/media/{id}` | حذف ناعم + إزالة من التخزين |

**POST حقول**
- `file` (مطلوب) — صورة: jpeg/png/webp/gif ≤ ٥ ميجا · فيديو: mp4/webm/mov ≤ ٥٠ ميجا
- `collection` — مثل `customers_id` · `vehicle_docs` · `inspection_damage` · `general`
- `purpose` — `national_id` · `license` · `document` · `damage` · …
- `attachable_type` — `customer` · `vehicle` · `contract` · `inspection`
- `attachable_id` — رقم الكيان (اختياري)

**استجابة مختصرة**
```json
{
  "data": {
    "id": 1,
    "kind": "image",
    "mime_type": "image/jpeg",
    "path": "media/customers_id/….jpg",
    "url": "https://…/api/media/1/file",
    "collection": "customers_id",
    "purpose": "national_id"
  },
  "message": "تم رفع الملف."
}
```

صور الهوية (`purpose` = national_id/license أو `collection` = customers_id) تتطلب صلاحية عرض عملاء / حسّاس.

---

## ١٥) الإشعارات

### `GET /attention`
عدّادات خفيفة لنقاط الانتباه في القائمة وتبويبات الصفحات. بلا أثر جانبي (لا استحقاق جماعي للملاك).

| الحقل | المعنى | النقطة |
|--------|--------|--------|
| `overdue_returns` | عقود نشطة تجاوزت `planned_ends_at` | العقود · حرج |
| `pending_approvals` | عقود `pending` | العقود · حرج |
| `returning_today` / `pickups_today` | راجعة اليوم / تسليم اليوم | تبويب العقود |
| `open_work_orders` | أوامر شغل `open` أو `in_progress` | الصيانة · حرج |
| `blocking_work_orders` | أوامر توقف السيارة | ضمن الصيانة |
| `low_stock` | أصناف رصيدها ≤ حد إعادة الطلب | المخزون · تحذير |
| `open_violations` | مخالفات ليست `paid` ولا `confirmed` | المخالفات · تحذير |
| `unread_notifications` | إشعارات المستخدم الحالي بلا `read_at` | الإشعارات |
| `pending_hr_acks` | إسناد `pending_ack` | الموارد البشرية · حرج |
| `draft_payroll_runs` | مسيرات `draft` | الموارد البشرية · تحذير |
| `blacklisted_customers` | عملاء `blacklist` | العملاء · تحذير |
| `owners_unpaid` | ملاك استحقاقهم المسجّل أكبر من مجموع الصرف | الملاك · تحذير |

العهدة والمصروفات وإخطار القسم بلا طابور معلّق في البيانات، فلا نقطة لها.

### Notifications
| Method | Path |
|--------|------|
| GET | `/notifications` | آخر ٥٠ + `unread` |
| GET | `/notifications/unread-count` | `{ "unread": N }` |
| POST | `/notifications/{id}/read` | تعليم مقروء (مالك الإشعار فقط — حماية IDOR) |

الماسح `php artisan notifications:scan` يشتغل كل ساعة من `schedule:run` (Cron على cPanel). يرسل الإشعار الأول عند تحقق الشرط ثم تذكيراً كل ٢٤ ساعة لنفس السجل ونفس القاعدة، مع **توزيع على نافذة ١٣:٠٠→٠١:٠٠** (١٢ شريحة) حتى لا تتجمّع كل التنبيهات في دقيقة واحدة. خارج النافذة لا إرسال. يشمل: بداية حجز معلّق/معتمد أو نهاية عقد نشط قبلها بيومين، والمستحقات المالية (عقد، تأمين، مالك، راتب معتمد، مخالفة)، ونقص المخزون، والمستندات، والصيانة. Web Push يُرسل مع نفس الصف إن وُجد VAPID.

### Web Push (موبايل)
صلاحية `notifications.view` · يحتاج VAPID في `.env` (`php artisan push:vapid --write`) وHTTPS. `VAPID_SUBJECT` لازم يكون `https://` لنفس الموقع؛ عنوان `mailto` على نطاق غير عام (مثل `.local`) يرفضه آيفون بـ 403.

| Method | Path | الوصف |
|--------|------|--------|
| GET | `/push/vapid-public-key` | المفتاح العام للمتصفح |
| GET | `/push/status` | `{ enabled, devices }` |
| POST | `/push/subscribe` | تسجيل جهاز `{ endpoint, keys:{p256dh,auth} }` |
| POST | `/push/unsubscribe` | إلغاء جهاز |
| POST | `/push/test` | إرسال إشعار تجريبي. المتصفح لازم يتفعّل من الصفحة أولاً (ترميز aes128gcm). من غير جهاز مشترك يرجع 422 برسالة تطلب التفعيل، ومن غير مفاتيح VAPID رسالة منفصلة. |

---

## ١٦) خريطة الوحدات ↔ المسارات

| وحدة RSD | مسارات API | واجهة PWA |
|----------|------------|-----------|
| أسطول | `/vehicles*` | `/vehicles` |
| عقود | `/contracts*` | `/bookings` |
| تقويم | عبر contracts | `/calendar` |
| معرض | `/showroom` | `/showroom` |
| QR | `/qr` · `/public/vehicles/{id}/qr-card` | `/qr/vehicles/{id}` (تفاصيل إن مسجّل · بطاقة زائر إن لا) |
| فحص/توقيع | handover/return | Bookings sheet |
| وديعة | `/contracts/.../deposit*` | Bookings |
| عملاء/سوداء | `/customers` | `/customers` |
| مخالفات | `/violations` · `/violation-inquiries` | `/violations` · `/violation-inquiries` |
| صيانة | `/work-orders` | `/maintenance` |
| مخزون | `/inventory*` | `/inventory` |
| عهدة | `/petty-cash*` | `/petty-cash` |
| حسابات | `/accounting*` | `/accounts` |
| تقارير | `/reports/summary` | `/reports` |
| موظفون/صلاحيات | `/staff` `/roles` `/employees` | `/staff` · `/staff/:id` |
| إخطار قسم | `/department-notice` | `/department-notice` |
| إعدادات | `/settings/tenant` | `/settings` |
| مُدمج | نفس settings + flags | `/integrator` |
| سجل | `/audit-logs` | `/audit-logs` |
| إشعارات | `/notifications*` · `/push*` | `/notifications` (قائمة + تفعيل Push) |
| وسائط | `/media*` | رفع في العملاء/المركبات/الأضرار |
| ملاك العربيات | `/owners*` | `/owners` |

---

## ١٦‑ب) ملاك العربيات (عربيات الغير)

بعض العربيات ليست ملك المركز — تُربط بمالك وتُستحق له مبلغ شهري ثابت عند إضافة/تعديل المركبة. يمكن صرف جزء من المستحق في أي يوم، مع رصيد: مستحق · مصروف · متبقي.

| Method | Path | صلاحية |
|--------|------|--------|
| GET | `/owners` | `owners.view` |
| POST | `/owners` | `owners.create` |
| GET | `/owners/{id}` | `owners.view` — عربيات + استحقاقات + سحوبات + رصيد |
| PUT | `/owners/{id}` | `owners.update` |
| GET | `/owners/{id}/balance?month=YYYY-MM` | `owners.view` — قراءة فقط، بلا ترحيل |
| POST | `/owners/{id}/accruals` | `owners.accrue` أو `owners.payout` — `{ "through": "YYYY-MM" }` اختياري. Idempotent. شهر مقفل = 422 بلا كتابة. المبلغ = أيام مدنية شاملة في الشهر ÷ أيام ذلك الشهر × الشهري؛ الشهر الكامل = الشهري تماماً. لا شهر جارٍ. لا إعادة كتابة لمستحق قائم |
| POST | `/owners/{id}/payouts` | `owners.payout` (حسّاسة) |
| GET | `/owner-payouts` | `owners.view` |

**على المركبة:** `owner_id` · `owner_monthly_amount` · `owner_since` · عقد المالك (`owner_agreement_*`)

**حقول المالك:** اسم · هاتف · هاتف بديل · رقم قومي · بطاقة وش/ضهر · ملاحظات. **بلا رخصة على المالك** — رخصة التسيير على العربية (`vehicle_documents.document_number` + وش/ضهر + انتهاء).

**ترحيل محاسبي**
- استحقاق شهري: مدين `5200` مصروف إيجار ملاك · دائن `2100` مستحقات ملاك
- صرف: مدين `2100` · دائن صندوق `1000` أو بنك `1100`

**POST /owners/{id}/payouts**
```json
{ "amount": 3000, "paid_on": "2026-10-15", "payment_method": "cash", "vehicle_id": 12, "notes": "دفعة جزئية" }
```
يرفض إن تجاوز المتبقي. فتح الملف أو أي GET لا ينشئ استحقاقاً ولا قيد يومية.

`per_page` على القوائم المرقّمة: عدد صحيح من ١ إلى ٢٠٠. صفر أو سالب أو غير رقمي أو أكبر من ٢٠٠ = 422. `page` أقل من ١ أو غير رقمي = 422.

---

## ١٧) أخطاء بيزنس شائعة (`422`)

| رسالة (مثال) | السبب |
|---------------|--------|
| السيارة محجوزة بعقد متعارض | Overlap |
| العميل في القائمة السوداء | status blacklist |
| رخصة العميل منتهية | اعتماد |
| مستند سيارة منتهٍ | اعتماد |
| توقيع العميل على الشاشة إلزامي | فحص |
| عداد الخروج أقل من عداد السيارة | تسليم |
| عداد العودة أقل من عداد الخروج | استلام |
| رصيد العهدة غير كافٍ | spend |
| وضع المعرض غير مفعّل | feature flag |

---

## ١٨) بيانات تجريبية

```
POST /login
email: admin@rpm.local
password: password
```
يُزرع عبر `php artisan db:seed` (`DemoSeeder`).

---

*نهاية وثائق API — الإصدار ١٫١ — يُحدَّث مع أي مسار جديد في نفس الـ PR*

---

## ١٩) إضافات إغلاق الفجوات (٢٫٠)

| Method | Path | صلاحية / ملاحظة |
|--------|------|------------------|
| PUT | `/settings/operational` | `settings.update` — تشغيل يومي فقط |
| PUT | `/integrator/tenant` | دور `super_admin` فقط |
| GET/POST/PUT | `/expenses` · `/expenses/{id}` | `expenses.view` / `create` / `update` — صيانة ↔ `work_order_id` · عهدة `from_petty_cash`+`employee_id` |
| GET/POST | `/payroll` · `/payroll/draft` · `/payroll/{id}/approve` | `hr.salaries` / `hr.payroll_approve` |
| GET/POST/DELETE | `/salary-advances` · `/salary-advances/{id}` | `hr.advances` — سلف الشهر تُخصم من المسودة |
| POST | `/payroll/lines/{id}/payout` | صرف جزئي أو كامل بعد الاعتماد؛ المتبقي يُرحَّل |
| GET/POST | `/employee-assignments` · `…/acknowledge` | `hr.custody` — تأكيد استلام الأدمن |
| GET | `/vehicles/recommend` | العربيات المتاحة في الفترة (بلا أوزان/درجات) |
| GET/POST | `/vehicles/{id}/tires|batteries|downtime|consumables` | أصول المركبة ٦٫٩–٦٫١١ · ١٤٫١ |
| GET/POST | `/consumable-templates` | قوالب قطع استهلاكية |
| ~~GET/POST `/evaluations`~~ | **موقوف** — لا مسارات؛ التقييمات خارج التشغيل |
| GET/POST | `/chat` | خلف `feature:chat` · حد 100/يوم |
| POST | `/ocr/review` | مراجعة حقول داخلية بلا إرسال خارجي |
| POST | `/print-snapshots` | حفظ نسخة وقت الطباعة |
| GET | `/vehicles/{id}/estimated-odometer` | عداد تقديري أثناء التأجير |

كل المسارات الحساسة محمية بـ `permission:` و/أو `feature:` و/أو `integrator`.

صلاحيات HR الدقيقة: `hr.salaries` · `hr.custody` · `hr.advances` · `hr.expenses` (حساسة حيث ينطبق).
