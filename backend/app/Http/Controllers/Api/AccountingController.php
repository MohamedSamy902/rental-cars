<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\MonthLedgerService;
use App\Services\Dashboard\HomeDailyCashService;
use App\Support\PaginationInput;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingController extends Controller
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly MonthLedgerService $ledger,
        private readonly HomeDailyCashService $dailyCash,
    ) {}

    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->accounting->overview()]);
    }

    public function dailyCash(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
        ]);
        $day = isset($data['date'])
            ? Carbon::parse($data['date'], config('app.timezone', 'Africa/Cairo'))
            : now();

        $user = $request->user();
        abort_unless($user !== null, 401);

        $cash = $this->dailyCash->forUser($user, $day, HomeDailyCashService::ACCOUNTS_LIMIT);
        if ($cash === null) {
            return response()->json(['message' => 'ليست لديك صلاحية لعرض حركة الفلوس.'], 403);
        }

        return response()->json(['data' => $cash]);
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
        $perPage = PaginationInput::perPage($request, 50, PaginationInput::MAX_PER_PAGE);
        $page = PaginationInput::page($request);
        $rows = Payment::query()->latest('id')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function storePayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string'],
            'direction' => ['required', 'in:in,out'],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string'],
            'link_type' => ['required', 'string', 'in:contract,customer,deposit,vehicle'],
            'link_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string'],
            'balance_due_on' => ['nullable', 'date', 'after_or_equal:today'],
        ], [
            'method.required' => 'الطريقة مطلوبة.',
            'link_id.required' => 'معرّف الربط مطلوب.',
        ], [
            'amount' => 'المبلغ',
            'method' => 'الطريقة',
            'direction' => 'الاتجاه',
            'link_type' => 'نوع الربط',
            'link_id' => 'معرّف الربط',
            'balance_due_on' => 'تاريخ تحصيل المتبقي',
        ]);

        try {
            $payment = $this->accounting->recordPayment($data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $payment, 'message' => 'تم تسجيل الحركة.'], 201);
    }
}
