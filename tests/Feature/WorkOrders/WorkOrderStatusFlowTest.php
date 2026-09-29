<?php

use App\Actions\Attachments\AddAttachment;
use App\Actions\WorkOrders\TransitionWorkOrder;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\States\WorkOrder\WorkOrderStatus;
use App\States\WorkOrder\WorkOrderTransition;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

/*
| The status flow of FLOW.md §5 beyond who may make each change (see
| WorkOrderTransitionMatrixTest): status properties (§5.3), notes,
| cancellation (§5.2), resubmission, returning for revision, attachments per
| status, comments on closed work orders, and what the detail page,
| timeline, and Riwayat show.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->travelTo(Carbon::parse('2026-09-25 02:00', 'UTC'));

    $this->requesterDepartment = Department::factory()->client()->create(['code' => 'PRD']);
    $this->ops = Department::factory()->create(['code' => 'OPS']);
    $role = fn (string $role): User => User::factory()->for($this->ops)->create()->assignRole($role);

    $this->adminWo = $role('admin-wo');
    $this->lead = $role('lead-operational');
    $this->pic = $role('pic-timesheet');
    $this->rental = $role('rental');
    $this->direktur = $role('direktur');
    $this->finance = $role('finance');
    $this->viewer = $role('viewer');
    $this->workOrder = WorkOrder::factory()->by($this->adminWo)->requestedBy($this->requesterDepartment)->create();
});

/**
 * Posts a status change as the given user.
 */
function moveTo(User $user, WorkOrder $workOrder, string $status, ?string $note = null): TestResponse
{
    return test()->actingAs($user)->post(route('work-orders.transitions.store', $workOrder), array_filter(['status' => $status, 'note' => $note]));
}

/**
 * A work order of the test's Admin WO in the given factory state.
 */
function flowWorkOrder(string $state): WorkOrder
{
    $factory = WorkOrder::factory()->by(test()->adminWo)->requestedBy(test()->requesterDepartment);

    return $state === 'draft' ? $factory->create() : $factory->{$state}()->create();
}

it('sets every status property per FLOW.md §5.3', function () {
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
            'number' => $state?->assignsNumber(),
            'files' => array_map(fn (Permission $permission): string => $permission->value, $state?->attachmentPermissions() ?? []),
            'waits' => array_map(fn (Permission $permission): string => $permission->value, $state?->waitsOn() ?? []),
        ]];
    });

    // Closed as a new work order, i.e. not billed yet (Belum ditagih).
    expect($properties->all())->toBe([
        'draft' => ['tone' => 'secondary', 'active' => false, 'editable' => true, 'deletable' => true, 'comments' => true, 'deadline' => null, 'numbered' => false, 'number' => false, 'files' => ['dokumen' => 'work-orders.update'], 'waits' => ['work-orders.submit']],
        'diajukan' => ['tone' => 'warning', 'active' => true, 'editable' => false, 'deletable' => false, 'comments' => true, 'deadline' => 'target_date', 'numbered' => true, 'number' => true, 'files' => [], 'waits' => ['work-orders.approve']],
        'ditolak' => ['tone' => 'destructive', 'active' => true, 'editable' => true, 'deletable' => false, 'comments' => true, 'deadline' => null, 'numbered' => true, 'number' => false, 'files' => ['dokumen' => 'work-orders.update'], 'waits' => ['work-orders.submit']],
        'pelaksanaan' => ['tone' => 'info', 'active' => true, 'editable' => false, 'deletable' => false, 'comments' => true, 'deadline' => 'target_date', 'numbered' => true, 'number' => false, 'files' => ['dokumen' => 'work-orders.submit-review'], 'waits' => ['work-orders.submit-review']],
        'review_dokumen' => ['tone' => 'review', 'active' => true, 'editable' => false, 'deletable' => false, 'comments' => true, 'deadline' => 'target_date', 'numbered' => true, 'number' => false, 'files' => [], 'waits' => ['work-orders.review']],
        'approval_bast' => ['tone' => 'approval', 'active' => true, 'editable' => false, 'deletable' => false, 'comments' => true, 'deadline' => null, 'numbered' => true, 'number' => false, 'files' => [], 'waits' => ['work-orders.approve-bast']],
        'bast_disetujui' => ['tone' => 'approved', 'active' => true, 'editable' => false, 'deletable' => false, 'comments' => true, 'deadline' => null, 'numbered' => true, 'number' => false, 'files' => [], 'waits' => ['work-orders.close']],
        'closed' => ['tone' => 'success', 'active' => false, 'editable' => false, 'deletable' => false, 'comments' => true, 'deadline' => 'payment_due_date', 'numbered' => true, 'number' => false, 'files' => [], 'waits' => ['work-orders.bill']],
        'dibatalkan' => ['tone' => 'muted', 'active' => false, 'editable' => false, 'deletable' => false, 'comments' => false, 'deadline' => null, 'numbered' => false, 'number' => false, 'files' => [], 'waits' => []],
    ]);
});

it('defines the transitions of FLOW.md §5.1 and §5.2, with their notes', function () {
    $transitions = collect(WorkOrderStatus::flowOrder())->mapWithKeys(fn (string $from): array => [
        $from => collect(WorkOrderStatus::fromName($from)::transitions())->mapWithKeys(fn (WorkOrderTransition $transition): array => [
            $transition->toName() => [$transition->permission->value, $transition->label, $transition->requiresNote ? $transition->noteLabel : null],
        ])->all(),
    ]);

    expect($transitions->all())->toBe([
        'draft' => [
            'diajukan' => ['work-orders.submit', 'Ajukan', null],
            'dibatalkan' => ['work-orders.cancel', 'Batalkan', 'Alasan pembatalan'],
        ],
        'diajukan' => [
            'pelaksanaan' => ['work-orders.approve', 'Setujui', null],
            'ditolak' => ['work-orders.approve', 'Tolak', 'Alasan penolakan'],
            'dibatalkan' => ['work-orders.cancel', 'Batalkan', 'Alasan pembatalan'],
        ],
        'ditolak' => [
            'diajukan' => ['work-orders.submit', 'Ajukan ulang', null],
            'dibatalkan' => ['work-orders.cancel', 'Batalkan', 'Alasan pembatalan'],
        ],
        'pelaksanaan' => [
            'review_dokumen' => ['work-orders.submit-review', 'Ajukan review dokumen', null],
            'dibatalkan' => ['work-orders.cancel-execution', 'Batalkan', 'Alasan pembatalan'],
        ],
        'review_dokumen' => [
            'pelaksanaan' => ['work-orders.review', 'Kembalikan untuk revisi', 'Data yang perlu dilengkapi'],
            'approval_bast' => ['work-orders.review', 'Ajukan BAST', null],
            'dibatalkan' => ['work-orders.cancel-execution', 'Batalkan', 'Alasan pembatalan'],
        ],
        'approval_bast' => [
            'bast_disetujui' => ['work-orders.approve-bast', 'Setujui BAST', null],
        ],
        'bast_disetujui' => [
            'closed' => ['work-orders.close', 'Tutup work order', null],
        ],
        'closed' => [],
        'dibatalkan' => [],
    ]);
});

it('guards every transition with an internal-only permission', function () {
    expect(WorkOrderStatus::transitionPermissions())->each(fn ($permission) => $permission->isInternalOnly()->toBeTrue());
});

it('calls a status active exactly when it is submitted and not final', function (string $status) {
    $state = WorkOrderStatus::fromName($status);
    $isFinal = WorkOrderStatus::config()->transitionableStates($status) === [];

    expect($state?->isActive())->toBe($state?->requiresNumber() && ! $isFinal);
})->with(fn (): array => WorkOrderStatus::flowOrder());

it('refuses a work order without a number in every status after the first submission', function (string $status) {
    $factory = WorkOrder::factory()->by($this->adminWo);

    expect(fn () => DB::transaction(fn () => $factory->create(['status' => $status, 'number' => null])))
        ->toThrow(QueryException::class, 'work_orders_submitted_number_check');
})->with(fn (): array => array_values(array_filter(
    WorkOrderStatus::flowOrder(),
    fn (string $status): bool => (bool) WorkOrderStatus::fromName($status)?->requiresNumber(),
)));

describe('notes', function () {
    it('requires a note where FLOW.md says so, on the request and in the action', function (string $state, string $to) {
        $workOrder = flowWorkOrder($state);
        $from = $workOrder->status->getValue();
        $admin = adminUser();

        moveTo($admin, $workOrder, $to)->assertSessionHasErrors(['note' => 'Catatan wajib diisi.']);

        expect(fn () => app(TransitionWorkOrder::class)->handle($workOrder, $to, $admin, '  '))->toThrow(ValidationException::class)
            ->and($workOrder->refresh()->status->getValue())->toBe($from)
            ->and($workOrder->statusHistories()->where('to_status', $to)->exists())->toBeFalse();
    })->with([
        'reject' => ['submitted', 'ditolak'],
        'return for revision' => ['inReview', 'pelaksanaan'],
        'cancel a draft' => ['draft', 'dibatalkan'],
        'cancel a submitted work order' => ['submitted', 'dibatalkan'],
        'cancel a rejected work order' => ['rejected', 'dibatalkan'],
        'cancel during Pelaksanaan' => ['inProgress', 'dibatalkan'],
        'cancel during Review Dokumen' => ['inReview', 'dibatalkan'],
    ]);

    it('approves with or without a note', function (?string $note) {
        $workOrder = flowWorkOrder('submitted');

        moveTo($this->lead, $workOrder, 'pelaksanaan', $note)->assertSessionHasNoErrors();

        expect($workOrder->refresh()->status->getValue())->toBe('pelaksanaan')
            ->and($workOrder->statusHistories()->where('to_status', 'pelaksanaan')->sole()->note)->toBe($note);
    })->with(['without' => [null], 'with' => ['Dijadwalkan besok pagi.']]);
});

describe('cancellation (FLOW.md §5.2)', function () {
    it('lets Admin WO cancel only before execution, and Lead Operational only during it', function (string $state, bool $adminWo, bool $lead) {
        foreach (['adminWo' => $adminWo, 'lead' => $lead] as $user => $allowed) {
            $workOrder = flowWorkOrder($state);

            $response = moveTo($this->{$user}, $workOrder, 'dibatalkan', 'Tidak diperlukan lagi.');

            // Lead Operational does not see drafts at all (FLOW.md §6).
            $allowed
                ? $response->assertSessionHasNoErrors()->assertRedirect()
                : $response->assertStatus($state === 'draft' ? 404 : 403);
            expect($workOrder->refresh()->status->getValue() === 'dibatalkan')->toBe($allowed, "{$user} cancels {$state}");
        }
    })->with([
        'Draft' => ['draft', true, false],
        'Diajukan' => ['submitted', true, false],
        'Ditolak' => ['rejected', true, false],
        'Pelaksanaan' => ['inProgress', false, true],
        'Review Dokumen' => ['inReview', false, true],
    ]);

    it('never cancels from Approval BAST onwards, not even the admin', function (string $state) {
        $workOrder = flowWorkOrder($state);
        $from = $workOrder->status->getValue();

        moveTo(adminUser(), $workOrder, 'dibatalkan', 'Tidak jadi.')->assertSessionHasErrors('status');

        expect($workOrder->refresh()->status->getValue())->toBe($from);
    })->with(['awaitingBastApproval', 'bastApproved', 'closed', 'billed', 'paid']);

    it('re-checks the permission under the lock when the status changed since the page loaded', function () {
        $stale = flowWorkOrder('submitted');
        // Lead Operational approves it while the Admin WO's page still shows Diajukan.
        app(TransitionWorkOrder::class)->handle(WorkOrder::find($stale->id), 'pelaksanaan', $this->lead);

        // Dibatalkan is allowed from Pelaksanaan too, but only with cancel-execution.
        expect(fn () => app(TransitionWorkOrder::class)->handle($stale, 'dibatalkan', $this->adminWo, 'Tidak jadi.'))
            ->toThrow(CouldNotPerformTransition::class);

        expect($stale->refresh()->status->getValue())->toBe('pelaksanaan');
    });

    it('keeps the number of a work order cancelled during execution', function () {
        $workOrder = flowWorkOrder('inProgress');
        $number = $workOrder->number;

        moveTo($this->lead, $workOrder, 'dibatalkan', 'Proyek dihentikan IC.')->assertSessionHasNoErrors();

        expect($workOrder->refresh())->number->toBe($number)->status->getValue()->toBe('dibatalkan');
    });
});

describe('resubmission', function () {
    it('keeps the number and does not advance the counter', function () {
        moveTo($this->adminWo, $this->workOrder, 'diajukan')->assertSessionHasNoErrors();
        moveTo($this->lead, $this->workOrder, 'ditolak', 'Salah kategori.')->assertSessionHasNoErrors();
        moveTo($this->adminWo, $this->workOrder, 'diajukan')
            ->assertInertiaFlash('toast.message', 'Status work order WO/PRD/2026/09/0001 sekarang Diajukan.');

        $next = WorkOrder::factory()->by($this->adminWo)->requestedBy($this->requesterDepartment)->create();
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
        $action->handle($this->workOrder, 'ditolak', $this->lead, 'Salah kategori, pilih Perbaikan.');

        $this->actingAs($this->adminWo)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('transitions.0.value', 'diajukan')
                ->where('transitions.0.label', 'Ajukan ulang')
                ->where('transitions.1.value', 'dibatalkan')
                ->where('waitingFor', null)
                ->where('statusNote', [
                    'label' => 'Alasan penolakan',
                    'note' => 'Salah kategori, pilih Perbaikan.',
                    'user' => $this->lead->name,
                    'created_at' => '2026-09-25T02:05:00+00:00',
                ])
                ->where('can.update', true)
                ->where('can.delete', false));
    });
});

describe('returning for revision', function () {
    it('sends the work order back to Pelaksanaan and shows why', function () {
        $workOrder = flowWorkOrder('inReview');
        $number = $workOrder->number;

        moveTo($this->rental, $workOrder, 'pelaksanaan', 'Foto sesudah pekerjaan belum ada.')
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', "Status work order {$number} sekarang Pelaksanaan.");

        $this->actingAs($this->pic)
            ->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('workOrder.number', $number)
                ->where('transitions', fn ($options): bool => collect($options)->pluck('value')->all() === ['review_dokumen'])
                ->where('statusNote.label', 'Data yang perlu dilengkapi')
                ->where('statusNote.note', 'Foto sesudah pekerjaan belum ada.'));
    });

    it('shows no note when Lead Operational approved it into Pelaksanaan', function () {
        $workOrder = flowWorkOrder('submitted');
        app(TransitionWorkOrder::class)->handle($workOrder, 'pelaksanaan', $this->lead, 'Mulai besok.');

        $this->actingAs($this->pic)
            ->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page->where('statusNote', null));
    });
});

describe('revising a rejected work order', function () {
    beforeEach(function () {
        $this->rejected = flowWorkOrder('rejected');
        $this->payload = fn (array $overrides = []): array => [
            'title' => $this->rejected->title,
            'work_order_category_id' => $this->rejected->work_order_category_id,
            'urgency' => 'normal',
            'requester_name' => $this->rejected->requester_name,
            ...$overrides,
        ];
    });

    it('lets Admin WO edit it', function () {
        $this->actingAs($this->adminWo)
            ->put(route('work-orders.update', $this->rejected), ($this->payload)(['title' => 'Direvisi']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('work-orders.show', $this->rejected));

        expect($this->rejected->refresh()->title)->toBe('Direvisi');
    });

    it('refuses edits by Lead Operational', function () {
        $this->actingAs($this->lead)
            ->put(route('work-orders.update', $this->rejected), ($this->payload)(['title' => 'Diubah']))
            ->assertForbidden();
    });

    it('refuses edits after submission', function (string $state) {
        $workOrder = flowWorkOrder($state);

        $this->actingAs($this->adminWo)
            ->put(route('work-orders.update', $workOrder), ($this->payload)(['work_order_category_id' => $workOrder->work_order_category_id]))
            ->assertForbidden();
    })->with(['submitted', 'inProgress', 'inReview', 'awaitingBastApproval', 'bastApproved', 'closed']);

    it('never deletes a rejected work order', function () {
        $this->actingAs(adminUser())
            ->delete(route('work-orders.destroy', $this->rejected))
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error');

        expect($this->rejected->refresh()->trashed())->toBeFalse();
    });
});

describe('attachments', function () {
    it('lets only the holder of the status\'s permission add documents', function (string $state, array $allowed) {
        $workOrder = flowWorkOrder($state);

        foreach (['adminWo', 'lead', 'pic', 'rental', 'direktur', 'finance'] as $user) {
            expect($this->{$user}->can('addAttachment', [$workOrder, WorkOrder::DOCUMENTS]))->toBe(in_array($user, $allowed, true), "{$user} in {$state}");
        }
    })->with([
        'Draft' => ['draft', ['adminWo']],
        'Diajukan' => ['submitted', []],
        'Ditolak' => ['rejected', ['adminWo']],
        'Pelaksanaan' => ['inProgress', ['pic']],
        'Review Dokumen' => ['inReview', []],
        'Approval BAST' => ['awaitingBastApproval', []],
        'BAST Disetujui' => ['bastApproved', []],
        'Closed' => ['closed', []],
        'Dibatalkan' => ['cancelled', []],
    ]);

    it('changes only the collections the status names', function () {
        $invoiceFile = new Media()->forceFill(['collection_name' => WorkOrder::INVOICE, 'uploaded_by' => $this->adminWo->id]);

        expect($this->adminWo->can('addAttachment', [$this->workOrder, WorkOrder::INVOICE]))->toBeFalse()
            ->and($this->adminWo->can('deleteAttachment', [$this->workOrder, $invoiceFile]))->toBeFalse();
    });

    it('lets PIC Timesheet upload during Pelaksanaan and remove only their own uploads', function () {
        Storage::fake('attachments');
        $workOrder = flowWorkOrder('inProgress');
        $colleague = User::factory()->for($this->ops)->create()->assignRole('pic-timesheet');
        $requesterFile = app(AddAttachment::class)->handle($workOrder, $workOrder->documentsCollection(), attachmentUpload('dokumen.pdf'), $this->adminWo);

        $this->actingAs($this->pic)
            ->post(route('attachments.store', ['work-order', $workOrder->id, 'dokumen']), ['file' => attachmentUpload('foto.jpg')])
            ->assertSessionHasNoErrors();
        $own = $workOrder->attachmentsIn('dokumen')->firstWhere('uploaded_by', $this->pic->id);

        $this->actingAs($colleague)->delete(route('attachments.destroy', $own))->assertForbidden();
        $this->actingAs($this->pic)->delete(route('attachments.destroy', $requesterFile))->assertForbidden();
        $this->actingAs($this->adminWo)->delete(route('attachments.destroy', $own))->assertForbidden();

        $this->actingAs($this->pic)
            ->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('attachments.can.upload', true)
                ->where('attachments.items', fn ($items): bool => collect($items)->pluck('can_delete', 'id')->all() === [
                    $requesterFile->uuid => false,
                    $own->uuid => true,
                ]));

        $this->actingAs($this->pic)->delete(route('attachments.destroy', $own))->assertRedirect();

        expect($workOrder->attachmentsIn('dokumen')->pluck('id')->all())->toBe([$requesterFile->id]);
    });
});

describe('comments on closed work orders', function () {
    it('stay open while billing is under way and become read-only once paid', function (string $state, bool $open) {
        $workOrder = flowWorkOrder($state);

        $this->actingAs($this->finance)
            ->post(route('work-orders.comments.store', $workOrder), ['body' => '<p>Invoice dikirim hari ini.</p>'])
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', $open ? 'success' : 'error');

        expect($workOrder->comments()->exists())->toBe($open);

        $this->actingAs($this->finance)
            ->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('can.comment', $open)
                ->where('comments.read_only', ! $open));
    })->with([
        'Belum ditagih' => ['closed', true],
        'Ditagih' => ['billed', true],
        'Lunas' => ['paid', false],
    ]);
});

describe('detail page', function () {
    it('tells each user who the work order waits for', function (string $state, string $viewer, ?string $waitingFor, array $transitions) {
        $workOrder = flowWorkOrder($state);

        $this->actingAs($this->{$viewer})
            ->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('waitingFor', $waitingFor)
                ->where('transitions', fn ($options): bool => collect($options)->pluck('value')->all() === $transitions));
    })->with([
        'Draft, Admin WO' => ['draft', 'adminWo', null, ['diajukan', 'dibatalkan']],
        'Diajukan, Lead Operational' => ['submitted', 'lead', null, ['pelaksanaan', 'ditolak']],
        'Diajukan, Admin WO' => ['submitted', 'adminWo', 'Menunggu persetujuan Lead Operational.', ['dibatalkan']],
        'Diajukan, Viewer' => ['submitted', 'viewer', 'Menunggu persetujuan Lead Operational.', []],
        'Ditolak, Lead Operational' => ['rejected', 'lead', 'Menunggu Admin WO merevisi dan mengajukan ulang.', []],
        'Ditolak, Admin WO' => ['rejected', 'adminWo', null, ['diajukan', 'dibatalkan']],
        'Pelaksanaan, PIC Timesheet' => ['inProgress', 'pic', null, ['review_dokumen']],
        'Pelaksanaan, Lead Operational' => ['inProgress', 'lead', 'Sedang dilaksanakan. Menunggu PIC Timesheet mengajukan review dokumen.', ['dibatalkan']],
        'Pelaksanaan, Admin WO' => ['inProgress', 'adminWo', 'Sedang dilaksanakan. Menunggu PIC Timesheet mengajukan review dokumen.', []],
        'Review Dokumen, Rental' => ['inReview', 'rental', null, ['pelaksanaan', 'approval_bast']],
        'Review Dokumen, PIC Timesheet' => ['inReview', 'pic', 'Menunggu review dokumen oleh Rental.', []],
        'Review Dokumen, Lead Operational' => ['inReview', 'lead', 'Menunggu review dokumen oleh Rental.', ['dibatalkan']],
        'Approval BAST, Direktur' => ['awaitingBastApproval', 'direktur', null, ['bast_disetujui']],
        'Approval BAST, Rental' => ['awaitingBastApproval', 'rental', 'Menunggu persetujuan BAST oleh Direktur.', []],
        'BAST Disetujui, Admin WO' => ['bastApproved', 'adminWo', null, ['closed']],
        'BAST Disetujui, Direktur' => ['bastApproved', 'direktur', 'Menunggu Admin WO menutup work order.', []],
        'Closed, Finance' => ['closed', 'finance', null, []],
        'Closed, Admin WO' => ['closed', 'adminWo', 'Menunggu Finance menerbitkan invoice.', []],
        'Ditagih, Finance' => ['billed', 'finance', null, []],
        'Ditagih, Viewer' => ['billed', 'viewer', 'Menunggu konfirmasi pembayaran oleh Finance.', []],
        'Lunas, Viewer' => ['paid', 'viewer', null, []],
        'Dibatalkan, Admin WO' => ['cancelled', 'adminWo', null, []],
    ]);

    it('describes each button and its note', function () {
        $workOrder = flowWorkOrder('inReview');

        $this->actingAs($this->rental)
            ->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('transitions', [
                    ['value' => 'pelaksanaan', 'label' => 'Kembalikan untuk revisi', 'destructive' => true, 'requires_note' => true, 'note_label' => 'Data yang perlu dilengkapi'],
                    ['value' => 'approval_bast', 'label' => 'Ajukan BAST', 'destructive' => false, 'requires_note' => false, 'note_label' => 'Catatan'],
                ]));
    });
});

it('walks the whole flow and shows it in the timeline and the Riwayat', function () {
    $action = app(TransitionWorkOrder::class);
    $steps = [
        ['diajukan', $this->adminWo, null],
        ['ditolak', $this->lead, 'Salah kategori.'],
        ['diajukan', $this->adminWo, null],
        ['pelaksanaan', $this->lead, 'Mulai besok.'],
        ['review_dokumen', $this->pic, null],
        ['pelaksanaan', $this->rental, 'Foto belum ada.'],
        ['review_dokumen', $this->pic, null],
        ['approval_bast', $this->rental, null],
        ['bast_disetujui', $this->direktur, null],
        ['closed', $this->adminWo, null],
    ];

    foreach ($steps as [$to, $user, $note]) {
        $this->travel(1)->minutes();
        $action->handle($this->workOrder, $to, $user, $note);
    }

    $this->actingAs($this->adminWo)
        ->get(route('work-orders.show', $this->workOrder))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('timeline', fn ($entries): bool => collect($entries)->map(fn (array $entry): array => [
                $entry['to']['label'], $entry['user']['name'], $entry['note'],
            ])->all() === [
                ['Diajukan', $this->adminWo->name, null],
                ['Ditolak', $this->lead->name, 'Salah kategori.'],
                ['Diajukan', $this->adminWo->name, null],
                ['Pelaksanaan', $this->lead->name, 'Mulai besok.'],
                ['Review Dokumen', $this->pic->name, null],
                ['Pelaksanaan', $this->rental->name, 'Foto belum ada.'],
                ['Review Dokumen', $this->pic->name, null],
                ['Approval BAST', $this->rental->name, null],
                ['BAST Disetujui', $this->direktur->name, null],
                ['Closed', $this->adminWo->name, null],
            ])
            ->where('paymentStatus', ['value' => 'belum_ditagih', 'label' => 'Belum ditagih', 'tone' => 'secondary']));

    $this->actingAs(adminUser())
        ->getJson(route('admin.activity-log.history', ['work-order', $this->workOrder->id]))
        ->assertOk()
        ->assertJsonPath('data.0.changes.0', ['field' => 'status', 'label' => 'Status', 'old' => 'BAST Disetujui', 'new' => 'Closed'])
        ->assertJsonPath('data.4.changes.0', ['field' => 'status', 'label' => 'Status', 'old' => 'Review Dokumen', 'new' => 'Pelaksanaan']);
});
