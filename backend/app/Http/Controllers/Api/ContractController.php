<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\Contracts\ContractLifecycleService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function __construct(private readonly ContractLifecycleService $lifecycle) {}

    public function index(Request $request): JsonResponse
    {
        $q = Contract::query()->with(['customer', 'vehicle', 'deposit'])->latest('id');
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }
        if ($search = $request->string('search')->toString()) {
            $q->where(function ($qq) use ($search) {
                $qq->where('number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$search}%"));
            });
        }

        $rows = $q->paginate((int) $request->integer('per_page', 50));

        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
            'summary' => [
                'total' => Contract::query()->count(),
                'pending' => Contract::query()->where('status', 'pending')->count(),
                'approved' => Contract::query()->where('status', 'approved')->count(),
                'active' => Contract::query()->where('status', 'active')->count(),
                'completed' => Contract::query()->where('status', 'completed')->count(),
                'cancelled' => Contract::query()->where('status', 'cancelled')->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'guarantor_customer_id' => ['nullable', 'exists:customers,id'],
            'starts_at' => ['required', 'date'],
            'planned_ends_at' => ['required', 'date', 'after:starts_at'],
            'daily_rate' => ['nullable', 'integer', 'min:0'],
            'daily_km_allowance' => ['nullable', 'integer', 'min:0'],
            'excess_km_rate' => ['nullable', 'integer', 'min:0'],
            'deposit' => ['nullable', 'integer', 'min:0'],
            'min_days' => ['nullable', 'integer', 'min:0'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'other_fees' => ['nullable', 'integer', 'min:0'],
            'other_fees_description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'extended_from_contract_id' => ['nullable', 'exists:contracts,id'],
            'swapped_from_contract_id' => ['nullable', 'exists:contracts,id'],
            'swap_reason' => ['nullable', 'string'],
        ], [], [
            'customer_id' => 'العميل',
            'vehicle_id' => 'السيارة',
            'starts_at' => 'وقت البداية',
            'planned_ends_at' => 'وقت النهاية المخطط',
        ]);

        try {
            $contract = $this->lifecycle->create($data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $contract, 'message' => 'تم إنشاء العقد.'], 201);
    }

    public function show(Contract $contract): JsonResponse
    {
        return response()->json(['data' => $contract->load(['customer', 'vehicle', 'deposit.deductions', 'inspections'])]);
    }

    public function approve(Request $request, Contract $contract): JsonResponse
    {
        try {
            $contract = $this->lifecycle->approve($contract, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $contract, 'message' => 'تم اعتماد العقد وتثبيت الأسعار.']);
    }

    public function handover(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'odometer_out' => ['required', 'integer', 'min:0'],
            'fuel_level' => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
            'signature_data' => ['nullable', 'string'],
            'accessories' => ['nullable', 'array'],
            'accessories.*.name' => ['required_with:accessories', 'string'],
            'accessories.*.expected_qty' => ['nullable', 'integer', 'min:0'],
            'accessories.*.actual_qty' => ['nullable', 'integer', 'min:0'],
            'accessories.*.ok' => ['nullable', 'boolean'],
            'damages' => ['nullable', 'array'],
            'damages.*.position' => ['required_with:damages', 'string'],
            'damages.*.description' => ['required_with:damages', 'string'],
        ], [], ['odometer_out' => 'عداد الخروج', 'signature_data' => 'توقيع العميل']);

        try {
            $contract = $this->lifecycle->activateOnHandover(
                $contract,
                (int) $data['odometer_out'],
                $request->user()?->id,
                $data,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $contract, 'message' => 'تم التسليم وتفعيل العقد.']);
    }

    public function returnVehicle(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'odometer_in' => ['required', 'integer', 'min:0'],
            'returned_at' => ['nullable', 'date'],
            'damage_fee' => ['nullable', 'integer', 'min:0'],
            'fuel_level' => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
            'signature_data' => ['nullable', 'string'],
            'accessories' => ['nullable', 'array'],
            'accessories.*.name' => ['required_with:accessories', 'string'],
            'accessories.*.expected_qty' => ['nullable', 'integer', 'min:0'],
            'accessories.*.actual_qty' => ['nullable', 'integer', 'min:0'],
            'accessories.*.ok' => ['nullable', 'boolean'],
            'damages' => ['nullable', 'array'],
            'damages.*.position' => ['required_with:damages', 'string'],
            'damages.*.description' => ['required_with:damages', 'string'],
        ], [], ['odometer_in' => 'عداد العودة', 'signature_data' => 'توقيع العميل']);

        $returnedAt = isset($data['returned_at']) ? new \DateTimeImmutable($data['returned_at']) : null;

        try {
            $contract = $this->lifecycle->completeOnReturn(
                $contract,
                (int) $data['odometer_in'],
                $returnedAt,
                $request->user()?->id,
                (int) ($data['damage_fee'] ?? 0),
                $data,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $contract, 'message' => 'تم الاستلام وإقفال الإجمالي.']);
    }

    public function printData(Contract $contract): JsonResponse
    {
        $contract->load(['customer', 'vehicle.documents', 'vehicle.accessories', 'deposit.deductions', 'inspections.damages']);
        $tenant = \App\Models\TenantSetting::query()->first();

        return response()->json([
            'data' => [
                'tenant' => [
                    'center_name' => $tenant?->center_name,
                    'print_footer' => $tenant?->print_footer,
                ],
                'contract' => $contract,
            ],
        ]);
    }

    public function cancel(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
        ], [], ['reason' => 'سبب الإلغاء']);

        try {
            $contract = $this->lifecycle->cancel($contract, $data['reason'], $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $contract, 'message' => 'تم إلغاء العقد.']);
    }

    public function extend(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'planned_ends_at' => ['required', 'date'],
            'starts_at' => ['nullable', 'date'],
        ], [], ['planned_ends_at' => 'نهاية التمديد']);

        try {
            $created = $this->lifecycle->extend($contract, $data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $created, 'message' => 'تم إنشاء عقد التمديد.'], 201);
    }

    public function swap(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'replacement_vehicle_id' => ['required', 'exists:vehicles,id'],
            'odometer_in' => ['required', 'integer', 'min:0'],
            'odometer_out' => ['required', 'integer', 'min:0'],
            'planned_ends_at' => ['nullable', 'date'],
            'damage_fee' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'replacement_vehicle_id' => 'السيارة البديلة',
            'odometer_in' => 'عداد السيارة القديمة',
            'odometer_out' => 'عداد السيارة البديلة',
        ]);

        try {
            $result = $this->lifecycle->swapDueToFault($contract, $data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $result,
            'message' => 'تم التبديل بسبب عطل: أُغلق العقد القديم وأنشئ عقد جديد.',
        ]);
    }

    public function receiveDeposit(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', 'integer', 'min:1'],
            'method' => ['required', 'string'],
            'receipt_number' => ['required', 'string'],
            'collection_ref' => ['nullable', 'string'],
        ], [], [
            'method' => 'طريقة التحصيل',
            'receipt_number' => 'رقم الإيصال',
        ]);

        try {
            $deposit = $this->lifecycle->receiveDeposit($contract, $data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $deposit, 'message' => 'تم قبض التأمين النقدي.'], 201);
    }

    public function deductDeposit(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
        ], [], ['reason' => 'سبب الخصم', 'amount' => 'المبلغ']);

        $deposit = $contract->deposit;
        if (! $deposit) {
            return response()->json(['message' => 'لا يوجد تأمين نقدي على العقد.'], 422);
        }

        try {
            $deposit = $this->lifecycle->deductDeposit($deposit, $data, (int) $request->user()->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $deposit, 'message' => 'تم خصم المبلغ من التأمين.']);
    }

    public function returnDeposit(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string'],
            'receipt_number' => ['required', 'string'],
        ], [], ['method' => 'طريقة الرد', 'receipt_number' => 'رقم إيصال الرد']);

        $deposit = $contract->deposit;
        if (! $deposit) {
            return response()->json(['message' => 'لا يوجد تأمين نقدي على العقد.'], 422);
        }

        try {
            $deposit = $this->lifecycle->returnDeposit($deposit, $data['method'], $data['receipt_number'], $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $deposit, 'message' => 'تم رد متبقي التأمين النقدي.']);
    }
}
