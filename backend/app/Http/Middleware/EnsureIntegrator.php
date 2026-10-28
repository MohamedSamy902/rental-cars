<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIntegrator
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'غير مصرح.'], 401);
        }

        $user->loadMissing('role');
        if ($user->role?->name !== 'super_admin') {
            return response()->json(['message' => 'لوحة المُدمج خاصة بمقدّم النظام فقط.'], 403);
        }

        return $next($request);
    }
}
