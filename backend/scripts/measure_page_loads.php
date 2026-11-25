<?php

/**
 * قياس حمولة الصفحة كما يراها العميل: مجموع طلبات الشاشة + /attention من الشلّ.
 * خرج JSON إلى stdout.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Support\PerfReadCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

$user = User::query()->where('email', 'admin@rpm.local')->firstOrFail();

$ids = [
    'vehicle' => (int) (\App\Models\Vehicle::query()->orderBy('id')->value('id') ?: 1),
    'contract' => (int) (\App\Models\Contract::query()->orderBy('id')->value('id') ?: 1),
    'customer' => (int) (\App\Models\Customer::query()->orderBy('id')->value('id') ?: 1),
    'owner' => (int) (\App\Models\VehicleOwner::query()->orderBy('id')->value('id') ?: 1),
    'employee' => (int) (\App\Models\Employee::query()->orderBy('id')->value('id') ?: 1),
    'petty' => (int) (\App\Models\PettyCashAccount::query()->orderBy('id')->value('id') ?: 1),
];

$month = now()->format('Y-m');

/** @var array<string, array{label:string, apis:list<string>}> */
$pages = [
    'dashboard' => [
        'label' => 'لوحة اليوم',
        'apis' => ['/api/dashboard'],
    ],
    'vehicles' => [
        'label' => 'قائمة العربيات',
        'apis' => ['/api/vehicles?per_page=50&page=1'],
    ],
    'vehicle_detail' => [
        'label' => 'تفاصيل عربية',
        'apis' => ["/api/vehicles/{$ids['vehicle']}/dossier"],
    ],
    'bookings' => [
        'label' => 'الحجوزات/العقود',
        'apis' => ['/api/contracts?per_page=50&page=1'],
    ],
    'contract_detail' => [
        'label' => 'تفاصيل عقد',
        'apis' => ["/api/contracts/{$ids['contract']}"],
    ],
    'calendar' => [
        'label' => 'التقويم',
        'apis' => [
            '/api/contracts?view=calendar&per_page=200',
            '/api/vehicles?per_page=1',
        ],
    ],
    'maintenance' => [
        'label' => 'الصيانة',
        'apis' => ['/api/work-orders'],
    ],
    'inventory' => [
        'label' => 'المخزون',
        'apis' => ['/api/inventory?page=1&per_page=50'],
    ],
    'customers' => [
        'label' => 'العملاء',
        'apis' => ['/api/customers?per_page=50&page=1'],
    ],
    'customer_detail' => [
        'label' => 'تفاصيل عميل',
        'apis' => ["/api/customers/{$ids['customer']}"],
    ],
    'violations' => [
        'label' => 'المخالفات',
        'apis' => ['/api/violations'],
    ],
    'violation_inquiries' => [
        'label' => 'استعلام مخالفات',
        'apis' => ['/api/violation-inquiries?per_page=50&page=1'],
    ],
    'notifications' => [
        'label' => 'الإشعارات',
        'apis' => ['/api/notifications', '/api/push/status'],
    ],
    'reports' => [
        'label' => 'التقارير (تبويب ملخص)',
        'apis' => ['/api/reports/summary'],
    ],
    'reports_income' => [
        'label' => 'التقارير · قائمة الدخل',
        'apis' => ['/api/reports/income-statement'],
    ],
    'reports_pay_methods' => [
        'label' => 'التقارير · طرق الدفع',
        'apis' => ['/api/reports/payment-methods'],
    ],
    'reports_profit' => [
        'label' => 'التقارير · ربحية',
        'apis' => ['/api/reports/profitability-compare'],
    ],
    'accounts' => [
        'label' => 'الحسابات',
        'apis' => [
            '/api/accounting/overview',
            '/api/accounting/daily-cash?date='.now()->toDateString(),
            '/api/accounting/payments?per_page=50&page=1',
        ],
    ],
    'month_ledger' => [
        'label' => 'دفتر الشهر',
        'apis' => ['/api/accounting/months/'.now()->format('Y').'/'.now()->format('n')],
    ],
    'expenses' => [
        'label' => 'المصروفات',
        'apis' => ['/api/expenses?per_page=50&page=1'],
    ],
    'owners' => [
        'label' => 'الملاك',
        'apis' => ['/api/owners?per_page=50&page=1'],
    ],
    'owner_detail' => [
        'label' => 'تفاصيل مالك',
        'apis' => ["/api/owners/{$ids['owner']}"],
    ],
    'petty_cash' => [
        'label' => 'العهدة',
        'apis' => ['/api/petty-cash?page=1&per_page=50'],
    ],
    'petty_cash_detail' => [
        'label' => 'تفاصيل عهدة',
        'apis' => ["/api/petty-cash/{$ids['petty']}"],
    ],
    'hr' => [
        'label' => 'الموارد البشرية',
        'apis' => [
            '/api/employees/lookup',
            '/api/payroll',
            '/api/salary-advances?year='.now()->year.'&month='.now()->month,
            '/api/employee-assignments',
            '/api/petty-cash?per_page=100',
        ],
    ],
    'staff' => [
        'label' => 'الموظفون/الصلاحيات',
        'apis' => [
            '/api/staff?per_page=50',
            '/api/roles',
            '/api/employees?per_page=100',
        ],
    ],
    'employee_detail' => [
        'label' => 'تفاصيل موظف',
        'apis' => ["/api/employees/{$ids['employee']}"],
    ],
    'chat' => [
        'label' => 'الشات الداخلي',
        'apis' => ['/api/chat'],
    ],
    'showroom' => [
        'label' => 'المعرض',
        'apis' => ['/api/showroom'],
    ],
    'settings' => [
        'label' => 'الإعدادات',
        'apis' => ['/api/settings/tenant'],
    ],
    'integrator' => [
        'label' => 'لوحة المُدمج',
        'apis' => ['/api/settings/tenant'],
    ],
    'audit_logs' => [
        'label' => 'سجل التدقيق',
        'apis' => ['/api/audit-logs?per_page=50&page=1'],
    ],
    'department_notice' => [
        'label' => 'إخطار القسم',
        'apis' => ['/api/customers?per_page=100'],
    ],
];

function hit(Illuminate\Contracts\Http\Kernel $kernel, User $user, string $path): array
{
    Sanctum::actingAs($user);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $t0 = microtime(true);
    $req = Request::create($path, 'GET');
    $req->headers->set('Accept', 'application/json');
    $res = $kernel->handle($req);
    $ms = (microtime(true) - $t0) * 1000;
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $kernel->terminate($req, $res);

    return [
        'path' => $path,
        'status' => $res->getStatusCode(),
        'queries' => $queries,
        'ms' => round($ms, 1),
        'bytes' => strlen($res->getContent()),
    ];
}

function measurePage(Illuminate\Contracts\Http\Kernel $kernel, User $user, array $def, bool $includeShell, bool $cold): array
{
    if ($cold) {
        Cache::flush();
        PerfReadCache::bump();
    }

    $apis = $def['apis'];
    if ($includeShell) {
        array_unshift($apis, '/api/attention');
    }

    $calls = [];
    $q = 0;
    $ms = 0.0;
    $bytes = 0;
    $ok = true;
    foreach ($apis as $path) {
        $row = hit($kernel, $user, $path);
        $calls[] = $row;
        $q += $row['queries'];
        $ms += $row['ms'];
        $bytes += $row['bytes'];
        if ($row['status'] >= 400) {
            $ok = false;
        }
    }

    return [
        'label' => $def['label'],
        'ok' => $ok,
        'api_count' => count($apis),
        'queries' => $q,
        'ms' => round($ms, 1),
        'bytes' => $bytes,
        'calls' => $calls,
    ];
}

$journalBefore = (int) \App\Models\JournalEntry::query()->count();
$paymentBefore = (int) \App\Models\Payment::query()->count();
$contractBefore = (int) \App\Models\Contract::query()->count();
$recogBefore = (int) \App\Models\JournalEntry::query()->where('source', 'like', 'rent_recog%')->count();

$results = [];
foreach ($pages as $key => $def) {
    $cold = measurePage($kernel, $user, $def, true, true);
    $warm = measurePage($kernel, $user, $def, true, false);
    $results[$key] = [
        'label' => $def['label'],
        'cold' => $cold,
        'warm' => $warm,
    ];
}

$journalAfter = (int) \App\Models\JournalEntry::query()->count();
$paymentAfter = (int) \App\Models\Payment::query()->count();
$contractAfter = (int) \App\Models\Contract::query()->count();
$recogAfter = (int) \App\Models\JournalEntry::query()->where('source', 'like', 'rent_recog%')->count();

$payload = [
    'measured_at' => now()->timezone('Africa/Cairo')->toIso8601String(),
    'zone' => 'Africa/Cairo',
    'user' => $user->email,
    'ids' => $ids,
    'shell' => 'كل صفحة تتضمن GET /attention من AppShell (مرة لكل جلسة قطبية فعلياً؛ هنا تُحسب مع كل صفحة للأسوأ)',
    'budgets' => [
        // APIs الخاصة بالصفحة فقط (بدون /attention المشترك من الشلّ)
        'page_only_queries_warn' => 40,
        'page_queries_critical' => 80,
        'page_bytes_warn' => 250_000,
        'page_ms_warn' => 500,
    ],
    'data_safety' => [
        'journals_before' => $journalBefore,
        'journals_after' => $journalAfter,
        'journals_delta' => $journalAfter - $journalBefore,
        'rent_recog_before' => $recogBefore,
        'rent_recog_after' => $recogAfter,
        'rent_recog_delta' => $recogAfter - $recogBefore,
        'payments_delta' => $paymentAfter - $paymentBefore,
        'contracts_delta' => $contractAfter - $contractBefore,
        'ok' => ($journalAfter === $journalBefore)
            && ($recogAfter === $recogBefore)
            && ($paymentAfter === $paymentBefore)
            && ($contractAfter === $contractBefore),
    ],
    'pages' => $results,
];

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
