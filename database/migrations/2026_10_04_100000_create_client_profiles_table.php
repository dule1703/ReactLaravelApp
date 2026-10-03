<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Expand step only: a new table plus an idempotent backfill, the previous release ignores it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('individual');
            $table->string('full_name')->nullable();
            // Encrypted by the model; the ciphertext is long, hence text.
            $table->text('jmbg')->nullable();
            // HMAC-SHA256 hex of the normalized JMBG (exact lookup, uniqueness).
            $table->char('jmbg_hash', 64)->nullable()->unique();
            $table->string('pib', 9)->nullable();
            $table->string('address')->nullable();
            $table->char('postal_code', 5)->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('RS');
            $table->timestamps();
        });

        // Existing clients get an empty profile; re-running adds nothing.
        $now = now();

        DB::table('client_profiles')->insertUsing(
            ['user_id', 'full_name', 'created_at', 'updated_at'],
            DB::table('users')
                ->where('role', 'client')
                ->whereNotExists(fn ($query) => $query
                    ->select(DB::raw(1))
                    ->from('client_profiles')
                    ->whereColumn('client_profiles.user_id', 'users.id'))
                ->selectRaw('users.id, users.name, ?, ?', [$now, $now]),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('client_profiles');
    }
};
