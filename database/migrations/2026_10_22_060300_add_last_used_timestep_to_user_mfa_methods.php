<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_mfa_methods', function (Blueprint $table) {
            $table->unsignedBigInteger('last_used_timestep')->nullable()->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_mfa_methods', function (Blueprint $table) {
            $table->dropColumn('last_used_timestep');
        });
    }
};
