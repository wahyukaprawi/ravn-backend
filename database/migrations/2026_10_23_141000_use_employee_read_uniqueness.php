<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcement_reads', function (Blueprint $table) {
            $table->dropUnique('announcement_reads_announcement_id_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('announcement_reads', function (Blueprint $table) {
            $table->unique(['announcement_id', 'user_id'], 'announcement_reads_announcement_id_user_id_unique');
        });
    }
};
