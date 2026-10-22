<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('manager_id')->nullable()->after('created_by');
            $table->index('manager_id');
            $table->foreign('manager_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('manager_id')->nullable()->after('leave_type_id');
            $table->index(['user_id', 'status', 'start_date', 'end_date'], 'leave_requests_overlap_index');
            $table->foreign('manager_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('leave_cancellation_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('leave_request_id');
            $table->unsignedBigInteger('requester_id');
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->unsignedBigInteger('workflow_instance_id')->nullable();
            $table->string('status', 40)->default('pending');
            $table->text('reason');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['leave_request_id', 'status']);
            $table->foreign('leave_request_id')->references('id')->on('leave_requests')->cascadeOnDelete();
            $table->foreign('workflow_instance_id')->references('id')->on('workflow_instances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_cancellation_requests');

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropIndex('leave_requests_overlap_index');
            $table->dropColumn('manager_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropIndex(['manager_id']);
            $table->dropColumn('manager_id');
        });
    }
};
