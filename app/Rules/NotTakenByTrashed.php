<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Fails when the value belongs to a soft-deleted record.
 *
 * Unique indexes still cover soft-deleted rows, so pair this rule with
 * `Rule::unique(...)->withoutTrashed()` to tell the user to restore the old
 * record instead of hitting a database error or a generic "taken" message.
 */
class NotTakenByTrashed implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model  A model that uses SoftDeletes with the default `deleted_at` column.
     * @param  bool  $offerRestore  Only users allowed to see deleted records get the restore hint;
     *                              everyone else gets the standard "taken" message.
     */
    public function __construct(
        private readonly string $model,
        private readonly string $column,
        private readonly bool $offerRestore,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $takenByTrashed = $this->model::query()
            ->withoutGlobalScopes()
            ->whereNotNull('deleted_at')
            ->where($this->column, $value)
            ->exists();

        if (! $takenByTrashed) {
            return;
        }

        $fail($this->offerRestore
            ? __(':Attribute ini dipakai oleh data yang sudah dihapus. Pulihkan data tersebut lewat filter "Tampilkan terhapus".')
            : __('validation.unique'));
    }
}
