<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 80)->unique();
            $table->string('name', 150);
            $table->unsignedInteger('version')->default(1);
            $table->json('schema');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workflow_definition_id');
            $table->string('subject_type', 150);
            $table->unsignedBigInteger('subject_id');
            $table->string('status', 40)->default('pending');
            $table->unsignedBigInteger('initiator_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['status', 'due_at']);
            $table->foreign('workflow_definition_id')->references('id')->on('workflow_definitions')->restrictOnDelete();
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workflow_instance_id');
            $table->string('step_key', 80);
            $table->unsignedInteger('position');
            $table->string('status', 40)->default('pending');
            $table->string('assignee_type', 40)->nullable();
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->json('required_permissions')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->unsignedBigInteger('acted_by')->nullable();
            $table->string('action', 40)->nullable();
            $table->json('action_payload')->nullable();
            $table->timestamps();

            $table->unique(['workflow_instance_id', 'step_key']);
            $table->index(['workflow_instance_id', 'status']);
            $table->index(['assignee_type', 'assignee_id', 'status']);
            $table->foreign('workflow_instance_id')->references('id')->on('workflow_instances')->cascadeOnDelete();
        });

        Schema::create('workflow_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workflow_instance_id');
            $table->unsignedBigInteger('workflow_step_id')->nullable();
            $table->string('event_type', 80);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workflow_instance_id', 'created_at']);
            $table->index(['event_type']);
            $table->foreign('workflow_instance_id')->references('id')->on('workflow_instances')->cascadeOnDelete();
        });

        Schema::create('workflow_escalations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workflow_step_id');
            $table->unsignedInteger('level')->default(1);
            $table->string('escalate_to_type', 40);
            $table->unsignedBigInteger('escalate_to_id');
            $table->timestamp('escalated_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['workflow_step_id', 'resolved_at']);
            $table->foreign('workflow_step_id')->references('id')->on('workflow_steps')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_escalations');
        Schema::dropIfExists('workflow_events');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_definitions');
    }
};
