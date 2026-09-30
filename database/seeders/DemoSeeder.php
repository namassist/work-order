<?php

namespace Database\Seeders;

use App\Actions\Attachments\AddAttachment;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Registrations\RejectRegistration;
use App\Actions\WorkOrders\AddDailyReport;
use App\Actions\WorkOrders\AddWorkOrderComment;
use App\Actions\WorkOrders\BillWorkOrder;
use App\Actions\WorkOrders\ConfirmWorkOrderPayment;
use App\Actions\WorkOrders\CorrectInvoice;
use App\Actions\WorkOrders\CreateWorkOrder;
use App\Actions\WorkOrders\DeleteWorkOrderComment;
use App\Actions\WorkOrders\TransitionWorkOrder;
use App\Actions\WorkOrders\UpdateDailyReport;
use App\Actions\WorkOrders\UpdateWorkOrderComment;
use App\Models\Company;
use App\Models\Department;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderComment;
use App\Models\WorkOrderDailyReport;
use App\States\WorkOrder\ApprovalBast;
use App\States\WorkOrder\BastDisetujui;
use App\States\WorkOrder\Closed;
use App\States\WorkOrder\Diajukan;
use App\States\WorkOrder\Dibatalkan;
use App\States\WorkOrder\Ditolak;
use App\States\WorkOrder\Pelaksanaan;
use App\States\WorkOrder\ReviewDokumen;
use App\Support\Comments\CommentHtml;
use App\Support\DailyReports\ReportCalendar;
use App\Support\DisplayDate;
use Carbon\CarbonImmutable;
use Closure;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo data for local development and visual checks (FLOW.md v2): the IC
 * and Unggul companies with their departments, Unggul accounts for every
 * role (IC never logs in; its departments and contacts are requester data
 * only), categories, and work orders spread over the last three months.
 * Work orders go through CreateWorkOrder, AddAttachment,
 * TransitionWorkOrder, the payment track actions, and the comment actions at
 * their historical moments,
 * in chronological order, so numbers, status history, the timeline, and the
 * activity log match real use.
 *
 * Never runs in production. Safe to re-run: master data and accounts are
 * created only when missing (existing accounts are not touched), and work
 * orders only while no demo account has any.
 */
class DemoSeeder extends Seeder
{
    /**
     * The email domain of the executor company's accounts (admin@worder.test).
     */
    public const string EMAIL_DOMAIN = 'worder.test';

    /**
     * How many days back the oldest work order may be created.
     */
    private const int HISTORY_DAYS = 85;

    private const int FAKER_SEED = 20260926;

    /**
     * Companies as code => [name, is client, email domain] (FLOW.md §2).
     *
     * @var array<string, array{0: string, 1: bool, 2: string}>
     */
    private const array COMPANIES = [
        'IC' => ['IC', true, 'ic.worder.test'],
        'UGL' => ['Unggul', false, self::EMAIL_DOMAIN],
    ];

    /**
     * Departments as code => [name, company code]. IC departments request
     * work orders (requester data only); Unggul departments are where the
     * staff work.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array DEPARTMENTS = [
        'PRD' => ['Produksi', 'IC'],
        'HRD' => ['Human Resources', 'IC'],
        'LOG' => ['Logistik', 'IC'],
        'MTC' => ['Maintenance', 'IC'],
        'ENG' => ['Engineering', 'UGL'],
        'GA' => ['General Affair', 'UGL'],
        'IT' => ['Information Technology', 'UGL'],
        'KEU' => ['Keuangan', 'UGL'],
        'DIR' => ['Direksi', 'UGL'],
    ];

    /**
     * The Unggul department that handles each category: the work order's
     * informational target department (FLOW.md v2 §4), whose PIC Timesheet
     * discusses the work. Some drafts have no target (see planWorkOrders()).
     *
     * @var array<string, string>
     */
    private const array CATEGORY_DEPARTMENTS = [
        'PRB' => 'ENG',
        'PGD' => 'GA',
        'INS' => 'IT',
        'MNT' => 'ENG',
        'KBR' => 'GA',
        'KND' => 'GA',
    ];

    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private const array CATEGORIES = [
        'PRB' => ['Perbaikan', 'Perbaikan fasilitas, peralatan, dan bangunan yang rusak.'],
        'PGD' => ['Pengadaan', 'Pembelian barang atau peralatan baru.'],
        'INS' => ['Instalasi', 'Pemasangan peralatan, jaringan, atau instalasi listrik baru.'],
        'MNT' => ['Maintenance Rutin', 'Perawatan terjadwal agar peralatan tetap layak pakai.'],
        'KBR' => ['Kebersihan', 'Kebersihan area kerja dan lingkungan.'],
        'KND' => ['Kendaraan', 'Servis dan administrasi kendaraan operasional.'],
    ];

    /**
     * Accounts for local visual checks (Playwright): signed in with a fixed
     * password written here, never DEFAULT_USER_PASSWORD, so no secret has to
     * be read from .env. Demo data only: the seeder refuses production. Kept
     * out of the demo timeline, so they act only when someone signs in.
     *
     * @var array<string, array{0: string, 1: string, 2: string}> email => [name, department, role]
     */
    public const array VISUAL_CHECK_ACCOUNTS = [
        'visual@worder.test' => ['Visual Check Admin', 'IT', 'admin'],
        'visual.adminwo@worder.test' => ['Visual Check Admin WO', 'GA', 'admin-wo'],
        'visual.lead@worder.test' => ['Visual Check Lead Operational', 'ENG', 'lead-operational'],
        'visual.pictimesheet@worder.test' => ['Visual Check PIC Timesheet', 'GA', 'pic-timesheet'],
        'visual.rental@worder.test' => ['Visual Check Rental', 'GA', 'rental'],
        'visual.direktur@worder.test' => ['Visual Check Direktur', 'DIR', 'direktur'],
        'visual.finance@worder.test' => ['Visual Check Finance', 'KEU', 'finance'],
        'visual.viewer@worder.test' => ['Visual Check Viewer', 'IT', 'viewer'],
    ];

    /**
     * The password of VISUAL_CHECK_ACCOUNTS. Not a secret: local demo data only.
     */
    public const string VISUAL_CHECK_PASSWORD = 'visual-check-local';

    /**
     * Unggul accounts as [name, department code, role slug, must change
     * password], one or more for every role of FLOW.md v2 §3. Lead
     * Operational is one person.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    private const array USERS = [
        ['Administrator', 'IT', 'admin', false],
        ['Dewi Lestari', 'GA', 'admin-wo', false],
        ['Ratna Wijaya', 'GA', 'admin-wo', false],
        ['Bambang Hartono', 'ENG', 'lead-operational', false],
        ['Fajar Nugroho', 'ENG', 'pic-timesheet', false],
        ['Hendra Gunawan', 'GA', 'pic-timesheet', false],
        ['Rizky Pratama', 'IT', 'pic-timesheet', false],
        ['Agus Setiawan', 'GA', 'rental', false],
        ['Hartono Wijaya', 'DIR', 'direktur', false],
        ['Sri Wahyuni', 'KEU', 'finance', false],
        ['Budi Santoso', 'KEU', 'finance', false],
        ['Andi Saputra', 'IT', 'viewer', true],
        ['Wulan Sari', 'GA', 'viewer', false],
        ['Rina Kurniawati', 'KEU', 'viewer', false],
    ];

    /**
     * The IC people who request work orders, per IC department: contact
     * names only, since IC never logs in (FLOW.md v2 §1, §4).
     *
     * @var array<string, list<string>>
     */
    private const array REQUESTER_CONTACTS = [
        'PRD' => ['Eko Purnomo', 'Indah Permatasari', 'Arif Hidayat'],
        'HRD' => ['Putri Rahmawati', 'Maya Anggraini'],
        'LOG' => ['Nur Aini', 'Rudi Hermawan'],
        'MTC' => ['Dimas Prasetyo', 'Yoga Firmansyah', 'Teguh Wibowo'],
    ];

    /**
     * Unggul staff IC contacted about a request (PIC Work Order, optional and
     * informational), recorded on every third work order.
     *
     * @var list<string>
     */
    private const array PIC_NAMES = ['Wulan Sari', 'Hendra Gunawan', 'Fajar Nugroho'];

    /**
     * Self-registrations (FLOW.md §3, Unggul email domains only) as [name,
     * department code, days ago, rejection reason or null]: three pending
     * and one rejected, for the Pendaftaran page and its sidebar badge.
     *
     * @var list<array{0: string, 1: string, 2: int, 3: string|null}>
     */
    private const array REGISTRATIONS = [
        ['Galih Saputra', 'ENG', 2, null],
        ['Siti Marlina', 'GA', 1, null],
        ['Yusuf Maulana', 'IT', 3, null],
        ['Tono Sugiarto', 'KEU', 6, 'Tidak terdaftar sebagai karyawan KEU. Hubungi atasan Anda untuk konfirmasi.'],
    ];

    /**
     * [title, description] templates per category code. Placeholders:
     * {lokasi}, {ruang}, {dept}, {plat}, {jumlah}, {hari}.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const array TEMPLATES = [
        'PRB' => [
            ['Perbaikan AC {ruang}', 'AC di {ruang} tidak dingin dan berbunyi keras sejak {hari} hari terakhir. Mohon dicek freon dan kipas outdoor.'],
            ['Perbaikan kebocoran atap {lokasi}', 'Atap {lokasi} bocor saat hujan deras dan air menetes ke lantai. Perlu penggantian seng dan penambalan talang.'],
            ['Perbaikan pintu {ruang}', 'Engsel pintu {ruang} lepas dan pintu tidak bisa dikunci. Mohon diganti agar ruangan aman.'],
            ['Perbaikan pompa air {lokasi}', 'Pompa air {lokasi} mati sejak {hari} hari terakhir sehingga suplai air ke toilet terhenti.'],
            ['Perbaikan lampu penerangan {lokasi}', 'Sebanyak {jumlah} titik lampu di {lokasi} padam. Penerangan kurang untuk aktivitas malam.'],
        ],
        'PGD' => [
            ['Pengadaan toner printer Divisi {dept}', 'Stok toner printer Divisi {dept} habis. Dibutuhkan {jumlah} unit toner untuk kebutuhan cetak bulan ini.'],
            ['Pengadaan kursi kerja Divisi {dept}', 'Kursi kerja lama sudah rusak dan tidak ergonomis. Dibutuhkan {jumlah} unit kursi pengganti.'],
            ['Pengadaan laptop staf Divisi {dept}', 'Laptop untuk staf baru Divisi {dept} belum tersedia. Mohon pengadaan {jumlah} unit sesuai standar spesifikasi IT.'],
            ['Pengadaan APD untuk {lokasi}', 'Helm, sarung tangan, dan rompi keselamatan di {lokasi} perlu ditambah untuk {jumlah} pekerja.'],
            ['Pengadaan alat tulis kantor Divisi {dept}', 'Permintaan ATK rutin Divisi {dept}: kertas A4, map, dan pulpen untuk kebutuhan satu bulan.'],
        ],
        'INS' => [
            ['Instalasi titik jaringan LAN {ruang}', 'Dibutuhkan {jumlah} titik LAN tambahan di {ruang} untuk komputer staf yang baru bergabung.'],
            ['Instalasi CCTV {lokasi}', 'Pemasangan {jumlah} kamera CCTV di {lokasi} untuk memantau keluar masuk barang.'],
            ['Instalasi stop kontak tambahan {ruang}', 'Stop kontak di {ruang} tidak mencukupi. Mohon dipasang {jumlah} titik baru dengan grounding.'],
            ['Instalasi proyektor {ruang}', 'Pemasangan proyektor dan layar permanen di {ruang} untuk kebutuhan rapat dan pelatihan.'],
        ],
        'MNT' => [
            ['Servis berkala genset {lokasi}', 'Jadwal servis 250 jam genset {lokasi}: ganti oli, filter solar, dan pengecekan aki.'],
            ['Maintenance rutin AC {ruang}', 'Pembersihan filter dan evaporator AC {ruang} sesuai jadwal perawatan tiga bulanan.'],
            ['Pengecekan APAR {lokasi}', 'Pengecekan tekanan dan masa berlaku {jumlah} tabung APAR di {lokasi}.'],
            ['Kalibrasi timbangan {lokasi}', 'Timbangan di {lokasi} sudah melewati jadwal kalibrasi tahunan. Mohon dijadwalkan kalibrasi eksternal.'],
            ['Pembersihan tangki air {lokasi}', 'Tandon air {lokasi} terakhir dibersihkan enam bulan lalu. Mohon dijadwalkan pembersihan.'],
        ],
        'KBR' => [
            ['Pembersihan saluran air {lokasi}', 'Saluran air di {lokasi} tersumbat dan air menggenang setelah hujan.'],
            ['Fogging {lokasi}', 'Banyak nyamuk di {lokasi}. Mohon dijadwalkan fogging di luar jam kerja.'],
            ['Pengangkutan sisa material {lokasi}', 'Sisa material renovasi di {lokasi} menumpuk dan menghalangi jalur evakuasi.'],
        ],
        'KND' => [
            ['Servis rutin kendaraan operasional {plat}', 'Kendaraan {plat} sudah mencapai jadwal servis 10.000 km: ganti oli, filter, dan cek rem.'],
            ['Penggantian ban forklift {lokasi}', 'Ban forklift di {lokasi} sudah aus dan tidak aman untuk mengangkat beban.'],
            ['Perpanjangan STNK kendaraan {plat}', 'STNK kendaraan {plat} habis masa berlakunya dalam {hari} hari. Mohon diproses perpanjangannya.'],
        ],
    ];

    /**
     * @var list<string>
     */
    private const array ROOMS = ['ruang rapat lt. 2', 'ruang server', 'lobi utama', 'pantry lt. 1', 'ruang arsip', 'ruang pelatihan', 'ruang direksi'];

    /**
     * @var list<string>
     */
    private const array LOCATIONS = ['gudang bahan baku', 'area produksi line 2', 'gedung B', 'area parkir', 'mess karyawan', 'workshop', 'gudang barang jadi'];

    /**
     * @var list<string>
     */
    private const array DRAFT_CANCEL_NOTES = [
        'Duplikat dengan pengajuan sebelumnya.',
        'Sudah ditangani langsung oleh tim internal.',
        'Salah memilih kategori, akan diajukan ulang.',
    ];

    /**
     * @var list<string>
     */
    private const array SUBMITTED_CANCEL_NOTES = [
        'Anggaran belum tersedia bulan ini.',
        'Dibatalkan atas permintaan pemohon.',
        'Pekerjaan digabung dengan work order lain.',
    ];

    /**
     * Reasons Lead Operational rejects a work order that Admin WO then
     * revises and resubmits, or leaves waiting.
     *
     * @var list<string>
     */
    private const array REJECT_NOTES = [
        'Deskripsi kurang jelas. Mohon lengkapi lokasi persis dan foto kondisi saat ini.',
        'Mohon lampirkan penawaran harga dari vendor sebelum diajukan.',
        'Target selesai tidak realistis untuk lingkup pekerjaan ini, mohon disesuaikan.',
    ];

    /**
     * What Admin WO adds to the description before resubmitting a rejected
     * work order.
     */
    private const string REVISION = ' Revisi: lokasi dan foto kondisi sudah dilengkapi.';

    /**
     * @var list<string>
     */
    private const array REJECTED_CANCEL_NOTES = [
        'Tidak jadi, pekerjaan ditangani langsung oleh vendor.',
        'Kebutuhan sudah tidak ada setelah evaluasi ulang.',
    ];

    /**
     * Why Rental returns a work order from Review Dokumen to Pelaksanaan.
     *
     * @var list<string>
     */
    private const array RETURN_NOTES = [
        'Foto sesudah pekerjaan belum dilampirkan. Mohon dilengkapi.',
        'Laporan harian tanggal terakhir belum mencantumkan jumlah material terpakai.',
    ];

    /**
     * Why Lead Operational cancels a work order during execution.
     *
     * @var list<string>
     */
    private const array EXECUTION_CANCEL_NOTES = [
        'IC menghentikan pekerjaan karena perubahan rencana produksi.',
        'Pekerjaan dialihkan ke vendor IC sendiri.',
    ];

    /**
     * Optional notes when Lead Operational approves a work order.
     *
     * @var list<string|null>
     */
    private const array ACCEPT_NOTES = [
        'Dijadwalkan minggu ini.',
        null,
        'Teknisi berangkat besok pagi.',
        null,
    ];

    /**
     * Notes of the daily reports (FLOW.md §7), in turn.
     *
     * @var list<string>
     */
    private const array DAILY_REPORT_NOTES = [
        'Pekerjaan berjalan sesuai jadwal. Timesheet tim lapangan terlampir.',
        'Material tiba siang hari, pemasangan dilanjutkan sore.',
        'Pengecekan akhir bagian pertama selesai, lanjut bagian berikutnya besok.',
        'Dua teknisi tambahan membantu hari ini karena target dikejar.',
        'Pekerjaan tertunda satu jam karena hujan, sisanya sesuai rencana.',
        'Area kerja dibersihkan dan dirapikan setelah pekerjaan.',
    ];

    /**
     * Appended to a report corrected the same day.
     */
    private const string DAILY_REPORT_CORRECTION = ' Koreksi: jam kerja tim B sampai 16.30.';

    /**
     * Where the timesheets live outside the application: a SharePoint folder
     * per work order; every fourth report shares a OneDrive link instead.
     */
    private const string TIMESHEET_FOLDER = 'https://unggulgroup.sharepoint.com/sites/Operasional/Shared%20Documents/Timesheet/';

    /**
     * Pelaksanaan work orders left without today's report, per path, so
     * the list and dashboard show a few "Belum lapor" after the cutoff.
     *
     * @var array<string, int> the first n of the path
     */
    private const array MISSING_TODAY = ['in_progress' => 2, 'in_progress_overdue' => 1];

    /**
     * Comment threads between the target department's PIC Timesheet
     * ('pelaksana') and the Admin WO who entered the work order
     * ('requester'), in order. A work order gets the first one to three
     * messages of one.
     *
     * @var list<list<array{0: 'pelaksana'|'requester', 1: string}>>
     */
    private const array COMMENT_THREADS = [
        [
            ['pelaksana', 'Mohon dilampirkan foto kondisi saat ini supaya bisa kami nilai.'],
            ['requester', 'Baik, Pak. Foto sudah saya unggah di bagian Dokumen.'],
            ['pelaksana', 'Terima kasih, sudah jelas.'],
        ],
        [
            ['requester', 'Mohon diprioritaskan, kondisi ini mengganggu pekerjaan tim kami setiap hari.'],
            ['pelaksana', 'Dipahami. Kami cek jadwal teknisi minggu ini.
Nanti saya kabari lagi.'],
        ],
        [
            ['pelaksana', 'Apakah sudah ada perkiraan biaya dari vendor?'],
            ['requester', 'Belum, Bu. Penawaran dari vendor baru masuk paling lambat hari Jumat.'],
            ['pelaksana', 'Oke, ditunggu. Sementara WO ini saya tahan dulu.'],
        ],
        [
            ['requester', 'Tambahan info: lokasinya di sisi timur, dekat pintu darurat.'],
            ['pelaksana', 'Noted, terima kasih infonya.'],
        ],
        [
            ['pelaksana', 'Target selesai terlalu mepet. Bisa dimundurkan satu minggu?'],
            ['requester', 'Bisa, Pak. Yang penting sebelum akhir bulan.'],
        ],
        [
            ['requester', 'Apakah perlu persetujuan kepala departemen juga untuk pekerjaan ini?'],
            ['pelaksana', 'Tidak perlu, cukup lewat WO ini.'],
        ],
    ];

    /**
     * Posted by the Admin WO on the first work order with comments, then
     * deleted a minute later, so the timeline shows "Komentar dihapus".
     */
    private const string MISTAKEN_COMMENT = 'Maaf, komentar ini untuk WO lain.';

    /**
     * The PIC Timesheet's progress report on some accepted work orders: a
     * formatted comment with an inline photo (%s) and a document attached.
     */
    private const string PROGRESS_REPORT = '<p><strong>Laporan progres</strong></p>'
        .'<ul><li><p>Pemeriksaan awal selesai.</p></li><li><p>Suku cadang sudah dipesan, perkiraan tiba <em>2 hari lagi</em>.</p></li></ul>'
        .'<p>Foto kondisi terkini:</p>%s'
        .'<p>Rincian pekerjaan terlampir. Pertanyaan bisa ke <a href="mailto:helpdesk@worder.test">helpdesk</a>.</p>';

    /**
     * How many work orders take each path through the flow (FLOW.md §5) and
     * the payment track (§10). Overdue ones have a target date that has
     * passed. Resubmitted ones were rejected and submitted again under the
     * same number; returned ones were sent back from Review Dokumen to
     * Pelaksanaan by Rental; cancelled_execution ones were cancelled by Lead
     * Operational, alternately in Pelaksanaan and in Review Dokumen.
     *
     * @var array<string, int>
     */
    private const array PATHS = [
        'draft' => 10,
        'submitted' => 9,
        'overdue' => 4,
        'resubmitted' => 3,
        'rejected' => 4,
        'rejected_cancelled' => 2,
        'cancelled_draft' => 4,
        'cancelled_submitted' => 2,
        'in_progress' => 5,
        'in_progress_overdue' => 2,
        'returned' => 2,
        'cancelled_execution' => 2,
        'in_review' => 3,
        'awaiting_bast' => 3,
        'bast_approved' => 2,
        'closed' => 3,
        'billed' => 4,
        'billed_overdue' => 2,
        'paid' => 4,
    ];

    /**
     * How far along the flow each path goes after Lead Operational approves
     * it: every later path also takes the earlier steps.
     *
     * @var array<string, int> 1 review submitted, 2 BAST submitted, 3 BAST approved, 4 closed
     */
    private const array STAGES = [
        'returned' => 1,
        'in_review' => 1,
        'awaiting_bast' => 2,
        'bast_approved' => 3,
        'closed' => 4,
        'billed' => 4,
        'billed_overdue' => 4,
        'paid' => 4,
    ];

    /**
     * Paths through Pelaksanaan: approved by Lead Operational.
     */
    private const array ACCEPTED_PATHS = ['in_progress', 'in_progress_overdue', 'returned', 'cancelled_execution', 'in_review', 'awaiting_bast', 'bast_approved', 'closed', 'billed', 'billed_overdue', 'paid'];

    /**
     * Paths Finance bills after the work order is closed.
     */
    private const array BILLED_PATHS = ['billed', 'billed_overdue', 'paid'];

    /**
     * Paths whose work orders get comments, from submission until they stop
     * taking them (a cancellation or the payment).
     */
    private const array COMMENTED_PATHS = ['submitted', 'overdue', 'in_progress', 'in_progress_overdue', 'returned', 'cancelled_execution', 'in_review', 'awaiting_bast', 'bast_approved', 'closed', 'billed', 'billed_overdue', 'paid', 'cancelled_submitted'];

    /**
     * How many work orders get each urgency: 10% rendah, 60% normal, 20%
     * tinggi, 10% mendesak. Adds up to the total of PATHS.
     *
     * @var array<string, int>
     */
    private const array URGENCIES = [
        'rendah' => 7,
        'normal' => 42,
        'tinggi' => 14,
        'mendesak' => 7,
    ];

    /**
     * Sample documents as [fixture in tests/Fixtures/attachments, name shown].
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array SAMPLE_FILES = [
        ['dokumen.pdf', 'Penawaran_Harga.pdf'],
        ['foto.jpg', 'Foto_Kondisi.jpg'],
    ];

    private Generator $faker;

    /**
     * The last invoice number given, per department and year, so numbers
     * follow the billing order of the timeline.
     *
     * @var array<string, int>
     */
    private array $invoiceSequences = [];

    public function __construct(
        private readonly CreateNewUser $createNewUser,
        private readonly RejectRegistration $rejectRegistration,
        private readonly CreateWorkOrder $createWorkOrder,
        private readonly TransitionWorkOrder $transitionWorkOrder,
        private readonly BillWorkOrder $billWorkOrder,
        private readonly CorrectInvoice $correctInvoice,
        private readonly ConfirmWorkOrderPayment $confirmPayment,
        private readonly AddAttachment $addAttachment,
        private readonly AddWorkOrderComment $addComment,
        private readonly UpdateWorkOrderComment $updateComment,
        private readonly DeleteWorkOrderComment $deleteComment,
        private readonly AddDailyReport $addDailyReport,
        private readonly UpdateDailyReport $updateDailyReport,
    ) {}

    /**
     * Run the database seeds.
     *
     * @throws RuntimeException in production or without DEFAULT_USER_PASSWORD
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }

        $password = config('auth.default_user_password');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Set DEFAULT_USER_PASSWORD before running DemoSeeder.');
        }

        $this->faker = FakerFactory::create('id_ID');
        $this->faker->seed(self::FAKER_SEED);

        $this->call(RolePermissionSeeder::class);
        $this->purgeOrphanedAttachments();

        // Published before the oldest demo work order, as the BASTs are generated from it.
        $now = Date::getTestNow();

        try {
            Date::setTestNow(now()->subMonths(4));
            $this->call(BastTemplateSeeder::class);
        } finally {
            Date::setTestNow($now);
        }

        $departments = $this->seedDepartments($this->seedCompanies());
        $categories = $this->seedCategories();
        $users = $this->seedUsers($departments, $password);
        $this->seedVisualCheckAccounts($departments);

        try {
            $this->seedRegistrations($departments, $password, $users->sole(fn (User $user): bool => $user->hasRole('admin')));
        } finally {
            Date::setTestNow();
            Auth::forgetUser();
        }

        if (WorkOrder::withTrashed()->whereIn('created_by', $users->pluck('id'))->exists()) {
            $this->command->info('Demo work orders already exist, skipped. Use migrate:fresh --seeder=DemoSeeder for a clean refresh.');

            return;
        }

        try {
            DB::transaction(fn () => $this->runTimeline($this->planWorkOrders($users, $categories)));
        } finally {
            Date::setTestNow();
            Auth::forgetUser();
        }
    }

    /**
     * The demo email for an account: admin@worder.test for the admin,
     * first.last@ followed by their company's domain for everyone else.
     */
    public static function emailFor(string $name, string $role, string $domain = self::EMAIL_DOMAIN): string
    {
        $local = $role === 'admin' ? 'admin' : str($name)->lower()->replace(' ', '.')->toString();

        return $local.'@'.$domain;
    }

    /**
     * In a fresh database (no media, no work orders) every media directory
     * on the attachments disk is an orphan left by migrate:fresh, so it is
     * removed. Only {media uuid}/ directories are touched, the layout
     * AttachmentPathGenerator writes; anything else on the disk stays.
     */
    private function purgeOrphanedAttachments(): void
    {
        if (Media::query()->exists() || WorkOrder::withTrashed()->exists()) {
            return;
        }

        $disk = Storage::disk(config()->string('media-library.disk_name'));

        foreach ($disk->directories() as $directory) {
            if (Str::isUuid($directory)) {
                $disk->deleteDirectory($directory);
            }
        }
    }

    /**
     * @return Collection<string, Company> keyed by code
     */
    private function seedCompanies(): Collection
    {
        return collect(self::COMPANIES)->map(
            fn (array $company, string $code): Company => Company::query()->firstOrCreate(['code' => $code], [
                'name' => $company[0],
                'is_client' => $company[1],
                'email_domains' => [$company[2]],
            ]),
        );
    }

    /**
     * @param  Collection<string, Company>  $companies
     * @return Collection<string, Department> keyed by code
     */
    private function seedDepartments(Collection $companies): Collection
    {
        return collect(self::DEPARTMENTS)->map(
            fn (array $department, string $code): Department => Department::query()->firstOrCreate(['code' => $code], [
                'name' => $department[0],
                'company_id' => $companies[$department[1]]->id,
            ]),
        );
    }

    /**
     * @return Collection<string, WorkOrderCategory> keyed by code
     */
    private function seedCategories(): Collection
    {
        return collect(self::CATEGORIES)->map(
            fn (array $category, string $code): WorkOrderCategory => WorkOrderCategory::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $category[0], 'description' => $category[1]],
            ),
        );
    }

    /**
     * @param  Collection<string, Department>  $departments
     * @return Collection<int, User>
     */
    private function seedUsers(Collection $departments, string $password): Collection
    {
        return collect(self::USERS)->map(function (array $account) use ($departments, $password): User {
            [$name, $departmentCode, $role, $mustChangePassword] = $account;

            $department = $departments[$departmentCode];
            $domain = self::COMPANIES[self::DEPARTMENTS[$departmentCode][1]][2];

            $user = User::query()->firstOrCreate(['email' => self::emailFor($name, $role, $domain)], [
                'name' => $name,
                'password' => $password,
                'must_change_password' => $mustChangePassword,
                'department_id' => $department->id,
            ]);

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }

            return $user;
        });
    }

    /**
     * The visual-check accounts, created when missing (an existing one keeps
     * its password), without a forced password change.
     *
     * @param  Collection<string, Department>  $departments
     */
    private function seedVisualCheckAccounts(Collection $departments): void
    {
        foreach (self::VISUAL_CHECK_ACCOUNTS as $email => [$name, $departmentCode, $role]) {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => self::VISUAL_CHECK_PASSWORD,
                'must_change_password' => false,
                'department_id' => $departments[$departmentCode]->id,
            ]);

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }
    }

    /**
     * Registers the demo self-registrations through the real action (pending,
     * "registered" logged), then rejects one as the admin. Skipped for an
     * email that already exists.
     *
     * @param  Collection<string, Department>  $departments
     */
    private function seedRegistrations(Collection $departments, string $password, User $admin): void
    {
        foreach (self::REGISTRATIONS as [$name, $departmentCode, $daysAgo, $rejectionReason]) {
            $department = $departments[$departmentCode];
            $email = self::emailFor($name, 'registration', self::COMPANIES[self::DEPARTMENTS[$departmentCode][1]][2]);

            if (User::withTrashed()->withEmail($email)->exists()) {
                continue;
            }

            $registeredAt = now()->subDays($daysAgo)->setTime(8 + $daysAgo, 15);
            Date::setTestNow($registeredAt);
            Auth::forgetUser();

            $user = $this->createNewUser->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
                'department_id' => $department->id,
            ]);

            if ($rejectionReason !== null) {
                Date::setTestNow($registeredAt->addHours(3));
                Auth::setUser($admin);
                $this->rejectRegistration->handle($user, $admin, $rejectionReason);
            }
        }
    }

    /**
     * Every create, upload, comment, and transition with its moment and
     * actor, oldest first. Events at the same moment keep their planned order.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<string, WorkOrderCategory>  $categories
     * @return list<array{at: CarbonImmutable, actor: User, run: Closure(): void}>
     */
    private function planWorkOrders(Collection $users, Collection $categories): array
    {
        $withRole = fn (string $role): array => $users->filter(fn (User $user): bool => $user->hasRole($role))->values()->all();
        $adminWos = $withRole('admin-wo');
        $lead = $users->sole(fn (User $user): bool => $user->hasRole('lead-operational'));
        $rental = $users->sole(fn (User $user): bool => $user->hasRole('rental'));
        $direktur = $users->sole(fn (User $user): bool => $user->hasRole('direktur'));
        $finance = $withRole('finance');
        $picByDepartment = $users
            ->filter(fn (User $user): bool => $user->hasRole('pic-timesheet'))
            ->groupBy(fn (User $user): string => $user->department->code);
        $requesterDepartments = Department::query()->whereIn('code', array_keys(self::REQUESTER_CONTACTS))->get()->keyBy('code');

        $paths = $this->faker->shuffleArray(collect(self::PATHS)->flatMap(fn (int $count, string $path): array => array_fill(0, $count, $path))->all());
        $urgencies = $this->faker->shuffleArray(collect(self::URGENCIES)->flatMap(fn (int $count, string $urgency): array => array_fill(0, $count, $urgency))->all());

        /** @var array<int, WorkOrder> $created */
        $created = [];
        $events = [];
        $withComments = 0;
        /** @var array<string, int> $perPath how many work orders of each path came before */
        $perPath = [];

        foreach ($paths as $index => $path) {
            // Admin WO enters every work order for an IC department and contact
            // (FLOW.md v2 §4), and acts as its requester side afterwards.
            /** @var User $actor */
            $actor = $this->faker->randomElement($adminWos);
            /** @var string $departmentCode */
            $departmentCode = $this->faker->randomElement(array_keys(self::REQUESTER_CONTACTS));
            $requesterDepartment = $requesterDepartments[$departmentCode];
            /** @var string $contactName */
            $contactName = $this->faker->randomElement(self::REQUESTER_CONTACTS[$departmentCode]);
            /** @var string $categoryCode */
            $categoryCode = $this->faker->randomElement(array_keys(self::TEMPLATES));
            $targetDepartmentCode = self::CATEGORY_DEPARTMENTS[$categoryCode];
            /** @var User $pic */
            $pic = $this->faker->randomElement($picByDepartment[$targetDepartmentCode]->all());

            $createdAt = $this->creationMoment($path);
            $attributes = $this->workOrderAttributes($path, $categoryCode, $requesterDepartment, $createdAt)
                + [
                    'requester_department_id' => $requesterDepartment->id,
                    'requester_name' => $contactName,
                    'pic_name' => $index % 3 === 0 ? self::PIC_NAMES[intdiv($index, 3) % count(self::PIC_NAMES)] : null,
                    'work_order_category_id' => $categories[$categoryCode]->id,
                    'urgency' => $urgencies[$index],
                    // Informational only; every third draft leaves it empty.
                    'target_department_id' => $path === 'draft' && $index % 3 === 0 ? null : $pic->department_id,
                ];

            $events[] = ['at' => $createdAt, 'actor' => $actor, 'run' => function () use (&$created, $index, $attributes, $actor): void {
                $created[$index] = $this->createWorkOrder->handle($attributes, $actor);
            }];

            if ($index % 4 === 0) {
                foreach ($this->sampleFilesFor($index) as $sample) {
                    $events[] = ['at' => $createdAt->addMinutes(2), 'actor' => $actor, 'run' => function () use (&$created, $index, $sample, $actor): void {
                        $this->attachSample($created[$index], $sample, $actor);
                    }];
                }
            }

            $nth = $perPath[$path] = ($perPath[$path] ?? 0) + 1;

            // Each step some minutes to days after the one before: at most
            // 20 days after creation for a paid one, within creationMoment()'s margin.
            $submittedAt = $createdAt->addMinutes($this->faker->numberBetween(30, 3 * 24 * 60));
            $decidedAt = $submittedAt->addMinutes($this->faker->numberBetween(60, 2 * 24 * 60));
            $revisedAt = $decidedAt->addMinutes($this->faker->numberBetween(60, 2 * 24 * 60));
            // After the progress photo and report a day into the work.
            $reviewAt = $decidedAt->addMinutes($this->faker->numberBetween(2 * 24 * 60, 5 * 24 * 60));
            $reviewedAt = $reviewAt->addMinutes($this->faker->numberBetween(2 * 60, 24 * 60));
            $bastApprovedAt = $reviewedAt->addMinutes($this->faker->numberBetween(2 * 60, 24 * 60));
            $closedAt = $bastApprovedAt->addMinutes($this->faker->numberBetween(60, 24 * 60));
            $billedAt = $closedAt->addMinutes($this->faker->numberBetween(24 * 60, 3 * 24 * 60));
            $paidAt = $billedAt->addMinutes($this->faker->numberBetween(24 * 60, 4 * 24 * 60));
            // Alternately during Pelaksanaan (after the progress photo) and during Review Dokumen.
            $cancelledInReview = $path === 'cancelled_execution' && $nth % 2 === 0;
            $executionCancelledAt = $cancelledInReview ? $reviewedAt : $decidedAt->addMinutes($this->faker->numberBetween(2 * 24 * 60, 4 * 24 * 60));

            if ($path === 'cancelled_draft') {
                $events[] = $this->transitionEvent($created, $index, $submittedAt, $actor, Dibatalkan::getMorphClass(), $this->faker->randomElement(self::DRAFT_CANCEL_NOTES));

                continue;
            }

            if ($path === 'draft') {
                continue;
            }

            $events[] = $this->transitionEvent($created, $index, $submittedAt, $actor, Diajukan::getMorphClass());

            // Comments start once the work order is submitted, when every
            // role sees it, and end before a cancellation or the payment
            // (Lunas), which make them read-only.
            if (in_array($path, self::COMMENTED_PATHS, true) && in_array($index % 5, [1, 2, 3], true)) {
                $until = match ($path) {
                    'cancelled_submitted' => $decidedAt,
                    'cancelled_execution' => $executionCancelledAt,
                    'paid' => $paidAt,
                    default => CarbonImmutable::now()->subHour(),
                };
                array_push($events, ...$this->planComments($created, $index, $submittedAt, $until, $actor, $pic, $withComments++ === 0));
            }

            if ($path === 'cancelled_submitted') {
                $events[] = $this->transitionEvent($created, $index, $decidedAt, $actor, Dibatalkan::getMorphClass(), $this->faker->randomElement(self::SUBMITTED_CANCEL_NOTES));
            }

            $stage = self::STAGES[$path] ?? ($cancelledInReview ? 1 : 0);

            if (in_array($path, self::ACCEPTED_PATHS, true)) {
                array_push($events, ...$this->planAcceptance($created, $index, $decidedAt, $lead, $pic));

                // Daily reports while in Pelaksanaan: until review, a cancellation, or now.
                $executionEnd = match (true) {
                    $stage >= 1 => $reviewAt,
                    $path === 'cancelled_execution' => $executionCancelledAt,
                    default => CarbonImmutable::now(),
                };
                array_push($events, ...$this->planDailyReports($created, $index, $decidedAt, $executionEnd, $pic, $nth <= (self::MISSING_TODAY[$path] ?? 0)));

                if ($path === 'returned') {
                    array_push($events, ...$this->planDailyReports($created, $index, $reviewedAt, CarbonImmutable::now(), $pic, false));
                }
            }

            if ($stage >= 1) {
                $events[] = $this->transitionEvent($created, $index, $reviewAt, $pic, ReviewDokumen::getMorphClass());
            }

            if ($path === 'returned') {
                $events[] = $this->transitionEvent($created, $index, $reviewedAt, $rental, Pelaksanaan::getMorphClass(), self::RETURN_NOTES[$nth % count(self::RETURN_NOTES)]);
            }

            if ($path === 'cancelled_execution') {
                $events[] = $this->transitionEvent($created, $index, $executionCancelledAt, $lead, Dibatalkan::getMorphClass(), self::EXECUTION_CANCEL_NOTES[$nth % count(self::EXECUTION_CANCEL_NOTES)]);
            }

            if ($stage >= 2) {
                $events[] = $this->transitionEvent($created, $index, $reviewedAt, $rental, ApprovalBast::getMorphClass());
            }

            if ($stage >= 3) {
                $events[] = $this->transitionEvent($created, $index, $bastApprovedAt, $direktur, BastDisetujui::getMorphClass());
            }

            if ($stage >= 4) {
                $events[] = $this->transitionEvent($created, $index, $closedAt, $actor, Closed::getMorphClass());
            }

            if (in_array($path, self::BILLED_PATHS, true)) {
                /** @var User $biller */
                $biller = $this->faker->randomElement($finance);
                array_push($events, ...$this->planBilling($created, $index, $path, $nth, $billedAt, $biller));
            }

            if ($path === 'paid') {
                /** @var User $confirmer */
                $confirmer = $this->faker->randomElement($finance);
                // Every other payment comes with the transfer receipt: counted among the
                // paid work orders, not by position in the shuffled plan, so exactly half do.
                $withProof = $nth % 2 === 0;
                $events[] = ['at' => $paidAt, 'actor' => $confirmer, 'run' => function () use (&$created, $index, $confirmer, $paidAt, $withProof): void {
                    $proof = $withProof ? [$this->sampleUpload(['foto.jpg', 'Bukti_Transfer.jpg'])] : [];
                    $this->confirmPayment->handle($created[$index], $confirmer, DisplayDate::local($paidAt)->toDateString(), $proof);
                }];
            }

            if (in_array($path, ['rejected', 'resubmitted', 'rejected_cancelled'], true)) {
                $events[] = $this->transitionEvent($created, $index, $decidedAt, $lead, Ditolak::getMorphClass(), $this->faker->randomElement(self::REJECT_NOTES));
            }

            if ($path === 'resubmitted') {
                $events[] = ['at' => $revisedAt, 'actor' => $actor, 'run' => function () use (&$created, $index): void {
                    $workOrder = $created[$index]->refresh();
                    $workOrder->update(['description' => $workOrder->description.self::REVISION]);
                }];
                $events[] = $this->transitionEvent($created, $index, $revisedAt->addMinutes(2), $actor, Diajukan::getMorphClass());
            }

            if ($path === 'rejected_cancelled') {
                $events[] = $this->transitionEvent($created, $index, $revisedAt, $actor, Dibatalkan::getMorphClass(), $this->faker->randomElement(self::REJECTED_CANCEL_NOTES));
            }
        }

        usort($events, fn (array $a, array $b): int => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        return $events;
    }

    /**
     * A status change of the work order created at $index, by $actor at $at.
     *
     * @param  array<int, WorkOrder>  $created  filled while the timeline runs
     * @return array{at: CarbonImmutable, actor: User, run: Closure(): void}
     */
    private function transitionEvent(array &$created, int $index, CarbonImmutable $at, User $actor, string $to, ?string $note = null): array
    {
        return ['at' => $at, 'actor' => $actor, 'run' => function () use (&$created, $index, $actor, $to, $note): void {
            $this->transitionWorkOrder->handle($created[$index], $to, $actor, $note);
        }];
    }

    /**
     * Lead Operational approves the work order, sometimes with a note. On
     * every other one the target department's PIC Timesheet uploads a
     * progress photo a day later (PIC Timesheet adds documents during
     * Pelaksanaan, FLOW.md §5.3), and on every third one posts a formatted
     * progress report with an inline photo and a document (FLOW.md §9),
     * uploaded and claimed through the real actions.
     *
     * @param  array<int, WorkOrder>  $created  filled while the timeline runs
     * @return list<array{at: CarbonImmutable, actor: User, run: Closure(): void}>
     */
    private function planAcceptance(array &$created, int $index, CarbonImmutable $at, User $lead, User $pic): array
    {
        $events = [$this->transitionEvent($created, $index, $at, $lead, Pelaksanaan::getMorphClass(), self::ACCEPT_NOTES[$index % count(self::ACCEPT_NOTES)])];

        if ($index % 2 === 0) {
            $events[] = ['at' => $at->addDay(), 'actor' => $pic, 'run' => function () use (&$created, $index, $pic): void {
                $this->attachSample($created[$index], ['foto.jpg', 'Foto_Progres.jpg'], $pic);
            }];
        }

        if ($index % 3 === 1) {
            $events[] = ['at' => $at->addHours(20), 'actor' => $pic, 'run' => function () use (&$created, $index, $pic): void {
                $this->postProgressReport($created[$index], $pic);
            }];
        }

        return $events;
    }

    /**
     * PIC Timesheet's daily reports (FLOW.md §7) from the day after $from
     * until $until: on most working days at about 15:30 WITA (today's only
     * once that moment has passed, or earlier when it is already past 09:00),
     * with a link to the timesheet and, on every third, the timesheet itself.
     * With none planned before $until (a short period over a weekend), one
     * shortly before $until, since review needs a report. The first report
     * of every fifth work order is corrected twenty minutes later.
     *
     * @param  array<int, WorkOrder>  $created  filled while the timeline runs
     * @param  bool  $skipToday  leave today unreported ("Belum lapor" after the cutoff)
     * @return list<array{at: CarbonImmutable, actor: User, run: Closure(): void}>
     */
    private function planDailyReports(array &$created, int $index, CarbonImmutable $from, CarbonImmutable $until, User $pic, bool $skipToday): array
    {
        $now = CarbonImmutable::now();
        $today = DisplayDate::today();
        $last = DisplayDate::local($until->min($now))->startOfDay();
        $moments = [];

        for ($day = DisplayDate::local($from)->startOfDay()->addDay(); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $date = $day->toDateString();

            // An occasional working day goes unreported.
            if (! ReportCalendar::isWorkingDay($date) || ($index + $day->dayOfYear) % 7 === 0 || ($date === $today && $skipToday)) {
                continue;
            }

            $at = $day->setTime(15, 30 + $index % 25)->utc();

            if ($date === $today && $at->greaterThanOrEqualTo($now) && $now->greaterThan($day->setTime(9, 0))) {
                $at = $now->subMinutes(30);
            }

            if ($at->lessThan($until) && $at->lessThan($now)) {
                $moments[] = $at;
            }
        }

        if ($moments === [] && $until->lessThan($now)) {
            $moments[] = $until->subHours(2);
        }

        $events = [];
        /** @var array<int, WorkOrderDailyReport> $reports */
        $reports = [];

        foreach ($moments as $nth => $at) {
            $events[] = ['at' => $at, 'actor' => $pic, 'run' => function () use (&$created, &$reports, $index, $nth, $at, $pic): void {
                $workOrder = $created[$index];
                $date = DisplayDate::local($at)->toDateString();
                $stamp = str_replace('-', '', $date);
                $withFile = $nth % 3 === 2;
                $link = $nth % 4 === 3
                    ? 'https://1drv.ms/x/s!Ag'.substr(md5($workOrder->number.$date), 0, 14)
                    : self::TIMESHEET_FOLDER.rawurlencode(str_replace('/', '-', (string) $workOrder->number)).'/Timesheet_'.$stamp.'.xlsx';

                $reports[$nth] = $this->addDailyReport->handle($workOrder, $pic, [
                    'report_date' => $date,
                    'note' => self::DAILY_REPORT_NOTES[($index + $nth) % count(self::DAILY_REPORT_NOTES)],
                    // A file alone on every other report with one.
                    'links' => $withFile && $nth % 2 === 0 ? [] : [$link],
                ], $withFile ? [$this->sampleUpload(['timesheet.xlsx', 'Timesheet_'.$stamp.'.xlsx'])] : []);
            }];
        }

        $correctedAt = isset($moments[0]) ? $moments[0]->addMinutes(20) : null;

        if ($index % 5 === 0 && $correctedAt !== null && $correctedAt->lessThan($until) && $correctedAt->lessThan($now)) {
            $events[] = ['at' => $correctedAt, 'actor' => $pic, 'run' => function () use (&$created, &$reports, $index, $pic): void {
                $report = $reports[0];
                $this->updateDailyReport->handle($created[$index], $report, $pic, [
                    'note' => $report->note.self::DAILY_REPORT_CORRECTION,
                    'links' => $report->links,
                ]);
            }];
        }

        return $events;
    }

    /**
     * Finance bills the closed work order (FLOW.md §10): an invoice file, an
     * amount, and a due date 30 days on (14 when it is meant to be past by
     * now). Of the ones still waiting for payment, the first has neither
     * amount nor due date, and the second gets its amount corrected by the
     * same Finance user a few hours later.
     *
     * @param  array<int, WorkOrder>  $created  filled while the timeline runs
     * @param  int  $nth  this work order's place among those of its path, from 1
     * @return list<array{at: CarbonImmutable, actor: User, run: Closure(): void}>
     */
    private function planBilling(array &$created, int $index, string $path, int $nth, CarbonImmutable $at, User $biller): array
    {
        $bare = $path === 'billed' && $nth === 1;
        $invoiceDate = DisplayDate::local($at)->toDateString();
        $amount = $bare ? null : (string) ($this->faker->numberBetween(5, 250) * 50_000);
        $dueDate = match (true) {
            $bare => null,
            $path === 'billed_overdue' => DisplayDate::local($at)->addDays(14)->toDateString(),
            default => DisplayDate::local($at)->addDays(30)->toDateString(),
        };

        $events = [['at' => $at, 'actor' => $biller, 'run' => function () use (&$created, $index, $biller, $invoiceDate, $amount, $dueDate): void {
            $workOrder = $created[$index];
            $department = (string) $workOrder->targetDepartment?->code;
            $year = substr($invoiceDate, 0, 4);
            $sequence = $this->invoiceSequences[$department.$year] = ($this->invoiceSequences[$department.$year] ?? 0) + 1;

            $created[$index] = $this->billWorkOrder->handle(
                $workOrder,
                $biller,
                [
                    'number' => sprintf('INV/UGL/%s/%s/%03d', $department, $year, $sequence),
                    'invoice_date' => $invoiceDate,
                    'amount' => $amount,
                    'due_date' => $dueDate,
                ],
                [$this->sampleUpload(['dokumen.pdf', "Invoice_{$department}_{$sequence}.pdf"])],
            );
        }]];

        if ($path === 'billed' && $nth === 2 && $amount !== null) {
            $events[] = ['at' => $at->addHours(3), 'actor' => $biller, 'run' => function () use (&$created, $index, $biller, $amount): void {
                $invoice = $created[$index]->invoice()->sole();

                $this->correctInvoice->handle($created[$index], $biller, [
                    'number' => $invoice->number,
                    'invoice_date' => $invoice->invoice_date->toDateString(),
                    'amount' => (string) ((int) $amount + 250_000),
                    'due_date' => $invoice->due_date?->toDateString(),
                ]);
            }];
        }

        return $events;
    }

    /**
     * One to three messages of a thread, from 20 minutes after submission until
     * $until, each some minutes to hours after the last. Every third message
     * is corrected right after posting, within the edit window. With
     * $mistaken the Admin WO ($requester) first posts a comment and deletes
     * it; $pelaksana is the target department's PIC Timesheet.
     *
     * @param  array<int, WorkOrder>  $created  filled while the timeline runs
     * @return list<array{at: CarbonImmutable, actor: User, run: Closure(): void}>
     */
    private function planComments(array &$created, int $index, CarbonImmutable $submittedAt, CarbonImmutable $until, User $requester, User $pelaksana, bool $mistaken): array
    {
        /** @var list<array{0: 'pelaksana'|'requester', 1: string}> $thread */
        $thread = $this->faker->randomElement(self::COMMENT_THREADS);
        $messages = array_slice($thread, 0, $this->faker->numberBetween(1, count($thread)));
        $latestAt = $until->subMinutes(10);
        $at = $submittedAt->addMinutes(20);
        $events = [];

        if ($mistaken && $at->lessThan($latestAt)) {
            /** @var WorkOrderComment|null $comment */
            $comment = null;
            $events[] = ['at' => $at, 'actor' => $requester, 'run' => function () use (&$created, &$comment, $index, $requester): void {
                $comment = $this->addComment->handle($created[$index], $requester, CommentHtml::fromPlainText(self::MISTAKEN_COMMENT));
            }];
            $events[] = ['at' => $at->addMinute(), 'actor' => $requester, 'run' => function () use (&$comment, $requester): void {
                $this->deleteComment->handle($comment, $requester);
            }];
            $at = $at->addMinutes(5);
            unset($comment);
        }

        foreach ($messages as $position => [$role, $body]) {
            if ($position > 0) {
                $at = $at->addMinutes($this->faker->numberBetween(15, 6 * 60));
            }

            if ($at->greaterThan($latestAt)) {
                break;
            }

            $author = $role === 'pelaksana' ? $pelaksana : $requester;
            $edited = ($index + $position) % 3 === 0;
            $firstDraft = $edited ? Str::beforeLast($body, ' ') : $body;

            /** @var WorkOrderComment|null $comment */
            $comment = null;
            $events[] = ['at' => $at, 'actor' => $author, 'run' => function () use (&$created, &$comment, $index, $author, $firstDraft): void {
                $comment = $this->addComment->handle($created[$index], $author, CommentHtml::fromPlainText($firstDraft));
            }];

            if ($edited) {
                $events[] = ['at' => $at->addMinutes(3), 'actor' => $author, 'run' => function () use (&$comment, $author, $body): void {
                    $this->updateComment->handle($comment, $author, CommentHtml::fromPlainText($body));
                }];
            }

            unset($comment);
        }

        return $events;
    }

    /**
     * Runs each event with the clock at its moment and its actor signed in,
     * so timestamps, numbers, and the activity log causer are historical.
     *
     * @param  list<array{at: CarbonImmutable, actor: User, run: Closure(): void}>  $events
     */
    private function runTimeline(array $events): void
    {
        foreach ($events as $event) {
            Date::setTestNow($event['at']);
            Auth::setUser($event['actor']);

            ($event['run'])();
        }
    }

    /**
     * A moment in WITA working hours, far enough back that every later step
     * of the path is in the past (see planWorkOrders()): up to 7 days of
     * steps before execution, 13 to closing, 16 to billing, and 20 to
     * payment. Overdue ones at least 30 days ago so their target has passed.
     */
    private function creationMoment(string $path): CarbonImmutable
    {
        // Billed ones are due 30 days after the invoice, so ones meant to be
        // still due are at most 30 days old; ones meant to be past due (14
        // days) at least 40.
        [$minDaysAgo, $maxDaysAgo] = match ($path) {
            'draft' => [1, self::HISTORY_DAYS],
            'overdue', 'in_progress_overdue' => [30, self::HISTORY_DAYS],
            'returned', 'cancelled_execution', 'in_review', 'awaiting_bast', 'bast_approved', 'closed' => [14, self::HISTORY_DAYS],
            'billed' => [18, 30],
            'billed_overdue' => [40, self::HISTORY_DAYS],
            'paid' => [22, self::HISTORY_DAYS],
            default => [8, self::HISTORY_DAYS],
        };

        return CarbonImmutable::now(DisplayDate::timezone())
            ->startOfDay()
            ->subDays($this->faker->numberBetween($minDaysAgo, $maxDaysAgo))
            ->setTime($this->faker->numberBetween(8, 15), $this->faker->numberBetween(0, 59), $this->faker->numberBetween(0, 59))
            ->utc();
    }

    /**
     * @return array{title: string, description: string, target_date: string|null}
     */
    private function workOrderAttributes(string $path, string $categoryCode, Department $requesterDepartment, CarbonImmutable $createdAt): array
    {
        /** @var array{0: string, 1: string} $template */
        $template = $this->faker->randomElement(self::TEMPLATES[$categoryCode]);

        $replacements = [
            '{lokasi}' => (string) $this->faker->randomElement(self::LOCATIONS),
            '{ruang}' => (string) $this->faker->randomElement(self::ROOMS),
            '{dept}' => $requesterDepartment->name,
            '{plat}' => strtoupper($this->faker->bothify('DA #### ??')),
            '{jumlah}' => (string) $this->faker->numberBetween(2, 12),
            '{hari}' => (string) $this->faker->numberBetween(2, 7),
        ];

        return [
            'title' => strtr($template[0], $replacements),
            'description' => strtr($template[1], $replacements),
            'target_date' => $this->targetDate($path, $createdAt),
        ];
    }

    /**
     * Overdue work orders get a target 5–14 days after creation, which has
     * passed; others none or one still ahead. A date only, in WITA.
     */
    private function targetDate(string $path, CarbonImmutable $createdAt): ?string
    {
        if (in_array($path, ['overdue', 'in_progress_overdue'], true)) {
            return DisplayDate::local($createdAt)->addDays($this->faker->numberBetween(5, 14))->toDateString();
        }

        if ($this->faker->boolean(35)) {
            return null;
        }

        return CarbonImmutable::now(DisplayDate::timezone())->addDays($this->faker->numberBetween(3, 45))->toDateString();
    }

    /**
     * In turn a PDF, a photo, or both.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function sampleFilesFor(int $index): array
    {
        return match (intdiv($index, 4) % 3) {
            0 => [self::SAMPLE_FILES[0]],
            1 => [self::SAMPLE_FILES[1]],
            default => self::SAMPLE_FILES,
        };
    }

    /**
     * Uploads the report's photo and document as the PIC Timesheet's
     * pending comment uploads, then posts the comment that claims them.
     */
    private function postProgressReport(WorkOrder $workOrder, User $pic): void
    {
        $collections = $workOrder->attachmentCollections();
        $photo = $this->addAttachment->handle($workOrder, $collections[WorkOrder::COMMENT_IMAGE_UPLOADS], $this->sampleUpload(['kondisi.jpg', 'Kondisi_Terkini.jpg']), $pic);
        $details = $this->addAttachment->handle($workOrder, $collections[WorkOrder::COMMENT_FILE_UPLOADS], $this->sampleUpload(['dokumen.pdf', 'Rincian_Pekerjaan.pdf']), $pic);

        $image = '<img src="/attachments/'.$photo->uuid.'" alt="'.e($photo->name).'">';
        $this->addComment->handle($workOrder, $pic, sprintf(self::PROGRESS_REPORT, $image), [$details->uuid]);
    }

    /**
     * Uploads a sample to the work order's documents.
     *
     * @param  array{0: string, 1: string}  $sample
     */
    private function attachSample(WorkOrder $workOrder, array $sample, User $uploader): void
    {
        $this->addAttachment->handle($workOrder, $workOrder->documentsCollection(), $this->sampleUpload($sample), $uploader);
    }

    /**
     * A copy of the fixture as an upload, because media-library moves the
     * file it is given.
     *
     * @param  array{0: string, 1: string}  $sample  fixture in tests/Fixtures/attachments, name shown
     */
    private function sampleUpload(array $sample): UploadedFile
    {
        [$fixture, $clientName] = $sample;

        $copy = (string) tempnam(sys_get_temp_dir(), 'demo-attachment');
        copy(base_path('tests/Fixtures/attachments/'.$fixture), $copy);

        return new UploadedFile($copy, $clientName, null, null, true);
    }
}
