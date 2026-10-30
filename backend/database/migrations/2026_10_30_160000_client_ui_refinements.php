<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_brands')) {
            Schema::create('vehicle_brands', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vehicle_addon_catalog')) {
            Schema::create('vehicle_addon_catalog', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('category')->nullable();
                $table->unsignedInteger('default_qty')->default(1);
                $table->boolean('required_each_rental')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vehicle_document_histories')) {
            Schema::create('vehicle_document_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
                $table->foreignId('vehicle_document_id')->nullable()->constrained('vehicle_documents')->nullOnDelete();
                $table->string('type');
                $table->string('file_path')->nullable();
                $table->date('issued_on')->nullable();
                $table->date('expires_on')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('archived_at');
                $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['vehicle_id', 'type']);
            });
        }

        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'work_order_id')) {
                $table->foreignId('work_order_id')->nullable()->after('vehicle_id')->constrained('work_orders')->nullOnDelete();
            }
        });

        Schema::table('work_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('work_orders', 'opened_at')) {
                $table->dateTime('opened_at')->nullable()->after('opened_on');
            }
            if (! Schema::hasColumn('work_orders', 'next_service_on')) {
                $table->date('next_service_on')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('work_orders', 'next_service_odometer')) {
                $table->unsignedInteger('next_service_odometer')->nullable()->after('next_service_on');
            }
        });

        $brands = [
            'تويوتا', 'هيونداي', 'كيا', 'نيسان', 'شيفروليه', 'رينو', 'بيجو', 'فيات',
            'ميتسوبيشي', 'هوندا', 'سوزوكي', 'مازدا', 'فولكس فاجن', 'مرسيدس', 'بي إم دبليو',
            'سكودا', 'جيلي', 'شيري', 'بي واي دي', 'إم جي', 'أوبل', 'فورد', 'جيب', 'أخرى',
        ];
        foreach ($brands as $i => $name) {
            DB::table('vehicle_brands')->updateOrInsert(
                ['name' => $name],
                ['sort_order' => $i + 1, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $addons = [
            ['name' => 'مثلث تحذير', 'category' => 'سلامة', 'default_qty' => 1],
            ['name' => 'طفاية حريق', 'category' => 'سلامة', 'default_qty' => 1],
            ['name' => 'عدة إسعافات', 'category' => 'سلامة', 'default_qty' => 1],
            ['name' => 'كفر احتياطي', 'category' => 'تشغيل', 'default_qty' => 1],
            ['name' => 'مفتاح احتياطي', 'category' => 'تشغيل', 'default_qty' => 1],
            ['name' => 'شاحن USB', 'category' => 'راحة', 'default_qty' => 1],
            ['name' => 'سجادة أرضية', 'category' => 'راحة', 'default_qty' => 4],
        ];
        foreach ($addons as $i => $row) {
            DB::table('vehicle_addon_catalog')->updateOrInsert(
                ['name' => $row['name']],
                [
                    'category' => $row['category'],
                    'default_qty' => $row['default_qty'],
                    'required_each_rental' => true,
                    'sort_order' => $i + 1,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (Schema::hasColumn('work_orders', 'next_service_odometer')) {
                $table->dropColumn('next_service_odometer');
            }
            if (Schema::hasColumn('work_orders', 'next_service_on')) {
                $table->dropColumn('next_service_on');
            }
            if (Schema::hasColumn('work_orders', 'opened_at')) {
                $table->dropColumn('opened_at');
            }
        });

        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'work_order_id')) {
                $table->dropConstrainedForeignId('work_order_id');
            }
        });

        Schema::dropIfExists('vehicle_document_histories');
        Schema::dropIfExists('vehicle_addon_catalog');
        Schema::dropIfExists('vehicle_brands');
    }
};
