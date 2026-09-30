<?php

namespace App\Actions\BastTemplates;

use App\Enums\AuditEvent;
use App\Models\BastTemplate;
use App\Models\User;
use App\Support\Bast\BastTemplateHtml;
use App\Support\Bast\BastTemplateImages;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the BAST template's draft (FLOW.md §9), sanitized: only the
 * editor's markup and the template's own images are kept, and a template
 * with an unknown placeholder, a placeholder in an attribute, or a
 * misplaced block placeholder is refused. Logs that the draft changed,
 * never its content.
 */
class SaveBastTemplateDraft
{
    /**
     * @param  User|null  $user  null only for the default template, saved by the seeder
     *
     * @throws ValidationException when the template has a placeholder problem
     */
    public function handle(string $html, ?User $user): BastTemplate
    {
        return DB::transaction(function () use ($html, $user): BastTemplate {
            $template = BastTemplate::current();
            $template = BastTemplate::query()->lockForUpdate()->findOrFail($template->id);

            $names = BastTemplateImages::names($template);
            $sanitized = BastTemplateHtml::sanitize($html, fn (string $uuid): ?string => $names[$uuid] ?? null);

            if ($sanitized->problems !== []) {
                throw ValidationException::withMessages(['html' => $sanitized->problems]);
            }

            if ($sanitized->html === $template->draft_html) {
                return $template;
            }

            $template->forceFill([
                'draft_html' => $sanitized->html,
                'draft_updated_by' => $user?->id,
                'draft_updated_at' => now(),
            ])->save();

            activity()
                ->performedOn($template)
                ->event(AuditEvent::BastTemplateSaved->value)
                ->log(AuditEvent::BastTemplateSaved->value);

            return $template;
        });
    }
}
