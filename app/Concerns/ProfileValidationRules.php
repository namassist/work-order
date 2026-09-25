<?php

namespace App\Concerns;

use App\Models\User;
use App\Rules\NotTakenByTrashed;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null, bool $offerRestore = false): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId, $offerRestore),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null, bool $offerRestore = false): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            Rule::unique(User::class)->withoutTrashed()->ignore($userId),
            new NotTakenByTrashed(User::class, 'email', $offerRestore),
        ];
    }
}
