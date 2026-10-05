<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Expand step only: a new technical table, the previous release ignores it.
//
// One row per year with the last assigned sequence number; App\Services\OfferNumber increments it
// inside the transaction that saves the offer, so a rolled back offer does not use up a number.
// The unique keys of `offers` ((year, seq) and number) stay the final guard.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_seq')->default(0);
        });

        // Offers that already exist (local or test data) must not get a duplicate number.
        DB::table('offer_counters')->insertUsing(
            ['year', 'last_seq'],
            DB::table('offers')->selectRaw('year, max(seq)')->groupBy('year'),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_counters');
    }
};
