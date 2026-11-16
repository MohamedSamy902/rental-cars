<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'role_id', 'employee_id', 'is_active', 'session_minutes',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function hasPermission(string $key): bool
    {
        $this->loadMissing('role.permissions');
        if ($this->role?->name === 'super_admin') {
            return true;
        }

        return (bool) $this->role?->permissions?->contains('key', $key);
    }

    /**
     * Person-facing name for «من سجّل» / audit / attribution.
     * Never returns a bare role/permission title when a person identity exists.
     */
    public function displayName(): string
    {
        $this->loadMissing(['employee', 'role']);

        $employeeName = trim((string) ($this->employee?->name ?? ''));
        if ($employeeName !== '') {
            return $employeeName;
        }

        $stored = trim((string) $this->name);
        if ($stored !== '' && ! $this->nameLooksLikeRoleTitle($stored)) {
            return $stored;
        }

        $phone = trim((string) ($this->phone ?? ''));
        if ($phone !== '') {
            return $phone;
        }

        $email = trim((string) ($this->email ?? ''));
        if ($email !== '') {
            return $email;
        }

        return $stored !== '' ? $stored : 'مستخدم';
    }

    private function nameLooksLikeRoleTitle(string $name): bool
    {
        $norm = static function (string $s): string {
            $s = preg_replace('/\s+/u', '', $s) ?? $s;
            $s = preg_replace('/^ال/u', '', $s) ?? $s;

            return mb_strtolower($s);
        };

        $needle = $norm($name);
        if ($needle === '') {
            return false;
        }

        $candidates = array_filter([
            $this->role?->label,
            $this->role?->name,
            'مدير أعلى',
            'مدير النظام',
            'مدير تشغيل',
            'مدير التشغيل',
            'مبيعات',
            'مسؤول المبيعات',
            'أسطول',
            'مسؤول الأسطول',
            'صيانة',
            'مسؤول الصيانة',
            'حسابات',
            'المحاسب',
            'موارد بشرية',
            'مسؤول الموارد البشرية',
            'مشاهدة فقط',
            'المشاهد',
            'super_admin',
            'ops_manager',
            'sales',
            'fleet',
            'maintenance',
            'accounts',
            'hr',
            'viewer',
        ], static fn ($v) => is_string($v) && trim($v) !== '');

        foreach ($candidates as $candidate) {
            if ($norm((string) $candidate) === $needle) {
                return true;
            }
        }

        return false;
    }
}
