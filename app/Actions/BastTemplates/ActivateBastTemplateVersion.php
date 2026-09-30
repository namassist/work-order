<?php

namespace App\Actions\BastTemplates;

use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\BastTemplate;
use App\Models\BastTemplateVersion;
use Illuminate\Support\Facades\DB;

/**
 * Makes a published version the active one again (a rollback, FLOW.md §9).
 * The version itself does not change; new BASTs are generated from it.
 */
class ActivateBastTemplateVersion
{
    use LogsAuditChanges;

    public function handle(BastTemplateVersion $version): BastTemplateVersion
    {
        return DB::transaction(function () use ($version): BastTemplateVersion {
            $template = BastTemplate::query()->lockForUpdate()->findOrFail($version->bast_template_id);
            $version = BastTemplateVersion::query()->findOrFail($version->id);
            $previous = BastTemplateVersion::active();

            if ($previous?->is($version)) {
                return $version;
            }

            $previous?->forceFill(['is_active' => false])->save();
            $version->forceFill(['is_active' => true])->save();

            $this->logAuditChange($template, AuditEvent::BastTemplateActivated, ['versi_aktif' => $previous?->version], ['versi_aktif' => $version->version]);

            return $version;
        });
    }
}
