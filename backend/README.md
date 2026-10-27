# RPM Backend (Laravel)

استضافة مستهدفة: **cPanel** · قاعدة بيانات: **MySQL (InnoDB · utf8mb4)** · البيزنس في **Services فقط**.

## تشغيل محلي

```bash
cp .env.example .env
php artisan key:generate
# اضبط DB_* على MySQL
php artisan migrate
php artisan test
php artisan serve
```

على cPanel: Cron يستدعي `php artisan schedule:run` بدل worker دائم. الطابور: `database` أو `queue:work --stop-when-empty` عبر Cron.

## هيكل Services

```
app/Services/
  Contracts/ContractService.php
  Contracts/ContractPricingService.php
  Fleet/VehicleAvailabilityService.php
  Inventory/FifoConsumptionService.php
```

مسار الطلب: `Route → Middleware → Controller → FormRequest → Service → JSON`.
