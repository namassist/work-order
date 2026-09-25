<?php

namespace App\Http\Resources;

use App\Enums\AuditEvent;
use App\Enums\AuditSubject;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * An activity log entry in a readable form: labelled fields, formatted
 * values, and the subject and causer by name.
 *
 * @property Activity $resource
 */
class ActivityResource extends JsonResource
{
    /**
     * Display labels for logged attributes; unknown attributes show their key.
     *
     * @var array<string, string>
     */
    private const array FIELD_LABELS = [
        'name' => 'Nama',
        'code' => 'Kode',
        'email' => 'Email',
        'description' => 'Deskripsi',
        'is_active' => 'Status',
        'department_id' => 'Departemen',
        'must_change_password' => 'Wajib ganti password',
        'roles' => 'Role',
        'permissions' => 'Izin',
    ];

    /**
     * @param  array<int, string>  $departmentCodes  department id => code, see departmentCodes()
     */
    public function __construct(Activity $resource, private readonly array $departmentCodes = [])
    {
        parent::__construct($resource);
    }

    /**
     * Activity with what this resource renders (subject, causer including
     * deleted users) eager loaded, newest first.
     *
     * @return Builder<Activity>
     */
    public static function query(): Builder
    {
        return Activity::query()
            ->with([
                'subject',
                'causer' => fn (Relation $causer) => $causer->withoutGlobalScope(SoftDeletingScope::class),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Load the codes of every department referenced by the entries at once.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array<int, string>
     */
    public static function departmentCodes(Collection $activities): array
    {
        $ids = $activities
            ->flatMap(fn (Activity $activity): array => [
                data_get($activity->attribute_changes, 'attributes.department_id'),
                data_get($activity->attribute_changes, 'old.department_id'),
            ])
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Department::withTrashed()->whereKey($ids)->pluck('code', 'id')->all();
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activity = $this->resource;

        return [
            'id' => $activity->id,
            'log_name' => $activity->log_name,
            'event' => $activity->event,
            'event_label' => AuditEvent::tryFrom((string) $activity->event)?->label() ?? $activity->event,
            'subject' => $this->subject(),
            'causer' => $activity->causer instanceof User
                ? ['id' => $activity->causer->id, 'name' => $activity->causer->name]
                : null,
            'changes' => $this->changes(),
            'properties' => $activity->properties?->all() ?? [],
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{type: string, type_label: string, id: int|string|null, label: string}|null
     */
    private function subject(): ?array
    {
        $activity = $this->resource;

        if ($activity->subject_type === null) {
            return null;
        }

        return [
            'type' => $activity->subject_type,
            'type_label' => AuditSubject::tryFrom($activity->subject_type)?->label() ?? $activity->subject_type,
            'id' => $activity->subject_id,
            'label' => $this->subjectLabel($activity->subject)
                ?? data_get($activity->attribute_changes, 'attributes.name')
                ?? data_get($activity->attribute_changes, 'old.name')
                ?? '#'.$activity->subject_id,
        ];
    }

    /**
     * The code for master data, otherwise the name.
     */
    private function subjectLabel(?Model $subject): ?string
    {
        $label = $subject?->getAttribute('code') ?? $subject?->getAttribute('name');

        return is_string($label) ? $label : null;
    }

    /**
     * One row per changed attribute, in logged order.
     *
     * @return list<array{field: string, label: string, old: mixed, new: mixed}>
     */
    private function changes(): array
    {
        $new = (array) data_get($this->resource->attribute_changes, 'attributes', []);
        $old = (array) data_get($this->resource->attribute_changes, 'old', []);

        return array_map(fn (string $field): array => [
            'field' => $field,
            'label' => self::FIELD_LABELS[$field] ?? $field,
            'old' => $this->formatValue($field, $old[$field] ?? null),
            'new' => $this->formatValue($field, $new[$field] ?? null),
        ], array_values(array_unique([...array_keys($new), ...array_keys($old)])));
    }

    private function formatValue(string $field, mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            $field === 'is_active' => $value ? 'Aktif' : 'Nonaktif',
            $field === 'department_id' => $this->departmentCodes[$value] ?? '#'.$value,
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            default => $value,
        };
    }
}
