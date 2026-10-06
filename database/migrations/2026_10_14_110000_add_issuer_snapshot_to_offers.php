<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expand step only: nullable columns, the previous release ignores them. The issuer (dealer) details
// copied into the offer when it is made; an older offer has none and prints the plain header.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->string('issuer_name', 150)->nullable()->after('client_country');
            $table->string('issuer_address', 150)->nullable()->after('issuer_name');
            $table->string('issuer_postal_code', 5)->nullable()->after('issuer_address');
            $table->string('issuer_city', 100)->nullable()->after('issuer_postal_code');
            $table->string('issuer_pib', 9)->nullable()->after('issuer_city');
            $table->string('issuer_phone', 30)->nullable()->after('issuer_pib');
            $table->string('issuer_email', 150)->nullable()->after('issuer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['issuer_name', 'issuer_address', 'issuer_postal_code', 'issuer_city', 'issuer_pib', 'issuer_phone', 'issuer_email']);
        });
    }
};
