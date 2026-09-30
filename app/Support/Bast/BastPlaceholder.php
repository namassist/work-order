<?php

namespace App\Support\Bast;

/**
 * The placeholders a BAST template may use (FLOW.md §9), written {{key}} in
 * its text. Nothing else is ever substituted: templates are never compiled
 * or evaluated as Blade, PHP, or any template language. Inline placeholders
 * become escaped text; a block placeholder stands alone in a paragraph,
 * which the application replaces with HTML it builds itself.
 */
enum BastPlaceholder: string
{
    case NomorBast = 'nomor_bast';
    case TanggalBast = 'tanggal_bast';
    case NamaPengajuBast = 'nama_pengaju_bast';
    case NomorWo = 'nomor_wo';
    case Judul = 'judul';
    case Deskripsi = 'deskripsi';
    case Kategori = 'kategori';
    case DepartemenPemohon = 'departemen_pemohon';
    case KontakPemohon = 'kontak_pemohon';
    case DepartemenTujuan = 'departemen_tujuan';
    case PicWo = 'pic_wo';
    case TanggalTarget = 'tanggal_target';
    case PeriodePelaksanaan = 'periode_pelaksanaan';
    case JumlahLaporanHarian = 'jumlah_laporan_harian';
    case NamaDirektur = 'nama_direktur';
    case TanggalPersetujuan = 'tanggal_persetujuan';
    case TabelLaporanHarian = 'tabel_laporan_harian';

    /**
     * What looks like a placeholder: {{key}} or {{ key }} with a key of
     * letters, digits, and underscores. Such a token must be a known key,
     * or the template is refused. Anything else ({{ $x }}, {!! !!}, @php)
     * is plain text.
     */
    public const string PATTERN = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    public function token(): string
    {
        return '{{'.$this->value.'}}';
    }

    public function isBlock(): bool
    {
        return $this === self::TabelLaporanHarian;
    }

    public function label(): string
    {
        return match ($this) {
            self::NomorBast => 'Nomor BAST',
            self::TanggalBast => 'Tanggal BAST',
            self::NamaPengajuBast => 'Nama pengaju BAST (Rental)',
            self::NomorWo => 'Nomor WO',
            self::Judul => 'Judul WO',
            self::Deskripsi => 'Deskripsi WO',
            self::Kategori => 'Kategori',
            self::DepartemenPemohon => 'Departemen pemohon',
            self::KontakPemohon => 'Kontak pemohon',
            self::DepartemenTujuan => 'Departemen tujuan',
            self::PicWo => 'PIC Work Order',
            self::TanggalTarget => 'Tanggal target',
            self::PeriodePelaksanaan => 'Periode pelaksanaan (laporan harian pertama s.d. terakhir)',
            self::JumlahLaporanHarian => 'Jumlah laporan harian',
            self::NamaDirektur => 'Nama Direktur yang menyetujui',
            self::TanggalPersetujuan => 'Tanggal dan jam persetujuan',
            self::TabelLaporanHarian => 'Tabel laporan harian (paragraf sendiri)',
        };
    }

    /**
     * For the template editor's picker.
     *
     * @return list<array{key: string, token: string, label: string, block: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $placeholder): array => [
            'key' => $placeholder->value,
            'token' => $placeholder->token(),
            'label' => $placeholder->label(),
            'block' => $placeholder->isBlock(),
        ], self::cases());
    }
}
