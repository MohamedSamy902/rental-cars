# آر بي إم — نظام إدارة تأجير السيارات

نظام عربي RTL لإدارة الأسطول والعقود والحسابات — جاهز للتشغيل المحلي وللرفع على **cPanel + MySQL**.

| | |
|--|--|
| الواجهة | `ui-pwa/` — React 19 · Vite · PWA |
| الـ API | `backend/` — Laravel · Sanctum · Services |
| المستندات | `docs/` — المعايير · RSD · API · مراجعة المطابقة |
| الدخول التجريبي | `admin@rpm.local` / `password` |

---

## ١) ابدأ من هنا (للمطور)

1. [`docs/معايير-المشروع-الملزمة.md`](docs/معايير-المشروع-الملزمة.md) — المرجع الواحد  
2. [`docs/RSD.md`](docs/RSD.md) — مواصفة العميل  
3. [`docs/API.md`](docs/API.md) — وثائق API  
4. [`docs/مراجعة-المعايير-والبيزنس.md`](docs/مراجعة-المعايير-والبيزنس.md)  
5. [`AGENTS.md`](AGENTS.md)

---

## ٢) التشغيل المحلي (Local)

### المتطلبات
- PHP **8.3+** مع امتدادات: `mbstring`, `pdo_sqlite` (للاختبار) أو `pdo_mysql`, `fileinfo`, `tokenizer`, `xml`, `curl`
- Composer 2  
- Node.js **22+** و npm  
- Git

### أ) أسرع طريقة

```bash
git clone https://github.com/MohamedSamy902/rental-cars.git
cd rental-cars

cd backend
composer install
cp .env.example .env
php artisan key:generate
# للتطوير السريع يمكن التحويل مؤقتاً إلى sqlite في .env:
# DB_CONNECTION=sqlite
# DB_DATABASE=/absolute/path/to/backend/database/database.sqlite
# ثم: touch database/database.sqlite
php artisan migrate --seed

cd ../ui-pwa
npm install

cd ..
./scripts/dev.sh
```

| الخدمة | الرابط |
|--------|--------|
| الواجهة | http://127.0.0.1:3847 |
| API | http://127.0.0.1:8000 |
| دخول | `admin@rpm.local` / `password` |

السكربت يشغّل الـ API والواجهة معاً ويوقفهم عند `Ctrl+C`.

### ب) يدوياً (نافذتان)

```bash
# نافذة 1 — API
cd backend
composer install && cp -n .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000

# نافذة 2 — PWA (فيها proxy لـ /api → :8000)
cd ui-pwa
npm install
npm run dev
```

### ج) اختبارات محلية

```bash
cd backend && php artisan test
cd ui-pwa && npm run lint && npm run build
```

> **ملاحظة:** الإنتاج على cPanel يستخدم **MySQL فقط**. SQLite مسموح محلياً/في PHPUnit فقط.

---

## ٣) الرفع على cPanel — خطوة بخطوة بدقة

### ٣٫١ ما سترفعه

| على الاستضافة | المحتوى |
|---------------|---------|
| `/home/USER/rpm/` | تطبيق Laravel كاملاً (`app`, `vendor`, `artisan`, `.env`, …) **خارج** الويب |
| `/home/USER/public_html/` | واجهة SPA + `index.php` + `.htaccess` (نقطة الدخول العامة) |

 Domains: دومين واحد يكفي — `/api/*` يذهب لـ Laravel وباقي المسارات للواجهة.

### ٣٫٢ تجهيز الحزمة من جهازك أو من CI

```bash
# من جذر المشروع
bash scripts/build-production.sh
```

ينتج:
- `dist/cpanel/` → ارفعه إلى `public_html/`
- `dist/cpanel-app/` → ارفعه إلى `~/rpm/`
- `dist/README-DEPLOY.txt` → ملخص سريع

أو من GitHub Actions: Workflow **CI** على `main` يرفع Artifact باسم `rpm-cpanel-bundle`.

### ٣٫٣ إنشاء قاعدة MySQL من cPanel

1. **MySQL® Databases** → أنشئ قاعدة `rpm_prod` (أو أي اسم).  
2. أنشئ مستخدماً بكلمة مرور قوية واربطه بالقاعدة بـ **All Privileges**.  
3. سجّل: اسم القاعدة · المستخدم · كلمة المرور · المضيف غالباً `localhost`.

### ٣٫٤ رفع الملفات

**File Manager** أو FTP:

1. ارفع محتويات `dist/cpanel-app/` → `/home/USER/rpm/`  
2. ارفع محتويات `dist/cpanel/` → `/home/USER/public_html/`  
   - استبدل `.htaccess` إن طُلب  
3. استبدل `public_html/index.php` بنسخة [`scripts/cpanel-index.php`](scripts/cpanel-index.php)  
4. داخل `public_html/index.php` تأكد أن:
   ```php
   $laravelRoot = __DIR__.'/../rpm';
   ```
   يطابق مسار رفع Laravel.

> إن رفعت `vendor` ضمن الحزمة لا تحتاج Composer على الاستضافة.  
> إن لم ترفعه: من Terminal في cPanel نفّذ `cd ~/rpm && composer install --no-dev --optimize-autoloader`.

### ٣٫٥ ملف البيئة `.env`

في `/home/USER/rpm/`:

```bash
cp .env.example .env
```

عدّل على الأقل:

```env
APP_NAME=RPM
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=USER_rpm_prod
DB_USERNAME=USER_rpm_user
DB_PASSWORD=********

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local
LOG_CHANNEL=stack
LOG_LEVEL=error
```

ثم:

```bash
cd ~/rpm
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force          # أول تثبيت فقط (مستخدم تجريبي + بيانات عرض)
php artisan config:cache
php artisan route:cache
chmod -R 775 storage bootstrap/cache
```

دخول أولي بعد الـ seed: `admin@rpm.local` / `password` — **غيّر كلمة المرور فوراً** من شاشة الموظفين.

### ٣٫٦ PHP و SSL و Cron

1. **MultiPHP Manager** → اختر **PHP 8.2 أو 8.3** لـ `public_html`.  
2. فعّل الامتدادات: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `curl`.  
3. **SSL/TLS** → AutoSSL أو شهادة Let’s Encrypt للدومين.  
4. **Cron Jobs** → أضف (كل دقيقة):
   ```cron
   * * * * * cd /home/USER/rpm && php artisan schedule:run >> /dev/null 2>&1
   ```
5. للنسخ الاحتياطي: فعّل **Backup** اليومي في cPanel أو JetBackup واذكر مكان الحفظ في محضر التسليم (٣٠ يوماً وفق المواصفة).

### ٣٫٧ تحقق بعد الرفع

| فحص | متوقع |
|------|--------|
| `https://your-domain.com/` | شاشة الدخول العربية |
| `https://your-domain.com/api/login` بـ POST | JSON توكن |
| `https://your-domain.com/up` | صحة Laravel |
| رفع صورة عميل | ملف تحت `rpm/storage/app/private/media/` |

### ٣٫٨ تحديث لاحق (بدون مسح البيانات)

1. ابنِ حزمة جديدة `build-production.sh`  
2. ارفع الكود الجديد فوق `~/rpm` **بدون** استبدال `.env` ولا `storage/app`  
3. ارفع أصول `public_html` الجديدة  
4. نفّذ: `php artisan migrate --force && php artisan config:cache && php artisan route:cache`

---

## ٤) CI / CD (GitHub Actions)

| Workflow | الملف | ماذا يفعل |
|----------|--------|-----------|
| **CI** | [`.github/workflows/ci.yml`](.github/workflows/ci.yml) | على كل PR وpush لـ `main`: PHPUnit + Lint + Build · وعلى `main` يصدر Artifact للحزمة |
| **Deploy · cPanel** | [`.github/workflows/deploy-cpanel.yml`](.github/workflows/deploy-cpanel.yml) | نشر FTP يدوي (`workflow_dispatch` مع كتابة `deploy`) — جاهز للتلقائي لاحقاً |

### أسرار النشر (Settings → Secrets → Actions)

| السر | مثال |
|------|------|
| `CPANEL_FTP_HOST` | `ftp.yourdomain.com` |
| `CPANEL_FTP_USER` | مستخدم FTP |
| `CPANEL_FTP_PASSWORD` | كلمة مرور FTP |
| `CPANEL_FTP_REMOTE_DIR` | `/public_html/` |
| `CPANEL_FTP_PORT` | `21` (اختياري) |

> الـ Deploy يرفع محتويات الحزمة المبنية. مسار Laravel خارج `public_html` يُفضَّل رفعه مرة أولى يدوياً أو عبر FTP منفصل لنفس السبب الأمني.

لفتح النشر التلقائي عند كل push على `main`: ألغِ تعليق قسم `push:` داخل `deploy-cpanel.yml`.

---

## ٥) الهيكل

```
rental-cars/
├── backend/                 Laravel API
├── ui-pwa/                  React PWA
├── docs/                    مواصفات ومراجعات
├── scripts/
│   ├── dev.sh               تشغيل محلي
│   ├── build-production.sh  حزمة cPanel
│   ├── cpanel.htaccess
│   └── cpanel-index.php
└── .github/workflows/       CI + Deploy
```

---

## ٦) الوحدات الجاهزة

- تسجيل دخول Sanctum · صلاحيات · لوحة المُدمج (فيتشر فلاج)
- أسطول · عملاء · عقود (اعتماد · تأمين · تسليم · استلام · تمديد · تبديل · إلغاء)
- رفع صور/فيديو محلي · ملاك العربيات (مبلغ شهري · سحب جزئي · رصيد)
- صيانة · مخزون FIFO · مخالفات · انتظار · معرض · إخطار قسم
- مصروفات · عهدة · حسابات · إقفال شهر · تقارير · موارد بشرية · إشعارات

الإنتاج المستهدف: **cPanel + MySQL InnoDB utf8mb4** · Cron بدل Redis/WebSocket/Worker دائم.

---

## ٧) إشعارات الموبايل (Web Push)

القائمة داخل النظام تبقى، ومعها **Push يصل لشاشة الموبايل** عبر PWA (بدون Firebase إجباري · مناسب لـ cPanel).

### تفعيل السيرفر
```bash
cd backend
php artisan push:vapid --write
php artisan migrate --force
php artisan config:clear
```

تأكد أن الدومين على **HTTPS** (AutoSSL في cPanel).

### تفعيل من الموبايل
1. افتح الموقع على Chrome (أندرويد) أو Safari 16.4+ (آيفون بعد **إضافة إلى الشاشة الرئيسية**).  
2. ادخل بحسابك → **الإشعارات** → **تفعيل على هذا الجهاز** → اسمح بالإذن.  
3. اختياري: **إرسال تجربة** للتأكد.

| المنصة | الدعم |
|--------|--------|
| Android Chrome / Edge | ممتاز |
| iPhone/iPad (Safari 16.4+) | بعد تثبيت الـ PWA على الشاشة الرئيسية |
| سطح المكتب Chrome | يعمل أيضاً |

المكتبة: `minishlink/web-push` · المسارات: `/api/push/*` · الاشتراكات في جدول `push_subscriptions`.
