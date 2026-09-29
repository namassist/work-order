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
use Inertia\Support\SessionKey;

/*
| FLOW.md §5.1 and §5.2 as a matrix: every role posts every status change on
| a work order in every status, and every payment track action (§10) on a
| closed work order in every payment status. The work orders are entered by
| Admin WO X for a contact of IC department A. Roles have the seeded grants
| (RolePermissionSeeder::INITIAL_ROLES).
|
| Outcome per request:
|   done     the change was made
|   invalid  refused with a message: the current status does not allow the
|            change (for a user who makes some status change at all), or the
|            payment track is not in the right state (a toast)
|   403      visible, but the user lacks the permission for this change
|   404      the user cannot see the work order
*/

/** The permission that makes each allowed status change (FLOW.md §5.1, §5.2). */
const FLOW_TRANSITIONS = [
    'draft' => ['diajukan' => 'work-orders.submit', 'dibatalkan' => 'work-orders.cancel'],
    'diajukan' => ['pelaksanaan' => 'work-orders.approve', 'ditolak' => 'work-orders.approve', 'dibatalkan' => 'work-orders.cancel'],
    'ditolak' => ['diajukan' => 'work-orders.submit', 'dibatalkan' => 'work-orders.cancel'],
    'pelaksanaan' => ['review_dokumen' => 'work-orders.submit-review', 'dibatalkan' => 'work-orders.cancel-execution'],
    'review_dokumen' => ['pelaksanaan' => 'work-orders.review', 'approval_bast' => 'work-orders.review', 'dibatalkan' => 'work-orders.cancel-execution'],
    'approval_bast' => ['bast_disetujui' => 'work-orders.approve-bast'],
    'bast_disetujui' => ['closed' => 'work-orders.close'],
    'closed' => [],
    'dibatalkan' => [],
];

const FLOW_DESTINATIONS = ['draft', 'diajukan', 'ditolak', 'pelaksanaan', 'review_dokumen', 'approval_bast', 'bast_disetujui', 'closed', 'dibatalkan'];

const FLOW_SUBMITTED = ['diajukan', 'ditolak', 'pelaksanaan', 'review_dokumen', 'approval_bast', 'bast_disetujui', 'closed', 'dibatalkan'];

/** Every permission that makes a status change. */
const FLOW_TRANSITION_PERMISSIONS = [
    'work-orders.submit', 'work-orders.approve', 'work-orders.submit-review', 'work-orders.review',
    'work-orders.approve-bast', 'work-orders.close', 'work-orders.cancel', 'work-orders.cancel-execution',
];

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
            'pelaksanaan' => $factory->inProgress()->create(),
            'review_dokumen' => $factory->inReview()->create(),
            'approval_bast' => $factory->awaitingBastApproval()->create(),
            'bast_disetujui' => $factory->bastApproved()->create(),
            'closed', 'belum_ditagih' => $factory->closed()->create(),
            'ditagih' => $factory->billed()->create(),
            'lunas' => $factory->paid()->create(),
            'dibatalkan' => $factory->submitted()->cancelled()->create(),
        };
    };

    return ['user' => $users[$userKey](), 'workOrder' => $workOrder];
}

$everyStatus = array_keys(FLOW_TRANSITIONS);

dataset('transition matrix users', [
    // user, the work order permissions it acts with, statuses in which it sees the work order
    'admin' => ['admin', [...FLOW_TRANSITION_PERMISSIONS, 'work-orders.bill', 'work-orders.confirm-payment'], $everyStatus],
    'Admin WO (entered it)' => ['Admin WO (entered it)', ['work-orders.submit', 'work-orders.close', 'work-orders.cancel'], $everyStatus],
    'another Admin WO' => ['another Admin WO', ['work-orders.submit', 'work-orders.close', 'work-orders.cancel'], $everyStatus],
    'Lead Operational' => ['Lead Operational', ['work-orders.approve', 'work-orders.cancel-execution'], FLOW_SUBMITTED],
    'PIC Timesheet' => ['PIC Timesheet', ['work-orders.submit-review'], FLOW_SUBMITTED],
    'Rental' => ['Rental', ['work-orders.review'], FLOW_SUBMITTED],
    'Direktur' => ['Direktur', ['work-orders.approve-bast'], FLOW_SUBMITTED],
    'Finance' => ['Finance', ['work-orders.bill', 'work-orders.confirm-payment'], FLOW_SUBMITTED],
    'Viewer' => ['Viewer', [], FLOW_SUBMITTED],
    'internal without a role' => ['internal without a role', [], []],
    // The v1 safeguard shows it its department's work orders, but it holds no internal-only permission.
    'IC A with every permission' => ['IC A with every permission', [], $everyStatus],
]);

it('allows each status change only with its permission, from the statuses FLOW.md allows', function (string $userKey, array $permissions, array $visible) {
    // Dozens of status changes in one frozen minute; throttling has its own tests.
    $this->withoutMiddleware(ThrottleRequests::class);

    ['user' => $user, 'workOrder' => $makeWorkOrder] = transitionWorld($userKey);
    $changesStatus = array_intersect($permissions, FLOW_TRANSITION_PERMISSIONS) !== [];

    foreach (FLOW_TRANSITIONS as $from => $allowed) {
        foreach (FLOW_DESTINATIONS as $to) {
            $workOrder = $makeWorkOrder($from);
            $number = $workOrder->number;

            $expected = match (true) {
                ! in_array($from, $visible, true) => '404',
                ! isset($allowed[$to]) => $changesStatus ? 'invalid' : '403',
                in_array($allowed[$to], $permissions, true) => 'done',
                default => '403',
            };

            $this->flushSession();
            $response = $this->actingAs($user)
                ->from(route('work-orders.index'))
                ->post(route('work-orders.transitions.store', $workOrder), ['status' => $to, 'note' => 'Alasan.']);

            $outcome = $response->getStatusCode() === 302
                ? (session()->has('errors') ? 'invalid' : 'done')
                : (string) $response->getStatusCode();

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

/** Which payment status each payment track action needs, and the permission that makes it (FLOW.md §10). */
const PAYMENT_ACTIONS = [
    'bill' => ['belum_ditagih', 'work-orders.bill'],
    'correct' => ['ditagih', 'work-orders.bill'],
    'confirm' => ['ditagih', 'work-orders.confirm-payment'],
];

/**
 * Posts a payment track action the way the detail page does, with valid data.
 */
function postPaymentAction(User $user, WorkOrder $workOrder, string $action): TestResponse
{
    $request = test()->actingAs($user)->from(route('work-orders.index'));

    return match ($action) {
        'bill' => $request->post(route('work-orders.invoice.store', $workOrder), [
            'invoice_number' => 'INV-'.Str::random(8),
            'invoice_date' => '2026-09-25',
            'invoice_files' => [attachmentUpload('dokumen.pdf')],
        ]),
        // The factory's invoice has no file, so the correction adds one.
        'correct' => $request->patch(route('work-orders.invoice.update', $workOrder), [
            'invoice_number' => 'INV-'.Str::random(8),
            'invoice_date' => '2026-09-21',
            'invoice_files' => [attachmentUpload('dokumen.pdf')],
        ]),
        'confirm' => $request->post(route('work-orders.payment.store', $workOrder), ['paid_on' => '2026-09-25']),
    };
}

it('allows each payment track action only with its permission, in the payment status it needs', function (string $userKey, array $permissions, array $visible) {
    Storage::fake('attachments');
    $this->withoutMiddleware(ThrottleRequests::class);

    ['user' => $user, 'workOrder' => $makeWorkOrder] = transitionWorld($userKey);

    foreach (PAYMENT_ACTIONS as $action => [$needs, $permission]) {
        // Not closed yet (BAST Disetujui) and every payment status.
        foreach (['bast_disetujui', 'belum_ditagih', 'ditagih', 'lunas'] as $state) {
            $workOrder = $makeWorkOrder($state);
            $before = [$workOrder->invoice?->number, $workOrder->invoice?->paid_on?->toDateString()];

            $expected = match (true) {
                ! in_array($state === 'bast_disetujui' ? 'bast_disetujui' : 'closed', $visible, true) => '404',
                ! in_array($permission, $permissions, true) => '403',
                $state === $needs => 'done',
                default => 'invalid',
            };

            $this->flushSession();
            $response = postPaymentAction($user, $workOrder, $action);

            $outcome = match (true) {
                $response->getStatusCode() !== 302 => (string) $response->getStatusCode(),
                session()->has('errors') => 'invalid',
                data_get(session(SessionKey::FLASH_DATA), 'toast.type') === 'error' => 'invalid',
                default => 'done',
            };

            expect($outcome)->toBe($expected, "{$userKey}: {$action} while {$state}");
            $workOrder->refresh()->load('invoice');

            if ($outcome !== 'done') {
                expect([$workOrder->invoice?->number, $workOrder->invoice?->paid_on?->toDateString()])->toBe($before, "{$userKey}: {$action} while {$state} changed the invoice");
            }
        }
    }
})->with('transition matrix users');
