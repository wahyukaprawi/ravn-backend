<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_locations', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('id');
            $table->string('remote_address', 500)->nullable()->after('address');
            $table->softDeletes();
        });

        DB::table('work_locations')->whereNull('code')->orderBy('id')->each(function (object $location): void {
            DB::table('work_locations')
                ->where('id', $location->id)
                ->update(['code' => 'LOC-'.$location->id]);
        });

        DB::statement('ALTER TABLE `work_locations` MODIFY `code` VARCHAR(50) NOT NULL');
        Schema::table('work_locations', function (Blueprint $table) {
            $table->unique('code', 'work_locations_code_unique');
            $table->index('deleted_at');
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 50);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->string('active_code', 50)->nullable()
                ->storedAs('IF((`deleted_at` is null),`code`,NULL)');
            $table->unique('active_code', 'departments_active_code_unique');
            $table->index('deleted_at');
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 50);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->string('active_code', 50)->nullable()
                ->storedAs('IF((`deleted_at` is null),`code`,NULL)');
            $table->unique('active_code', 'positions_active_code_unique');
            $table->index('deleted_at');
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('employee_no', 50)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('work_email', 255)->nullable();
            $table->date('hire_date');
            $table->softDeletes();
            $table->timestamps();

            $table->string('active_work_email', 255)->nullable()
                ->storedAs('IF((`deleted_at` is null),`work_email`,NULL)');
            $table->unique('active_work_email', 'employees_active_work_email_unique');
            $table->index('deleted_at');
            $table->index('hire_date');

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('employee_employments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('employee_id');
            $table->string('employment_type', 20);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from', 'effective_to'], 'employments_employee_range_index');
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE `employee_employments` ADD CONSTRAINT `employments_type_check` CHECK (`employment_type` IN ('permanent', 'fixed_term', 'internship'))");
        DB::statement('ALTER TABLE `employee_employments` ADD CONSTRAINT `employments_range_check` CHECK (`effective_to` IS NULL OR `effective_to` >= `effective_from`)');

        Schema::create('employee_assignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('employee_employment_id');
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('position_id');
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->unsignedBigInteger('work_location_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('replaced_assignment_id')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->unsignedBigInteger('canceled_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from', 'effective_to'], 'assignments_employee_range_index');
            $table->index(['manager_id', 'effective_from', 'effective_to'], 'assignments_manager_range_index');
            $table->index('work_location_id');

            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('employee_employment_id')->references('id')->on('employee_employments')->restrictOnDelete();
            $table->foreign('replaced_assignment_id')->references('id')->on('employee_assignments')->restrictOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->foreign('position_id')->references('id')->on('positions')->restrictOnDelete();
            $table->foreign('manager_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('work_location_id')->references('id')->on('work_locations')->restrictOnDelete();
            $table->foreign('canceled_by')->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE `employee_assignments` ADD CONSTRAINT `assignments_range_check` CHECK (`effective_to` IS NULL OR `effective_to` >= `effective_from`)');

        Schema::create('employee_import_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('uploaded_by');
            $table->string('original_filename', 255);
            $table->string('stored_path', 500)->nullable();
            $table->string('status', 20)->default('validated');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['uploaded_by', 'status']);
            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('employee_import_rows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('employee_import_batch_id');
            $table->unsignedInteger('row_number');
            $table->string('employee_no', 50)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('work_email', 255)->nullable();
            $table->date('hire_date')->nullable();
            $table->string('status', 20)->default('valid');
            $table->json('errors')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->timestamps();

            $table->unique(['employee_import_batch_id', 'row_number'], 'import_rows_batch_row_unique');
            $table->foreign('employee_import_batch_id', 'import_rows_batch_fk')
                ->references('id')->on('employee_import_batches')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
        });

        Schema::create('history_corrections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity_type', 20);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('requester_id');
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->unsignedBigInteger('executor_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('changes');
            $table->json('before');
            $table->text('reason');
            $table->string('evidence_path', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('status');
            $table->foreign('requester_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approver_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('executor_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('history_corrections');
        Schema::dropIfExists('employee_import_rows');
        Schema::dropIfExists('employee_import_batches');
        Schema::dropIfExists('employee_assignments');
        Schema::dropIfExists('employee_employments');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');

        Schema::table('work_locations', function (Blueprint $table) {
            $table->dropUnique('work_locations_code_unique');
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
            $table->dropColumn(['code', 'remote_address']);
        });
    }
};
