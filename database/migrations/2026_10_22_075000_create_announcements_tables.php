<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title', 200);
            $table->longText('body')->nullable();
            $table->string('status', 40)->default('draft');
            $table->unsignedBigInteger('author_id');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->foreign('author_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('announcement_audiences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('announcement_id');
            $table->string('audience_type', 40);
            $table->unsignedBigInteger('audience_id')->nullable();
            $table->timestamps();

            $table->index(['announcement_id']);
            $table->foreign('announcement_id')->references('id')->on('announcements')->cascadeOnDelete();
        });

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('announcement_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('read_at')->useCurrent();

            $table->unique(['announcement_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
            $table->foreign('announcement_id')->references('id')->on('announcements')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('announcement_revisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('announcement_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['announcement_id', 'created_at']);
            $table->foreign('announcement_id')->references('id')->on('announcements')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_revisions');
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcement_audiences');
        Schema::dropIfExists('announcements');
    }
};
