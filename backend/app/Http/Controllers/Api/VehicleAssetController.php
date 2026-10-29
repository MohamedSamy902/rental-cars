<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsumablePartTemplate;
use App\Models\Vehicle;
use App\Services\Fleet\VehicleAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleAssetController extends Controller
{
    public function __construct(private readonly VehicleAssetService $assets) {}

    public function extras(Vehicle $vehicle): JsonResponse
    {
        return response()->json(['data' => $this->assets->dossierExtras($vehicle)]);
    }

    public function storeTire(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'position' => ['required', 'in:front_right,front_left,rear_right,rear_left,spare'],
            'brand' => ['nullable', 'string', 'max:120'],
            'installed_on' => ['required', 'date'],
            'odometer_at_install' => ['required', 'integer', 'min:0'],
            'expected_life_km' => ['nullable', 'integer', 'min:1'],
            'replaced_on' => ['nullable', 'date'],
            'odometer_at_replace' => ['nullable', 'integer', 'min:0'],
            'cost' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ], [], ['position' => 'الموضع', 'installed_on' => 'تاريخ التركيب', 'odometer_at_install' => 'العداد عند التركيب']);

        return response()->json(['data' => $this->assets->addTire($vehicle, $data), 'message' => 'تم تسجيل الإطار.'], 201);
    }

    public function storeBattery(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'brand' => ['nullable', 'string', 'max:120'],
            'installed_on' => ['required', 'date'],
            'odometer_at_install' => ['required', 'integer', 'min:0'],
            'expected_life_km' => ['nullable', 'integer', 'min:1'],
            'replaced_on' => ['nullable', 'date'],
            'odometer_at_replace' => ['nullable', 'integer', 'min:0'],
            'cost' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ], [], ['installed_on' => 'تاريخ التركيب', 'odometer_at_install' => 'العداد عند التركيب']);

        return response()->json(['data' => $this->assets->addBattery($vehicle, $data), 'message' => 'تم تسجيل البطارية.'], 201);
    }

    public function storeDowntime(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'in:maintenance,out_of_service,other'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'work_order_id' => ['nullable', 'exists:work_orders,id'],
            'notes' => ['nullable', 'string'],
        ], [], ['reason' => 'السبب', 'starts_at' => 'بداية التوقف']);

        return response()->json(['data' => $this->assets->openDowntime($vehicle, $data), 'message' => 'تم تسجيل أيام التوقف.'], 201);
    }

    public function templates(): JsonResponse
    {
        return response()->json([
            'data' => ConsumablePartTemplate::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:120'],
            'default_life_km' => ['nullable', 'integer', 'min:1'],
            'default_life_days' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ], [], ['name' => 'اسم القالب']);

        return response()->json(['data' => $this->assets->upsertTemplate($data), 'message' => 'تم حفظ قالب القطعة.'], 201);
    }

    public function setConsumable(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'exists:consumable_part_templates,id'],
            'installed_on' => ['nullable', 'date'],
            'odometer_at_install' => ['nullable', 'integer', 'min:0'],
            'life_km' => ['nullable', 'integer', 'min:1'],
            'life_days' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string'],
        ], [], ['template_id' => 'القالب']);

        $template = ConsumablePartTemplate::query()->findOrFail($data['template_id']);
        $row = $this->assets->setVehicleConsumable($vehicle, $template, $data);

        return response()->json(['data' => $row->load('template'), 'message' => 'تم تحديث حالة القطعة على المركبة.']);
    }
}
