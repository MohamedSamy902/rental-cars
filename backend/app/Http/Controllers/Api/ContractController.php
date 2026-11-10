<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExtendContractRequest;
use App\Http\Requests\StoreContractRequest;
use App\Http\Requests\UpdateContractPricingRequest;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\DocumentTemplate;
use App\Models\Payment;
use App\Models\TenantSetting;
use App\Models\Violation;
use App\Services\Contracts\ContractDossierService;
use App\Services\Contracts\ContractLifecycleService;
use App\Services\Contracts\RentalProgressService;
use App\Services\Documents\DocumentTemplateService;
use App\Support\NestedCustomerRedactor;
use App\Support\PaginationInput;
use App\Support\VehicleOwnerVisibility;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function __construct(
        private readonly ContractLifecycleService $lifecycle,
        private readonly RentalProgressService $progress,
        private readonly DocumentTemplateService $documentTemplates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $q = Contract::query()->with(['customer', 'vehicle', 'deposit', 'salesUser:id,name'])->latest('id');
        $user = $request->user();
        if ($user && $user->role?->name === 'sales' && ! $user->hasPermission('contracts.approve')) {
            $q->where('sales_user_id', $user->id);
        }
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }
        if ($tab = $request->string('tab')->toString()) {
            if ($tab === 'upcoming') {
                $q->whereIn('status', ['pending', 'approved'])
                    ->where('starts_at', '>', now()->endOfDay());
            } elseif ($tab === 'handover_today') {
                $q->whereIn('status', ['pending', 'approved'])
                    ->whereDate('starts_at', now()->toDateString());
            }
        }
        if ($search = $request->string('search')->toString()) {
            $q->where(function ($qq) use ($search) {
                $qq->where('number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$search}%"));
            });
        }
        if ($vehicleId = $request->integer('vehicle_id')) {
            $q->where('vehicle_id', $vehicleId);
        }

        $perPage = PaginationInput::perPage($request, 50, PaginationInput::MAX_PER_PAGE);
        $page = PaginationInput::page($request);
        $rows = $q->paginate($perPage, ['*'], 'page', $page);
        $contractIds = collect($rows->items())->pluck('id')->all();
        $openViolationTotals = $contractIds === []
            ? collect()
            : Violation::query()
                ->selectRaw('contract_id, SUM(amount) as total_amount, COUNT(*) as cnt')
                ->whereIn('contract_id', $contractIds)
                ->open()
                ->groupBy('contract_id')
                ->get()
                ->keyBy('contract_id');

        $data = collect($rows->items())->map(function (Contract $c) use ($openViolationTotals, $user) {
            $arr = $c->toArray();
            if (isset($arr['customer']) && is_array($arr['customer'])) {
                $arr['customer'] = Customer::normalizeDateOnlyFields($arr['customer']);
            }
            $arr['progress'] = $this->progress->forContract($c);
            $arr['created_by'] = $c->salesUser?->name;
            $arr['sales_user'] = $c->salesUser
                ? ['id' => $c->salesUser->id, 'name' => $c->salesUser->name]
                : null;
            $agg = $openViolationTotals->get($c->id);
            $arr['open_violations_total'] = (int) ($agg->total_amount ?? 0);
            $arr['open_violations_count'] = (int) ($agg->cnt ?? 0);

            return VehicleOwnerVisibility::redactEmbedded(NestedCustomerRedactor::contract($arr, $user), $user);
        })->all();

        $today = now()->toDateString();

        return response()->json([
            'data' => $data,
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
                'upcoming' => Contract::query()
                    ->whereIn('status', ['pending', 'approved'])
                    ->where('starts_at', '>', now()->endOfDay())
                    ->count(),
                'handover_today' => Contract::query()
                    ->whereIn('status', ['pending', 'approved'])
                    ->whereDate('starts_at', $today)
                    ->count(),
            ],
        ]);
    }

    public function store(StoreContractRequest $request): JsonResponse
    {
        try {
            $allowOverride = (bool) $request->user()?->hasPermission('contracts_price.override');
            $contract = $this->lifecycle->create($request->validated(), $request->user()?->id, $allowOverride);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->presentContract($contract), 'message' => 'تم إنشاء العقد.'], 201);
    }

    public function show(Request $request, Contract $contract, ContractDossierService $dossier): JsonResponse
    {
        $this->authorize('view', $contract);

        return response()->json(['data' => $dossier->build($contract, $request->user())]);
    }

    public function approve(Request $request, Contract $contract): JsonResponse
    {
        try {
            $contract = $this->lifecycle->approve($contract, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->presentContract($contract), 'message' => 'تم اعتماد العقد وتثبيت الأسعار.']);
    }

    public function updatePricing(UpdateContractPricingRequest $request, Contract $contract): JsonResponse
    {
        try {
            $contract = $this->lifecycle->updateFrozenPricing($contract, $request->validated(), $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->presentContract($contract), 'message' => 'تم تعديل الأسعار مع تسجيل في سجل التدقيق.']);
    }

    public function handover(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'odometer_out' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'signature_data' => ['nullable', 'string'],
            'payment_amount' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['nullable', 'string', 'in:cash,card,transfer'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'balance_due_on' => ['nullable', 'date', 'after_or_equal:today'],
            'accessories' => ['nullable', 'array'],
            'accessories.*.name' => ['required_with:accessories', 'string'],
            'accessories.*.expected_qty' => ['nullable', 'integer', 'min:0'],
            'accessories.*.actual_qty' => ['nullable', 'integer', 'min:0'],
            'accessories.*.ok' => ['nullable', 'boolean'],
            'damages' => ['nullable', 'array'],
            'damages.*.position' => ['required_with:damages', 'string'],
            'damages.*.description' => ['required_with:damages', 'string'],
        ], [], [
            'odometer_out' => 'عداد الخروج',
            'payment_amount' => 'مبلغ الدفعة',
            'payment_method' => 'طريقة الدفع',
        ]);

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

        return response()->json(['data' => $this->presentContract($contract), 'message' => 'تم التسليم وتفعيل العقد.']);
    }

    public function returnVehicle(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'odometer_in' => ['required', 'integer', 'min:0'],
            'returned_at' => ['nullable', 'date'],
            'damage_fee' => ['nullable', 'integer', 'min:0'],
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
        ], [], ['odometer_in' => 'عداد العودة']);

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

        return response()->json(['data' => $this->presentContract($contract), 'message' => 'تم الاستلام وإقفال الإجمالي.']);
    }

    public function printData(Request $request, Contract $contract): JsonResponse
    {
        $contract->load(['customer', 'vehicle.documents', 'vehicle.accessories', 'deposit.deductions', 'inspections.damages']);
        $tenant = TenantSetting::query()->first();
        $ops = is_array($tenant?->operational) ? $tenant->operational : [];

        $payments = Payment::query()
            ->where('link_type', 'contract')
            ->where('link_id', $contract->id)
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => [
                'amount' => (int) $p->amount,
                'direction' => $p->direction,
                'method' => $p->method,
                'reference' => $p->reference ?? null,
                'paid_at' => $p->paid_at?->toDateString(),
                'date' => $p->paid_at?->toDateString(),
            ])
            ->values()
            ->all();

        $payload = VehicleOwnerVisibility::redactEmbedded(
            NestedCustomerRedactor::contract($contract->toArray(), $request->user()),
            $request->user(),
        );
        $payload['payments'] = $payments;
        // Ensure print consumers always see a stable document number.
        $payload['number'] = $contract->number ?: ('C-'.$contract->id);

        // Templates are optional for print: never fail the whole print payload
        // if the migration has not been applied yet or rendering hits an edge case.
        $templates = [
            'contract' => null,
            'handover_receipt' => null,
        ];
        try {
            $contractTpl = $this->documentTemplates->getActive(DocumentTemplate::TYPE_CONTRACT);
            $handoverTpl = $this->documentTemplates->getActive(DocumentTemplate::TYPE_HANDOVER_RECEIPT);
            if ($contractTpl) {
                $contractVars = NestedCustomerRedactor::printVariables(
                    $this->documentTemplates->variablesForContract($contract, $tenant, $contractTpl),
                    $request->user(),
                );
                $templates['contract'] = [
                    ...$this->documentTemplates->present($contractTpl),
                    'rendered_html' => $this->documentTemplates->render(
                        $contractTpl->content,
                        $contractTpl->format,
                        $contractVars,
                    ),
                    'variables' => $contractVars,
                ];
            }
            if ($handoverTpl) {
                $handoverVars = NestedCustomerRedactor::printVariables(
                    $this->documentTemplates->variablesForContract($contract, $tenant, $handoverTpl),
                    $request->user(),
                );
                $templates['handover_receipt'] = [
                    ...$this->documentTemplates->present($handoverTpl),
                    'rendered_html' => $this->documentTemplates->render(
                        $handoverTpl->content,
                        $handoverTpl->format,
                        $handoverVars,
                    ),
                    'variables' => $handoverVars,
                ];
            }
        } catch (\Throwable) {
            $templates = [
                'contract' => null,
                'handover_receipt' => null,
            ];
        }

        return response()->json([
            'data' => [
                'tenant' => [
                    'center_name' => $tenant?->center_name,
                    'print_footer' => $tenant?->print_footer,
                    'logo_path' => $tenant?->logo_path,
                    'print_phone' => $ops['print_phone'] ?? null,
                    'print_address' => $ops['print_address'] ?? null,
                    'print_commercial_register' => $ops['print_commercial_register'] ?? null,
                    'print_tax_info' => $ops['print_tax_info'] ?? null,
                    'print_signer_name' => $ops['print_signer_name'] ?? null,
                    'company_tagline' => $ops['company_tagline'] ?? null,
                    'default_contract_vehicle_value' => $ops['default_contract_vehicle_value'] ?? null,
                    'contract_terms' => $ops['contract_terms'] ?? null,
                ],
                'contract' => $payload,
                'templates' => $templates,
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

        return response()->json(['data' => $this->presentContract($contract), 'message' => 'تم إلغاء العقد.']);
    }

    public function extend(ExtendContractRequest $request, Contract $contract): JsonResponse
    {
        try {
            $created = $this->lifecycle->extend($contract, $request->validated(), $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->presentContract($created), 'message' => 'تم إنشاء عقد التمديد.'], 201);
    }

    public function swap(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'replacement_vehicle_id' => ['required', 'exists:vehicles,id'],
            'odometer_in' => ['required', 'integer', 'min:0'],
            'odometer_out' => ['required', 'integer', 'min:0'],
            'planned_ends_at' => ['nullable', 'date'],
            'damage_fee' => ['nullable', 'integer', 'min:0'],
            'signature_data' => ['nullable', 'string'],
            'damages' => ['nullable', 'array'],
            'damages.*.position' => ['required_with:damages', 'string', 'max:120'],
            'damages.*.description' => ['required_with:damages', 'string', 'max:500'],
            'damages.*.photo_path' => ['nullable', 'string', 'max:500'],
            'accessories' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'replacement_vehicle_id' => 'العربية البديلة',
            'odometer_in' => 'عداد العربية القديمة',
            'odometer_out' => 'عداد العربية البديلة',
            'damages' => 'الأضرار',
        ]);

        try {
            $result = $this->lifecycle->swapDueToFault($contract, $data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->presentContract($result),
            'message' => 'تم التبديل بسبب عطل على نفس العقد مع فتح أمر صيانة للعربية السابقة.',
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

    /**
     * @return array<string, mixed>
     */
    private function presentContract(mixed $contract): array
    {
        $payload = $contract instanceof Contract ? $contract->toArray() : (array) $contract;
        if (isset($payload['customer']) && is_array($payload['customer'])) {
            $payload['customer'] = Customer::normalizeDateOnlyFields($payload['customer']);
        }

        return VehicleOwnerVisibility::redactEmbedded(
            NestedCustomerRedactor::contract($payload, request()->user()),
            request()->user(),
        );
    }
}
