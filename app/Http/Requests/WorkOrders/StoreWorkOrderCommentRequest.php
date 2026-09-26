<?php

namespace App\Http\Requests\WorkOrders;

use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreWorkOrderCommentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        // The policy's response keeps its 404 for other departments' work orders.
        return Gate::inspect('addComment', $this->workOrder());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.WorkOrderComment::MAX_BODY_LENGTH],
        ];
    }

    /**
     * The work order being commented on.
     */
    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }
}
