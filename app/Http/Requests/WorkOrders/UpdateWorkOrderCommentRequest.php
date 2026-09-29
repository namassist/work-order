<?php

namespace App\Http\Requests\WorkOrders;

use App\Concerns\WorkOrderCommentValidationRules;
use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateWorkOrderCommentRequest extends FormRequest
{
    use WorkOrderCommentValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        // The policy's response keeps its 404 for work orders the user cannot see.
        return Gate::inspect('updateComment', [$this->workOrder(), $this->comment()]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->commentRules();
    }

    /**
     * The media uuids of the documents to attach.
     *
     * @return list<string>
     */
    public function documentUuids(): array
    {
        /** @var list<string> */
        return array_values($this->array('attachments'));
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
