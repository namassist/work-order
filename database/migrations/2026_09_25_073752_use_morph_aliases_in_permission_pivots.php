<?php

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrderCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Rewrites the role/permission holder type from the class name to the morph
 * alias enforced in AppServiceProvider.
 *
 * The map is frozen here on purpose: later changes to the morph map must not
 * change what this migration did.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const array ALIASES = [
        'user' => User::class,
        'department' => Department::class,
        'wo-category' => WorkOrderCategory::class,
        'role' => Role::class,
        'permission' => Permission::class,
    ];

    /**
     * @var list<string>
     */
    private const array TABLES = ['model_has_roles', 'model_has_permissions'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->rewrite(array_flip(self::ALIASES));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->rewrite(self::ALIASES);
    }

    /**
     * @param  array<string, string>  $replacements  current type => new type
     */
    private function rewrite(array $replacements): void
    {
        foreach (self::TABLES as $table) {
            foreach ($replacements as $from => $to) {
                DB::table($table)->where('model_type', $from)->update(['model_type' => $to]);
            }
        }

        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
