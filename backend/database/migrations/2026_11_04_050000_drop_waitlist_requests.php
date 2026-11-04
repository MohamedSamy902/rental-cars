<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('waitlist_requests');

        $permissionIds = DB::table('permissions')->where('key', 'like', 'waitlist.%')->pluck('id');
        if ($permissionIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        if (Schema::hasTable('tenant_settings')) {
            $rows = DB::table('tenant_settings')->select('id', 'feature_flags')->get();
            foreach ($rows as $row) {
                $flags = json_decode($row->feature_flags ?? '[]', true);
                if (! is_array($flags) || ! array_key_exists('waitlist', $flags)) {
                    continue;
                }
                unset($flags['waitlist']);
                DB::table('tenant_settings')->where('id', $row->id)->update([
                    'feature_flags' => json_encode($flags, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('waitlist_requests')) {
            return;
        }

        Schema::create('waitlist_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->foreignId('preferred_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->dateTime('desired_start')->nullable();
            $table->dateTime('desired_end')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->string('status')->default('waiting');
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('resulting_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
