<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        PermissionCatalog::ensureSeeded();

        $roles = Role::query()
            ->with(['permissions:id,key,label,module'])
            ->withCount('permissions')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'permissions_count' => $role->permissions_count,
                'permission_ids' => $role->permissions->pluck('id')->values(),
                'permission_keys' => $role->permissions->pluck('key')->values(),
            ]);

        $permissions = Permission::query()
            ->orderBy('module')
            ->orderBy('id')
            ->get()
            ->groupBy('module')
            ->map(fn ($rows, $module) => [
                'module' => $module,
                'label' => PermissionCatalog::moduleLabel((string) $module),
                'items' => $rows->map(fn (Permission $p) => [
                    'id' => $p->id,
                    'key' => $p->key,
                    'label' => $p->label,
                    'module' => $p->module,
                    'sensitive' => PermissionCatalog::isSensitive($p->key),
                ])->values(),
            ])
            ->values();

        return response()->json([
            'data' => [
                'roles' => $roles,
                'permissions' => $permissions,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'alpha_dash', 'unique:roles,name'],
            'label' => ['required', 'string', 'max:120'],
        ], [], [
            'name' => 'مفتاح الدور',
            'label' => 'اسم الدور',
        ]);

        $role = Role::query()->create($data);

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'permissions_count' => 0,
                'permission_ids' => [],
                'permission_keys' => [],
            ],
            'message' => 'تم إنشاء الدور.',
        ], 201);
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        PermissionCatalog::ensureSeeded();

        $data = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ], [], [
            'permission_ids' => 'الصلاحيات',
        ]);

        $role->permissions()->sync($data['permission_ids']);
        $role->load(['permissions:id,key,label,module']);

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'permissions_count' => $role->permissions->count(),
                'permission_ids' => $role->permissions->pluck('id')->values(),
                'permission_keys' => $role->permissions->pluck('key')->values(),
            ],
            'message' => 'تم حفظ صلاحيات الدور.',
        ]);
    }
}
