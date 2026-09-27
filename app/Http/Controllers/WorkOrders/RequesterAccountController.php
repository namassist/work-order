<?php

namespace App\Http\Controllers\WorkOrders;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The requester account picker of on-behalf work orders: active accounts of
 * one active IC department, for a koordinator. Internal only (IC users get
 * 404, see routes/web.php); when registration lands, pending accounts must
 * never appear here.
 */
class RequesterAccountController extends Controller
{
    /**
     * The most accounts one search returns; typing narrows the rest.
     */
    private const int LIMIT = 20;

    /**
     * Search the department's active accounts by name or email.
     */
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('createOnBehalf', WorkOrder::class);

        $validated = $request->validate([
            'department' => ['required', 'integer', Rule::exists(Department::class, 'id')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where(fn ($query) => $query->whereIn('company_id', fn ($companies) => $companies->select('id')->from('companies')->where('is_client', true)))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $accounts = User::query()
            ->activeRequesterIn((int) $validated['department'])
            ->search($validated['search'] ?? null)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'email']);

        return response()->json(['data' => $accounts->map(fn (User $user): array => $user->only(['id', 'name', 'email']))->all()]);
    }
}
