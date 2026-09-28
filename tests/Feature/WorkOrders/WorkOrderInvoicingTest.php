<?php

use App\Actions\Attachments\AddAttachment;
use App\Actions\WorkOrders\ConfirmWorkOrderPayment;
use App\Actions\WorkOrders\TransitionWorkOrder;
use App\Enums\AuditEvent;
use App\Http\Resources\ActivityResource;
use App\Models\Department;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderInvoice;
use App\Support\Attachments\Attachable;
use App\Support\Attachments\AttachmentCollection;
use App\Support\Attachments\AttachmentTypeDetector;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Invoicing (FLOW.md §8): Dikerjakan → Penagihan with the invoice and its
| files, correcting the invoice while it waits for payment, Penagihan →
| Selesai with the payment date, segregation of duties, the payment due date
| as the overdue basis, and Selesai being read-only. Who may make each
| status change is in WorkOrderTransitionMatrixTest; visibility of the
| invoice files in WorkOrderVisibilityTest.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('attachments');
    // 10:00 WITA on 25 Sep.
    $this->travelTo(Carbon::parse('2026-09-25 02:00', 'UTC'));

    $this->requesterDepartment = Department::factory()->client()->create(['code' => 'PRD']);
    $this->target = Department::factory()->create(['code' => 'ENG']);
    $this->pemohon = User::factory()->for($this->requesterDepartment)->create()->assignRole('pemohon');
    $this->pelaksana = User::factory()->for($this->target)->create()->assignRole('pelaksana');
    $this->keuangan = User::factory()->for(Department::factory()->create(['code' => 'KEU']))->create()->assignRole('keuangan');
    $this->workOrder = WorkOrder::factory()->by($this->pemohon)->targeting($this->target)->inProgress()->create();
});

/**
 * A valid invoice form, with one invoice file and one BAST file.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function invoicePayload(array $overrides = []): array
{
    return [
        'invoice_number' => 'INV/ENG/2026/001',
        'invoice_date' => '2026-09-25',
        'amount' => '1500000',
        'due_date' => '2026-10-25',
        'invoice_files' => [attachmentUpload('dokumen.pdf', 'Invoice 001.pdf')],
        'bast_files' => [attachmentUpload('foto.jpg', 'BAST.jpg')],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function billAs(User $user, WorkOrder $workOrder, array $overrides = []): TestResponse
{
    return test()->actingAs($user)->post(route('work-orders.invoice.store', $workOrder), invoicePayload($overrides));
}

/**
 * @param  array<string, mixed>  $payload
 */
function payAs(User $user, WorkOrder $workOrder, array $payload = []): TestResponse
{
    return test()->actingAs($user)->post(route('work-orders.payment.store', $workOrder), ['paid_on' => '2026-09-25', ...$payload]);
}

/**
 * The test's work order, invoiced by its pelaksana through the endpoint.
 */
function billedByPelaksana(): WorkOrder
{
    billAs(test()->pelaksana, test()->workOrder)->assertSessionHasNoErrors();

    return test()->workOrder->refresh();
}

describe('invoicing (Dikerjakan → Penagihan)', function () {
    it('saves the invoice, its files, and the status change together', function () {
        billAs($this->pelaksana, $this->workOrder)
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $workOrder = $this->workOrder->refresh();

        expect($workOrder->status->getValue())->toBe('penagihan')
            ->and($workOrder->invoice)
            ->number->toBe('INV/ENG/2026/001')
            ->amount->toBe('1500000.00')
            ->paid_on->toBeNull()
            ->issued_by->toBe($this->pelaksana->id)
            ->and($workOrder->invoice->invoice_date->toDateString())->toBe('2026-09-25')
            ->and($workOrder->invoice->due_date?->toDateString())->toBe('2026-10-25')
            ->and($workOrder->attachmentsIn('invoice')->pluck('name')->all())->toBe(['Invoice 001.pdf'])
            ->and($workOrder->attachmentsIn('bast')->pluck('name')->all())->toBe(['BAST.jpg'])
            ->and($workOrder->statusHistories()->reorder()->latest('id')->first())
            ->from_status->toBe('dikerjakan')
            ->to_status->toBe('penagihan')
            ->user_id->toBe($this->pelaksana->id);

        $issued = Activity::query()->where('event', AuditEvent::InvoiceIssued->value)->sole();

        expect($issued->subject_id)->toBe($workOrder->id)
            ->and($issued->causer_id)->toBe($this->pelaksana->id)
            ->and($issued->attribute_changes?->toArray())->toBe(['attributes' => [
                'no_invoice' => 'INV/ENG/2026/001',
                'tanggal_invoice' => '2026-09-25',
                'jumlah' => '1500000.00',
                'jatuh_tempo' => '2026-10-25',
            ]]);
    });

    it('needs only the number, the date, and one invoice file', function () {
        billAs($this->pelaksana, $this->workOrder, ['amount' => null, 'due_date' => null, 'bast_files' => []])
            ->assertSessionHasNoErrors();

        expect($this->workOrder->refresh()->invoice)
            ->amount->toBeNull()
            ->due_date->toBeNull();
    });

    it('validates the invoice', function (array $overrides, string $field) {
        billAs($this->pelaksana, $this->workOrder, $overrides)->assertSessionHasErrors($field);

        expect($this->workOrder->refresh()->status->getValue())->toBe('dikerjakan')
            ->and(WorkOrderInvoice::query()->count())->toBe(0);
    })->with([
        'no number' => [['invoice_number' => ''], 'invoice_number'],
        'number too long' => [['invoice_number' => str_repeat('9', 101)], 'invoice_number'],
        'no invoice date' => [['invoice_date' => ''], 'invoice_date'],
        'invoice date tomorrow in WITA' => [['invoice_date' => '2026-09-26', 'due_date' => null], 'invoice_date'],
        'amount zero' => [['amount' => '0'], 'amount'],
        'negative amount' => [['amount' => '-5'], 'amount'],
        'three decimals' => [['amount' => '1500.125'], 'amount'],
        'comma as decimal separator' => [['amount' => '1500,50'], 'amount'],
        'thousands separators' => [['amount' => '1.500.000'], 'amount'],
        'too large' => [['amount' => '99999999999999'], 'amount'],
        'due before the invoice date' => [['due_date' => '2026-09-24'], 'due_date'],
        'no invoice file' => [['invoice_files' => []], 'invoice_files'],
        'invoice file not an allowed type' => [fn (): array => ['invoice_files' => [attachmentUpload('laporan.docx')]], 'invoice_files.0'],
        'disguised invoice file' => [fn (): array => ['invoice_files' => [attachmentUpload('program.exe', 'invoice.pdf')]], 'invoice_files.0'],
    ]);

    it('accepts two decimals and an invoice dated today in WITA while it is still yesterday in UTC', function () {
        // 07:30 WITA on 26 Sep, 23:30 UTC on 25 Sep.
        $this->travelTo(Carbon::parse('2026-09-25 23:30', 'UTC'));

        billAs($this->pelaksana, $this->workOrder, ['invoice_date' => '2026-09-26', 'amount' => '1500000.5', 'due_date' => null])
            ->assertSessionHasNoErrors();

        expect($this->workOrder->refresh()->invoice)
            ->amount->toBe('1500000.50')
            ->and($this->workOrder->invoice->invoice_date->toDateString())->toBe('2026-09-26');
    });

    it('refuses an invoice number already used, whatever its casing', function () {
        WorkOrderInvoice::factory()->create(['number' => 'inv/eng/2026/001']);

        billAs($this->pelaksana, $this->workOrder, ['invoice_number' => '  INV/ENG/2026/001 '])
            ->assertSessionHasErrors('invoice_number');

        expect($this->workOrder->refresh()->status->getValue())->toBe('dikerjakan');
    });

    it('backs the unique invoice number with the database', function () {
        WorkOrderInvoice::factory()->create(['number' => 'INV-1']);

        expect(fn () => DB::transaction(fn () => WorkOrderInvoice::factory()->create(['number' => 'inv-1'])))
            ->toThrow(QueryException::class, 'work_order_invoices_number_lower_unique');
    });

    it('leaves no invoice, status change, or file when an upload fails', function () {
        // The second upload fails inside the action, after the invoice row and the first file were written.
        app()->bind(AddAttachment::class, fn (): AddAttachment => new class(app(AttachmentTypeDetector::class)) extends AddAttachment
        {
            private int $calls = 0;

            public function handle(Model&Attachable $parent, AttachmentCollection $collection, UploadedFile $file, User $uploader, string $errorKey = 'file'): Media
            {
                if (++$this->calls === 2) {
                    throw ValidationException::withMessages([$errorKey => 'Gagal menyimpan berkas.']);
                }

                return parent::handle($parent, $collection, $file, $uploader, $errorKey);
            }
        });

        billAs($this->pelaksana, $this->workOrder)->assertSessionHasErrors(['bast_files' => 'Gagal menyimpan berkas.']);

        expect($this->workOrder->refresh()->status->getValue())->toBe('dikerjakan')
            ->and(WorkOrderInvoice::query()->count())->toBe(0)
            ->and(Media::query()->count())->toBe(0)
            ->and(Storage::disk('attachments')->allFiles())->toBe([])
            ->and($this->workOrder->statusHistories()->where('to_status', 'penagihan')->exists())->toBeFalse()
            ->and(Activity::query()->where('event', AuditEvent::InvoiceIssued->value)->exists())->toBeFalse();
    });

    it('refuses Penagihan through the plain status change', function () {
        $this->actingAs($this->pelaksana)
            ->post(route('work-orders.transitions.store', $this->workOrder), ['status' => 'penagihan'])
            ->assertSessionHasErrors(['status' => 'Status Penagihan diubah lewat formulirnya sendiri.']);

        expect($this->workOrder->refresh()->status->getValue())->toBe('dikerjakan');
    });

    it('never enters Penagihan without an invoice, or Selesai without a payment', function () {
        $billed = WorkOrder::factory()->targeting($this->target)->billed()->create();

        expect(fn () => app(TransitionWorkOrder::class)->handle($this->workOrder, 'penagihan', $this->pelaksana))
            ->toThrow(ValidationException::class)
            ->and(fn () => app(TransitionWorkOrder::class)->handle($billed, 'selesai', $this->keuangan))
            ->toThrow(ValidationException::class)
            ->and($this->workOrder->refresh()->status->getValue())->toBe('dikerjakan')
            ->and($billed->refresh()->status->getValue())->toBe('penagihan');
    });

    it('answers a page loaded before the work order was invoiced with a message', function () {
        billedByPelaksana();

        billAs($this->pelaksana, $this->workOrder, ['invoice_number' => 'INV-2'])
            ->assertSessionHasErrors(['status' => 'Status tidak dapat diubah dari Penagihan.']);

        expect(WorkOrderInvoice::query()->count())->toBe(1);
    });
});

describe('correcting the invoice', function () {
    beforeEach(function () {
        $this->billed = billedByPelaksana();
        $this->invoiceFile = $this->billed->attachmentsIn('invoice')->sole();
        $this->correct = fn (User $user, array $payload): TestResponse => $this->actingAs($user)->patch(route('work-orders.invoice.update', $this->billed), [
            'invoice_number' => 'INV/ENG/2026/001',
            'invoice_date' => '2026-09-25',
            'amount' => '1500000',
            'due_date' => '2026-10-25',
            ...$payload,
        ]);
    });

    it('lets the executor side correct the invoice, logging before and after', function () {
        $colleague = User::factory()->for($this->target)->create()->assignRole('pelaksana');

        ($this->correct)($colleague, ['invoice_number' => 'INV/ENG/2026/001-R', 'amount' => '1750000', 'due_date' => ''])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect($this->billed->refresh()->invoice)
            ->number->toBe('INV/ENG/2026/001-R')
            ->amount->toBe('1750000.00')
            ->due_date->toBeNull()
            ->issued_by->toBe($this->pelaksana->id)
            ->corrected_by->toBe($colleague->id)
            ->and($this->billed->status->getValue())->toBe('penagihan');

        $corrected = Activity::query()->where('event', AuditEvent::InvoiceCorrected->value)->sole();

        expect($corrected->causer_id)->toBe($colleague->id)
            ->and($corrected->attribute_changes?->toArray())->toBe([
                'attributes' => ['no_invoice' => 'INV/ENG/2026/001-R', 'jumlah' => '1750000.00', 'jatuh_tempo' => null],
                'old' => ['no_invoice' => 'INV/ENG/2026/001', 'jumlah' => '1500000.00', 'jatuh_tempo' => '2026-10-25'],
            ]);
    });

    it('replaces invoice files, but never removes the last one', function () {
        ($this->correct)($this->pelaksana, ['remove_files' => [$this->invoiceFile->uuid]])
            ->assertSessionHasErrors(['invoice_files' => 'Invoice harus memiliki minimal satu berkas.']);

        expect($this->billed->attachmentsIn('invoice')->pluck('id')->all())->toBe([$this->invoiceFile->id])
            ->and($this->billed->refresh()->invoice->corrected_by)->toBeNull();

        ($this->correct)($this->pelaksana, [
            'remove_files' => [$this->invoiceFile->uuid],
            'invoice_files' => [attachmentUpload('dokumen.pdf', 'Invoice 001 revisi.pdf')],
        ])->assertSessionHasNoErrors();

        expect($this->billed->attachmentsIn('invoice')->pluck('name')->all())->toBe(['Invoice 001 revisi.pdf'])
            ->and(Activity::query()->where('event', AuditEvent::AttachmentRemoved->value)->where('subject_id', $this->billed->id)->count())->toBe(1)
            ->and($this->billed->refresh()->invoice->corrected_by)->toBe($this->pelaksana->id);
    });

    it('removes only invoice and BAST files of this work order', function () {
        $document = app(AddAttachment::class)->handle($this->billed, $this->billed->documentsCollection(), attachmentUpload('dokumen.pdf'), $this->pemohon);
        $otherWorkOrder = WorkOrder::factory()->targeting($this->target)->billed()->create();
        $otherFile = app(AddAttachment::class)->handle($otherWorkOrder, $otherWorkOrder->attachmentCollections()['invoice'], attachmentUpload('dokumen.pdf'), $this->pelaksana);

        ($this->correct)($this->pelaksana, ['remove_files' => [$document->uuid, $otherFile->uuid], 'invoice_number' => 'INV-X'])
            ->assertSessionHasNoErrors();

        expect(Media::query()->whereKey([$document->id, $otherFile->id])->count())->toBe(2);
    });

    it('keeps the number unique, apart from the invoice itself', function () {
        WorkOrderInvoice::factory()->create(['number' => 'INV-TAKEN']);

        ($this->correct)($this->pelaksana, ['invoice_number' => 'inv/eng/2026/001'])->assertSessionHasNoErrors();
        ($this->correct)($this->pelaksana, ['invoice_number' => 'inv-taken'])->assertSessionHasErrors('invoice_number');

        expect($this->billed->refresh()->invoice->number)->toBe('inv/eng/2026/001');
    });

    it('records nothing when nothing changes', function () {
        ($this->correct)($this->pelaksana, [])->assertSessionHasNoErrors();

        expect($this->billed->refresh()->invoice->corrected_by)->toBeNull()
            ->and(Activity::query()->where('event', AuditEvent::InvoiceCorrected->value)->exists())->toBeFalse();
    });

    it('is only for the executor side', function (string $who) {
        ($this->correct)($who === 'pemohon' ? $this->pemohon : $this->keuangan, ['invoice_number' => 'INV-X'])->assertForbidden();
    })->with(['pemohon', 'keuangan']);

    it('refuses corrections once paid', function () {
        payAs($this->keuangan, $this->billed)->assertSessionHasNoErrors();

        ($this->correct)($this->pelaksana, ['invoice_number' => 'INV-X'])
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Invoice hanya dapat dikoreksi selama work order dalam penagihan.']);

        expect($this->billed->refresh()->invoice->number)->toBe('INV/ENG/2026/001');
    });
});

describe('confirming payment (Penagihan → Selesai)', function () {
    beforeEach(function () {
        $this->billed = billedByPelaksana();
    });

    it('records the payment date and proof, and closes the work order', function () {
        payAs($this->keuangan, $this->billed, ['proof_files' => [attachmentUpload('foto.png', 'Transfer.png')]])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $workOrder = $this->billed->refresh();

        expect($workOrder->status->getValue())->toBe('selesai')
            ->and($workOrder->invoice->paid_on?->toDateString())->toBe('2026-09-25')
            ->and($workOrder->invoice->paid_by)->toBe($this->keuangan->id)
            ->and($workOrder->attachmentsIn('bukti_bayar')->pluck('name')->all())->toBe(['Transfer.png'])
            ->and(Activity::query()->where('event', AuditEvent::PaymentConfirmed->value)->sole()->attribute_changes?->toArray())
            ->toBe(['attributes' => ['tanggal_bayar' => '2026-09-25']]);
    });

    it('checks the payment date in WITA', function (string $now, string $paidOn, bool $accepted) {
        $this->travelTo(Carbon::parse($now, 'UTC'));

        $response = payAs($this->keuangan, $this->billed, ['paid_on' => $paidOn]);

        $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('paid_on');
        expect($this->billed->refresh()->status->getValue())->toBe($accepted ? 'selesai' : 'penagihan');
    })->with([
        'before the invoice date' => ['2026-09-26 02:00', '2026-09-24', false],
        'on the invoice date' => ['2026-09-26 02:00', '2026-09-25', true],
        'tomorrow in WITA' => ['2026-09-26 02:00', '2026-09-27', false],
        // 01:00 WITA on 27 Sep, 17:00 UTC on 26 Sep.
        'today in WITA, still yesterday in UTC' => ['2026-09-26 17:00', '2026-09-27', true],
        'not a date' => ['2026-09-26 02:00', '27-09-2026', false],
    ]);

    it('re-checks the payment date against the invoice under the lock', function () {
        expect(fn () => app(ConfirmWorkOrderPayment::class)->handle($this->billed, $this->keuangan, '2026-09-24'))
            ->toThrow(ValidationException::class)
            ->and($this->billed->refresh()->status->getValue())->toBe('penagihan');
    });

    it('refuses Selesai through the plain status change', function () {
        $this->actingAs($this->keuangan)
            ->post(route('work-orders.transitions.store', $this->billed), ['status' => 'selesai'])
            ->assertSessionHasErrors('status');

        expect($this->billed->refresh()->status->getValue())->toBe('penagihan');
    });
});

describe('segregation of duties', function () {
    beforeEach(function () {
        // An admin in the target department is on both the executor and the finance side.
        $this->admin = User::factory()->for($this->target)->create()->assignRole('admin');
    });

    it('refuses the payment by whoever issued the invoice', function () {
        billAs($this->admin, $this->workOrder)->assertSessionHasNoErrors();

        payAs($this->admin, $this->workOrder)->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Anda menerbitkan atau terakhir mengoreksi invoice ini, jadi pembayarannya harus dikonfirmasi oleh petugas keuangan lain.',
        ]);

        expect($this->workOrder->refresh()->status->getValue())->toBe('penagihan')
            ->and($this->workOrder->invoice->paid_on)->toBeNull();

        payAs($this->keuangan, $this->workOrder)->assertSessionHasNoErrors();

        expect($this->workOrder->refresh()->status->getValue())->toBe('selesai');
    });

    it('refuses the payment by whoever last corrected the invoice', function () {
        $billed = billedByPelaksana();

        $this->actingAs($this->admin)->patch(route('work-orders.invoice.update', $billed), [
            'invoice_number' => 'INV/ENG/2026/001-R',
            'invoice_date' => '2026-09-25',
        ])->assertSessionHasNoErrors();

        payAs($this->admin, $billed)->assertInertiaFlash('toast.type', 'error');

        expect($billed->refresh()->status->getValue())->toBe('penagihan');
    });

    it('lets an admin who did not prepare the invoice confirm it', function () {
        $billed = billedByPelaksana();

        payAs($this->admin, $billed)->assertSessionHasNoErrors();

        expect($billed->refresh()->invoice->paid_by)->toBe($this->admin->id);
    });

    it('shows the reason on the detail page instead of a usable button', function () {
        billAs($this->admin, $this->workOrder)->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('transitions.0.value', 'selesai')
                ->where('transitions.0.form', 'payment')
                ->where('transitions.0.blocked_reason', 'Anda menerbitkan atau terakhir mengoreksi invoice ini, jadi pembayarannya harus dikonfirmasi oleh petugas keuangan lain.'));

        $this->actingAs($this->keuangan)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page->where('transitions.0.blocked_reason', null));
    });

    it('backs the rule with the database', function () {
        $invoice = WorkOrderInvoice::factory()->create(['issued_by' => $this->admin->id]);

        expect(fn () => DB::transaction(fn () => $invoice->forceFill(['paid_on' => '2026-09-24', 'paid_by' => $this->admin->id])->save()))
            ->toThrow(QueryException::class, 'work_order_invoices_segregation_check');
    });
});

describe('overdue per status date', function () {
    it('counts Penagihan against the payment due date, and the other statuses against the target date', function () {
        // 07:30 WITA on 25 Sep, still 24 Sep in UTC.
        $this->travelTo(Carbon::parse('2026-09-24 23:30', 'UTC'));
        $billed = fn (array $invoice, ?string $targetDate = null): WorkOrder => WorkOrder::factory()->targeting($this->target)->billed($invoice)->create(['target_date' => $targetDate]);

        $late = [
            'billed, due yesterday' => $billed(['due_date' => '2026-09-24']),
            'in progress, target yesterday' => WorkOrder::factory()->targeting($this->target)->inProgress()->create(['target_date' => '2026-09-24']),
        ];
        $notLate = [
            'billed, due today' => $billed(['due_date' => '2026-09-25']),
            'billed without a due date, target passed' => $billed(['due_date' => null], '2026-09-01'),
            'paid, due passed' => WorkOrder::factory()->targeting($this->target)->paid(['due_date' => '2026-09-21'])->create(['target_date' => '2026-09-01']),
            'in progress, target today' => WorkOrder::factory()->targeting($this->target)->inProgress()->create(['target_date' => '2026-09-25']),
        ];

        $overdueIds = WorkOrder::query()->overdue()->pluck('id')->all();

        foreach ($late as $label => $workOrder) {
            expect(in_array($workOrder->id, $overdueIds, true))->toBeTrue($label)
                ->and($workOrder->refresh()->isOverdue())->toBeTrue($label);
        }

        foreach ($notLate as $label => $workOrder) {
            expect(in_array($workOrder->id, $overdueIds, true))->toBeFalse($label)
                ->and($workOrder->refresh()->isOverdue())->toBeFalse($label);
        }
    });

    it('counts a late invoice in the dashboard', function () {
        WorkOrder::factory()->by($this->pemohon)->targeting($this->target)->billed(['due_date' => '2026-09-24'])->create();

        $this->actingAs($this->pemohon)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->loadDeferredProps(fn (Assert $reload): Assert => $reload->where('workOrderCounts.overdue', 1)));
    });
});

describe('Selesai is read-only', function () {
    beforeEach(function () {
        $this->paid = WorkOrder::factory()->by($this->pemohon)->targeting($this->target)->paid()->create();
    });

    it('offers no status change, correction, or comment', function (string $who) {
        $user = match ($who) {
            'pemohon' => $this->pemohon,
            'pelaksana' => $this->pelaksana,
            'keuangan' => $this->keuangan,
            'admin' => adminUser(),
        };

        $this->actingAs($user)
            ->get(route('work-orders.show', $this->paid))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('transitions', [])
                ->where('waitingFor', null)
                ->where('can.correctInvoice', false)
                ->where('can.comment', false)
                ->where('comments.read_only', true));
    })->with(['pemohon', 'pelaksana', 'keuangan', 'admin']);

    it('refuses comments with a message', function () {
        $this->actingAs($this->pemohon)
            ->post(route('work-orders.comments.store', $this->paid), ['body' => 'Terima kasih'])
            ->assertInertiaFlash('toast.type', 'error');

        expect($this->paid->comments()->count())->toBe(0);
    });

    it('refuses changes to every attachment collection', function (string $collection) {
        $media = app(AddAttachment::class)->handle($this->paid, $this->paid->attachmentCollections()[$collection], attachmentUpload('dokumen.pdf'), $this->keuangan);

        foreach ([$this->pemohon, $this->pelaksana, $this->keuangan, adminUser()] as $user) {
            $this->actingAs($user)
                ->post(route('attachments.store', ['work-order', $this->paid->id, $collection]), ['file' => attachmentUpload('dokumen.pdf')])
                ->assertForbidden();
            $this->actingAs($user)->delete(route('attachments.destroy', $media))->assertForbidden();
        }
    })->with(['dokumen', 'invoice', 'bast', 'bukti_bayar']);
});

describe('attachments while invoicing', function () {
    it('lets the target department add a BAST while it works, and only the finance side add proof of payment while billed', function () {
        $billed = WorkOrder::factory()->by($this->pemohon)->targeting($this->target)->billed()->create();

        expect($this->pelaksana->can('addAttachment', [$this->workOrder, 'bast']))->toBeTrue()
            ->and($this->pelaksana->can('addAttachment', [$this->workOrder, 'invoice']))->toBeFalse()
            ->and($this->keuangan->can('addAttachment', [$this->workOrder, 'bast']))->toBeFalse()
            // While billed, invoice and BAST change only through the correction, which records who made it.
            ->and($this->pelaksana->can('addAttachment', [$billed, 'invoice']))->toBeFalse()
            ->and($this->pelaksana->can('addAttachment', [$billed, 'bast']))->toBeFalse()
            ->and($this->pelaksana->can('addAttachment', [$billed, 'bukti_bayar']))->toBeFalse()
            ->and($this->pemohon->can('addAttachment', [$billed, 'bukti_bayar']))->toBeFalse()
            ->and($this->keuangan->can('addAttachment', [$billed, 'bukti_bayar']))->toBeTrue();
    });

    it('lets the finance side remove only its own proof of payment', function () {
        $billed = WorkOrder::factory()->by($this->pemohon)->targeting($this->target)->billed()->create();
        $colleague = User::factory()->for($this->keuangan->department)->create()->assignRole('keuangan');

        $this->actingAs($this->keuangan)
            ->post(route('attachments.store', ['work-order', $billed->id, 'bukti_bayar']), ['file' => attachmentUpload('foto.jpg')])
            ->assertSessionHasNoErrors();
        $proof = $billed->attachmentsIn('bukti_bayar')->sole();

        $this->actingAs($colleague)->delete(route('attachments.destroy', $proof))->assertForbidden();
        $this->actingAs($this->keuangan)->delete(route('attachments.destroy', $proof))->assertRedirect();

        expect($billed->attachmentsIn('bukti_bayar'))->toBeEmpty();
    });

    it('accepts only PDF and images as proof of payment', function () {
        $billed = WorkOrder::factory()->by($this->pemohon)->targeting($this->target)->billed()->create();

        $this->actingAs($this->keuangan)
            ->post(route('attachments.store', ['work-order', $billed->id, 'bukti_bayar']), ['file' => attachmentUpload('anggaran.xlsx')])
            ->assertSessionHasErrors('file');
    });
});

describe('detail page', function () {
    it('offers the invoice form to the target department while it works', function () {
        $this->actingAs($this->pelaksana)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('transitions.0', [
                    'value' => 'penagihan',
                    'label' => 'Tagihkan',
                    'destructive' => false,
                    'requires_note' => false,
                    'note_label' => 'Catatan',
                    'requires_target_department' => true,
                    'form' => 'invoice',
                    'blocked_reason' => null,
                ])
                ->where('invoice', null)
                ->has('invoiceAttachments', 1)
                ->where('invoiceAttachments.bast.can.upload', true));
    });

    it('shows the invoice, its files, and whom the work order waits for', function () {
        $billed = billedByPelaksana();

        $this->actingAs($this->pemohon)
            ->get(route('work-orders.show', $billed))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('workOrder.status', ['value' => 'penagihan', 'label' => 'Penagihan', 'tone' => 'billing'])
                ->where('invoice', [
                    'number' => 'INV/ENG/2026/001',
                    'invoice_date' => '2026-09-25',
                    'amount' => '1500000.00',
                    'due_date' => '2026-10-25',
                    'paid_on' => null,
                    'is_overdue' => false,
                    'issued_by' => ['name' => $this->pelaksana->name],
                    'corrected_by' => null,
                    'paid_by' => null,
                ])
                ->where('invoiceAttachments.invoice.items.0.name', 'Invoice 001.pdf')
                ->where('invoiceAttachments.bast.items.0.name', 'BAST.jpg')
                ->where('invoiceAttachments.bukti_bayar.items', [])
                ->where('invoiceAttachments.bukti_bayar.can.upload', false)
                ->where('transitions', [])
                ->where('can.correctInvoice', false)
                ->where('waitingFor', 'Menunggu konfirmasi pembayaran oleh keuangan.'));

        $this->actingAs($this->pelaksana)
            ->get(route('work-orders.show', $billed))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('can.correctInvoice', true)
                ->where('waitingFor', 'Menunggu konfirmasi pembayaran oleh keuangan.'));

        $this->actingAs($this->keuangan)
            ->get(route('work-orders.show', $billed))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('waitingFor', null)
                ->where('transitions.0.value', 'selesai')
                ->where('invoiceAttachments.bukti_bayar.can.upload', true));
    });

    it('shows the new statuses in the timeline and the invoice changes in the Riwayat', function () {
        $billed = billedByPelaksana();
        $this->actingAs($this->pelaksana)->patch(route('work-orders.invoice.update', $billed), [
            'invoice_number' => 'INV/ENG/2026/001',
            'invoice_date' => '2026-09-25',
            'amount' => '1750000.5',
        ])->assertSessionHasNoErrors();
        payAs($this->keuangan, $billed)->assertSessionHasNoErrors();

        $this->actingAs($this->pemohon)
            ->get(route('work-orders.show', $billed))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('timeline', fn ($entries): bool => collect($entries)->where('type', 'status')->pluck('to.label')->take(-2)->values()->all() === ['Penagihan', 'Selesai']));

        $rows = Activity::query()
            ->where('subject_id', $billed->id)
            ->whereIn('event', [AuditEvent::InvoiceIssued->value, AuditEvent::InvoiceCorrected->value, AuditEvent::PaymentConfirmed->value])
            ->orderBy('id')
            ->get()
            ->map(fn (Activity $activity): array => new ActivityResource($activity)->resolve(request()))
            ->all();

        expect(array_column($rows, 'event_label'))->toBe(['Invoice diterbitkan', 'Invoice dikoreksi', 'Pembayaran dikonfirmasi'])
            ->and(collect($rows[1]['changes'])->map(fn (array $change): array => [$change['label'], $change['old'], $change['new']])->all())->toBe([
                ['Jumlah tagihan', 'Rp 1.500.000', 'Rp 1.750.000,50'],
                ['Jatuh tempo', '2026-10-25', null],
            ])
            ->and($rows[2]['changes'][0]['label'])->toBe('Tanggal pembayaran');
    });
});
