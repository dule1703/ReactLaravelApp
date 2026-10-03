<?php

namespace App\Services;

use App\Models\CarModel;

/**
 * The only place that changes the categories of a car model. sync() on the relation skips
 * model events, so this service compares the state before and after and writes ONE activity
 * log entry (`car_model.categories_changed`) with the old and new category names; nothing is
 * written when nothing changed. Call it inside the transaction that saves the model.
 */
class CarModelCategories
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * @param  array<int, int|string>  $categoryIds
     */
    public function sync(CarModel $model, array $categoryIds): void
    {
        $before = $this->names($model);

        $model->categories()->sync(array_map('intval', $categoryIds));

        $after = $this->names($model);

        if ($before === $after) {
            return;
        }

        $this->logger->log(
            'car_model.categories_changed',
            $model,
            changes: ['categories' => ['old' => implode(', ', $before), 'new' => implode(', ', $after)]],
        );
    }

    /**
     * @return list<string> category names in display order
     */
    private function names(CarModel $model): array
    {
        return $model->categories()->pluck('categories.name')->all();
    }
}
