<?php

namespace App\Console\Commands;

use App\Services\Accounting\AccountingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * يمسح كل البيانات التشغيلية ويصفّر القيود، مع الإبقاء على مستخدمي النظام والصلاحيات وإعدادات المركز ودليل الحسابات.
 */
class PurgeOperationalDataCommand extends Command
{
    protected $signature = 'rpm:purge-operational-data
                            {--force : تنفيذ فعلي بدون طلب تأكيد تفاعلي}
                            {--keep-catalogs : الإبقاء على كتالوج الماركات والإضافات}';

    protected $description = 'مسح البيانات التشغيلية وتصفير الحسابات مع الإبقاء على مستخدمي النظام';

    /** جداول تبقى كما هي (هوية النظام والهيكل). */
    private const PRESERVE = [
        'migrations',
        'users',
        'roles',
        'permissions',
        'permission_role',
        'tenant_settings',
        'accounts',
    ];

    /** كتالوجات اختيارية (ماركات/إضافات). */
    private const CATALOGS = [
        'vehicle_brands',
        'vehicle_addon_catalog',
        'consumable_part_templates',
        'document_templates',
    ];

    public function handle(AccountingService $accounting): int
    {
        if (! $this->option('force')) {
            if (! $this->confirm('سيتم مسح كل البيانات التشغيلية وتصفير القيود. المتبقي: المستخدمون والصلاحيات وإعدادات المركز ودليل الحسابات. متأكد؟', false)) {
                $this->warn('أُلغي المسح.');

                return self::FAILURE;
            }
        }

        $keepCatalogs = (bool) $this->option('keep-catalogs');
        $preserve = self::PRESERVE;
        if ($keepCatalogs) {
            $preserve = array_merge($preserve, self::CATALOGS);
        }

        $all = $this->listTables();
        $targets = [];
        foreach ($all as $raw) {
            $table = $this->bareTableName($raw);
            if (in_array($table, $preserve, true) || $table === 'sqlite_sequence') {
                continue;
            }
            $targets[] = $table;
        }
        $targets = array_values(array_unique($targets));
        sort($targets);

        $this->info('جداول ستُفرَّغ: '.count($targets));
        $this->line(implode(', ', $targets));

        $beforeUsers = (int) DB::table('users')->count();
        $beforeAccounts = (int) DB::table('accounts')->count();

        // MySQL TRUNCATE يعمل COMMIT ضمني — لا تُغلَّف في DB::transaction.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        try {
            foreach ($targets as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                if ($driver === 'sqlite') {
                    DB::table($table)->delete();
                } else {
                    DB::table($table)->truncate();
                }
            }
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        }

        // دليل الحسابات يبقى؛ إن نقص يُعاد بناؤه فارغاً بلا أرصدة.
        $accounting->ensureChartOfAccounts();

        $afterUsers = (int) DB::table('users')->count();
        $journals = (int) DB::table('journal_entries')->count();
        $lines = (int) DB::table('journal_lines')->count();
        $contracts = (int) DB::table('contracts')->count();
        $payments = (int) DB::table('payments')->count();
        $vehicles = (int) DB::table('vehicles')->count();
        $customers = (int) DB::table('customers')->count();

        $this->newLine();
        $this->info('تم المسح.');
        $this->table(
            ['البند', 'القيمة'],
            [
                ['مستخدمون (قبل → بعد)', $beforeUsers.' → '.$afterUsers],
                ['دليل حسابات', (string) max($beforeAccounts, (int) DB::table('accounts')->count())],
                ['عقود', (string) $contracts],
                ['عملاء', (string) $customers],
                ['عربيات', (string) $vehicles],
                ['دفعات', (string) $payments],
                ['قيود يومية', (string) $journals],
                ['بنود قيود', (string) $lines],
            ],
        );

        if ($afterUsers < 1) {
            $this->error('تحذير: لا يوجد مستخدمون بعد المسح.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function listTables(): array
    {
        /** @var list<string> $tables */
        $tables = Schema::getTableListing();

        return array_values($tables);
    }

    private function bareTableName(string $qualified): string
    {
        $parts = explode('.', $qualified);

        return (string) end($parts);
    }
}
