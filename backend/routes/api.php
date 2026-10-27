<?php

use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StaffController;
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

    Route::get('/staff', [StaffController::class, 'index']);
    Route::post('/staff', [StaffController::class, 'store']);
    Route::put('/staff/{user}', [StaffController::class, 'update']);

    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/employees', [EmployeeController::class, 'store']);
    Route::put('/employees/{employee}', [EmployeeController::class, 'update']);

    Route::get('/roles', [RoleController::class, 'index']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::put('/roles/{role}/permissions', [RoleController::class, 'syncPermissions']);

    Route::apiResource('vehicles', VehicleController::class);
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('contracts', ContractController::class)->only(['index', 'store', 'show']);
    Route::post('/contracts/{contract}/approve', [ContractController::class, 'approve']);
    Route::post('/contracts/{contract}/handover', [ContractController::class, 'handover']);
    Route::post('/contracts/{contract}/return', [ContractController::class, 'returnVehicle']);
    Route::post('/contracts/{contract}/cancel', [ContractController::class, 'cancel']);
    Route::post('/contracts/{contract}/extend', [ContractController::class, 'extend']);
    Route::post('/contracts/{contract}/swap', [ContractController::class, 'swap']);
    Route::post('/contracts/{contract}/deposit', [ContractController::class, 'receiveDeposit']);
    Route::post('/contracts/{contract}/deposit/deduct', [ContractController::class, 'deductDeposit']);
    Route::post('/contracts/{contract}/deposit/return', [ContractController::class, 'returnDeposit']);

    Route::get('/accounting/overview', [AccountingController::class, 'overview']);
    Route::post('/accounting/lock-month', [AccountingController::class, 'lockMonth']);
    Route::post('/accounting/reopen-month', [AccountingController::class, 'reopenMonth']);
    Route::get('/accounting/payments', [AccountingController::class, 'payments']);
    Route::post('/accounting/payments', [AccountingController::class, 'storePayment']);
    Route::get('/reports/summary', [ReportController::class, 'summary']);

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
        $base = Notification::query()->where('user_id', $request->user()->id);
        $rows = (clone $base)->latest('id')->limit(50)->get();
        $unread = (clone $base)->whereNull('read_at')->count();

        return response()->json(['data' => $rows, 'unread' => $unread]);
    });

    Route::get('/notifications/unread-count', function (Request $request) {
        $unread = Notification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['unread' => $unread]);
    });

    Route::post('/notifications/{notification}/read', function (Request $request, Notification $notification) {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['read_at' => now()]);

        return response()->json(['data' => $notification]);
    });
});
