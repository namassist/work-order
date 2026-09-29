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
- Diajukan (menunggu diproses) → `warning`
- Dikerjakan → `info`
- Penagihan (menunggu pembayaran) → `billing`: ungu redup (`--billing`, terang `#7a5a92` dengan teks
  `--billing-foreground` terang 5,57:1; gelap `#bea2d4` dengan teks gelap 8,17:1). Badge berisi,
  seperti Diajukan dan Dikerjakan: masih berjalan, menunggu keuangan.
- Selesai / Lunas / Closed → `success`
- Ditolak → `destructive`: masih hidup, menunggu pemohon merevisi dan mengajukan ulang.
- Dibatalkan → `muted`: sudah berakhir, tidak ada lagi yang perlu dilakukan. Badge garis tipis
  (`border-border`, tanpa isi) dengan teks `text-muted-foreground` (5,26:1 terang, 6,73:1 gelap),
  supaya berbeda dari Draft yang berisi abu dan dari Ditolak yang merah.

Status tidak boleh hanya dibedakan lewat warna; selalu sertakan label teks. Tombol aksi yang
menolak atau membatalkan memakai varian `destructive` di dialog konfirmasinya, apa pun tone status
tujuannya.

Di grafik, seri status memakai token badge-nya, asal kontrasnya minimal 3:1 terhadap `card`
di mode terang dan gelap. Jika kurang, seri memakai token `--chart-*` khusus grafik dari keluarga
warna yang sama; token badge tidak diubah. Saat ini: Draft `--chart-5` (abu netral, 4,96:1
terang), Diajukan `--chart-submitted` (emas `--warning` yang digelapkan, 3,21:1 terang; di mode
gelap sama dengan `--warning`), Dikerjakan `--info` (5,32:1 terang, 7,70:1 gelap), Penagihan
`--billing` (5,57:1 terang, 7,55:1 gelap; jarak warna ΔE 26–27 dari `--info`, seri status terdekat,
dan lebih jauh dari seri lain), Selesai `--success` (4,91:1 terang, 7,58:1 gelap), Ditolak
`--destructive` (6,46:1 terang, 6,16:1 gelap), Dibatalkan `--chart-cancelled` (netral gelap:
12,46:1 terang, 3,18:1 gelap; di kedua mode lebih gelap dari `--chart-5`, beda 2,51:1 terang dan
2,11:1 gelap, supaya tidak tertukar dengan Draft). Warna grafik dipilih per status, dengan tone
sebagai cadangan: `statusChartColor()` di `lib/workOrderStatus.ts` membaca `STATUS_CHART_COLORS`
dulu (Dibatalkan), lalu warna tone-nya. Grafik selalu punya legenda berlabel, tooltip dengan
angka, dan tabel alternatif untuk pembaca layar; angka tidak dicetak di dalam batang.

## Urgensi Work Order

Urgensi (Rendah, Normal, Tinggi, Mendesak) tampil sebagai ikon lucide + teks, tidak pernah
sebagai badge dan tidak pernah memakai warna status, supaya tidak tertukar dengan status:

- Rendah `ChevronDown`, Normal `Minus`, Tinggi `ChevronUp`, Mendesak `ChevronsUp`.
- Hanya Mendesak yang ditonjolkan: teks `font-semibold` dengan warna teks utama. Level lain
  `text-muted-foreground`. Tanpa warna aksen atau warna status.
- Satu komponen untuk semua tempat: `components/work-orders/WorkOrderUrgency.vue`.
- Tabel: kolom Urgensi sendiri, disembunyikan di bawah `lg`. Di bawah `lg`, WO Mendesak
  menampilkan penanda Mendesak (ikon + teks kecil) di sel utama, di baris kedua sebelum
  deskripsi, supaya judul tetap terbaca di layar sempit; level lain tidak ditampilkan di sana.

## Komentar

Komentar rich text tampil sebagai prosa biasa di timeline, tanpa kotak atau kartu. Gayanya ada di
kelas `.comment-body` (`resources/css/app.css`), dipakai bersama oleh isi komentar dan editornya:

- Paragraf berjarak `mt-2`; daftar berpoin/bernomor dengan indentasi `pl-5`.
- Kutipan: garis kiri `border-l-2` dan teks `text-muted-foreground`, tanpa latar.
- Kode inline: `bg-muted`, `font-mono`, sedikit lebih kecil.
- Tautan: warna teks biasa dengan garis bawah (bukan warna aksen), selalu dibuka di tab baru.
- Gambar: responsif (`max-w-full`, tinggi maks. `max-h-80`), sudut `rounded-md` dan border 1px;
  klik atau Enter membukanya dalam ukuran penuh di dialog.
- Dokumen: baris berkas yang sama dengan panel Dokumen (`AttachmentRow.vue`).
- Toolbar editor: tombol ikon `ghost` kecil (`size-8`) yang membungkus di layar sempit, format
  aktif ditandai `bg-muted` dan `aria-pressed`; setiap tombol punya label dan pintasan keyboard.

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
    2. Toolbar: aksi utama di kiri, pencarian dan filter di kanan (lihat Toolbar filter).
    3. Tabel.
- Halaman form: field langsung di dalam panel, tanpa kotak tambahan. Grid 2 kolom di desktop,
  1 kolom di mobile. Textarea deskripsi selebar penuh. Tombol Simpan dan Batal di kanan bawah.
  Form create tidak menampilkan nomor WO (nomor dibuat saat diajukan).

### Toolbar filter

Pola untuk daftar dengan banyak filter; Daftar WO yang pertama. Bagian-bagiannya ada di
`components/list-filters/` (`FilterToolbar`, `SearchFilter`, `FilterSelect`, `FilterPopover`,
`FilterSheet`, `DateRangeFilter`, `SortSelect`, `ActiveFilterChips`). Parameter query tetap
milik server, jadi URL lama, ekspor, dan tautan dashboard tetap berlaku.

- Selalu terlihat: pencarian plus dua atau tiga filter yang paling sering dipakai (Daftar WO:
  Status, Urgensi). Sisanya di balik tombol "Filter" (popover) dengan badge jumlah filter aktif
  di dalamnya. Filter yang butuh izin (mis. Departemen, Tampilkan terhapus) tidak dirender sama
  sekali tanpa izinnya.
- Urutkan terpisah di ujung kanan, setelah garis vertikal. Urutan bukan filter: tanpa chip,
  tidak dihitung di badge, tidak memicu empty state "Tidak ada … yang cocok", dan tetap
  dipertahankan oleh "Reset semua".
- Chip filter aktif di bawah kontrol, di dalam bagian toolbar yang sama: satu chip per filter
  ("Status: Diajukan", "Dibuat: 1 Sep 2026 – 27 Sep 2026"), klik untuk menghapusnya, lalu
  "Reset semua". Chip dibaca dari filter yang dikirim server, bukan dari isian yang belum
  diterapkan. Tanpa filter aktif, baris chip tidak ada.
- Filter diterapkan langsung, tanpa tombol Terapkan. Reload memakai `preserveState` dan
  `preserveScroll`, jadi popover dan sheet tetap terbuka selama filter diubah.
- Rentang tanggal: `DateRangeFilter` (kalender id-ID, minggu mulai Senin) dengan preset Hari
  ini, 7 hari terakhir, 30 hari terakhir, Bulan ini, dihitung dari "hari ini" WITA. Rentang
  diterapkan hanya saat kedua ujung sudah dipilih atau preset diklik; "Hapus" mengosongkan
  kedua ujung sekaligus. Nilainya tanggal kalender (Y-m-d); server mengubahnya ke batas UTC.
- Responsif: kontrol inline mulai `xl`. Di bawah `xl`, hanya pencarian dan satu tombol
  "Filter" yang membuka Sheet berisi semua filter plus Urutkan. Hasil cek 390, 768, 1024,
  1280, dan 1440px: kontrol inline Daftar WO butuh ±810px dalam satu baris, sedangkan lebar
  toolbar di 1024px hanya ±690px, jadi `lg` memecahnya jadi dua baris; mulai `xl` muat satu
  baris. Bila tidak muat di samping aksi utama, seluruh baris kontrol turun ke bawah aksi,
  tidak pernah terpecah.

### Tabel

- Sel utama dua baris: baris 1 nomor WO (font-mono) + judul tebal, baris 2 deskripsi singkat
  terpotong satu baris dengan warna muted. Draft menampilkan "Draft" di posisi nomor.
- Kolom orang: avatar kecil + nama.
- Status sebagai badge, tanggal via lib/format.ts.
- Seluruh baris bisa diklik untuk membuka detail. Aksi lain (Edit, Riwayat, Hapus)
  di menu titik tiga di kolom terakhir.

### Responsif

- Di bawah 1024px sidebar menjadi drawer.
- Strip statistik: 4 kolom → 2×2 → 1 kolom. Strip dengan lebih dari 4 sel (Daftar WO: total plus
  setiap status, 8 sel): 4 kolom mulai `lg` (dua baris) → 2 kolom, juga di ponsel, supaya tidak
  menjadi satu kolom yang panjang dan tidak ada baris dengan sel kosong.
- Tabel: kolom sekunder (orang, tanggal) disembunyikan di mobile, sisanya tetap terbaca
  tanpa scroll horizontal jika memungkinkan.

Referensi visual: docs/design/refs/ (struktur saja, lihat README di folder itu).
