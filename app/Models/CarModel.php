<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\CarModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class CarModel extends Model
{
    /** @use HasFactory<CarModelFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'image_path', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Categories of this model, in display order. Read access: change the links only through
     * CarModelCategories (sync() skips model events, so the service writes the activity log).
     *
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'car_model_category')
            ->withTimestamps()
            ->orderBy('categories.sort_order')->orderBy('categories.name');
    }

    /**
     * Public URL of the uploaded image (relative to the current host), or null.
     */
    public function imageUrl(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
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
