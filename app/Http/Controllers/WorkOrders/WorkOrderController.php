<?php

namespace App\Http\Controllers\WorkOrders;

use App\Actions\Attachments\AddAttachment;
use App\Actions\WorkOrders\CreateWorkOrder;
use App\Actions\WorkOrders\InvoiceNotAllowed;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\WorkOrderUrgency;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkOrders\ListWorkOrdersRequest;
use App\Http\Requests\WorkOrders\StoreWorkOrderRequest;
use App\Http\Requests\WorkOrders\UpdateWorkOrderRequest;
use App\Http\Resources\WorkOrderInvoiceResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderComment;
use App\States\WorkOrder\WorkOrderStatus;
use App\States\WorkOrder\WorkOrderTransition;
use App\Support\Attachments\AttachmentPanel;
use App\Support\Comments\CommentHtml;
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
    public const array LIST_RELATIONS = ['requesterDepartment', 'targetDepartment', 'category', 'enteredBy'];

    /**
     * The fillable fields the create and edit forms send (the requester
     * department only until the first submission, see WorkOrderRequesterRules).
     */
    private const array FORM_FIELDS = ['title', 'description', 'requester_department_id', 'requester_name', 'pic_name', 'work_order_category_id', 'target_department_id', 'urgency', 'target_date'];

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
            'statusGroups' => WorkOrderStatus::groupOptions(),
            'urgencies' => WorkOrderUrgency::options(),
            'paymentStatuses' => PaymentStatus::options(),
            'stats' => $this->statusCounts($user),
            // Requesting (client company) departments; only executor company
            // users see work orders of more than one (the v1 safeguard).
            'departments' => $user->isClient()
                ? null
                : Department::query()
                    ->whereRelation('company', 'is_client', true)
                    ->orderBy('code')
                    ->get(['id', 'code', 'name']),
            'targetDepartments' => $this->filterableTargetDepartments($user),
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
    public function create(): Response
    {
        Gate::authorize('create', WorkOrder::class);

        return Inertia::render('work-orders/Create', [
            'requesterDepartments' => $this->selectableRequesterDepartments(),
            'targetDepartments' => $this->selectableTargetDepartments(),
            'categories' => $this->selectableCategories(),
            'urgencies' => WorkOrderUrgency::options(),
            'attachmentRules' => (new WorkOrder)->documentsCollection()->toFrontend(),
        ]);
    }

    /**
     * Store a new draft work order for the IC department and contact the
     * Admin WO entered, with the documents attached on the form.
     */
    public function store(StoreWorkOrderRequest $request, CreateWorkOrder $createWorkOrder, AddAttachment $addAttachment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workOrder = DB::transaction(function () use ($request, $user, $createWorkOrder, $addAttachment): WorkOrder {
            $workOrder = $createWorkOrder->handle($request->safe()->only(self::FORM_FIELDS), $user);

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

        $workOrder->load([...self::LIST_RELATIONS, 'invoice.issuer', 'invoice.corrector', 'invoice.payer']);

        $status = $workOrder->status;
        $invoice = $workOrder->invoice;
        $paymentStatus = $workOrder->paymentStatus();

        return Inertia::render('work-orders/Show', [
            'workOrder' => new WorkOrderResource($workOrder)->resolve($request),
            'timeline' => WorkOrderTimeline::for($workOrder, $user, $request),
            // Only the transitions this user may perform (FLOW.md §5).
            'transitions' => array_values(array_map(
                $this->transitionOption(...),
                array_filter($status->transitions(), fn (WorkOrderTransition $transition): bool => $user->can('transition', [$workOrder, $transition->toName()])),
            )),
            'waitingFor' => $this->waitingFor($workOrder, $user),
            'statusNote' => $this->statusNote($workOrder),
            'can' => [
                'update' => $user->can('update', $workOrder),
                'delete' => $user->can('delete', $workOrder) && $workOrder->status->isDeletable(),
                'comment' => $user->can('addComment', $workOrder) && $workOrder->status->acceptsComments(),
                'bill' => $paymentStatus === PaymentStatus::BelumDitagih && $user->can('bill', $workOrder),
                'correctInvoice' => $paymentStatus === PaymentStatus::Ditagih && $user->can('bill', $workOrder),
                'confirmPayment' => $paymentStatus === PaymentStatus::Ditagih && $user->can('confirmPayment', $workOrder),
            ],
            // Why confirming the payment is refused, instead of letting the user try (segregation of duties).
            'paymentBlockedReason' => $paymentStatus === PaymentStatus::Ditagih && $invoice?->segregationBlocks($user)
                ? InvoiceNotAllowed::preparedByPayer()->getMessage()
                : null,
            'comments' => [
                'max_length' => CommentHtml::MAX_TEXT_LENGTH,
                'max_images' => config()->integer('work_order.comments.images.max_files'),
                'max_documents' => config()->integer('work_order.comments.documents.max_files'),
                'read_only' => ! $workOrder->status->acceptsComments(),
                // For the editor, which uploads files before the comment is posted.
                'uploads' => [
                    WorkOrderComment::IMAGES => $workOrder->attachmentCollections()[WorkOrder::COMMENT_IMAGE_UPLOADS]->toFrontend(),
                    WorkOrderComment::DOCUMENTS => $workOrder->attachmentCollections()[WorkOrder::COMMENT_FILE_UPLOADS]->toFrontend(),
                ],
            ],
            'attachments' => AttachmentPanel::props($workOrder, WorkOrder::DOCUMENTS, $user, $request),
            'paymentStatus' => $paymentStatus?->toOption(),
            'invoice' => $invoice ? new WorkOrderInvoiceResource($invoice->setRelation('workOrder', $workOrder))->resolve($request) : null,
            'invoiceAttachments' => $this->invoiceAttachments($workOrder, $user, $request),
            // For the invoice and payment forms, which upload with their data.
            'invoiceRules' => collect([WorkOrder::INVOICE, WorkOrder::PAYMENT_PROOF])
                ->mapWithKeys(fn (string $collection): array => [$collection => $workOrder->attachmentCollections()[$collection]->toFrontend()])
                ->all(),
        ]);
    }

    /**
     * The invoice's file panels (FLOW.md §10): the invoice and proof of
     * payment, once the closed work order was billed.
     *
     * @return array<string, array<string, mixed>>
     */
    private function invoiceAttachments(WorkOrder $workOrder, User $user, Request $request): array
    {
        $collections = $workOrder->invoice !== null ? [WorkOrder::INVOICE, WorkOrder::PAYMENT_PROOF] : [];

        return collect($collections)
            ->mapWithKeys(fn (string $collection): array => [$collection => AttachmentPanel::props($workOrder, $collection, $user, $request)])
            ->all();
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
            // The requester department changes only until the first submission.
            'requesterDepartments' => $workOrder->wasSubmitted() ? null : $this->selectableRequesterDepartments($workOrder),
            'targetDepartments' => $this->selectableTargetDepartments($workOrder),
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
        // An empty department passes `prohibited` once submitted; never let it reach the model.
        $fields = $workOrder->wasSubmitted()
            ? array_diff(self::FORM_FIELDS, ['requester_department_id'])
            : self::FORM_FIELDS;

        $workOrder->fill($request->safe()->only($fields))->save();

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
            // Never submitted yet not deletable: a draft that was cancelled.
            $message = $workOrder->wasSubmitted()
                ? __('Work order :number sudah :status dan tidak dapat dihapus. Batalkan bila tidak diperlukan.', [
                    'number' => $workOrder->reference(),
                    'status' => mb_strtolower($workOrder->status->label()),
                ])
                : __('Draft yang sudah dibatalkan tidak dapat dihapus.');

            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Work order :number dipulihkan.', ['number' => $workOrder->reference()])]);

        return back();
    }

    /**
     * Departments an Admin WO can enter work orders for: active ones of a
     * client company (FLOW.md §2), plus the work order's current one even if
     * it was deactivated or deleted.
     *
     * @return Collection<int, Department>
     */
    private function selectableRequesterDepartments(?WorkOrder $workOrder = null): Collection
    {
        return Department::withTrashed()
            ->whereRelation('company', 'is_client', true)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('is_active', true)->whereNull('deleted_at'))
                ->when($workOrder?->requester_department_id, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * Departments a work order can be addressed to (FLOW.md §2): active ones
     * of the executor company, plus the work order's current target even if
     * it was deactivated or deleted. Only id, code, and name.
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
     * Target departments every user may filter the list by: the active
     * executor departments the form offers, plus deactivated or deleted
     * ones that a work order the user may see is addressed to. So a filter
     * never names a department the user could not already see.
     *
     * @return Collection<int, Department>
     */
    private function filterableTargetDepartments(User $user): Collection
    {
        return Department::withTrashed()
            ->whereRelation('company', 'is_client', false)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('is_active', true)->whereNull('deleted_at'))
                ->orWhereIn('id', WorkOrder::query()->visibleTo($user)->whereNotNull('target_department_id')->select('target_department_id')))
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
     * @return array{value: string, label: string, destructive: bool, requires_note: bool, note_label: string}
     */
    private function transitionOption(WorkOrderTransition $transition): array
    {
        return [
            'value' => $transition->toName(),
            'label' => $transition->label,
            'destructive' => $transition->isDestructive,
            'requires_note' => $transition->requiresNote,
            'note_label' => $transition->noteLabel,
        ];
    }

    /**
     * Who the work order waits for, told to everyone who is not the one
     * whose turn it is (holds none of the status's waitsOn() permissions).
     */
    private function waitingFor(WorkOrder $workOrder, User $user): ?string
    {
        $waitsOn = $workOrder->status->waitsOn();

        if ($waitsOn === [] || (! $user->isClient() && array_any($waitsOn, fn (Permission $permission): bool => $user->checkPermissionTo($permission->value)))) {
            return null;
        }

        return $workOrder->status->waitingMessage();
    }

    /**
     * The note given when the work order entered its current status, when
     * the change that brought it there requires one: why it was rejected,
     * returned for revision, or cancelled.
     *
     * @return array{label: string, note: string, user: string, created_at: string}|null
     */
    private function statusNote(WorkOrder $workOrder): ?array
    {
        $entry = $workOrder->statusHistories()
            ->reorder()
            ->latest('created_at')
            ->latest('id')
            ->where('to_status', $workOrder->status->getValue())
            ->with('user')
            ->first();

        $transition = $entry?->from_status !== null
            ? WorkOrderStatus::fromName($entry->from_status)?->transitionFor($workOrder->status->getValue())
            : null;

        if ($entry === null || $transition?->requiresNote !== true || blank($entry->note)) {
            return null;
        }

        return [
            'label' => $transition->noteLabel,
            'note' => $entry->note,
            'user' => $entry->user->name,
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }
}
