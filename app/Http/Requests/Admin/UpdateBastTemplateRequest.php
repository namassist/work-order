<?php

namespace App\Http\Requests\Admin;

use App\Models\BastTemplate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBastTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage', BastTemplate::class) ?? false;
    }

    /**
     * The size is checked in bytes, before the HTML is parsed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'html' => ['present', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > config()->integer('work_order.bast.template_max_html_bytes')) {
                    $fail(__('Template terlalu besar. Kurangi isi atau format.'));
                }
            }],
        ];
    }
}
