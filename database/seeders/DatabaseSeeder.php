<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrderCategory;
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
        $this->call(BastTemplateSeeder::class);

        Company::factory()->client()->create(['code' => 'IC', 'name' => 'IC', 'email_domains' => []]);
        $executor = Company::factory()->create(['code' => 'UGL', 'name' => 'Unggul', 'email_domains' => []]);

        $department = Department::factory()->for($executor)->create([
            'code' => 'IT',
            'name' => 'Information Technology',
        ]);

        User::factory()->for($department)->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ])->assignRole(SystemRole::Admin->value);

        foreach (['LST' => 'Listrik', 'BGN' => 'Bangunan', 'KND' => 'Kendaraan'] as $code => $name) {
            WorkOrderCategory::factory()->create(['code' => $code, 'name' => $name, 'description' => null]);
        }
    }
}
