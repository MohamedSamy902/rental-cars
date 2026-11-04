<?php

namespace App\Services\Operations;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use DomainException;
use Illuminate\Support\Facades\DB;

class EmployeeAssignmentService
{
    /**
     * @param  array{employee_id:int,title:string,assignable_type?:string,assignable_id?:int|null,notes?:string|null}  $data
     */
    public function assign(array $data, ?int $actorId = null): EmployeeAssignment
    {
        Employee::query()->findOrFail($data['employee_id']);

        return EmployeeAssignment::query()->create([
            'employee_id' => (int) $data['employee_id'],
            'assignable_type' => $data['assignable_type'] ?? 'manual',
            'assignable_id' => $data['assignable_id'] ?? null,
            'title' => trim((string) $data['title']),
            'notes' => $data['notes'] ?? null,
            'status' => 'pending_ack',
            'assigned_by' => $actorId,
            'assigned_at' => now(),
        ]);
    }

    public function acknowledgeReceipt(EmployeeAssignment $assignment, ?int $adminId = null): EmployeeAssignment
    {
        if ($assignment->status === 'acknowledged') {
            throw new DomainException('تم تأكيد استلام هذا التسليم مسبقاً.');
        }

        return DB::transaction(function () use ($assignment, $adminId) {
            $assignment->fill([
                'status' => 'acknowledged',
                'acknowledged_by' => $adminId,
                'acknowledged_at' => now(),
            ])->save();

            return $assignment->fresh(['employee', 'assignedBy', 'acknowledgedBy']);
        });
    }
}
