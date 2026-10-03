<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\CarModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class CarModel extends Model
{
    /** @use HasFactory<CarModelFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Trim, $this>
     */
    public function trims(): HasMany
    {
        return $this->hasMany(Trim::class);
    }

    /**
     * @return HasManyThrough<Version, Trim, $this>
     */
    public function versions(): HasManyThrough
    {
        return $this->hasManyThrough(Version::class, Trim::class);
    }
}
