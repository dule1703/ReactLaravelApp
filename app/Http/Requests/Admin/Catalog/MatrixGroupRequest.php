<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Enums\EquipmentAvailability;
use App\Models\OptionGroup;
use App\Models\Trim;
use App\Models\TrimEquipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Removing a whole option group from a trim: an explicit step with the rows the browser saw.
 */
class MatrixGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delete', new TrimEquipment) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'car_model_id' => ['required', 'integer', 'exists:car_models,id'],
            'trim_id' => ['required', 'integer', Rule::exists('trims', 'id')->where('car_model_id', $this->input('car_model_id'))],
            'option_group_id' => ['required', 'integer', 'exists:option_groups,id'],
            'confirm' => ['accepted'],
            'expected' => ['required', 'array', 'min:1'],
            'expected.*.equipment_item_id' => ['required', 'integer'],
            'expected.*.availability' => ['required', Rule::in(array_column(EquipmentAvailability::cases(), 'value'))],
            'expected.*.price_cents' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'trim_id.exists' => __('The line does not belong to the selected model.'),
            'confirm.accepted' => __('Confirm removing the whole group from the line.'),
        ];
    }

    public function trim(): Trim
    {
        return Trim::query()->findOrFail($this->integer('trim_id'));
    }

    public function group(): OptionGroup
    {
        return OptionGroup::query()->findOrFail($this->integer('option_group_id'));
    }
}
