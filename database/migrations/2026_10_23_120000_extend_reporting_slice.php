<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->string('permission', 120)->nullable()->after('scope');
            $table->boolean('sensitive')->default(false)->after('permission');
        });

        Schema::table('report_exports', function (Blueprint $table): void {
            $table->string('scope', 20)->default('self')->after('format');
            $table->unsignedInteger('row_count')->nullable()->after('checksum');
            $table->string('failure_code', 60)->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->dropColumn(['scope', 'row_count', 'failure_code']);
        });

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn(['permission', 'sensitive']);
        });
    }
};
