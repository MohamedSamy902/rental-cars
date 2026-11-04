<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Services\Customers\CustomerCreditService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerCreditService $credits) {}

    public function index(Request $request): JsonResponse
    {
        $q = Customer::query()->latest('id');
        if ($search = $request->string('search')->toString()) {
            $q->where(function ($qq) use ($search) {
                $qq->where('full_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('national_id', 'like', "%{$search}%");
            });
        }
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        $rows = $q->paginate((int) $request->integer('per_page', 50));
        $canViewNid = (bool) $request->user()?->hasPermission('sensitive.view_national_id');

        return response()->json([
            'data' => collect($rows->items())->map(
                fn (Customer $c) => $this->presentCustomer($c, $canViewNid)
            )->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $data = $this->resolveMediaPaths($request->validated());
        $customer = Customer::query()->create([
            ...$data,
            'status' => 'active',
        ]);

        $canViewNid = (bool) $request->user()?->hasPermission('sensitive.view_national_id');

        return response()->json([
            'data' => $this->presentCustomer($customer, $canViewNid),
            'message' => 'تم تسجيل العميل.',
        ], 201);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);
        $canViewNid = (bool) $request->user()?->hasPermission('sensitive.view_national_id');

        $customer->load([
            'contracts' => fn ($q) => $q->with('vehicle:id,brand,model,plate,code')->latest('id')->limit(50),
        ]);

        $violations = \App\Models\Violation::query()
            ->with(['vehicle:id,brand,model,plate', 'contract:id,code'])
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->limit(50)
            ->get();

        $payload = $this->presentCustomer($customer, $canViewNid);
        $payload['contracts'] = $customer->contracts->map(function ($c) {
            return [
                'id' => $c->id,
                'code' => $c->code,
                'status' => $c->status,
                'starts_at' => optional($c->starts_at)?->toDateString(),
                'planned_ends_at' => optional($c->planned_ends_at)?->toDateString(),
                'actual_ends_at' => optional($c->actual_ends_at)?->toDateString(),
                'daily_rate' => $c->daily_rate,
                'deposit' => $c->deposit,
                'vehicle' => $c->vehicle,
            ];
        })->values();
        $payload['violations'] = $violations;

        return response()->json([
            'data' => $payload,
            'credit_ledger' => $this->credits->ledger($customer),
        ]);
    }

    public function applyCredit(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'contract_id' => ['required', 'exists:contracts,id'],
            'amount' => ['required', 'integer', 'min:1'],
        ], [], [
            'contract_id' => 'العقد',
            'amount' => 'المبلغ',
        ]);

        try {
            $result = $this->credits->applyToContract(
                $customer,
                (int) $data['contract_id'],
                (int) $data['amount'],
                $request->user()?->id,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $result,
            'message' => 'تم خصم الرصيد الدائن لسداد العقد.',
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $data = $this->resolveMediaPaths($request->validated());

        if (($data['status'] ?? null) === 'blacklist') {
            $data['blacklisted_at'] = now();
            $data['blacklisted_by'] = $request->user()?->id;
        }

        $customer->update($data);

        $canViewNid = (bool) $request->user()?->hasPermission('sensitive.view_national_id');

        return response()->json([
            'data' => $this->presentCustomer($customer->fresh(), $canViewNid),
            'message' => 'تم تحديث العميل.',
        ]);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);
        $hasActive = $customer->contracts()
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->exists();

        if ($hasActive) {
            return response()->json([
                'message' => 'لا يمكن حذف العميل لوجود عقود نشطة.',
            ], 422);
        }

        $customer->delete();

        return response()->json(['message' => 'تم حذف العميل.']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveMediaPaths(array $data): array
    {
        if (! empty($data['national_id_media_id'])) {
            $data['national_id_image_path'] = '/media/'.(int) $data['national_id_media_id'].'/file';
        }
        if (! empty($data['license_media_id'])) {
            $data['license_image_path'] = '/media/'.(int) $data['license_media_id'].'/file';
        }
        unset($data['national_id_media_id'], $data['license_media_id']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCustomer(Customer $customer, bool $canViewNationalId): array
    {
        $data = $customer->toArray();
        unset($data['contracts']);
        $nid = $data['national_id'] ?? null;

        if ($nid !== null && $nid !== '' && ! $canViewNationalId) {
            $nid = (string) $nid;
            $len = mb_strlen($nid);
            $visible = min(4, $len);
            $data['national_id'] = str_repeat('*', max(0, $len - $visible)).mb_substr($nid, -$visible);
        }

        foreach (['birth_date', 'license_expires_on', 'national_id_expires_on', 'passport_expires_on'] as $dateKey) {
            if (! empty($data[$dateKey]) && is_string($data[$dateKey])) {
                $data[$dateKey] = substr($data[$dateKey], 0, 10);
            } elseif (! empty($data[$dateKey])) {
                $data[$dateKey] = optional($customer->{$dateKey})?->toDateString();
            }
        }

        return $data;
    }
}
