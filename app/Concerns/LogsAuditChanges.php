<?php

namespace App\Concerns;

use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Logs changes that model events cannot see (pivot syncs such as roles and
 * permissions) in the same before/after shape as LogsModelActivity.
 */
trait LogsAuditChanges
{
    /**
     * Log only the keys whose value differs. Nothing is logged when no key
     * changed. Pass an empty $old for a creation and an empty $new for a deletion.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function logAuditChange(Model $subject, AuditEvent $event, array $old, array $new): void
    {
        $changedKeys = array_flip(array_filter(
            array_unique([...array_keys($new), ...array_keys($old)]),
            fn (string $key): bool => ($old[$key] ?? null) !== ($new[$key] ?? null),
        ));

        if ($changedKeys === []) {
            return;
        }

        activity()
            ->performedOn($subject)
            ->event($event->value)
            ->withChanges(array_filter([
                'attributes' => array_intersect_key($new, $changedKeys),
                'old' => array_intersect_key($old, $changedKeys),
            ], fn (array $values): bool => $values !== []))
            ->log($event->value);
    }
}
