<?php

namespace App\Support\Bast;

use App\Http\Resources\AttachmentResource;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBast;
use Illuminate\Http\Request;

/**
 * The "BAST" section of the work order detail page (FLOW.md §8): its
 * number, the template version it was generated from, who submitted and
 * approved it, the SHA-256 of the final PDF, and its PDF (the final one
 * once approved, the draft before). BASTs are internal (Unggul) data, so
 * client company users never get them, like daily reports.
 */
class BastPanel
{
    /**
     * @return array{number: string, template_version: int, submitted_by: string, submitted_at: string, approver_name: string|null, approved_at: string|null, final_sha256: string|null, file: array<string, mixed>|null}|null
     */
    public static function props(WorkOrder $workOrder, User $user, Request $request): ?array
    {
        if ($user->isClient()) {
            return null;
        }

        $bast = $workOrder->bast()->with(['templateVersion', 'submitter', 'draftFile.uploader', 'finalFile.uploader'])->first();

        if (! $bast instanceof WorkOrderBast) {
            return null;
        }

        $file = $bast->isApproved() ? $bast->finalFile : $bast->draftFile;

        return [
            'number' => $bast->number,
            'template_version' => $bast->templateVersion->version,
            'submitted_by' => $bast->submitter->name,
            'submitted_at' => $bast->submitted_at->toIso8601String(),
            'approver_name' => $bast->approver_name,
            'approved_at' => $bast->approved_at?->toIso8601String(),
            'final_sha256' => $bast->final_sha256,
            'file' => $file ? new AttachmentResource($file)->resolve($request) : null,
        ];
    }
}
