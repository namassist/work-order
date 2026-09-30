<?php

use App\Enums\AccountStatus;
use App\Enums\AuditEvent;
use App\Enums\CompanyScope;
use App\Enums\PaymentStatus;
use App\Enums\SystemRole;
use App\Models\Company;
use App\Models\Department;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderComment;
use App\Models\WorkOrderDailyReport;
use App\Models\WorkOrderInvoice;
use App\Models\WorkOrderStatusHistory;
use App\States\WorkOrder\WorkOrderStatus;
use App\States\WorkOrder\WorkOrderTransition;
use App\Support\Comments\CommentHtml;
use App\Support\DisplayDate;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['auth.default_user_password' => 'demo-secret']);
    Storage::fake('attachments');
});

it('refuses to run in production and writes nothing', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(DemoSeeder::class)->run())->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0)
        ->and(User::withTrashed()->whereIn('email', array_keys(DemoSeeder::VISUAL_CHECK_ACCOUNTS))->exists())->toBeFalse()
        ->and(Department::count())->toBe(0)
        ->and(WorkOrder::count())->toBe(0);
});

it('creates the visual-check accounts with their fixed password, outside the demo timeline', function () {
    $this->seed(DemoSeeder::class);

    $accounts = User::query()->whereIn('email', array_keys(DemoSeeder::VISUAL_CHECK_ACCOUNTS))->with('roles')->get();

    expect($accounts->mapWithKeys(fn (User $user): array => [$user->email => $user->roles->pluck('name')->all()])->sortKeys()->all())->toBe([
        'visual.adminwo@worder.test' => ['admin-wo'],
        'visual.direktur@worder.test' => ['direktur'],
        'visual.finance@worder.test' => ['finance'],
        'visual.lead@worder.test' => ['lead-operational'],
        'visual.pictimesheet@worder.test' => ['pic-timesheet'],
        'visual.rental@worder.test' => ['rental'],
        'visual.viewer@worder.test' => ['viewer'],
        'visual@worder.test' => [SystemRole::Admin->value],
    ])
        ->and($accounts->every(fn (User $user): bool => Hash::check(DemoSeeder::VISUAL_CHECK_PASSWORD, $user->password)
            && ! $user->must_change_password
            && ! $user->isClient()))->toBeTrue()
        // They never act in the seeded history.
        ->and(WorkOrderStatusHistory::query()->whereIn('user_id', $accounts->pluck('id'))->exists())->toBeFalse()
        ->and(Activity::query()->whereIn('causer_id', $accounts->pluck('id'))->exists())->toBeFalse();
});

it('refuses to run without a default user password', function () {
    config(['auth.default_user_password' => null]);

    expect(fn () => app(DemoSeeder::class)->run())->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0);
});

it('creates the IC and Unggul companies with their departments', function () {
    $this->seed(DemoSeeder::class);

    expect(Company::query()->orderBy('code')->get()->map(fn (Company $company): array => $company->only(['code', 'name', 'is_client', 'email_domains']))->all())
        ->toBe([
            ['code' => 'IC', 'name' => 'IC', 'is_client' => true, 'email_domains' => ['ic.worder.test']],
            ['code' => 'UGL', 'name' => 'Unggul', 'is_client' => false, 'email_domains' => ['worder.test']],
        ])
        ->and(Department::query()->with('company')->orderBy('code')->get()->groupBy('company.code')->map(fn ($departments): array => $departments->pluck('code')->all())->all())
        ->toBe([
            'UGL' => ['DIR', 'ENG', 'GA', 'IT', 'KEU'],
            'IC' => ['HRD', 'LOG', 'MTC', 'PRD'],
        ]);
});

it('creates categories and Unggul accounts for every v2 role, and no IC account', function () {
    $this->seed(DemoSeeder::class);

    expect(WorkOrderCategory::count())->toBeGreaterThanOrEqual(4);

    $users = User::query()->whereNotIn('email', array_keys(DemoSeeder::VISUAL_CHECK_ACCOUNTS))->with(['roles', 'department.company'])->get();
    $staff = $users->filter(fn (User $user): bool => $user->roles->isNotEmpty());

    expect($staff->pluck('roles')->flatten()->pluck('name')->unique()->sort()->values()->all())
        ->toBe(['admin', 'admin-wo', 'direktur', 'finance', 'lead-operational', 'pic-timesheet', 'rental', 'viewer'])
        // Lead Operational is one person (FLOW.md §3).
        ->and($staff->filter(fn (User $user): bool => $user->hasRole('lead-operational')))->toHaveCount(1)
        ->and($users->every(fn (User $user): bool => ! $user->isClient()))->toBeTrue()
        ->and($users->every(fn (User $user): bool => Hash::check('demo-secret', $user->password)))->toBeTrue()
        ->and($users->where('must_change_password', true)->count())->toBe(1);

    $users->each(function (User $user): void {
        $company = $user->department->company;

        expect($user->roles->every(fn (Role $role): bool => CompanyScope::fits(CompanyScope::tryFrom((string) $role->company_scope), $company)))->toBeTrue()
            ->and($user->email)->toEndWith('@'.$company->email_domains[0]);
    });

    $admin = User::query()->where('email', 'admin@worder.test')->firstOrFail();

    expect($admin->hasRole(SystemRole::Admin->value))->toBeTrue()
        ->and($admin->must_change_password)->toBeFalse();
});

it('enters every work order through Admin WO for an IC department and contact', function () {
    $this->seed(DemoSeeder::class);

    $workOrders = WorkOrder::query()->with(['requesterDepartment.company', 'targetDepartment.company', 'enteredBy.roles'])->get();

    expect($workOrders->every(fn (WorkOrder $workOrder): bool => $workOrder->requesterDepartment->company->is_client
        && $workOrder->enteredBy->hasRole('admin-wo')
        && filled($workOrder->requester_name)))->toBeTrue()
        ->and($workOrders->pluck('created_by')->unique()->count())->toBe(2)
        ->and($workOrders->pluck('requester_department_id')->unique()->count())->toBe(4)
        // The target is informational: an Unggul department, or none on some drafts.
        ->and($workOrders->whereNotNull('target_department_id')->every(fn (WorkOrder $workOrder): bool => ! $workOrder->targetDepartment->company->is_client))->toBeTrue()
        ->and($workOrders->whereNull('target_department_id'))->not->toBeEmpty()
        ->and($workOrders->whereNotNull('pic_name'))->not->toBeEmpty()
        ->and($workOrders->whereNull('pic_name'))->not->toBeEmpty();
});

it('only lets people act on work orders they can see', function () {
    $this->seed(DemoSeeder::class);

    $comments = WorkOrderComment::withTrashed()->with(['workOrder', 'author'])->get();
    $transitions = WorkOrderStatusHistory::query()->with(['workOrder', 'user'])->get();

    expect($comments->every(fn (WorkOrderComment $comment): bool => $comment->workOrder->isVisibleTo($comment->author)))->toBeTrue()
        ->and($transitions->every(fn (WorkOrderStatusHistory $history): bool => $history->workOrder->isVisibleTo($history->user)))->toBeTrue();
});

it('lets only the role FLOW.md names make each status change', function () {
    $this->seed(DemoSeeder::class);

    // FLOW.md §3, §5.1, §5.2: the role that holds each transition's permission.
    $roles = [
        'work-orders.submit' => 'admin-wo',
        'work-orders.cancel' => 'admin-wo',
        'work-orders.close' => 'admin-wo',
        'work-orders.approve' => 'lead-operational',
        'work-orders.cancel-execution' => 'lead-operational',
        'work-orders.submit-review' => 'pic-timesheet',
        'work-orders.review' => 'rental',
        'work-orders.approve-bast' => 'direktur',
    ];

    WorkOrderStatusHistory::query()->whereNotNull('from_status')->with(['workOrder', 'user'])->get()
        ->each(function (WorkOrderStatusHistory $history) use ($roles): void {
            $transition = WorkOrderStatus::fromName((string) $history->from_status)?->transitionFor($history->to_status);

            expect($transition instanceof WorkOrderTransition
                && $history->user->checkPermissionTo($transition->permission->value)
                && $history->user->hasRole($roles[$transition->permission->value]))
                ->toBeTrue("{$history->from_status} → {$history->to_status} by {$history->user->name}");
        });
});

it('takes work orders through every path of the flow', function () {
    $this->seed(DemoSeeder::class);

    $workOrders = WorkOrder::query()->with(['statusHistories', 'media'])->get();
    $paths = $workOrders->map(fn (WorkOrder $workOrder): string => $workOrder->statusHistories->pluck('to_status')->implode(' → '));
    $resubmitted = $workOrders->filter(fn (WorkOrder $workOrder): bool => $workOrder->statusHistories->pluck('to_status')->all() === ['draft', 'diajukan', 'ditolak', 'diajukan']);
    $inProgress = $workOrders->filter(fn (WorkOrder $workOrder): bool => $workOrder->status->getValue() === 'pelaksanaan');
    $executed = 'draft → diajukan → pelaksanaan';
    $closed = "{$executed} → review_dokumen → approval_bast → bast_disetujui → closed";

    expect($paths->countBy()->sortKeys()->all())->toBe(collect([
        'draft' => 10,
        'draft → diajukan' => 13,
        'draft → diajukan → dibatalkan' => 2,
        'draft → diajukan → ditolak' => 4,
        'draft → diajukan → ditolak → diajukan' => 3,
        'draft → diajukan → ditolak → dibatalkan' => 2,
        'draft → dibatalkan' => 4,
        $executed => 7,
        "{$executed} → dibatalkan" => 1,
        "{$executed} → review_dokumen" => 3,
        "{$executed} → review_dokumen → approval_bast" => 3,
        "{$executed} → review_dokumen → approval_bast → bast_disetujui" => 2,
        $closed => 13,
        "{$executed} → review_dokumen → dibatalkan" => 1,
        "{$executed} → review_dokumen → pelaksanaan" => 2,
    ])->sortKeys()->all())
        // Admin WO revises the description before resubmitting.
        ->and($resubmitted->every(fn (WorkOrder $workOrder): bool => str_ends_with((string) $workOrder->description, 'Revisi: lokasi dan foto kondisi sudah dilengkapi.')))->toBeTrue()
        // PIC Timesheet adds progress photos while the work is carried out.
        ->and($inProgress->filter(fn (WorkOrder $workOrder): bool => $workOrder->media->contains(fn (Media $media): bool => $media->name === 'Foto_Progres.jpg'
            && (bool) User::find($media->uploaded_by)?->hasRole('pic-timesheet'))))->not->toBeEmpty();
});

it('creates closed work orders in every payment status', function () {
    $this->seed(DemoSeeder::class);

    expect(WorkOrder::query()->with('invoice')->get()
        ->map(fn (WorkOrder $workOrder): ?string => $workOrder->paymentStatus()?->value)
        ->filter()
        ->countBy()
        ->sortKeys()
        ->all())->toBe([
            PaymentStatus::BelumDitagih->value => 3,
            PaymentStatus::Ditagih->value => 6,
            PaymentStatus::Lunas->value => 4,
        ]);
});

it('creates work orders in every status over the last three months', function () {
    $this->seed(DemoSeeder::class);

    $workOrders = WorkOrder::query()->with('statusHistories')->get();

    expect($workOrders->count())->toBeBetween(60, 80)
        ->and($workOrders->map(fn (WorkOrder $workOrder): string => $workOrder->status->getValue())->unique()->sort()->values()->all())
        ->toBe(collect(WorkOrderStatus::options())->pluck('value')->sort()->values()->all())
        ->and($workOrders->min('created_at')->greaterThanOrEqualTo(now()->subMonths(3)->startOfDay()))->toBeTrue()
        ->and($workOrders->max('created_at')->lessThanOrEqualTo(now()))->toBeTrue();

    $cancelledAfterSubmission = $workOrders->filter(
        fn (WorkOrder $workOrder): bool => $workOrder->statusHistories->pluck('to_status')->all() === ['draft', 'diajukan', 'dibatalkan'],
    );
    expect($cancelledAfterSubmission)->not->toBeEmpty();

    $overdue = $workOrders->load('invoice')->filter(fn (WorkOrder $workOrder): bool => $workOrder->isOverdue());
    expect($overdue->countBy(fn (WorkOrder $workOrder): string => $workOrder->status->getValue())->sortKeys()->all())
        ->toBe(['closed' => 2, 'diajukan' => 4, 'pelaksanaan' => 2])
        ->and(WorkOrder::query()->overdue()->pluck('id')->sort()->values()->all())->toBe($overdue->pluck('id')->sort()->values()->all());
});

it('bills closed work orders and confirms payments through the real actions', function () {
    $this->seed(DemoSeeder::class);

    $invoices = WorkOrderInvoice::query()->with(['workOrder.statusHistories', 'workOrder.media', 'issuer', 'corrector', 'payer'])->get();
    $paid = $invoices->filter(fn (WorkOrderInvoice $invoice): bool => $invoice->isPaid());

    expect($invoices)->toHaveCount(10)
        ->and($paid)->toHaveCount(4)
        ->and($invoices->pluck('number')->map(fn (string $number): string => mb_strtolower($number))->duplicates())->toBeEmpty()
        ->and($invoices->whereNull('amount')->count())->toBe(1)
        ->and($invoices->whereNotNull('corrected_by')->count())->toBe(1)
        ->and(Activity::query()->where('event', AuditEvent::InvoiceIssued->value)->count())->toBe(10)
        ->and(Activity::query()->where('event', AuditEvent::InvoiceCorrected->value)->count())->toBe(1)
        ->and(Activity::query()->where('event', AuditEvent::PaymentConfirmed->value)->count())->toBe(4)
        ->and($paid->filter(fn (WorkOrderInvoice $invoice): bool => $invoice->workOrder->media->contains('collection_name', WorkOrder::PAYMENT_PROOF))->count())->toBe(2);

    $invoices->each(function (WorkOrderInvoice $invoice): void {
        $workOrder = $invoice->workOrder;
        $issued = Activity::query()->forSubject($workOrder)->where('event', AuditEvent::InvoiceIssued->value)->sole();

        // Billed by Finance after closing, with at least one invoice file; the status stays Closed.
        expect($workOrder->status->getValue())->toBe('closed')
            ->and($invoice->issuer->hasRole('finance'))->toBeTrue()
            ->and($issued->causer_id)->toBe($invoice->issued_by)
            ->and($issued->created_at->greaterThan($workOrder->statusHistories->firstWhere('to_status', 'closed')->created_at))->toBeTrue()
            ->and($invoice->invoice_date->toDateString())->toBe(DisplayDate::local($issued->created_at)->toDateString())
            ->and($workOrder->media->where('collection_name', WorkOrder::INVOICE))->not->toBeEmpty();

        if ($invoice->isPaid()) {
            $confirmed = Activity::query()->forSubject($workOrder)->where('event', AuditEvent::PaymentConfirmed->value)->sole();

            expect($invoice->payer->hasRole('finance'))->toBeTrue()
                ->and($confirmed->causer_id)->toBe($invoice->paid_by)
                ->and($invoice->paid_on?->toDateString())->toBe(DisplayDate::local($confirmed->created_at)->toDateString());
        }
    });
});

it('mixes urgencies realistically: about 10% rendah, 60% normal, 20% tinggi, 10% mendesak', function () {
    $this->seed(DemoSeeder::class);

    $counts = WorkOrder::query()->toBase()->selectRaw('urgency, count(*) as aggregate')->groupBy('urgency')->pluck('aggregate', 'urgency');
    $total = WorkOrder::query()->count();

    expect(collect(['rendah' => 0.1, 'normal' => 0.6, 'tinggi' => 0.2, 'mendesak' => 0.1])
        ->map(fn (float $share, string $urgency): bool => abs(((int) ($counts[$urgency] ?? 0)) / $total - $share) <= 0.05)
        ->all())
        ->toBe(['rendah' => true, 'normal' => true, 'tinggi' => true, 'mendesak' => true]);
});

it('gives every work order a consistent status history', function () {
    $this->seed(DemoSeeder::class);

    WorkOrder::query()->with('statusHistories')->get()->each(function (WorkOrder $workOrder): void {
        $histories = $workOrder->statusHistories;

        expect($histories->first()->from_status)->toBeNull()
            ->and($histories->first()->to_status)->toBe('draft')
            ->and($histories->first()->user_id)->toBe($workOrder->created_by)
            ->and($histories->first()->created_at->equalTo($workOrder->created_at))->toBeTrue()
            ->and($histories->last()->to_status)->toBe($workOrder->status->getValue())
            ->and($workOrder->number === null)->toBe(! $histories->contains('to_status', 'diajukan'));

        $histories->sliding(2)->each(function (Collection $pair): void {
            [$previous, $next] = $pair->values()->all();

            expect($next->from_status)->toBe($previous->to_status)
                ->and($next->created_at->greaterThan($previous->created_at))->toBeTrue();
        });

        // Every change whose transition requires a note has one.
        $histories->whereNotNull('from_status')
            ->filter(fn (WorkOrderStatusHistory $history): bool => (bool) WorkOrderStatus::fromName((string) $history->from_status)?->transitionFor($history->to_status)?->requiresNote)
            ->each(fn (WorkOrderStatusHistory $history) => expect($history->note)->not->toBeEmpty());
    });
});

it('numbers work orders in submission order, restarting every month per department', function () {
    $this->seed(DemoSeeder::class);

    // The first submission gives the number; a resubmission keeps it.
    $submissions = WorkOrderStatusHistory::query()
        ->where('from_status', 'draft')
        ->where('to_status', 'diajukan')
        ->with('workOrder')
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($submissions)->not->toBeEmpty()
        ->and($submissions->pluck('workOrder.number')->duplicates())->toBeEmpty();

    $submissions->groupBy(fn (WorkOrderStatusHistory $history): string => Str::beforeLast((string) $history->workOrder->number, '/'))
        ->each(function (Collection $histories, string $scope): void {
            expect($scope)->toEndWith(DisplayDate::local($histories->first()->created_at)->format('Y/m'))
                ->and($histories->map(fn (WorkOrderStatusHistory $history): int => (int) Str::afterLast((string) $history->workOrder->number, '/'))->values()->all())
                ->toBe(range(1, $histories->count()));
        });
});

it('logs every status change with the user who made it', function () {
    $this->seed(DemoSeeder::class);

    $transitions = WorkOrderStatusHistory::query()->whereNotNull('from_status')->get();
    $activities = Activity::query()->where('event', AuditEvent::StatusChanged->value)->get();

    expect($activities->count())->toBe($transitions->count());

    $transitions->each(function (WorkOrderStatusHistory $history) use ($activities): void {
        expect($activities->contains(fn (Activity $activity): bool => $activity->subject_id === $history->work_order_id
            && $activity->causer_id === $history->user_id
            && $activity->created_at->equalTo($history->created_at)))->toBeTrue();
    });
});

it('adds comments from Admin WO and PIC Timesheet while the work order still took them', function () {
    $this->seed(DemoSeeder::class);

    $comments = WorkOrderComment::withTrashed()->with(['workOrder.statusHistories', 'author.roles', 'author.department.company'])->get();

    expect($comments->pluck('work_order_id')->unique()->count())->toBeGreaterThanOrEqual(8)
        ->and($comments->pluck('author')->flatMap(fn (User $author) => $author->roles->pluck('name'))->unique()->sort()->values()->all())
        ->toBe(['admin-wo', 'pic-timesheet'])
        ->and($comments->whereNotNull('edited_at'))->not->toBeEmpty()
        ->and($comments->whereNotNull('deleted_at'))->not->toBeEmpty();

    $comments->each(function (WorkOrderComment $comment): void {
        // Dibatalkan and the payment (Lunas) make comments read-only.
        $cancelledAt = $comment->workOrder->statusHistories->firstWhere('to_status', 'dibatalkan')?->created_at
            ?? Activity::query()->forSubject($comment->workOrder)->where('event', AuditEvent::PaymentConfirmed->value)->first()?->created_at;

        // The PIC Timesheet of the target department, or the Admin WO who entered it.
        expect($comment->author->hasRole('pic-timesheet')
            ? $comment->author->department_id === $comment->workOrder->target_department_id
            : $comment->author->id === $comment->workOrder->created_by)->toBeTrue()
            ->and($comment->created_at->greaterThan($comment->workOrder->created_at))->toBeTrue()
            ->and($comment->created_at->lessThanOrEqualTo(now()))->toBeTrue()
            ->and($cancelledAt === null || $comment->created_at->lessThan($cancelledAt))->toBeTrue();
    });

    expect(Activity::query()->where('event', AuditEvent::CommentAdded->value)->pluck('causer_id')->sort()->values()->all())
        ->toBe($comments->pluck('user_id')->sort()->values()->all());
});

it('writes rich comments with inline photos and documents through the real actions', function () {
    $this->seed(DemoSeeder::class);

    $comments = WorkOrderComment::query()->with(['media', 'author.roles', 'workOrder'])->get();
    $reports = $comments->filter(fn (WorkOrderComment $comment): bool => $comment->media->isNotEmpty());

    expect($reports->count())->toBeGreaterThanOrEqual(3)
        ->and($comments->filter(fn (WorkOrderComment $comment): bool => str_contains($comment->body, '<strong>') && str_contains($comment->body, '<ul>')))->not->toBeEmpty()
        // Nothing is left waiting: every upload was claimed by its comment.
        ->and(Media::query()->whereIn('collection_name', [WorkOrder::COMMENT_IMAGE_UPLOADS, WorkOrder::COMMENT_FILE_UPLOADS])->exists())->toBeFalse();

    $reports->each(function (WorkOrderComment $comment): void {
        $image = $comment->media->firstWhere('collection_name', WorkOrderComment::IMAGES);

        expect($comment->author->hasRole('pic-timesheet'))->toBeTrue()
            ->and($image)->not->toBeNull()
            ->and($comment->body)->toContain('<img src="/attachments/'.$image->uuid.'"')
            ->and($comment->media->where('collection_name', WorkOrderComment::DOCUMENTS))->toHaveCount(1)
            ->and($comment->body_text)->not->toBe('');
        Storage::disk('attachments')->assertExists($image->getPathRelativeToRoot());
    });

    // Every body, plain-text threads included, is paragraph HTML exactly as the sanitizer keeps it.
    $comments->each(function (WorkOrderComment $comment): void {
        expect($comment->body)->toStartWith('<p>')->toBe(CommentHtml::forDisplay($comment->body));
    });
});

it('posts daily reports during Pelaksanaan through the real actions, leaving a few missing today', function () {
    $this->seed(DemoSeeder::class);

    $reports = WorkOrderDailyReport::query()->with(['workOrder.statusHistories', 'reporter', 'files'])->get();
    $today = DisplayDate::today();

    expect($reports->count())->toBeGreaterThan(50)
        ->and($reports->every(fn (WorkOrderDailyReport $report): bool => $report->reporter->hasRole('pic-timesheet')))->toBeTrue()
        ->and($reports->every(fn (WorkOrderDailyReport $report): bool => $report->links !== [] || $report->files->isNotEmpty()))->toBeTrue()
        ->and($reports->flatMap->links->every(fn (string $link): bool => str_starts_with($link, 'https://')))->toBeTrue()
        ->and($reports->flatMap->files->pluck('mime_type')->unique()->all())->toBe(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
        ->and($reports->whereNotNull('updated_by')->count())->toBeGreaterThan(0)
        ->and(Activity::query()->where('event', AuditEvent::DailyReportAdded->value)->count())->toBe($reports->count());

    // Every submission for review had a report, filed while the work order was in Pelaksanaan.
    foreach (WorkOrderStatusHistory::query()->where('to_status', 'review_dokumen')->get() as $review) {
        expect($reports->where('work_order_id', $review->work_order_id)->contains(fn (WorkOrderDailyReport $report): bool => $report->created_at->lessThan($review->created_at)))
            ->toBeTrue("WO {$review->work_order_id} reviewed without a report");
    }

    $withoutToday = WorkOrder::query()->where('status', 'pelaksanaan')
        ->whereDoesntHave('dailyReports', fn ($query) => $query->where('report_date', $today))
        ->count();

    expect($withoutToday)->toBeGreaterThanOrEqual(3);
});

it('attaches sample documents without touching the fixtures', function () {
    $this->seed(DemoSeeder::class);

    $media = Media::query()->where('collection_name', WorkOrder::DOCUMENTS)->get();

    expect($media->pluck('model_id')->unique()->count())->toBeGreaterThanOrEqual(5)
        ->and($media->pluck('mime_type')->unique()->sort()->values()->all())->toBe(['application/pdf', 'image/jpeg'])
        ->and(file_exists(base_path('tests/Fixtures/attachments/dokumen.pdf')))->toBeTrue()
        ->and(file_exists(base_path('tests/Fixtures/attachments/foto.jpg')))->toBeTrue();

    $media->each(fn (Media $item) => Storage::disk('attachments')->assertExists($item->getPathRelativeToRoot()));
});

it('clears orphaned media directories when seeding into a fresh database', function () {
    $orphan = Str::uuid()->toString().'/'.Str::uuid()->toString().'.pdf';
    Storage::disk('attachments')->put($orphan, 'left over from migrate:fresh');
    Storage::disk('attachments')->put('manual/keep.pdf', 'not written by media-library');

    $this->seed(DemoSeeder::class);

    Storage::disk('attachments')->assertMissing($orphan);
    Storage::disk('attachments')->assertExists('manual/keep.pdf');
});

it('leaves the attachments disk alone when the database already has work orders', function () {
    WorkOrder::factory()->create();
    $file = Str::uuid()->toString().'/'.Str::uuid()->toString().'.pdf';
    Storage::disk('attachments')->put($file, 'belongs to a restored database');

    $this->seed(DemoSeeder::class);

    Storage::disk('attachments')->assertExists($file);
});

it('does not duplicate data or remove files when run again', function () {
    $this->seed(DemoSeeder::class);

    $counts = fn (): array => [
        Company::count(), Department::count(), WorkOrderCategory::count(), User::count(),
        WorkOrder::count(), WorkOrderStatusHistory::count(), WorkOrderComment::withTrashed()->count(), Media::count(),
    ];
    $before = $counts();

    $this->seed(DemoSeeder::class);

    expect($counts())->toBe($before);
    Media::all()->each(fn (Media $item) => Storage::disk('attachments')->assertExists($item->getPathRelativeToRoot()));
});

it('seeds pending Unggul registrations and one rejected, through the real actions', function () {
    $this->seed(DemoSeeder::class);

    $registrations = User::query()->registrations()->with(['roles', 'department.company'])->get();
    $pending = $registrations->where('account_status', AccountStatus::Pending);
    $rejected = $registrations->where('account_status', AccountStatus::Rejected);

    expect($pending)->toHaveCount(3)
        ->and($registrations->every(fn (User $user): bool => ! $user->isClient()))->toBeTrue()
        ->and($rejected)->toHaveCount(1)
        ->and($rejected->sole()->rejection_reason)->not->toBeEmpty()
        ->and($registrations->every(fn (User $user): bool => $user->roles->isEmpty()))->toBeTrue()
        ->and($registrations->every(fn (User $user): bool => $user->department->company->allowsEmailDomain($user->email)))->toBeTrue()
        ->and(Activity::where('event', AuditEvent::Registered->value)->count())->toBe(4)
        ->and(Activity::where('event', AuditEvent::RegistrationRejected->value)->sole()->causer_id)
        ->toBe(User::where('email', 'admin@worder.test')->value('id'));
});
