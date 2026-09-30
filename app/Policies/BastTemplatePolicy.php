<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\BastTemplate;
use App\Models\Media;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The BAST template (FLOW.md §9): editing its draft, previewing,
 * publishing, activating a version, and its images all need
 * bast-templates.manage, which is internal-only (never a client company
 * user) and held by the system admin by default.
 */
class BastTemplatePolicy
{
    public function manage(User $user): bool
    {
        return $user->checkPermissionTo(Permission::BastTemplatesManage->value);
    }

    public function viewAttachment(User $user, BastTemplate $template, Media $media): Response
    {
        return $this->manage($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function addAttachment(User $user, BastTemplate $template, string $collection): Response
    {
        return $this->manage($user) ? Response::allow() : Response::deny();
    }

    /**
     * Removing an image changes only the draft: published versions keep
     * their own copy.
     */
    public function deleteAttachment(User $user, BastTemplate $template, Media $media): Response
    {
        return $this->manage($user) ? Response::allow() : Response::deny();
    }
}
