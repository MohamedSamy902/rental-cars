<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\MonthLedgerService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingController extends Controller
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly MonthLedgerService $ledger,
    ) {}

    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->accounting->overview()]);
    }

    public function month(int $year, int $month): JsonResponse
    {
        try {
            $data = $this->ledger->build($year, $month);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $data]);
    }

    public function lockMonth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ], [], ['year' => 'السنة', 'month' => 'الشهر']);

        try {
            $lock = $this->accounting->lockMonth((int) $data['year'], (int) $data['month'], $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $lock, 'message' => 'تم إقفال الشهر.']);
    }

    public function reopenMonth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'reason' => ['required', 'string', 'min:3'],
        ], [], ['year' => 'السنة', 'month' => 'الشهر', 'reason' => 'السبب']);

        try {
            $lock = $this->accounting->reopenMonth(
                (int) $data['year'],
                (int) $data['month'],
                $data['reason'],
                $request->user()?->id,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $lock, 'message' => 'أُعيد فتح الشهر.']);
    }

    public function payments(Request $request): JsonResponse
    {
        $rows = Payment::query()->latest('id')->limit(100)->get();

        return response()->json(['data' => $rows]);
    }

    public function storePayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string'],
            'direction' => ['required', 'in:in,out'],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string'],
            'link_type' => ['required', 'string'],
            'link_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'amount' => 'المبلغ',
            'method' => 'الطريقة',
            'direction' => 'الاتجاه',
            'link_type' => 'نوع الربط',
        ]);

        try {
            $payment = $this->accounting->recordPayment($data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $payment, 'message' => 'تم تسجيل الحركة.'], 201);
    }
}
