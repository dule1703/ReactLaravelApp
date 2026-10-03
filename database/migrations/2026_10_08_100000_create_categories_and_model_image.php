<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expand step only: two new tables and one nullable column; the previous release ignores them.
// A model can be in several categories (many-to-many). Foreign keys are RESTRICT, like the rest
// of the catalog: links are removed explicitly (through CarModelCategories), never by cascade.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('car_model_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_model_id')->constrained('car_models')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['car_model_id', 'category_id']);
        });

        Schema::table('car_models', function (Blueprint $table) {
            // Path on the "public" disk (catalog/models/<random>.<ext>); uploaded files never go to git.
            $table->string('image_path')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('car_models', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });

        Schema::dropIfExists('car_model_category');
        Schema::dropIfExists('categories');
    }
};
