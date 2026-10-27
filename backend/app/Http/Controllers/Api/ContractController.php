<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\Contracts\ContractLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function __construct(private readonly ContractLifecycleService $lifecycle) {}

    public function index(Request $request): JsonResponse
    {
        $q = Contract::query()->with(['customer', 'vehicle'])->latest('id');
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

        $contract = $this->lifecycle->create($data, $request->user()?->id);

        return response()->json(['data' => $contract, 'message' => 'تم إنشاء العقد.'], 201);
    }

    public function show(Contract $contract): JsonResponse
    {
        return response()->json(['data' => $contract->load(['customer', 'vehicle', 'deposit', 'inspections'])]);
    }

    public function approve(Request $request, Contract $contract): JsonResponse
    {
        $contract = $this->lifecycle->approve($contract, $request->user()?->id);

        return response()->json(['data' => $contract, 'message' => 'تم اعتماد العقد وتثبيت الأسعار.']);
    }

    public function handover(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'odometer_out' => ['required', 'integer', 'min:0'],
        ], [], ['odometer_out' => 'عداد الخروج']);

        $contract = $this->lifecycle->activateOnHandover($contract, (int) $data['odometer_out'], $request->user()?->id);

        return response()->json(['data' => $contract, 'message' => 'تم التسليم وتفعيل العقد.']);
    }

    public function returnVehicle(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'odometer_in' => ['required', 'integer', 'min:0'],
            'returned_at' => ['nullable', 'date'],
        ], [], ['odometer_in' => 'عداد العودة']);

        $returnedAt = isset($data['returned_at']) ? new \DateTimeImmutable($data['returned_at']) : null;
        $contract = $this->lifecycle->completeOnReturn(
            $contract,
            (int) $data['odometer_in'],
            $returnedAt,
            $request->user()?->id,
        );

        return response()->json(['data' => $contract, 'message' => 'تم الاستلام وإقفال الإجمالي.']);
    }
}
