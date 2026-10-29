<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        return response()->json([
            'data' => $rows->items(),
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
            'phone' => ['required', 'string', 'max:30'],
            'phone_alt' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'national_id' => ['nullable', 'string', 'max:30'],
            'national_id_expires_on' => ['nullable', 'date'],
            'passport_number' => ['nullable', 'string', 'max:50'],
            'passport_expires_on' => ['nullable', 'date'],
            'birth_date' => ['required', 'date'],
            'address' => ['nullable', 'string'],
            'license_number' => ['required', 'string', 'max:50'],
            'license_expires_on' => ['required', 'date'],
            'national_id_image_path' => ['nullable', 'string', 'max:500'],
            'license_image_path' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'full_name' => 'الاسم الكامل',
            'phone' => 'الهاتف',
            'birth_date' => 'تاريخ الميلاد',
            'license_number' => 'رقم الرخصة',
            'license_expires_on' => 'انتهاء الرخصة',
        ]);

        $customer = Customer::query()->create([
            ...$data,
            'status' => 'active',
        ]);

        return response()->json(['data' => $customer, 'message' => 'تم تسجيل العميل.'], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json([
            'data' => $customer,
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

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:200'],
            'phone' => ['sometimes', 'string', 'max:30'],
            'phone_alt' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'national_id' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['sometimes', 'date'],
            'license_number' => ['sometimes', 'string', 'max:50'],
            'license_expires_on' => ['sometimes', 'date'],
            'national_id_image_path' => ['nullable', 'string', 'max:500'],
            'license_image_path' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:active,blacklist'],
            'blacklist_reason' => ['nullable', 'string'],
        ]);

        if (($data['status'] ?? null) === 'blacklist') {
            $data['blacklisted_at'] = now();
            $data['blacklisted_by'] = $request->user()?->id;
        }

        $customer->update($data);

        return response()->json(['data' => $customer->fresh(), 'message' => 'تم تحديث العميل.']);
    }
}
