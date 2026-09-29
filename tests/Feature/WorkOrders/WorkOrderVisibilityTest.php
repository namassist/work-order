<?php

use App\Actions\Attachments\AddAttachment;
use App\Enums\Permission;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;

/*
| FLOW.md v2 §6 as a matrix: every role against every kind of work order, on
| every surface that shows work orders. Each surface goes through
| WorkOrder::isVisibleTo() or scopeVisibleTo(); this file proves they agree
| with the spec and with each other.
|
| Internal users with work-orders.view see every submitted work order; drafts
| (cancelled ones included) only with work-orders.create (Admin WO, admin).
| Client company users keep the v1 safeguard: their own department at most.
|
| Work orders (entered by Admin WO X, requested by IC department A unless noted):
|   draft                 a draft
|   other Admin WO draft  a draft entered by Admin WO Y
|   cancelled draft       cancelled before submission (no number)
|   submitted             Diajukan
|   cancelled submitted   Diajukan, then cancelled (numbered)
|   other IC dept         Diajukan, requested by IC department B
|   rejected              Ditolak
|   in progress           Dikerjakan
|   billed                Penagihan, payment due date passed
|   paid                  Selesai
*/

const MATRIX_WORK_ORDERS = ['draft', 'other Admin WO draft', 'cancelled draft', 'submitted', 'cancelled submitted', 'other IC dept', 'rejected', 'in progress', 'billed', 'paid'];

/** Numbered, so seen by every internal user with work-orders.view. */
const MATRIX_SUBMITTED = ['submitted', 'cancelled submitted', 'other IC dept', 'rejected', 'in progress', 'billed', 'paid'];

/** Diajukan: the dashboard's "Menunggu Diterima". */
const MATRIX_PENDING = ['submitted', 'other IC dept'];

/** Past their target date while Diajukan or Dikerjakan. Rejected and paid are past their target date too, but do not count. */
const MATRIX_OVERDUE_TARGET = [...MATRIX_PENDING, 'in progress'];

/** Past the payment due date while Penagihan. */
const MATRIX_OVERDUE_PAYMENT = ['billed'];

/**
 * Builds the departments, users, and work orders of the matrix, and returns
 * the user under test.
 *
 * @return array{user: User, workOrders: array<string, WorkOrder>}
 */
function visibilityWorld(string $userKey): array
{
    test()->seed(RolePermissionSeeder::class);
    Carbon::setTestNow('2026-09-25 02:00:00');

    $ic = Company::factory()->client()->create(['code' => 'IC']);
    $unggul = Company::factory()->create(['code' => 'UGL']);
    $department = fn (Company $company, string $code): Department => Department::factory()->for($company)->create(['code' => $code]);
    [$a, $b, $ops] = [$department($ic, 'IC-A'), $department($ic, 'IC-B'), $department($unggul, 'OPS')];

    $user = fn (string $role, Department $in): User => User::factory()->for($in)->create()->assignRole($role);
    $adminWo = $user('admin-wo', $ops);
    $otherAdminWo = $user('admin-wo', $ops);
    $everyPermission = fn (Department $in): User => User::factory()->for($in)->create()->givePermissionTo(Permission::values());

    $users = [
        'admin' => fn (): User => $user('admin', $ops),
        'Admin WO (entered them)' => fn (): User => $adminWo,
        'another Admin WO' => fn (): User => $user('admin-wo', $ops),
        'Lead Operational' => fn (): User => $user('lead-operational', $ops),
        'PIC Timesheet' => fn (): User => $user('pic-timesheet', $ops),
        'Rental' => fn (): User => $user('rental', $ops),
        'Direktur' => fn (): User => $user('direktur', $ops),
        'Finance' => fn (): User => $user('finance', $ops),
        'Viewer' => fn (): User => $user('viewer', $ops),
        'internal without a role' => fn (): User => User::factory()->for($ops)->create(),
        'internal creating without view' => fn (): User => User::factory()->for($ops)->create()->givePermissionTo(Permission::WorkOrdersCreate->value),
        'IC A with every permission' => fn (): User => $everyPermission($a),
        'IC A with the admin role' => fn (): User => $user('admin', $a),
        'IC B with every permission' => fn (): User => $everyPermission($b),
    ];

    $overdue = ['target_date' => '2026-09-01'];
    $factory = fn () => WorkOrder::factory()->by($adminWo)->requestedBy($a);

    $workOrders = [
        'draft' => $factory()->create(),
        'other Admin WO draft' => $factory()->by($otherAdminWo)->create(),
        'cancelled draft' => $factory()->cancelled()->create(),
        'submitted' => $factory()->submitted()->create($overdue),
        'cancelled submitted' => $factory()->submitted()->cancelled()->create($overdue),
        'other IC dept' => $factory()->requestedBy($b)->submitted()->create($overdue),
        'rejected' => $factory()->rejected()->create($overdue),
        'in progress' => $factory()->inProgress()->create($overdue),
        'billed' => $factory()->billed(['invoice_date' => '2026-08-25', 'due_date' => '2026-09-01'])->create($overdue),
        'paid' => $factory()->paid()->create($overdue),
    ];

    foreach ($workOrders as $key => $workOrder) {
        $workOrder->update(['title' => $key]);
    }

    return ['user' => $users[$userKey](), 'workOrders' => $workOrders];
}

/**
 * Keys of the work orders with the given ids, in matrix order.
 *
 * @param  array<string, WorkOrder>  $workOrders
 * @param  iterable<int>  $ids
 * @return list<string>
 */
function matrixKeys(array $workOrders, iterable $ids): array
{
    $ids = collect($ids)->map(fn (mixed $id): int => (int) $id)->all();

    return array_values(array_keys(array_filter($workOrders, fn (WorkOrder $workOrder): bool => in_array($workOrder->id, $ids, true))));
}

/**
 * The titles in an exported sheet (column 2 after the header row).
 *
 * @return list<string>
 */
function matrixExportTitles(TestResponse $response): array
{
    $path = (string) tempnam(sys_get_temp_dir(), 'wo-matrix');
    file_put_contents($path, $response->streamedContent());

    $reader = new Reader;
    $reader->open($path);
    $titles = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $index => $row) {
            if ($index > 1) {
                $titles[] = (string) $row->cells[1]->getValue();
            }
        }
    }

    $reader->close();
    unlink($path);

    return $titles;
}

/**
 * @param  list<string>  $visible
 * @return list<string>
 */
function inMatrixOrder(array $visible): array
{
    return array_values(array_intersect(MATRIX_WORK_ORDERS, $visible));
}

$departmentA = array_values(array_diff(MATRIX_WORK_ORDERS, ['other IC dept']));

// [user, visible work orders, may list them (work-orders.view), may export
// them (work-orders.export, internal-only, so never an IC user)]
dataset('visibility matrix', [
    'admin' => ['admin', MATRIX_WORK_ORDERS, true, true],
    'Admin WO (entered them)' => ['Admin WO (entered them)', MATRIX_WORK_ORDERS, true, true],
    'another Admin WO' => ['another Admin WO', MATRIX_WORK_ORDERS, true, true],
    'Lead Operational' => ['Lead Operational', MATRIX_SUBMITTED, true, true],
    'PIC Timesheet' => ['PIC Timesheet', MATRIX_SUBMITTED, true, true],
    'Rental' => ['Rental', MATRIX_SUBMITTED, true, true],
    'Direktur' => ['Direktur', MATRIX_SUBMITTED, true, true],
    'Finance' => ['Finance', MATRIX_SUBMITTED, true, true],
    'Viewer' => ['Viewer', MATRIX_SUBMITTED, true, true],
    'internal without a role' => ['internal without a role', [], false, false],
    'internal creating without view' => ['internal creating without view', [], false, false],
    'IC A with every permission' => ['IC A with every permission', $departmentA, true, false],
    'IC A with the admin role' => ['IC A with the admin role', $departmentA, true, false],
    'IC B with every permission' => ['IC B with every permission', ['other IC dept'], true, false],
]);

it('agrees in the policy, the rule, and the list query', function (string $userKey, array $visible) {
    ['user' => $user, 'workOrders' => $workOrders] = visibilityWorld($userKey);

    $byPolicy = array_values(array_keys(array_filter($workOrders, fn (WorkOrder $workOrder): bool => $user->can('view', $workOrder))));
    $byRule = array_values(array_keys(array_filter($workOrders, fn (WorkOrder $workOrder): bool => $workOrder->isVisibleTo($user))));

    expect($byPolicy)->toBe(inMatrixOrder($visible))
        ->and($byRule)->toBe(inMatrixOrder($visible))
        ->and(matrixKeys($workOrders, WorkOrder::visibleTo($user)->pluck('id')))->toBe(inMatrixOrder($visible));
})->with('visibility matrix');

it('lists exactly the visible work orders', function (string $userKey, array $visible, bool $mayList) {
    ['user' => $user, 'workOrders' => $workOrders] = visibilityWorld($userKey);

    $response = $this->actingAs($user)->get(route('work-orders.index'));

    if (! $mayList) {
        $response->assertForbidden();

        return;
    }

    $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('workOrders.data', fn ($rows): bool => matrixKeys($workOrders, collect($rows)->pluck('id')) === inMatrixOrder($visible))
        ->where('stats.total', count($visible)));
})->with('visibility matrix');

it('opens the detail page of visible work orders and answers 404 for the rest', function (string $userKey, array $visible) {
    ['user' => $user, 'workOrders' => $workOrders] = visibilityWorld($userKey);

    foreach ($workOrders as $key => $workOrder) {
        $this->actingAs($user)->get(route('work-orders.show', $workOrder))
            ->assertStatus(in_array($key, $visible, true) ? 200 : 404);
    }
})->with('visibility matrix');

it('answers 404 to comments exactly on the work orders the user cannot see', function (string $userKey, array $visible) {
    ['user' => $user, 'workOrders' => $workOrders] = visibilityWorld($userKey);

    // More posts than the comment limiter allows in a minute; throttling has its own tests.
    $this->withoutMiddleware(ThrottleRequests::class);

    foreach ($workOrders as $key => $workOrder) {
        $status = $this->actingAs($user)->post(route('work-orders.comments.store', $workOrder), ['body' => 'Cek'])->getStatusCode();

        // Visible: posted, refused by status, or forbidden without work-orders.comment; never 404.
        expect($status === 404)->toBe(! in_array($key, $visible, true), "{$key}: {$status}");
    }
})->with('visibility matrix');

it('downloads attachments only of visible work orders', function (string $userKey, array $visible) {
    Storage::fake('attachments');
    ['user' => $user, 'workOrders' => $workOrders] = visibilityWorld($userKey);

    foreach ($workOrders as $key => $workOrder) {
        $media = app(AddAttachment::class)->handle($workOrder, $workOrder->documentsCollection(), attachmentUpload('dokumen.pdf'), $workOrder->enteredBy);

        $this->actingAs($user)->get(route('attachments.show', $media))
            ->assertStatus(in_array($key, $visible, true) ? 200 : 404);
    }
})->with('visibility matrix');

it('shows invoices and downloads their files only on visible work orders', function (string $userKey, array $visible) {
    Storage::fake('attachments');
    ['user' => $user, 'workOrders' => $workOrders] = visibilityWorld($userKey);

    foreach (['billed', 'paid'] as $key) {
        $workOrder = $workOrders[$key];
        $seen = in_array($key, $visible, true);

        foreach ([WorkOrder::INVOICE, WorkOrder::BAST, WorkOrder::PAYMENT_PROOF] as $collection) {
            $media = app(AddAttachment::class)->handle($workOrder, $workOrder->attachmentCollections()[$collection], attachmentUpload('dokumen.pdf'), $workOrder->enteredBy);

            $this->actingAs($user)->get(route('attachments.show', $media))->assertStatus($seen ? 200 : 404);
        }

        $response = $this->actingAs($user)->get(route('work-orders.show', $workOrder))->assertStatus($seen ? 200 : 404);

        if ($seen) {
            $response->assertInertia(fn (Assert $page): Assert => $page
                ->where('invoice.number', $workOrder->invoice?->number)
                ->has('invoiceAttachments', 3));
        }
    }
})->with('visibility matrix');

it('counts and lists only visible work orders on the dashboard', function (string $userKey, array $visible, bool $mayList) {
    ['user' => $user, 'workOrders' => $workOrders] = visibilityWorld($userKey);
    $count = fn (array $keys): int => count(array_intersect($visible, $keys));

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    if (! $mayList) {
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->where('workOrderCounts', null)
            ->where('urgentWorkOrders', null)
            ->where('recentWorkOrders', null));

        return;
    }

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->loadDeferredProps(fn (Assert $reload): Assert => $reload
            ->where('workOrderCounts', [
                'submitted' => $count(MATRIX_PENDING),
                'in_progress' => $count(['in progress']),
                'billing' => $count(['billed']),
                'overdue' => $count([...MATRIX_OVERDUE_TARGET, ...MATRIX_OVERDUE_PAYMENT]),
                'overdue_by' => ['target_date' => $count(MATRIX_OVERDUE_TARGET), 'payment_due_date' => $count(MATRIX_OVERDUE_PAYMENT)],
            ])
            // "WO Terbaru" lists at most 8 of them.
            ->where('recentWorkOrders', fn ($rows): bool => count($rows) === min(8, count($visible))
                && array_diff(matrixKeys($workOrders, collect($rows)->pluck('id')), $visible) === [])));
})->with('visibility matrix');

it('exports only visible work orders', function (string $userKey, array $visible, bool $mayList, bool $mayExport) {
    ['user' => $user] = visibilityWorld($userKey);
    $user->givePermissionTo(Permission::WorkOrdersExport->value);

    $response = $this->actingAs($user)->get(route('work-orders.export'));

    if (! $mayExport) {
        $response->assertForbidden();

        return;
    }

    $response->assertOk();
    expect(inMatrixOrder(matrixExportTitles($response)))->toBe(inMatrixOrder($visible));
})->with('visibility matrix');
