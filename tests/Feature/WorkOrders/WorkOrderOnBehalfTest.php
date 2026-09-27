<?php

use App\Enums\Permission;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| FLOW.md §4: a koordinator (work-orders.create-on-behalf, executor only)
| enters work orders on behalf of an IC department, for an account there or
| a contact without one, and alone may correct that requester on the draft.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Carbon::setTestNow('2026-09-25 02:00:00');

    $this->ic = Company::factory()->client()->create(['code' => 'IC']);
    $this->requesterDepartment = Department::factory()->for($this->ic)->create(['code' => 'PRD', 'name' => 'Produksi']);
    $this->targetDepartment = Department::factory()->create(['code' => 'ENG']);
    $this->woCategory = WorkOrderCategory::factory()->create();

    $this->koordinatorUser = User::factory()->create(['name' => 'Dewi Koordinator'])->assignRole('koordinator');
    $this->icRequester = User::factory()->for($this->requesterDepartment)->create(['name' => 'Eko Pemohon'])->assignRole('pemohon');
});

/**
 * A valid on-behalf store payload with the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function onBehalfPayload(array $overrides = []): array
{
    return [
        'title' => 'Genset mati',
        'work_order_category_id' => test()->woCategory->id,
        'urgency' => 'normal',
        'target_department_id' => test()->targetDepartment->id,
        'requester_department_id' => test()->requesterDepartment->id,
        'requester_mode' => 'account',
        'requester_id' => test()->icRequester->id,
        ...$overrides,
    ];
}

describe('create', function () {
    it('enters a work order for an IC account', function () {
        $this->actingAs($this->koordinatorUser)
            ->post(route('work-orders.store'), onBehalfPayload())
            ->assertSessionHasNoErrors();

        $workOrder = WorkOrder::sole();
        expect($workOrder)
            ->requester_department_id->toBe($this->requesterDepartment->id)
            ->requester_id->toBe($this->icRequester->id)
            ->requester_name->toBeNull()
            ->created_by->toBe($this->koordinatorUser->id)
            ->target_department_id->toBe($this->targetDepartment->id)
            ->and($workOrder->wasEnteredOnBehalf())->toBeTrue()
            ->and($workOrder->statusHistories()->sole()->user_id)->toBe($this->koordinatorUser->id);
    });

    it('enters a work order for a contact without an account', function () {
        $this->actingAs($this->koordinatorUser)
            ->post(route('work-orders.store'), onBehalfPayload(['requester_mode' => 'contact', 'requester_id' => null, 'requester_name' => '  Pak Andi, Maintenance ']))
            ->assertSessionHasNoErrors();

        expect(WorkOrder::sole())
            ->requester_id->toBeNull()
            ->requester_name->toBe('Pak Andi, Maintenance')
            ->created_by->toBe($this->koordinatorUser->id);
    });

    it('refuses an invalid requester', function (Closure $overrides, string $error) {
        $this->actingAs($this->koordinatorUser)
            ->post(route('work-orders.store'), onBehalfPayload($overrides()))
            ->assertSessionHasErrors($error);

        expect(WorkOrder::count())->toBe(0);
    })->with([
        'no department' => [fn (): array => ['requester_department_id' => null], 'requester_department_id'],
        'an Unggul department' => [fn (): array => ['requester_department_id' => Department::factory()->create()->id], 'requester_department_id'],
        'an inactive IC department' => [fn (): array => ['requester_department_id' => Department::factory()->client()->inactive()->create()->id], 'requester_department_id'],
        'no mode' => [fn (): array => ['requester_mode' => null], 'requester_mode'],
        'an unknown mode' => [fn (): array => ['requester_mode' => 'both'], 'requester_mode'],
        'no account' => [fn (): array => ['requester_id' => null], 'requester_id'],
        'an account of another department' => [fn (): array => ['requester_id' => User::factory()->for(Department::factory()->client())->create()->id], 'requester_id'],
        'an inactive account' => [fn (): array => ['requester_id' => User::factory()->for(test()->requesterDepartment)->inactive()->create()->id], 'requester_id'],
        'a deleted account' => [fn (): array => ['requester_id' => tap(User::factory()->for(test()->requesterDepartment)->create())->delete()->id], 'requester_id'],
        'a pending registration' => [fn (): array => ['requester_id' => User::factory()->for(test()->requesterDepartment)->pending()->create()->id], 'requester_id'],
        'a rejected registration' => [fn (): array => ['requester_id' => User::factory()->for(test()->requesterDepartment)->rejected()->create()->id], 'requester_id'],
        'an account and a contact' => [fn (): array => ['requester_name' => 'Pak Andi'], 'requester_name'],
        'a contact without a name' => [fn (): array => ['requester_mode' => 'contact', 'requester_id' => null, 'requester_name' => '   '], 'requester_name'],
        'a contact and an account' => [fn (): array => ['requester_mode' => 'contact', 'requester_name' => 'Pak Andi'], 'requester_id'],
    ]);

    it('refuses the requester fields from an IC user, who is the requester', function (string $field, mixed $value) {
        $this->actingAs($this->icRequester)
            ->post(route('work-orders.store'), [
                'title' => 'Genset mati',
                'work_order_category_id' => $this->woCategory->id,
                'urgency' => 'normal',
                $field => $value === 'dept' ? Department::factory()->client()->create()->id : $value,
            ])
            ->assertSessionHasErrors($field);

        expect(WorkOrder::count())->toBe(0);
    })->with([
        'requester_department_id' => ['requester_department_id', 'dept'],
        'requester_mode' => ['requester_mode', 'contact'],
        'requester_id' => ['requester_id', 1],
        'requester_name' => ['requester_name', 'Pak Andi'],
    ]);

    it('forbids Unggul users without work-orders.create-on-behalf', function () {
        $unggul = unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersCreate);

        $this->actingAs($unggul)->get(route('work-orders.create'))->assertForbidden();
        $this->actingAs($unggul)->post(route('work-orders.store'), onBehalfPayload())->assertForbidden();

        expect(WorkOrder::count())->toBe(0);
    });

    it('never lets an IC user enter work orders on behalf of others, even when granted', function () {
        $this->icRequester->givePermissionTo(Permission::WorkOrdersCreateOnBehalf->value);

        $this->actingAs($this->icRequester)->get(route('work-orders.create'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('department.id', $this->requesterDepartment->id)
                ->where('requesterDepartments', null));

        $this->actingAs($this->icRequester)
            ->post(route('work-orders.store'), onBehalfPayload(['requester_department_id' => Department::factory()->client()->create()->id]))
            ->assertSessionHasErrors(['requester_department_id', 'requester_mode', 'requester_id']);
    });

    it('offers the koordinator the active IC departments instead of their own', function () {
        Department::factory()->client()->inactive()->create(['code' => 'OFF']);

        $this->actingAs($this->koordinatorUser)->get(route('work-orders.create'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('department', null)
                ->where('requesterDepartments', [['id' => $this->requesterDepartment->id, 'code' => 'PRD', 'name' => 'Produksi']]));
    });
});

describe('requester accounts picker', function () {
    it('lists the approved, active accounts of the IC department, by name or email', function () {
        User::factory()->for($this->requesterDepartment)->create(['name' => 'Andi Saputra', 'email' => 'andi@ic.test']);
        User::factory()->for($this->requesterDepartment)->create(['name' => 'Budi', 'email' => 'budi.andi@ic.test']);
        User::factory()->for($this->requesterDepartment)->inactive()->create(['name' => 'Andi Nonaktif']);
        User::factory()->for($this->requesterDepartment)->create(['name' => 'Andi Terhapus'])->delete();
        User::factory()->for($this->requesterDepartment)->pending()->create(['name' => 'Andi Menunggu']);
        User::factory()->for($this->requesterDepartment)->rejected()->create(['name' => 'Andi Ditolak']);
        User::factory()->for(Department::factory()->client())->create(['name' => 'Andi Lain']);

        $response = $this->actingAs($this->koordinatorUser)
            ->getJson(route('work-orders.requester-accounts', ['department' => $this->requesterDepartment->id, 'search' => 'andi']))
            ->assertOk();

        expect($response->json('data'))->toBe([
            ['id' => User::where('email', 'andi@ic.test')->value('id'), 'name' => 'Andi Saputra', 'email' => 'andi@ic.test'],
            ['id' => User::where('email', 'budi.andi@ic.test')->value('id'), 'name' => 'Budi', 'email' => 'budi.andi@ic.test'],
        ]);
    });

    it('returns at most 20 accounts', function () {
        User::factory()->count(25)->for($this->requesterDepartment)->create();

        $this->actingAs($this->koordinatorUser)
            ->getJson(route('work-orders.requester-accounts', ['department' => $this->requesterDepartment->id]))
            ->assertOk()
            ->assertJsonCount(20, 'data');
    });

    it('refuses a department that is not an active IC department', function (Closure $department) {
        $this->actingAs($this->koordinatorUser)
            ->getJson(route('work-orders.requester-accounts', ['department' => $department()->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department');
    })->with([
        'an Unggul department' => [fn (): Department => Department::factory()->create()],
        'an inactive IC department' => [fn (): Department => Department::factory()->client()->inactive()->create()],
    ]);

    it('answers 404 to IC users, whatever they hold', function (Closure $icUser) {
        $this->actingAs($icUser())
            ->getJson(route('work-orders.requester-accounts', ['department' => $this->requesterDepartment->id]))
            ->assertNotFound();
    })->with([
        'pemohon' => [fn (): User => test()->icRequester],
        'holding every permission' => [fn (): User => icUser(...Permission::cases())],
    ]);

    it('forbids Unggul users without work-orders.create-on-behalf', function () {
        $this->actingAs(unggulUser(...array_filter(Permission::cases(), fn (Permission $permission): bool => $permission !== Permission::WorkOrdersCreateOnBehalf)))
            ->getJson(route('work-orders.requester-accounts', ['department' => $this->requesterDepartment->id]))
            ->assertForbidden();
    });
});

describe('requester correction', function () {
    /**
     * An on-behalf draft entered by the test koordinator for the test requester.
     */
    function onBehalfDraft(): WorkOrder
    {
        return WorkOrder::factory()->onBehalf(test()->koordinatorUser, test()->icRequester)->targeting(test()->targetDepartment)->create(['title' => 'Genset mati']);
    }

    /**
     * An update payload keeping the draft's fields, with the given overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function correctionPayload(WorkOrder $workOrder, array $overrides = []): array
    {
        return [
            'title' => $workOrder->title,
            'work_order_category_id' => $workOrder->work_order_category_id,
            'urgency' => 'normal',
            'target_department_id' => $workOrder->target_department_id,
            ...$overrides,
        ];
    }

    it('lets the koordinator who entered the draft correct the requester, within the department', function () {
        $draft = onBehalfDraft();
        $colleague = User::factory()->for($this->requesterDepartment)->create();

        $this->actingAs($this->koordinatorUser)
            ->put(route('work-orders.update', $draft), correctionPayload($draft, ['requester_mode' => 'contact', 'requester_name' => 'Pak Andi']))
            ->assertSessionHasNoErrors();

        expect($draft->refresh())->requester_id->toBeNull()->requester_name->toBe('Pak Andi');

        $this->actingAs($this->koordinatorUser)
            ->put(route('work-orders.update', $draft), correctionPayload($draft, ['requester_mode' => 'account', 'requester_id' => $colleague->id]))
            ->assertSessionHasNoErrors();

        expect($draft->refresh())
            ->requester_id->toBe($colleague->id)
            ->requester_name->toBeNull()
            ->requester_department_id->toBe($this->requesterDepartment->id);
    });

    it('logs the correction with readable values', function () {
        $draft = onBehalfDraft();

        $this->actingAs($this->koordinatorUser)
            ->put(route('work-orders.update', $draft), correctionPayload($draft, ['requester_mode' => 'contact', 'requester_name' => 'Pak Andi']));

        $this->actingAs(adminUser())
            ->getJson(route('admin.activity-log.history', ['work-order', $draft->id]))
            ->assertOk()
            ->assertJsonPath('data.0.event', 'updated')
            ->assertJsonPath('data.0.changes', [
                ['field' => 'requester_id', 'label' => 'Pemohon', 'old' => 'Eko Pemohon', 'new' => null],
                ['field' => 'requester_name', 'label' => 'Nama kontak pemohon', 'old' => null, 'new' => 'Pak Andi'],
            ]);
    });

    it('refuses an account of another department and never moves the department', function () {
        $draft = onBehalfDraft();
        $outsider = User::factory()->for(Department::factory()->client())->create();

        $this->actingAs($this->koordinatorUser)
            ->put(route('work-orders.update', $draft), correctionPayload($draft, ['requester_mode' => 'account', 'requester_id' => $outsider->id]))
            ->assertSessionHasErrors('requester_id');

        $this->actingAs($this->koordinatorUser)
            ->put(route('work-orders.update', $draft), correctionPayload($draft, [
                'requester_mode' => 'account',
                'requester_id' => $this->icRequester->id,
                'requester_department_id' => $outsider->department_id,
            ]))
            ->assertSessionHasErrors('requester_department_id');

        expect($draft->refresh())->requester_id->toBe($this->icRequester->id)->requester_department_id->toBe($this->requesterDepartment->id);
    });

    it('refuses the correction from the IC side, who may still edit the other fields', function () {
        $draft = onBehalfDraft();
        $icEditor = User::factory()->for($this->requesterDepartment)->create()->assignRole('pemohon');

        $this->actingAs($icEditor)
            ->put(route('work-orders.update', $draft), correctionPayload($draft, ['requester_mode' => 'contact', 'requester_name' => 'Pak Andi']))
            ->assertSessionHasErrors(['requester_mode', 'requester_name']);

        $this->actingAs($icEditor)
            ->put(route('work-orders.update', $draft), correctionPayload($draft, ['title' => 'Genset rusak']))
            ->assertSessionHasNoErrors();

        expect($draft->refresh())->title->toBe('Genset rusak')->requester_id->toBe($this->icRequester->id);
    });

    it('hides the draft from other executor users, who cannot correct it', function (Closure $other) {
        $draft = onBehalfDraft();

        $this->actingAs($other())
            ->put(route('work-orders.update', $draft), correctionPayload($draft, ['requester_mode' => 'contact', 'requester_name' => 'Pak Andi']))
            ->assertNotFound();
    })->with([
        'another koordinator' => [fn (): User => User::factory()->create()->assignRole('koordinator')],
        'admin' => [adminUser(...)],
    ]);

    it('offers the correction on the edit form only to the koordinator who entered it', function () {
        $draft = onBehalfDraft();
        $own = WorkOrder::factory()->by($this->icRequester)->create();

        $this->actingAs($this->koordinatorUser)->get(route('work-orders.edit', $draft))
            ->assertInertia(fn (Assert $page): Assert => $page->where('requesterCorrection', [
                'department_id' => $this->requesterDepartment->id,
                'account' => ['id' => $this->icRequester->id, 'name' => 'Eko Pemohon', 'email' => $this->icRequester->email],
                'contact_name' => null,
            ]));

        $this->actingAs($this->icRequester)->get(route('work-orders.edit', $draft))
            ->assertInertia(fn (Assert $page): Assert => $page->where('requesterCorrection', null));

        $this->actingAs($this->icRequester)->get(route('work-orders.edit', $own))
            ->assertInertia(fn (Assert $page): Assert => $page->where('requesterCorrection', null));
    });

    it('allows no correction once the work order is submitted', function () {
        $submitted = WorkOrder::factory()->onBehalf($this->koordinatorUser, $this->icRequester)->submitted()->create();

        expect($this->koordinatorUser->can('updateRequester', $submitted))->toBeFalse();

        $this->actingAs($this->koordinatorUser)
            ->put(route('work-orders.update', $submitted), correctionPayload($submitted, ['requester_mode' => 'contact', 'requester_name' => 'Pak Andi']))
            ->assertForbidden();
    });
});

describe('entered by X on behalf of Y', function () {
    it('says so on the timeline and in the detail', function (string $mode, string $name) {
        $this->actingAs($this->koordinatorUser)
            ->post(route('work-orders.store'), onBehalfPayload($mode === 'contact' ? ['requester_mode' => 'contact', 'requester_id' => null, 'requester_name' => $name] : []));
        $workOrder = WorkOrder::sole();

        $this->actingAs($this->icRequester)->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('timeline.0.user.name', 'Dewi Koordinator')
                ->where('timeline.0.on_behalf_of', $name)
                ->where('workOrder.entered_on_behalf', true)
                ->where('workOrder.entered_by', ['name' => 'Dewi Koordinator'])
                ->where('workOrder.requester.name', $name));
    })->with([
        'an account' => ['account', 'Eko Pemohon'],
        'a contact' => ['contact', 'Pak Andi'],
    ]);

    it('says nothing extra for a work order its requester entered', function () {
        $workOrder = WorkOrder::factory()->by($this->icRequester)->create();
        $workOrder->statusHistories()->create(['to_status' => 'draft', 'user_id' => $this->icRequester->id]);

        $this->actingAs($this->icRequester)->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('timeline.0.on_behalf_of', null)
                ->where('workOrder.entered_on_behalf', false));
    });

    it('says so in the work order\'s history', function () {
        $this->actingAs($this->koordinatorUser)->post(route('work-orders.store'), onBehalfPayload());
        $workOrder = WorkOrder::sole();

        $this->actingAs(adminUser())
            ->getJson(route('admin.activity-log.history', ['work-order', $workOrder->id]))
            ->assertOk()
            ->assertJsonPath('data.0.event', 'created')
            ->assertJsonPath('data.0.summary', 'Diinput oleh Dewi Koordinator atas nama Eko Pemohon')
            ->assertJsonFragment(['field' => 'requester_id', 'label' => 'Pemohon', 'old' => null, 'new' => 'Eko Pemohon']);

        expect(Activity::query()->where('event', 'created')->where('subject_type', 'work-order')->count())->toBe(1);
    });
});

describe('visibility of a work order entered through the form', function () {
    it('shows the draft to the koordinator and the IC department, and to the target only once submitted', function () {
        $pelaksana = User::factory()->for($this->targetDepartment)->create()->assignRole('pelaksana');
        $otherKoordinator = User::factory()->create()->assignRole('koordinator');

        $this->actingAs($this->koordinatorUser)->post(route('work-orders.store'), onBehalfPayload());
        $workOrder = WorkOrder::sole();

        expect([
            $this->koordinatorUser->can('view', $workOrder),
            $this->icRequester->can('view', $workOrder),
            $pelaksana->can('view', $workOrder),
            $otherKoordinator->can('view', $workOrder),
        ])->toBe([true, true, false, false]);

        $this->actingAs($this->koordinatorUser)
            ->post(route('work-orders.transitions.store', $workOrder), ['status' => 'diajukan'])
            ->assertSessionHasNoErrors();

        expect($pelaksana->can('view', $workOrder->refresh()))->toBeTrue()
            ->and($otherKoordinator->can('view', $workOrder))->toBeFalse();
    });
});
