<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = InventoryItem::query()->with('batches')->latest('id')->get()->map(function (InventoryItem $item) {
            return [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'category' => $item->category,
                'unit' => $item->unit,
                'reorder_level' => $item->reorder_level,
                'remaining' => $item->remainingQuantity(),
                'is_low' => $item->remainingQuantity() <= $item->reorder_level,
            ];
        });

        return response()->json(['data' => $items]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'unique:inventory_items,code'],
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

        $item = InventoryItem::query()->create($data);

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

        $batch = InventoryBatch::query()->create([
            ...$data,
            'remaining' => $data['quantity'],
            'recorded_by' => $request->user()?->id,
            'from_petty_cash' => (bool) ($data['from_petty_cash'] ?? false),
        ]);

        return response()->json(['data' => $batch, 'message' => 'تم إدخال دفعة المخزون.'], 201);
    }
}
