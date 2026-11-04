# تفعيل CI/CD — GitHub → cPanel (لايف)

| | |
|--|--|
| الهدف | بناء الفرونت+الباك على GitHub Actions ورفع الناتج جاهزاً إلى cPanel |
| Node على cPanel | **غير مطلوب** — البناء كله على GitHub |
| Workflows | `CI` · `Deploy · cPanel` |
| الحزمة | `dist/cpanel` → `public_html` · `dist/cpanel-app` → `~/rpm` |

---

## أ) مرة واحدة على cPanel (قبل أي Deploy)

### ١) MySQL
1. **MySQL® Databases** → أنشئ قاعدة + مستخدم + **All Privileges**.
2. سجّل: `DB_DATABASE` · `DB_USERNAME` · `DB_PASSWORD` · المضيف غالباً `localhost`.

### ٢) مجلدات الرفع
من **File Manager** أو Terminal:

```bash
mkdir -p ~/rpm ~/public_html
```

- `~/rpm` = Laravel (خارج الويب)
- `~/public_html` = الواجهة + `index.php`

### ٣) PHP و SSL
1. **MultiPHP Manager** → PHP **8.2 أو 8.3** على `public_html`.
2. امتدادات: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `curl`.
3. حدود الرفع: راجع [`cpanel-php-ini.md`](cpanel-php-ini.md).
4. **SSL/TLS** → AutoSSL للدومين.

### ٤) Cron (بديل الـ worker)
**Cron Jobs** → كل دقيقة:

```cron
* * * * * cd /home/USER/rpm && php artisan schedule:run >> /dev/null 2>&1
```

استبدل `USER` بمستخدم cPanel الحقيقي.

### ٥) حساب FTP (للـ CI)
**FTP Accounts** → أنشئ حساباً يصل لجذر المنزل (أو على الأقل `public_html` + `rpm`).  
سجّل: المضيف · المستخدم · كلمة المرور · المنفذ `21`.

### ٦) SSH (اختياري — لتشغيل migrate تلقائياً)
إن كان **SSH Access** مفعّلاً: نفس بيانات الدخول أو مفتاح.  
بدون SSH: بعد كل رفع نفّذ من Terminal يدوياً:

```bash
cd ~/rpm && bash cpanel-post-deploy.sh
```

---

## ب) أسرار GitHub (Settings → Secrets and variables → Actions)

### Secrets — إلزامي للنشر

| السر | مثال | ملاحظة |
|------|------|--------|
| `CPANEL_FTP_HOST` | `ftp.yourdomain.com` | من cPanel |
| `CPANEL_FTP_USER` | مستخدم FTP | |
| `CPANEL_FTP_PASSWORD` | ******** | |
| `CPANEL_FTP_REMOTE_DIR` | `/public_html/` | لازم ينتهي بـ `/` |
| `CPANEL_FTP_APP_DIR` | `/rpm/` | لازم ينتهي بـ `/` |
| `CPANEL_FTP_PORT` | `21` | اختياري |

### Secrets — اختياري لـ post-deploy عبر SSH

| السر | متى |
|------|-----|
| `CPANEL_SSH_HOST` | نفس الدومين أو IP |
| `CPANEL_SSH_USER` | مستخدم cPanel |
| `CPANEL_SSH_PASSWORD` | أو استخدم المفتاح بدلها |
| `CPANEL_SSH_KEY` | مفتاح خاص كامل (بديل الباسورد) |
| `CPANEL_SSH_PORT` | `22` غالباً |

### Variables — اختياري

| المتغير | القيمة | المعنى |
|---------|--------|--------|
| `CPANEL_SSH_POSTDEPLOY` | `true` | بعد FTP شغّل `cpanel-post-deploy.sh` عبر SSH |
| `CPANEL_APP_SSH_PATH` | `rpm` | مجلد التطبيق تحت الـ home |

### Environment
أنشئ Environment اسمه **`production`** (Settings → Environments) واربط حماية المراجعين إن رغبت.

---

## ج) أول رفع لايف (تسلسل آمن)

### الخطوة 1 — ابنِ محلياً أو من Actions وجرّب يدوياً مرة
```bash
bash scripts/build-production.sh
```
أو من GitHub: Actions → **CI** على `main` → حمّل Artifact `rpm-cpanel-bundle`.

### الخطوة 2 — ارفع أول مرة (يدوي أو Deploy)
- محتويات `cpanel-app/` → `/home/USER/rpm/`
- محتويات `cpanel/` → `/home/USER/public_html/`
- تأكد في `public_html/index.php`:
  ```php
  $laravelRoot = __DIR__.'/../rpm';
  ```

### الخطوة 3 — `.env` على السيرفر فقط (لا يُرفع من Git)
```bash
cd ~/rpm
cp .env.example .env
# عدّل: APP_URL · APP_DEBUG=false · DB_* · SESSION/QUEUE/CACHE=database
nano .env
```

قيم مقترحة للإنتاج:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
DB_CONNECTION=mysql
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
LOG_LEVEL=error
```

### الخطوة 4 — أوامر ما بعد الرفع
```bash
cd ~/rpm
RPM_RUN_SEED=yes bash cpanel-post-deploy.sh --first
```
- ينشئ `APP_KEY` · migrate · caches · seed اختياري.
- غيّر فوراً كلمة `admin@rpm.local`.

### الخطوة 5 — فعّل Workflow النشر من GitHub
1. تأكد الأسرار في القسم **ب**.
2. Actions → **Deploy · cPanel** → Run workflow.
3. في `confirm` اكتب بالضبط: `deploy`
4. انتظر نجاح: Build → FTP public → FTP app → (SSH إن مفعّل).

### الخطوة 6 — تحقق
| رابط | متوقع |
|------|--------|
| `https://your-domain.com/` | شاشة الدخول العربية |
| `https://your-domain.com/up` | صحة Laravel |
| `POST /api/login` | JSON توكن |

---

## د) التحديثات بعد كل دمج على `main`

**الوضع الحالي (موصى به أولاً):** تشغيل يدوي لـ **Deploy · cPanel** بعد مراجعة التغييرات.

**تفعيل النشر التلقائي:** في [`.github/workflows/deploy-cpanel.yml`](../.github/workflows/deploy-cpanel.yml) ألغِ تعليق:

```yaml
push:
  branches: [main]
```

ثم كل push على `main` يبني ويرفع. بدون SSH: بعد الرفع نفّذ من Terminal:

```bash
cd ~/rpm && bash cpanel-post-deploy.sh
```

> الـ FTP **لا يستبدل** `.env` ولا ملفات `storage/app/private` (مرفوعة المستخدمين).

---

## هـ) ماذا يفعل كل Workflow؟

| Workflow | الملف | متى | الناتج |
|----------|--------|-----|--------|
| **CI** | `.github/workflows/ci.yml` | PR و push لـ `main` | اختبارات + build · وعلى `main` Artifact كامل |
| **Deploy · cPanel** | `.github/workflows/deploy-cpanel.yml` | يدوي (`deploy`) أو push إن فُعّل | FTP لـ `public_html` + `~/rpm` ثم SSH اختياري |

مسار البناء (على GitHub فقط):
1. `npm ci` + `npm run build` في `ui-pwa`
2. `composer install --no-dev` داخل حزمة Laravel
3. دمج SPA في `dist/cpanel`
4. رفع الملفات الثابتة — **لا Node على cPanel**

---

## و) أعطال شائعة

| العرض | السبب المحتمل | الحل |
|--------|----------------|------|
| `تعذّر العثور على Laravel` | مسار `$laravelRoot` خطأ | عدّل `public_html/index.php` ليطابق `~/rpm` |
| 500 بعد الرفع | `.env` ناقص أو صلاحيات | `bash cpanel-post-deploy.sh` + `chmod` على `storage` |
| فشل FTP في Actions | سر خطأ أو مسار بلا `/` | راجع الأسرار؛ المسارات تنتهي بـ `/` |
| الواجهة تظهر والكود API لا | `.htaccess` لم يُرفع | تأكد وجود `scripts/cpanel.htaccess` في `public_html` |
| رفع صور يفشل | حدود PHP 2M | طبّق [`cpanel-php-ini.md`](cpanel-php-ini.md) |

---

## ز) قائمة تحقق سريعة قبل اعتبار لايف «مفعّل»

- [ ] MySQL جاهز و`.env` على `~/rpm` فقط
- [ ] PHP 8.2/8.3 + SSL + Cron
- [ ] أسرار FTP في GitHub (و Environment `production`)
- [ ] أول Run يدوي لـ **Deploy · cPanel** نجح
- [ ] `/` و `/up` و login يعملون
- [ ] (اختياري) `CPANEL_SSH_POSTDEPLOY=true` بعد اختبار SSH
- [ ] (اختياري) تفعيل `push:` على `main` للنشر التلقائي
