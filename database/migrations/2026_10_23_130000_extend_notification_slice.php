<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('outbox_message_id')->nullable()->after('id');
            $table->unique(['outbox_message_id', 'notifiable_id'], 'notifications_outbox_recipient_unique');
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'notifications_recipient_created_index');
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->timestamp('available_at')->useCurrent()->after('attempts');
            $table->string('last_error_code', 80)->nullable()->after('last_error');
            $table->timestamp('dead_lettered_at')->nullable()->after('sent_at');
            $table->unique(['notification_id', 'channel']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropUnique(['notification_id', 'channel']);
            $table->dropIndex(['status', 'available_at']);
            $table->dropColumn(['available_at', 'last_error_code', 'dead_lettered_at']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique('notifications_outbox_recipient_unique');
            $table->dropIndex('notifications_recipient_created_index');
            $table->dropColumn('outbox_message_id');
        });
    }
};
