<?php

use App\Actions\WorkOrders\CommentNotAllowed;
use App\Actions\WorkOrders\DeleteWorkOrderComment;
use App\Actions\WorkOrders\TransitionWorkOrder;
use App\Actions\WorkOrders\UpdateWorkOrderComment;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-25 02:00', 'UTC'));
    $this->department = Department::factory()->create(['code' => 'IT']);
    $this->user = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersComment);
    $this->workOrder = WorkOrder::factory()->submitted()->create(['department_id' => $this->department->id]);
});

/**
 * A comment on the test work order by the given user (the test user by default).
 */
function commentBy(?User $author = null, array $attributes = []): WorkOrderComment
{
    return WorkOrderComment::factory()
        ->for(test()->workOrder)
        ->for($author ?? test()->user, 'author')
        ->create($attributes);
}

describe('posting', function () {
    it('adds a comment and logs it on the work order without its text', function () {
        $this->actingAs($this->user)
            ->post(route('work-orders.comments.store', $this->workOrder), ['body' => "Mohon dicek.\nTerima kasih."])
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', 'Komentar ditambahkan.');

        $comment = $this->workOrder->comments()->sole();
        expect($comment)
            ->user_id->toBe($this->user->id)
            ->body->toBe("Mohon dicek.\nTerima kasih.")
            ->edited_at->toBeNull();

        $activity = Activity::query()->forSubject($this->workOrder)->where('event', 'comment_added')->sole();
        expect($activity)
            ->causer_id->toBe($this->user->id)
            ->properties->toArray()->toBe(['komentar_id' => $comment->id])
            ->and(json_encode($activity->toArray()))->not->toContain('Mohon dicek');
    });

    it('rejects an invalid body', function (mixed $body) {
        $this->actingAs($this->user)
            ->post(route('work-orders.comments.store', $this->workOrder), ['body' => $body])
            ->assertSessionHasErrors('body');

        expect($this->workOrder->comments()->exists())->toBeFalse();
    })->with([
        'empty' => '',
        'whitespace only' => "  \n  ",
        'too long' => str_repeat('a', 2001),
    ]);

    it('accepts a body of exactly 2000 characters', function () {
        $this->actingAs($this->user)
            ->post(route('work-orders.comments.store', $this->workOrder), ['body' => str_repeat('a', 2000)])
            ->assertSessionHasNoErrors();

        expect($this->workOrder->comments()->count())->toBe(1);
    });

    it('counts posting separately from editing and other throttled routes', function () {
        $comment = commentBy();
        $this->actingAs($this->user);

        foreach (range(1, 10) as $attempt) {
            $this->post(route('work-orders.comments.store', $this->workOrder), ['body' => "Komentar {$attempt}"])->assertRedirect();
        }

        $this->patch(route('work-orders.comments.update', [$this->workOrder, $comment]), ['body' => 'Masih bisa'])->assertRedirect();
        $this->get(route('work-orders.export'))->assertStatus(403);
    });

    it('limits how often a user may edit', function () {
        $comment = commentBy();
        $this->actingAs($this->user);

        foreach (range(1, 10) as $attempt) {
            $this->patch(route('work-orders.comments.update', [$this->workOrder, $comment]), ['body' => "Versi {$attempt}"])->assertRedirect();
        }

        $this->patch(route('work-orders.comments.update', [$this->workOrder, $comment]), ['body' => 'Satu lagi'])->assertTooManyRequests();
        expect($comment->refresh()->body)->toBe('Versi 10');
    });

    it('limits how often a user may post', function () {
        $this->actingAs($this->user);

        foreach (range(1, 10) as $attempt) {
            $this->post(route('work-orders.comments.store', $this->workOrder), ['body' => "Komentar {$attempt}"])->assertRedirect();
        }

        $this->post(route('work-orders.comments.store', $this->workOrder), ['body' => 'Satu lagi'])->assertTooManyRequests();
        expect($this->workOrder->comments()->count())->toBe(10);
    });
});

describe('access', function () {
    it('answers 404 for another department\'s work order', function (Closure $request) {
        $other = WorkOrder::factory()->submitted()->create();
        $comment = WorkOrderComment::factory()->for($other)->for($this->user, 'author')->create();

        $request($this->actingAs($this->user), $other, $comment)->assertNotFound();
    })->with([
        'post' => fn ($test, WorkOrder $workOrder) => $test->post(route('work-orders.comments.store', $workOrder), ['body' => 'Halo']),
        'edit' => fn ($test, WorkOrder $workOrder, WorkOrderComment $comment) => $test->patch(route('work-orders.comments.update', [$workOrder, $comment]), ['body' => 'Halo']),
        'delete' => fn ($test, WorkOrder $workOrder, WorkOrderComment $comment) => $test->delete(route('work-orders.comments.destroy', [$workOrder, $comment])),
    ]);

    it('answers 404 for a comment of another work order', function (Closure $request) {
        $other = WorkOrder::factory()->submitted()->create(['department_id' => $this->department->id]);
        $comment = WorkOrderComment::factory()->for($other)->for($this->user, 'author')->create();

        $request($this->actingAs($this->user), $comment)->assertNotFound();
        $this->assertNotSoftDeleted($comment);
    })->with([
        'edit' => fn ($test, WorkOrderComment $comment) => $test->patch(route('work-orders.comments.update', [test()->workOrder, $comment]), ['body' => 'Halo']),
        'delete' => fn ($test, WorkOrderComment $comment) => $test->delete(route('work-orders.comments.destroy', [test()->workOrder, $comment])),
    ]);

    it('redirects guests to login', function () {
        $this->post(route('work-orders.comments.store', $this->workOrder), ['body' => 'Halo'])
            ->assertRedirect(route('login'));
    });

    it('lets a user without the comment permission read but not post', function () {
        $viewer = userInDepartment($this->department, Permission::WorkOrdersView);
        commentBy();

        $this->actingAs($viewer)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('timeline.0.type', 'comment')
                ->where('can.comment', false));

        $this->post(route('work-orders.comments.store', $this->workOrder), ['body' => 'Halo'])->assertForbidden();
    });

    it('lets only the author edit or delete a comment', function (Closure $request) {
        $comment = commentBy(userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersComment));

        $request($this->actingAs($this->user), $comment)->assertForbidden();
        expect($comment->refresh())->body->not->toBe('Diubah')->deleted_at->toBeNull();
    })->with([
        'edit' => fn ($test, WorkOrderComment $comment) => $test->patch(route('work-orders.comments.update', [test()->workOrder, $comment]), ['body' => 'Diubah']),
        'delete' => fn ($test, WorkOrderComment $comment) => $test->delete(route('work-orders.comments.destroy', [test()->workOrder, $comment])),
    ]);
});

describe('cancelled work orders', function () {
    beforeEach(function () {
        $this->comment = commentBy();
        $this->travel(1)->minutes();
        app(TransitionWorkOrder::class)->handle($this->workOrder, 'dibatalkan', $this->user, 'Tidak jadi');
    });

    it('refuses new, edited, and deleted comments', function (Closure $request) {
        $request($this->actingAs($this->user))
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error');

        expect($this->workOrder->comments()->withTrashed()->sole())
            ->body->toBe($this->comment->body)
            ->deleted_at->toBeNull();
    })->with([
        'post' => fn ($test) => $test->post(route('work-orders.comments.store', test()->workOrder), ['body' => 'Halo']),
        'edit' => fn ($test) => $test->patch(route('work-orders.comments.update', [test()->workOrder, test()->comment]), ['body' => 'Diubah']),
        'delete' => fn ($test) => $test->delete(route('work-orders.comments.destroy', [test()->workOrder, test()->comment])),
    ]);

    it('shows the timeline read-only', function () {
        $this->actingAs($this->user)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('can.comment', false)
                ->where('comments.read_only', true)
                ->where('timeline.0.can', ['update' => false, 'delete' => false]));
    });
});

describe('editing', function () {
    it('edits within the window and marks the comment edited', function () {
        $comment = commentBy();
        $this->travel(14)->minutes();
        $this->travel(59)->seconds();

        $this->actingAs($this->user)
            ->patch(route('work-orders.comments.update', [$this->workOrder, $comment]), ['body' => 'Sudah diperbaiki'])
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', 'Komentar diperbarui.');

        expect($comment->refresh())
            ->body->toBe('Sudah diperbaiki')
            ->edited_at->toEqual(now());
        expect(Activity::query()->forSubject($this->workOrder)->where('event', 'comment_edited')->sole())
            ->causer_id->toBe($this->user->id)
            ->properties->toArray()->toBe(['komentar_id' => $comment->id]);
    });

    it('refuses an edit after the window', function () {
        $comment = commentBy(attributes: ['body' => 'Asli']);
        $this->travel(15)->minutes();
        $this->travel(1)->seconds();

        $this->actingAs($this->user)
            ->patch(route('work-orders.comments.update', [$this->workOrder, $comment]), ['body' => 'Diubah'])
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error');

        expect($comment->refresh())->body->toBe('Asli')->edited_at->toBeNull();
    });

    it('follows the configured window', function () {
        config(['work_order.comments.edit_window_minutes' => 60]);
        $comment = commentBy();
        $this->travel(30)->minutes();

        $this->actingAs($this->user)
            ->patch(route('work-orders.comments.update', [$this->workOrder, $comment]), ['body' => 'Diubah'])
            ->assertInertiaFlash('toast.type', 'success');
    });

    it('does not edit a deleted comment', function () {
        $comment = commentBy();
        $comment->delete();

        $this->actingAs($this->user)
            ->patch(route('work-orders.comments.update', [$this->workOrder, $comment]), ['body' => 'Diubah'])
            ->assertNotFound();
    });
});

describe('concurrent changes', function () {
    it('does not edit a comment deleted after it was loaded', function () {
        $comment = commentBy(attributes: ['body' => 'Asli']);
        WorkOrderComment::find($comment->id)->delete();

        expect(fn () => app(UpdateWorkOrderComment::class)->handle($comment, $this->user, 'Diubah'))
            ->toThrow(CommentNotAllowed::class);

        expect(WorkOrderComment::withTrashed()->find($comment->id))->body->toBe('Asli')->edited_at->toBeNull();
        expect(Activity::query()->where('event', 'comment_edited')->exists())->toBeFalse();
    });

    it('does not delete a comment twice', function () {
        $comment = commentBy();
        WorkOrderComment::find($comment->id)->delete();

        expect(fn () => app(DeleteWorkOrderComment::class)->handle($comment, $this->user))
            ->toThrow(CommentNotAllowed::class);

        expect(Activity::query()->where('event', 'comment_deleted')->exists())->toBeFalse();
    });
});

describe('deleting', function () {
    it('soft-deletes within the window and keeps a placeholder in the timeline', function () {
        $comment = commentBy(attributes: ['body' => 'Rahasia kecil']);
        $this->travel(14)->minutes();
        $this->travel(59)->seconds();

        $this->actingAs($this->user)
            ->delete(route('work-orders.comments.destroy', [$this->workOrder, $comment]))
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', 'Komentar dihapus.');

        $this->assertSoftDeleted($comment);
        expect(Activity::query()->forSubject($this->workOrder)->where('event', 'comment_deleted')->sole())
            ->causer_id->toBe($this->user->id)
            ->properties->toArray()->toBe(['komentar_id' => $comment->id]);

        $this->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('timeline.0.type', 'comment')
                ->where('timeline.0.deleted', true)
                ->where('timeline.0.body', null)
                ->where('timeline.0.user.name', $this->user->name)
                ->where('timeline.0.can', ['update' => false, 'delete' => false]));
    });

    it('refuses a delete after the window', function () {
        $comment = commentBy();
        $this->travel(15)->minutes();
        $this->travel(1)->seconds();

        $this->actingAs($this->user)
            ->delete(route('work-orders.comments.destroy', [$this->workOrder, $comment]))
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertNotSoftDeleted($comment);
    });
});

describe('timeline', function () {
    it('merges status changes and comments in chronological order', function () {
        $transition = app(TransitionWorkOrder::class);
        $draft = WorkOrder::factory()->create(['department_id' => $this->department->id]);
        $this->workOrder = $draft;
        $draft->statusHistories()->create(['to_status' => 'draft', 'user_id' => $this->user->id]);

        $this->travel(5)->minutes();
        $first = commentBy(attributes: ['body' => 'Pertama']);
        $this->travel(5)->minutes();
        $transition->handle($draft, 'diajukan', $this->user);
        $second = commentBy(attributes: ['body' => 'Bersamaan dengan pengajuan']);
        $this->travel(5)->minutes();
        $transition->handle($draft, 'dibatalkan', $this->user, 'Salah input');

        $this->actingAs($this->user)
            ->get(route('work-orders.show', $draft))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('timeline', 5)
                ->where('timeline.0.type', 'status')
                ->where('timeline.0.to.value', 'draft')
                ->where('timeline.1.type', 'comment')
                ->where('timeline.1.id', $first->id)
                ->where('timeline.2.type', 'status')
                ->where('timeline.2.to.value', 'diajukan')
                ->where('timeline.3.type', 'comment')
                ->where('timeline.3.id', $second->id)
                ->where('timeline.4.type', 'status')
                ->where('timeline.4.to.value', 'dibatalkan')
                ->where('timeline.4.note', 'Salah input'));
    });

    it('sends the body as plain text, unescaped and unstripped', function () {
        commentBy(attributes: ['body' => "<script>alert('x')</script>\n<b>tebal</b>"]);

        $this->actingAs($this->user)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('timeline.0.body', "<script>alert('x')</script>\n<b>tebal</b>")
                ->where('timeline.0.edited', false)
                ->where('timeline.0.can', ['update' => true, 'delete' => true])
                ->where('can.comment', true)
                ->where('comments', ['max_length' => 2000, 'read_only' => false]));
    });

    it('offers edit and delete only to the author within the window', function () {
        $other = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersComment);
        commentBy($other);
        $this->travel(1)->minutes();
        commentBy();

        $this->actingAs($this->user)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('timeline.0.can', ['update' => false, 'delete' => false])
                ->where('timeline.1.can', ['update' => true, 'delete' => true]));

        $this->travel(16)->minutes();

        $this->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('timeline.1.can', ['update' => false, 'delete' => false]));
    });
});
