<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 32)->default('pending_activation')->after('password');
            $table->timestamp('invitation_expires_at')->nullable()->after('status');
            $table->timestamp('activated_at')->nullable()->after('invitation_expires_at');
            $table->timestamp('deactivated_at')->nullable()->after('activated_at');
            $table->unsignedBigInteger('deactivated_by')->nullable()->after('deactivated_at');
            $table->timestamp('last_login_at')->nullable()->after('deactivated_by');
            $table->unsignedBigInteger('created_by')->nullable()->after('last_login_at');

            $table->index('status');

            $table->foreign('deactivated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('user_activation_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id');
            $table->unique('token_hash');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('user_mfa_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 16)->default('totp');
            $table->text('secret');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['user_id', 'type']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('user_mfa_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('code_hash', 64);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 120);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('outcome', 24);
            $table->timestamp('created_at');

            $table->index(['subject_type', 'subject_id']);
            $table->index('actor_id');
            $table->index('action');
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('user_mfa_recovery_codes');
        Schema::dropIfExists('user_mfa_methods');
        Schema::dropIfExists('user_activation_tokens');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['deactivated_by']);
            $table->dropForeign(['created_by']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status',
                'invitation_expires_at',
                'activated_at',
                'deactivated_at',
                'deactivated_by',
                'last_login_at',
                'created_by',
            ]);
        });
    }
};
