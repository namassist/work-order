<?php

namespace App\Http\Requests\WorkOrders;

use App\Models\User;
use App\Models\WorkOrder;
use App\States\WorkOrder\WorkOrderStatus;
use App\Support\DisplayDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The work order list's filters. The list and its export both build their
 * query from workOrders(), so an export never holds more than the list shows.
 */
class ListWorkOrdersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->listingUser();

        return $user->can('viewAny', WorkOrder::class)
            && (! $this->boolean('trashed') || $user->can('viewTrashed', WorkOrder::class));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_column(WorkOrderStatus::options(), 'value'))],
            'department' => ['nullable', 'integer'],
            'category' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'trashed' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The validated filters, with an empty string (or false) for each one not set.
     *
     * @return array{search: string, status: string, department: string, category: string, from: string, to: string, trashed: bool}
     */
    public function filters(): array
    {
        return [
            'search' => (string) $this->validated('search'),
            'status' => (string) $this->validated('status'),
            'department' => (string) $this->validated('department'),
            'category' => (string) $this->validated('category'),
            'from' => (string) $this->validated('from'),
            'to' => (string) $this->validated('to'),
            'trashed' => (bool) $this->validated('trashed'),
        ];
    }

    /**
     * The work orders the list shows, in list order: those the user may see,
     * narrowed by the filters.
     *
     * @return Builder<WorkOrder>
     */
    public function workOrders(): Builder
    {
        $filters = $this->filters();

        return WorkOrder::query()
            ->visibleTo($this->listingUser())
            ->search($filters['search'])
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['department'], fn (Builder $query, string $id) => $query->where('department_id', (int) $id))
            ->when($filters['category'], fn (Builder $query, string $id) => $query->where('work_order_category_id', (int) $id))
            ->when($filters['from'], fn (Builder $query, string $from) => $query
                ->where('created_at', '>=', DisplayDate::startOfDayUtc($from)))
            ->when($filters['to'], fn (Builder $query, string $to) => $query
                ->where('created_at', '<=', DisplayDate::endOfDayUtc($to)))
            ->when($filters['trashed'], fn (Builder $query) => $query->onlyTrashed())
            ->latest()
            ->latest('id');
    }

    protected function listingUser(): User
    {
        /** @var User */
        return $this->user();
    }
}
