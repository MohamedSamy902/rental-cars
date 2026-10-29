<?php

use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\AttentionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CollaborationController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeAssignmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SalaryAdvanceController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\VehicleAssetController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\VehicleOwnerController;
use App\Http\Controllers\Api\ViolationController;
use App\Http\Controllers\Api\WorkOrderController;
use App\Models\Notification;
use App\Models\TenantSetting;
use App\Services\Operations\WaitlistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/dashboard', DashboardController::class)->middleware('permission:reports.view|contracts.view|vehicles.view');
    Route::get('/attention', AttentionController::class);

    Route::get('/media/limits', [MediaController::class, 'limits'])->middleware('permission:media.view|media.upload|customers.view|vehicles.view|contracts.view');
    Route::post('/media', [MediaController::class, 'store'])->middleware('permission:media.upload|customers.create|customers.update|vehicles.update|contracts.update|violations.create|maintenance.create');
    Route::get('/media/{medium}', [MediaController::class, 'show'])->middleware('permission:media.view|customers.view|vehicles.view|contracts.view');
    Route::get('/media/{medium}/file', [MediaController::class, 'file'])->middleware('permission:media.view|customers.view|vehicles.view|contracts.view');
    Route::delete('/media/{medium}', [MediaController::class, 'destroy'])->middleware('permission:media.delete|customers.update|vehicles.update|settings.update');

    Route::get('/settings/tenant', function () {
        return response()->json(['data' => TenantSetting::query()->first()]);
    })->middleware('permission:settings.view');

    Route::put('/settings/operational', [OperationsController::class, 'updateOperational'])
        ->middleware('permission:settings.update');
    Route::put('/settings/tenant', [OperationsController::class, 'updateOperational'])
        ->middleware('permission:settings.update');
    Route::put('/integrator/tenant', [OperationsController::class, 'updateIntegrator'])
        ->middleware('integrator');

    Route::middleware('permission:staff.view')->group(function () {
        Route::get('/staff', [StaffController::class, 'index']);
        Route::get('/roles', [RoleController::class, 'index']);
    });
    Route::middleware('permission:staff.manage')->group(function () {
        Route::post('/staff', [StaffController::class, 'store']);
        Route::put('/staff/{user}', [StaffController::class, 'update']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
    });

    Route::get('/employees/lookup', [OperationsController::class, 'employeesLookup'])
        ->middleware('permission:hr.view,hr.salaries,hr.custody,hr.advances,petty_cash.view,petty_cash.manage,maintenance.view');
    Route::middleware('permission:hr.view,hr.salaries,hr.custody,hr.advances,hr.expenses')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index']);
    });
    Route::middleware('permission:hr.salaries,hr.view')->group(function () {
        Route::get('/payroll', [PayrollController::class, 'index']);
    });
    Route::middleware('permission:hr.create,hr.salaries')->group(function () {
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::post('/payroll/draft', [PayrollController::class, 'draft']);
    });
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])
        ->middleware('permission:hr.update,hr.salaries');
    Route::post('/payroll/{payrollRun}/approve', [PayrollController::class, 'approve'])
        ->middleware('permission:hr.payroll_approve,hr.salaries');
    Route::post('/payroll/lines/{payrollLine}/payout', [PayrollController::class, 'payout'])
        ->middleware('permission:hr.salaries,hr.payroll_approve');

    Route::middleware('permission:hr.custody,hr.view')->group(function () {
        Route::get('/employee-assignments', [EmployeeAssignmentController::class, 'index']);
    });
    Route::middleware('permission:hr.custody,hr.update')->group(function () {
        Route::post('/employee-assignments', [EmployeeAssignmentController::class, 'store']);
        Route::post('/employee-assignments/{assignment}/acknowledge', [EmployeeAssignmentController::class, 'acknowledgeReceipt']);
    });

    Route::middleware('permission:hr.advances,hr.salaries,hr.view')->group(function () {
        Route::get('/salary-advances', [SalaryAdvanceController::class, 'index']);
    });
    Route::middleware('permission:hr.advances')->group(function () {
        Route::post('/salary-advances', [SalaryAdvanceController::class, 'store']);
        Route::delete('/salary-advances/{salaryAdvance}', [SalaryAdvanceController::class, 'destroy']);
    });

    Route::get('/vehicles', [VehicleController::class, 'index'])->middleware('permission:vehicles.view');
    Route::get('/vehicles/recommend', [VehicleController::class, 'recommend'])->middleware('permission:vehicles.view,contracts.create');
    Route::post('/vehicles', [VehicleController::class, 'store'])->middleware('permission:vehicles.create');
    Route::get('/vehicles/{vehicle}/dossier', [VehicleController::class, 'dossier'])->middleware('permission:vehicles.view');
    Route::get('/vehicles/{vehicle}/assets', [VehicleAssetController::class, 'extras'])->middleware('permission:vehicles.view');
    Route::post('/vehicles/{vehicle}/tires', [VehicleAssetController::class, 'storeTire'])->middleware('permission:vehicles.update');
    Route::post('/vehicles/{vehicle}/batteries', [VehicleAssetController::class, 'storeBattery'])->middleware('permission:vehicles.update');
    Route::post('/vehicles/{vehicle}/downtime', [VehicleAssetController::class, 'storeDowntime'])->middleware('permission:vehicles.update,maintenance.create');
    Route::post('/vehicles/{vehicle}/consumables', [VehicleAssetController::class, 'setConsumable'])->middleware('permission:vehicles.update');
    Route::get('/consumable-templates', [VehicleAssetController::class, 'templates'])->middleware('permission:vehicles.view,maintenance.view');
    Route::post('/consumable-templates', [VehicleAssetController::class, 'storeTemplate'])->middleware('permission:vehicles.update,maintenance.create');
    Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])->middleware('permission:vehicles.view');
    Route::put('/vehicles/{vehicle}', [VehicleController::class, 'update'])->middleware('permission:vehicles.update');
    Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->middleware('permission:vehicles.archive');
    Route::post('/vehicles/{vehicle}/documents', [OperationsController::class, 'storeVehicleDocument'])
        ->middleware('permission:vehicles.update');
    Route::put('/vehicles/{vehicle}/documents/{document}', [OperationsController::class, 'updateVehicleDocument'])
        ->middleware('permission:vehicles.update');
    Route::delete('/vehicles/{vehicle}/documents/{document}', [OperationsController::class, 'destroyVehicleDocument'])
        ->middleware('permission:vehicles.update');
    Route::post('/vehicles/{vehicle}/accessories', [OperationsController::class, 'storeVehicleAccessory'])
        ->middleware('permission:vehicles.update');
    Route::put('/vehicles/{vehicle}/accessories/{accessory}', [OperationsController::class, 'updateVehicleAccessory'])
        ->middleware('permission:vehicles.update');
    Route::delete('/vehicles/{vehicle}/accessories/{accessory}', [OperationsController::class, 'destroyVehicleAccessory'])
        ->middleware('permission:vehicles.update');
    Route::get('/vehicles/{vehicle}/estimated-odometer', [OperationsController::class, 'estimatedOdometer'])
        ->middleware('permission:vehicles.view');

    Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:customers.create');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
    Route::post('/customers/{customer}/apply-credit', [CustomerController::class, 'applyCredit'])
        ->middleware('permission:payments.create,contracts.update');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])
        ->middleware('permission:customers.update,blacklist.manage');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.update');

    Route::get('/contracts', [ContractController::class, 'index'])->middleware('permission:contracts.view');
    Route::post('/contracts', [ContractController::class, 'store'])->middleware('permission:contracts.create');
    Route::get('/contracts/{contract}', [ContractController::class, 'show'])->middleware('permission:contracts.view');
    Route::post('/contracts/{contract}/approve', [ContractController::class, 'approve'])->middleware('permission:contracts.approve');
    Route::post('/contracts/{contract}/pricing', [ContractController::class, 'updatePricing'])->middleware('permission:contracts_price.override');
    Route::post('/contracts/{contract}/handover', [ContractController::class, 'handover'])->middleware('permission:contracts.update');
    Route::post('/contracts/{contract}/return', [ContractController::class, 'returnVehicle'])->middleware('permission:contracts.update');
    Route::post('/contracts/{contract}/cancel', [ContractController::class, 'cancel'])->middleware('permission:contracts_cancel.approve');
    Route::post('/contracts/{contract}/extend', [ContractController::class, 'extend'])->middleware('permission:contracts_extend.execute');
    Route::post('/contracts/{contract}/swap', [ContractController::class, 'swap'])->middleware('permission:contracts_swap.execute');
    Route::post('/contracts/{contract}/deposit', [ContractController::class, 'receiveDeposit'])->middleware('permission:deposits.collect');
    Route::post('/contracts/{contract}/deposit/deduct', [ContractController::class, 'deductDeposit'])->middleware('permission:deposits.settle');
    Route::post('/contracts/{contract}/deposit/return', [ContractController::class, 'returnDeposit'])->middleware('permission:deposits.settle');
    Route::get('/contracts/{contract}/print', [ContractController::class, 'printData'])->middleware('permission:contracts.print');

    Route::get('/showroom', [OperationsController::class, 'showroom'])
        ->middleware(['feature:showroom', 'permission:showroom.run']);
    Route::get('/department-notice', [OperationsController::class, 'departmentNotice'])
        ->middleware(['feature:department_notice', 'permission:department_notice.print']);
    Route::get('/audit-logs', [OperationsController::class, 'auditLogs'])->middleware('permission:audit.view');
    Route::get('/qr', [OperationsController::class, 'qrPayload'])->middleware('permission:vehicles.view,customers.view');
    Route::post('/print-snapshots', [OperationsController::class, 'storePrintSnapshot'])
        ->middleware('permission:contracts.print,department_notice.print');

    Route::middleware(['feature:petty_cash', 'permission:petty_cash.view,hr.custody'])->group(function () {
        Route::get('/petty-cash', [OperationsController::class, 'pettyCashIndex']);
    });
    Route::middleware(['feature:petty_cash', 'permission:petty_cash.manage,hr.custody'])->group(function () {
        Route::post('/petty-cash', [OperationsController::class, 'pettyCashStore']);
        Route::post('/petty-cash/{account}/move', [OperationsController::class, 'pettyCashMove']);
    });

    Route::middleware(['feature:inventory', 'permission:inventory.view'])->get('/inventory', [InventoryController::class, 'index']);
    Route::middleware(['feature:inventory', 'permission:inventory.create'])->group(function () {
        Route::post('/inventory/items', [InventoryController::class, 'storeItem']);
        Route::post('/inventory/batches', [InventoryController::class, 'receiveBatch']);
    });

    Route::middleware('permission:expenses.view,hr.expenses')->get('/expenses', [ExpenseController::class, 'index']);
    Route::middleware('permission:expenses.create,hr.expenses')->post('/expenses', [ExpenseController::class, 'store']);

    Route::middleware('permission:owners.view')->group(function () {
        Route::get('/owners', [VehicleOwnerController::class, 'index']);
        Route::get('/owners/{owner}', [VehicleOwnerController::class, 'show']);
        Route::get('/owners/{owner}/balance', [VehicleOwnerController::class, 'balance']);
        Route::get('/owner-payouts', [VehicleOwnerController::class, 'payoutsIndex']);
    });
    Route::middleware('permission:owners.create')->post('/owners', [VehicleOwnerController::class, 'store']);
    Route::middleware('permission:owners.update')->put('/owners/{owner}', [VehicleOwnerController::class, 'update']);
    Route::middleware('permission:owners.payout')->post('/owners/{owner}/payouts', [VehicleOwnerController::class, 'payout']);

    Route::get('/accounting/overview', [AccountingController::class, 'overview'])->middleware('permission:accounting.view');
    Route::get('/accounting/months/{year}/{month}', [AccountingController::class, 'month'])
        ->whereNumber(['year', 'month'])
        ->middleware('permission:accounting.view');
    Route::post('/accounting/lock-month', [AccountingController::class, 'lockMonth'])->middleware('permission:accounting.lock_month');
    Route::post('/accounting/reopen-month', [AccountingController::class, 'reopenMonth'])->middleware('permission:accounting.reopen_month');
    Route::get('/accounting/payments', [AccountingController::class, 'payments'])->middleware('permission:payments.view');
    Route::post('/accounting/payments', [AccountingController::class, 'storePayment'])->middleware('permission:payments.create');
    Route::middleware('permission:reports.view,accounting.view')->group(function () {
        Route::get('/reports/summary', [ReportController::class, 'summary']);
        Route::get('/reports/profitability-compare', [ReportController::class, 'profitabilityCompare']);
        Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance']);
        Route::get('/reports/income-statement', [ReportController::class, 'incomeStatement']);
        Route::get('/reports/payment-methods', [ReportController::class, 'paymentMethodBalances']);
        Route::get('/reports/petty-cash-balances', [ReportController::class, 'pettyCashBalances']);
        Route::get('/reports/customers/{customer}/statement', [ReportController::class, 'customerStatement']);
    });

    Route::get('/work-orders', [WorkOrderController::class, 'index'])->middleware('permission:maintenance.view');
    Route::post('/work-orders', [WorkOrderController::class, 'store'])->middleware('permission:maintenance.create');
    Route::post('/work-orders/{workOrder}/close', [WorkOrderController::class, 'close'])->middleware('permission:maintenance.close');

    Route::get('/violations', [ViolationController::class, 'index'])->middleware('permission:violations.view');
    Route::post('/violations', [ViolationController::class, 'store'])->middleware('permission:violations.create');
    Route::post('/violations/{violation}/confirm', [ViolationController::class, 'confirmPayment'])->middleware('permission:violations.update');

    Route::middleware(['feature:waitlist', 'permission:waitlist.view'])->get('/waitlist', function (WaitlistService $service) {
        return response()->json(['data' => $service->list()]);
    });
    Route::middleware(['feature:waitlist', 'permission:waitlist.create'])->post('/waitlist', function (Request $request, WaitlistService $service) {
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

        $row = $service->create($data, (int) $request->user()->id);

        return response()->json(['data' => $row, 'message' => 'تم إضافة طلب الانتظار.'], 201);
    });

    Route::middleware(['feature:chat'])->group(function () {
        Route::get('/chat', [CollaborationController::class, 'chatIndex'])->middleware('permission:chat.view');
        Route::post('/chat', [CollaborationController::class, 'chatStore'])->middleware('permission:chat.create');
    });
    Route::middleware(['feature:evaluations', 'permission:hr.view'])->get('/evaluations', [CollaborationController::class, 'evaluationsIndex']);
    Route::middleware(['feature:evaluations', 'permission:hr.create'])->post('/evaluations', [CollaborationController::class, 'evaluationsStore']);

    Route::post('/ocr/review', [CollaborationController::class, 'ocrReview'])
        ->middleware('permission:customers.create,customers.update');

    Route::middleware('permission:notifications.view')->group(function () {
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

        Route::get('/push/vapid-public-key', [PushSubscriptionController::class, 'vapidPublicKey']);
        Route::get('/push/status', [PushSubscriptionController::class, 'status']);
        Route::post('/push/subscribe', [PushSubscriptionController::class, 'subscribe']);
        Route::post('/push/unsubscribe', [PushSubscriptionController::class, 'unsubscribe']);
        Route::post('/push/test', [PushSubscriptionController::class, 'test']);
    });
});
