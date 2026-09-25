<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $department = Department::factory()->create([
            'code' => 'IT',
            'name' => 'Information Technology',
        ]);

        User::factory()->for($department)->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ])->assignRole(SystemRole::Admin->value);
    }
}
