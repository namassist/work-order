<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationStatusController extends Controller
{
    /**
     * The only page a pending or rejected account reaches: its review state
     * and, when rejected, the reason. Approved accounts go to the dashboard.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        if ($user->isApproved()) {
            return to_route('dashboard');
        }

        $user->loadMissing('department.company');

        return Inertia::render('auth/RegistrationStatus', [
            'registration' => [
                'status' => $user->account_status->value,
                'name' => $user->name,
                'email' => $user->email,
                'company' => $user->department->company->name,
                'department' => $user->department->name,
                'registered_at' => $user->registered_at?->toIso8601String(),
                'rejection_reason' => $user->account_status === AccountStatus::Rejected ? $user->rejection_reason : null,
            ],
        ]);
    }
}
