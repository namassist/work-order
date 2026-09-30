<?php

use App\Actions\WorkOrders\Transitions\ApproveBast;
use App\Actions\WorkOrders\Transitions\StoreBastPdf;
use App\Models\BastTemplateVersion;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBast;
use App\Models\WorkOrderDailyReport;
use App\Support\Bast\BastGenerationFailed;
use App\Support\Bast\BastPdf;
use App\Support\Bast\BastPdfRenderer;
use App\Support\Bast\DompdfBastPdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Rental submits the BAST (Review Dokumen → Approval BAST): it is numbered,
| records the active template version, and gets a draft PDF. The Direktur
| approves it (Approval BAST → BAST Disetujui): the final PDF is generated
| from the recorded version with the Direktur's name and approval time,
| its SHA-256 recorded, and from then on it is immutable (FLOW.md §8).
|
| "Now" is Wednesday 30 September 2026, 10:15 WITA (02:15 UTC).
*/

beforeEach(function () {
    Storage::fake('attachments');
    $this->travelTo(CarbonImmutable::parse('2026-09-30 02:15', 'UTC'));
    $this->version = activeBastTemplate('<h1>BAST {{nomor_bast}}</h1><p>WO {{nomor_wo}}: {{judul}}</p><p>Disetujui: {{nama_direktur}}, {{tanggal_persetujuan}}</p><p>{{tabel_laporan_harian}}</p>');
    $this->workOrder = WorkOrder::factory()->inReview()->create(['title' => 'Perbaikan <b>pintu</b>']);
    $this->rental = userWithRole('rental');
    $this->direktur = userWithRole('direktur');

    // Keep the HTML each PDF was rendered from, and still render it for real.
    $this->rendered = new ArrayObject;
    app()->instance(BastPdfRenderer::class, new class($this->rendered) implements BastPdfRenderer
    {
        public function __construct(private ArrayObject $rendered) {}

        public function render(string $html): string
        {
            $this->rendered[] = $html;

            return new DompdfBastPdfRenderer()->render($html);
        }
    });
});

function submitBast(WorkOrder $workOrder, User $user): TestResponse
{
    return test()->actingAs($user)->post(route('work-orders.transitions.store', $workOrder), ['status' => 'approval_bast']);
}

function approveBast(WorkOrder $workOrder, User $user): TestResponse
{
    return test()->actingAs($user)->post(route('work-orders.transitions.store', $workOrder), ['status' => 'bast_disetujui']);
}

/**
 * The renderer throws, as a broken template or engine would.
 */
function failingRenderer(): void
{
    app()->instance(BastPdfRenderer::class, new class implements BastPdfRenderer
    {
        public function render(string $html): string
        {
            throw new BastGenerationFailed('BAST gagal dibuat.');
        }
    });
}

describe('submitting the BAST', function () {
    it('numbers the BAST, records the active version, and stores a draft PDF', function () {
        submitBast($this->workOrder, $this->rental)->assertSessionHasNoErrors();

        $bast = $this->workOrder->refresh()->bast;
        $draft = $bast->draftFile;

        expect($this->workOrder->status->getValue())->toBe('approval_bast')
            ->and($bast->number)->toBe('BAST/2026/09/0001')
            ->and($bast->bast_template_version_id)->toBe($this->version->id)
            ->and($bast->submitted_by)->toBe($this->rental->id)
            ->and($bast->isApproved())->toBeFalse()
            ->and($draft->name)->toBe('BAST-2026-09-0001-draf.pdf')
            ->and($draft->mime_type)->toBe('application/pdf')
            ->and(Storage::disk('attachments')->get($draft->getPathRelativeToRoot()))->toStartWith('%PDF-')
            ->and($bast->finalFile)->toBeNull();

        $log = Activity::query()->forSubject($this->workOrder)->where('event', 'bast_generated')->sole();
        expect($log->causer_id)->toBe($this->rental->id)
            ->and($log->attribute_changes['attributes'])->toBe(['nomor_bast' => 'BAST/2026/09/0001', 'versi_template' => $this->version->version]);
    });

    it('marks the draft PDF as a draft and leaves the approval blank', function () {
        submitBast($this->workOrder, $this->rental);

        expect($this->rendered[0])
            ->toContain('<div class="watermark">DRAF</div>')
            ->toContain('DRAF: menunggu persetujuan Direktur')
            ->toContain('Disetujui: (menunggu persetujuan), (menunggu persetujuan)')
            ->toContain('<h1>BAST BAST/2026/09/0001</h1>')
            // The title is a value: escaped, never markup.
            ->toContain('Perbaikan &lt;b&gt;pintu&lt;/b&gt;');
    });

    it('fills the daily report table built by the application', function () {
        WorkOrderDailyReport::factory()->for($this->workOrder)->on('2026-09-28')->create(['note' => "<script>alert(1)</script>\nbaris dua"]);

        submitBast($this->workOrder, $this->rental);

        expect($this->rendered[0])
            ->toContain('<table class="daily-reports">')
            ->toContain('<td>28 September 2026</td><td>&lt;script&gt;alert(1)&lt;/script&gt;<br>baris dua</td>');
    });

    it('counts BAST numbers per the configured format, with the requester department code', function () {
        config(['work_order.bast.number_format' => 'BAST/{DEPT_CODE}/{YY}{MM}/{SEQ:3}']);
        $code = $this->workOrder->requesterDepartment->code;
        $second = WorkOrder::factory()->inReview()->requestedBy($this->workOrder->requesterDepartment)->create();

        submitBast($this->workOrder, $this->rental);
        submitBast($second, $this->rental);

        expect($this->workOrder->refresh()->bast->number)->toBe("BAST/{$code}/2609/001")
            ->and($second->refresh()->bast->number)->toBe("BAST/{$code}/2609/002");
    });

    it('is refused without an active template, and the page says why', function () {
        BastTemplateVersion::query()->update(['is_active' => false]);
        $reason = 'Belum ada template BAST yang aktif. Minta admin menerbitkan template BAST.';

        submitBast($this->workOrder, $this->rental)->assertSessionHasErrors(['status' => $reason]);

        expect($this->workOrder->refresh()->status->getValue())->toBe('review_dokumen')
            ->and(WorkOrderBast::query()->count())->toBe(0);

        $this->actingAs($this->rental)->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn ($page) => $page->where('transitions.1.value', 'approval_bast')->where('transitions.1.blocked_reason', $reason));
    });

    it('rolls everything back when generation fails', function () {
        failingRenderer();

        submitBast($this->workOrder, $this->rental)
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error');

        expect($this->workOrder->refresh()->status->getValue())->toBe('review_dokumen')
            ->and($this->workOrder->number)->not->toBeNull()
            ->and(WorkOrderBast::query()->count())->toBe(0)
            ->and(Media::query()->count())->toBe(0)
            ->and(DB::table('bast_number_sequences')->count())->toBe(0)
            ->and(Storage::disk('attachments')->allFiles())->toBe([])
            ->and($this->workOrder->statusHistories()->where('to_status', 'approval_bast')->exists())->toBeFalse()
            ->and(Activity::query()->forSubject($this->workOrder)->whereIn('event', ['status_changed', 'bast_generated'])->exists())->toBeFalse();
    });
});

describe('approving the BAST', function () {
    beforeEach(function () {
        submitBast($this->workOrder, $this->rental);
        $this->bast = $this->workOrder->refresh()->bast;
    });

    it('stores the final PDF with the Direktur\'s name and time, and its SHA-256', function () {
        approveBast($this->workOrder, $this->direktur)->assertSessionHasNoErrors();

        $bast = $this->bast->refresh();
        $final = $bast->finalFile;
        $content = (string) Storage::disk('attachments')->get($final->getPathRelativeToRoot());

        expect($this->workOrder->refresh()->status->getValue())->toBe('bast_disetujui')
            ->and($bast->approved_by)->toBe($this->direktur->id)
            ->and($bast->approver_name)->toBe($this->direktur->name)
            ->and($bast->approved_at->equalTo(now()))->toBeTrue()
            ->and($final->name)->toBe('BAST-2026-09-0001.pdf')
            ->and($content)->toStartWith('%PDF-')
            ->and($bast->final_sha256)->toBe(hash('sha256', $content))
            ->and($bast->draftFile)->not->toBeNull();

        expect($this->rendered[1])
            ->not->toContain('class="watermark"')
            ->not->toContain('class="banner"')
            ->toContain('Disetujui: '.e($this->direktur->name).', 30 September 2026 10:15 WITA');

        expect(Activity::query()->forSubject($this->workOrder)->where('event', 'bast_approved')->exists())->toBeTrue();
    });

    it('generates the final PDF from the version the BAST recorded, not the active one', function () {
        activeBastTemplate('<p>Template baru {{nomor_bast}}</p>');

        approveBast($this->workOrder, $this->direktur);

        expect($this->bast->refresh()->bast_template_version_id)->toBe($this->version->id)
            ->and($this->rendered[1])->toContain('<h1>BAST BAST/2026/09/0001</h1>')
            ->not->toContain('Template baru');
    });

    it('rolls everything back when generation fails', function () {
        failingRenderer();

        approveBast($this->workOrder, $this->direktur)->assertInertiaFlash('toast.type', 'error');

        expect($this->workOrder->refresh()->status->getValue())->toBe('approval_bast')
            ->and($this->bast->refresh()->isApproved())->toBeFalse()
            ->and($this->bast->final_sha256)->toBeNull()
            ->and($this->bast->finalFile)->toBeNull();
    });

    it('leaves no final file behind when the transition fails after storing it', function () {
        app()->bind(ApproveBast::class, fn (): ApproveBast => new class(app(BastPdf::class), app(StoreBastPdf::class)) extends ApproveBast
        {
            public function handle(WorkOrder $workOrder, User $user): void
            {
                parent::handle($workOrder, $user);

                throw new BastGenerationFailed('Gagal setelah menyimpan.');
            }
        });
        $filesBefore = Storage::disk('attachments')->allFiles();

        approveBast($this->workOrder, $this->direktur)->assertInertiaFlash('toast.type', 'error');

        expect($this->bast->refresh()->finalFile)->toBeNull()
            ->and(Storage::disk('attachments')->allFiles())->toBe($filesBefore);
    });

    it('refuses to approve a BAST twice', function () {
        approveBast($this->workOrder, $this->direktur);

        expect(fn () => app(ApproveBast::class)->handle($this->workOrder->refresh(), $this->direktur))
            ->toThrow(ValidationException::class, 'BAST work order ini sudah disetujui.');
    });

    it('refuses a work order in Approval BAST without a BAST', function () {
        $workOrder = WorkOrder::factory()->submitted()->create(['status' => 'approval_bast']);

        approveBast($workOrder, $this->direktur)->assertSessionHasErrors(['status' => 'BAST work order ini belum dibuat.']);

        expect($workOrder->refresh()->status->getValue())->toBe('approval_bast');
    });
});

describe('an approved BAST is immutable', function () {
    beforeEach(function () {
        submitBast($this->workOrder, $this->rental);
        approveBast($this->workOrder, $this->direktur);
        $this->bast = $this->workOrder->refresh()->bast;
    });

    it('cannot be changed or deleted in the database', function (Closure $change) {
        expect(fn () => DB::transaction(fn () => $change($this->bast)))->toThrow(QueryException::class);
    })->with([
        'hash' => fn (WorkOrderBast $bast) => DB::table('work_order_basts')->where('id', $bast->id)->update(['final_sha256' => str_repeat('0', 64)]),
        'approver' => fn (WorkOrderBast $bast) => DB::table('work_order_basts')->where('id', $bast->id)->update(['approver_name' => 'Orang lain']),
        'number' => fn (WorkOrderBast $bast) => DB::table('work_order_basts')->where('id', $bast->id)->update(['number' => 'BAST/X']),
        'touch' => fn (WorkOrderBast $bast) => DB::table('work_order_basts')->where('id', $bast->id)->update(['updated_at' => now()->addMinute()]),
        'delete' => fn (WorkOrderBast $bast) => DB::table('work_order_basts')->where('id', $bast->id)->delete(),
        'final file row delete' => fn (WorkOrderBast $bast) => DB::table('media')->where('id', $bast->finalFile->id)->delete(),
        'final file row update' => fn (WorkOrderBast $bast) => DB::table('media')->where('id', $bast->finalFile->id)->update(['name' => 'lain.pdf']),
        'final file through the model' => fn (WorkOrderBast $bast) => $bast->finalFile->delete(),
    ]);

    it('keeps a stored final file matching its hash', function () {
        $path = Storage::disk('attachments')->path($this->bast->finalFile->getPathRelativeToRoot());

        expect(hash_file('sha256', $path))->toBe($this->bast->final_sha256);
    });

    it('cannot be replaced or deleted through the attachment endpoints', function () {
        $admin = adminUser();

        $this->actingAs($admin)->delete(route('attachments.destroy', $this->bast->finalFile->uuid))->assertForbidden();
        $this->actingAs($admin)->delete(route('attachments.destroy', $this->bast->draftFile->uuid))->assertForbidden();
        $this->actingAs($admin)
            ->post(route('attachments.store', ['wo-bast', $this->bast->id, WorkOrderBast::FINAL]), ['file' => attachmentUpload('dokumen.pdf')])
            ->assertNotFound();

        expect($this->bast->refresh()->attachmentsIn(WorkOrderBast::FINAL))->toHaveCount(1);
    });
});
