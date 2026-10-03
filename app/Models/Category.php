<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Purpose-based grouping of car models (a model can be in several). Purely a classification:
 * it never affects what can be offered (Version::available()).
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Read access to the links. Change them only through CarModelCategories (sync() skips model
     * events, so the service writes the activity log entry).
     *
     * @return BelongsToMany<CarModel, $this>
     */
    public function carModels(): BelongsToMany
    {
        return $this->belongsToMany(CarModel::class, 'car_model_category')->withTimestamps();
    }
}
