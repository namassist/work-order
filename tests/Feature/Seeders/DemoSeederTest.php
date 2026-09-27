<?php

use App\Enums\AuditEvent;
use App\Enums\CompanyScope;
use App\Enums\SystemRole;
use App\Models\Company;
use App\Models\Department;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderComment;
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
        ->and(Department::count())->toBe(0)
        ->and(WorkOrder::count())->toBe(0);
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

    $users = User::query()->with(['roles', 'department.company'])->get();

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
        ->and($transitions->every(fn (WorkOrderStatusHistory $history): bool => $history->workOrder->isVisibleTo($history->user)))->toBeTrue();
});

it('creates work orders in every status over the last three months', function () {
    $this->seed(DemoSeeder::class);

    $workOrders = WorkOrder::query()->with('statusHistories')->get();

    expect($workOrders->count())->toBeBetween(40, 60)
        ->and($workOrders->map(fn (WorkOrder $workOrder): string => $workOrder->status->getValue())->unique()->sort()->values()->all())
        ->toBe(collect(WorkOrderStatus::options())->pluck('value')->sort()->values()->all())
        ->and($workOrders->min('created_at')->greaterThanOrEqualTo(now()->subMonths(3)->startOfDay()))->toBeTrue()
        ->and($workOrders->max('created_at')->lessThanOrEqualTo(now()))->toBeTrue();

    $cancelledAfterSubmission = $workOrders->filter(
        fn (WorkOrder $workOrder): bool => $workOrder->statusHistories->pluck('to_status')->all() === ['draft', 'diajukan', 'dibatalkan'],
    );
    expect($cancelledAfterSubmission)->not->toBeEmpty();

    $today = DisplayDate::local(now())->toDateString();
    $overdue = $workOrders->filter(
        fn (WorkOrder $workOrder): bool => $workOrder->status->getValue() === 'diajukan'
            && $workOrder->target_date !== null
            && $workOrder->target_date->toDateString() < $today,
    );
    expect($overdue->count())->toBe(5);
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

        $histories->where('to_status', 'dibatalkan')
            ->each(fn (WorkOrderStatusHistory $history) => expect($history->note)->not->toBeEmpty());
    });
});

it('numbers work orders in submission order, restarting every month per department', function () {
    $this->seed(DemoSeeder::class);

    $submissions = WorkOrderStatusHistory::query()
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
        $cancelledAt = $comment->workOrder->statusHistories->firstWhere('to_status', 'dibatalkan')?->created_at;

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
