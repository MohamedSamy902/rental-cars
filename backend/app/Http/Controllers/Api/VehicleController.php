<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\MediaFile;
use App\Models\OwnerMonthDue;
use App\Models\OwnerPayout;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\Violation;
use App\Models\WorkOrder;
use App\Services\Fleet\VehicleAssetService;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Services\Fleet\VehicleRecommendationService;
use App\Services\Fleet\VehicleService;
use App\Support\MaintenanceContractLabel;
use App\Support\PaginationInput;
use App\Support\PublicAppUrl;
use App\Support\VehicleOwnerVisibility;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function __construct(
        private readonly VehicleAvailabilityService $availability,
        private readonly VehicleRecommendationService $recommendation,
        private readonly VehicleAssetService $assets,
        private readonly VehicleService $vehicles,
    ) {}

    public function catalogs(): JsonResponse
    {
        return response()->json([
            'data' => [
                'brands' => $this->vehicles->brandOptions(),
                'addons' => $this->vehicles->addonCatalog(),
                'next_code' => $this->vehicles->nextInternalCode(),
            ],
        ]);
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ], [
            'name.required' => 'اسم الماركة مطلوب.',
        ], [
            'name' => 'اسم الماركة',
        ]);

        try {
            $brand = $this->vehicles->createBrand((string) $data['name']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => ['id' => $brand->id, 'name' => $brand->name],
            'message' => 'تم إضافة الماركة للقائمة.',
        ], 201);
    }

    public function recommend(Request $request): JsonResponse
    {
        $category = $request->string('category')->toString() ?: null;
        $from = $request->filled('from') ? Carbon::parse((string) $request->input('from')) : null;
        $to = $request->filled('to') ? Carbon::parse((string) $request->input('to')) : null;

        return response()->json([
            'data' => $this->recommendation->rankAvailable($category ?: null, null, $from, $to),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $q = Vehicle::query()->latest('id');

        if ($search = $request->string('search')->toString()) {
            $q->where(function ($qq) use ($search) {
                $qq->where('code', 'like', "%{$search}%")
                    ->orWhere('plate', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%");
            });
        }

        if ($status = $request->string('status')->toString()) {
            if ($status === 'maintenance') {
                $q->where('manual_status', 'maintenance');
            } elseif ($status === 'available') {
                $periodFrom = $request->filled('from')
                    ? Carbon::parse((string) $request->input('from'))
                    : null;
                $periodTo = $request->filled('to')
                    ? Carbon::parse((string) $request->input('to'))
                    : null;
                $q->where(function ($qq) {
                    $qq->whereNull('manual_status')->orWhereNotIn('manual_status', ['maintenance', 'out_of_service', 'sold']);
                });
                if ($periodFrom && $periodTo && $periodTo->gte($periodFrom)) {
                    // Future-window availability: exclude cars whose contracts overlap [from, to).
                    $q->whereDoesntHave('contracts', function ($c) use ($periodFrom, $periodTo) {
                        $c->whereIn('status', ['pending', 'approved', 'active'])
                            ->where('starts_at', '<', $periodTo)
                            ->where(function ($qq) use ($periodFrom) {
                                $qq->where(function ($inner) use ($periodFrom) {
                                    $inner->whereNull('actual_ends_at')->where('planned_ends_at', '>', $periodFrom);
                                })->orWhere('actual_ends_at', '>', $periodFrom);
                            });
                    });
                } else {
                    $q->whereDoesntHave('contracts', function ($c) {
                        $c->whereIn('status', ['approved', 'active'])
                            ->where('starts_at', '<=', now())
                            ->where('planned_ends_at', '>=', now());
                    });
                }
            } elseif ($status === 'rented') {
                $q->whereHas('contracts', fn ($c) => $c->where('status', 'active'));
            } elseif ($status === 'reserved') {
                $q->whereHas('contracts', fn ($c) => $c->where('status', 'approved'));
            } elseif ($status === 'expiring') {
                $within = max(0, (int) $request->integer('within_days', 2));
                $from = now()->startOfDay();
                $until = now()->addDays($within)->endOfDay();
                $q->whereHas('contracts', function ($c) use ($from, $until) {
                    $c->where('status', 'active')
                        ->where('planned_ends_at', '>=', $from)
                        ->where('planned_ends_at', '<=', $until);
                });
            } elseif ($status === 'license_expiring') {
                $within = max(0, (int) $request->integer('within_days', 30));
                $until = now()->addDays($within)->toDateString();
                $q->whereHas('documents', function ($d) use ($until) {
                    $this->constrainLicenseDocuments($d)
                        ->whereNotNull('expires_on')
                        ->whereDate('expires_on', '<=', $until);
                });
            } elseif ($status === 'sim_recharge') {
                $within = max(0, (int) $request->integer('within_days', 14));
                $until = now()->addDays($within)->toDateString();
                $q->whereNotNull('tracker_sim_next_recharge_on')
                    ->whereDate('tracker_sim_next_recharge_on', '<=', $until);
            } elseif ($status === 'owner_agreement_expiring') {
                $within = max(0, (int) $request->integer('within_days', 30));
                $until = now()->addDays($within)->toDateString();
                $q->whereNotNull('owner_agreement_ends_on')
                    ->whereDate('owner_agreement_ends_on', '<=', $until);
            }
        }

        $with = [
            'documents' => function ($d) {
                $this->constrainLicenseDocuments($d)->orderByDesc('id');
            },
        ];
        if ($request->user()?->hasPermission('owners.view')) {
            $with[] = 'owner:id,full_name,phone';
        }
        if ($request->user()?->hasPermission('contracts.view')) {
            $with['contracts'] = function ($c) {
                $c->where('status', 'active')->latest('planned_ends_at');
            };
        }
        $q->with($with);
        $perPage = PaginationInput::perPage($request, 50, PaginationInput::MAX_PER_PAGE);
        $page = PaginationInput::page($request);
        $rows = $q->paginate($perPage, ['*'], 'page', $page);

        $data = collect($rows->items())->map(function (Vehicle $v) use ($request) {
            return $this->present($v, $request->user());
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
            'summary' => $this->summary(),
        ]);
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $ownerInline = $data['owner_inline'] ?? null;
        $addonIds = $data['addon_ids'] ?? [];
        unset($data['owner_inline'], $data['addon_ids']);

        try {
            $vehicle = $this->vehicles->create($data, is_array($ownerInline) ? $ownerInline : null, is_array($addonIds) ? $addonIds : []);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload = [
            'data' => $this->present($vehicle, $request->user()),
            'message' => 'تم إضافة العربية.',
        ];
        if ($vehicle->owner_id && $request->user()?->hasPermission('owners.view')) {
            try {
                $payload['owner_agreement'] = $this->vehicles->ownerAgreementPrintPayload($vehicle);
                $payload['message'] = 'تم إضافة العربية وتسجيل عقد المالك للطباعة.';
            } catch (DomainException) {
                // القالب غير جاهز لا يمنع الحفظ
            }
        }

        return response()->json($payload, 201);
    }

    public function show(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('view', $vehicle);
        $with = ['accessories', 'documents'];
        if ($request->user()?->hasPermission('owners.view')) {
            $with[] = 'owner';
        }

        return response()->json(['data' => $this->present($vehicle->load($with), $request->user())]);
    }

    /** ملف العربية الكامل: حجوزات · صيانة · مصروفات · مرفقات · مالك · QR */
    public function dossier(Request $request, Vehicle $vehicle): JsonResponse
    {
        $viewer = $request->user();
        $canContracts = (bool) $viewer?->hasPermission('contracts.view');
        $with = ['accessories', 'documents'];
        if ($viewer?->hasPermission('owners.view')) {
            $with[] = 'owner';
        }
        $vehicle->load($with);

        $contracts = Contract::query()
            ->with(['customer:id,full_name,phone', 'deposit'])
            ->where('vehicle_id', $vehicle->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Contract $c) => [
                'id' => $c->id,
                'number' => $c->number,
                'status' => $c->status,
                'starts_at' => $c->starts_at?->toIso8601String(),
                'planned_ends_at' => $c->planned_ends_at?->toIso8601String(),
                'actual_ends_at' => $c->actual_ends_at?->toIso8601String(),
                'final_total' => $c->final_total,
                'period_rent' => $c->period_rent,
                'odometer_out' => $c->odometer_out,
                'odometer_in' => $c->odometer_in,
                'customer' => $c->customer ? [
                    'id' => $c->customer->id,
                    'full_name' => $c->customer->full_name,
                    'phone' => $c->customer->phone,
                ] : null,
            ]);

        $workOrders = WorkOrder::query()
            ->with('lines')
            ->where('vehicle_id', $vehicle->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (WorkOrder $w) => [
                'id' => $w->id,
                'status' => $w->status,
                'workshop' => MaintenanceContractLabel::present($w->workshop, $canContracts),
                'opened_on' => $w->opened_on?->toDateString(),
                'closed_on' => $w->closed_on?->toDateString(),
                'odometer' => $w->odometer,
                'parts_cost' => $w->parts_cost,
                'labor_cost' => $w->labor_cost,
                'notes' => MaintenanceContractLabel::present($w->notes, $canContracts),
                'lines' => $w->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'description' => MaintenanceContractLabel::present($l->description, $canContracts),
                    'quantity' => $l->quantity,
                    'unit_price' => $l->unit_price,
                ])->values(),
            ]);

        $expenses = Expense::query()
            ->where('vehicle_id', $vehicle->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Expense $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'category' => $e->category,
                'amount' => $e->amount,
                'spent_on' => $e->spent_on?->toDateString(),
                'payment_method' => $e->payment_method,
                'notes' => $e->notes,
            ]);

        $violations = Violation::query()
            ->with('customer:id,full_name')
            ->where('vehicle_id', $vehicle->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Violation $v) => [
                'id' => $v->id,
                'status' => $v->status,
                'amount' => $v->amount,
                'location' => $v->location,
                'occurred_at' => $v->occurred_at?->toIso8601String(),
                'customer' => $v->customer ? [
                    'id' => $v->customer->id,
                    'full_name' => $v->customer->full_name,
                ] : null,
            ]);

        $media = MediaFile::query()
            ->where('attachable_type', Vehicle::class)
            ->where('attachable_id', $vehicle->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (MediaFile $m) => [
                'id' => $m->id,
                'kind' => $m->kind,
                'original_name' => $m->original_name,
                'collection' => $m->collection,
                'purpose' => $m->purpose,
                'mime_type' => $m->mime_type,
                'size_bytes' => $m->size_bytes,
                'url' => url('/api/media/'.$m->id.'/file'),
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        $ownerDues = OwnerMonthDue::query()
            ->where('vehicle_id', $vehicle->id)
            ->latest('year_month')
            ->limit(36)
            ->get()
            ->map(fn (OwnerMonthDue $d) => [
                'id' => $d->id,
                'year_month' => $d->year_month,
                'amount' => $d->amount,
            ]);

        $ownerPayouts = OwnerPayout::query()
            ->where('vehicle_id', $vehicle->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (OwnerPayout $p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'paid_on' => $p->paid_on?->toDateString() ?? $p->paid_on,
                'payment_method' => $p->payment_method,
                'notes' => $p->notes,
            ]);

        $qr = PublicAppUrl::to('/showroom?vehicle='.$vehicle->id);
        $qrLabel = trim($vehicle->brand.' '.$vehicle->model.' · '.$vehicle->plate);

        $user = $viewer;
        $canExpenses = (bool) ($user?->hasPermission('expenses.view') || $user?->hasPermission('hr.expenses'));
        $canOwners = (bool) $user?->hasPermission('owners.view');
        $canCustomers = (bool) $user?->hasPermission('customers.view');
        $canFinance = (bool) ($user?->hasPermission('reports.view') || $user?->hasPermission('accounting.view'));
        $contractMoney = $contracts;
        if (! $canCustomers) {
            $contracts = $contracts->map(function (array $row) {
                $row['customer'] = null;

                return $row;
            });
            $violations = $violations->map(function (array $row) {
                $row['customer'] = null;

                return $row;
            });
        }
        // Contract rows follow contracts.view. Per-contract amounts stay with that
        // permission because GET /contracts/{id} already returns them. Revenue
        // totals and charts stay behind reports.view or accounting.view.
        if (! $canContracts) {
            $contracts = collect();
        }

        $contractStatus = $contracts->groupBy('status')->map(fn ($rows, $status) => [
            'status' => $status,
            'label' => $status,
            'count' => $rows->count(),
        ])->values();

        $months = collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonths(11 - $i)->format('Y-m'));

        $expensesByMonth = $months->map(function (string $ym) use ($expenses) {
            $sum = $expenses->filter(fn ($e) => is_string($e['spent_on'] ?? null) && str_starts_with((string) $e['spent_on'], $ym))
                ->sum('amount');

            return ['month' => $ym, 'amount' => (int) $sum];
        });

        $maintenanceByMonth = $months->map(function (string $ym) use ($workOrders) {
            $rows = $workOrders->filter(fn ($w) => is_string($w['opened_on'] ?? null) && str_starts_with((string) $w['opened_on'], $ym));
            $cost = $rows->sum(fn ($w) => (int) ($w['parts_cost'] ?? 0) + (int) ($w['labor_cost'] ?? 0));

            return ['month' => $ym, 'amount' => (int) $cost, 'count' => $rows->count()];
        });

        $revenueByMonth = $months->map(function (string $ym) use ($contractMoney, $canFinance) {
            if (! $canFinance) {
                return ['month' => $ym, 'amount' => 0];
            }
            $sum = $contractMoney->filter(function ($c) use ($ym) {
                $start = is_string($c['starts_at'] ?? null) ? substr((string) $c['starts_at'], 0, 7) : null;

                return $start === $ym && in_array($c['status'], ['active', 'completed', 'approved'], true);
            })->sum(fn ($c) => (int) ($c['final_total'] ?? $c['period_rent'] ?? 0));

            return ['month' => $ym, 'amount' => (int) $sum];
        });

        $assetExtras = $this->assets->dossierExtras($vehicle);
        if (! $canExpenses) {
            $expenses = collect();
            $expensesByMonth = $expensesByMonth->map(fn (array $row) => ['month' => $row['month'], 'amount' => 0]);
        }
        if (! $canOwners) {
            $ownerDues = collect();
            $ownerPayouts = collect();
        }
        if (! $canFinance) {
            $assetExtras['defaults']['lost_income_daily_basis'] = 0;
            $assetExtras['downtime'] = $assetExtras['downtime']->map(function ($row) {
                $row->lost_revenue_estimate = 0;

                return $row;
            });
        }

        return response()->json([
            'data' => [
                'vehicle' => $this->present($vehicle, $viewer),
                'contracts' => $contracts->values(),
                'work_orders' => $workOrders->values(),
                'expenses' => $expenses->values(),
                'violations' => $violations->values(),
                'media' => $media->values(),
                'owner_dues' => $ownerDues->values(),
                'owner_payouts' => $ownerPayouts->values(),
                'tires' => $assetExtras['tires'],
                'batteries' => $assetExtras['batteries'],
                'downtime' => $assetExtras['downtime'],
                'consumables' => $assetExtras['consumables'],
                'asset_defaults' => $assetExtras['defaults'],
                'qr' => [
                    'payload' => $qr,
                    'label' => $qrLabel,
                ],
                'charts' => [
                    'contracts_by_status' => $contractStatus,
                    'expenses_by_month' => $expensesByMonth->values(),
                    'maintenance_by_month' => $maintenanceByMonth->values(),
                    'revenue_by_month' => $revenueByMonth->values(),
                ],
                'totals' => [
                    'contracts' => $contracts->count(),
                    'work_orders' => $workOrders->count(),
                    'expenses_amount' => $expenses->sum('amount'),
                    'violations' => $violations->count(),
                    'media' => $media->count(),
                    'maintenance_cost' => $workOrders->sum(fn ($w) => (int) ($w['parts_cost'] ?? 0) + (int) ($w['labor_cost'] ?? 0)),
                    'revenue_amount' => $canFinance ? $contractMoney
                        ->filter(fn ($c) => in_array($c['status'], ['active', 'completed', 'approved'], true))
                        ->sum(fn ($c) => (int) ($c['final_total'] ?? $c['period_rent'] ?? 0)) : 0,
                    'accessories' => $vehicle->accessories->count(),
                    'documents' => $vehicle->documents->count(),
                    'lost_revenue' => $canFinance
                        ? collect($assetExtras['downtime'])->sum(fn ($d) => (int) ($d->lost_revenue_estimate ?? 0))
                        : 0,
                ],
            ],
        ]);
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $this->authorize('delete', $vehicle);
        $hasActive = $vehicle->contracts()
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->exists();
        if ($hasActive) {
            return response()->json(['message' => 'لا يمكن أرشفة العربية لوجود عقود نشطة.'], 422);
        }

        // تحرير code/plate من الفهرس الفريد الكامل حتى يمكن إعادة استخدامهما بعد الأرشفة (توافق MySQL).
        $suffix = '__del_'.$vehicle->id;
        $vehicle->forceFill([
            'code' => mb_substr($vehicle->code, 0, 40).$suffix,
            'plate' => mb_substr($vehicle->plate, 0, 40).$suffix,
            'is_active' => false,
        ])->save();
        $vehicle->delete();

        return response()->json(['message' => 'تم أرشفة العربية.']);
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        try {
            $data = $request->validated();
            $addonIds = array_key_exists('addon_ids', $data) ? ($data['addon_ids'] ?? []) : null;
            unset($data['addon_ids']);
            if (! $request->user()?->hasPermission('owners.view')) {
                unset($data['owner_id'], $data['owner_since']);
                if (! VehicleOwnerVisibility::canSeeAmount($request->user())) {
                    unset($data['owner_monthly_amount']);
                }
            }
            $vehicle = $this->vehicles->update($vehicle, $data, is_array($addonIds) ? $addonIds : null);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload = [
            'data' => $this->present($vehicle, $request->user()),
            'message' => 'تم تحديث العربية.',
        ];
        if ($vehicle->owner_id && $request->user()?->hasPermission('owners.view')) {
            try {
                $payload['owner_agreement'] = $this->vehicles->ownerAgreementPrintPayload($vehicle);
                $payload['message'] = 'تم تحديث العربية وتحديث عقد المالك للطباعة.';
            } catch (DomainException) {
                // القالب غير جاهز لا يمنع الحفظ
            }
        }

        return response()->json($payload);
    }

    public function ownerAgreementPrint(Request $request, Vehicle $vehicle): JsonResponse
    {
        if (! $request->user()?->hasPermission('owners.view')) {
            return response()->json(['message' => 'ليست لديك صلاحية لتنفيذ هذا الإجراء.'], 403);
        }

        try {
            $payload = $this->vehicles->ownerAgreementPrintPayload($vehicle);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Vehicle $v, ?User $viewer = null): array
    {
        $viewer ??= request()->user();
        $canOwners = VehicleOwnerVisibility::canSeeProfile($viewer);
        $canAmount = VehicleOwnerVisibility::canSeeAmount($viewer);
        $canContracts = (bool) $viewer?->hasPermission('contracts.view');
        $status = $this->availability->statusOnDate([
            'manual_status' => $v->manual_status,
            'has_active_handover' => $v->contracts()->where('status', 'active')->exists(),
            'has_approved_covering_date' => $v->contracts()
                ->where('status', 'approved')
                ->where('starts_at', '<=', now())
                ->where('planned_ends_at', '>=', now())
                ->exists(),
            'open_work_order_blocks' => $v->manual_status === 'maintenance'
                || WorkOrder::query()
                    ->where('vehicle_id', $v->id)
                    ->where('puts_in_maintenance', true)
                    ->whereIn('status', ['open', 'in_progress'])
                    ->exists(),
        ]);

        return [
            'id' => $v->id,
            'code' => $v->code,
            'plate' => $v->plate,
            'brand' => $v->brand,
            'model' => $v->model,
            'year' => $v->year,
            'category' => $v->category,
            'fuel_type' => $v->fuel_type,
            'color' => $v->color,
            'transmission' => $v->transmission,
            'chassis_number' => $v->chassis_number,
            'engine_number' => $v->engine_number,
            'engine_capacity' => $v->engine_capacity,
            'seats' => $v->seats,
            'odometer' => $v->odometer,
            'odometer_source' => $v->odometer_source,
            'daily_rate' => $v->daily_rate,
            'default_deposit' => $v->default_deposit,
            'purchase_value_estimate' => $v->purchase_value_estimate,
            'tracker_device_number' => $v->tracker_device_number,
            'tracker_sim_number' => $v->tracker_sim_number,
            'tracker_sim_last_recharged_on' => $v->tracker_sim_last_recharged_on?->toDateString(),
            'tracker_sim_next_recharge_on' => $v->tracker_sim_next_recharge_on?->toDateString(),
            'tracker_sim_recharge_cycle_days' => $v->tracker_sim_recharge_cycle_days !== null
                ? (int) $v->tracker_sim_recharge_cycle_days
                : null,
            'manual_status' => $v->manual_status,
            'is_active' => $v->is_active,
            'status' => $status,
            'owner_id' => $canOwners && $v->owner_id !== null ? (int) $v->owner_id : null,
            'owner_monthly_amount' => $canAmount && $v->owner_monthly_amount !== null ? (int) $v->owner_monthly_amount : null,
            'owner_since' => $canOwners ? $v->owner_since?->toDateString() : null,
            'owner_agreement_starts_on' => $v->owner_agreement_starts_on?->toDateString(),
            'owner_agreement_ends_on' => $v->owner_agreement_ends_on?->toDateString(),
            'owner' => $canOwners && $v->relationLoaded('owner') && $v->owner ? [
                'id' => $v->owner->id,
                'full_name' => $v->owner->full_name,
                'phone' => $v->owner->phone,
            ] : null,
            'accessories' => $v->relationLoaded('accessories') ? $v->accessories : null,
            'documents' => $v->relationLoaded('documents') ? $v->documents : null,
            'notes' => $v->notes,
            'active_contract_ends_at' => $canContracts ? $this->activeContractEndsAt($v) : null,
            'license_expires_on' => $this->licenseExpiresOn($v),
            'created_at' => $v->created_at?->toIso8601String(),
            'updated_at' => $v->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Builder<VehicleDocument>|HasMany<VehicleDocument, Vehicle>  $query
     * @return Builder<VehicleDocument>|HasMany<VehicleDocument, Vehicle>
     */
    private function constrainLicenseDocuments($query)
    {
        return $query->where(function ($qq) {
            $qq->where('type', 'رخصة تسيير')
                ->orWhere('type', 'like', '%رخص%')
                ->orWhere('type', 'like', '%license%');
        });
    }

    private function licenseExpiresOn(Vehicle $v): ?string
    {
        if ($v->relationLoaded('documents')) {
            $license = $v->documents->first(function ($doc) {
                $type = (string) ($doc->type ?? '');

                return $type === 'رخصة تسيير'
                    || str_contains($type, 'رخص')
                    || str_contains(mb_strtolower($type), 'license');
            });

            return $license?->expires_on?->toDateString();
        }

        $license = $v->documents()
            ->where(function ($qq) {
                $qq->where('type', 'رخصة تسيير')
                    ->orWhere('type', 'like', '%رخص%')
                    ->orWhere('type', 'like', '%license%');
            })
            ->orderByDesc('id')
            ->first();

        return $license?->expires_on?->toDateString();
    }

    private function activeContractEndsAt(Vehicle $v): ?string
    {
        if ($v->relationLoaded('contracts')) {
            $active = $v->contracts->firstWhere('status', 'active') ?? $v->contracts->first();

            return $active?->planned_ends_at?->toIso8601String();
        }

        $active = $v->contracts()->where('status', 'active')->orderBy('planned_ends_at')->first();

        return $active?->planned_ends_at?->toIso8601String();
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        $all = Vehicle::query()->where('is_active', true)->get();
        $counts = [
            'total' => $all->count(),
            'available' => 0,
            'rented' => 0,
            'reserved' => 0,
            'maintenance' => 0,
            'license_expiring' => 0,
            'sim_recharge' => 0,
            'owner_agreement_expiring' => 0,
        ];
        foreach ($all as $v) {
            $status = $this->present($v)['status'];
            if (isset($counts[$status]) && ! in_array($status, ['license_expiring', 'sim_recharge', 'owner_agreement_expiring'], true)) {
                $counts[$status]++;
            }
        }

        $licenseUntil = now()->addDays(30)->toDateString();
        $counts['license_expiring'] = Vehicle::query()
            ->where('is_active', true)
            ->whereHas('documents', function ($d) use ($licenseUntil) {
                $this->constrainLicenseDocuments($d)
                    ->whereNotNull('expires_on')
                    ->whereDate('expires_on', '<=', $licenseUntil);
            })
            ->count();

        $simUntil = now()->addDays(14)->toDateString();
        $counts['sim_recharge'] = Vehicle::query()
            ->where('is_active', true)
            ->whereNotNull('tracker_sim_next_recharge_on')
            ->whereDate('tracker_sim_next_recharge_on', '<=', $simUntil)
            ->count();

        $agreementUntil = now()->addDays(30)->toDateString();
        $counts['owner_agreement_expiring'] = Vehicle::query()
            ->where('is_active', true)
            ->whereNotNull('owner_agreement_ends_on')
            ->whereDate('owner_agreement_ends_on', '<=', $agreementUntil)
            ->count();

        return $counts;
    }
}
