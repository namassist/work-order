<?php

namespace App\Actions\BastTemplates;

use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\BastTemplate;
use App\Models\BastTemplateVersion;
use App\Models\User;
use App\Support\Bast\BastTemplateHtml;
use App\Support\Bast\BastTemplateImages;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishes the BAST template's draft (FLOW.md §9): sanitized again and
 * copied, with the images it shows, into a new immutable version, which
 * becomes the only active one. BASTs already generated keep the version
 * they were generated from.
 */
class PublishBastTemplate
{
    use LogsAuditChanges;

    /**
     * @param  User|null  $user  null only for the default template, published by the seeder
     *
     * @throws ValidationException when the draft is empty, has a placeholder problem, or equals the latest version
     */
    public function handle(?User $user): BastTemplateVersion
    {
        return DB::transaction(function () use ($user): BastTemplateVersion {
            $template = BastTemplate::current();
            $template = BastTemplate::query()->lockForUpdate()->findOrFail($template->id);

            $names = BastTemplateImages::names($template);
            $sanitized = BastTemplateHtml::sanitize($template->draft_html, fn (string $uuid): ?string => $names[$uuid] ?? null);

            if ($sanitized->problems !== []) {
                throw ValidationException::withMessages(['html' => $sanitized->problems]);
            }

            if ($sanitized->isEmpty) {
                throw ValidationException::withMessages(['html' => __('Template masih kosong.')]);
            }

            $images = BastTemplateImages::of($template, $sanitized->imageUuids);
            $latest = $template->latestVersion;

            if ($latest instanceof BastTemplateVersion && $latest->html === $sanitized->html && $latest->images == $images) {
                throw ValidationException::withMessages(['html' => __('Draf sama dengan versi :version; tidak ada yang diterbitkan.', ['version' => $latest->version])]);
            }

            $previous = BastTemplateVersion::active();
            $previous?->forceFill(['is_active' => false])->save();

            $version = new BastTemplateVersion;
            $version->forceFill([
                'bast_template_id' => $template->id,
                'version' => ($latest->version ?? 0) + 1,
                'html' => $sanitized->html,
                'images' => $images,
                'published_by' => $user?->id,
                'published_at' => now(),
                'is_active' => true,
            ])->save();

            $this->logAuditChange($template, AuditEvent::BastTemplatePublished, ['versi_aktif' => $previous?->version], ['versi_aktif' => $version->version]);

            return $version;
        });
    }
}
