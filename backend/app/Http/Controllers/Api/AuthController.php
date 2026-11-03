<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'البريد الإلكتروني غير صالح.',
            'password.required' => 'كلمة المرور مطلوبة.',
        ]);

        /** @var User|null $user */
        $user = User::query()->with('role')->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['بيانات الدخول غير صحيحة.'],
            ]);
        }

        $token = $user->createToken('rpm-spa')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->presentUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role.permissions');

        return response()->json($this->presentUser($user));
    }

    /**
     * Self-service profile update — auth:sanctum only, no RBAC permission gate.
     * Callers may only change personal fields; role/permissions cannot be escalated here.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'current_password' => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'الاسم مطلوب.',
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'البريد الإلكتروني غير صالح.',
            'email.unique' => 'البريد الإلكتروني مستخدم مسبقاً.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن ٨ أحرف.',
            'password.confirmed' => 'تأكيد كلمة المرور غير متطابق.',
        ], [
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'phone' => 'الهاتف',
            'current_password' => 'كلمة المرور الحالية',
            'password' => 'كلمة المرور الجديدة',
        ]);

        $changingPassword = filled($data['password'] ?? null);
        if ($changingPassword) {
            $current = (string) ($data['current_password'] ?? '');
            if ($current === '' || ! Hash::check($current, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['كلمة المرور الحالية غير صحيحة.'],
                ]);
            }
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->phone = $data['phone'] ?? null;
        if ($changingPassword) {
            $user->password = $data['password'];
        }
        $user->save();

        // Sync personal name onto linked HR employee record when present.
        if ($user->employee_id) {
            Employee::query()->whereKey($user->employee_id)->update([
                'name' => $user->name,
            ]);
        }

        $fresh = $user->fresh(['role.permissions']);

        return response()->json([
            'data' => $this->presentUser($fresh ?? $user),
            'message' => 'تم تحديث بيانات حسابك بنجاح.',
        ]);
    }

    /**
     * @return array{
     *   id:int,name:string,email:string,phone:?string,role:?string,role_name:?string,
     *   permissions:list<string>,is_integrator:bool,feature_flags:array<string,bool>
     * }
     */
    private function presentUser(User $user): array
    {
        $user->loadMissing('role.permissions');
        $permissions = $user->role?->permissions?->pluck('key')->values()->all() ?? [];
        $roleName = $user->role?->name;
        $flags = TenantSetting::query()->value('feature_flags') ?? [];

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role?->label,
            'role_name' => $roleName,
            'permissions' => $permissions,
            'is_integrator' => $roleName === 'super_admin',
            'feature_flags' => is_array($flags) ? $flags : [],
        ];
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }
}
