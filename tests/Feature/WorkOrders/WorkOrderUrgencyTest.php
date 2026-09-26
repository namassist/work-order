<?php

use App\Actions\WorkOrders\CreateWorkOrder;
use App\Enums\Permission;
use App\Enums\WorkOrderUrgency;
use App\Models\Department;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->department = Department::factory()->create(['code' => 'IT']);
    $this->category = WorkOrderCategory::factory()->create();
});

/**
 * A work order in the test department.
 */
function urgentWorkOrder(array $attributes = []): WorkOrder
{
    return WorkOrder::factory()->create(['department_id' => test()->department->id, ...$attributes]);
}

describe('storage', function () {
    it('defaults a new work order to normal', function () {
        $requester = userInDepartment($this->department, Permission::WorkOrdersCreate);

        $workOrder = app(CreateWorkOrder::class)->handle([
            'title' => 'Lampu mati',
            'work_order_category_id' => $this->category->id,
        ], $requester);

        expect($workOrder->urgency)->toBe(WorkOrderUrgency::Normal)
            ->and($workOrder->fresh()->urgency)->toBe(WorkOrderUrgency::Normal);
    });

    it('backfills existing work orders as normal', function () {
        /** @var Migration $migration */
        $migration = require collect(glob(database_path('migrations/*_add_urgency_to_work_orders_table.php')))->sole();
        $existing = urgentWorkOrder();

        $migration->down();
        DB::table('work_orders')->insert([
            'title' => 'Sebelum urgensi',
            'department_id' => $this->department->id,
            'work_order_category_id' => $this->category->id,
            'created_by' => $existing->created_by,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $migration->up();

        expect(DB::table('work_orders')->pluck('urgency')->unique()->all())->toBe(['normal']);
    });
});

describe('form', function () {
    it('saves the urgency chosen on create', function () {
        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersCreate))
            ->post(route('work-orders.store'), [
                'title' => 'Genset mati',
                'work_order_category_id' => $this->category->id,
                'urgency' => 'mendesak',
            ])
            ->assertRedirect();

        expect(WorkOrder::sole()->urgency)->toBe(WorkOrderUrgency::Mendesak);
    });

    it('requires a known urgency', function (?string $urgency) {
        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersCreate))
            ->post(route('work-orders.store'), [
                'title' => 'Genset mati',
                'work_order_category_id' => $this->category->id,
                'urgency' => $urgency,
            ])
            ->assertSessionHasErrors('urgency');

        expect(WorkOrder::count())->toBe(0);
    })->with([
        'missing' => [null],
        'unknown' => ['kritis'],
    ]);

    it('changes the urgency of a draft', function () {
        $workOrder = urgentWorkOrder(['work_order_category_id' => $this->category->id]);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersUpdate))
            ->put(route('work-orders.update', $workOrder), [
                'title' => $workOrder->title,
                'work_order_category_id' => $this->category->id,
                'urgency' => 'tinggi',
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        expect($workOrder->fresh()->urgency)->toBe(WorkOrderUrgency::Tinggi);
    });

    it('does not change the urgency once the work order left Draft', function () {
        $workOrder = urgentWorkOrder([
            'work_order_category_id' => $this->category->id,
            'status' => 'diajukan',
            'number' => 'WO/IT/2026/09/0001',
        ]);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersUpdate))
            ->put(route('work-orders.update', $workOrder), [
                'title' => $workOrder->title,
                'work_order_category_id' => $this->category->id,
                'urgency' => 'mendesak',
            ])
            ->assertForbidden();

        expect($workOrder->fresh()->urgency)->toBe(WorkOrderUrgency::Normal);
    });

    it('offers the urgency options on the create form', function () {
        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersCreate))
            ->get(route('work-orders.create'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('urgencies', [
                    ['value' => 'rendah', 'label' => 'Rendah'],
                    ['value' => 'normal', 'label' => 'Normal'],
                    ['value' => 'tinggi', 'label' => 'Tinggi'],
                    ['value' => 'mendesak', 'label' => 'Mendesak'],
                ]));
    });
});

describe('list', function () {
    it('shows each work order\'s urgency', function () {
        urgentWorkOrder(['urgency' => 'mendesak']);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('workOrders.data.0.urgency', ['value' => 'mendesak', 'label' => 'Mendesak'])
                ->has('urgencies', 4));
    });

    it('filters by urgency', function () {
        $urgent = urgentWorkOrder(['urgency' => 'mendesak']);
        urgentWorkOrder(['urgency' => 'normal']);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index', ['urgency' => 'mendesak']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('workOrders.data', 1)
                ->where('workOrders.data.0.id', $urgent->id)
                ->where('filters.urgency', 'mendesak'));
    });

    it('sorts by urgency, most urgent first, then newest', function () {
        $normal = urgentWorkOrder(['urgency' => 'normal', 'created_at' => now()->subHour()]);
        $low = urgentWorkOrder(['urgency' => 'rendah']);
        $urgentOld = urgentWorkOrder(['urgency' => 'mendesak', 'created_at' => now()->subDay()]);
        $high = urgentWorkOrder(['urgency' => 'tinggi', 'created_at' => now()->subDays(2)]);
        $urgentNew = urgentWorkOrder(['urgency' => 'mendesak', 'created_at' => now()->subMinute()]);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index', ['sort' => 'urgensi']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('workOrders.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [
                    $urgentNew->id, $urgentOld->id, $high->id, $normal->id, $low->id,
                ])
                ->where('filters.sort', 'urgensi'));
    });

    it('keeps newest first without a sort', function () {
        $older = urgentWorkOrder(['urgency' => 'mendesak', 'created_at' => now()->subDay()]);
        $newer = urgentWorkOrder(['urgency' => 'rendah']);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('workOrders.data.0.id', $newer->id)
                ->where('workOrders.data.1.id', $older->id)
                ->where('filters.sort', ''));
    });

    it('rejects an unknown urgency filter or sort', function (array $query, string $field) {
        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index', $query))
            ->assertSessionHasErrors($field);
    })->with([
        'urgency' => [['urgency' => 'kritis'], 'urgency'],
        'sort' => [['sort' => 'judul'], 'sort'],
    ]);
});

it('shows urgency changes with labels in the history panel', function () {
    $workOrder = urgentWorkOrder(['work_order_category_id' => $this->category->id]);

    $this->travel(1)->minutes();
    $workOrder->update(['urgency' => 'mendesak']);

    $this->actingAs(adminUser())
        ->getJson(route('admin.activity-log.history', ['work-order', $workOrder->id]))
        ->assertOk()
        ->assertJsonPath('data.0.changes.0', ['field' => 'urgency', 'label' => 'Urgensi', 'old' => 'Normal', 'new' => 'Mendesak']);
});
