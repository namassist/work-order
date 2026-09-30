<?php

namespace App\Support\Bast;

use App\Models\BastTemplate;
use App\Models\BastTemplateVersion;
use App\Models\WorkOrder;
use App\Models\WorkOrderBast;
use Carbon\CarbonInterface;

/**
 * Generates BAST PDFs: from a published template version (a work order's
 * draft and final BAST, or a preview of that version), or from the
 * template's current draft (a preview only).
 */
class BastPdf
{
    public function __construct(private readonly BastPdfRenderer $renderer) {}

    public function ofVersion(
        BastTemplateVersion $version,
        WorkOrder $workOrder,
        ?WorkOrderBast $bast,
        BastDocumentKind $kind,
        ?string $approverName = null,
        ?CarbonInterface $approvedAt = null,
    ): string {
        return $this->renderer->render(BastDocument::html(
            $version->html,
            $version->images,
            BastValues::for($workOrder, $bast, $kind, $approverName, $approvedAt),
            $kind,
        ));
    }

    /**
     * A preview of the template's draft, filled from a work order.
     */
    public function previewDraft(BastTemplate $template, WorkOrder $workOrder): string
    {
        return $this->renderer->render(BastDocument::html(
            $template->draft_html,
            BastTemplateImages::of($template),
            BastValues::for($workOrder, $workOrder->bast, BastDocumentKind::Preview),
            BastDocumentKind::Preview,
        ));
    }
}
