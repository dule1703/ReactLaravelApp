<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expand step only: a nullable column, the previous release ignores it (and would show deleted
// offers again until the next deploy: see the rollback warning in CLAUDE.md). An offer is deleted
// "softly": the row, its number and its parts stay; offers.user_id still restricts deleting a client.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->softDeletes()->after('withdrawn_at');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
