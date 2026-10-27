# آر بي إم — نظام إدارة تأجير السيارات

## ابدأ من هنا (أي شات / تنفيذ جديد)

1. **[`docs/معايير-المشروع-الملزمة.md`](docs/معايير-المشروع-الملزمة.md)** — المرجع الواحد (٢٫٤): Clean/SOLID/DRY · React SPA · **cPanel** · **MySQL** · بلا رفريش · حركة سموث + دليل لود · responsive لكل الشاشات · كومبوننتس · Laravel Service · أمن · أداء · اختبارات · لوحة المُدمج · البيزنس  
2. [`docs/RSD.md`](docs/RSD.md) — جداول الحقول ومواصفة الإرسال ٣٫٤ (بعد الملف الملزم)  
3. [`AGENTS.md`](AGENTS.md) · [`docs/mockups/`](docs/mockups/) · [`ui-pwa/`](ui-pwa/)

**لا يُكسر** شيء مقفول إلا بتعديل صريح على الملف الملزم (+ RSD إن مسّ البيزنس/الحقول).

## هيكل المستودع

| المسار | الدور |
|--------|--------|
| `ui-pwa/` | واجهة SPA/PWA عربية RTL (معاينة حية) |
| `backend/` | Laravel API · Services · Migrations (MySQL) |
| `docs/` | المعايير · RSD · موكبات · ملاحظات داخلية |

## معاينة واجهة PWA

```bash
cd ui-pwa && npm install && npm run dev
```

افتح http://127.0.0.1:3847

## باك اند

```bash
cd backend && cp .env.example .env && php artisan key:generate
# اضبط MySQL ثم:
php artisan migrate
php artisan test
```

### أبرز ما في المواصفة ٣٫٤
- تعدد الفروع **اختياري** من لوحة المُدمج؛ الافتراضي فرع واحد
- مخزن موحّد أو مخزن لكل فرع — عند تفعيل التعدد
- لوحة المُدمج: ألوان · لوجو · اسم المركز · فيتشر فلاج
- فورمات realtime بلا رفريش · Zero-detour · فاليديشن عربي
- Laravel **Service فقط** · اختبارات تتجاوز الهابي باث
