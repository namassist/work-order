<?php

namespace App\Http\Requests\Admin;

use App\Concerns\CompanyValidationRules;
use App\Models\Company;
use App\Models\Department;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCompanyRequest extends FormRequest
{
    use CompanyValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->company()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->companyRules($this->company()->id);
    }

    /**
     * Whether a company is a client decides which roles its users may hold
     * and which side of a work order its departments are on, so it cannot
     * change once the company has departments (deleted ones included, since
     * they can be restored).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $company = $this->company();

                if ($this->boolean('is_client') === $company->is_client) {
                    return;
                }

                if (Department::withTrashed()->whereBelongsTo($company)->exists()) {
                    $validator->errors()->add('is_client', __('Jenis perusahaan tidak dapat diubah karena perusahaan ini sudah memiliki departemen.'));
                }
            },
        ];
    }

    /**
     * The company being updated.
     */
    public function company(): Company
    {
        /** @var Company */
        return $this->route('company');
    }
}
