<?php

namespace App\Models;

use App\Concerns\LogsModelActivity;
use App\Concerns\SearchesColumns;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A company whose departments use the application: a client (IC), which
 * requests work orders, or the executor (Unggul), which carries them out.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_client
 * @property list<string> $email_domains
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['code', 'name', 'is_client', 'email_domains', 'is_active'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, LogsModelActivity, SearchesColumns, SoftDeletes;

    /**
     * The model's default values for attributes, matching the columns' defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_client' => false,
        'email_domains' => '[]',
    ];

    /**
     * Departments of the company (soft-deleted ones excluded).
     *
     * @return HasMany<Department, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * Whether the email's domain is exactly one of the company's allowed
     * domains (case-insensitive; subdomains do not match).
     */
    public function allowsEmailDomain(string $email): bool
    {
        return in_array(Str::lower(Str::afterLast($email, '@')), array_map(Str::lower(...), $this->email_domains), true);
    }

    /**
     * Search by code or name.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $this->searchColumns($query, $term, ['code', 'name']);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_client' => 'boolean',
            'email_domains' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
