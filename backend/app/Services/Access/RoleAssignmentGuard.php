<?php

namespace App\Services\Access;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use DomainException;

/**
 * A staff manager may only grant a role, or a permission set, that is a subset of their own keys.
 * The super_admin role itself can only be assigned or edited by another super_admin.
 */
final class RoleAssignmentGuard
{
    public function assertCanAssignRole(User $actor, int $roleId): void
    {
        $role = Role::query()->with('permissions:id,key')->find($roleId);
        if (! $role) {
            throw new DomainException('الدور غير موجود.');
        }

        $this->assertNotEscalatingToSuperAdmin($actor, $role);
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        $this->assertKeysSubset($actor, $role->permissions->pluck('key')->all());
    }

    public function assertCanEditUser(User $actor, User $target): void
    {
        $target->loadMissing('role');
        if ($target->role?->name === 'super_admin' && ! $this->isSuperAdmin($actor)) {
            throw new DomainException('لا يمكن تعديل حساب المدير الأعلى.');
        }
    }

    /**
     * @param  list<int>  $permissionIds
     */
    public function assertCanSyncPermissions(User $actor, Role $role, array $permissionIds): void
    {
        if ($role->name === 'super_admin' && ! $this->isSuperAdmin($actor)) {
            throw new DomainException('لا يمكن تعديل صلاحيات المدير الأعلى.');
        }
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        $keys = Permission::query()->whereIn('id', $permissionIds)->pluck('key')->all();
        $this->assertKeysSubset($actor, $keys);
    }

    private function assertNotEscalatingToSuperAdmin(User $actor, Role $role): void
    {
        if ($role->name === 'super_admin' && ! $this->isSuperAdmin($actor)) {
            throw new DomainException('لا يمكن منح دور المدير الأعلى إلا من مدير أعلى.');
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertKeysSubset(User $actor, array $keys): void
    {
        $actor->loadMissing('role.permissions');
        $owned = $actor->role?->permissions?->pluck('key')->all() ?? [];
        $extra = array_values(array_diff($keys, $owned));
        if ($extra !== []) {
            throw new DomainException('لا يمكن منح صلاحية لا تملكها.');
        }
    }

    private function isSuperAdmin(User $actor): bool
    {
        $actor->loadMissing('role');

        return $actor->role?->name === 'super_admin';
    }
}
