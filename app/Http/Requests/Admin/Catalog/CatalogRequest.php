<?php

namespace App\Http\Requests\Admin\Catalog;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (POST) and update (PATCH) of one catalog entity share one request. Names are unique
 * WITHOUT regard to letter case, checked with lower() on both sides in the database: the same
 * result on MySQL and on SQLite, whose comparison is case-sensitive.
 */
abstract class CatalogRequest extends FormRequest
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** Name of the route parameter that holds the row on update, e.g. "carModel". */
    abstract protected function routeParameter(): string;

    public function authorize(): bool
    {
        $user = $this->user();
        $current = $this->current();

        if ($user === null) {
            return false;
        }

        return $current ? $user->can('update', $current) : $user->can('create', $this->modelClass());
    }

    /** The row being edited, or null when creating. */
    protected function current(): ?Model
    {
        $route = $this->route($this->routeParameter());

        return $route instanceof Model ? $route : null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function baseRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'regex:/^[^\p{C}]+$/u'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Whether another row (not the one being edited) matches the scope, comparing the name
     * case-insensitively.
     *
     * @param  callable(Builder<Model>): mixed  $scope
     */
    protected function nameTaken(string $name, callable $scope): bool
    {
        return $this->modelClass()::query()
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->tap($scope)
            ->when($this->current(), fn (Builder $query, Model $row) => $query->whereKeyNot($row->getKey()))
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('catalog.attr.name'),
            'sort_order' => __('catalog.attr.sort_order'),
            'power_kw' => __('catalog.attr.power_kw'),
            'fuel_type' => __('catalog.attr.fuel_type'),
            'type' => __('catalog.attr.type'),
            'drive' => __('catalog.attr.drive'),
            'car_model_id' => __('catalog.attr.car_model_id'),
        ];
    }
}
