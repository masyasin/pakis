<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mesin approval generik (15 §4.2, ADR-105, BR-153, T-067). DDL mengikuti 15 §4.2 apa adanya:
 * aturan + langkahnya bisa diubah Owner, permintaan menyimpan salinan aturan (`rule_snapshot`)
 * sehingga perubahan aturan tidak memengaruhi permintaan berjalan.
 *
 * `pending_key` (kolom generated + unique) menjamin di tingkat DB hanya ada satu permintaan
 * `pending` per dokumen; permintaan lama (approved/rejected/cancelled) bernilai NULL sehingga tidak
 * ikut bentrok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 60);
            $table->string('name', 120);
            $table->decimal('min_amount', 18, 2)->nullable();   // inklusif; NULL = tanpa batas bawah
            $table->decimal('max_amount', 18, 2)->nullable();   // eksklusif; NULL = tanpa batas atas
            $table->json('conditions')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);  // kecil = dicek lebih dulu
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['document_type', 'is_active', 'priority'], 'approval_rules_type_active_idx');
        });
        DB::statement('ALTER TABLE approval_rules ADD CONSTRAINT approval_rules_amount_chk CHECK (min_amount IS NULL OR max_amount IS NULL OR max_amount > min_amount)');

        Schema::create('approval_rule_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('approval_rule_id');
            $table->unsignedTinyInteger('step_order');
            $table->enum('approver_type', ['permission', 'direct_supervisor', 'location_head']);
            $table->string('permission_name', 120)->nullable();
            $table->unsignedSmallInteger('escalate_after_hours')->nullable(); // jam kalender; NULL = setting
            $table->string('escalation_permission', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['approval_rule_id', 'step_order'], 'ars_rule_order_unique');
            $table->foreign('approval_rule_id', 'ars_rule_fk')->references('id')->on('approval_rules')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE approval_rule_steps ADD CONSTRAINT ars_perm_chk CHECK (approver_type <> 'permission' OR permission_name IS NOT NULL)");

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 60);
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('approval_rule_id');
            $table->json('rule_snapshot');
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('summary', 255);
            $table->json('context')->nullable();
            $table->unsignedBigInteger('requester_user_id');
            $table->unsignedBigInteger('document_creator_user_id')->nullable();
            $table->unsignedBigInteger('scope_location_id')->nullable();
            $table->unsignedBigInteger('scope_employee_id')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->timestamp('decided_at')->nullable();
            $table->string('pending_key', 80)->nullable()
                ->storedAs("IF(status = 'pending', CONCAT(document_type, '#', document_id), NULL)");
            $table->timestamps();

            $table->unique('pending_key', 'apr_one_pending_unique');
            $table->index(['document_type', 'document_id'], 'apr_doc_idx');
            $table->index(['status', 'created_at'], 'apr_status_created_idx');
            $table->foreign('approval_rule_id', 'apr_rule_fk')->references('id')->on('approval_rules');
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('approval_request_id');
            $table->unsignedTinyInteger('step_order');
            $table->enum('approver_type', ['permission', 'direct_supervisor', 'location_head']);
            $table->string('permission_name', 120)->nullable();
            $table->unsignedBigInteger('resolved_employee_id')->nullable();
            $table->enum('status', ['waiting', 'pending', 'approved', 'rejected', 'skipped'])->default('waiting');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('escalate_at')->nullable();
            $table->boolean('escalated')->default(false);
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->unsignedBigInteger('acting_for_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['approval_request_id', 'step_order'], 'aps_req_order_unique');
            $table->index(['status', 'permission_name'], 'aps_pending_perm_idx');
            $table->index(['status', 'resolved_employee_id'], 'aps_pending_emp_idx');
            $table->index(['status', 'escalate_at'], 'aps_escalate_idx');
            $table->foreign('approval_request_id', 'aps_req_fk')->references('id')->on('approval_requests')->cascadeOnDelete();
        });

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delegator_user_id');
            $table->unsignedBigInteger('delegate_user_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->json('document_types')->nullable();  // NULL = semua jenis
            $table->string('reason', 255);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['delegate_user_id', 'is_active', 'start_date', 'end_date'], 'apd_delegate_idx');
        });
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT apd_not_self_chk CHECK (delegator_user_id <> delegate_user_id)');
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT apd_dates_chk CHECK (end_date >= start_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_rule_steps');
        Schema::dropIfExists('approval_rules');
    }
};
