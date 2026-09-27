<?php

namespace App\Enums;

use App\Models\Company;

/**
 * Which companies' users a role may be given (roles.company_scope). A null
 * scope means users of any company.
 */
enum CompanyScope: string
{
    /**
     * Users of a client company, which requests work orders (IC).
     */
    case Client = 'client';

    /**
     * Users of the executor company, which carries them out (Unggul).
     */
    case Executor = 'executor';

    /**
     * The label shown on the role form and list.
     */
    public function label(): string
    {
        return match ($this) {
            self::Client => 'Perusahaan klien',
            self::Executor => 'Perusahaan pelaksana',
        };
    }

    /**
     * @return array{value: string, label: string}
     */
    public function toOption(): array
    {
        return ['value' => $this->value, 'label' => $this->label()];
    }

    /**
     * The scope of the given company's users.
     */
    public static function of(Company $company): self
    {
        return $company->is_client ? self::Client : self::Executor;
    }

    /**
     * Whether a role with the given scope fits users of the company.
     */
    public static function fits(?self $scope, Company $company): bool
    {
        return ! $scope instanceof CompanyScope || $scope === self::of($company);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $scope): array => $scope->toOption(), self::cases());
    }
}
