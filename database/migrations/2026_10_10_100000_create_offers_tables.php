<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expand step only: new tables, the previous release ignores them.
//
// An offer is a SNAPSHOT document: it copies names and prices (and the VAT rate and a few client
// details) when it is created and never references the catalog. That is why there is no foreign
// key to car models, trims, engines, versions or equipment (the catalog is deactivated or
// deleted without looking at offers; the equipment matrix even deletes rows). The only foreign
// keys are to the owner (restrict: a client with offers cannot be deleted) and from parts of an
// offer to the offer (cascade).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // NNN/GGGG. The number is generated in 4.2; (year, seq) is the real guard against
            // two offers with the same number, `number` is the text shown and searched.
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('seq');
            $table->string('number', 12);
            $table->date('offer_date');

            // The VAT rate in basis points at the moment of creation (a later change of the
            // setting never touches an existing offer).
            $table->unsignedInteger('vat_rate_bp');
            $table->text('note')->nullable();

            // Client details at the moment of creation (what the PDF shows). The JMBG is NEVER
            // copied here; the PIB is a public number, but it is still logged by field name only.
            $table->string('client_type', 20)->default('individual');
            $table->string('client_name')->nullable();
            $table->string('client_pib', 9)->nullable();
            $table->string('client_address')->nullable();
            $table->char('client_postal_code', 5)->nullable();
            $table->string('client_city')->nullable();
            $table->char('client_country', 2)->default('RS');

            // Totals in cents, filled by the calculation service (4.4) when the offer is created
            // and stored so that a later change of the algorithm cannot change an old offer.
            $table->unsignedBigInteger('total_net_cents')->nullable();
            $table->unsignedBigInteger('vat_cents')->nullable();
            $table->unsignedBigInteger('total_gross_cents')->nullable();

            $table->timestamps();

            $table->unique(['year', 'seq']);
            $table->unique('number');
        });

        Schema::create('offer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedSmallInteger('quantity')->default(1);

            // Snapshot of the configuration (text, not references).
            $table->string('car_model_name');
            $table->string('trim_name');
            $table->string('engine_name');
            $table->string('fuel_type', 20);
            $table->unsignedSmallInteger('power_kw');
            $table->string('transmission_name');
            $table->string('drive', 10);

            // Net price of the version and the line total ((version + options) x quantity, 4.4).
            $table->unsignedBigInteger('version_price_cents');
            $table->unsignedBigInteger('line_net_cents')->nullable();

            $table->timestamps();
        });

        Schema::create('offer_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            // Snapshot of one chosen extra. For an item of a single-choice option group the
            // price is a SURCHARGE over the standard item (added to the version price, never a
            // replacement), so the group name and the flag are kept with it.
            $table->string('name');
            $table->string('category', 20);
            $table->string('group_name')->nullable();
            $table->boolean('is_surcharge')->default(false);
            $table->unsignedBigInteger('price_cents');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_item_options');
        Schema::dropIfExists('offer_items');
        Schema::dropIfExists('offers');
    }
};
