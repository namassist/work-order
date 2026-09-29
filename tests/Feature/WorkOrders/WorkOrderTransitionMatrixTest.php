<?php

use App\Enums\Permission;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
| FLOW.md §5 as a matrix: every role posts every status change on a work
| order in every status. Penagihan and Selesai are posted to their own forms
| (invoice, payment) with valid data; every other status to the plain status
| change. The work orders are entered by Admin WO X for a contact of IC
| department A.
|
| PROVISIONAL mapping of the v1 statuses to the v2 roles until step 3: the
| requester side is Admin WO (any of them, work-orders.update), the executor
| side Lead Operational (work-orders.process), the finance side Finance
| (work-orders.confirm-payment); departments no longer matter, and client
| company users are on no side.
|
| Outcome per request:
|   done     the status changed
|   invalid  a validation error: the current status does not allow it (for a
|            user on any side of the work order)
|   403      visible, but the user is not on the side that makes this change
|   404      the user cannot see the work order
*/

/** Who makes each allowed status change (FLOW.md §5). */
const FLOW_TRANSITIONS = [
    'draft' => ['diajukan' => 'requester', 'dibatalkan' => 'requester'],
    'diajukan' => ['dikerjakan' => 'executor', 'ditolak' => 'executor', 'dibatalkan' => 'requester'],
    'ditolak' => ['diajukan' => 'requester', 'dibatalkan' => 'requester'],
    'dikerjakan' => ['penagihan' => 'executor'],
    'penagihan' => ['selesai' => 'finance'],
    'selesai' => [],
    'dibatalkan' => [],
];

const FLOW_DESTINATIONS = ['draft', 'diajukan', 'dikerjakan', 'ditolak', 'penagihan', 'selesai', 'dibatalkan'];

const FLOW_SUBMITTED = ['diajukan', 'ditolak', 'dikerjakan', 'penagihan', 'selesai', 'dibatalkan'];

/**
 * Builds the departments and users of the matrix, and returns the user under
 * test plus a factory for work orders in a given status.
 *
 * @return array{user: User, workOrder: Closure(string): WorkOrder}
 */
function transitionWorld(string $userKey): array
{
    test()->seed(RolePermissionSeeder::class);
    Carbon::setTestNow('2026-09-25 02:00:00');

    $ic = Company::factory()->client()->create(['code' => 'IC']);
    $unggul = Company::factory()->create(['code' => 'UGL']);
    $a = Department::factory()->for($ic)->create(['code' => 'ICA']);
    $ops = Department::factory()->for($unggul)->create(['code' => 'OPS']);

    $user = fn (string $role, Department $in): User => User::factory()->for($in)->create()->assignRole($role);
    $adminWo = $user('admin-wo', $ops);

    $users = [
        'admin' => fn (): User => $user('admin', $ops),
        'Admin WO (entered it)' => fn (): User => $adminWo,
        'another Admin WO' => fn (): User => $user('admin-wo', $ops),
        'Lead Operational' => fn (): User => $user('lead-operational', $ops),
        'PIC Timesheet' => fn (): User => $user('pic-timesheet', $ops),
        'Rental' => fn (): User => $user('rental', $ops),
        'Direktur' => fn (): User => $user('direktur', $ops),
        'Finance' => fn (): User => $user('finance', $ops),
        'Viewer' => fn (): User => $user('viewer', $ops),
        'internal without a role' => fn (): User => User::factory()->for($ops)->create(),
        'IC A with every permission' => fn (): User => User::factory()->for($a)->create()->givePermissionTo(Permission::values()),
    ];

    $workOrder = function (string $status) use ($adminWo, $a): WorkOrder {
        $factory = WorkOrder::factory()->by($adminWo)->requestedBy($a);

        return match ($status) {
            'draft' => $factory->create(),
            'diajukan' => $factory->submitted()->create(),
            'ditolak' => $factory->rejected()->create(),
            'dikerjakan' => $factory->inProgress()->create(),
            'penagihan' => $factory->billed()->create(),
            'selesai' => $factory->paid()->create(),
            'dibatalkan' => $factory->submitted()->cancelled()->create(),
        };
    };

    return ['user' => $users[$userKey](), 'workOrder' => $workOrder];
}

$everyStatus = array_keys(FLOW_TRANSITIONS);

dataset('transition matrix users', [
    // user, sides the user is on, statuses in which the user sees the work order
    'admin' => ['admin', ['requester', 'executor', 'finance'], $everyStatus],
    'Admin WO (entered it)' => ['Admin WO (entered it)', ['requester'], $everyStatus],
    'another Admin WO' => ['another Admin WO', ['requester'], $everyStatus],
    'Lead Operational' => ['Lead Operational', ['executor'], FLOW_SUBMITTED],
    'PIC Timesheet' => ['PIC Timesheet', [], FLOW_SUBMITTED],
    'Rental' => ['Rental', [], FLOW_SUBMITTED],
    'Direktur' => ['Direktur', [], FLOW_SUBMITTED],
    'Finance' => ['Finance', ['finance'], FLOW_SUBMITTED],
    'Viewer' => ['Viewer', [], FLOW_SUBMITTED],
    'internal without a role' => ['internal without a role', [], []],
    // The v1 safeguard shows it its department's work orders, but it acts for no side.
    'IC A with every permission' => ['IC A with every permission', [], $everyStatus],
]);

/**
 * Posts the status change the way the detail page does: Penagihan and
 * Selesai through their own forms, with valid data.
 */
function postStatusChange(User $user, WorkOrder $workOrder, string $to): TestResponse
{
    $request = test()->actingAs($user)->from(route('work-orders.index'));

    return match ($to) {
        'penagihan' => $request->post(route('work-orders.invoice.store', $workOrder), [
            'invoice_number' => 'INV-'.Str::random(8),
            'invoice_date' => '2026-09-25',
            'invoice_files' => [attachmentUpload('dokumen.pdf')],
        ]),
        'selesai' => $request->post(route('work-orders.payment.store', $workOrder), ['paid_on' => '2026-09-25']),
        default => $request->post(route('work-orders.transitions.store', $workOrder), ['status' => $to, 'note' => 'Alasan.']),
    };
}

it('allows each status change only to its side, from the statuses FLOW.md allows', function (string $userKey, array $sides, array $visible) {
    Storage::fake('attachments');
    // Dozens of status changes in one frozen minute; throttling has its own tests.
    $this->withoutMiddleware(ThrottleRequests::class);

    ['user' => $user, 'workOrder' => $makeWorkOrder] = transitionWorld($userKey);

    foreach (FLOW_TRANSITIONS as $from => $allowed) {
        foreach (FLOW_DESTINATIONS as $to) {
            $workOrder = $makeWorkOrder($from);
            $number = $workOrder->number;

            $expected = match (true) {
                ! in_array($from, $visible, true) => '404',
                ! isset($allowed[$to]) => $sides !== [] ? 'invalid' : '403',
                in_array($allowed[$to], $sides, true) => 'done',
                default => '403',
            };

            $this->flushSession();
            $response = postStatusChange($user, $workOrder, $to);

            $outcome = match ($response->getStatusCode()) {
                302 => session()->has('errors') ? 'invalid' : 'done',
                default => (string) $response->getStatusCode(),
            };

            expect($outcome)->toBe($expected, "{$userKey}: {$from} → {$to}");
            $workOrder->refresh();

            if ($outcome === 'done') {
                expect($workOrder->status->getValue())->toBe($to)
                    ->and($workOrder->statusHistories()->where('to_status', $to)->where('user_id', $user->id)->exists())->toBeTrue();
            } else {
                expect($workOrder->status->getValue())->toBe($from, "{$userKey}: {$from} → {$to} changed the status");
            }

            if ($number !== null) {
                expect($workOrder->number)->toBe($number);
            }
        }
    }
})->with('transition matrix users');
