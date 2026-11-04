<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'غير مصرح.'], 401);
        }

        $required = [];
        foreach ($permissions as $permission) {
            foreach (preg_split('/[|,]/', $permission) ?: [] as $key) {
                $key = trim($key);
                if ($key === '') {
                    continue;
                }
                $required[] = $key;
                if ($user->hasPermission($key)) {
                    return $next($request);
                }
            }
        }

        return response()->json([
            'message' => 'ليست لديك صلاحية لتنفيذ هذا الإجراء.',
            'required' => array_values(array_unique($required)),
        ], 403);
    }
}
