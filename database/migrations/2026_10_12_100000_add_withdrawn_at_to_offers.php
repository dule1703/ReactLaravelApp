<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expand step only: a nullable column, the previous release ignores it. A withdrawn offer is a
// status of the offer (the client withdrew it), not a deletion: it stays readable.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->timestamp('withdrawn_at')->nullable()->after('total_gross_cents');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('withdrawn_at');
        });
    }
};
