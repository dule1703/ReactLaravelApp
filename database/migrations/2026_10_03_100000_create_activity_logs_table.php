<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expand only: a new, append-only table. Nothing existing is touched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->useCurrent()->index();

            // Who: user_id has no foreign key on purpose, so deleting a user never touches the log.
            // The snapshot columns keep who they were at the time of the action.
            $table->string('actor_type', 10)->default('user'); // user | guest | system
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('user_role', 20)->nullable();

            $table->string('action', 60)->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable(); // label at the time; the subject may be deleted later
            $table->string('description', 500)->nullable();
            $table->json('changes')->nullable();

            $table->string('ip', 45)->nullable()->index();
            $table->text('user_agent')->nullable();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
