<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\Fleet\VehicleShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VehicleShareController extends Controller
{
    public function __construct(private readonly VehicleShareService $shares) {}

    /** موظف مسجّل: يجهّز رابط بطاقة عامة + نص واتساب. */
    public function prepare(Request $request, Vehicle $vehicle): JsonResponse
    {
        $user = $request->user();
        $ok = $user?->hasPermission('vehicles.view') || $user?->hasPermission('showroom.run');
        if (! $ok) {
            return response()->json(['message' => __('http.forbidden')], 403);
        }

        return response()->json([
            'data' => $this->shares->prepare($vehicle),
        ]);
    }

    /** صفحة عامة (HTML) تُفتح من واتساب بلا لوجين. */
    public function publicShare(Request $request): Response
    {
        $id = (int) $request->query('id', 0);
        $vehicle = Vehicle::query()->whereKey($id)->where('is_active', true)->first();
        if (! $vehicle) {
            return response('العربية غير موجودة أو غير نشطة.', 404)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($this->shares->renderPublicHtml($vehicle), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
