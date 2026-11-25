<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\Access\RoleAssignmentGuard;
use App\Support\AuthSession;
use App\Support\PaginationInput;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // المديرون الأعلى لا يظهرون أبداً في قائمة الموظفين (حسابات نظام بلا employee_id).
        $staffScope = fn ($q) => $q->whereDoesntHave('role', fn ($r) => $r->where('name', 'super_admin'));

        $q = User::query()->with(['role:id,name,label'])->tap($staffScope)->latest('id');

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

        $perPage = PaginationInput::perPage($request, 50, PaginationInput::MAX_PER_PAGE);
        $page = PaginationInput::page($request);
        $rows = $q->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($rows->items())->map(fn (User $u) => $this->present($u))->values(),
            'summary' => [
                'total' => User::query()->tap($staffScope)->count(),
                'active' => User::query()->tap($staffScope)->where('is_active', true)->count(),
                'inactive' => User::query()->tap($staffScope)->where('is_active', false)->count(),
                'roles' => DB::table('roles')->where('name', '!=', 'super_admin')->count(),
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
            'email' => ['nullable', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role_id' => ['required', 'exists:roles,id'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'is_active' => ['sometimes', 'boolean'],
            'session_minutes' => ['nullable', 'integer', 'min:'.AuthSession::MIN_MINUTES, 'max:'.AuthSession::MAX_MINUTES],
            'create_employee' => ['sometimes', 'boolean'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'hired_on' => ['nullable', 'date'],
            'base_salary' => ['nullable', 'integer', 'min:0'],
        ], [
            'phone.required' => 'رقم الموبايل مطلوب.',
            'phone.unique' => 'رقم الموبايل مستخدم مسبقاً.',
            'email.email' => 'البريد الإلكتروني غير صالح.',
            'email.unique' => 'البريد الإلكتروني مستخدم مسبقاً.',
        ], [
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'phone' => 'رقم الموبايل',
            'password' => 'كلمة المرور',
            'role_id' => 'الدور',
            'employee_id' => 'الموظف',
            'session_minutes' => 'مدة الجلسة',
            'job_title' => 'المسمى الوظيفي',
            'hired_on' => 'تاريخ التعيين',
            'base_salary' => 'الراتب الأساسي',
        ]);

        try {
            app(RoleAssignmentGuard::class)->assertCanAssignRole($request->user(), (int) $data['role_id']);
            $this->assertNotSuperAdminRoleId((int) $data['role_id']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

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

            $email = isset($data['email']) && is_string($data['email']) && trim($data['email']) !== ''
                ? trim($data['email'])
                : null;

            return User::query()->create([
                'name' => $data['name'],
                'email' => $email,
                'phone' => trim((string) $data['phone']),
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
        $user->loadMissing('role');
        $actor = $request->user();
        $actor->loadMissing('role');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'role_id' => ['sometimes', 'exists:roles,id'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'is_active' => ['sometimes', 'boolean'],
            'session_minutes' => ['nullable', 'integer', 'min:'.AuthSession::MIN_MINUTES, 'max:'.AuthSession::MAX_MINUTES],
        ], [
            'phone.required' => 'رقم الموبايل مطلوب.',
            'phone.unique' => 'رقم الموبايل مستخدم مسبقاً.',
            'email.email' => 'البريد الإلكتروني غير صالح.',
            'email.unique' => 'البريد الإلكتروني مستخدم مسبقاً.',
        ], [
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'phone' => 'رقم الموبايل',
            'password' => 'كلمة المرور',
            'role_id' => 'الدور',
            'employee_id' => 'الموظف',
            'session_minutes' => 'مدة الجلسة',
        ]);

        if (array_key_exists('password', $data) && ($data['password'] === null || $data['password'] === '')) {
            unset($data['password']);
        }

        if (array_key_exists('email', $data)) {
            $email = is_string($data['email']) ? trim($data['email']) : '';
            $data['email'] = $email !== '' ? $email : null;
        }

        if (array_key_exists('phone', $data)) {
            $data['phone'] = trim((string) $data['phone']);
        }

        try {
            $this->assertCanMutateStaffUser($actor, $user, $data);
            $guard = app(RoleAssignmentGuard::class);
            $guard->assertCanEditUser($actor, $user);
            if (isset($data['role_id'])) {
                $guard->assertCanAssignRole($actor, (int) $data['role_id']);
                $this->assertNotSuperAdminRoleId((int) $data['role_id']);
            }
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $wasActive = (bool) $user->is_active;
        $user->update($data);
        $user->refresh();
        if ($wasActive && ! $user->is_active) {
            $user->tokens()->delete();
        }

        return response()->json([
            'data' => $this->present($user->fresh()->load('role:id,name,label')),
            'message' => array_key_exists('password', $data) && count($data) === 1
                ? 'تم تحديث كلمة المرور.'
                : 'تم تحديث الموظف.',
        ]);
    }

    /**
     * تعيين/تغيير كلمة مرور موظف من ملفه — مسار واضح بلا لبس «غير موجود».
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $user->loadMissing('role');
        $actor = $request->user();
        $actor->loadMissing('role');

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
        ], [
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min' => 'كلمة المرور ٨ أحرف على الأقل.',
            'password.confirmed' => 'تأكيد كلمة المرور غير متطابق.',
        ], [
            'password' => 'كلمة المرور',
        ]);

        try {
            $this->assertCanMutateStaffUser($actor, $user, ['password' => $data['password']]);
            app(RoleAssignmentGuard::class)->assertCanEditUser($actor, $user);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $user->update(['password' => $data['password']]);

        return response()->json([
            'data' => $this->present($user->fresh()->load('role:id,name,label')),
            'message' => 'تم تحديث كلمة المرور.',
        ]);
    }

    /**
     * حسابات المدير الأعلى النظامية (بلا موظف HR) تبقى مخفية.
     * إن وُجد حساب مربوط بموظف ومُعيَّن خطأً كمدير أعلى، يسمح المدير الأعلى بإصلاحه (كلمة مرور / تخفيض الدور).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertCanMutateStaffUser(User $actor, User $target, array $data): void
    {
        if ($target->role?->name !== 'super_admin') {
            return;
        }

        if ($actor->role?->name !== 'super_admin') {
            throw new DomainException('لا يمكن تعديل حساب المدير الأعلى.');
        }

        if (! $target->employee_id) {
            throw new DomainException('حسابات النظام العليا تُدار من أمر إعادة التعيين فقط، وليس من شاشة الموظفين.');
        }

        // حساب موظف مُنح دور المدير الأعلى بالخطأ — يُسمح بإصلاحه كاملاً (بما فيها تخفيض الدور).
        if (isset($data['role_id'])) {
            $this->assertNotSuperAdminRoleId((int) $data['role_id']);
        }
    }

    private function assertNotSuperAdminRoleId(int $roleId): void
    {
        $role = \App\Models\Role::query()->find($roleId);
        if ($role?->name === 'super_admin') {
            throw new DomainException('لا يُنشأ ولا يُمنح دور المدير الأعلى من شاشة الموظفين.');
        }
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
