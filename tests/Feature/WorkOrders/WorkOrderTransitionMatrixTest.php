<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;

/*
| FLOW.md §5 as a matrix: every kind of user posts every status change on a
| work order in every status. The work orders are entered by koordinator K
| on behalf of pemohon A (IC department A) and addressed to Unggul
| department T, so both the IC requester and the koordinator are on the
| requester side.
|
| Outcome per request:
|   done     the status changed
|   invalid  a validation error: the current status does not allow it
|   403      visible, but the user is not on the side that makes this change
|   404      the user cannot see the work order
*/

/** Who makes each allowed status change (FLOW.md §5). */
const FLOW_TRANSITIONS = [
    'draft' => ['diajukan' => 'requester', 'dibatalkan' => 'requester'],
    'diajukan' => ['dikerjakan' => 'executor', 'ditolak' => 'executor', 'dibatalkan' => 'requester'],
    'ditolak' => ['diajukan' => 'requester', 'dibatalkan' => 'requester'],
    'dikerjakan' => [],
    'dibatalkan' => [],
];

const FLOW_DESTINATIONS = ['draft', 'diajukan', 'dikerjakan', 'ditolak', 'dibatalkan'];

const FLOW_SUBMITTED = ['diajukan', 'ditolak', 'dikerjakan', 'dibatalkan'];

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
    $department = fn (Company $company, string $code): Department => Department::factory()->for($company)->create(['code' => $code]);
    [$a, $b] = [$department($ic, 'ICA'), $department($ic, 'ICB')];
    [$k, $t, $u, $keu, $it] = [$department($unggul, 'KOR'), $department($unggul, 'TGT'), $department($unggul, 'OTH'), $department($unggul, 'KEU'), $department($unggul, 'IT')];

    $user = fn (Department $in, string $role): User => User::factory()->for($in)->create()->assignRole($role);
    $pemohonA = $user($a, 'pemohon');
    $koordinator = $user($k, 'koordinator');

    $users = [
        'IC pemohon A' => fn (): User => $pemohonA,
        'IC viewer A' => fn (): User => $user($a, 'viewer'),
        'IC pemohon B' => fn (): User => $user($b, 'pemohon'),
        'koordinator K (entered it)' => fn (): User => $koordinator,
        'another koordinator in K' => fn (): User => $user($k, 'koordinator'),
        'pelaksana T' => fn (): User => $user($t, 'pelaksana'),
        'koordinator in T' => fn (): User => $user($t, 'koordinator'),
        'viewer T' => fn (): User => $user($t, 'viewer'),
        'admin in T' => fn (): User => $user($t, 'admin'),
        'pelaksana U' => fn (): User => $user($u, 'pelaksana'),
        'keuangan' => fn (): User => $user($keu, 'keuangan'),
        'admin in IT' => fn (): User => $user($it, 'admin'),
    ];

    $workOrder = function (string $status) use ($koordinator, $pemohonA, $t): WorkOrder {
        $factory = WorkOrder::factory()->onBehalf($koordinator, $pemohonA)->targeting($t);

        return match ($status) {
            'draft' => $factory->create(),
            'diajukan' => $factory->submitted()->create(),
            'ditolak' => $factory->rejected()->create(),
            'dikerjakan' => $factory->inProgress()->create(),
            'dibatalkan' => $factory->submitted()->cancelled()->create(),
        };
    };

    return ['user' => $users[$userKey](), 'workOrder' => $workOrder];
}

dataset('transition matrix users', [
    // user, side, statuses in which the user sees the work order
    'IC pemohon A' => ['IC pemohon A', 'requester', array_keys(FLOW_TRANSITIONS)],
    'IC viewer A' => ['IC viewer A', null, array_keys(FLOW_TRANSITIONS)],
    'IC pemohon B' => ['IC pemohon B', null, []],
    'koordinator K (entered it)' => ['koordinator K (entered it)', 'requester', array_keys(FLOW_TRANSITIONS)],
    'another koordinator in K' => ['another koordinator in K', null, []],
    'pelaksana T' => ['pelaksana T', 'executor', FLOW_SUBMITTED],
    'koordinator in T (no work-orders.process)' => ['koordinator in T', null, FLOW_SUBMITTED],
    'viewer T' => ['viewer T', null, FLOW_SUBMITTED],
    'admin in T' => ['admin in T', 'executor', FLOW_SUBMITTED],
    'pelaksana U' => ['pelaksana U', null, []],
    'keuangan' => ['keuangan', null, FLOW_SUBMITTED],
    'admin in IT' => ['admin in IT', null, FLOW_SUBMITTED],
]);

it('allows each status change only to its side, from the statuses FLOW.md allows', function (string $userKey, ?string $side, array $visible) {
    ['user' => $user, 'workOrder' => $makeWorkOrder] = transitionWorld($userKey);

    foreach (FLOW_TRANSITIONS as $from => $allowed) {
        foreach (FLOW_DESTINATIONS as $to) {
            $workOrder = $makeWorkOrder($from);
            $number = $workOrder->number;

            $expected = match (true) {
                ! in_array($from, $visible, true) => '404',
                ! isset($allowed[$to]) => $side !== null ? 'invalid' : '403',
                $allowed[$to] === $side => 'done',
                default => '403',
            };

            $this->flushSession();
            $response = $this->actingAs($user)
                ->from(route('work-orders.index'))
                ->post(route('work-orders.transitions.store', $workOrder), ['status' => $to, 'note' => 'Alasan.']);

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
