<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
     * @return array{
     *   id:int,name:string,email:string,role:?string,role_name:?string,
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
