<?php

use App\Actions\Attachments\AddAttachment;
use App\Actions\WorkOrders\TransitionWorkOrder;
use App\Enums\WorkOrderSide;
use App\Models\Department;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\States\WorkOrder\WorkOrderStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| The status flow of FLOW.md §5 beyond who may make each change (see
| WorkOrderTransitionMatrixTest): status properties, notes, resubmission,
| revising a rejected work order, attachments per status, and what the
| detail page, timeline, and Riwayat show.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->travelTo(Carbon::parse('2026-09-25 02:00', 'UTC'));

    $this->requesterDepartment = Department::factory()->client()->create(['code' => 'PRD']);
    $this->target = Department::factory()->create(['code' => 'ENG']);
    $this->adminWo = User::factory()->for(Department::factory()->create(['code' => 'OPS']))->create()->assignRole('admin-wo');
    $this->lead = User::factory()->for($this->target)->create()->assignRole('lead-operational');
    $this->workOrder = WorkOrder::factory()->by($this->adminWo)->requestedBy($this->requesterDepartment)->targeting($this->target)->create();
});

/**
 * Posts a status change as the given user.
 */
function moveTo(User $user, WorkOrder $workOrder, string $status, ?string $note = null): TestResponse
{
    return test()->actingAs($user)->post(route('work-orders.transitions.store', $workOrder), array_filter(['status' => $status, 'note' => $note]));
}

it('sets every status property per FLOW.md §5', function () {
    $properties = collect(WorkOrderStatus::options())->mapWithKeys(function (array $option): array {
        $state = WorkOrderStatus::fromName($option['value']);

        return [$option['value'] => [
            'tone' => $state?->tone(),
            'active' => $state?->isActive(),
            'editable' => $state?->isEditable(),
            'deletable' => $state?->isDeletable(),
            'comments' => $state?->acceptsComments(),
            'deadline' => $state?->deadline()?->value,
            'numbered' => $state?->requiresNumber(),
            'note' => $state?->requiresNote(),
            'number' => $state?->assignsNumber(),
            'attachments' => array_map(fn (WorkOrderSide $side): string => $side->value, $state?->attachmentSides() ?? []),
            'by' => $state?->performedBy()?->value,
            'waits' => $state?->waitsOn()?->value,
            'form' => $state?->transitionForm(),
        ]];
    });

    expect($properties->all())->toBe([
        'draft' => ['tone' => 'secondary', 'active' => false, 'editable' => true, 'deletable' => true, 'comments' => true, 'deadline' => null, 'numbered' => false, 'note' => false, 'number' => false, 'attachments' => ['dokumen' => 'requester'], 'by' => null, 'waits' => 'requester', 'form' => null],
        'diajukan' => ['tone' => 'warning', 'active' => true, 'editable' => false, 'deletable' => false, 'comments' => true, 'deadline' => 'target_date', 'numbered' => true, 'note' => false, 'number' => true, 'attachments' => [], 'by' => 'requester', 'waits' => 'executor', 'form' => null],
        'ditolak' => ['tone' => 'destructive', 'active' => true, 'editable' => true, 'deletable' => false, 'comments' => true, 'deadline' => null, 'numbered' => true, 'note' => true, 'number' => false, 'attachments' => ['dokumen' => 'requester'], 'by' => 'executor', 'waits' => 'requester', 'form' => null],
        'dikerjakan' => ['tone' => 'info', 'active' => true, 'editable' => false, 'deletable' => false, 'comments' => true, 'deadline' => 'target_date', 'numbered' => true, 'note' => false, 'number' => false, 'attachments' => ['dokumen' => 'executor', 'bast' => 'executor'], 'by' => 'executor', 'waits' => 'executor', 'form' => null],
        'penagihan' => ['tone' => 'billing', 'active' => true, 'editable' => false, 'deletable' => false, 'comments' => true, 'deadline' => 'payment_due_date', 'numbered' => true, 'note' => false, 'number' => false, 'attachments' => ['bukti_bayar' => 'finance'], 'by' => 'executor', 'waits' => 'finance', 'form' => 'invoice'],
        'selesai' => ['tone' => 'success', 'active' => false, 'editable' => false, 'deletable' => false, 'comments' => false, 'deadline' => null, 'numbered' => true, 'note' => false, 'number' => false, 'attachments' => [], 'by' => 'finance', 'waits' => null, 'form' => 'payment'],
        'dibatalkan' => ['tone' => 'muted', 'active' => false, 'editable' => false, 'deletable' => false, 'comments' => false, 'deadline' => null, 'numbered' => false, 'note' => true, 'number' => false, 'attachments' => [], 'by' => 'requester', 'waits' => null, 'form' => null],
    ]);
});

it('calls a status active exactly when it is submitted and not final', function (string $status) {
    $state = WorkOrderStatus::fromName($status);
    $isFinal = WorkOrderStatus::config()->transitionableStates($status) === [];

    expect($state?->isActive())->toBe($state?->requiresNumber() && ! $isFinal);
})->with(fn (): array => WorkOrderStatus::flowOrder());

it('refuses a work order without a number in every status after the first submission', function (string $status) {
    $factory = WorkOrder::factory()->by($this->adminWo)->targeting($this->target);

    expect(fn () => DB::transaction(fn () => $factory->create(['status' => $status, 'number' => null])))
        ->toThrow(QueryException::class, 'work_orders_submitted_number_check');
})->with(fn (): array => array_values(array_filter(
    array_column(WorkOrderStatus::options(), 'value'),
    fn (string $status): bool => (bool) WorkOrderStatus::fromName($status)?->requiresNumber(),
)));

describe('notes', function () {
    it('requires a reason to reject', function () {
        $this->workOrder = WorkOrder::factory()->by($this->adminWo)->targeting($this->target)->submitted()->create();

        moveTo($this->lead, $this->workOrder, 'ditolak')->assertSessionHasErrors(['note' => 'Catatan wajib diisi.']);

        expect($this->workOrder->refresh()->status->getValue())->toBe('diajukan');
    });

    it('requires a reason to cancel a rejected work order', function () {
        $rejected = WorkOrder::factory()->by($this->adminWo)->rejected()->create();

        moveTo($this->adminWo, $rejected, 'dibatalkan')->assertSessionHasErrors('note');

        expect($rejected->refresh()->status->getValue())->toBe('ditolak');
    });

    it('accepts a work order with or without a note', function (?string $note) {
        $this->workOrder = WorkOrder::factory()->by($this->adminWo)->targeting($this->target)->submitted()->create();

        moveTo($this->lead, $this->workOrder, 'dikerjakan', $note)->assertSessionHasNoErrors();

        expect($this->workOrder->refresh()->status->getValue())->toBe('dikerjakan')
            ->and($this->workOrder->statusHistories()->where('to_status', 'dikerjakan')->sole()->note)->toBe($note);
    })->with(['without' => [null], 'with' => ['Dijadwalkan besok pagi.']]);
});

describe('resubmission', function () {
    it('keeps the number and does not advance the counter', function () {
        moveTo($this->adminWo, $this->workOrder, 'diajukan')->assertSessionHasNoErrors();
        moveTo($this->lead, $this->workOrder, 'ditolak', 'Salah departemen.')->assertSessionHasNoErrors();
        moveTo($this->adminWo, $this->workOrder, 'diajukan')
            ->assertInertiaFlash('toast.message', 'Work order WO/PRD/2026/09/0001 sekarang diajukan.');

        $next = WorkOrder::factory()->by($this->adminWo)->requestedBy($this->requesterDepartment)->targeting($this->target)->create();
        moveTo($this->adminWo, $next, 'diajukan');

        expect($this->workOrder->refresh())
            ->number->toBe('WO/PRD/2026/09/0001')
            ->status->getValue()->toBe('diajukan')
            ->and($next->refresh()->number)->toBe('WO/PRD/2026/09/0002')
            ->and($this->workOrder->statusHistories()->pluck('to_status')->all())->toBe(['diajukan', 'ditolak', 'diajukan'])
            ->and(Activity::query()->forSubject($this->workOrder)->where('event', 'status_changed')->latest('id')->first()->attribute_changes->toArray())->toBe([
                // Only what changed: the number stays.
                'attributes' => ['status' => 'diajukan'],
                'old' => ['status' => 'ditolak'],
            ]);
    });

    it('labels the resubmit button and shows the rejection reason', function () {
        $action = app(TransitionWorkOrder::class);
        $action->handle($this->workOrder, 'diajukan', $this->adminWo);
        $this->travel(5)->minutes();
        $action->handle($this->workOrder, 'ditolak', $this->lead, 'Salah departemen, ajukan ke GA.');

        $this->actingAs($this->adminWo)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('transitions.0.value', 'diajukan')
                ->where('transitions.0.label', 'Ajukan ulang')
                ->where('transitions.1.value', 'dibatalkan')
                ->where('waitingFor', null)
                ->where('statusNote', [
                    'label' => 'Alasan penolakan',
                    'note' => 'Salah departemen, ajukan ke GA.',
                    'user' => $this->lead->name,
                    'created_at' => '2026-09-25T02:05:00+00:00',
                ])
                ->where('can.update', true)
                ->where('can.delete', false));
    });
});

describe('revising a rejected work order', function () {
    beforeEach(function () {
        $this->rejected = WorkOrder::factory()->by($this->adminWo)->targeting($this->target)->rejected()->create();
        $this->payload = fn (array $overrides = []): array => [
            'title' => $this->rejected->title,
            'work_order_category_id' => $this->rejected->work_order_category_id,
            'urgency' => 'normal',
            'requester_name' => $this->rejected->requester_name,
            'target_department_id' => $this->target->id,
            ...$overrides,
        ];
    });

    it('lets the requester side change the informational target department', function () {
        $ga = Department::factory()->create(['code' => 'GA']);

        $this->actingAs($this->adminWo)
            ->put(route('work-orders.update', $this->rejected), ($this->payload)(['target_department_id' => $ga->id, 'title' => 'Direvisi']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('work-orders.show', $this->rejected));

        expect($this->rejected->refresh())
            ->target_department_id->toBe($ga->id)
            ->title->toBe('Direvisi')
            // The target no longer decides who sees it (FLOW.md v2 §6).
            ->and($this->lead->can('view', $this->rejected))->toBeTrue();
    });

    it('may clear the target department, which is informational only', function () {
        $this->actingAs($this->adminWo)
            ->put(route('work-orders.update', $this->rejected), ($this->payload)(['target_department_id' => null]))
            ->assertSessionHasNoErrors();

        expect($this->rejected->refresh()->target_department_id)->toBeNull();
    });

    it('refuses edits by Lead Operational', function () {
        $this->actingAs($this->lead)
            ->put(route('work-orders.update', $this->rejected), ($this->payload)(['title' => 'Diubah pelaksana']))
            ->assertForbidden();
    });

    it('refuses edits once submitted or in progress', function (string $state) {
        $workOrder = WorkOrder::factory()->by($this->adminWo)->targeting($this->target)->{$state}()->create();

        $this->actingAs($this->adminWo)
            ->put(route('work-orders.update', $workOrder), ($this->payload)(['work_order_category_id' => $workOrder->work_order_category_id]))
            ->assertForbidden();
    })->with(['submitted', 'inProgress']);

    it('never deletes a rejected work order', function () {
        $this->actingAs(adminUser())
            ->delete(route('work-orders.destroy', $this->rejected))
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error');

        expect($this->rejected->refresh()->trashed())->toBeFalse();
    });
});

describe('attachments', function () {
    it('lets only the side of attachmentSides() add documents', function (string $state, bool $requester, bool $executor) {
        $workOrder = WorkOrder::factory()->by($this->adminWo)->targeting($this->target)->{$state}()->create();

        expect($this->adminWo->can('addAttachment', [$workOrder, WorkOrder::DOCUMENTS]))->toBe($requester)
            ->and($this->lead->can('addAttachment', [$workOrder, WorkOrder::DOCUMENTS]))->toBe($executor);
    })->with([
        'Diajukan' => ['submitted', false, false],
        'Ditolak' => ['rejected', true, false],
        'Dikerjakan' => ['inProgress', false, true],
        'Dibatalkan' => ['cancelled', false, false],
    ]);

    it('changes only the collections the status names for the side', function () {
        $other = new Media()->forceFill(['collection_name' => 'bast', 'uploaded_by' => $this->adminWo->id]);

        expect($this->adminWo->can('addAttachment', [$this->workOrder, 'bast']))->toBeFalse()
            ->and($this->adminWo->can('deleteAttachment', [$this->workOrder, $other]))->toBeFalse();
    });

    it('lets the requester side add documents to a draft', function () {
        expect($this->adminWo->can('addAttachment', [$this->workOrder, WorkOrder::DOCUMENTS]))->toBeTrue();
    });

    it('lets Lead Operational upload while in progress and remove only their own uploads', function () {
        Storage::fake('attachments');
        $workOrder = WorkOrder::factory()->by($this->adminWo)->targeting($this->target)->inProgress()->create();
        $colleague = User::factory()->for($this->target)->create()->assignRole('lead-operational');
        $requesterFile = app(AddAttachment::class)->handle($workOrder, $workOrder->documentsCollection(), attachmentUpload('dokumen.pdf'), $this->adminWo);

        $this->actingAs($this->lead)
            ->post(route('attachments.store', ['work-order', $workOrder->id, 'dokumen']), ['file' => attachmentUpload('foto.jpg')])
            ->assertSessionHasNoErrors();
        $own = $workOrder->attachmentsIn('dokumen')->firstWhere('uploaded_by', $this->lead->id);

        $this->actingAs($colleague)->delete(route('attachments.destroy', $own))->assertForbidden();
        $this->actingAs($this->lead)->delete(route('attachments.destroy', $requesterFile))->assertForbidden();
        $this->actingAs($this->adminWo)->delete(route('attachments.destroy', $own))->assertForbidden();

        $this->actingAs($this->lead)
            ->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('attachments.can.upload', true)
                ->where('attachments.items', fn ($items): bool => collect($items)->pluck('can_delete', 'id')->all() === [
                    $requesterFile->uuid => false,
                    $own->uuid => true,
                ]));

        $this->actingAs($this->lead)->delete(route('attachments.destroy', $own))->assertRedirect();

        expect($workOrder->attachmentsIn('dokumen')->pluck('id')->all())->toBe([$requesterFile->id]);
    });
});

describe('detail page', function () {
    it('tells each user who the work order waits for', function (string $state, string $viewer, ?string $waitingFor, array $transitions) {
        $workOrder = WorkOrder::factory()->by($this->adminWo)->targeting($this->target)->{$state}()->create();
        $users = [
            'Admin WO' => $this->adminWo,
            'Lead Operational' => $this->lead,
            'Finance' => User::factory()->for(Department::factory())->create()->assignRole('finance'),
            'Viewer' => User::factory()->for(Department::factory())->create()->assignRole('viewer'),
        ];

        $this->actingAs($users[$viewer])
            ->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('waitingFor', $waitingFor)
                ->where('transitions', fn ($options): bool => collect($options)->pluck('value')->all() === $transitions));
    })->with([
        'Diajukan, Lead Operational' => ['submitted', 'Lead Operational', null, ['dikerjakan', 'ditolak']],
        'Diajukan, Admin WO' => ['submitted', 'Admin WO', 'Menunggu persetujuan Lead Operational.', ['dibatalkan']],
        'Diajukan, Finance' => ['submitted', 'Finance', 'Menunggu persetujuan Lead Operational.', []],
        'Diajukan, Viewer' => ['submitted', 'Viewer', 'Menunggu persetujuan Lead Operational.', []],
        'Ditolak, Lead Operational' => ['rejected', 'Lead Operational', 'Menunggu Admin WO merevisi dan mengajukan ulang.', []],
        'Dikerjakan, Admin WO' => ['inProgress', 'Admin WO', 'Sedang dikerjakan.', []],
        'Dikerjakan, Lead Operational' => ['inProgress', 'Lead Operational', null, ['penagihan']],
        'Dikerjakan, Finance' => ['inProgress', 'Finance', 'Sedang dikerjakan.', []],
        'Penagihan, Admin WO' => ['billed', 'Admin WO', 'Menunggu konfirmasi pembayaran oleh Finance.', []],
        'Penagihan, Lead Operational' => ['billed', 'Lead Operational', 'Menunggu konfirmasi pembayaran oleh Finance.', []],
        'Penagihan, Finance' => ['billed', 'Finance', null, ['selesai']],
        'Selesai, Finance' => ['paid', 'Finance', null, []],
        'Dibatalkan, Admin WO' => ['cancelled', 'Admin WO', null, []],
    ]);

    it('labels the reject and cancel notes', function () {
        $this->workOrder = WorkOrder::factory()->by($this->adminWo)->targeting($this->target)->submitted()->create();

        $this->actingAs($this->lead)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('transitions', [
                    ['value' => 'dikerjakan', 'label' => 'Kerjakan', 'destructive' => false, 'requires_note' => false, 'note_label' => 'Catatan', 'form' => null, 'blocked_reason' => null],
                    ['value' => 'ditolak', 'label' => 'Tolak', 'destructive' => true, 'requires_note' => true, 'note_label' => 'Alasan penolakan', 'form' => null, 'blocked_reason' => null],
                ]));
    });
});

it('shows the new transitions in the timeline and the Riwayat', function () {
    $action = app(TransitionWorkOrder::class);
    $action->handle($this->workOrder, 'diajukan', $this->adminWo);
    $this->travel(1)->minutes();
    $action->handle($this->workOrder, 'ditolak', $this->lead, 'Salah kategori.');
    $this->travel(1)->minutes();
    $action->handle($this->workOrder, 'diajukan', $this->adminWo);
    $this->travel(1)->minutes();
    $action->handle($this->workOrder, 'dikerjakan', $this->lead, 'Mulai besok.');

    $this->actingAs($this->adminWo)
        ->get(route('work-orders.show', $this->workOrder))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('timeline', fn ($entries): bool => collect($entries)->map(fn (array $entry): array => [
                $entry['from']['label'] ?? null, $entry['to']['label'], $entry['user']['name'], $entry['note'],
            ])->all() === [
                ['Draft', 'Diajukan', $this->adminWo->name, null],
                ['Diajukan', 'Ditolak', $this->lead->name, 'Salah kategori.'],
                ['Ditolak', 'Diajukan', $this->adminWo->name, null],
                ['Diajukan', 'Dikerjakan', $this->lead->name, 'Mulai besok.'],
            ]));

    $this->actingAs(adminUser())
        ->getJson(route('admin.activity-log.history', ['work-order', $this->workOrder->id]))
        ->assertOk()
        ->assertJsonPath('data.0.changes.0', ['field' => 'status', 'label' => 'Status', 'old' => 'Diajukan', 'new' => 'Dikerjakan'])
        ->assertJsonPath('data.2.changes.0', ['field' => 'status', 'label' => 'Status', 'old' => 'Diajukan', 'new' => 'Ditolak']);
});
