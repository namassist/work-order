<?php

namespace App\Http\Requests\WorkOrders;

use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateWorkOrderCommentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        // The policy's response keeps its 404 for other departments' work orders.
        return Gate::inspect('updateComment', [$this->workOrder(), $this->comment()]);
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
     * The work order the comment belongs to.
     */
    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }

    /**
     * The comment being edited.
     */
    public function comment(): WorkOrderComment
    {
        /** @var WorkOrderComment */
        return $this->route('comment');
    }
}
