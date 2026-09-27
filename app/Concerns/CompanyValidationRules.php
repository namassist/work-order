<?php

namespace App\Concerns;

use App\Models\Company;
use App\Rules\NotTakenByTrashed;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait CompanyValidationRules
{
    /**
     * The most allowed email domains one company may list.
     */
    private const int MAX_EMAIL_DOMAINS = 20;

    /**
     * Get the validation rules used to validate companies.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string|Closure>>
     */
    protected function companyRules(?int $companyId = null): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique(Company::class)->withoutTrashed()->ignore($companyId),
                new NotTakenByTrashed(Company::class, 'code', $this->user()?->can('viewTrashed', Company::class) ?? false),
            ],
            'name' => ['required', 'string', 'max:255'],
            'is_client' => ['required', 'boolean'],
            'email_domains' => ['present', 'array', 'max:'.self::MAX_EMAIL_DOMAINS],
            'email_domains.*' => [
                'string',
                'max:253',
                'distinct',
                // A hostname with at least one dot, e.g. "ic.co.id".
                'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
                // Registration picks the company from the email domain, so a
                // domain belongs to one company only (deleted ones included).
                function (string $attribute, mixed $value, Closure $fail) use ($companyId): void {
                    $taken = Company::withTrashed()
                        ->when($companyId, fn ($query, int $id) => $query->whereKeyNot($id))
                        ->whereJsonContains('email_domains', $value)
                        ->exists();

                    if ($taken) {
                        $fail(__('Domain :domain sudah dipakai perusahaan lain.', ['domain' => $value]));
                    }
                },
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email_domains.*.regex' => __('Domain :input tidak valid. Contoh: perusahaan.co.id'),
            'email_domains.*.distinct' => __('Domain :input tercantum lebih dari sekali.'),
        ];
    }

    /**
     * Normalize the code to upper case and the domains to bare lower-case
     * hostnames ("@IC.co.id " becomes "ic.co.id") before validating.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }

        $domains = $this->input('email_domains');

        if (is_array($domains)) {
            $this->merge(['email_domains' => array_values(array_filter(
                array_map(fn (mixed $domain): mixed => is_string($domain) ? ltrim(mb_strtolower(trim($domain)), '@') : $domain, $domains),
                fn (mixed $domain): bool => $domain !== '' && $domain !== null,
            ))]);
        }
    }
}
