<?php

namespace Database\Seeders;

use App\Actions\Attachments\AddAttachment;
use App\Actions\WorkOrders\CreateWorkOrder;
use App\Actions\WorkOrders\TransitionWorkOrder;
use App\Models\Department;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\States\WorkOrder\Diajukan;
use App\States\WorkOrder\Dibatalkan;
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
 * Demo data for local development and visual checks: departments, accounts
 * for every role, categories, and work orders spread over the last three
 * months. Work orders go through CreateWorkOrder, AddAttachment, and
 * TransitionWorkOrder at their historical moments, in chronological order,
 * so numbers, status history, and the activity log match real use.
 *
 * Never runs in production. Safe to re-run: master data and accounts are
 * created only when missing (existing accounts are not touched), and work
 * orders only while no demo account has any.
 */
class DemoSeeder extends Seeder
{
    public const string EMAIL_DOMAIN = 'worder.test';

    /**
     * How many days back the oldest work order may be created.
     */
    private const int HISTORY_DAYS = 85;

    private const int FAKER_SEED = 20260926;

    /**
     * @var array<string, string>
     */
    private const array DEPARTMENTS = [
        'KEU' => 'Keuangan',
        'GA' => 'General Affair',
        'ENG' => 'Engineering',
        'PRD' => 'Produksi',
        'HRD' => 'Human Resources',
        'IT' => 'Information Technology',
        'LOG' => 'Logistik',
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
     * Accounts as [name, department code, role, must change password]. Every
     * department has an approver and at least one pemohon.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    private const array USERS = [
        ['Administrator', 'IT', 'admin', false],
        ['Rizky Pratama', 'IT', 'approver', false],
        ['Dewi Lestari', 'IT', 'pemohon', false],
        ['Andi Saputra', 'IT', 'pemohon', true],
        ['Sri Wahyuni', 'KEU', 'approver', false],
        ['Budi Santoso', 'KEU', 'keuangan', false],
        ['Rina Kurniawati', 'KEU', 'keuangan', false],
        ['Agus Setiawan', 'KEU', 'pemohon', false],
        ['Hendra Gunawan', 'GA', 'approver', false],
        ['Siti Nurhaliza', 'GA', 'pemohon', false],
        ['Joko Susilo', 'GA', 'pemohon', false],
        ['Wulan Sari', 'GA', 'viewer', false],
        ['Bambang Hartono', 'ENG', 'approver', false],
        ['Dimas Prasetyo', 'ENG', 'pemohon', false],
        ['Yoga Firmansyah', 'ENG', 'pemohon', true],
        ['Teguh Wibowo', 'ENG', 'pemohon', false],
        ['Fajar Nugroho', 'ENG', 'viewer', false],
        ['Slamet Riyadi', 'PRD', 'approver', false],
        ['Eko Purnomo', 'PRD', 'pemohon', false],
        ['Indah Permatasari', 'PRD', 'pemohon', false],
        ['Arif Hidayat', 'PRD', 'pemohon', false],
        ['Maya Anggraini', 'HRD', 'approver', false],
        ['Putri Rahmawati', 'HRD', 'pemohon', false],
        ['Lukman Hakim', 'HRD', 'viewer', true],
        ['Hadi Kusuma', 'LOG', 'approver', false],
        ['Nur Aini', 'LOG', 'pemohon', false],
        ['Rudi Hermawan', 'LOG', 'pemohon', false],
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
     * How many work orders take each path through the current flow. Overdue
     * ones are submitted with a target date that has passed.
     *
     * @var array<string, int>
     */
    private const array PATHS = [
        'draft' => 15,
        'submitted' => 20,
        'overdue' => 5,
        'cancelled_draft' => 6,
        'cancelled_submitted' => 4,
    ];

    /**
     * How many work orders get each urgency: 10% rendah, 60% normal, 20%
     * tinggi, 10% mendesak. Adds up to the total of PATHS.
     *
     * @var array<string, int>
     */
    private const array URGENCIES = [
        'rendah' => 5,
        'normal' => 30,
        'tinggi' => 10,
        'mendesak' => 5,
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

    public function __construct(
        private readonly CreateWorkOrder $createWorkOrder,
        private readonly TransitionWorkOrder $transitionWorkOrder,
        private readonly AddAttachment $addAttachment,
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

        $departments = $this->seedDepartments();
        $categories = $this->seedCategories();
        $users = $this->seedUsers($departments, $password);

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
     * first.last@worder.test for everyone else.
     */
    public static function emailFor(string $name, string $role): string
    {
        $local = $role === 'admin' ? 'admin' : str($name)->lower()->replace(' ', '.')->toString();

        return $local.'@'.self::EMAIL_DOMAIN;
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
     * @return Collection<string, Department> keyed by code
     */
    private function seedDepartments(): Collection
    {
        return collect(self::DEPARTMENTS)->map(
            fn (string $name, string $code): Department => Department::query()->firstOrCreate(['code' => $code], ['name' => $name]),
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

            $user = User::query()->firstOrCreate(['email' => self::emailFor($name, $role)], [
                'name' => $name,
                'password' => $password,
                'must_change_password' => $mustChangePassword,
                'department_id' => $departments[$departmentCode]->id,
            ]);

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }

            return $user;
        });
    }

    /**
     * Every create, upload, and transition with its moment and actor, oldest
     * first. Events at the same moment keep their planned order.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<string, WorkOrderCategory>  $categories
     * @return list<array{at: CarbonImmutable, actor: User, run: Closure(): void}>
     */
    private function planWorkOrders(Collection $users, Collection $categories): array
    {
        $requesters = $users->filter(fn (User $user): bool => $user->hasRole('pemohon'))->values()->all();
        $approvers = $users->filter(fn (User $user): bool => $user->hasRole('approver'))->keyBy('department_id');

        $paths = $this->faker->shuffleArray(collect(self::PATHS)->flatMap(fn (int $count, string $path): array => array_fill(0, $count, $path))->all());
        $urgencies = $this->faker->shuffleArray(collect(self::URGENCIES)->flatMap(fn (int $count, string $urgency): array => array_fill(0, $count, $urgency))->all());

        /** @var array<int, WorkOrder> $created */
        $created = [];
        $events = [];

        foreach ($paths as $index => $path) {
            /** @var User $requester */
            $requester = $this->faker->randomElement($requesters);
            /** @var User $approver */
            $approver = $approvers[$requester->department_id];
            /** @var string $categoryCode */
            $categoryCode = $this->faker->randomElement(array_keys(self::TEMPLATES));

            $createdAt = $this->creationMoment($path);
            $attributes = $this->workOrderAttributes($path, $categoryCode, $requester, $createdAt)
                + ['work_order_category_id' => $categories[$categoryCode]->id, 'urgency' => $urgencies[$index]];

            $events[] = ['at' => $createdAt, 'actor' => $requester, 'run' => function () use (&$created, $index, $attributes, $requester): void {
                $created[$index] = $this->createWorkOrder->handle($attributes, $requester);
            }];

            if ($index % 4 === 0) {
                foreach ($this->sampleFilesFor($index) as $sample) {
                    $events[] = ['at' => $createdAt->addMinutes(2), 'actor' => $requester, 'run' => function () use (&$created, $index, $sample, $requester): void {
                        $this->attachSample($created[$index], $sample, $requester);
                    }];
                }
            }

            $nextAt = $createdAt->addMinutes($this->faker->numberBetween(30, 3 * 24 * 60));

            if ($path === 'cancelled_draft') {
                $note = $this->faker->randomElement(self::DRAFT_CANCEL_NOTES);
                $events[] = ['at' => $nextAt, 'actor' => $requester, 'run' => function () use (&$created, $index, $requester, $note): void {
                    $this->transitionWorkOrder->handle($created[$index], Dibatalkan::getMorphClass(), $requester, $note);
                }];
            }

            if (in_array($path, ['submitted', 'overdue', 'cancelled_submitted'], true)) {
                $events[] = ['at' => $nextAt, 'actor' => $requester, 'run' => function () use (&$created, $index, $requester): void {
                    $this->transitionWorkOrder->handle($created[$index], Diajukan::getMorphClass(), $requester);
                }];
            }

            if ($path === 'cancelled_submitted') {
                $note = $this->faker->randomElement(self::SUBMITTED_CANCEL_NOTES);
                $events[] = ['at' => $nextAt->addMinutes($this->faker->numberBetween(60, 4 * 24 * 60)), 'actor' => $approver, 'run' => function () use (&$created, $index, $approver, $note): void {
                    $this->transitionWorkOrder->handle($created[$index], Dibatalkan::getMorphClass(), $approver, $note);
                }];
            }
        }

        usort($events, fn (array $a, array $b): int => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

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
     * A moment in WITA working hours. Work orders that move on are created at
     * least 8 days ago so every later step is in the past (at most 7 days
     * later); overdue ones at least 30 days ago so their target has passed.
     */
    private function creationMoment(string $path): CarbonImmutable
    {
        $minDaysAgo = match ($path) {
            'draft' => 1,
            'overdue' => 30,
            default => 8,
        };

        return CarbonImmutable::now(DisplayDate::timezone())
            ->startOfDay()
            ->subDays($this->faker->numberBetween($minDaysAgo, self::HISTORY_DAYS))
            ->setTime($this->faker->numberBetween(8, 15), $this->faker->numberBetween(0, 59), $this->faker->numberBetween(0, 59))
            ->utc();
    }

    /**
     * @return array{title: string, description: string, target_date: string|null}
     */
    private function workOrderAttributes(string $path, string $categoryCode, User $requester, CarbonImmutable $createdAt): array
    {
        /** @var array{0: string, 1: string} $template */
        $template = $this->faker->randomElement(self::TEMPLATES[$categoryCode]);

        $replacements = [
            '{lokasi}' => (string) $this->faker->randomElement(self::LOCATIONS),
            '{ruang}' => (string) $this->faker->randomElement(self::ROOMS),
            '{dept}' => $requester->department->name,
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
        if ($path === 'overdue') {
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
     * Uploads a copy of the fixture, because media-library moves the file it
     * is given.
     *
     * @param  array{0: string, 1: string}  $sample
     */
    private function attachSample(WorkOrder $workOrder, array $sample, User $uploader): void
    {
        [$fixture, $clientName] = $sample;

        $copy = (string) tempnam(sys_get_temp_dir(), 'demo-attachment');
        copy(base_path('tests/Fixtures/attachments/'.$fixture), $copy);

        $this->addAttachment->handle($workOrder, $workOrder->documentsCollection(), new UploadedFile($copy, $clientName, null, null, true), $uploader);
    }
}
