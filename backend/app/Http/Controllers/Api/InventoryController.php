<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->inventory->catalog()]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50', 'unique:inventory_items,code'],
            'name' => ['required', 'string'],
            'category' => ['required', 'string'],
            'unit' => ['required', 'string'],
            'reorder_level' => ['required', 'integer', 'min:0'],
            'location' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'code' => 'الكود',
            'name' => 'الاسم',
            'category' => 'التصنيف',
            'unit' => 'وحدة القياس',
            'reorder_level' => 'حد التنبيه',
        ]);

        if (array_key_exists('code', $data) && ($data['code'] === null || $data['code'] === '')) {
            unset($data['code']);
        }

        $item = $this->inventory->storeItem($data);

        return response()->json(['data' => $item, 'message' => 'تم إضافة الصنف.'], 201);
    }

    public function receiveBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inventory_item_id' => ['required', 'exists:inventory_items,id'],
            'received_on' => ['required', 'date'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['required', 'integer', 'min:0'],
            'supplier' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string'],
            'from_petty_cash' => ['nullable', 'boolean'],
            'reference' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'inventory_item_id' => 'الصنف',
            'quantity' => 'الكمية',
            'unit_cost' => 'سعر الوحدة',
        ]);

        $batch = $this->inventory->receiveBatch($data, $request->user()?->id);

        return response()->json(['data' => $batch, 'message' => 'تم إدخال دفعة المخزون.'], 201);
    }
}
