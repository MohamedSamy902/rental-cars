# آر بي إم — نظام إدارة تأجير السيارات

## ابدأ من هنا

1. **[`docs/معايير-المشروع-الملزمة.md`](docs/معايير-المشروع-الملزمة.md)** — المرجع الواحد (٢٫٤)  
2. [`docs/RSD.md`](docs/RSD.md) — جداول الحقول ٣٫٤  
3. [`AGENTS.md`](AGENTS.md) · [`docs/mockups/نظام-التصميم.md`](docs/mockups/نظام-التصميم.md)

## تشغيل المشروع (شغال محلياً)

```bash
# مرة واحدة
cd backend && composer install && cp .env.example .env && php artisan key:generate
# للتطوير المحلي السريع يُستخدم sqlite (الإنتاج = MySQL على cPanel)
# راجع backend/.env

./scripts/dev.sh
```

أو يدوياً:

```bash
# API
cd backend && php artisan migrate --seed && php artisan serve --host=127.0.0.1 --port=8000

# PWA
cd ui-pwa && npm install && npm run dev
# http://127.0.0.1:3847
```

**دخول تجريبي:** `admin@rpm.local` / `password`

## الهيكل

| المسار | الدور |
|--------|--------|
| `ui-pwa/` | SPA/PWA عربية RTL — Ostolak-style كثيف + Keynetic timeline |
| `backend/` | Laravel API · Services · Policies/Sanctum · MySQL (إنتاج) |
| `docs/` | المعايير · RSD · نظام التصميم |

## ما يعمل الآن

- تسجيل دخول Sanctum
- لوحة اليوم من API (إشغال · KPI · مهام · عقود)
- مركبات / عملاء / عقود بلا رفريش
- دورة العقد: اعتماد · قبض تأمين · تسليم · استلام · إلغاء قبل التسليم · تمديد بعقد جديد · تبديل بسبب عطل
- Zero-detour: إضافة عميل داخل شيت الحجز
- أوامر شغل + مخزون FIFO + رفض صيانة مع تعارض حجوزات
- مخالفات للمتابعة بلا ترحيل محاسبي
- حسابات: دليل حسابات · توزيع إيجار على الشهور · إقفال/إعادة فتح شهر · حركات قبض/رد
- تقارير: ذمم · إشغال · إيراد شهري (المخالفات خارج المحاسبة)
- قائمة انتظار · إشعارات · إعدادات المستأجر / فيتشر فلاج
- تقويم زمني

الإنتاج المستهدف: **cPanel + MySQL InnoDB utf8mb4** · Cron بدل worker دائم.
