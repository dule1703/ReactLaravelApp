<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expand step only: new tables, nothing existing is touched. Foreign keys are RESTRICT (offers
// in phase 4 rely on these rows). "Not available" equipment is simply a missing trim_equipment
// row. Prices are integer cents, NET of VAT. The price rule (standard => no price, optional =>
// price >= 0) is enforced by the TrimEquipment model.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_items', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('category', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('trim_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trim_id')->constrained('trims')->restrictOnDelete();
            $table->foreignId('equipment_item_id')->constrained('equipment_items')->restrictOnDelete();
            $table->string('availability', 20);
            $table->unsignedBigInteger('price_cents')->nullable();
            $table->timestamps();

            $table->unique(['trim_id', 'equipment_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trim_equipment');
        Schema::dropIfExists('equipment_items');
    }
};
