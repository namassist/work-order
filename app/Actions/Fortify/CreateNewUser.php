<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\AccountStatus;
use App\Enums\AuditEvent;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Self-service registration (FLOW.md §3), for executor company (Unggul)
 * staff only: the email's domain picks the company, and IC domains are
 * refused (IC never logs in, v2 §1). The account is pending, holds no roles
 * or permissions, and only reaches its status page until an admin approves
 * it. Only name, email, password, and department are taken from the
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
            'department_id' => [
                'required',
                'integer',
                Rule::exists(Department::class, 'id')
                    ->where('company_id', $this->companyFor($input['email'] ?? null)->id ?? 0)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
        ], [
            'department_id.exists' => __('Pilih departemen aktif dari perusahaan email Anda.'),
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
     * The email's domain must be one of an active executor company's
     * domains; that company is the one the department must belong to.
     */
    private function emailDomainCheck(ValidatorInstance $validator): void
    {
        if ($validator->errors()->has('email') || $this->companyFor($validator->getData()['email'] ?? null) instanceof Company) {
            return;
        }

        $domains = self::registrableCompanies()->flatMap(fn (Company $company): array => $company->email_domains)->all();

        $validator->errors()->add('email', __('Email harus memakai domain perusahaan pelaksana (:domains).', [
            'domains' => implode(', ', $domains),
        ]));
    }

    /**
     * The active executor company whose domains include the email's, if any
     * (domains are unique across companies).
     */
    private function companyFor(mixed $email): ?Company
    {
        return is_string($email)
            ? self::registrableCompanies()->first(fn (Company $company): bool => $company->allowsEmailDomain($email))
            : null;
    }

    /**
     * Companies whose staff may register: active executor companies.
     *
     * @return Collection<int, Company>
     */
    public static function registrableCompanies(): Collection
    {
        return Company::query()
            ->where('is_client', false)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email_domains']);
    }
}
