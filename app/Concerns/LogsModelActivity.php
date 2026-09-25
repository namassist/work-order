<?php

namespace App\Concerns;

use App\Enums\AuditEvent;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Audit trail conventions shared by every model: log create, update, delete,
 * and restore with only the fillable attributes that changed.
 *
 * Secrets are excluded globally in config/activitylog.php. Models override
 * activityLogOptions() to drop more attributes or ignore housekeeping writes.
 */
trait LogsModelActivity
{
    use LogsActivity;

    /**
     * The options used by the activity logger.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return $this->activityLogOptions(
            LogOptions::defaults()
                ->logFillable()
                ->logOnlyDirty()
                ->dontLogEmptyChanges()
                ->dontLogIfAttributesChangedOnly(['updated_at']),
        );
    }

    /**
     * Adjust the shared options for this model.
     */
    protected function activityLogOptions(LogOptions $options): LogOptions
    {
        return $options;
    }

    /**
     * Name an update that flips is_active as activated or deactivated so the
     * log can be filtered by it. The entry keeps every attribute that changed.
     */
    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $isActive = data_get($activity->attribute_changes, 'attributes.is_active');

        if ($eventName !== AuditEvent::Updated->value || $isActive === null) {
            return;
        }

        $activity->event = ($isActive ? AuditEvent::Activated : AuditEvent::Deactivated)->value;
        $activity->description = $activity->event;
    }
}
