<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('announcement_audiences')
            ->where('audience_type', 'all_employees')
            ->whereNull('audience_id')
            ->update(['audience_id' => 0]);

        Schema::table('announcement_audiences', function (Blueprint $table) {
            $table->unique(
                ['announcement_id', 'audience_type', 'audience_id'],
                'announcement_audiences_type_unique',
            );
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->index(['status', 'scheduled_at'], 'announcements_status_scheduled_index');
        });

        Schema::table('announcement_reads', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->after('announcement_id');
            $table->index(['employee_id', 'read_at'], 'announcement_reads_employee_read_index');
            $table->foreign('employee_id', 'announcement_reads_employee_id_foreign')
                ->references('id')
                ->on('employees')
                ->cascadeOnDelete();
        });

        DB::statement(
            'UPDATE announcement_reads ar
             JOIN employees e ON e.user_id = ar.user_id
             SET ar.employee_id = e.id',
        );

        Schema::table('announcement_reads', function (Blueprint $table) {
            $table->unique(
                ['announcement_id', 'employee_id'],
                'announcement_reads_announcement_employee_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('announcement_reads', function (Blueprint $table) {
            $table->dropUnique('announcement_reads_announcement_employee_unique');
            $table->dropForeign('announcement_reads_employee_id_foreign');
            $table->dropIndex('announcement_reads_employee_read_index');
            $table->dropColumn('employee_id');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex('announcements_status_scheduled_index');
        });

        Schema::table('announcement_audiences', function (Blueprint $table) {
            $table->dropUnique('announcement_audiences_type_unique');
        });
    }
};
