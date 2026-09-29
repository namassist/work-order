<?php

use App\Enums\Permission;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| FLOW.md v2 §4: an Admin WO enters every work order on behalf of IC: an IC
| requester department and the IC contact's name (both required), plus an
| optional PIC Work Order name and an optional, informational target
| department. The department changes only in Draft, since the number
| carries its code from the first submission; the names in Draft and Ditolak.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Carbon::setTestNow('2026-09-25 02:00:00');

    $this->ic = Company::factory()->client()->create(['code' => 'IC']);
    $this->requesterDepartment = Department::factory()->for($this->ic)->create(['code' => 'PRD', 'name' => 'Produksi']);
    $this->woCategory = WorkOrderCategory::factory()->create();
    $this->adminWo = User::factory()->create(['name' => 'Dewi Admin WO'])->assignRole('admin-wo');
});

/**
 * A valid store payload with the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function requesterPayload(array $overrides = []): array
{
    return [
        'title' => 'Genset mati',
        'work_order_category_id' => test()->woCategory->id,
        'urgency' => 'normal',
        'requester_department_id' => test()->requesterDepartment->id,
        'requester_name' => 'Pak Andi',
        ...$overrides,
    ];
}

/**
 * An update payload keeping the work order's fields, with the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function requesterUpdatePayload(WorkOrder $workOrder, array $overrides = []): array
{
    return [
        'title' => $workOrder->title,
        'work_order_category_id' => $workOrder->work_order_category_id,
        'urgency' => $workOrder->urgency->value,
        'requester_name' => $workOrder->requester_name,
        ...$overrides,
    ];
}

describe('create', function () {
    it('stores the IC department, the contact, and the PIC, entered by the Admin WO', function () {
        $this->actingAs($this->adminWo)
            ->post(route('work-orders.store'), requesterPayload(['requester_name' => '  Pak Andi  ', 'pic_name' => 'Bu Sari']))
            ->assertSessionHasNoErrors();

        expect(WorkOrder::sole())
            ->requester_department_id->toBe($this->requesterDepartment->id)
            ->requester_name->toBe('Pak Andi')
            ->pic_name->toBe('Bu Sari')
            ->target_department_id->toBeNull()
            ->created_by->toBe($this->adminWo->id);
    });

    it('stores a blank PIC name as none', function () {
        $this->actingAs($this->adminWo)->post(route('work-orders.store'), requesterPayload(['pic_name' => '   ']))->assertSessionHasNoErrors();

        expect(WorkOrder::sole()->pic_name)->toBeNull();
    });

    it('validates the requester fields', function (Closure $overrides, string $field) {
        $this->actingAs($this->adminWo)
            ->post(route('work-orders.store'), requesterPayload($overrides()))
            ->assertSessionHasErrors($field);

        expect(WorkOrder::count())->toBe(0);
    })->with([
        'contact name missing' => [fn (): array => ['requester_name' => null], 'requester_name'],
        'contact name blank' => [fn (): array => ['requester_name' => '   '], 'requester_name'],
        'contact name too long' => [fn (): array => ['requester_name' => str_repeat('a', 151)], 'requester_name'],
        'PIC name too long' => [fn (): array => ['pic_name' => str_repeat('a', 151)], 'pic_name'],
        'department missing' => [fn (): array => ['requester_department_id' => null], 'requester_department_id'],
        'an executor department' => [fn (): array => ['requester_department_id' => Department::factory()->create()->id], 'requester_department_id'],
        'an inactive IC department' => [fn (): array => ['requester_department_id' => Department::factory()->client()->inactive()->create()->id], 'requester_department_id'],
        'a deleted IC department' => [fn (): array => ['requester_department_id' => tap(Department::factory()->client()->create())->delete()->id], 'requester_department_id'],
        'a client target department' => [fn (): array => ['target_department_id' => Department::factory()->client()->create()->id], 'target_department_id'],
    ]);

    it('offers active IC departments only', function () {
        Department::factory()->client()->inactive()->create(['code' => 'OLD']);
        Department::factory()->create(['code' => 'ENG']);

        $this->actingAs($this->adminWo)->get(route('work-orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('requesterDepartments', [['id' => $this->requesterDepartment->id, 'code' => 'PRD', 'name' => 'Produksi']])
                ->missing('department'));
    });

    it('needs work-orders.view as well as work-orders.create', function () {
        $this->actingAs(unggulUser(Permission::WorkOrdersCreate))->get(route('work-orders.create'))->assertForbidden();
    });

    it('is for Admin WO and the admin only', function (string $role, bool $allowed) {
        $user = userWithRole($role);

        $this->actingAs($user)->get(route('work-orders.create'))->assertStatus($allowed ? 200 : 403);
        $this->actingAs($user)->post(route('work-orders.store'), requesterPayload())->assertStatus($allowed ? 302 : 403);
    })->with([
        'admin' => ['admin', true],
        'Admin WO' => ['admin-wo', true],
        'Lead Operational' => ['lead-operational', false],
        'PIC Timesheet' => ['pic-timesheet', false],
        'Rental' => ['rental', false],
        'Direktur' => ['direktur', false],
        'Finance' => ['finance', false],
        'Viewer' => ['viewer', false],
    ]);
});

describe('update', function () {
    it('changes the requester department, contact, and PIC of a draft', function () {
        $workOrder = WorkOrder::factory()->by($this->adminWo)->create();

        $this->actingAs($this->adminWo)
            ->put(route('work-orders.update', $workOrder), requesterUpdatePayload($workOrder, [
                'requester_department_id' => $this->requesterDepartment->id,
                'requester_name' => 'Bu Rina',
                'pic_name' => 'Pak Joko',
            ]))
            ->assertSessionHasNoErrors();

        expect($workOrder->refresh())
            ->requester_department_id->toBe($this->requesterDepartment->id)
            ->requester_name->toBe('Bu Rina')
            ->pic_name->toBe('Pak Joko');
    });

    it('lets any Admin WO edit a draft, not only the one who entered it', function () {
        $workOrder = WorkOrder::factory()->by($this->adminWo)->create();

        $this->actingAs(userWithRole('admin-wo'))
            ->put(route('work-orders.update', $workOrder), requesterUpdatePayload($workOrder, ['requester_department_id' => $workOrder->requester_department_id, 'requester_name' => 'Bu Rina']))
            ->assertSessionHasNoErrors();

        expect($workOrder->refresh()->requester_name)->toBe('Bu Rina');
    });

    it('keeps the requester department once submitted but still changes the names in Ditolak', function () {
        $workOrder = WorkOrder::factory()->by($this->adminWo)->requestedBy($this->requesterDepartment)->rejected()->create();
        $other = Department::factory()->for($this->ic)->create();

        $this->actingAs($this->adminWo)
            ->put(route('work-orders.update', $workOrder), requesterUpdatePayload($workOrder, ['requester_department_id' => $other->id]))
            ->assertSessionHasErrors(['requester_department_id' => 'Departemen pemohon tidak dapat diubah setelah work order diajukan.']);

        $this->actingAs($this->adminWo)
            ->put(route('work-orders.update', $workOrder), requesterUpdatePayload($workOrder, ['requester_name' => 'Bu Rina', 'pic_name' => 'Pak Joko']))
            ->assertSessionHasNoErrors();

        expect($workOrder->refresh())
            ->requester_department_id->toBe($this->requesterDepartment->id)
            ->requester_name->toBe('Bu Rina')
            ->pic_name->toBe('Pak Joko');
    });

    it('ignores an empty requester department sent once submitted', function (?string $empty) {
        $workOrder = WorkOrder::factory()->by($this->adminWo)->requestedBy($this->requesterDepartment)->rejected()->create();

        $this->actingAs($this->adminWo)
            ->put(route('work-orders.update', $workOrder), requesterUpdatePayload($workOrder, ['requester_department_id' => $empty, 'requester_name' => 'Bu Rina']))
            ->assertSessionHasNoErrors();

        expect($workOrder->refresh())
            ->requester_department_id->toBe($this->requesterDepartment->id)
            ->requester_name->toBe('Bu Rina');
    })->with(['empty' => [''], 'null' => [null]]);

    it('keeps a deactivated requester department of a draft valid', function () {
        $workOrder = WorkOrder::factory()->by($this->adminWo)->requestedBy($this->requesterDepartment)->create();
        $this->requesterDepartment->update(['is_active' => false]);

        $this->actingAs($this->adminWo)
            ->put(route('work-orders.update', $workOrder), requesterUpdatePayload($workOrder, ['requester_department_id' => $this->requesterDepartment->id]))
            ->assertSessionHasNoErrors();
    });

    it('offers the requester departments on the edit page only while the work order is a draft', function () {
        $draft = WorkOrder::factory()->by($this->adminWo)->requestedBy($this->requesterDepartment)->create();
        $rejected = WorkOrder::factory()->by($this->adminWo)->requestedBy($this->requesterDepartment)->rejected()->create();

        $this->actingAs($this->adminWo)->get(route('work-orders.edit', $draft))
            ->assertInertia(fn (Assert $page): Assert => $page->where('requesterDepartments.0.id', $this->requesterDepartment->id));
        $this->actingAs($this->adminWo)->get(route('work-orders.edit', $rejected))
            ->assertInertia(fn (Assert $page): Assert => $page->where('requesterDepartments', null));
    });

    it('requires a contact name on edit too', function () {
        $workOrder = WorkOrder::factory()->by($this->adminWo)->create();

        $this->actingAs($this->adminWo)
            ->put(route('work-orders.update', $workOrder), requesterUpdatePayload($workOrder, ['requester_department_id' => $workOrder->requester_department_id, 'requester_name' => '']))
            ->assertSessionHasErrors('requester_name');
    });
});

describe('entered by X on behalf of Y', function () {
    it('says so on the timeline and in the detail', function () {
        $this->actingAs($this->adminWo)->post(route('work-orders.store'), requesterPayload(['pic_name' => 'Bu Sari']));
        $workOrder = WorkOrder::sole();

        $this->actingAs($this->adminWo)->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('timeline.0.user.name', 'Dewi Admin WO')
                ->where('timeline.0.on_behalf_of', 'Pak Andi')
                ->where('workOrder.entered_by', ['name' => 'Dewi Admin WO'])
                ->where('workOrder.requester_name', 'Pak Andi')
                ->where('workOrder.pic_name', 'Bu Sari'));
    });

    it('says so in the work order\'s history', function () {
        $this->actingAs($this->adminWo)->post(route('work-orders.store'), requesterPayload());
        $workOrder = WorkOrder::sole();

        $this->actingAs(adminUser())
            ->getJson(route('admin.activity-log.history', ['work-order', $workOrder->id]))
            ->assertOk()
            ->assertJsonPath('data.0.event', 'created')
            ->assertJsonPath('data.0.summary', 'Diinput oleh Dewi Admin WO atas nama Pak Andi')
            ->assertJsonFragment(['field' => 'requester_department_id', 'label' => 'Departemen pemohon', 'old' => null, 'new' => 'PRD'])
            ->assertJsonFragment(['field' => 'requester_name', 'label' => 'Kontak pemohon', 'old' => null, 'new' => 'Pak Andi']);

        expect(Activity::query()->where('event', 'created')->where('subject_type', 'work-order')->count())->toBe(1);
    });
});

it('refuses a blank contact name in the database too', function () {
    expect(fn () => WorkOrder::factory()->create(['requester_name' => ' ']))->toThrow(QueryException::class);
});
