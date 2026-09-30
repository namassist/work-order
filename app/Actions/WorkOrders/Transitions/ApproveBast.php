<?php

namespace App\Actions\WorkOrders\Transitions;

use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBast;
use App\Support\Bast\BastDocumentKind;
use App\Support\Bast\BastPdf;
use Illuminate\Validation\ValidationException;

/**
 * Approval BAST → BAST Disetujui (FLOW.md §5.1, §8): the Direktur approves
 * the BAST. Its final PDF is generated from the template version the BAST
 * recorded (not the one active now), with the Direktur's name and the
 * approval time, stored, and its SHA-256 recorded. From then on the BAST
 * and its final PDF are immutable (database triggers). Runs inside the
 * transition's transaction: if generation fails, nothing is kept.
 */
class ApproveBast implements TransitionEffect
{
    use LogsAuditChanges;

    public function __construct(
        private readonly BastPdf $pdf,
        private readonly StoreBastPdf $files,
    ) {}

    public function handle(WorkOrder $workOrder, User $user): void
    {
        $bast = WorkOrderBast::query()->where('work_order_id', $workOrder->id)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['status' => __('BAST work order ini belum dibuat.')]);

        if ($bast->isApproved()) {
            throw ValidationException::withMessages(['status' => __('BAST work order ini sudah disetujui.')]);
        }

        $approvedAt = now();
        $pdf = $this->pdf->ofVersion($bast->templateVersion, $workOrder, $bast, BastDocumentKind::Final, $user->name, $approvedAt);

        $this->files->handle($bast, WorkOrderBast::FINAL, $pdf, $user);

        $bast->forceFill([
            'approved_by' => $user->id,
            'approver_name' => $user->name,
            'approved_at' => $approvedAt,
            'final_sha256' => hash('sha256', $pdf),
        ])->save();

        $this->logAuditChange($workOrder, AuditEvent::BastApproved, [], [
            'nomor_bast' => $bast->number,
            'disetujui_oleh' => $user->name,
        ]);
    }
}
