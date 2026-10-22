<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 80)->unique();
            $table->string('name', 150);
            $table->string('scope', 40);
            $table->json('parameters')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('report_id')->nullable();
            $table->string('report_code', 80);
            $table->string('status', 40)->default('pending');
            $table->string('format', 20)->default('csv');
            $table->json('filters')->nullable();
            $table->string('disk', 40)->default('local');
            $table->string('path')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedBigInteger('downloaded_by')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['expires_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('token')->unique();
            $table->string('platform', 20);
            $table->string('device_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'active']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('reports');
    }
};
