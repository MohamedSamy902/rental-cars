<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Violation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViolationController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Violation::query()->with(['vehicle', 'customer', 'contract'])->latest('id')->paginate(50);

        return response()->json(['data' => $rows->items(), 'meta' => ['total' => $rows->total()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'occurred_at' => ['required', 'date'],
            'location' => ['nullable', 'string'],
            'amount' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
        ], [], [
            'vehicle_id' => 'السيارة',
            'occurred_at' => 'وقت المخالفة',
            'amount' => 'المبلغ',
        ]);

        $contractId = $data['contract_id'] ?? null;
        $customerId = null;
        if (! $contractId) {
            $contract = Contract::query()
                ->where('vehicle_id', $data['vehicle_id'])
                ->whereIn('status', ['active', 'completed'])
                ->where('starts_at', '<=', $data['occurred_at'])
                ->where(function ($q) use ($data) {
                    $q->whereNull('actual_ends_at')
                        ->orWhere('actual_ends_at', '>=', $data['occurred_at']);
                })
                ->orderByDesc('starts_at')
                ->first();
            if ($contract) {
                $contractId = $contract->id;
                $customerId = $contract->customer_id;
            }
        } else {
            $customerId = Contract::query()->find($contractId)?->customer_id;
        }

        $violation = Violation::query()->create([
            ...$data,
            'contract_id' => $contractId,
            'customer_id' => $customerId,
            'status' => $contractId ? 'assigned' : 'new',
        ]);

        return response()->json([
            'data' => $violation->load(['vehicle', 'customer', 'contract']),
            'message' => 'تم تسجيل المخالفة للمتابعة (بلا ترحيل محاسبي).',
        ], 201);
    }

    public function confirmPayment(Request $request, Violation $violation): JsonResponse
    {
        $data = $request->validate([
            'collection_note' => ['nullable', 'string'],
        ]);

        $violation->update([
            'status' => 'confirmed',
            'collection_note' => $data['collection_note'] ?? $violation->collection_note,
            'confirmed_by' => $request->user()?->id,
            'confirmed_at' => now(),
        ]);

        return response()->json([
            'data' => $violation->fresh(),
            'message' => 'تم تأكيد السداد دون قيد محاسبي.',
        ]);
    }
}
