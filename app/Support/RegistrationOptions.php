<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Department;

/**
 * What the public registration page may know: active companies that have an
 * email domain (id, name, domains) and their active departments (id, code,
 * name, company). Nothing else, since anyone can open the page.
 */
class RegistrationOptions
{
    /**
     * @return array{
     *     companies: list<array{id: int, name: string, email_domains: list<string>}>,
     *     departments: list<array{id: int, code: string, name: string, company_id: int}>,
     * }
     */
    public static function forRegisterPage(): array
    {
        $companies = Company::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email_domains'])
            ->filter(fn (Company $company): bool => $company->email_domains !== [])
            ->values();

        $departments = Department::query()
            ->where('is_active', true)
            ->whereIn('company_id', $companies->pluck('id'))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'company_id']);

        return [
            'companies' => array_values($companies
                ->map(fn (Company $company): array => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'email_domains' => $company->email_domains,
                ])
                ->all()),
            'departments' => array_values($departments
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'code' => $department->code,
                    'name' => $department->name,
                    'company_id' => $department->company_id,
                ])
                ->all()),
        ];
    }
}
