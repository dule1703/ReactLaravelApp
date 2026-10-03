<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expand step only: one new table and three nullable columns; the previous release ignores them.
// Colors, wheels and upholstery are "one of several" choices, not independent extras: an option
// group holds such items. An item without a group stays an independent extra (as before).
// The FK is RESTRICT like the rest of the catalog: a group with items is deactivated, not deleted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('category', 20);
            $table->string('selection', 10)->default('single');
            // Items of this group may carry a color swatch (equipment_items.swatch_hex).
            $table->boolean('uses_swatch')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('equipment_items', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('category')->constrained('option_groups')->restrictOnDelete();
            // Path on the "public" disk (catalog/equipment/<random>.<ext>); uploads never go to git.
            $table->string('image_path')->nullable()->after('group_id');
            $table->char('swatch_hex', 7)->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn(['image_path', 'swatch_hex']);
        });

        Schema::dropIfExists('option_groups');
    }
};
