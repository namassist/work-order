<?php

namespace App\Http\Requests\Admin;

use App\Concerns\WorkOrderCategoryValidationRules;
use App\Models\WorkOrderCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkOrderCategoryRequest extends FormRequest
{
    use WorkOrderCategoryValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->category()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->workOrderCategoryRules($this->category()->id);
    }

    /**
     * The work order category being updated.
     */
    public function category(): WorkOrderCategory
    {
        /** @var WorkOrderCategory */
        return $this->route('category');
    }
}
