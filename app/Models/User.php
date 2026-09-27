<?php

namespace App\Models;

use App\Concerns\LogsModelActivity;
use App\Concerns\SearchesColumns;
use App\Enums\AccountStatus;
use App\Enums\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property int $department_id
 * @property bool $is_active
 * @property AccountStatus $account_status
 * @property string|null $rejection_reason
 * @property Carbon|null $registered_at
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $must_change_password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Department $department
 * @property-read User|null $reviewer
 */
#[Fillable(['name', 'email', 'password', 'must_change_password', 'department_id', 'is_active'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsModelActivity, Notifiable, SearchesColumns, SoftDeletes;

    use HasRoles {
        HasRoles::hasPermissionTo as private hasPermissionToIgnoringCompany;
        HasRoles::getAllPermissions as private getAllPermissionsIgnoringCompany;
    }

    /**
     * The model's default values for attributes, matching the columns' defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'account_status' => 'approved',
    ];

    /**
     * The form every email is stored and compared in: trimmed and lowercase.
     * Emails are case-insensitive; the unique index is on lower(email).
     */
    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * Every write (registration, admin forms, profile, seeders) stores the
     * normalized email, so two casings of one address cannot coexist.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => self::normalizeEmail($value));
    }

    /**
     * The user with the email, whatever its casing (uses the lower(email) index).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withEmail(Builder $query, string $email): void
    {
        $query->whereRaw('lower(email) = ?', [self::normalizeEmail($email)]);
    }

    /**
     * The department the user belongs to, even if it was deleted later.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    /**
     * The admin who last approved or rejected the registration.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    /**
     * Whether an admin approved the account (admin-created accounts always are).
     */
    public function isApproved(): bool
    {
        return $this->account_status === AccountStatus::Approved;
    }

    /**
     * Whether the user works for a client company (IC), which requests work
     * orders, rather than the executor company.
     */
    public function isClient(): bool
    {
        return $this->department->company->is_client;
    }

    /**
     * Spatie's check, except that users of a client company never hold an
     * internal-only permission (Permission::isInternalOnly()), whichever role
     * or direct grant gives it to them.
     *
     * @param  string|int|PermissionContract|\BackedEnum  $permission
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        $permission = $this->filterPermission($permission, $guardName);

        if ($this->isClient() && Permission::tryFrom($permission->name)?->isInternalOnly()) {
            return false;
        }

        return $this->hasPermissionToIgnoringCompany($permission, $guardName);
    }

    /**
     * Every permission the user holds, directly or through roles, without
     * internal-only ones for client company users (see hasPermissionTo()).
     *
     * @return Collection<int, PermissionContract>
     */
    public function getAllPermissions(): Collection
    {
        $permissions = $this->getAllPermissionsIgnoringCompany();

        if (! $this->isClient()) {
            return $permissions;
        }

        return $permissions
            ->reject(fn (PermissionContract $permission): bool => Permission::tryFrom($permission->name)?->isInternalOnly() ?? false)
            ->values();
    }

    /**
     * Accounts that can be the requester of an on-behalf work order in the
     * department: approved, active, and not deleted. The same rule backs the
     * picker and the validation of the chosen account
     * (WorkOrderRequesterRules), so pending and rejected registrations never
     * pass either.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function activeRequesterIn(Builder $query, int $departmentId): void
    {
        $query->where('department_id', $departmentId)
            ->where('is_active', true)
            ->where('account_status', AccountStatus::Approved->value);
    }

    /**
     * Self-registered accounts (FLOW.md §3), the ones the Pendaftaran page reviews.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function registrations(Builder $query): void
    {
        $query->whereNotNull('registered_at');
    }

    /**
     * Search by name or email.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $this->searchColumns($query, $term, ['name', 'email']);
    }

    /**
     * Whether this user is the only active, non-deleted user that can manage roles.
     *
     * Deactivating, deleting, or demoting such a user would lock everyone out of
     * role management.
     */
    public function isLastRoleManager(): bool
    {
        if (! $this->is_active || ! $this->checkPermissionTo(Permission::RolesManage->value)) {
            return false;
        }

        return ! self::permission(Permission::RolesManage->value)
            ->where('is_active', true)
            ->whereKeyNot($this->getKey())
            ->exists();
    }

    /**
     * Password changes and login housekeeping are logged as auth events
     * instead, so they never produce an "updated" entry.
     */
    protected function activityLogOptions(LogOptions $options): LogOptions
    {
        return $options->dontLogIfAttributesChangedOnly(['updated_at', 'remember_token', 'password', 'must_change_password']);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'account_status' => AccountStatus::class,
            'registered_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }
}
