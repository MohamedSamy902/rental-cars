<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;

class PermissionCatalog
{
    /** @return list<array{key:string,label:string,module:string}> */
    public static function definitions(): array
    {
        $defs = [];

        $modules = [
            'vehicles' => ['السيارات', ['view' => 'عرض', 'create' => 'إضافة', 'update' => 'تعديل', 'archive' => 'أرشفة', 'print' => 'طباعة', 'export' => 'تصدير']],
            'contracts' => ['العقود', ['view' => 'عرض', 'create' => 'إضافة', 'update' => 'تعديل', 'approve' => 'اعتماد', 'print' => 'طباعة', 'export' => 'تصدير']],
            'contracts_extend' => ['تمديد بعقد جديد', ['execute' => 'تنفيذ']],
            'contracts_swap' => ['تبديل بسبب عطل', ['execute' => 'تنفيذ']],
            'contracts_cancel' => ['إلغاء عقد', ['approve' => 'اعتماد إلغاء']],
            'waitlist' => ['قائمة الانتظار', ['view' => 'عرض', 'create' => 'إضافة', 'update' => 'تعديل']],
            'customers' => ['العملاء', ['view' => 'عرض', 'create' => 'إضافة', 'update' => 'تعديل', 'export' => 'تصدير']],
            'blacklist' => ['القائمة السوداء', ['manage' => 'إدارة']],
            'maintenance' => ['الصيانة', ['view' => 'عرض', 'create' => 'إضافة', 'update' => 'تعديل', 'close' => 'إقفال أمر شغل']],
            'inventory' => ['المخزون', ['view' => 'عرض', 'create' => 'إضافة', 'update' => 'تعديل', 'adjust' => 'تسوية كمية']],
            'violations' => ['المخالفات', ['view' => 'عرض', 'create' => 'إضافة', 'update' => 'تعديل']],
            'media' => ['الملفات والمرفقات', ['view' => 'عرض', 'upload' => 'رفع', 'delete' => 'حذف']],
            'chat' => ['المحادثة', ['view' => 'عرض', 'create' => 'إرسال']],
            'contracts_price' => ['تعديل أسعار عقد معتمد', ['override' => 'تعديل بعد الاعتماد']],
            'deposits' => ['التأمين النقدي', ['view' => 'عرض', 'collect' => 'قبض', 'settle' => 'تسوية']],
            'expenses' => ['المصروفات', ['view' => 'عرض', 'create' => 'إضافة', 'update' => 'تعديل']],
            'owners' => ['ملاك العربيات', ['view' => 'عرض', 'create' => 'إضافة', 'update' => 'تعديل', 'payout' => 'صرف للمالك']],
            'petty_cash' => ['العهدة', ['view' => 'عرض', 'manage' => 'إدارة']],
            'accounting' => ['الحسابات', ['view' => 'عرض', 'lock_month' => 'إقفال شهر', 'reopen_month' => 'إعادة فتح شهر']],
            'payments' => ['الدفعات', ['view' => 'عرض', 'create' => 'إضافة']],
            'reports' => ['التقارير', ['view' => 'عرض', 'export' => 'تصدير']],
            'hr' => ['الموارد البشرية', [
                'view' => 'عرض',
                'create' => 'إضافة',
                'update' => 'تعديل',
                'payroll_approve' => 'اعتماد مسير رواتب',
                'salaries' => 'المرتبات والمسير',
                'custody' => 'إسناد العهدة والأصول',
                'advances' => 'السلف',
                'expenses' => 'مصروفات الموارد البشرية',
            ]],
            'staff' => ['المستخدمون والصلاحيات', ['view' => 'عرض', 'manage' => 'إدارة']],
            'notifications' => ['الإشعارات', ['view' => 'عرض']],
            'settings' => ['الإعدادات', ['view' => 'عرض', 'update' => 'تعديل']],
            'showroom' => ['وضع عرض المعرض', ['run' => 'تشغيل']],
            'department_notice' => ['إخطار القسم', ['print' => 'طباعة']],
            'audit' => ['سجل التعديلات', ['view' => 'عرض']],
            'sensitive' => ['صلاحيات حسّاسة', [
                'view_national_id' => 'عرض الرقم القومي كاملاً',
                'view_id_images' => 'عرض صور الهوية والرخص',
                'view_salaries' => 'عرض المرتبات',
                'edit_odometer_closed' => 'تعديل العداد بعد إغلاق العقد',
            ]],
        ];

        foreach ($modules as $module => [$moduleLabel, $actions]) {
            foreach ($actions as $action => $actionLabel) {
                $defs[] = [
                    'key' => "{$module}.{$action}",
                    'label' => "{$moduleLabel} · {$actionLabel}",
                    'module' => $module,
                ];
            }
        }

        return $defs;
    }

    public static function moduleLabel(string $module): string
    {
        return match ($module) {
            'vehicles' => 'السيارات',
            'contracts' => 'العقود',
            'contracts_extend' => 'تمديد بعقد جديد',
            'contracts_swap' => 'تبديل بسبب عطل',
            'contracts_cancel' => 'إلغاء عقد',
            'waitlist' => 'قائمة الانتظار',
            'customers' => 'العملاء',
            'blacklist' => 'القائمة السوداء',
            'maintenance' => 'الصيانة',
            'inventory' => 'المخزون',
            'violations' => 'المخالفات',
            'media' => 'الملفات والمرفقات',
            'chat' => 'المحادثة',
            'contracts_price' => 'تعديل أسعار عقد معتمد',
            'deposits' => 'التأمين النقدي',
            'expenses' => 'المصروفات',
            'owners' => 'ملاك العربيات',
            'petty_cash' => 'العهدة',
            'accounting' => 'الحسابات',
            'payments' => 'الدفعات',
            'reports' => 'التقارير',
            'hr' => 'الموارد البشرية',
            'staff' => 'المستخدمون والصلاحيات',
            'notifications' => 'الإشعارات',
            'settings' => 'الإعدادات',
            'showroom' => 'وضع عرض المعرض',
            'department_notice' => 'إخطار القسم',
            'audit' => 'سجل التعديلات',
            'sensitive' => 'صلاحيات حسّاسة',
            default => $module,
        };
    }

    public static function isSensitive(string $key): bool
    {
        return str_starts_with($key, 'sensitive.')
            || in_array($key, [
                'blacklist.manage',
                'deposits.settle',
                'inventory.adjust',
                'accounting.lock_month',
                'accounting.reopen_month',
                'hr.payroll_approve',
                'hr.salaries',
                'hr.custody',
                'hr.advances',
                'petty_cash.manage',
                'owners.payout',
                'contracts_swap.execute',
                'contracts_cancel.approve',
                'staff.manage',
            ], true);
    }

    public static function ensureSeeded(): void
    {
        foreach (self::definitions() as $def) {
            Permission::query()->firstOrCreate(
                ['key' => $def['key']],
                ['label' => $def['label'], 'module' => $def['module']],
            );
        }

        $roles = [
            ['name' => 'super_admin', 'label' => 'مدير أعلى'],
            ['name' => 'ops_manager', 'label' => 'مدير تشغيل'],
            ['name' => 'sales', 'label' => 'مبيعات'],
            ['name' => 'fleet', 'label' => 'أسطول'],
            ['name' => 'maintenance', 'label' => 'صيانة'],
            ['name' => 'accounts', 'label' => 'حسابات'],
            ['name' => 'hr', 'label' => 'موارد بشرية'],
            ['name' => 'viewer', 'label' => 'مشاهدة فقط'],
        ];

        foreach ($roles as $role) {
            Role::query()->firstOrCreate(['name' => $role['name']], ['label' => $role['label']]);
        }

        $super = Role::query()->where('name', 'super_admin')->first();
        if ($super) {
            $super->permissions()->syncWithoutDetaching(Permission::query()->pluck('id'));
        }

        $viewer = Role::query()->where('name', 'viewer')->first();
        if ($viewer) {
            $viewer->permissions()->syncWithoutDetaching(
                Permission::query()->where('key', 'like', '%.view')->pluck('id')
            );
        }
    }
}
