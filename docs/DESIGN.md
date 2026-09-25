# Design System — Aplikasi Work Order

Diadaptasi dari referensi "Mindful Moments Dashboard". Yang diambil: palet hijau-kuning,
tipografi, radius, spacing, dan ciri sidebar hijau tua. Yang TIDAK diambil: efek
WebGL/Three.js/dither, animasi dekoratif, dan layout showcase. Ini aplikasi operasional:
keterbacaan data dan kecepatan kerja lebih penting dari suasana.

Sumber kebenaran token ada di `resources/css/app.css`. Jangan hardcode hex di komponen;
selalu pakai kelas token Tailwind (`bg-primary`, `text-muted-foreground`, dst).

## Warna

Dasar netral putih/hitam lembut; hijau dan kuning adalah warna brand (logo perusahaan).

| Token                  | Light          | Dark           | Peran                       |
| ---------------------- | -------------- | -------------- | --------------------------- |
| background             | #F7F7F5        | #141414        | latar halaman               |
| card                   | #FDFDFC        | #1C1C1C        | panel, tabel, form          |
| foreground             | #1A1A1A        | #EDEDEA        | teks utama                  |
| primary                | #1C322D hijau  | #EBB552 kuning | tombol aksi utama           |
| ring / sidebar-primary | #EBB552 kuning | #EBB552        | focus ring, menu aktif      |
| sidebar                | #1C322D hijau  | #1C322D hijau  | sidebar brand di kedua mode |

Aturan kontras: teks di atas kuning selalu gelap (#1A1A1A/#141414), jangan putih.
Warna brand dipakai hemat: sidebar, aksi utama, dan highlight. Sisanya netral.

## Status Work Order

Warna status konsisten di badge, tabel, timeline, dan grafik:

- Draft → `secondary`
- Menunggu persetujuan → `warning`
- Disetujui / Dikerjakan → `info`
- Selesai / Lunas / Closed → `success`
- Ditolak / Dibatalkan → `destructive`

Status tidak boleh hanya dibedakan lewat warna; selalu sertakan label teks.

## Tipografi

- Inter (`font-sans`): semua UI, body, tabel, form. Angka nominal pakai `tabular-nums`.
- Playfair Display (`font-serif`): hanya judul halaman (h1). Jangan untuk body atau tabel.
- JetBrains Mono (`font-mono`): nomor WO, kode, nomor dokumen (invoice, PO, BAST).

## Radius & Spacing

- Control (button, input, select, badge): `rounded-md` / `rounded-lg` (8px).
- Card & panel: `rounded-2xl` (16px).
- Pill hanya untuk badge status.
- Grid 8px. Gap antar panel `gap-4`, padding card `p-6`.

## Komponen

- Gunakan komponen shadcn-vue di `resources/js/components/ui`; tambah lewat CLI, jangan tulis ulang.
- Satu tombol `primary` per area; aksi lain `outline` atau `ghost`.
- Tabel data padat: baris ringkas, header sticky, filter di atas tabel.
- Dashboard: kartu metrik (jumlah WO per status, WO terlambat), lalu tabel/daftar yang bisa ditindaklanjuti.

## Motion

Hanya transisi yang menjawab aksi user (buka dialog, expand baris, toast konfirmasi),
150–200ms ease-out. Tanpa animasi masuk per section. Hormati `prefers-reduced-motion`.
