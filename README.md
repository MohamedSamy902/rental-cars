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

## ٣) الرفع على cPanel — فرونت + باك كامل (بلا Node على السيرفر)

الاستضافة: **cPanel + MySQL** · SSH مُفضَّل · **لا Node** على السيرفر (البناء محلياً أو في GitHub Actions).

### ٣٫١ ما يُرفع

| على الاستضافة | المحتوى |
|---------------|---------|
| `/home/USER/rpm/` | Laravel كامل: `app` · `vendor` · `artisan` · `.env` … **خارج** الويب |
| `/home/USER/public_html/` | SPA مبنية + `index.php` + `.htaccess` |

دومين واحد يكفي: `/api/*` و `/up` و `/sanctum/*` → Laravel · باقي المسارات → الواجهة.

### ٣٫٢ إنشاء قاعدة MySQL (مرة أولى)

1. **MySQL® Databases** → قاعدة مثل `rpm_prod`.  
2. مستخدم + كلمة مرور قوية → **All Privileges**.  
3. سجّل: القاعدة · المستخدم · كلمة المرور · المضيف غالباً `localhost`.

### ٣٫٣ أول رفع على الإطلاق (فرونت + باك + قاعدة)

من جهازك (فيه Node للبناء فقط — **السيرفر بلا Node**):

```bash
# 1) بعد إنشاء قاعدة MySQL من cPanel:
export CPANEL_SSH_HOST=your-domain.com
export CPANEL_SSH_USER=cpaneluser
export CPANEL_SSH_KEY=~/.ssh/id_rsa

export APP_URL=https://your-domain.com
export DB_DATABASE=USER_rpm_prod
export DB_USERNAME=USER_rpm_user
export DB_PASSWORD='********'
# اختياري: DB_HOST=localhost  DB_PORT=3306

bash scripts/cpanel-first-install.sh
```

هذا السكربت (للتثبيت الأول فقط):
1. يبني الحزمة كاملة (React + Laravel + `vendor`)  
2. يرفع الباك → `~/rpm/` والفرونت → `~/public_html/`  
3. يكتب `.env` للإنتاج على السيرفر  
4. يشغّل `key:generate` · `migrate` · `db:seed` · `config/route:cache`

دخول أولي: `admin@rpm.local` / `password` — **غيّر كلمة المرور فوراً**.

> للتحديثات اللاحقة (بدون seed وبدون استبدال `.env`):  
> `bash scripts/deploy-cpanel-ssh.sh`

### ٣٫٤ بناء الحزمة فقط (رفع يدوي / File Manager)

```bash
bash scripts/build-production.sh
```

ينتج:
- `dist/cpanel/` → `public_html/`
- `dist/cpanel-app/` → `~/rpm/`
- `dist/rpm-cpanel.tar.gz` → أرشيف الاثنين
- `dist/README-DEPLOY.txt`

`vendor` مضمّن افتراضياً — **لا تحتاج Composer ولا Node على الاستضافة**.  
تأكد أن `public_html/index.php` يشير إلى `../rpm` (ملف [`scripts/cpanel-index.php`](scripts/cpanel-index.php)).

### ٣٫٥ PHP و SSL و Cron

1. **MultiPHP Manager** → **PHP 8.2 أو 8.3** لـ `public_html`.  
2. امتدادات: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `curl`.  
3. حدود الرفع: راجع [`docs/cpanel-php-ini.md`](docs/cpanel-php-ini.md).  
4. **SSL/TLS** → AutoSSL.  
5. **Cron Jobs** (كل دقيقة):
   ```cron
   * * * * * cd /home/USER/rpm && php artisan schedule:run >> /dev/null 2>&1
   ```
6. فعّل **Backup** يومي في cPanel.

### ٣٫٦ تحقق بعد الرفع

| فحص | متوقع |
|------|--------|
| `https://your-domain.com/` | شاشة الدخول العربية |
| `https://your-domain.com/api/login` بـ POST | JSON توكن |
| `https://your-domain.com/up` | صحة Laravel |
| رفع صورة عميل | تحت `rpm/storage/app/private/media/` |

### ٣٫٧ تحديث لاحق (بدون مسح البيانات)

```bash
# من جهازك — نفس أوامر SSH أعلاه بدون SEED_FIRST
bash scripts/deploy-cpanel-ssh.sh
```

الـ rsync لا يستبدل `.env` ولا وسائط `storage/app/private/media`.

---

## ٤) CI / CD — نشر تلقائي من `main`

| الجزء | أين يُنشر | متى |
|--------|-----------|-----|
| **فرونت** | Vercel (`ui-pwa`) | تلقائي عند push على `main` (ربط GitHub في Vercel) |
| **باك / API** | cPanel `~/rpm` + `public_html` | تلقائي عبر [`.github/workflows/deploy-cpanel.yml`](.github/workflows/deploy-cpanel.yml) عند push على `main` |

الفرونت على Vercel ينادي `https://rpmrentcar.com/api/*` عبر Rewrite في `ui-pwa/vercel.json`.

### أسرار GitHub (مرة واحدة) — Settings → Secrets → Actions

| السر | قيمة مثال |
|------|-----------|
| `CPANEL_SSH_HOST` | `rpmrentcar.com` |
| `CPANEL_SSH_USER` | `rpmrentcar` |
| `CPANEL_SSH_KEY` | المفتاح الخاص PEM كاملاً |
| `CPANEL_SSH_PORT` | `8090` |
| `CPANEL_PHP_BIN` | `/opt/cpanel/ea-php84/root/usr/bin/php` |

أول رفع: من Actions شغّل **Deploy · cPanel** يدوياً بـ `confirm=deploy` و`seed_first` إن لزم، بعد ما يكون `.env` جاهز على السيرفر. بعدها أي push على `main` يكفي.
---

## ٥) الهيكل

```
rental-cars/
├── backend/                 Laravel API
├── ui-pwa/                  React PWA
├── docs/                    مواصفات ومراجعات
├── scripts/
│   ├── dev.sh                 تشغيل محلي
│   ├── build-production.sh    حزمة cPanel كاملة (فرونت+باك+vendor)
│   ├── cpanel-first-install.sh تثبيت أول مرة (env+seed)
│   ├── deploy-cpanel-ssh.sh    تحديث/نشر SSH/rsync
│   ├── cpanel-post-deploy.sh   migrate/cache على السيرفر
│   ├── env.cpanel.example      قالب .env للإنتاج
│   ├── cpanel.htaccess
│   └── cpanel-index.php
└── .github/workflows/         CI + Deploy
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
