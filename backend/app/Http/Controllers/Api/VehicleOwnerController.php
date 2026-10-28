<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OwnerPayout;
use App\Models\VehicleOwner;
use App\Services\Fleet\VehicleOwnerService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleOwnerController extends Controller
{
    public function __construct(private readonly VehicleOwnerService $owners) {}

    public function index(Request $request): JsonResponse
    {
        $q = VehicleOwner::query()->withCount('vehicles')->latest('id');
        if ($search = $request->string('search')->toString()) {
            $q->where(function ($qq) use ($search) {
                $qq->where('full_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('national_id', 'like', "%{$search}%");
            });
        }
        if ($request->boolean('active_only', false)) {
            $q->where('is_active', true);
        }

        $rows = $q->paginate((int) $request->integer('per_page', 50));
        $data = collect($rows->items())->map(function (VehicleOwner $o) {
            // قائمة خفيفة: رصيد من القيود الحالية دون إعادة استحقاق جماعي
            $accrued = (int) $o->monthDues()->sum('amount');
            $paid = (int) $o->payouts()->sum('amount');
            $monthly = (int) $o->vehicles()->where('is_active', true)->sum('owner_monthly_amount');

            return $this->present($o, [
                'accrued_total' => $accrued,
                'paid_total' => $paid,
                'remaining' => max(0, $accrued - $paid),
                'month' => now()->format('Y-m'),
                'month_accrued' => (int) $o->monthDues()->where('year_month', now()->format('Y-m'))->sum('amount'),
                'month_paid' => (int) $o->payouts()
                    ->whereBetween('paid_on', [now()->format('Y-m-01'), now()->format('Y-m-t')])
                    ->sum('amount'),
                'vehicles_monthly_total' => $monthly,
            ]);
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'phone_alt' => ['nullable', 'string', 'max:30'],
            'national_id' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'full_name' => 'اسم المالك',
            'phone' => 'الهاتف',
        ]);

        $owner = $this->owners->createOwner($data);

        return response()->json(['data' => $this->present($owner), 'message' => 'تم تسجيل المالك.'], 201);
    }

    public function show(Request $request, VehicleOwner $owner): JsonResponse
    {
        $ym = $request->string('month')->toString() ?: now()->format('Y-m');
        $owner->load(['vehicles' => fn ($q) => $q->orderBy('code')]);
        $balance = $this->owners->balance($owner, $ym);
        $dues = $owner->monthDues()->with('vehicle:id,code,plate,brand,model')->orderByDesc('year_month')->limit(24)->get();
        $payouts = $owner->payouts()->with('vehicle:id,code,plate')->orderByDesc('paid_on')->orderByDesc('id')->limit(50)->get();

        return response()->json([
            'data' => [
                ...$this->present($owner, $balance),
                'vehicles' => $owner->vehicles->map(fn ($v) => [
                    'id' => $v->id,
                    'code' => $v->code,
                    'plate' => $v->plate,
                    'brand' => $v->brand,
                    'model' => $v->model,
                    'owner_monthly_amount' => $v->owner_monthly_amount,
                    'owner_since' => $v->owner_since?->toDateString(),
                    'is_active' => $v->is_active,
                ]),
                'dues' => $dues,
                'payouts' => $payouts,
            ],
        ]);
    }

    public function update(Request $request, VehicleOwner $owner): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'phone_alt' => ['nullable', 'string', 'max:30'],
            'national_id' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $owner = $this->owners->updateOwner($owner, $data);

        return response()->json(['data' => $this->present($owner), 'message' => 'تم تحديث المالك.']);
    }

    public function balance(Request $request, VehicleOwner $owner): JsonResponse
    {
        $ym = $request->string('month')->toString() ?: now()->format('Y-m');

        return response()->json(['data' => $this->owners->balance($owner, $ym)]);
    }

    public function payout(Request $request, VehicleOwner $owner): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'paid_on' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'amount' => 'المبلغ',
            'paid_on' => 'تاريخ الصرف',
        ]);

        try {
            $row = $this->owners->payout($owner, $data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $balance = $this->owners->balance($owner);

        return response()->json([
            'data' => $row,
            'balance' => $balance,
            'message' => 'تم تسجيل صرف للمالك.',
        ], 201);
    }

    public function payoutsIndex(Request $request): JsonResponse
    {
        $q = OwnerPayout::query()->with(['owner:id,full_name', 'vehicle:id,code,plate'])->latest('paid_on')->latest('id');
        if ($ownerId = $request->integer('owner_id')) {
            $q->where('owner_id', $ownerId);
        }
        if ($month = $request->string('month')->toString()) {
            $q->whereBetween('paid_on', [$month.'-01', $month.'-31']);
        }

        return response()->json(['data' => $q->limit(100)->get()]);
    }

    /**
     * @param  array<string, mixed>|null  $balance
     * @return array<string, mixed>
     */
    private function present(VehicleOwner $o, ?array $balance = null): array
    {
        return [
            'id' => $o->id,
            'full_name' => $o->full_name,
            'phone' => $o->phone,
            'phone_alt' => $o->phone_alt,
            'national_id' => $o->national_id,
            'notes' => $o->notes,
            'is_active' => $o->is_active,
            'vehicles_count' => $o->vehicles_count ?? $o->vehicles()->count(),
            'balance' => $balance,
        ];
    }
}
