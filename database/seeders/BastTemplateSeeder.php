<?php

namespace Database\Seeders;

use App\Actions\BastTemplates\PublishBastTemplate;
use App\Actions\BastTemplates\SaveBastTemplateDraft;
use App\Models\BastTemplate;
use App\Models\BastTemplateVersion;
use Illuminate\Database\Seeder;

/**
 * The default BAST template (FLOW.md §8, §9), saved and published as
 * version 1 through the real actions, so BASTs can be generated on a fresh
 * install. Runs only while no version and no saved draft exist; afterwards
 * the system admin edits the template on its page. Safe to run in production.
 *
 * PROVISIONAL: a generic Indonesian layout until the business provides its
 * official BAST sample (letterhead, wording, signature blocks; FLOW.md §13).
 */
class BastTemplateSeeder extends Seeder
{
    public const string DEFAULT_HTML = '<h1 style="text-align: center;">BERITA ACARA SERAH TERIMA PEKERJAAN</h1>'
        .'<p style="text-align: center;">Nomor: {{nomor_bast}}</p>'
        .'<hr>'
        .'<p style="text-align: justify;">Pada tanggal {{tanggal_bast}}, pihak pelaksana menyerahkan pekerjaan berikut kepada pihak pemohon, dan pekerjaan tersebut dinyatakan telah selesai dilaksanakan:</p>'
        .'<table><tbody>'
        .'<tr><th colspan="1" rowspan="1"><p>Nomor work order</p></th><td colspan="1" rowspan="1"><p>{{nomor_wo}}</p></td></tr>'
        .'<tr><th colspan="1" rowspan="1"><p>Pekerjaan</p></th><td colspan="1" rowspan="1"><p>{{judul}}</p></td></tr>'
        .'<tr><th colspan="1" rowspan="1"><p>Kategori</p></th><td colspan="1" rowspan="1"><p>{{kategori}}</p></td></tr>'
        .'<tr><th colspan="1" rowspan="1"><p>Departemen pemohon</p></th><td colspan="1" rowspan="1"><p>{{departemen_pemohon}} ({{kontak_pemohon}})</p></td></tr>'
        .'<tr><th colspan="1" rowspan="1"><p>Departemen pelaksana</p></th><td colspan="1" rowspan="1"><p>{{departemen_tujuan}}</p></td></tr>'
        .'<tr><th colspan="1" rowspan="1"><p>Periode pelaksanaan</p></th><td colspan="1" rowspan="1"><p>{{periode_pelaksanaan}}</p></td></tr>'
        .'</tbody></table>'
        .'<h2>Uraian pekerjaan</h2>'
        .'<p>{{deskripsi}}</p>'
        .'<h2>Laporan harian</h2>'
        .'<p>{{tabel_laporan_harian}}</p>'
        .'<p style="text-align: justify;">Demikian berita acara ini dibuat dengan sebenarnya untuk dipergunakan sebagaimana mestinya.</p>'
        .'<table><tbody>'
        .'<tr><th colspan="1" rowspan="1"><p style="text-align: center;">Diajukan oleh</p></th><th colspan="1" rowspan="1"><p style="text-align: center;">Disetujui oleh</p></th></tr>'
        .'<tr><td colspan="1" rowspan="1"><p style="text-align: center;">{{nama_pengaju_bast}}</p><p style="text-align: center;">Rental</p></td>'
        .'<td colspan="1" rowspan="1"><p style="text-align: center;">{{nama_direktur}}</p><p style="text-align: center;">Direktur</p><p style="text-align: center;">{{tanggal_persetujuan}}</p></td></tr>'
        .'</tbody></table>';

    public function run(SaveBastTemplateDraft $save, PublishBastTemplate $publish): void
    {
        // Never replace an admin's work: a published version, or a draft saved before the first publish.
        if (BastTemplateVersion::query()->exists() || BastTemplate::query()->where('draft_html', '!=', '')->exists()) {
            return;
        }

        $save->handle(self::DEFAULT_HTML, null);
        $publish->handle(null);
    }
}
