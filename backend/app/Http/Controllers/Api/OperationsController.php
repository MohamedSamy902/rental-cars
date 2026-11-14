<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\PettyCashAccount;
use App\Models\PettyCashMovement;
use App\Models\PrintSnapshot;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocumentHistory;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingService;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Services\Fleet\VehicleQrVisitorService;
use App\Services\Fleet\VehicleService;
use App\Services\Operations\AuditNarrator;
use App\Services\Operations\PettyCashLedgerService;
use App\Support\PaginationInput;
use App\Support\PublicAppUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    public function __construct(
        private readonly VehicleAvailabilityService $availability,
        private readonly PettyCashLedgerService $pettyCashLedger,
        private readonly VehicleQrVisitorService $qrVisitor,
    ) {}

    public function showroom(): JsonResponse
    {
        $settings = TenantSetting::query()->first();
        $flags = $settings?->feature_flags ?? [];
        abort_unless(($flags['showroom'] ?? true) === true, 403, 'وضع المعرض غير مفعّل.');

        $activeIds = Contract::query()->where('status', 'active')->pluck('vehicle_id')->flip();
        $reservedIds = Contract::query()
            ->where('status', 'approved')
            ->where('starts_at', '<=', now())
            ->where('planned_ends_at', '>=', now())
            ->pluck('vehicle_id')
            ->flip();

        $rows = Vehicle::query()->where('is_active', true)->orderBy('brand')->orderBy('model')->get()
            ->map(function (Vehicle $v) use ($activeIds, $reservedIds) {
                $status = $this->availability->statusOnDate([
                    'manual_status' => $v->manual_status,
                    'has_active_handover' => $activeIds->has($v->id),
                    'has_approved_covering_date' => $reservedIds->has($v->id),
                    'open_work_order_blocks' => $v->manual_status === 'maintenance'
                        || WorkOrder::query()
                            ->where('vehicle_id', $v->id)
                            ->where('puts_in_maintenance', true)
                            ->whereIn('status', ['open', 'in_progress'])
                            ->exists(),
                ]);

                $returning = null;
                if ($status !== 'available') {
                    $returning = Contract::query()
                        ->where('vehicle_id', $v->id)
                        ->whereIn('status', ['active', 'approved'])
                        ->orderBy('planned_ends_at')
                        ->value('planned_ends_at');
                }

                return [
                    'id' => $v->id,
                    'brand' => $v->brand,
                    'model' => $v->model,
                    'year' => $v->year,
                    'category' => $v->category,
                    'fuel_type' => $v->fuel_type,
                    'transmission' => $v->transmission,
                    'color' => $v->color,
                    'seats' => $v->seats,
                    'plate' => $v->plate,
                    'daily_rate' => (int) $v->daily_rate,
                    'weekly_rate' => (int) round(((int) $v->daily_rate) * 7 * 0.9),
                    'monthly_rate' => (int) round(((int) $v->daily_rate) * 30 * 0.75),
                    'default_deposit' => (int) $v->default_deposit,
                    'status' => $status,
                    'available' => $status === 'available',
                    'returning_on' => $returning?->toDateString(),
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'center_name' => $settings?->center_name,
                'vehicles' => $rows,
            ],
        ]);
    }

    public function departmentNotice(Request $request): JsonResponse
    {
        $settings = TenantSetting::query()->first();
        $flags = $settings?->feature_flags ?? [];
        abort_unless(($flags['department_notice'] ?? true) === true, 403, 'إخطار القسم غير مفعّل.');

        $filter = $request->string('filter', 'all')->toString();
        $ops = is_array($settings?->operational) ? $settings->operational : [];
        $phone = is_string($ops['print_phone'] ?? null) && trim((string) $ops['print_phone']) !== ''
            ? trim((string) $ops['print_phone'])
            : (string) config('rpm.center_public_phone', '01125253888');
        $phone2 = is_string($ops['print_phone_alt'] ?? null) && trim((string) $ops['print_phone_alt']) !== ''
            ? trim((string) $ops['print_phone_alt'])
            : '01150666901';
        $signer = is_string($ops['print_signer_name'] ?? null) && trim((string) $ops['print_signer_name']) !== ''
            ? trim((string) $ops['print_signer_name'])
            : 'أ/ محمد عبدالقادر';
        $tagline = is_string($ops['company_tagline'] ?? null) && trim((string) $ops['company_tagline']) !== ''
            ? trim((string) $ops['company_tagline'])
            : 'لتأجير وإيجار أحدث أنواع السيارات';

        $q = Contract::query()
            ->with([
                'customer:id,full_name,phone,national_id,address',
                'vehicle:id,brand,model,plate,year',
            ])
            ->whereIn('status', ['pending', 'approved', 'active', 'completed'])
            ->orderByDesc('starts_at');

        if ($filter === 'one') {
            $id = (int) $request->input('customer_id');
            $q->where('customer_id', $id);
        } elseif ($filter === 'today') {
            $q->whereDate('starts_at', now()->toDateString());
        } elseif ($filter === 'week') {
            $q->whereBetween('starts_at', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($filter === 'range') {
            $from = $request->date('from');
            $to = $request->date('to');
            if ($from && $to) {
                $q->whereBetween('starts_at', [$from, $to]);
            }
        }

        $statements = $q->limit(200)->get()->map(function (Contract $c) {
            $starts = $c->starts_at;
            $ends = $c->actual_ends_at ?? $c->planned_ends_at;
            $days = $c->approved_days ? (int) $c->approved_days : null;
            if ($days === null && $starts && $ends && $ends->gt($starts)) {
                $days = max(1, (int) ceil($starts->diffInSeconds($ends) / 86400));
            }

            $vehicleLabel = trim(implode(' ', array_filter([
                $c->vehicle?->brand,
                $c->vehicle?->model,
                $c->vehicle?->year ? (string) $c->vehicle->year : null,
            ])));

            return [
                'contract_id' => $c->id,
                'contract_number' => $c->number,
                'renter_name' => $c->customer?->full_name,
                'national_id' => $c->customer?->national_id,
                'vehicle_type' => $vehicleLabel !== '' ? $vehicleLabel : null,
                'plate' => $c->vehicle?->plate,
                'rental_duration_days' => $days,
                'starts_on' => $starts?->toDateString(),
                'ends_on' => $ends?->toDateString(),
                'address' => $c->customer?->address,
                'notes' => $c->notes,
                'status' => $c->status,
            ];
        })->values();

        return response()->json([
            'data' => [
                'center_name' => $settings?->center_name ?: 'مركز آر بي إم للتأجير',
                'signer_name' => $signer,
                'tagline' => $tagline,
                'logo_path' => $settings?->logo_path,
                'phones' => array_values(array_filter([$phone, $phone2])),
                'generated_at' => now()->toIso8601String(),
                'filter' => $filter,
                'statements' => $statements,
                // توافق خلفي للواجهات القديمة
                'customers' => [],
            ],
        ]);
    }

    public function updateOperational(Request $request): JsonResponse
    {
        $data = $request->validate([
            'center_name' => ['sometimes', 'string', 'max:200'],
            'print_footer' => ['nullable', 'string', 'max:2000'],
            'logo_path' => ['nullable', 'string', 'max:500'],
            'print_phone' => ['nullable', 'string', 'max:50'],
            'print_address' => ['nullable', 'string', 'max:500'],
            'print_commercial_register' => ['nullable', 'string', 'max:120'],
            'print_tax_info' => ['nullable', 'string', 'max:200'],
            'print_signer_name' => ['nullable', 'string', 'max:200'],
            'company_tagline' => ['nullable', 'string', 'max:200'],
            'contract_terms' => ['nullable', 'string', 'max:20000'],
            'operational' => ['nullable', 'array'],
        ]);

        $settings = TenantSetting::query()->firstOrCreate([]);

        $ops = is_array($settings->operational) ? $settings->operational : [];
        if (array_key_exists('operational', $data) && is_array($data['operational'])) {
            $ops = array_merge($ops, $data['operational']);
        }
        foreach ([
            'print_phone',
            'print_address',
            'print_commercial_register',
            'print_tax_info',
            'print_signer_name',
            'company_tagline',
            'contract_terms',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $ops[$key] = $data[$key];
                unset($data[$key]);
            }
        }
        unset($ops['default_contract_vehicle_value']);
        $data['operational'] = $ops;

        $settings->fill($data)->save();

        return response()->json(['data' => $settings->fresh(), 'message' => 'تم حفظ إعدادات التشغيل.']);
    }

    public function updateIntegrator(Request $request): JsonResponse
    {
        $data = $request->validate([
            'center_name' => ['sometimes', 'string', 'max:200'],
            'print_footer' => ['nullable', 'string', 'max:300'],
            'multi_branch' => ['sometimes', 'boolean'],
            'inventory_mode' => ['sometimes', 'in:unified,per_branch'],
            'colors' => ['nullable', 'array'],
            'feature_flags' => ['nullable', 'array'],
            'logo_path' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = TenantSetting::query()->firstOrCreate([]);
        $settings->fill($data)->save();

        return response()->json(['data' => $settings->fresh(), 'message' => 'تم حفظ إعدادات المُدمج.']);
    }

    /** @deprecated استخدم updateOperational أو updateIntegrator */
    public function updateTenant(Request $request): JsonResponse
    {
        if ($request->user()?->role?->name === 'super_admin' && (
            $request->exists('feature_flags') || $request->exists('colors') || $request->exists('multi_branch')
        )) {
            return $this->updateIntegrator($request);
        }

        return $this->updateOperational($request);
    }

    public function estimatedOdometer(Request $request, Vehicle $vehicle): JsonResponse
    {
        $active = $vehicle->contracts()->where('status', 'active')->latest('id')->first();
        $canContracts = (bool) $request->user()?->hasPermission('contracts.view');
        if (! $canContracts || ! $active || ! $active->odometer_out || ! $active->starts_at) {
            return response()->json([
                'data' => [
                    'vehicle_id' => $vehicle->id,
                    'estimated' => (int) $vehicle->odometer,
                    'basis' => 'recorded',
                    'note' => $canContracts
                        ? 'لا عقد نشط — يُعرض العداد المسجّل.'
                        : 'العداد المسجّل.',
                ],
            ]);
        }

        $days = max(1, $active->starts_at->diffInDays(now()) + 1);
        $dailyKm = (int) ($active->frozen_daily_km_allowance ?: 200);
        $estimated = (int) $active->odometer_out + ($days * $dailyKm);

        return response()->json([
            'data' => [
                'vehicle_id' => $vehicle->id,
                'contract_id' => $active->id,
                'odometer_out' => (int) $active->odometer_out,
                'days_elapsed' => $days,
                'daily_km_allowance' => $dailyKm,
                'estimated' => $estimated,
                'basis' => 'active_contract_projection',
                'note' => 'تقدير أثناء التأجير للتنبيه فقط — المرجع الفعلي عند الاستلام.',
            ],
        ]);
    }

    public function auditLogs(Request $request, AuditNarrator $narrator): JsonResponse
    {
        if ($request->exists('per_page') || $request->exists('page')) {
            PaginationInput::perPage($request, 50, PaginationInput::MAX_PER_PAGE);
            PaginationInput::page($request);
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:80'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);

        $query = AuditLog::query()->with([
            'user:id,name,role_id,employee_id',
            'user.role:id,name,label',
            'user.employee:id,job_title',
        ]);

        if (! empty($validated['user_id'])) {
            $query->where('user_id', (int) $validated['user_id']);
        }
        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }
        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $rows = $query->latest('id')->limit($limit)->get();

        $data = $rows->map(function (AuditLog $log) use ($narrator) {
            $user = $log->user;
            $userName = $user?->name;
            $roleTitle = $user ? $narrator->resolveRoleTitle($user) : null;

            return [
                'id' => $log->id,
                'action' => $log->action,
                'summary' => $narrator->summarizeForUser($log, $user),
                'record_type' => $log->record_type,
                'record_id' => $log->record_id,
                'user_id' => $log->user_id,
                'user_name' => $userName,
                'user_role_title' => $roleTitle,
                'user_phrase' => $narrator->actorPhrase($userName, $roleTitle),
                'before' => $log->before,
                'after' => $log->after,
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        })->values();

        $labels = $narrator->actionLabels();
        $seenActions = AuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        $actions = $seenActions
            ->map(fn (string $key) => [
                'value' => $key,
                'label' => $labels[$key] ?? $key,
            ])
            ->values()
            ->all();

        // Prefer known catalog order, then any unseen keys that appeared in the DB.
        $catalog = collect($labels)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values();
        $extra = collect($actions)->reject(
            fn (array $row) => $catalog->contains(fn (array $c) => $c['value'] === $row['value'])
        );
        $actionOptions = $catalog->concat($extra)->values()->all();

        $users = User::query()
            ->with(['role:id,name,label', 'employee:id,job_title'])
            ->where(function ($q) {
                $q->where('is_active', true)
                    ->orWhereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->select('user_id'));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'role_id', 'employee_id'])
            ->map(function (User $u) use ($narrator) {
                $title = $narrator->resolveRoleTitle($u);

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'role_title' => $title,
                    'label' => $title ? "{$title} — {$u->name}" : $u->name,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'users' => $users,
                'actions' => $actionOptions,
                'filters' => [
                    'user_id' => $validated['user_id'] ?? null,
                    'action' => $validated['action'] ?? null,
                    'date_from' => $validated['date_from'] ?? null,
                    'date_to' => $validated['date_to'] ?? null,
                    'limit' => $limit,
                ],
            ],
        ]);
    }

    public function pettyCashIndex(Request $request): JsonResponse
    {
        $perPage = PaginationInput::perPage($request, 50, PaginationInput::MAX_PER_PAGE);
        $page = PaginationInput::page($request);
        $rows = PettyCashAccount::query()->with('employee')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function pettyCashShow(Request $request, PettyCashAccount $account): JsonResponse
    {
        return response()->json([
            'data' => $this->pettyCashLedger->dossier(
                $account,
                (bool) $request->user()?->hasPermission('contracts.view'),
            ),
        ]);
    }

    public function pettyCashStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'limit_amount' => ['nullable', 'integer', 'min:0'],
            'balance' => ['nullable', 'integer', 'min:0'],
            'topup_amount' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'employee_id' => 'الموظف',
            'limit_amount' => 'حد العهدة',
            'balance' => 'المبلغ',
            'topup_amount' => 'مبلغ التغذية',
        ]);

        $row = DB::transaction(function () use ($data, $request) {
            $existing = PettyCashAccount::query()
                ->where('employee_id', $data['employee_id'])
                ->lockForUpdate()
                ->first();

            $topup = (int) ($data['topup_amount'] ?? $data['balance'] ?? 0);
            if ($topup < 0) {
                $topup = 0;
            }

            if ($existing) {
                // Cumulative top-up — never overwrite the live balance.
                $newBalance = (int) $existing->balance + $topup;
                $existing->update([
                    'limit_amount' => array_key_exists('limit_amount', $data)
                        ? (int) ($data['limit_amount'] ?? 0)
                        : (int) $existing->limit_amount,
                    'balance' => $newBalance,
                    'is_active' => true,
                ]);

                if ($topup > 0) {
                    $movement = PettyCashMovement::query()->create([
                        'petty_cash_account_id' => $existing->id,
                        'type' => 'topup',
                        'amount' => $topup,
                        'moved_on' => now()->toDateString(),
                        'performed_by' => $request->user()?->id,
                        'notes' => 'تغذية عهدة',
                    ]);
                    app(AccountingService::class)->postCustodyMovement(
                        'petty_topup:'.$movement->id,
                        'in',
                        $topup,
                        $movement->moved_on->toDateString(),
                        $request->user()?->id,
                    );
                }

                return $existing->fresh('employee');
            }

            $created = PettyCashAccount::query()->create([
                'employee_id' => $data['employee_id'],
                'limit_amount' => (int) ($data['limit_amount'] ?? 0),
                'balance' => $topup,
                'is_active' => true,
            ]);

            if ($topup > 0) {
                $movement = PettyCashMovement::query()->create([
                    'petty_cash_account_id' => $created->id,
                    'type' => 'topup',
                    'amount' => $topup,
                    'moved_on' => now()->toDateString(),
                    'performed_by' => $request->user()?->id,
                    'notes' => 'تغذية عهدة',
                ]);
                app(AccountingService::class)->postCustodyMovement(
                    'petty_topup:'.$movement->id,
                    'in',
                    $topup,
                    $movement->moved_on->toDateString(),
                    $request->user()?->id,
                );
            }

            return $created->load('employee');
        });

        return response()->json(['data' => $row, 'message' => 'تم حفظ العهدة.'], 201);
    }

    public function pettyCashMove(Request $request, PettyCashAccount $account): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:topup,spend,adjust,return'],
            'amount' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ], [], ['type' => 'نوع الحركة', 'amount' => 'المبلغ']);

        $row = DB::transaction(function () use ($account, $data, $request) {
            $amount = (int) $data['amount'];
            $priorBalance = (int) $account->balance;
            $type = (string) $data['type'];
            $accounting = app(AccountingService::class);

            // صرف العهدة يمر عبر مصروف/صيانة فقط حتى لا تتحرك العهدة بلا قيد.
            if ($type === 'spend') {
                abort(422, 'صرف العهدة يتم عبر تسجيل مصروف أو أمر صيانة من العهدة — وليس من حركة مباشرة.');
            }

            $newBalance = $priorBalance;
            if ($type === 'topup') {
                $limit = (int) $account->limit_amount;
                if ($limit > 0 && $priorBalance + $amount > $limit) {
                    abort(422, 'التغذية تتجاوز حد العهدة ('.$limit.')');
                }
                $newBalance = $priorBalance + $amount;
            } elseif ($type === 'return') {
                if ($amount > $priorBalance) {
                    abort(422, 'رصيد العهدة غير كافٍ.');
                }
                $newBalance = $priorBalance - $amount;
            } else {
                // adjust: amount is the absolute target balance.
                $newBalance = $amount;
            }

            $account->update(['balance' => $newBalance]);

            $movement = PettyCashMovement::query()->create([
                'petty_cash_account_id' => $account->id,
                'type' => $type,
                'amount' => $amount,
                'moved_on' => now()->toDateString(),
                'performed_by' => $request->user()?->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $movedOn = $movement->moved_on->toDateString();
            $userId = $request->user()?->id;

            if ($type === 'topup') {
                $accounting->postCustodyMovement('petty_topup:'.$movement->id, 'in', $amount, $movedOn, $userId);
            } elseif ($type === 'return') {
                $accounting->postCustodyMovement('petty_return:'.$movement->id, 'out', $amount, $movedOn, $userId);
            } elseif ($type === 'adjust') {
                $delta = $newBalance - $priorBalance;
                if ($delta > 0) {
                    $accounting->postCustodyMovement('petty_adjust_in:'.$movement->id, 'in', $delta, $movedOn, $userId);
                } elseif ($delta < 0) {
                    $accounting->postCustodyMovement('petty_adjust_out:'.$movement->id, 'out', abs($delta), $movedOn, $userId);
                }
            }

            return $movement;
        });

        return response()->json(['data' => ['movement' => $row, 'account' => $account->fresh('employee')], 'message' => 'تم تسجيل حركة العهدة.']);
    }

    public function storeVehicleDocument(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:100'],
            'document_number' => ['nullable', 'string', 'max:80'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'file_path_back' => ['nullable', 'string', 'max:500'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string'],
        ], [
            'expires_on.after_or_equal' => 'تاريخ الانتهاء لا يمكن أن يسبق تاريخ الإصدار.',
        ], [
            'type' => 'نوع المستند',
            'document_number' => 'رقم المستند',
            'file_path' => 'وجه المستند',
            'file_path_back' => 'ظهر المستند',
            'issued_on' => 'تاريخ الإصدار',
            'expires_on' => 'تاريخ الانتهاء',
        ]);

        $doc = $vehicle->documents()->create([
            'type' => $data['type'],
            'document_number' => $data['document_number'] ?? null,
            'file_path' => $data['file_path'] ?? 'manual/'.$data['type'],
            'file_path_back' => $data['file_path_back'] ?? null,
            'issued_on' => $data['issued_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['data' => $doc, 'message' => 'تم تسجيل المستند.'], 201);
    }

    public function storeVehicleAccessory(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'default_qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'required_each_rental' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [], ['name' => 'اسم المستلزم', 'default_qty' => 'الكمية']);

        $row = $vehicle->accessories()->create([
            'name' => $data['name'],
            'default_qty' => $data['default_qty'] ?? 1,
            'required_each_rental' => $data['required_each_rental'] ?? true,
            'is_active' => $data['is_active'] ?? true,
            'notes' => $data['notes'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return response()->json(['data' => $row, 'message' => 'تم إضافة المستلزم.'], 201);
    }

    public function updateVehicleAccessory(Request $request, Vehicle $vehicle, int $accessory): JsonResponse
    {
        $row = $vehicle->accessories()->whereKey($accessory)->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'default_qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'required_each_rental' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [], ['name' => 'اسم المستلزم', 'default_qty' => 'الكمية']);

        $row->update($data);

        return response()->json(['data' => $row->fresh(), 'message' => 'تم تحديث المستلزم.']);
    }

    public function destroyVehicleAccessory(Vehicle $vehicle, int $accessory): JsonResponse
    {
        $row = $vehicle->accessories()->whereKey($accessory)->firstOrFail();
        $row->delete();

        return response()->json(['message' => 'تم حذف المستلزم.']);
    }

    public function updateVehicleDocument(Request $request, Vehicle $vehicle, int $document): JsonResponse
    {
        $row = $vehicle->documents()->whereKey($document)->firstOrFail();
        $data = $request->validate([
            'type' => ['sometimes', 'string', 'max:100'],
            'document_number' => ['nullable', 'string', 'max:80'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'file_path_back' => ['nullable', 'string', 'max:500'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'archive_previous' => ['nullable', 'boolean'],
        ], [], [
            'type' => 'نوع المستند',
            'document_number' => 'رقم المستند',
            'file_path' => 'وجه المستند',
            'file_path_back' => 'ظهر المستند',
        ]);

        $type = strtolower((string) ($data['type'] ?? $row->type));
        $isLicense = str_contains($type, 'license') || str_contains($type, 'رخص');
        $shouldArchive = $isLicense || ! empty($data['archive_previous']);

        if ($shouldArchive) {
            unset($data['archive_previous']);
            $row = app(VehicleService::class)
                ->renewLicense($vehicle, $row, $data, $request->user()?->id);

            return response()->json([
                'data' => $row,
                'message' => 'تم تحديث الرخصة مع حفظ السجل السابق.',
            ]);
        }

        unset($data['archive_previous']);
        $row->update($data);

        return response()->json(['data' => $row->fresh(), 'message' => 'تم تحديث المستند.']);
    }

    public function vehicleDocumentHistory(Vehicle $vehicle): JsonResponse
    {
        $rows = VehicleDocumentHistory::query()
            ->where('vehicle_id', $vehicle->id)
            ->latest('archived_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function destroyVehicleDocument(Vehicle $vehicle, int $document): JsonResponse
    {
        $row = $vehicle->documents()->whereKey($document)->firstOrFail();
        $row->delete();

        return response()->json(['message' => 'تم حذف المستند.']);
    }

    public function qrPayload(Request $request): JsonResponse
    {
        $type = $request->string('type', 'vehicle')->toString();
        $id = (int) $request->input('id');

        if ($type === 'vehicle') {
            if (! $request->user()?->hasPermission('vehicles.view')) {
                return response()->json(['message' => 'ليست لديك صلاحية لتنفيذ هذا الإجراء.'], 403);
            }
            $v = Vehicle::query()->findOrFail($id);

            return response()->json([
                'data' => [
                    'qr' => $this->qrVisitor->payloadUrl($v),
                    'label' => $v->brand.' '.$v->model.' · '.$v->plate,
                ],
            ]);
        }

        if (! $request->user()?->hasPermission('customers.view')) {
            return response()->json(['message' => 'ليست لديك صلاحية لتنفيذ هذا الإجراء.'], 403);
        }

        $c = Customer::query()->findOrFail($id);

        return response()->json([
            'data' => [
                'qr' => PublicAppUrl::to('/department-notice?customer_id='.$c->id),
                'label' => $c->full_name,
            ],
        ]);
    }

    /** بطاقة الرمز السريع للزائر — عامة بلا مصادقة (RSD ٦). */
    public function vehicleQrVisitorCard(Vehicle $vehicle): JsonResponse
    {
        abort_unless($vehicle->is_active, 404);

        return response()->json([
            'data' => $this->qrVisitor->card($vehicle),
        ]);
    }

    public function employeesLookup(): JsonResponse
    {
        return response()->json([
            'data' => Employee::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'job_title']),
        ]);
    }

    public function storePrintSnapshot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:contract,invoice,receipt,handover,department_notice'],
            'record_type' => ['required', 'string', 'max:120'],
            'record_id' => ['required', 'integer', 'min:1'],
            'payload' => ['required', 'array'],
        ], [], ['type' => 'نوع المستند']);

        $row = PrintSnapshot::query()->create([
            ...$data,
            'printed_by' => $request->user()?->id,
            'printed_at' => now(),
        ]);

        return response()->json(['data' => $row, 'message' => 'تم حفظ نسخة الطباعة.'], 201);
    }
}
