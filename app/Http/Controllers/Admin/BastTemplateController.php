<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BastTemplates\ActivateBastTemplateVersion;
use App\Actions\BastTemplates\PublishBastTemplate;
use App\Actions\BastTemplates\SaveBastTemplateDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBastTemplateRequest;
use App\Models\BastTemplate;
use App\Models\BastTemplateVersion;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Attachments\AttachmentPanel;
use App\Support\Bast\BastDocumentKind;
use App\Support\Bast\BastGenerationFailed;
use App\Support\Bast\BastPdf;
use App\Support\Bast\BastPlaceholder;
use App\Support\Bast\BastTemplateHtml;
use App\Support\Bast\BastTemplateImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The BAST template page (FLOW.md §9): one template, whose draft is edited
 * and previewed, published as immutable versions, one of them active.
 */
class BastTemplateController extends Controller
{
    /**
     * How many recent work orders the preview offers.
     */
    private const int PREVIEW_WORK_ORDERS = 30;

    public function edit(Request $request): Response
    {
        Gate::authorize('manage', BastTemplate::class);

        /** @var User $user */
        $user = $request->user();
        $template = BastTemplate::current()->load(['draftEditor', 'latestVersion']);
        $names = BastTemplateImages::names($template);
        $draft = BastTemplateHtml::sanitize($template->draft_html, fn (string $uuid): ?string => $names[$uuid] ?? null);

        return Inertia::render('admin/bast-template/Edit', [
            'template' => [
                'draft_html' => $template->draft_html,
                'draft_updated_at' => $template->draft_updated_at?->toIso8601String(),
                'draft_editor' => $template->draftEditor?->name,
                // Whether the draft is what the latest version holds, i.e. nothing is waiting to be published.
                'is_published' => $template->latestVersion instanceof BastTemplateVersion
                    && $template->latestVersion->html === $draft->html
                    && collect(array_keys($template->latestVersion->images))->sort()->values()->all() === collect($draft->imageUuids)->sort()->values()->all(),
            ],
            'versions' => $template->versions()->with('publisher')->get()
                ->map(fn (BastTemplateVersion $version): array => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'published_at' => $version->published_at->toIso8601String(),
                    'publisher' => $version->publisher->name ?? null,
                    'is_active' => $version->is_active,
                ])
                ->values()
                ->all(),
            'images' => AttachmentPanel::props($template, BastTemplate::IMAGES, $user, $request),
            'placeholders' => BastPlaceholder::options(),
            'previewWorkOrders' => WorkOrder::query()
                ->visibleTo($user)
                ->whereNotNull('number')
                ->latest('updated_at')
                ->limit(self::PREVIEW_WORK_ORDERS)
                ->get(['id', 'number', 'title'])
                ->map(fn (WorkOrder $workOrder): array => ['id' => $workOrder->id, 'number' => $workOrder->number, 'title' => $workOrder->title])
                ->values()
                ->all(),
            'maxHtmlBytes' => config()->integer('work_order.bast.template_max_html_bytes'),
        ]);
    }

    public function update(UpdateBastTemplateRequest $request, SaveBastTemplateDraft $save): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $save->handle((string) $request->input('html', ''), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draf template BAST disimpan.')]);

        return back();
    }

    public function publish(Request $request, PublishBastTemplate $publish): RedirectResponse
    {
        Gate::authorize('manage', BastTemplate::class);

        /** @var User $user */
        $user = $request->user();

        $version = $publish->handle($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template BAST versi :version diterbitkan dan sekarang aktif.', ['version' => $version->version])]);

        return back();
    }

    public function activate(BastTemplateVersion $version, ActivateBastTemplateVersion $activate): RedirectResponse
    {
        Gate::authorize('manage', BastTemplate::class);

        $activate->handle($version);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template BAST versi :version sekarang aktif.', ['version' => $version->version])]);

        return back();
    }

    /**
     * The draft, or a published version, filled from a work order the user
     * may see, as an inline PDF marked PRATINJAU.
     */
    public function preview(Request $request, BastPdf $pdf): HttpResponse
    {
        Gate::authorize('manage', BastTemplate::class);

        /** @var User $user */
        $user = $request->user();

        $input = $request->validate([
            'work_order' => ['required', 'integer'],
            'version' => ['nullable', 'integer', Rule::exists('bast_template_versions', 'id')],
        ]);

        $workOrder = WorkOrder::query()->visibleTo($user)->whereNotNull('number')->findOrFail((int) $input['work_order']);
        $version = isset($input['version']) ? BastTemplateVersion::query()->findOrFail((int) $input['version']) : null;

        try {
            $content = $version instanceof BastTemplateVersion
                ? $pdf->ofVersion($version, $workOrder, $workOrder->bast, BastDocumentKind::Preview)
                : $pdf->previewDraft(BastTemplate::current(), $workOrder);
        } catch (BastGenerationFailed $exception) {
            report($exception);
            abort(HttpResponse::HTTP_UNPROCESSABLE_ENTITY, __('Pratinjau BAST gagal dibuat.'));
        }

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="pratinjau-bast.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
