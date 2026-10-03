<?php

namespace App\Models;

use App\Enums\FuelType;
use App\Models\Concerns\LogsActivity;
use Database\Factories\EngineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shared by all car models (not tied to one). Power is stored in kW only.
 */
class Engine extends Model
{
    /** @use HasFactory<EngineFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'fuel_type', 'power_kw', 'is_active'];

    protected function casts(): array
    {
        return [
            'fuel_type' => FuelType::class,
            'power_kw' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Version, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Version::class);
    }
}
