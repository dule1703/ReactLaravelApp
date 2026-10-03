<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expand step only: new tables, nothing existing is touched. Every foreign key is RESTRICT:
// offers (phase 4) rely on these rows, so catalog rows are deactivated (is_active), not deleted.
// Prices are integer cents, NET of VAT (the offer adds VAT on top).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_models', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('trims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_model_id')->constrained('car_models')->restrictOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['car_model_id', 'name']);
        });

        Schema::create('engines', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('fuel_type', 20);
            $table->unsignedSmallInteger('power_kw');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['name', 'fuel_type', 'power_kw']);
        });

        Schema::create('transmissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->string('drive', 10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['name', 'type', 'drive']);
        });

        Schema::create('versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trim_id')->constrained('trims')->restrictOnDelete();
            $table->foreignId('engine_id')->constrained('engines')->restrictOnDelete();
            $table->foreignId('transmission_id')->constrained('transmissions')->restrictOnDelete();
            $table->unsignedBigInteger('base_price_cents');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['trim_id', 'engine_id', 'transmission_id'], 'versions_trim_engine_transmission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versions');
        Schema::dropIfExists('transmissions');
        Schema::dropIfExists('engines');
        Schema::dropIfExists('trims');
        Schema::dropIfExists('car_models');
    }
};
