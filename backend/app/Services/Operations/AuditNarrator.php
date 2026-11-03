<?php

namespace App\Services\Operations;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Employee;
use App\Models\MonthLock;
use App\Models\PayrollLine;
use App\Models\PettyCashAccount;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;

/**
 * Converts audit trail rows into short natural Arabic sentences for staff.
 */
class AuditNarrator
{
    public function summarize(AuditLog $log, ?string $userName = null): string
    {
        $who = $userName ?: 'مستخدم النظام';
        $after = is_array($log->after) ? $log->after : [];
        $before = is_array($log->before) ? $log->before : [];

        return match ($log->action) {
            'created' => $this->createdSentence($who, $log, $after),
            'approved' => "قام {$who} باعتماد العقد".$this->contractRef($after),
            'handover' => "قام {$who} بتسليم العربية وتفعيل العقد".$this->contractRef($after),
            'completed' => "قام {$who} باستلام العربية وإقفال العقد".$this->contractRef($after),
            'cancelled' => "قام {$who} بإلغاء العقد".$this->contractRef($after),
            'price_override' => "قام {$who} بتعديل أسعار العقد".$this->contractRef($after),
            'swapped' => "قام {$who} بتبديل عربية العقد بسبب عطل".$this->contractRef($after),
            'deposit_received' => "قام {$who} بقبض التأمين النقدي".$this->moneyRef($after),
            'deposit_deducted' => "قام {$who} بخصم من التأمين النقدي".$this->moneyRef($after),
            'deposit_returned' => "قام {$who} برد متبقي التأمين".$this->moneyRef($after),
            'payment_recorded' => "قام {$who} بتسجيل دفعة".$this->moneyRef($after),
            'month_locked' => "قام {$who} بإقفال شهر محاسبي".$this->monthRef($after),
            'month_reopened' => "قام {$who} بإعادة فتح شهر محاسبي".$this->monthRef($after),
            'salary_advance_recorded' => "قام {$who} بتسجيل سلفة للموظف".$this->employeeRef($after),
            'salary_advance_deleted' => "قام {$who} بحذف سلفة موظف".$this->employeeRef($before ?: $after),
            'payroll_payout' => "قام {$who} بصرف راتب جزئي أو كامل".$this->moneyRef($after),
            'petty_cash_opened', 'petty_cash_topup' => "قام {$who} بتسجيل عهدة للموظف".$this->employeeRef($after),
            'work_order_opened' => "قام {$who} بفتح أمر شغل صيانة".$this->vehicleRef($after),
            'work_order_closed' => "قام {$who} بإغلاق أمر شغل صيانة".$this->vehicleRef($after),
            'vehicle_updated' => "قام {$who} بتعديل بيانات السيارة".$this->vehicleRef($after),
            'customer_updated' => "قام {$who} بتعديل بيانات عميل".$this->customerRef($after),
            default => $this->fallback($who, $log, $after),
        };
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function createdSentence(string $who, AuditLog $log, array $after): string
    {
        return match ($log->record_type) {
            Contract::class => "قام {$who} بإضافة حجز جديد".$this->contractRef($after),
            Vehicle::class => "قام {$who} بإضافة سيارة جديدة".$this->vehicleRef($after),
            Customer::class => "قام {$who} بإضافة عميل جديد".$this->customerRef($after),
            WorkOrder::class => "قام {$who} بفتح أمر شغل صيانة".$this->vehicleRef($after),
            PettyCashAccount::class => "قام {$who} بتسجيل عهدة للموظف".$this->employeeRef($after),
            Deposit::class => "قام {$who} بقبض التأمين النقدي".$this->moneyRef($after),
            default => "قام {$who} بإضافة سجل جديد",
        };
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function fallback(string $who, AuditLog $log, array $after): string
    {
        if (is_string($log->action) && str_starts_with($log->action, 'قام ')) {
            return $log->action;
        }

        $label = $this->recordLabel($log->record_type);

        return "قام {$who} بتنفيذ إجراء ({$log->action}) على {$label}".$this->contractRef($after);
    }

    private function recordLabel(string $type): string
    {
        return match ($type) {
            Contract::class => 'عقد',
            Vehicle::class => 'سيارة',
            Customer::class => 'عميل',
            WorkOrder::class => 'أمر شغل',
            PettyCashAccount::class => 'عهدة',
            Deposit::class => 'تأمين',
            MonthLock::class => 'شهر محاسبي',
            SalaryAdvance::class => 'سلفة',
            PayrollLine::class => 'سطر راتب',
            Employee::class => 'موظف',
            default => 'سجل',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function contractRef(array $data): string
    {
        $number = $data['number'] ?? null;
        if (is_string($number) && $number !== '') {
            return " رقم {$number}";
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function vehicleRef(array $data): string
    {
        $plate = $data['plate'] ?? $data['vehicle_plate'] ?? null;
        $code = $data['code'] ?? $data['vehicle_code'] ?? null;
        if (is_string($plate) && $plate !== '') {
            return " {$plate}";
        }
        if (is_string($code) && $code !== '') {
            return " {$code}";
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function customerRef(array $data): string
    {
        $name = $data['full_name'] ?? $data['customer_name'] ?? null;

        return is_string($name) && $name !== '' ? " {$name}" : '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function employeeRef(array $data): string
    {
        $name = $data['employee_name'] ?? $data['name'] ?? null;
        if (is_string($name) && $name !== '') {
            return " {$name}";
        }
        if (! empty($data['employee_id'])) {
            $resolved = Employee::query()->whereKey((int) $data['employee_id'])->value('name');
            if (is_string($resolved) && $resolved !== '') {
                return " {$resolved}";
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function moneyRef(array $data): string
    {
        if (isset($data['amount'])) {
            return ' بمبلغ '.(int) $data['amount'].' ج.م';
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function monthRef(array $data): string
    {
        if (isset($data['year'], $data['month'])) {
            return sprintf(' %04d-%02d', (int) $data['year'], (int) $data['month']);
        }

        return '';
    }

    /**
     * @param  list<AuditLog>|\Illuminate\Support\Collection<int, AuditLog>  $logs
     * @return array<int, string> keyed by user id
     */
    public function resolveUserNames($logs): array
    {
        $ids = collect($logs)->pluck('user_id')->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        return User::query()->whereKey($ids)->pluck('name', 'id')->all();
    }
}
