<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\ViolationController;
use App\Http\Controllers\Api\WorkOrderController;
use App\Models\Notification;
use App\Models\TenantSetting;
use App\Models\WaitlistRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/dashboard', DashboardController::class);

    Route::get('/settings/tenant', function () {
        $settings = TenantSetting::query()->first();

        return response()->json(['data' => $settings]);
    });

    Route::apiResource('vehicles', VehicleController::class);
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('contracts', ContractController::class)->only(['index', 'store', 'show']);
    Route::post('/contracts/{contract}/approve', [ContractController::class, 'approve']);
    Route::post('/contracts/{contract}/handover', [ContractController::class, 'handover']);
    Route::post('/contracts/{contract}/return', [ContractController::class, 'returnVehicle']);

    Route::get('/work-orders', [WorkOrderController::class, 'index']);
    Route::post('/work-orders', [WorkOrderController::class, 'store']);
    Route::post('/work-orders/{workOrder}/close', [WorkOrderController::class, 'close']);

    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::post('/inventory/items', [InventoryController::class, 'storeItem']);
    Route::post('/inventory/batches', [InventoryController::class, 'receiveBatch']);

    Route::get('/violations', [ViolationController::class, 'index']);
    Route::post('/violations', [ViolationController::class, 'store']);
    Route::post('/violations/{violation}/confirm', [ViolationController::class, 'confirmPayment']);

    Route::get('/waitlist', function () {
        return response()->json([
            'data' => WaitlistRequest::query()->with('customer')->latest('id')->get(),
        ]);
    });

    Route::post('/waitlist', function (Request $request) {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'category' => ['required', 'string'],
            'preferred_vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'desired_start' => ['nullable', 'date'],
            'desired_end' => ['nullable', 'date'],
            'priority' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'customer_id' => 'العميل',
            'category' => 'الفئة المطلوبة',
        ]);

        $row = WaitlistRequest::query()->create([
            ...$data,
            'status' => 'waiting',
            'created_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json(['data' => $row->load('customer'), 'message' => 'تم إضافة طلب الانتظار.'], 201);
    });

    Route::get('/notifications', function (Request $request) {
        $rows = Notification::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows]);
    });

    Route::post('/notifications/{notification}/read', function (Request $request, Notification $notification) {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['read_at' => now()]);

        return response()->json(['data' => $notification]);
    });
});
