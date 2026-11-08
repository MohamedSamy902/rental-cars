<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Console\Command;

class CreateAdminCommand extends Command
{
    protected $signature = 'rpm:create-admin
                            {--name= : الاسم}
                            {--email= : البريد}
                            {--phone= : الموبايل}
                            {--password= : كلمة المرور}';

    protected $description = 'إنشاء مدير أعلى بدون بيانات تجريبية';

    public function handle(): int
    {
        $name = trim((string) $this->option('name'));
        $email = trim((string) $this->option('email'));
        $phone = trim((string) $this->option('phone'));
        $password = (string) $this->option('password');

        if ($name === '' || $email === '' || $phone === '' || $password === '') {
            $this->error('الاسم والبريد والموبايل وكلمة المرور مطلوبة.');

            return self::FAILURE;
        }
        if (app()->environment('production') && $password === 'password') {
            $this->error('كلمة المرور التجريبية غير مسموحة في الإنتاج.');

            return self::FAILURE;
        }
        if (strlen($password) < 8) {
            $this->error('كلمة المرور ٨ أحرف على الأقل.');

            return self::FAILURE;
        }

        PermissionCatalog::ensureSeeded();
        $role = Role::query()->where('name', 'super_admin')->first();
        if (! $role) {
            $this->error('دور المدير الأعلى غير موجود.');

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $password,
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->info('تم إنشاء المدير الأعلى.');

        return self::SUCCESS;
    }
}
