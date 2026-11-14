<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * يمسح كل مستخدمي النظام وينشئ مديرين أعلى برقم موبايل فقط، بلا ربط موظف.
 */
class ResetSuperAdminsCommand extends Command
{
    protected $signature = 'rpm:reset-super-admins
                            {--force : تنفيذ فعلي بدون تأكيد}
                            {--phone1=01150100104 : موبايل المدير الأول}
                            {--phone2=01125253888 : موبايل المدير الثاني}
                            {--password1= : كلمة مرور المدير الأول (توليد تلقائي إن غابت)}
                            {--password2= : كلمة مرور المدير الثاني (توليد تلقائي إن غابت)}
                            {--name1=مدير أعلى : اسم المدير الأول}
                            {--name2=مدير أعلى : اسم المدير الثاني}';

    protected $description = 'مسح كل المستخدمين وإنشاء مديرين أعلى غير ظاهرين في قائمة الموظفين';

    public function handle(): int
    {
        if (! $this->option('force')) {
            if (! $this->confirm('سيتم حذف كل المستخدمين وجلساتهم وإنشاء مديرين أعلى جديدين. متأكد؟', false)) {
                $this->warn('أُلغي.');

                return self::FAILURE;
            }
        }

        $phone1 = Phone::normalize((string) $this->option('phone1'));
        $phone2 = Phone::normalize((string) $this->option('phone2'));
        if ($phone1 === null || $phone2 === null || ! Phone::looksLikePhone($phone1) || ! Phone::looksLikePhone($phone2)) {
            $this->error('رقما الموبايل غير صالحين.');

            return self::FAILURE;
        }
        if ($phone1 === $phone2) {
            $this->error('رقما الموبايل يجب أن يختلفا.');

            return self::FAILURE;
        }

        $password1 = (string) $this->option('password1');
        $password2 = (string) $this->option('password2');
        if ($password1 === '') {
            $password1 = $this->generatePassword();
        }
        if ($password2 === '') {
            $password2 = $this->generatePassword();
        }
        if (strlen($password1) < 12 || strlen($password2) < 12) {
            $this->error('كلمة المرور ١٢ حرفاً على الأقل.');

            return self::FAILURE;
        }
        if (app()->environment('production') && ($password1 === 'password' || $password2 === 'password')) {
            $this->error('كلمة المرور التجريبية غير مسموحة في الإنتاج.');

            return self::FAILURE;
        }

        PermissionCatalog::ensureSeeded();
        $role = Role::query()->where('name', 'super_admin')->first();
        if (! $role) {
            $this->error('دور المدير الأعلى غير موجود.');

            return self::FAILURE;
        }

        $before = (int) User::query()->count();

        DB::transaction(function () use ($role, $phone1, $phone2, $password1, $password2) {
            $this->detachUserReferences();
            $this->purgeUserOwnedRows();
            User::query()->delete();

            User::query()->create([
                'name' => trim((string) $this->option('name1')) ?: 'مدير أعلى',
                'email' => null,
                'phone' => $phone1,
                'password' => $password1,
                'role_id' => $role->id,
                'employee_id' => null,
                'is_active' => true,
            ]);

            User::query()->create([
                'name' => trim((string) $this->option('name2')) ?: 'مدير أعلى',
                'email' => null,
                'phone' => $phone2,
                'password' => $password2,
                'role_id' => $role->id,
                'employee_id' => null,
                'is_active' => true,
            ]);
        });

        $after = (int) User::query()->count();
        $staffVisible = (int) User::query()
            ->whereDoesntHave('role', fn ($q) => $q->where('name', 'super_admin'))
            ->count();

        $this->newLine();
        $this->info("تم. مستخدمون: {$before} → {$after} · ظاهرون في الموظفين: {$staffVisible}");
        $this->table(
            ['الموبايل', 'كلمة المرور', 'employee_id'],
            [
                [$phone1, $password1, 'null'],
                [$phone2, $password2, 'null'],
            ],
        );
        $this->warn('احفظ كلمتي المرور الآن — لن تُعرض مرة أخرى.');

        return self::SUCCESS;
    }

    private function detachUserReferences(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql') {
            $this->nullifyKnownColumns();

            return;
        }

        $refs = DB::select(
            "SELECT TABLE_NAME, COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE REFERENCED_TABLE_NAME = 'users'
               AND REFERENCED_COLUMN_NAME = 'id'
               AND TABLE_SCHEMA = DATABASE()"
        );

        foreach ($refs as $ref) {
            $table = (string) $ref->TABLE_NAME;
            $column = (string) $ref->COLUMN_NAME;
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $meta = DB::selectOne(
                'SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );
            if (($meta->n ?? 'NO') === 'YES') {
                DB::table($table)->whereNotNull($column)->update([$column => null]);
            }
        }
    }

    private function nullifyKnownColumns(): void
    {
        $map = [
            'contracts' => ['sales_user_id'],
            'payments' => ['user_id'],
            'audit_logs' => ['user_id'],
            'expenses' => ['recorded_by'],
            'violations' => ['created_by', 'confirmed_by'],
            'petty_cash_movements' => ['performed_by'],
            'salary_advances' => ['created_by', 'recorded_by'],
            'employee_assignments' => ['assigned_by', 'acknowledged_by'],
            'payroll_runs' => ['created_by', 'approved_by'],
            'payroll_line_payouts' => ['created_by'],
            'payroll_payouts' => ['paid_by'],
            'inspections' => ['inspector_id'],
            'inventory_batches' => ['recorded_by'],
            'owner_month_dues' => ['created_by'],
            'owner_payouts' => ['recorded_by'],
            'media_files' => ['uploaded_by'],
            'journal_entries' => ['posted_by'],
            'print_snapshots' => ['printed_by'],
            'document_templates' => ['updated_by'],
            'month_locks' => ['locked_by'],
            'contract_vehicle_swaps' => ['performed_by'],
            'vehicle_document_histories' => ['archived_by'],
            'vehicle_violation_inquiries' => ['inquired_by'],
            'vehicle_custody_agreements' => ['issued_by'],
            'customer_credit_movements' => ['recorded_by'],
            'vehicle_tracker_sim_recharges' => ['created_by'],
        ];

        foreach ($map as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    DB::table($table)->whereNotNull($column)->update([$column => null]);
                }
            }
        }
    }

    private function purgeUserOwnedRows(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->delete();
        }
        if (Schema::hasTable('notifications')) {
            DB::table('notifications')->delete();
        }
        if (Schema::hasTable('push_subscriptions')) {
            DB::table('push_subscriptions')->delete();
        }
        if (Schema::hasTable('chat_messages')) {
            DB::table('chat_messages')->delete();
        }
        if (Schema::hasTable('deposit_deductions') && Schema::hasColumn('deposit_deductions', 'user_id')) {
            DB::table('deposit_deductions')->whereNotNull('user_id')->delete();
        }
    }

    private function generatePassword(): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $symbols = '!@#$%^&*';
        $all = $upper.$lower.$digits.$symbols;

        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];
        for ($i = 0; $i < 12; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($chars);

        return implode('', $chars);
    }
}
