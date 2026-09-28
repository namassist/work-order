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
| FLOW.md §6 as a matrix: every kind of user against every kind of work
| order, on every surface that shows work orders. Each surface goes through
| WorkOrder::isVisibleTo() or scopeVisibleTo(); this file proves they agree
| with the spec and with each other.
|
| Work orders (all requested by IC department A unless noted):
|   own draft            draft entered by pemohon A, target T
|   on-behalf draft      draft entered by koordinator K for a contact in A, target T
|   submitted            submitted by pemohon A, target T
|   on-behalf submitted  entered by koordinator K for pemohon A, submitted, target T
|   cancelled draft      cancelled before submission, target T
|   other IC dept        requested by IC department B, submitted, target T
|   other target         submitted, target U
|   rejected             submitted by pemohon A, rejected by T (Ditolak)
|   in progress          submitted by pemohon A, accepted by T (Dikerjakan)
|   billed               invoiced by T, payment due date passed (Penagihan)
|   paid                 invoice paid (Selesai)
*/

const MATRIX_WORK_ORDERS = ['own draft', 'on-behalf draft', 'submitted', 'on-behalf submitted', 'cancelled draft', 'other IC dept', 'other target', 'rejected', 'in progress', 'billed', 'paid'];

/** Submitted at least once, so seen by view-all. */
const MATRIX_SUBMITTED = ['submitted', 'on-behalf submitted', 'other IC dept', 'other target', 'rejected', 'in progress', 'billed', 'paid'];

/** Diajukan, which counts as pending. */
const MATRIX_PENDING = ['submitted', 'on-behalf submitted', 'other IC dept', 'other target'];

/** Past the date their status is late against: the target date (Diajukan, Dikerjakan) or the payment due date (Penagihan). Rejected and paid are past their target date too, but do not count. */
const MATRIX_OVERDUE = [...MATRIX_PENDING, 'in progress', 'billed'];

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
    [$a, $b] = [$department($ic, 'IC-A'), $department($ic, 'IC-B')];
    [$k, $t, $u, $keu, $it] = [$department($unggul, 'KOR'), $department($unggul, 'TGT'), $department($unggul, 'OTH'), $department($unggul, 'KEU'), $department($unggul, 'IT')];

    $user = fn (Department $in, string $role): User => User::factory()->for($in)->create()->assignRole($role);
    $pemohonA = $user($a, 'pemohon');
    $koordinator = $user($k, 'koordinator');

    $users = [
        'IC pemohon A' => fn (): User => $pemohonA,
        'IC viewer A' => fn (): User => $user($a, 'viewer'),
        'IC A holding view-all' => fn (): User => $user($a, 'pemohon')->givePermissionTo(Permission::WorkOrdersViewAll->value),
        'IC pemohon B' => fn (): User => $user($b, 'pemohon'),
        'koordinator K (entered them)' => fn (): User => $koordinator,
        'another koordinator in K' => fn (): User => $user($k, 'koordinator'),
        'pelaksana T' => fn (): User => $user($t, 'pelaksana'),
        'viewer T' => fn (): User => $user($t, 'viewer'),
        'pelaksana U' => fn (): User => $user($u, 'pelaksana'),
        'keuangan' => fn (): User => $user($keu, 'keuangan'),
        'admin' => fn (): User => $user($it, 'admin'),
    ];

    $pemohonB = $user($b, 'pemohon');
    $overdue = ['target_date' => '2026-09-01'];

    $workOrders = [
        'own draft' => WorkOrder::factory()->by($pemohonA)->targeting($t)->create(),
        'on-behalf draft' => WorkOrder::factory()->onBehalf($koordinator, contactName: 'Pak Andi')->targeting($t)->create(['requester_department_id' => $a->id]),
        'submitted' => WorkOrder::factory()->by($pemohonA)->targeting($t)->submitted()->create($overdue),
        'on-behalf submitted' => WorkOrder::factory()->onBehalf($koordinator, $pemohonA)->targeting($t)->submitted()->create($overdue),
        'cancelled draft' => WorkOrder::factory()->by($pemohonA)->targeting($t)->cancelled()->create(),
        'other IC dept' => WorkOrder::factory()->by($pemohonB)->targeting($t)->submitted()->create($overdue),
        'other target' => WorkOrder::factory()->by($pemohonA)->targeting($u)->submitted()->create($overdue),
        'rejected' => WorkOrder::factory()->by($pemohonA)->targeting($t)->rejected()->create($overdue),
        'in progress' => WorkOrder::factory()->by($pemohonA)->targeting($t)->inProgress()->create($overdue),
        'billed' => WorkOrder::factory()->by($pemohonA)->targeting($t)->billed(['invoice_date' => '2026-08-25', 'due_date' => '2026-09-01'])->create($overdue),
        'paid' => WorkOrder::factory()->by($pemohonA)->targeting($t)->paid()->create($overdue),
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

$ownDepartment = ['own draft', 'on-behalf draft', 'submitted', 'on-behalf submitted', 'cancelled draft', 'other target', 'rejected', 'in progress', 'billed', 'paid'];
$addressedToT = ['submitted', 'on-behalf submitted', 'other IC dept', 'rejected', 'in progress', 'billed', 'paid'];

dataset('visibility matrix', [
    'IC pemohon A' => ['IC pemohon A', $ownDepartment],
    'IC viewer A' => ['IC viewer A', $ownDepartment],
    'IC A holding view-all' => ['IC A holding view-all', $ownDepartment],
    'IC pemohon B' => ['IC pemohon B', ['other IC dept']],
    'koordinator K (entered them)' => ['koordinator K (entered them)', ['on-behalf draft', 'on-behalf submitted']],
    'another koordinator in K' => ['another koordinator in K', []],
    'pelaksana T' => ['pelaksana T', $addressedToT],
    'viewer T' => ['viewer T', $addressedToT],
    'pelaksana U' => ['pelaksana U', ['other target']],
    'keuangan' => ['keuangan', MATRIX_SUBMITTED],
    'admin' => ['admin', MATRIX_SUBMITTED],
]);

it('agrees in the policy and the list query', function (string $userKey, array $visible) {
    ['user' => $user, 'workOrders' => $workOrders] = visibilityWorld($userKey);

    $byPolicy = array_values(array_keys(array_filter($workOrders, fn (WorkOrder $workOrder): bool => $user->can('view', $workOrder))));

    expect($byPolicy)->toBe(inMatrixOrder($visible))
        ->and(matrixKeys($workOrders, WorkOrder::visibleTo($user)->pluck('id')))->toBe(inMatrixOrder($visible));
})->with('visibility matrix');

it('lists exactly the visible work orders', function (string $userKey, array $visible) {
    ['user' => $user, 'workOrders' => $workOrders] = visibilityWorld($userKey);

    $this->actingAs($user)->get(route('work-orders.index'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
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

it('counts and lists only visible work orders on the dashboard', function (string $userKey, array $visible) {
    ['user' => $user, 'workOrders' => $workOrders] = visibilityWorld($userKey);
    $pending = array_intersect($visible, MATRIX_PENDING);
    $overdue = array_intersect($visible, MATRIX_OVERDUE);

    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->loadDeferredProps(fn (Assert $reload): Assert => $reload
            ->where('workOrderCounts', ['total' => count($visible), 'pending' => count($pending), 'overdue' => count($overdue)])
            // "WO Terbaru" lists at most 8 of them.
            ->where('recentWorkOrders', fn ($rows): bool => count($rows) === min(8, count($visible))
                && array_diff(matrixKeys($workOrders, collect($rows)->pluck('id')), $visible) === [])));
})->with('visibility matrix');

it('exports only visible work orders', function (string $userKey, array $visible) {
    ['user' => $user] = visibilityWorld($userKey);
    $user->givePermissionTo(Permission::WorkOrdersExport->value);

    $response = $this->actingAs($user)->get(route('work-orders.export'));

    $response->assertOk();
    expect(inMatrixOrder(matrixExportTitles($response)))->toBe(inMatrixOrder($visible));
})->with('visibility matrix');
