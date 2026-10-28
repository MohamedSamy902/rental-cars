<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Services\Operations\PayrollService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $service) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PayrollRun::query()->with('lines.employee')->latest('id')->limit(24)->get(),
        ]);
    }

    public function draft(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'notes' => ['nullable', 'string'],
        ], [], ['year' => 'السنة', 'month' => 'الشهر']);

        try {
            $run = $this->service->draft((int) $data['year'], (int) $data['month'], $request->user()?->id, $data['notes'] ?? null);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $run, 'message' => 'تم تجهيز مسودة المسير.'], 201);
    }

    public function approve(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        try {
            $run = $this->service->approve($payrollRun, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $run, 'message' => 'تم اعتماد مسير الرواتب.']);
    }
}
