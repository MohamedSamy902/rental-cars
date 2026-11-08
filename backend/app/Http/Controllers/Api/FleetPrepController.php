<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Fleet\FleetDailyPrepService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FleetPrepController extends Controller
{
    public function __construct(private readonly FleetDailyPrepService $prep) {}

    public function daily(Request $request): JsonResponse
    {
        $days = (int) $request->integer('days', 5);
        $from = $request->filled('from')
            ? Carbon::parse((string) $request->input('from'))
            : null;

        $user = $request->user();

        return response()->json([
            'data' => $this->prep->summarize(
                $from,
                $days,
                (bool) $user?->hasPermission('contracts.view'),
                (bool) $user?->hasPermission('customers.view'),
            ),
        ]);
    }
}
