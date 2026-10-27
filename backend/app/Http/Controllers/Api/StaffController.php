<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = User::query()->with(['role:id,name,label'])->latest('id');

        if ($search = $request->string('search')->toString()) {
            $q->where(function ($qq) use ($search) {
                $qq->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('active')) {
            $q->where('is_active', $request->boolean('active'));
        }

        if ($roleId = $request->integer('role_id')) {
            $q->where('role_id', $roleId);
        }

        $rows = $q->paginate((int) $request->integer('per_page', 50));

        return response()->json([
            'data' => collect($rows->items())->map(fn (User $u) => $this->present($u))->values(),
            'summary' => [
                'total' => User::query()->count(),
                'active' => User::query()->where('is_active', true)->count(),
                'inactive' => User::query()->where('is_active', false)->count(),
                'roles' => DB::table('roles')->count(),
            ],
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role_id' => ['required', 'exists:roles,id'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'is_active' => ['sometimes', 'boolean'],
            'session_minutes' => ['nullable', 'integer', 'min:15', 'max:1440'],
            'create_employee' => ['sometimes', 'boolean'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'hired_on' => ['nullable', 'date'],
            'base_salary' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'phone' => 'الهاتف',
            'password' => 'كلمة المرور',
            'role_id' => 'الدور',
            'employee_id' => 'الموظف',
            'session_minutes' => 'مدة الجلسة',
            'job_title' => 'المسمى الوظيفي',
            'hired_on' => 'تاريخ التعيين',
            'base_salary' => 'الراتب الأساسي',
        ]);

        $user = DB::transaction(function () use ($data) {
            $employeeId = $data['employee_id'] ?? null;

            if (! empty($data['create_employee']) && ! $employeeId) {
                $employee = Employee::query()->create([
                    'name' => $data['name'],
                    'job_title' => $data['job_title'] ?? null,
                    'hired_on' => $data['hired_on'] ?? now()->toDateString(),
                    'base_salary' => $data['base_salary'] ?? 0,
                    'status' => 'active',
                ]);
                $employeeId = $employee->id;
            }

            return User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'role_id' => $data['role_id'],
                'employee_id' => $employeeId,
                'is_active' => $data['is_active'] ?? true,
                'session_minutes' => $data['session_minutes'] ?? null,
            ])->load('role:id,name,label');
        });

        return response()->json([
            'data' => $this->present($user),
            'message' => 'تم إضافة الموظف.',
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'role_id' => ['sometimes', 'exists:roles,id'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'is_active' => ['sometimes', 'boolean'],
            'session_minutes' => ['nullable', 'integer', 'min:15', 'max:1440'],
        ], [], [
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'phone' => 'الهاتف',
            'password' => 'كلمة المرور',
            'role_id' => 'الدور',
            'employee_id' => 'الموظف',
            'session_minutes' => 'مدة الجلسة',
        ]);

        if (array_key_exists('password', $data) && ($data['password'] === null || $data['password'] === '')) {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json([
            'data' => $this->present($user->fresh()->load('role:id,name,label')),
            'message' => 'تم تحديث الموظف.',
        ]);
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role_id' => $user->role_id,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'label' => $user->role->label,
            ] : null,
            'employee_id' => $user->employee_id,
            'is_active' => (bool) $user->is_active,
            'session_minutes' => $user->session_minutes,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
