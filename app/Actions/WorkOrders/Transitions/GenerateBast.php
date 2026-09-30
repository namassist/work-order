<?php

namespace App\Actions\WorkOrders\Transitions;

use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\BastTemplate;
use App\Models\BastTemplateVersion;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBast;
use App\Support\Bast\BastDocumentKind;
use App\Support\Bast\BastNumberGenerator;
use App\Support\Bast\BastPdf;
use Illuminate\Validation\ValidationException;

/**
 * Review Dokumen → Approval BAST (FLOW.md §5.1, §8): Rental submits the
 * BAST. It gets its number, records the active template version, and its
 * draft PDF (marked as a draft) is generated and stored. Runs inside the
 * transition's transaction with the work order locked: if generation
 * fails, the status change, the number, and the file are all rolled back.
 */
class GenerateBast implements TransitionEffect
{
    use LogsAuditChanges;

    public function __construct(
        private readonly BastNumberGenerator $numbers,
        private readonly BastPdf $pdf,
        private readonly StoreBastPdf $files,
    ) {}

    public function handle(WorkOrder $workOrder, User $user): void
    {
        if ($workOrder->bast()->exists()) {
            throw ValidationException::withMessages(['status' => __('BAST work order ini sudah dibuat.')]);
        }

        // Publishing and activating hold the template row's update lock: waiting on it sees their
        // result committed, and holding it keeps the active version until this transaction ends.
        BastTemplate::query()->sharedLock()->first();
        $version = BastTemplateVersion::query()->where('is_active', true)->first()
            ?? throw ValidationException::withMessages(['status' => __('Belum ada template BAST yang aktif. Minta admin menerbitkan template BAST.')]);

        $bast = new WorkOrderBast;
        $bast->forceFill([
            'work_order_id' => $workOrder->id,
            'number' => $this->numbers->next($workOrder->requesterDepartment->code, now()),
            'bast_template_version_id' => $version->id,
            'submitted_by' => $user->id,
            'submitted_at' => now(),
        ])->save();
        $bast->setRelation('submitter', $user);

        $this->files->handle($bast, WorkOrderBast::DRAFT, $this->pdf->ofVersion($version, $workOrder, $bast, BastDocumentKind::Draft), $user);

        $this->logAuditChange($workOrder, AuditEvent::BastGenerated, [], [
            'nomor_bast' => $bast->number,
            'versi_template' => $version->version,
        ]);
    }
}
