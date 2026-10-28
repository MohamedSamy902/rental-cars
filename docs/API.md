# وثائق API — RPM Rental Cars

| البند | القيمة |
|--------|--------|
| الإصدار | ١٫٠ |
| التاريخ | ٢٨ أكتوبر ٢٠٢٦ |
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
  "unread_notifications": 1
}
```

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

**POST /staff Body**
```json
{
  "name": "أحمد",
  "email": "a@rpm.local",
  "phone": "0100...",
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
| GET | `/vehicles` | قائمة + summary حالات — `?search=&status=&per_page=` |
| POST | `/vehicles` | إضافة |
| GET | `/vehicles/{vehicle}` | تفاصيل + accessories + documents |
| PUT | `/vehicles/{vehicle}` | تعديل |
| DELETE | `/vehicles/{vehicle}` | أرشفة ناعمة (apiResource) |
| POST | `/vehicles/{vehicle}/documents` | مستند |
| POST | `/vehicles/{vehicle}/accessories` | مستلزم |

**حالات مشتقة `status`:** `available` | `reserved` | `rented` | `maintenance` | `out_of_service`

**POST /vehicles — حقول أساسية**
`code`, `plate`, `brand`, `model`, `year`, `category`, `fuel_type`, `odometer`, `daily_rate`, `default_deposit`  
اختياري: `rental_priority`, `tracker_device_number`, `tracker_sim_number`, `color`, `transmission`, `notes`, `is_active`

**POST documents**
```json
{ "type": "رخصة تسيير", "file_path": "manual/...", "issued_on": "2025-01-01", "expires_on": "2027-01-01", "notes": null }
```

**POST accessories**
```json
{ "name": "طفاية حريق", "default_qty": 1, "required_each_rental": true }
```

### `GET /qr?type=vehicle&id={id}` أو `type=customer`
```json
{ "data": { "qr": "https://.../showroom?vehicle=1", "label": "هيونداي إلنترا · أ ب ج 1234" } }
```

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
اختياري: `phone_alt`, `email`, `national_id`, `national_id_expires_on`, `passport_number`, `passport_expires_on`, `address`, `notes`

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
  "fuel_level": 70,
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
التوقيع إلزامي افتراضياً (`operational.require_handover_signature`).

### POST …/return
```json
{
  "odometer_in": 18600,
  "returned_at": null,
  "damage_fee": 100,
  "fuel_level": 40,
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
يتطلب `feature_flags.department_notice !== false`.

### `GET /audit-logs?limit=50`
آخر سجلات `audit_logs`.

---

## ١٠) العهدة النقدية

| Method | Path |
|--------|------|
| GET | `/petty-cash` |
| POST | `/petty-cash` | `{ employee_id, limit_amount, balance? }` |
| POST | `/petty-cash/{account}/move` | `{ type: topup\|spend\|adjust, amount, notes? }` |

`spend` يرفض إن المبلغ > الرصيد (`422`).

---

## ١١) الحسابات والدفعات

| Method | Path |
|--------|------|
| GET | `/accounting/overview` | شهور · حسابات · مدفوعات |
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
KPIs: ذمم · مدفوعات · عقود · أسطول · إيجار حسب الشهر · مخالفات مفتوحة · …

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
  "odometer": 89000,
  "puts_in_maintenance": true,
  "force_after_resolution": false,
  "labor_cost": 300,
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
| POST | `/inventory/batches` | دفعة استلام |

**Batch:** `inventory_item_id`, `received_on`, `quantity`, `unit_cost`, اختياري `supplier`, `payment_method`, `from_petty_cash`, `reference`, `notes`

---

## ١٣) المخالفات (متابعة بلا ترحيل محاسبي)

| Method | Path |
|--------|------|
| GET | `/violations` |
| POST | `/violations` | تسجيل مع إسناد حيازة تلقائي إن أمكن |
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

## ١٤) قائمة الانتظار · الإشعارات

### Waitlist
| Method | Path |
|--------|------|
| GET | `/waitlist` |
| POST | `/waitlist` | `{ customer_id, category, preferred_vehicle_id?, desired_start?, desired_end?, priority?, notes? }` |

الحالة الابتدائية: `waiting` · انتهاء افتراضي بعد ٧ أيام.

### Notifications
| Method | Path |
|--------|------|
| GET | `/notifications` | آخر ٥٠ + `unread` |
| GET | `/notifications/unread-count` | `{ "unread": N }` |
| POST | `/notifications/{id}/read` | تعليم مقروء (مالك الإشعار فقط — حماية IDOR) |

---

## ١٥) خريطة الوحدات ↔ المسارات

| وحدة RSD | مسارات API | واجهة PWA |
|----------|------------|-----------|
| أسطول | `/vehicles*` | `/vehicles` |
| عقود | `/contracts*` | `/bookings` |
| تقويم | عبر contracts | `/calendar` |
| انتظار | `/waitlist` | `/waitlist` |
| معرض | `/showroom` | `/showroom` |
| QR | `/qr` | من تفاصيل مركبة |
| فحص/توقيع | handover/return | Bookings sheet |
| وديعة | `/contracts/.../deposit*` | Bookings |
| عملاء/سوداء | `/customers` | `/customers` |
| مخالفات | `/violations` | `/violations` |
| صيانة | `/work-orders` | `/maintenance` |
| مخزون | `/inventory*` | `/inventory` |
| عهدة | `/petty-cash*` | `/petty-cash` |
| حسابات | `/accounting*` | `/accounts` |
| تقارير | `/reports/summary` | `/reports` |
| موظفون/صلاحيات | `/staff` `/roles` `/employees` | `/staff` |
| إخطار قسم | `/department-notice` | `/department-notice` |
| إعدادات | `/settings/tenant` | `/settings` |
| مُدمج | نفس settings + flags | `/integrator` |
| سجل | `/audit-logs` | `/audit-logs` |
| إشعارات | `/notifications*` | `/notifications` |

---

## ١٦) أخطاء بيزنس شائعة (`422`)

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

## ١٧) بيانات تجريبية

```
POST /login
email: admin@rpm.local
password: password
```
يُزرع عبر `php artisan db:seed` (`DemoSeeder`).

---

*نهاية وثائق API — الإصدار ١٫٠ — يُحدَّث مع أي مسار جديد في نفس الـ PR*

---

## ١٨) إضافات إغلاق الفجوات (٢٫٠)

| Method | Path | صلاحية / ملاحظة |
|--------|------|------------------|
| PUT | `/settings/operational` | `settings.update` — تشغيل يومي فقط |
| PUT | `/integrator/tenant` | دور `super_admin` فقط |
| GET/POST | `/expenses` | `expenses.view` / `expenses.create` |
| GET/POST | `/payroll` · `/payroll/draft` · `/payroll/{id}/approve` | HR |
| GET/POST | `/evaluations` | خلف `feature:evaluations` |
| GET/POST | `/chat` | خلف `feature:chat` · حد 100/يوم |
| POST | `/ocr/review` | مراجعة حقول داخلية بلا إرسال خارجي |
| POST | `/print-snapshots` | حفظ نسخة وقت الطباعة |
| GET | `/vehicles/{id}/estimated-odometer` | عداد تقديري أثناء التأجير |

كل المسارات الحساسة محمية بـ `permission:` و/أو `feature:` و/أو `integrator`.
