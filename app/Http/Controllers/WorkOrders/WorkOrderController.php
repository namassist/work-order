<?php

namespace App\Http\Controllers\WorkOrders;

use App\Actions\Attachments\AddAttachment;
use App\Actions\WorkOrders\CreateWorkOrder;
use App\Enums\WorkOrderUrgency;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkOrders\ListWorkOrdersRequest;
use App\Http\Requests\WorkOrders\StoreWorkOrderRequest;
use App\Http\Requests\WorkOrders\UpdateWorkOrderRequest;
use App\Http\Resources\WorkOrderResource;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderComment;
use App\States\WorkOrder\WorkOrderStatus;
use App\Support\Attachments\AttachmentPanel;
use App\Support\WorkOrderTimeline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    /**
     * List the work orders the user may see, with search, filters, and pagination.
     */
    public function index(ListWorkOrdersRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $workOrders = $request->workOrders()
            ->with(['department', 'category', 'requester'])
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('work-orders/Index', [
            'workOrders' => $workOrders->through(fn (WorkOrder $workOrder): array => [
                ...new WorkOrderResource($workOrder)->resolve($request),
                'can' => [
                    'update' => $user->can('update', $workOrder),
                    'delete' => $user->can('delete', $workOrder) && $workOrder->status->isEditable(),
                    'restore' => $user->can('restore', $workOrder),
                ],
            ]),
            'filters' => $request->filters(),
            'statuses' => WorkOrderStatus::options(),
            'urgencies' => WorkOrderUrgency::options(),
            'stats' => $this->statusCounts($user),
            // Only users who see other departments can filter by department.
            'departments' => $user->can('viewAllDepartments', WorkOrder::class)
                ? Department::orderBy('code')->get(['id', 'code', 'name'])
                : null,
            'categories' => WorkOrderCategory::orderBy('code')->get(['id', 'code', 'name']),
            'can' => [
                'create' => $user->can('create', WorkOrder::class),
                'restore' => $user->can('viewTrashed', WorkOrder::class),
                'export' => $user->can('export', WorkOrder::class),
            ],
            'exportMaxRows' => config()->integer('work_order.export.max_rows'),
        ]);
    }

    /**
     * Show the form for creating a work order.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', WorkOrder::class);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('work-orders/Create', [
            'department' => $user->department?->only(['id', 'code', 'name']),
            'categories' => $this->selectableCategories(),
            'urgencies' => WorkOrderUrgency::options(),
            'attachmentRules' => (new WorkOrder)->documentsCollection()->toFrontend(),
        ]);
    }

    /**
     * Store a new draft work order in the requester's department, with the
     * documents attached on the form.
     */
    public function store(StoreWorkOrderRequest $request, CreateWorkOrder $createWorkOrder, AddAttachment $addAttachment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workOrder = DB::transaction(function () use ($request, $user, $createWorkOrder, $addAttachment): WorkOrder {
            $workOrder = $createWorkOrder->handle($request->safe()->except('attachments'), $user);

            $documents = $workOrder->documentsCollection();

            /** @var list<UploadedFile> $files */
            $files = $request->file('attachments', []);

            foreach ($files as $file) {
                $addAttachment->handle($workOrder, $documents, $file, $user, 'attachments');
            }

            return $workOrder;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Work order disimpan sebagai draft.')]);

        return to_route('work-orders.show', $workOrder);
    }

    /**
     * Show the work order with its timeline of status changes and comments.
     */
    public function show(Request $request, WorkOrder $workOrder): Response
    {
        Gate::authorize('view', $workOrder);

        /** @var User $user */
        $user = $request->user();

        $workOrder->load(['department', 'category', 'requester']);

        $canTransition = $user->can('transition', $workOrder);

        return Inertia::render('work-orders/Show', [
            'workOrder' => new WorkOrderResource($workOrder)->resolve($request),
            'timeline' => WorkOrderTimeline::for($workOrder, $user),
            'transitions' => $canTransition
                ? array_map($this->transitionOption(...), $workOrder->status->transitionableStates())
                : [],
            'can' => [
                'update' => $user->can('update', $workOrder),
                'delete' => $user->can('delete', $workOrder) && $workOrder->status->isEditable(),
                'comment' => $user->can('addComment', $workOrder) && $workOrder->status->acceptsComments(),
            ],
            'comments' => [
                'max_length' => WorkOrderComment::MAX_BODY_LENGTH,
                'read_only' => ! $workOrder->status->acceptsComments(),
            ],
            'attachments' => AttachmentPanel::props($workOrder, WorkOrder::DOCUMENTS, $user, $request),
        ]);
    }

    /**
     * Show the form for editing a draft work order.
     */
    public function edit(Request $request, WorkOrder $workOrder): Response
    {
        Gate::authorize('update', $workOrder);

        /** @var User $user */
        $user = $request->user();

        $workOrder->load(['department', 'category', 'requester']);

        return Inertia::render('work-orders/Edit', [
            'workOrder' => new WorkOrderResource($workOrder)->resolve($request),
            'categories' => $this->selectableCategories($workOrder),
            'urgencies' => WorkOrderUrgency::options(),
            'attachments' => AttachmentPanel::props($workOrder, WorkOrder::DOCUMENTS, $user, $request),
        ]);
    }

    /**
     * Update the draft work order.
     */
    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder): RedirectResponse
    {
        $workOrder->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Work order diperbarui.')]);

        return to_route('work-orders.show', $workOrder);
    }

    /**
     * Soft-delete the work order, unless it has left the draft status.
     */
    public function destroy(WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('delete', $workOrder);

        if (! $workOrder->status->isEditable()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Work order :number sudah :status dan tidak dapat dihapus. Batalkan bila tidak diperlukan.', [
                'number' => $workOrder->displayNumber(),
                'status' => mb_strtolower($workOrder->status->label()),
            ])]);

            return back();
        }

        $workOrder->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft work order dihapus.')]);

        return to_route('work-orders.index');
    }

    /**
     * Restore a soft-deleted work order.
     */
    public function restore(WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('restore', $workOrder);

        $workOrder->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Work order :number dipulihkan.', ['number' => $workOrder->displayNumber()])]);

        return back();
    }

    /**
     * Active categories, plus the work order's current one even if it was
     * deactivated or deleted.
     *
     * @return Collection<int, WorkOrderCategory>
     */
    private function selectableCategories(?WorkOrder $workOrder = null): Collection
    {
        return WorkOrderCategory::withTrashed()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('is_active', true)->whereNull('deleted_at'))
                ->when($workOrder?->work_order_category_id, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * Work orders the user may see, per status, for the list's statistics
     * strip. Ignores the list filters and deleted work orders; every status
     * is present, with 0 when it has none.
     *
     * @return array{total: int, statuses: array<string, int>}
     */
    private function statusCounts(User $user): array
    {
        $counts = WorkOrder::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statuses = [];

        foreach (array_column(WorkOrderStatus::options(), 'value') as $status) {
            $statuses[$status] = (int) ($counts[$status] ?? 0);
        }

        return ['total' => array_sum($statuses), 'statuses' => $statuses];
    }

    /**
     * @return array{value: string, label: string, tone: string, requires_note: bool}
     */
    private function transitionOption(string $name): array
    {
        $state = WorkOrderStatus::fromName($name);

        return [
            'value' => $name,
            'label' => $state?->actionLabel() ?? $name,
            'tone' => $state?->tone() ?? 'secondary',
            'requires_note' => $state?->requiresNote() ?? false,
        ];
    }
}
