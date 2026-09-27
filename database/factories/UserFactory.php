<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
            'must_change_password' => false,
        ];
    }

    /**
     * Indicate that the user still has the default password.
     */
    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes): array => [
            'must_change_password' => true,
        ]);
    }

    /**
     * Indicate that the user is deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * A self-registered account waiting for review (FLOW.md §3).
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_status' => AccountStatus::Pending,
            'registered_at' => now(),
        ]);
    }

    /**
     * A self-registered account an admin rejected.
     */
    public function rejected(string $reason = 'Bukan karyawan perusahaan ini.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_status' => AccountStatus::Rejected,
            'rejection_reason' => $reason,
            'registered_at' => now()->subDay(),
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
