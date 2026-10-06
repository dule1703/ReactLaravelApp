<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expand step only: a new table the previous release does not know. One row: the dealer that issues
// the offers (printed in the header of the PDF; copied into each offer when it is made, see 5.3).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuer_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('address', 150)->nullable();
            $table->string('postal_code', 5)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('pib', 9)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuer_profiles');
    }
};
