<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Radical integrity fixes from audit:
 * - merge duplicate payroll payout tables into payroll_payouts
 * - stop cascade-deleting swap audit when a vehicle is force-deleted (MySQL)
 * - add secondary indexes for WO / violations / notifications / payments filters
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_line_payouts') && Schema::hasTable('payroll_payouts')) {
            $rows = DB::table('payroll_line_payouts')->orderBy('id')->get();
            foreach ($rows as $row) {
                $exists = DB::table('payroll_payouts')
                    ->where('payroll_line_id', $row->payroll_line_id)
                    ->where('amount', $row->amount)
                    ->whereDate('paid_on', $row->paid_on)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('payroll_payouts')->insert([
                    'payroll_line_id' => $row->payroll_line_id,
                    'amount' => $row->amount,
                    'paid_on' => $row->paid_on,
                    'method' => $row->method ?? 'cash',
                    'paid_by' => $row->created_by ?? null,
                    'notes' => $row->notes,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }
            Schema::dropIfExists('payroll_line_payouts');
        }

        if (Schema::hasTable('contract_vehicle_swaps') && Schema::getConnection()->getDriverName() === 'mysql') {
            $this->mysqlRebuildSwapVehicleFks();
        }

        $this->addIndexIfMissing('work_orders', 'work_orders_vehicle_status_idx', ['vehicle_id', 'status']);
        $this->addIndexIfMissing('work_orders', 'work_orders_status_opened_idx', ['status', 'opened_on']);
        $this->addIndexIfMissing('violations', 'violations_status_idx', ['status']);
        $this->addIndexIfMissing('violations', 'violations_vehicle_occurred_idx', ['vehicle_id', 'occurred_at']);
        $this->addIndexIfMissing('violations', 'violations_contract_status_idx', ['contract_id', 'status']);
        $this->addIndexIfMissing('notifications', 'notifications_user_read_idx', ['user_id', 'read_at']);
        $this->addIndexIfMissing('payments', 'payments_direction_paid_idx', ['direction', 'paid_at']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('payments', 'payments_direction_paid_idx');
        $this->dropIndexIfExists('notifications', 'notifications_user_read_idx');
        $this->dropIndexIfExists('violations', 'violations_status_idx');
        $this->dropIndexIfExists('violations', 'violations_vehicle_occurred_idx');
        $this->dropIndexIfExists('violations', 'violations_contract_status_idx');
        $this->dropIndexIfExists('work_orders', 'work_orders_vehicle_status_idx');
        $this->dropIndexIfExists('work_orders', 'work_orders_status_opened_idx');

        if (! Schema::hasTable('payroll_line_payouts')) {
            Schema::create('payroll_line_payouts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payroll_line_id')->constrained('payroll_lines')->cascadeOnDelete();
                $table->unsignedInteger('amount');
                $table->date('paid_on');
                $table->string('method', 32)->default('cash');
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['payroll_line_id', 'paid_on']);
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndexIfMissing(string $table, string $index, array $columns): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $index)) {
            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($columns, $index) {
            $blueprint->index($columns, $index);
        });
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $index)) {
            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($index) {
            $blueprint->dropIndex($index);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");

            return collect($rows)->contains(fn ($r) => ($r->name ?? '') === $index);
        }

        $schema = Schema::getConnection()->getDatabaseName();
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$schema, $table, $index],
        );

        return $rows !== [];
    }

    private function mysqlRebuildSwapVehicleFks(): void
    {
        $fks = DB::select("
            SELECT CONSTRAINT_NAME AS name
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'contract_vehicle_swaps'
              AND COLUMN_NAME IN ('from_vehicle_id', 'to_vehicle_id')
              AND REFERENCED_TABLE_NAME = 'vehicles'
            GROUP BY CONSTRAINT_NAME
        ");
        foreach ($fks as $fk) {
            $name = $fk->name;
            DB::statement("ALTER TABLE contract_vehicle_swaps DROP FOREIGN KEY `{$name}`");
        }

        Schema::table('contract_vehicle_swaps', function (Blueprint $table) {
            $table->foreign('from_vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
            $table->foreign('to_vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
        });
    }
};
