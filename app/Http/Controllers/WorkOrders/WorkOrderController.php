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
     * What WorkOrderResource shows.
     */
    public const array LIST_RELATIONS = ['requesterDepartment', 'targetDepartment', 'category', 'requester', 'enteredBy'];

    /**
     * The fillable fields the create and edit forms send.
     */
    private const array FORM_FIELDS = ['title', 'description', 'work_order_category_id', 'target_department_id', 'urgency', 'target_date'];

    /**
     * List the work orders the user may see, with search, filters, and pagination.
     */
    public function index(ListWorkOrdersRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $workOrders = $request->workOrders()
            ->with(self::LIST_RELATIONS)
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('work-orders/Index', [
            'workOrders' => $workOrders->through(fn (WorkOrder $workOrder): array => [
                ...new WorkOrderResource($workOrder)->resolve($request),
                'can' => [
                    'update' => $user->can('update', $workOrder),
                    'delete' => $user->can('delete', $workOrder) && $workOrder->status->isDeletable(),
                    'restore' => $user->can('restore', $workOrder),
                ],
            ]),
            'filters' => $request->filters(),
            'statuses' => WorkOrderStatus::options(),
            'urgencies' => WorkOrderUrgency::options(),
            'stats' => $this->statusCounts($user),
            // Only users who see other departments can filter by (requesting, so client company) department.
            'departments' => $user->can('viewAllDepartments', WorkOrder::class)
                ? Department::query()
                    ->whereRelation('company', 'is_client', true)
                    ->orderBy('code')
                    ->get(['id', 'code', 'name'])
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

        $onBehalf = $user->can('createOnBehalf', WorkOrder::class);

        return Inertia::render('work-orders/Create', [
            // An IC user enters it for their own department; a koordinator picks one.
            'department' => $onBehalf ? null : $user->department->only(['id', 'code', 'name']),
            'requesterDepartments' => $onBehalf ? $this->selectableRequesterDepartments() : null,
            'targetDepartments' => $this->selectableTargetDepartments(),
            'categories' => $this->selectableCategories(),
            'urgencies' => WorkOrderUrgency::options(),
            'attachmentRules' => (new WorkOrder)->documentsCollection()->toFrontend(),
        ]);
    }

    /**
     * Store a new draft work order, with the documents attached on the form:
     * for the IC user's own department, or on behalf of the IC department and
     * requester a koordinator picked.
     */
    public function store(StoreWorkOrderRequest $request, CreateWorkOrder $createWorkOrder, AddAttachment $addAttachment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workOrder = DB::transaction(function () use ($request, $user, $createWorkOrder, $addAttachment): WorkOrder {
            $workOrder = $request->isOnBehalf()
                ? $createWorkOrder->handle(
                    $request->safe()->only(self::FORM_FIELDS),
                    $user,
                    Department::findOrFail($request->integer('requester_department_id')),
                    // The same accounts the validation and the picker allow.
                    $request->filled('requester_id')
                        ? User::query()->activeRequesterIn($request->integer('requester_department_id'))->findOrFail($request->integer('requester_id'))
                        : null,
                    $request->validated('requester_name'),
                )
                : $createWorkOrder->handle($request->safe()->only(self::FORM_FIELDS), $user);

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

        $workOrder->load(self::LIST_RELATIONS);

        $status = $workOrder->status;
        $waitsOn = $status->waitsOn();

        return Inertia::render('work-orders/Show', [
            'workOrder' => new WorkOrderResource($workOrder)->resolve($request),
            'timeline' => WorkOrderTimeline::for($workOrder, $user),
            // Only the transitions this user may perform (FLOW.md §5).
            'transitions' => array_values(array_map(
                fn (string $to): array => $this->transitionOption($workOrder, $to),
                array_filter($status->transitionableStates(), fn (string $to): bool => $user->can('transition', [$workOrder, $to])),
            )),
            // Tells everyone not on the side whose turn it is who the work order waits for.
            'waitingFor' => $waitsOn !== null && ! $workOrder->isOnSide($user, $waitsOn) ? $status->waitingMessage($workOrder) : null,
            'statusNote' => $this->statusNote($workOrder),
            'can' => [
                'update' => $user->can('update', $workOrder),
                'delete' => $user->can('delete', $workOrder) && $workOrder->status->isDeletable(),
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

        $workOrder->load(self::LIST_RELATIONS);

        return Inertia::render('work-orders/Edit', [
            'workOrder' => new WorkOrderResource($workOrder)->resolve($request),
            'targetDepartments' => $this->selectableTargetDepartments($workOrder),
            // Only for the koordinator who entered this draft on someone's behalf.
            'requesterCorrection' => $user->can('updateRequester', $workOrder) ? [
                'department_id' => $workOrder->requester_department_id,
                'account' => $workOrder->requester?->only(['id', 'name', 'email']),
                'contact_name' => $workOrder->requester_name,
            ] : null,
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
        $workOrder->fill($request->safe()->only(self::FORM_FIELDS));

        if ($request->correctsRequester()) {
            $byAccount = $request->validated('requester_mode') === UpdateWorkOrderRequest::REQUESTER_ACCOUNT;
            $workOrder->requester_id = $byAccount ? $request->integer('requester_id') : null;
            $workOrder->requester_name = $byAccount ? null : $request->validated('requester_name');
        }

        $workOrder->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Work order diperbarui.')]);

        return to_route('work-orders.show', $workOrder);
    }

    /**
     * Soft-delete the work order, unless it has left the draft status.
     */
    public function destroy(WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('delete', $workOrder);

        if (! $workOrder->status->isDeletable()) {
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
     * Departments a koordinator can enter work orders for: active ones of a
     * client company (FLOW.md §2).
     *
     * @return Collection<int, Department>
     */
    private function selectableRequesterDepartments(): Collection
    {
        return Department::query()
            ->whereRelation('company', 'is_client', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * Departments a work order can be addressed to (FLOW.md §2): active ones
     * of the executor company, plus the work order's current target even if
     * it was deactivated or deleted. Only id, code, and name, since client
     * company users see this list too.
     *
     * @return Collection<int, Department>
     */
    private function selectableTargetDepartments(?WorkOrder $workOrder = null): Collection
    {
        return Department::withTrashed()
            ->whereRelation('company', 'is_client', false)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('is_active', true)->whereNull('deleted_at'))
                ->when($workOrder?->target_department_id, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
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
     * @return array{value: string, label: string, destructive: bool, requires_note: bool, note_label: string, requires_target_department: bool}
     */
    private function transitionOption(WorkOrder $workOrder, string $name): array
    {
        $state = WorkOrderStatus::fromName($name);

        return [
            'value' => $name,
            'label' => $state?->actionLabelFor($workOrder) ?? $name,
            'destructive' => $state?->isDestructiveAction() ?? false,
            'requires_note' => $state?->requiresNote() ?? false,
            'note_label' => $state?->noteLabel() ?? 'Catatan',
            'requires_target_department' => $state?->requiresTargetDepartment() ?? false,
        ];
    }

    /**
     * The note given when the work order entered its current status, when
     * that status requires one (the reason it was rejected or cancelled).
     *
     * @return array{label: string, note: string, user: string, created_at: string}|null
     */
    private function statusNote(WorkOrder $workOrder): ?array
    {
        if (! $workOrder->status->requiresNote()) {
            return null;
        }

        $entry = $workOrder->statusHistories()
            ->reorder()
            ->latest('created_at')
            ->latest('id')
            ->where('to_status', $workOrder->status->getValue())
            ->with('user')
            ->first();

        if ($entry === null || blank($entry->note)) {
            return null;
        }

        return [
            'label' => $workOrder->status->noteLabel(),
            'note' => $entry->note,
            'user' => $entry->user->name,
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }
}
