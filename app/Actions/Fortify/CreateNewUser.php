<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\AccountStatus;
use App\Enums\AuditEvent;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Self-service registration (FLOW.md §3). The account is pending, holds no
 * roles or permissions, and only reaches its status page until an admin
 * approves it. Only name, email, password, and department are taken from the
 * request; everything else (status, roles, flags) is set here.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        if (is_string($input['email'] ?? null)) {
            $input['email'] = User::normalizeEmail($input['email']);
        }

        $validated = Validator::make($input, [
            'name' => $this->nameRules(),
            'email' => $this->emailRules(),
            'password' => $this->passwordRules(),
            'company_id' => [
                'required',
                'integer',
                Rule::exists(Company::class, 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'department_id' => [
                'required',
                'integer',
                Rule::exists(Department::class, 'id')
                    ->where('company_id', is_numeric($input['company_id'] ?? null) ? (int) $input['company_id'] : 0)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
        ], [
            'department_id.exists' => __('Pilih departemen aktif dari perusahaan yang dipilih.'),
        ])->after($this->emailDomainCheck(...))->validate();

        return DB::transaction(function () use ($validated): User {
            $user = (new User)->forceFill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'department_id' => (int) $validated['department_id'],
                'is_active' => true,
                'must_change_password' => false,
                'account_status' => AccountStatus::Pending,
                'registered_at' => now(),
            ]);

            // One "registered" entry below instead of the generic "created".
            $user->disableLogging()->save();
            $user->enableLogging();

            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->event(AuditEvent::Registered->value)
                ->withChanges(['attributes' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'department_id' => $user->department_id,
                    'account_status' => AccountStatus::Pending->value,
                ]])
                ->withProperties(['ip' => (string) request()->ip()])
                ->log(AuditEvent::Registered->value);

            return $user;
        });
    }

    /**
     * The email's domain must be one of the chosen company's domains, so the
     * company is fixed by the email address.
     */
    private function emailDomainCheck(ValidatorInstance $validator): void
    {
        if ($validator->errors()->hasAny(['email', 'company_id'])) {
            return;
        }

        $data = $validator->getData();
        $company = Company::query()->findOrFail((int) $data['company_id']);

        if (! $company->allowsEmailDomain((string) $data['email'])) {
            $validator->errors()->add('email', __('Email harus memakai domain :company (:domains).', [
                'company' => $company->name,
                'domains' => implode(', ', $company->email_domains),
            ]));
        }
    }
}
