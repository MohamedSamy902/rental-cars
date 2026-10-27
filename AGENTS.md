# توجيه للوكيل — RPM

## ابدأ من هنا فقط
اقرأ أولاً وبالكامل:

**`docs/معايير-المشروع-الملزمة.md`** (الإصدار ٢٫٤+)

هذا هو المرجع الواحد: Clean/SOLID/DRY · React+TS SPA · **cPanel فقط** · **MySQL** · بلا رفريش · **حركة سموث + دليل لود** · **Responsive لكل الشاشات (فولد/ديسكتوب/…)** · كومبوننتس وحقول موحّدة · Laravel Service · أمن · أداء · اختبارات · لوحة المُدمج · البيزنس الملزم.

ثم إن احتجت جدول حقول شاشة: `docs/RSD.md`.  
مرجع بصري: `docs/mockups/` · سلوك حي: `ui-pwa/`.

## خطوط حمراء
- ممنوع رفريش صفحة في التنقّل أو CRUD  
- ممنوع شاشة معلّقة بلا دليل لود · الحركة لازم سموث
- ممنوع واجهة غير متجاوبة على أي مقاس (موبايل/فولد/تابلت/كمبيوتر)  
- ممنوع بيزنس خارج Service  
- ممنوع اعتماد إنتاج على VPS / Node دائم / Redis / WebSockets بدون بديل cPanel
- قاعدة البيانات **MySQL** فقط (InnoDB · utf8mb4)  
- ممنوع كسر التصميم/الحقول الموحّدة/الأمن/الأداء  
- ممنوع تعدد فروع أو فيتشر من شاشة العميل — لوحة المُدمج فقط  
- أي تغيير على المعايير أو البيزنس = تحديث الملف الملزم (+ RSD إن مسّ العميل) في نفس التغيير

## Cursor Cloud specific instructions

صورة الوكيل فيها PHP 8.3 وComposer وMariaDB. Node 22 موجود على PATH في login shell.

- قاعدة التطوير: MariaDB، قاعدة `rpm`، المستخدم `root` بلا كلمة مرور، مطابقة لـ `backend/.env.example` (`DB_HOST=127.0.0.1`). MariaDB لا تقلع وحدها مع الإقلاع؛ `sudo service mariadb start` يشغّلها. سكربت `start` في البيئة يفعل ذلك ثم `migrate` و`db:seed`.
- الواجهة: API على http://127.0.0.1:8000 (`php artisan serve`) وPWA على http://127.0.0.1:3847 (`ui-pwa`، `npm run dev`، يمرّر `/api` إلى 8000). الدخول التجريبي: `admin@rpm.local` / `password`.
- الاختبارات: `cd backend && php artisan test` (sqlite في الذاكرة عبر `phpunit.xml`). فحص الواجهة: `cd ui-pwa && npm run lint && npm run build`.
- فرع `main` فيه README فقط. إذا غاب `backend/vendor` بعد checkout، ثبّت مرة واحدة: `cd backend && composer install --no-interaction --prefer-dist` ثم `cd ui-pwa && npm ci`، وبعدها شغّل API وPWA كما فوق.
