<?php

namespace App\Concerns;

use App\Enums\AccountStatus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The requester of an on-behalf work order (FLOW.md §4): an approved, active account
 * of the requester department, or a contact name. Exactly one, chosen by
 * `requester_mode`.
 */
trait WorkOrderRequesterRules
{
    public const string REQUESTER_ACCOUNT = 'account';

    public const string REQUESTER_CONTACT = 'contact';

    /**
     * @param  int|null  $departmentId  the requester department; null reads `requester_department_id` from the input
     * @return array<string, array<int, ValidationRule|string|Exists>>
     */
    protected function requesterRules(?int $departmentId = null): array
    {
        $account = self::REQUESTER_ACCOUNT;
        $contact = self::REQUESTER_CONTACT;

        return [
            'requester_mode' => ['required', Rule::in([$account, $contact])],
            'requester_id' => [
                "required_if:requester_mode,{$account}",
                "prohibited_unless:requester_mode,{$account}",
                'nullable',
                'integer',
                // The same accounts the picker offers (User::activeRequesterIn()).
                Rule::exists(User::class, 'id')
                    ->where('department_id', $departmentId ?? $this->integer('requester_department_id'))
                    ->where('is_active', true)
                    ->where('account_status', AccountStatus::Approved->value)
                    ->whereNull('deleted_at'),
            ],
            'requester_name' => [
                "required_if:requester_mode,{$contact}",
                "prohibited_unless:requester_mode,{$contact}",
                'nullable',
                'string',
                'max:150',
            ],
        ];
    }

    /**
     * An active department of a client company, the side that requests work.
     *
     * @return array<int, string|Exists>
     */
    protected function requesterDepartmentRules(): array
    {
        return [
            'required',
            'integer',
            Rule::exists(Department::class, 'id')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where(fn ($query) => $query->whereIn('company_id', fn ($companies) => $companies->select('id')->from('companies')->where('is_client', true))),
        ];
    }

    /**
     * The requester fields, refused when the user may not set them.
     *
     * @return array<string, array<int, string>>
     */
    protected function prohibitedRequesterRules(bool $withDepartment): array
    {
        return [
            ...($withDepartment ? ['requester_department_id' => ['prohibited']] : []),
            'requester_mode' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'requester_name' => ['prohibited'],
        ];
    }

    /**
     * The trimmed contact name, so a blank one counts as missing.
     */
    protected function prepareRequesterForValidation(): void
    {
        if (is_string($this->input('requester_name'))) {
            $this->merge(['requester_name' => trim($this->input('requester_name'))]);
        }
    }
}
