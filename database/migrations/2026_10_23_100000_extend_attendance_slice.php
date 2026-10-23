<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->unsignedInteger('late_grace_minutes')->default(0)->after('overnight');
            $table->unsignedInteger('early_departure_grace_minutes')->default(0)->after('late_grace_minutes');
            $table->softDeletes();
        });

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->timestamp('scheduled_start_at')->nullable()->after('timezone');
            $table->timestamp('scheduled_end_at')->nullable()->after('scheduled_start_at');
            $table->unsignedInteger('late_minutes')->default(0)->after('scheduled_end_at');
            $table->unsignedInteger('early_departure_minutes')->default(0)->after('late_minutes');
            $table->json('flags')->nullable()->after('early_departure_minutes');

            $table->foreign('schedule_id')->references('id')->on('schedules')->nullOnDelete();
            $table->foreign('work_location_id')->references('id')->on('work_locations')->nullOnDelete();
        });

        Schema::table('attendance_events', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_id')->nullable()->after('attendance_session_id');
            $table->unsignedBigInteger('work_location_id')->nullable()->after('schedule_id');
            $table->decimal('distance_meters', 12, 2)->nullable()->after('accuracy_meters');
            $table->boolean('face_verified')->default(false)->after('distance_meters');
            $table->string('verification_status', 40)->default('unverified')->after('face_verified');
            $table->string('face_challenge_id', 64)->nullable()->after('verification_status');
            $table->string('device_id', 100)->nullable()->after('face_challenge_id');
            $table->string('ip_address', 45)->nullable()->after('device_id');
            $table->json('flags')->nullable()->after('ip_address');
            $table->timestamp('corrected_at')->nullable()->after('flags');
            $table->unsignedBigInteger('corrected_by')->nullable()->after('corrected_at');
            $table->unsignedBigInteger('attendance_correction_id')->nullable()->after('corrected_by');

            $table->foreign('schedule_id')->references('id')->on('schedules')->nullOnDelete();
            $table->foreign('work_location_id')->references('id')->on('work_locations')->nullOnDelete();
            $table->foreign('corrected_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'event_type', 'idempotency_key']);
        });

        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->json('before')->nullable()->after('changes');
            $table->string('evidence_path', 255)->nullable()->after('reason');
            $table->timestamp('decided_at')->nullable()->after('approved_at');
            $table->timestamp('cancelled_at')->nullable()->after('rejected_at');
        });

        Schema::table('face_verification_challenges', function (Blueprint $table) {
            $table->string('device_id', 100)->nullable()->after('challenge_id');
            $table->timestamp('used_at')->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('face_verification_challenges', function (Blueprint $table) {
            $table->dropColumn(['device_id', 'used_at']);
        });

        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropColumn(['before', 'evidence_path', 'decided_at', 'cancelled_at']);
        });

        Schema::table('attendance_events', function (Blueprint $table) {
            $table->dropForeign(['schedule_id']);
            $table->dropForeign(['work_location_id']);
            $table->dropForeign(['corrected_by']);
            $table->dropIndex(['user_id', 'event_type', 'idempotency_key']);
            $table->dropColumn([
                'schedule_id',
                'work_location_id',
                'distance_meters',
                'face_verified',
                'verification_status',
                'face_challenge_id',
                'device_id',
                'ip_address',
                'flags',
                'corrected_at',
                'corrected_by',
                'attendance_correction_id',
            ]);
        });

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropForeign(['schedule_id']);
            $table->dropForeign(['work_location_id']);
            $table->dropColumn([
                'scheduled_start_at',
                'scheduled_end_at',
                'late_minutes',
                'early_departure_minutes',
                'flags',
            ]);
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['late_grace_minutes', 'early_departure_grace_minutes']);
        });
    }
};
