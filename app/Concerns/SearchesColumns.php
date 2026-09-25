<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Case-insensitive substring search across columns for list pages.
 *
 * Uses `whereLike` so PostgreSQL gets ILIKE, and escapes LIKE wildcards so
 * `%` and `_` in the search box match literally.
 */
trait SearchesColumns
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     */
    protected function searchColumns(Builder $query, ?string $term, array $columns): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $query->where(function (Builder $query) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $query->orWhereLike($column, $pattern);
            }
        });
    }
}
