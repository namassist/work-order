<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\RegistrationNotReviewable;
use App\Actions\Registrations\RejectRegistration;
use App\Enums\AccountStatus;
use App\Enums\CompanyScope;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveRegistrationRequest;
use App\Http\Requests\Admin\RejectRegistrationRequest;
use App\Models\Department;
use App\Models\User;
use App\Support\RoleLabel;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * The Pendaftaran page (FLOW.md §3): self-registered accounts by review
 * state, approved with roles or rejected with a reason.
 */
class RegistrationController extends Controller
{
    /**
     * List registrations of one review state (pending by default).
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewRegistrations', User::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(AccountStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $status = AccountStatus::tryFrom($filters['status'] ?? '') ?? AccountStatus::Pending;

        $registrations = User::query()
            ->registrations()
            ->with(['department:id,company_id,code,name,deleted_at', 'department.company:id,name,is_client,deleted_at', 'reviewer:id,name', 'roles:id,name,label'])
            ->where('account_status', $status->value)
            ->search($filters['search'] ?? null)
            // Oldest waiting first; reviewed ones latest review first.
            ->when($status === AccountStatus::Pending, fn ($query) => $query->orderBy('registered_at'), fn ($query) => $query->orderByDesc('reviewed_at'))
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'account_status' => $user->account_status->value,
                'is_active' => $user->is_active,
                'department' => $user->department->only(['id', 'code', 'name']),
                'company' => [
                    'id' => $user->department->company_id,
                    'name' => $user->department->company->name,
                    'scope' => CompanyScope::of($user->department->company)->value,
                ],
                'roles' => $user->roles->sortBy('name')->map(RoleLabel::option(...))->values()->all(),
                'registered_at' => $user->registered_at?->toIso8601String(),
                'reviewed_at' => $user->reviewed_at?->toIso8601String(),
                'reviewer' => $user->reviewer?->name,
                'rejection_reason' => $user->rejection_reason,
            ]);

        $authUser = $request->user();

        return Inertia::render('admin/registrations/Index', [
            'registrations' => $registrations,
            'filters' => [
                'status' => $status->value,
                'search' => $filters['search'] ?? '',
            ],
            'counts' => $this->counts(),
            'departments' => $this->departments(),
            'roles' => $this->grantableRoles(),
            'can' => [
                'approve' => $authUser?->checkPermissionTo(Permission::RegistrationsApprove->value) ?? false,
                'reject' => $authUser?->checkPermissionTo(Permission::RegistrationsReject->value) ?? false,
            ],
        ]);
    }

    /**
     * Approve the registration with the chosen roles and department.
     */
    public function approve(ApproveRegistrationRequest $request, User $user, ApproveRegistration $approve): RedirectResponse
    {
        /** @var list<string> $roles */
        $roles = $request->validated('roles');

        return $this->attempt(
            fn (): User => $approve->handle($user, $request->user(), $roles, $request->integer('department_id')),
            __('Pendaftaran :name disetujui.', ['name' => $user->name]),
        );
    }

    /**
     * Reject the registration with a reason.
     */
    public function reject(RejectRegistrationRequest $request, User $user, RejectRegistration $reject): RedirectResponse
    {
        return $this->attempt(
            fn (): User => $reject->handle($user, $request->user(), (string) $request->validated('reason')),
            __('Pendaftaran :name ditolak.', ['name' => $user->name]),
        );
    }

    /**
     * Run the review and flash its outcome; one another admin made first
     * comes back as an error toast.
     */
    private function attempt(Closure $review, string $success): RedirectResponse
    {
        try {
            $review();
        } catch (RegistrationNotReviewable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $success]);

        return back();
    }

    /**
     * Registrations per review state, for the tabs.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = User::query()
            ->registrations()
            ->toBase()
            ->selectRaw('account_status, count(*) as total')
            ->groupBy('account_status')
            ->pluck('total', 'account_status');

        return collect(AccountStatus::cases())
            ->mapWithKeys(fn (AccountStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }

    /**
     * Active departments with their company, so the approval dialog can
     * offer those of the registration's company only.
     *
     * @return list<array{id: int, code: string, name: string, company_id: int}>
     */
    private function departments(): array
    {
        return array_values(Department::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'company_id', 'code', 'name'])
            ->map(fn (Department $department): array => $department->only(['id', 'code', 'name', 'company_id']))
            ->all());
    }

    /**
     * Roles an approval may grant: every role except those holding role
     * management (ApproveRegistrationRequest), with the company scope each
     * one fits (null: any company) and its label.
     *
     * @return list<array{name: string, label: string, company_scope: string|null}>
     */
    private function grantableRoles(): array
    {
        return array_values(Role::query()
            ->where('guard_name', 'web')
            ->whereDoesntHave('permissions', fn ($query) => $query->where('name', Permission::RolesManage->value))
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                ...RoleLabel::option($role),
                'company_scope' => CompanyScope::tryFrom((string) $role->getAttribute('company_scope'))?->value,
            ])
            ->all());
    }
}
