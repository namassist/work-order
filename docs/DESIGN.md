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

## Anti-pola (jangan dilakukan)

- Tanpa gradient, glow, glassmorphism, atau bayangan tebal. Pemisah cukup border 1px.
- Ikon hanya lucide, monokrom, satu ukuran per konteks. Tanpa emoji.
- Satu panel per halaman. Isi panel (header, statistik, toolbar, tabel, form) dipisah garis,
  bukan dibungkus kotak baru.
- Ikon di kartu metrik boleh, asal monokrom, kecil, maknanya sesuai metrik, dan angka tetap
  elemen paling menonjol. Tanpa lingkaran berwarna atau gradient di belakang ikon.
- Tanpa teks pemanis. Label singkat dan fungsional.
- Satu aksen per layar. Warna lain hanya untuk status.
- Dropdown/select tidak boleh terlihat seperti tombol aksi utama.
- Aksi destruktif (hapus) tidak boleh jadi satu-satunya aksi yang terlihat di baris tabel.
- Empty state: satu kalimat penjelas + satu aksi, tanpa ilustrasi besar.
- Tanpa elemen template yang tidak relevan: upsell paket, keranjang, bendera bahasa,
  footer promosi.

## Layout (warna tetap dari token di atas)

### App shell

- Sidebar kiri lebar tetap, bisa diciutkan lewat tombol di topbar. Menu dikelompokkan
  dengan label grup kecil huruf kapital (Dashboard, Work Order, Master Data, Administrasi).
- Topbar: tombol toggle sidebar di kiri; di kanan hanya toggle tema dan menu avatar.
  Pencarian global (kiri) dan notifikasi (kanan) baru ditambahkan saat fiturnya
  diimplementasikan; jangan pasang UI yang belum berfungsi.
- Footer tidak ada, atau cukup satu baris versi aplikasi.

### Struktur halaman

- Satu panel per halaman. Header panel: judul di kiri, breadcrumb di kanan, lalu garis pemisah.
- Halaman daftar, urutan dari atas:
    1. Strip statistik: 4 kolom dipisah garis vertikal, angka besar di atas label,
       ikon kecil monokrom di pojok kanan atas.
    2. Toolbar: aksi utama di kiri, pencarian dan filter di kanan.
    3. Tabel.
- Halaman form: field langsung di dalam panel, tanpa kotak tambahan. Grid 2 kolom di desktop,
  1 kolom di mobile. Textarea deskripsi selebar penuh. Tombol Simpan dan Batal di kanan bawah.
  Form create tidak menampilkan nomor WO (nomor dibuat saat diajukan).

### Tabel

- Sel utama dua baris: baris 1 nomor WO (font-mono) + judul tebal, baris 2 deskripsi singkat
  terpotong satu baris dengan warna muted. Draft menampilkan "Draft" di posisi nomor.
- Kolom orang: avatar kecil + nama.
- Status sebagai badge, tanggal via lib/format.ts.
- Seluruh baris bisa diklik untuk membuka detail. Aksi lain (Edit, Riwayat, Hapus)
  di menu titik tiga di kolom terakhir.

### Responsif

- Di bawah 1024px sidebar menjadi drawer.
- Strip statistik: 4 kolom → 2×2 → 1 kolom.
- Tabel: kolom sekunder (orang, tanggal) disembunyikan di mobile, sisanya tetap terbaca
  tanpa scroll horizontal jika memungkinkan.

Referensi visual: docs/refs/ (struktur saja, lihat README di folder itu).
