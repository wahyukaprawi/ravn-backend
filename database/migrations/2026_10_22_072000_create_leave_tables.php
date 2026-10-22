<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_calendars', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 150);
            $table->string('timezone', 64);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('work_calendar_days', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('work_calendar_id');
            $table->date('date');
            $table->string('type', 20)->default('work');
            $table->string('name', 150)->nullable();
            $table->timestamps();

            $table->unique(['work_calendar_id', 'date']);
            $table->foreign('work_calendar_id')->references('id')->on('work_calendars')->cascadeOnDelete();
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 60)->unique();
            $table->string('name', 150);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('leave_policies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('leave_type_id');
            $table->string('name', 150);
            $table->unsignedInteger('annual_entitlement_days')->default(0);
            $table->unsignedInteger('submission_deadline_days_before')->nullable();
            $table->boolean('active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->foreign('leave_type_id')->references('id')->on('leave_types')->restrictOnDelete();
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedInteger('year');
            $table->decimal('entitled', 10, 2)->default(0);
            $table->decimal('used', 10, 2)->default(0);
            $table->decimal('reserved', 10, 2)->default(0);
            $table->decimal('adjusted', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'leave_type_id', 'year']);
            $table->index(['user_id', 'year']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->restrictOnDelete();
        });

        Schema::create('leave_balance_ledger', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedBigInteger('leave_request_id')->nullable();
            $table->string('entry_type', 40);
            $table->decimal('amount', 10, 2);
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'leave_type_id', 'created_at']);
            $table->index(['leave_request_id']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedBigInteger('workflow_instance_id')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 10, 2);
            $table->string('status', 40)->default('draft');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('canceled_by')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['start_date', 'end_date']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->restrictOnDelete();
            $table->foreign('workflow_instance_id')->references('id')->on('workflow_instances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balance_ledger');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_policies');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('work_calendar_days');
        Schema::dropIfExists('work_calendars');
    }
};
