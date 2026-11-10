<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleCustodyAgreement;
use App\Services\Documents\VehicleCustodyAgreementService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleCustodyAgreementController extends Controller
{
    public function __construct(private readonly VehicleCustodyAgreementService $agreements) {}

    public function index(Vehicle $vehicle): JsonResponse
    {
        $rows = $this->agreements->listForVehicle($vehicle);

        return response()->json([
            'data' => array_map(fn (VehicleCustodyAgreement $row) => $this->agreements->present($row), $rows),
        ]);
    }

    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'number' => ['nullable', 'string', 'max:64'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'monthly_amount' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'number' => 'رقم عقد الاعتماد',
            'starts_on' => 'بداية عقد الاعتماد',
            'ends_on' => 'نهاية عقد الاعتماد',
            'monthly_amount' => 'القيمة الشهرية',
        ]);

        try {
            $row = $this->agreements->issue($vehicle, $data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->agreements->present($row),
            'message' => 'تم إصدار عقد الاعتماد. النسخة الصادرة ثابتة وغير قابلة للتعديل.',
        ], 201);
    }

    public function show(Vehicle $vehicle, VehicleCustodyAgreement $custodyAgreement): JsonResponse
    {
        try {
            $row = $this->agreements->findForVehicle($vehicle, (int) $custodyAgreement->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'data' => $this->agreements->present($row),
        ]);
    }

    public function print(Vehicle $vehicle, VehicleCustodyAgreement $custodyAgreement): JsonResponse
    {
        try {
            $row = $this->agreements->findForVehicle($vehicle, (int) $custodyAgreement->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        $presented = $this->agreements->present($row);

        return response()->json([
            'data' => [
                'id' => $presented['id'],
                'number' => $presented['number'],
                'title' => 'عقد اعتماد · '.$presented['number'],
                'html' => $presented['rendered_html'],
                'immutable' => true,
                'issued_at' => $presented['issued_at'],
                'template_version' => $presented['template_version'],
            ],
        ]);
    }
}
