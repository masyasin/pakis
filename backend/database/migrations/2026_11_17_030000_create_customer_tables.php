<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Area penjualan, kios inti & penugasan (15 §4.6, T-073, FR-A12/A13/A17, BR-167). Tabel kontak,
 * verifikasi, dan OTP kios dibuat T-074; FK `customers.price_list_id` ditambahkan T-075.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_areas', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('supervisor_employee_id')->nullable();
            $table->unsignedBigInteger('home_stock_location_id')->nullable()->comment('gudang asal default');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('code', 'sales_areas_code_unique');
            $table->index(['supervisor_employee_id', 'is_active'], 'sales_areas_supervisor_idx');
            $table->foreign('parent_id', 'sa_parent_fk')->references('id')->on('sales_areas');
            $table->foreign('supervisor_employee_id', 'sa_supervisor_fk')->references('id')->on('employees');
            $table->foreign('home_stock_location_id', 'sa_home_sl_fk')->references('id')->on('stock_locations');
        });

        // Satu kecamatan boleh masuk beberapa area (index tidak unik, keputusan T-073).
        Schema::create('sales_area_districts', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_area_id');
            $table->char('district_code', 8);

            $table->primary(['sales_area_id', 'district_code']);
            $table->index('district_code', 'sad_district_idx');
            $table->foreign('sales_area_id', 'sad_area_fk')->references('id')->on('sales_areas')->cascadeOnDelete();
            $table->foreign('district_code', 'sad_district_fk')->references('code')->on('districts');
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->comment("NumberGenerator('customer') atau manual");
            $table->string('store_name', 150);
            $table->string('owner_name', 150);
            $table->string('owner_phone', 20)->comment('format 62…, nomor WhatsApp resmi kios');
            $table->timestamp('owner_phone_verified_at')->nullable();
            $table->unsignedBigInteger('customer_type_id')->comment('reference_items(customer_type)');
            $table->text('address')->nullable();
            $table->char('village_code', 13)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('photo_path', 255)->nullable()->comment('foto toko (T-074)');
            $table->unsignedBigInteger('sales_area_id');
            $table->unsignedBigInteger('assigned_sales_employee_id')->nullable();
            $table->unsignedBigInteger('price_list_id')->nullable()->comment('NULL = price list default; FK di T-075');
            $table->unsignedBigInteger('home_stock_location_id')->nullable()->comment('NULL = gudang asal area');
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->boolean('credit_limit_active')->default(false);
            $table->unsignedSmallInteger('term_days')->default(30);
            $table->boolean('harvest_term_allowed')->default(false);
            $table->unsignedSmallInteger('harvest_term_max_days')->nullable();
            $table->boolean('restricted_buyer_eligible')->default(false)->comment('BR-112');
            $table->string('restricted_buyer_note', 255)->nullable();
            $table->string('restricted_buyer_document_path', 255)->nullable();
            $table->date('restricted_buyer_valid_until')->nullable();
            $table->enum('status', ['prospect', 'active', 'credit_hold', 'inactive', 'merged'])->default('prospect');
            $table->string('hold_reason', 255)->nullable();
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('code', 'customers_code_unique');
            $table->index(['sales_area_id', 'assigned_sales_employee_id', 'status'], 'customers_area_sales_idx');
            $table->index(['assigned_sales_employee_id', 'status'], 'customers_sales_status_idx');
            $table->index(['status', 'store_name'], 'customers_status_name_idx');
            $table->index('owner_phone', 'customers_phone_idx');
            $table->foreign('customer_type_id', 'customers_type_fk')->references('id')->on('reference_items');
            $table->foreign('village_code', 'customers_village_fk')->references('code')->on('villages');
            $table->foreign('sales_area_id', 'customers_area_fk')->references('id')->on('sales_areas');
            $table->foreign('assigned_sales_employee_id', 'customers_sales_fk')->references('id')->on('employees');
            $table->foreign('home_stock_location_id', 'customers_home_sl_fk')->references('id')->on('stock_locations');
            $table->foreign('merged_into_id', 'customers_merged_fk')->references('id')->on('customers');
        });

        // Pengaman DB di samping Policy/Action (15 §4.6, BR-125): limit aktif hanya untuk kios terverifikasi.
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_limit_chk CHECK (credit_limit >= 0)');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_active_limit_chk CHECK (credit_limit_active = 0 OR verified_at IS NOT NULL)');
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_merged_chk CHECK (status <> 'merged' OR merged_into_id IS NOT NULL)");

        Schema::create('customer_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('sales_area_id');
            $table->unsignedBigInteger('sales_employee_id')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('reason', 255)->nullable();
            // BR-167: index unik atas kolom ini = tepat satu penugasan terbuka per kios (NULL tidak
            // bentrok, jadi penugasan yang sudah ditutup bebas).
            $table->unsignedBigInteger('active_key')->nullable()
                ->storedAs('IF(end_date IS NULL, customer_id, NULL)');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('active_key', 'ca_one_active_unique');
            $table->index(['sales_employee_id', 'end_date'], 'ca_sales_end_idx');
            $table->index(['customer_id', 'start_date'], 'ca_customer_start_idx');
            $table->foreign('customer_id', 'ca_customer_fk')->references('id')->on('customers');
            $table->foreign('sales_area_id', 'ca_area_fk')->references('id')->on('sales_areas');
            $table->foreign('sales_employee_id', 'ca_sales_fk')->references('id')->on('employees');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_assignments');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('sales_area_districts');
        Schema::dropIfExists('sales_areas');
    }
};
