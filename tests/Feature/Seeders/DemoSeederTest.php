<?php

use App\Enums\AccountStatus;
use App\Enums\AuditEvent;
use App\Enums\CompanyScope;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Enums\WorkOrderSide;
use App\Models\Company;
use App\Models\Department;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderComment;
use App\Models\WorkOrderInvoice;
use App\Models\WorkOrderStatusHistory;
use App\States\WorkOrder\WorkOrderStatus;
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

    $admin = User::withEmail('visual@worder.test')->sole();
    $keuangan = User::withEmail('visual.keuangan@worder.test')->sole();

    expect($admin->hasRole(SystemRole::Admin->value))->toBeTrue()
        ->and($keuangan->hasRole('keuangan'))->toBeTrue()
        ->and($keuangan->department->code)->toBe('KEU')
        ->and(collect([$admin, $keuangan])->every(fn (User $user): bool => Hash::check(DemoSeeder::VISUAL_CHECK_PASSWORD, $user->password)
            && ! $user->must_change_password
            && ! $user->isClient()))->toBeTrue()
        // They never act in the seeded history.
        ->and(WorkOrderStatusHistory::query()->whereIn('user_id', [$admin->id, $keuangan->id])->exists())->toBeFalse()
        ->and(Activity::query()->whereIn('causer_id', [$admin->id, $keuangan->id])->exists())->toBeFalse();
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
            'UGL' => ['ENG', 'GA', 'IT', 'KEU'],
            'IC' => ['HRD', 'LOG', 'MTC', 'PRD'],
        ]);
});

it('creates categories and accounts for every role, each fitting its company', function () {
    $this->seed(DemoSeeder::class);

    expect(WorkOrderCategory::count())->toBeGreaterThanOrEqual(4);

    $users = User::query()->whereNotIn('email', array_keys(DemoSeeder::VISUAL_CHECK_ACCOUNTS))->with(['roles', 'department.company'])->get();

    expect($users->pluck('roles')->flatten()->pluck('name')->unique()->sort()->values()->all())
        ->toBe(['admin', 'keuangan', 'koordinator', 'pelaksana', 'pemohon', 'viewer'])
        ->and($users->every(fn (User $user): bool => Hash::check('demo-secret', $user->password)))->toBeTrue()
        ->and($users->where('must_change_password', true)->count())->toBeBetween(2, 3);

    $users->each(function (User $user): void {
        $company = $user->department->company;

        expect($user->roles->every(fn (Role $role): bool => CompanyScope::fits(CompanyScope::tryFrom((string) $role->company_scope), $company)))->toBeTrue()
            ->and($user->email)->toEndWith('@'.$company->email_domains[0]);
    });

    $rolesIn = fn (string $code): array => $users->filter(fn (User $user): bool => $user->department->code === $code)
        ->flatMap(fn (User $user) => $user->roles->pluck('name'))->unique()->values()->all();

    foreach (['PRD', 'HRD', 'LOG', 'MTC'] as $code) {
        expect($rolesIn($code))->toContain('pemohon');
    }

    foreach (['ENG', 'GA', 'IT'] as $code) {
        expect($rolesIn($code))->toContain('pelaksana');
    }

    expect($rolesIn('KEU'))->toContain('keuangan')
        ->and($rolesIn('GA'))->toContain('koordinator');

    $admin = User::query()->where('email', 'admin@worder.test')->firstOrFail();

    expect($admin->hasRole(SystemRole::Admin->value))->toBeTrue()
        ->and($admin->isClient())->toBeFalse()
        ->and($admin->must_change_password)->toBeFalse();
});

it('requests every work order from an IC department and addresses it to an Unggul one', function () {
    $this->seed(DemoSeeder::class);

    $workOrders = WorkOrder::query()->with(['requesterDepartment.company', 'targetDepartment.company', 'enteredBy'])->get();

    expect($workOrders->every(fn (WorkOrder $workOrder): bool => $workOrder->requesterDepartment->company->is_client))->toBeTrue()
        ->and($workOrders->filter(fn (WorkOrder $workOrder): bool => $workOrder->wasSubmitted())
            ->every(fn (WorkOrder $workOrder): bool => $workOrder->targetDepartment !== null && ! $workOrder->targetDepartment->company->is_client))->toBeTrue()
        ->and($workOrders->whereNull('target_department_id')->every(fn (WorkOrder $workOrder): bool => ! $workOrder->wasSubmitted()))->toBeTrue()
        ->and($workOrders->whereNull('target_department_id'))->not->toBeEmpty();
});

it('enters some work orders on behalf of IC, for accounts and for contacts', function () {
    $this->seed(DemoSeeder::class);

    $workOrders = WorkOrder::query()->with(['enteredBy.roles', 'requester'])->get();
    [$onBehalf, $own] = $workOrders->partition(fn (WorkOrder $workOrder): bool => $workOrder->wasEnteredOnBehalf());

    expect($own->every(fn (WorkOrder $workOrder): bool => $workOrder->requester_id === $workOrder->created_by
        && $workOrder->enteredBy->department_id === $workOrder->requester_department_id))->toBeTrue()
        ->and($onBehalf->every(fn (WorkOrder $workOrder): bool => $workOrder->enteredBy->hasRole('koordinator')))->toBeTrue()
        ->and($onBehalf->whereNotNull('requester_id')->every(fn (WorkOrder $workOrder): bool => $workOrder->requester->department_id === $workOrder->requester_department_id))->toBeTrue()
        ->and($onBehalf->whereNotNull('requester_id'))->not->toBeEmpty()
        ->and($onBehalf->whereNotNull('requester_name'))->not->toBeEmpty()
        ->and($onBehalf->filter(fn (WorkOrder $workOrder): bool => $workOrder->wasSubmitted()))->not->toBeEmpty()
        ->and($onBehalf->reject(fn (WorkOrder $workOrder): bool => $workOrder->wasSubmitted()))->not->toBeEmpty();
});

it('only lets people act on work orders they can see', function () {
    $this->seed(DemoSeeder::class);

    $comments = WorkOrderComment::withTrashed()->with(['workOrder', 'author'])->get();
    $transitions = WorkOrderStatusHistory::query()->with(['workOrder', 'user'])->get();

    expect($comments->every(fn (WorkOrderComment $comment): bool => $comment->workOrder->isVisibleTo($comment->author)))->toBeTrue()
        // A department that rejected a work order sent to it by mistake no longer sees it once it is moved.
        ->and($transitions->every(fn (WorkOrderStatusHistory $history): bool => $history->workOrder->isVisibleTo($history->user)
            || in_array($history->user->department_id, previousTargets($history->workOrder), true)))->toBeTrue();
});

/**
 * The target departments the work order had before its current one, from
 * its audit log.
 *
 * @return list<int>
 */
function previousTargets(WorkOrder $workOrder): array
{
    return Activity::query()->forSubject($workOrder)->get()
        ->map(fn (Activity $activity): mixed => $activity->attribute_changes?->get('old')['target_department_id'] ?? null)
        ->filter()
        ->values()
        ->all();
}

it('lets only the side FLOW.md names make each status change', function () {
    $this->seed(DemoSeeder::class);

    WorkOrderStatusHistory::query()->whereNotNull('from_status')->with(['workOrder', 'user'])->get()
        ->each(function (WorkOrderStatusHistory $history): void {
            $side = WorkOrderStatus::fromName($history->to_status)?->performedBy();
            $workOrder = $history->workOrder;

            $onSide = $side === WorkOrderSide::Executor
                // Rejections by a department it was moved away from count as that department's.
                ? $history->user->hasPermissionTo(Permission::WorkOrdersProcess->value)
                    && ($workOrder->target_department_id === $history->user->department_id
                        || in_array($history->user->department_id, previousTargets($workOrder), true))
                : $side instanceof WorkOrderSide && $workOrder->isOnSide($history->user, $side);

            expect($onSide)->toBeTrue("{$history->from_status} → {$history->to_status} by {$history->user->name}");
        });
});

it('rejects, resubmits under the same number, and carries out work orders', function () {
    $this->seed(DemoSeeder::class);

    $workOrders = WorkOrder::query()->with(['statusHistories', 'media'])->get();
    $paths = $workOrders->map(fn (WorkOrder $workOrder): string => $workOrder->statusHistories->pluck('to_status')->implode(' → '));
    $resubmitted = $workOrders->filter(fn (WorkOrder $workOrder): bool => $workOrder->statusHistories->pluck('to_status')->all() === ['draft', 'diajukan', 'ditolak', 'diajukan']);
    $moved = $resubmitted->filter(fn (WorkOrder $workOrder): bool => previousTargets($workOrder) !== []);
    $inProgress = $workOrders->filter(fn (WorkOrder $workOrder): bool => $workOrder->status->getValue() === 'dikerjakan');

    expect($paths->countBy()->only([
        'draft → diajukan → ditolak',
        'draft → diajukan → ditolak → diajukan',
        'draft → diajukan → ditolak → dibatalkan',
        'draft → diajukan → dikerjakan',
        'draft → diajukan → dikerjakan → penagihan',
        'draft → diajukan → dikerjakan → penagihan → selesai',
    ])->sortKeys()->all())->toBe([
        'draft → diajukan → dikerjakan' => 8,
        'draft → diajukan → dikerjakan → penagihan' => 6,
        'draft → diajukan → dikerjakan → penagihan → selesai' => 4,
        'draft → diajukan → ditolak' => 5,
        'draft → diajukan → ditolak → diajukan' => 4,
        'draft → diajukan → ditolak → dibatalkan' => 2,
    ])
        ->and($moved->count())->toBe(2)
        ->and($moved->every(fn (WorkOrder $workOrder): bool => str_starts_with((string) $workOrder->statusHistories->firstWhere('to_status', 'ditolak')->note, 'Bukan lingkup departemen kami')))->toBeTrue()
        // The pelaksana adds progress photos while carrying the work out.
        ->and($inProgress->filter(fn (WorkOrder $workOrder): bool => $workOrder->media->contains('uploaded_by', $workOrder->statusHistories->firstWhere('to_status', 'dikerjakan')->user_id)))->not->toBeEmpty();
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
        ->toBe(['diajukan' => 4, 'dikerjakan' => 2, 'penagihan' => 2])
        ->and(WorkOrder::query()->overdue()->pluck('id')->sort()->values()->all())->toBe($overdue->pluck('id')->sort()->values()->all());
});

it('invoices finished work orders and confirms payments through the real actions', function () {
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
        $billing = $workOrder->statusHistories->firstWhere('to_status', 'penagihan');

        // Issued by the pelaksana who accepted the work, with at least one invoice file.
        expect($invoice->issued_by)->toBe($workOrder->statusHistories->firstWhere('to_status', 'dikerjakan')->user_id)
            ->and($invoice->issued_by)->toBe($billing->user_id)
            ->and($invoice->invoice_date->toDateString())->toBe(DisplayDate::local($billing->created_at)->toDateString())
            ->and($workOrder->media->where('collection_name', WorkOrder::INVOICE))->not->toBeEmpty();

        if ($invoice->isPaid()) {
            // Confirmed by keuangan, never by whoever prepared the invoice.
            expect($invoice->payer->hasRole('keuangan'))->toBeTrue()
                ->and($invoice->wasPreparedBy($invoice->payer))->toBeFalse()
                ->and($invoice->paid_on?->toDateString())->toBe(DisplayDate::local($workOrder->statusHistories->firstWhere('to_status', 'selesai')->created_at)->toDateString());
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

        $histories->whereIn('to_status', ['dibatalkan', 'ditolak'])
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

it('adds comments from the requester side and pelaksana while the work order still took them', function () {
    $this->seed(DemoSeeder::class);

    $comments = WorkOrderComment::withTrashed()->with(['workOrder.statusHistories', 'author.roles', 'author.department.company'])->get();

    expect($comments->pluck('work_order_id')->unique()->count())->toBeGreaterThanOrEqual(8)
        ->and($comments->pluck('author')->flatMap(fn (User $author) => $author->roles->pluck('name'))->unique()->sort()->values()->all())
        ->toBe(['koordinator', 'pelaksana', 'pemohon'])
        ->and($comments->whereNotNull('edited_at'))->not->toBeEmpty()
        ->and($comments->whereNotNull('deleted_at'))->not->toBeEmpty();

    $comments->each(function (WorkOrderComment $comment): void {
        // Dibatalkan and Selesai make comments read-only.
        $cancelledAt = $comment->workOrder->statusHistories->first(fn (WorkOrderStatusHistory $history): bool => in_array($history->to_status, ['dibatalkan', 'selesai'], true))?->created_at;

        // The pelaksana of the target department, or the requester side: an
        // account of the requester department or the koordinator who entered it.
        expect($comment->author->hasRole('pelaksana')
            ? $comment->author->department_id === $comment->workOrder->target_department_id
            : $comment->author->department_id === $comment->workOrder->requester_department_id
                || $comment->author->id === $comment->workOrder->created_by)->toBeTrue()
            ->and($comment->created_at->greaterThan($comment->workOrder->created_at))->toBeTrue()
            ->and($comment->created_at->lessThanOrEqualTo(now()))->toBeTrue()
            ->and($cancelledAt === null || $comment->created_at->lessThan($cancelledAt))->toBeTrue();
    });

    expect(Activity::query()->where('event', AuditEvent::CommentAdded->value)->pluck('causer_id')->sort()->values()->all())
        ->toBe($comments->pluck('user_id')->sort()->values()->all());
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

it('seeds pending registrations from both companies and one rejected, through the real actions', function () {
    $this->seed(DemoSeeder::class);

    $registrations = User::query()->registrations()->with(['roles', 'department.company'])->get();
    $pending = $registrations->where('account_status', AccountStatus::Pending);
    $rejected = $registrations->where('account_status', AccountStatus::Rejected);

    expect($pending)->toHaveCount(3)
        ->and($pending->map(fn (User $user): bool => $user->isClient())->unique()->sort()->values()->all())->toBe([false, true])
        ->and($rejected)->toHaveCount(1)
        ->and($rejected->sole()->rejection_reason)->not->toBeEmpty()
        ->and($registrations->every(fn (User $user): bool => $user->roles->isEmpty()))->toBeTrue()
        ->and($registrations->every(fn (User $user): bool => $user->department->company->allowsEmailDomain($user->email)))->toBeTrue()
        ->and(Activity::where('event', AuditEvent::Registered->value)->count())->toBe(4)
        ->and(Activity::where('event', AuditEvent::RegistrationRejected->value)->sole()->causer_id)
        ->toBe(User::where('email', 'admin@worder.test')->value('id'));
});
