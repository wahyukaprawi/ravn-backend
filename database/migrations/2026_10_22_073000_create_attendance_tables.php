<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 150);
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_meters')->default(100);
            $table->string('timezone', 64);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('schedules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('work_location_id')->nullable();
            $table->string('name', 150)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('days');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('overnight')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'effective_from', 'effective_to']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('work_location_id')->references('id')->on('work_locations')->nullOnDelete();
        });

        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->unsignedBigInteger('work_location_id')->nullable();
            $table->date('work_date');
            $table->string('status', 40)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('timezone', 64);
            $table->timestamps();

            $table->unique(['user_id', 'work_date']);
            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('attendance_session_id');
            $table->unsignedBigInteger('user_id');
            $table->string('event_type', 40);
            $table->timestamp('occurred_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_meters', 10, 2)->nullable();
            $table->boolean('mock_location_detected')->default(false);
            $table->json('device_integrity')->nullable();
            $table->string('source', 40)->default('app');
            $table->string('idempotency_key', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['attendance_session_id', 'occurred_at']);
            $table->index(['user_id', 'event_type']);
            $table->index(['idempotency_key']);
            $table->foreign('attendance_session_id')->references('id')->on('attendance_sessions')->cascadeOnDelete();
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('attendance_session_id');
            $table->unsignedBigInteger('requester_id');
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->unsignedBigInteger('executor_id')->nullable();
            $table->unsignedBigInteger('workflow_instance_id')->nullable();
            $table->string('status', 40)->default('draft');
            $table->json('changes');
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['attendance_session_id', 'status']);
            $table->foreign('attendance_session_id')->references('id')->on('attendance_sessions')->cascadeOnDelete();
        });

        Schema::create('face_verification_challenges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('challenge_id', 64)->unique();
            $table->string('status', 40)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_verification_challenges');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('work_locations');
    }
};
