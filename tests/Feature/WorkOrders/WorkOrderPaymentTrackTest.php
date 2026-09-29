<?php

use App\Actions\Attachments\AddAttachment;
use App\Actions\WorkOrders\ConfirmWorkOrderPayment;
use App\Enums\AuditEvent;
use App\Enums\PaymentStatus;
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
| The payment track of closed work orders (FLOW.md §10): Finance bills a
| closed work order (Belum ditagih → Ditagih) with the invoice and its files,
| corrects the invoice while it is billed, and confirms the payment
| (Ditagih → Lunas); segregation of duties is a setting, off by default; the
| payment due date is the overdue basis while billed. The work order's status
| stays Closed throughout. Who may do each is in
| WorkOrderTransitionMatrixTest; visibility of the invoice files in
| WorkOrderVisibilityTest.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('attachments');
    // 10:00 WITA on 25 Sep.
    $this->travelTo(Carbon::parse('2026-09-25 02:00', 'UTC'));

    $ops = Department::factory()->create(['code' => 'OPS']);
    $keu = Department::factory()->create(['code' => 'KEU']);
    $this->adminWo = User::factory()->for($ops)->create()->assignRole('admin-wo');
    $this->lead = User::factory()->for($ops)->create()->assignRole('lead-operational');
    $this->finance = User::factory()->for($keu)->create()->assignRole('finance');
    $this->otherFinance = User::factory()->for($keu)->create()->assignRole('finance');
    $this->workOrder = WorkOrder::factory()->by($this->adminWo)->closed()->create();
});

/**
 * A valid invoice form, with one invoice file.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function invoicePayload(array $overrides = []): array
{
    return [
        'invoice_number' => 'INV/UGL/2026/001',
        'invoice_date' => '2026-09-25',
        'amount' => '1500000',
        'due_date' => '2026-10-25',
        'invoice_files' => [attachmentUpload('dokumen.pdf', 'Invoice 001.pdf')],
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
 * @param  array<string, mixed>  $payload
 */
function correctAs(User $user, WorkOrder $workOrder, array $payload = []): TestResponse
{
    return test()->actingAs($user)->patch(route('work-orders.invoice.update', $workOrder), [
        'invoice_number' => 'INV/UGL/2026/001',
        'invoice_date' => '2026-09-25',
        'amount' => '1500000',
        'due_date' => '2026-10-25',
        ...$payload,
    ]);
}

/**
 * The test's work order, billed by Finance through the endpoint.
 */
function billedByFinance(): WorkOrder
{
    billAs(test()->finance, test()->workOrder)->assertSessionHasNoErrors();

    return test()->workOrder->refresh();
}

it('follows the payment status from the invoice, only on closed work orders', function () {
    expect(WorkOrder::factory()->bastApproved()->create()->paymentStatus())->toBeNull()
        ->and(WorkOrder::factory()->closed()->create()->paymentStatus())->toBe(PaymentStatus::BelumDitagih)
        ->and(WorkOrder::factory()->billed()->create()->paymentStatus())->toBe(PaymentStatus::Ditagih)
        ->and(WorkOrder::factory()->paid()->create()->paymentStatus())->toBe(PaymentStatus::Lunas);

    $ids = fn (PaymentStatus $status): int => WorkOrder::query()->inPaymentStatus($status)->count();

    expect([$ids(PaymentStatus::BelumDitagih), $ids(PaymentStatus::Ditagih), $ids(PaymentStatus::Lunas)])->toBe([2, 1, 1]);
});

describe('billing (Belum ditagih → Ditagih)', function () {
    it('saves the invoice and its files, and keeps the work order Closed', function () {
        $before = $this->workOrder->updated_at;
        $this->travel(1)->minutes();

        billAs($this->finance, $this->workOrder)
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', "Invoice work order {$this->workOrder->number} diterbitkan.");

        $workOrder = $this->workOrder->refresh();

        expect($workOrder->status->getValue())->toBe('closed')
            ->and($workOrder->paymentStatus())->toBe(PaymentStatus::Ditagih)
            ->and($workOrder->updated_at->gt($before))->toBeTrue()
            ->and($workOrder->statusHistories()->exists())->toBeFalse()
            ->and($workOrder->invoice)
            ->number->toBe('INV/UGL/2026/001')
            ->amount->toBe('1500000.00')
            ->paid_on->toBeNull()
            ->issued_by->toBe($this->finance->id)
            ->and($workOrder->invoice->invoice_date->toDateString())->toBe('2026-09-25')
            ->and($workOrder->invoice->due_date?->toDateString())->toBe('2026-10-25')
            ->and($workOrder->attachmentsIn('invoice')->pluck('name')->all())->toBe(['Invoice 001.pdf']);

        $issued = Activity::query()->where('event', AuditEvent::InvoiceIssued->value)->sole();

        expect($issued->subject_id)->toBe($workOrder->id)
            ->and($issued->causer_id)->toBe($this->finance->id)
            ->and($issued->attribute_changes?->toArray())->toBe(['attributes' => [
                'no_invoice' => 'INV/UGL/2026/001',
                'tanggal_invoice' => '2026-09-25',
                'jumlah' => '1500000.00',
                'jatuh_tempo' => '2026-10-25',
            ]]);
    });

    it('needs only the number, the date, and one invoice file', function () {
        billAs($this->finance, $this->workOrder, ['amount' => null, 'due_date' => null])->assertSessionHasNoErrors();

        expect($this->workOrder->refresh()->invoice)
            ->amount->toBeNull()
            ->due_date->toBeNull();
    });

    it('validates the invoice', function (array $overrides, string $field) {
        billAs($this->finance, $this->workOrder, $overrides)->assertSessionHasErrors($field);

        expect(WorkOrderInvoice::query()->count())->toBe(0);
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

        billAs($this->finance, $this->workOrder, ['invoice_date' => '2026-09-26', 'amount' => '1500000.5', 'due_date' => null])
            ->assertSessionHasNoErrors();

        expect($this->workOrder->refresh()->invoice)
            ->amount->toBe('1500000.50')
            ->and($this->workOrder->invoice->invoice_date->toDateString())->toBe('2026-09-26');
    });

    it('refuses an invoice number already used, whatever its casing', function () {
        WorkOrderInvoice::factory()->create(['number' => 'inv/ugl/2026/001']);

        billAs($this->finance, $this->workOrder, ['invoice_number' => '  INV/UGL/2026/001 '])->assertSessionHasErrors('invoice_number');

        expect($this->workOrder->refresh()->invoice)->toBeNull();
    });

    it('backs the unique invoice number with the database', function () {
        WorkOrderInvoice::factory()->create(['number' => 'INV-1']);

        expect(fn () => DB::transaction(fn () => WorkOrderInvoice::factory()->create(['number' => 'inv-1'])))
            ->toThrow(QueryException::class, 'work_order_invoices_number_lower_unique');
    });

    it('leaves no invoice or file when an upload fails', function () {
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

        billAs($this->finance, $this->workOrder, ['invoice_files' => [attachmentUpload('dokumen.pdf'), attachmentUpload('foto.jpg')]])
            ->assertSessionHasErrors(['invoice_files' => 'Gagal menyimpan berkas.']);

        expect(WorkOrderInvoice::query()->count())->toBe(0)
            ->and(Media::query()->count())->toBe(0)
            ->and(Storage::disk('attachments')->allFiles())->toBe([])
            ->and(Activity::query()->where('event', AuditEvent::InvoiceIssued->value)->exists())->toBeFalse();
    });

    it('bills only a closed work order that was not billed yet', function (string $state) {
        $workOrder = WorkOrder::factory()->{$state}()->create();

        billAs($this->finance, $workOrder, ['invoice_number' => 'INV-NEW'])->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Invoice hanya dapat diterbitkan untuk work order Closed yang belum ditagih.',
        ]);

        expect(WorkOrderInvoice::query()->where('number', 'INV-NEW')->exists())->toBeFalse();
    })->with(['inProgress', 'bastApproved', 'billed', 'paid']);
});

describe('correcting the invoice', function () {
    beforeEach(function () {
        $this->billed = billedByFinance();
        $this->invoiceFile = $this->billed->attachmentsIn('invoice')->sole();
    });

    it('lets Finance correct the invoice, logging before and after', function () {
        correctAs($this->otherFinance, $this->billed, ['invoice_number' => 'INV/UGL/2026/001-R', 'amount' => '1750000', 'due_date' => ''])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect($this->billed->refresh()->invoice)
            ->number->toBe('INV/UGL/2026/001-R')
            ->amount->toBe('1750000.00')
            ->due_date->toBeNull()
            ->issued_by->toBe($this->finance->id)
            ->corrected_by->toBe($this->otherFinance->id)
            ->and($this->billed->paymentStatus())->toBe(PaymentStatus::Ditagih);

        $corrected = Activity::query()->where('event', AuditEvent::InvoiceCorrected->value)->sole();

        expect($corrected->causer_id)->toBe($this->otherFinance->id)
            ->and($corrected->attribute_changes?->toArray())->toBe([
                'attributes' => ['no_invoice' => 'INV/UGL/2026/001-R', 'jumlah' => '1750000.00', 'jatuh_tempo' => null],
                'old' => ['no_invoice' => 'INV/UGL/2026/001', 'jumlah' => '1500000.00', 'jatuh_tempo' => '2026-10-25'],
            ]);
    });

    it('replaces invoice files, but never removes the last one', function () {
        correctAs($this->finance, $this->billed, ['remove_files' => [$this->invoiceFile->uuid]])
            ->assertSessionHasErrors(['invoice_files' => 'Invoice harus memiliki minimal satu berkas.']);

        expect($this->billed->attachmentsIn('invoice')->pluck('id')->all())->toBe([$this->invoiceFile->id])
            ->and($this->billed->refresh()->invoice->corrected_by)->toBeNull();

        correctAs($this->finance, $this->billed, [
            'remove_files' => [$this->invoiceFile->uuid],
            'invoice_files' => [attachmentUpload('dokumen.pdf', 'Invoice 001 revisi.pdf')],
        ])->assertSessionHasNoErrors();

        expect($this->billed->attachmentsIn('invoice')->pluck('name')->all())->toBe(['Invoice 001 revisi.pdf'])
            ->and(Activity::query()->where('event', AuditEvent::AttachmentRemoved->value)->where('subject_id', $this->billed->id)->count())->toBe(1)
            ->and($this->billed->refresh()->invoice->corrected_by)->toBe($this->finance->id);
    });

    it('removes only invoice files of this work order', function () {
        $document = app(AddAttachment::class)->handle($this->billed, $this->billed->documentsCollection(), attachmentUpload('dokumen.pdf'), $this->adminWo);
        $otherWorkOrder = WorkOrder::factory()->billed()->create();
        $otherFile = app(AddAttachment::class)->handle($otherWorkOrder, $otherWorkOrder->attachmentCollections()['invoice'], attachmentUpload('dokumen.pdf'), $this->finance);

        correctAs($this->finance, $this->billed, ['remove_files' => [$document->uuid, $otherFile->uuid], 'invoice_number' => 'INV-X'])
            ->assertSessionHasNoErrors();

        expect(Media::query()->whereKey([$document->id, $otherFile->id])->count())->toBe(2);
    });

    it('keeps the number unique, apart from the invoice itself', function () {
        WorkOrderInvoice::factory()->create(['number' => 'INV-TAKEN']);

        correctAs($this->finance, $this->billed, ['invoice_number' => 'inv/ugl/2026/001'])->assertSessionHasNoErrors();
        correctAs($this->finance, $this->billed, ['invoice_number' => 'inv-taken'])->assertSessionHasErrors('invoice_number');

        expect($this->billed->refresh()->invoice->number)->toBe('inv/ugl/2026/001');
    });

    it('records nothing when nothing changes', function () {
        correctAs($this->finance, $this->billed)->assertSessionHasNoErrors();

        expect($this->billed->refresh()->invoice->corrected_by)->toBeNull()
            ->and(Activity::query()->where('event', AuditEvent::InvoiceCorrected->value)->exists())->toBeFalse();
    });

    it('is only for Finance', function (string $who) {
        correctAs($this->{$who}, $this->billed, ['invoice_number' => 'INV-X'])->assertForbidden();
    })->with(['adminWo', 'lead']);

    it('refuses corrections once paid', function () {
        payAs($this->otherFinance, $this->billed)->assertSessionHasNoErrors();

        correctAs($this->finance, $this->billed, ['invoice_number' => 'INV-X'])
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Invoice hanya dapat dikoreksi selama pembayarannya berstatus Ditagih.']);

        expect($this->billed->refresh()->invoice->number)->toBe('INV/UGL/2026/001');
    });
});

describe('confirming payment (Ditagih → Lunas)', function () {
    beforeEach(function () {
        $this->billed = billedByFinance();
    });

    it('records the payment date and proof, keeping the work order Closed', function () {
        payAs($this->otherFinance, $this->billed, ['proof_files' => [attachmentUpload('foto.png', 'Transfer.png')]])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', "Pembayaran work order {$this->billed->number} dikonfirmasi. Status pembayaran: Lunas.");

        $workOrder = $this->billed->refresh();

        expect($workOrder->status->getValue())->toBe('closed')
            ->and($workOrder->paymentStatus())->toBe(PaymentStatus::Lunas)
            ->and($workOrder->invoice->paid_on?->toDateString())->toBe('2026-09-25')
            ->and($workOrder->invoice->paid_by)->toBe($this->otherFinance->id)
            ->and($workOrder->attachmentsIn('bukti_bayar')->pluck('name')->all())->toBe(['Transfer.png'])
            ->and(Activity::query()->where('event', AuditEvent::PaymentConfirmed->value)->sole()->attribute_changes?->toArray())
            ->toBe(['attributes' => ['tanggal_bayar' => '2026-09-25']]);
    });

    it('checks the payment date in WITA', function (string $now, string $paidOn, bool $accepted) {
        $this->travelTo(Carbon::parse($now, 'UTC'));

        $response = payAs($this->finance, $this->billed, ['paid_on' => $paidOn]);

        $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('paid_on');
        expect($this->billed->refresh()->paymentStatus())->toBe($accepted ? PaymentStatus::Lunas : PaymentStatus::Ditagih);
    })->with([
        'before the invoice date' => ['2026-09-26 02:00', '2026-09-24', false],
        'on the invoice date' => ['2026-09-26 02:00', '2026-09-25', true],
        'tomorrow in WITA' => ['2026-09-26 02:00', '2026-09-27', false],
        // 01:00 WITA on 27 Sep, 17:00 UTC on 26 Sep.
        'today in WITA, still yesterday in UTC' => ['2026-09-26 17:00', '2026-09-27', true],
        'not a date' => ['2026-09-26 02:00', '27-09-2026', false],
    ]);

    it('re-checks the payment date against the invoice under the lock', function () {
        expect(fn () => app(ConfirmWorkOrderPayment::class)->handle($this->billed, $this->finance, '2026-09-24'))
            ->toThrow(ValidationException::class)
            ->and($this->billed->refresh()->paymentStatus())->toBe(PaymentStatus::Ditagih);
    });

    it('confirms only a billed, unpaid invoice', function (string $state) {
        $workOrder = WorkOrder::factory()->{$state}()->create();

        payAs($this->finance, $workOrder)->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Pembayaran hanya dapat dikonfirmasi selama pembayarannya berstatus Ditagih.',
        ]);
    })->with(['bastApproved', 'closed', 'paid']);
});

describe('segregation of duties', function () {
    it('is off by default: the Finance user who billed may confirm the payment', function () {
        expect(config('work_order.payment.segregation_of_duties'))->toBeFalse();
        $billed = billedByFinance();
        correctAs($this->finance, $billed, ['invoice_number' => 'INV-R'])->assertSessionHasNoErrors();

        $this->actingAs($this->finance)
            ->get(route('work-orders.show', $billed))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('can.confirmPayment', true)
                ->where('paymentBlockedReason', null));

        payAs($this->finance, $billed)->assertSessionHasNoErrors();

        expect($billed->refresh()->invoice)
            ->paid_by->toBe($this->finance->id)
            ->issued_by->toBe($this->finance->id);
    });

    describe('when turned on', function () {
        beforeEach(function () {
            config(['work_order.payment.segregation_of_duties' => true]);
            $this->billed = billedByFinance();
        });

        it('refuses the payment by whoever issued the invoice', function () {
            payAs($this->finance, $this->billed)->assertInertiaFlash('toast', [
                'type' => 'error',
                'message' => 'Anda menerbitkan atau terakhir mengoreksi invoice ini, jadi pembayarannya harus dikonfirmasi oleh petugas keuangan lain.',
            ]);

            expect($this->billed->refresh()->invoice->paid_on)->toBeNull();

            payAs($this->otherFinance, $this->billed)->assertSessionHasNoErrors();

            expect($this->billed->refresh()->paymentStatus())->toBe(PaymentStatus::Lunas);
        });

        it('refuses the payment by whoever last corrected the invoice', function () {
            correctAs($this->otherFinance, $this->billed, ['invoice_number' => 'INV-R'])->assertSessionHasNoErrors();

            payAs($this->otherFinance, $this->billed)->assertInertiaFlash('toast.type', 'error');

            expect($this->billed->refresh()->paymentStatus())->toBe(PaymentStatus::Ditagih);
        });

        it('shows the reason on the detail page instead of a usable button', function () {
            $this->actingAs($this->finance)
                ->get(route('work-orders.show', $this->billed))
                ->assertInertia(fn (Assert $page): Assert => $page
                    ->where('can.confirmPayment', true)
                    ->where('paymentBlockedReason', 'Anda menerbitkan atau terakhir mengoreksi invoice ini, jadi pembayarannya harus dikonfirmasi oleh petugas keuangan lain.'));

            $this->actingAs($this->otherFinance)
                ->get(route('work-orders.show', $this->billed))
                ->assertInertia(fn (Assert $page): Assert => $page->where('paymentBlockedReason', null));
        });
    });

    it('is no longer a database rule', function () {
        $invoice = WorkOrderInvoice::factory()->create(['issued_by' => $this->finance->id]);

        $invoice->forceFill(['paid_on' => '2026-09-24', 'paid_by' => $this->finance->id])->save();

        expect($invoice->refresh()->paid_by)->toBe($this->finance->id);
    });
});

describe('overdue per basis (FLOW.md §11)', function () {
    it('counts Ditagih against the payment due date, and the active statuses against the target date', function () {
        // 07:30 WITA on 25 Sep, still 24 Sep in UTC.
        $this->travelTo(Carbon::parse('2026-09-24 23:30', 'UTC'));
        $billed = fn (array $invoice, ?string $targetDate = null): WorkOrder => WorkOrder::factory()->billed($invoice)->create(['target_date' => $targetDate]);
        $at = fn (string $state, string $targetDate): WorkOrder => WorkOrder::factory()->{$state}()->create(['target_date' => $targetDate]);

        $late = [
            'Ditagih, due yesterday' => $billed(['due_date' => '2026-09-24']),
            'Diajukan, target yesterday' => $at('submitted', '2026-09-24'),
            'Pelaksanaan, target yesterday' => $at('inProgress', '2026-09-24'),
            'Review Dokumen, target yesterday' => $at('inReview', '2026-09-24'),
        ];
        $notLate = [
            'Ditagih, due today' => $billed(['due_date' => '2026-09-25']),
            'Ditagih without a due date, target passed' => $billed(['due_date' => null], '2026-09-01'),
            'Lunas, due passed' => WorkOrder::factory()->paid(['due_date' => '2026-09-21'])->create(['target_date' => '2026-09-01']),
            'Belum ditagih, target passed' => $at('closed', '2026-09-01'),
            'Pelaksanaan, target today' => $at('inProgress', '2026-09-25'),
            'Ditolak, target passed' => $at('rejected', '2026-09-01'),
            'Approval BAST, target passed' => $at('awaitingBastApproval', '2026-09-01'),
            'BAST Disetujui, target passed' => $at('bastApproved', '2026-09-01'),
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
        WorkOrder::factory()->billed(['due_date' => '2026-09-24'])->create();

        $this->actingAs($this->adminWo)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->loadDeferredProps(fn (Assert $reload): Assert => $reload
                    ->where('workOrderCounts.overdue', 1)
                    ->where('workOrderCounts.overdue_by.payment_due_date', 1)
                    ->where('workOrderCounts.billing', 1)));
    });
});

describe('files', function () {
    it('lets Finance add proof of payment only while billed; the invoice changes only through its forms', function () {
        $billed = WorkOrder::factory()->billed()->create();
        $paid = WorkOrder::factory()->paid()->create();

        expect($this->finance->can('addAttachment', [$this->workOrder, 'bukti_bayar']))->toBeFalse()
            ->and($this->finance->can('addAttachment', [$billed, 'bukti_bayar']))->toBeTrue()
            ->and($this->finance->can('addAttachment', [$billed, 'invoice']))->toBeFalse()
            ->and($this->adminWo->can('addAttachment', [$billed, 'bukti_bayar']))->toBeFalse()
            ->and($this->finance->can('addAttachment', [$paid, 'bukti_bayar']))->toBeFalse();
    });

    it('lets Finance remove only its own proof of payment', function () {
        $billed = WorkOrder::factory()->billed()->create();

        $this->actingAs($this->finance)
            ->post(route('attachments.store', ['work-order', $billed->id, 'bukti_bayar']), ['file' => attachmentUpload('foto.jpg')])
            ->assertSessionHasNoErrors();
        $proof = $billed->attachmentsIn('bukti_bayar')->sole();

        $this->actingAs($this->otherFinance)->delete(route('attachments.destroy', $proof))->assertForbidden();
        $this->actingAs($this->finance)->delete(route('attachments.destroy', $proof))->assertRedirect();

        expect($billed->attachmentsIn('bukti_bayar'))->toBeEmpty();
    });

    it('accepts only PDF and images as proof of payment', function () {
        $billed = WorkOrder::factory()->billed()->create();

        $this->actingAs($this->finance)
            ->post(route('attachments.store', ['work-order', $billed->id, 'bukti_bayar']), ['file' => attachmentUpload('anggaran.xlsx')])
            ->assertSessionHasErrors('file');
    });

    it('refuses changes to every collection once paid', function (string $collection) {
        $paid = WorkOrder::factory()->paid()->create();
        $media = app(AddAttachment::class)->handle($paid, $paid->attachmentCollections()[$collection], attachmentUpload('dokumen.pdf'), $this->finance);

        foreach ([$this->adminWo, $this->finance, adminUser()] as $user) {
            $this->actingAs($user)
                ->post(route('attachments.store', ['work-order', $paid->id, $collection]), ['file' => attachmentUpload('dokumen.pdf')])
                ->assertForbidden();
            $this->actingAs($user)->delete(route('attachments.destroy', $media))->assertForbidden();
        }
    })->with(['dokumen', 'invoice', 'bukti_bayar']);
});

describe('detail page', function () {
    it('offers billing to Finance on a closed work order', function () {
        $this->actingAs($this->finance)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('workOrder.status', ['value' => 'closed', 'label' => 'Closed', 'tone' => 'success'])
                ->where('paymentStatus', ['value' => 'belum_ditagih', 'label' => 'Belum ditagih', 'tone' => 'secondary'])
                ->where('transitions', [])
                ->where('can.bill', true)
                ->where('can.correctInvoice', false)
                ->where('can.confirmPayment', false)
                ->where('invoice', null)
                ->where('invoiceAttachments', []));

        $this->actingAs($this->adminWo)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page->where('can.bill', false));
    });

    it('shows no payment track before the work order is closed', function () {
        $this->actingAs($this->finance)
            ->get(route('work-orders.show', WorkOrder::factory()->bastApproved()->create()))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('paymentStatus', null)
                ->where('can.bill', false));
    });

    it('shows the invoice and its files while billed', function () {
        $billed = billedByFinance();

        $this->actingAs($this->adminWo)
            ->get(route('work-orders.show', $billed))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('paymentStatus.value', 'ditagih')
                ->where('invoice', [
                    'number' => 'INV/UGL/2026/001',
                    'invoice_date' => '2026-09-25',
                    'amount' => '1500000.00',
                    'due_date' => '2026-10-25',
                    'paid_on' => null,
                    'is_overdue' => false,
                    'issued_by' => ['name' => $this->finance->name],
                    'corrected_by' => null,
                    'paid_by' => null,
                ])
                ->where('invoiceAttachments.invoice.items.0.name', 'Invoice 001.pdf')
                ->where('invoiceAttachments.bukti_bayar.items', [])
                ->where('invoiceAttachments.bukti_bayar.can.upload', false)
                ->where('can.correctInvoice', false)
                ->where('can.confirmPayment', false)
                ->where('waitingFor', 'Menunggu konfirmasi pembayaran oleh Finance.'));

        $this->actingAs($this->finance)
            ->get(route('work-orders.show', $billed))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('waitingFor', null)
                ->where('can.bill', false)
                ->where('can.correctInvoice', true)
                ->where('can.confirmPayment', true)
                ->where('invoiceAttachments.bukti_bayar.can.upload', true));
    });

    it('shows the payment track changes in the Riwayat', function () {
        $billed = billedByFinance();
        correctAs($this->finance, $billed, ['amount' => '1750000.5', 'due_date' => ''])->assertSessionHasNoErrors();
        payAs($this->finance, $billed)->assertSessionHasNoErrors();

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
