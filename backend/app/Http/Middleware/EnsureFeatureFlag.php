<?php

namespace App\Http\Middleware;

use App\Models\TenantSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureFlag
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        $settings = TenantSetting::query()->first();
        $flags = $settings?->feature_flags ?? [];
        $enabled = array_key_exists($flag, $flags) ? (bool) $flags[$flag] : true;

        if (! $enabled) {
            return response()->json([
                'message' => 'هذه الوحدة غير مفعّلة من لوحة المُدمج.',
                'feature' => $flag,
            ], 403);
        }

        return $next($request);
    }
}
