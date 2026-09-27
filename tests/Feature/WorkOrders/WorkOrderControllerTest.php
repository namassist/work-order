<?php

use App\Actions\Attachments\AddAttachment;
use App\Actions\WorkOrders\AddWorkOrderComment;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->department = Department::factory()->client()->create(['code' => 'IT']);
    $this->category = WorkOrderCategory::factory()->create(['code' => 'LST']);
});

/**
 * A work order in the test department.
 */
function ownWorkOrder(array $attributes = []): WorkOrder
{
    return WorkOrder::factory()->create(['requester_department_id' => test()->department->id, ...$attributes]);
}

describe('index', function () {
    it('lists only the user\'s department work orders', function () {
        $mine = ownWorkOrder(['title' => 'Lampu kantor mati']);
        WorkOrder::factory()->create(['title' => 'Milik departemen lain']);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->component('work-orders/Index')
                ->has('workOrders.data', 1)
                ->where('workOrders.data.0.id', $mine->id)
                ->where('workOrders.data.0.display_number', 'Draft')
                ->where('workOrders.data.0.status', ['value' => 'draft', 'label' => 'Draft', 'tone' => 'secondary'])
                ->where('departments', null));
    });

    it('lists every department\'s submitted work orders with work-orders.view-all and offers the IC departments as a filter', function () {
        ownWorkOrder(['number' => 'WO/IT/2026/09/0001', 'status' => 'diajukan', 'target_department_id' => Department::factory()->create()->id]);
        $other = WorkOrder::factory()->submitted()->create();

        $this->actingAs(unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersViewAll))
            ->get(route('work-orders.index', ['department' => $other->requester_department_id]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('workOrders.data', 1)
                ->where('workOrders.data.0.id', $other->id)
                ->where('departments', fn ($departments): bool => collect($departments)->pluck('id')->sort()->values()->all()
                    === Department::query()->whereRelation('company', 'is_client', true)->orderBy('id')->pluck('id')->all()));
    });

    it('ignores a department filter that falls outside the user\'s visibility', function () {
        $other = WorkOrder::factory()->create();

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index', ['department' => $other->requester_department_id]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page->has('workOrders.data', 0));
    });

    it('searches number and title case-insensitively', function () {
        ownWorkOrder(['title' => 'Perbaikan AC ruang rapat']);
        ownWorkOrder(['title' => 'Lainnya', 'number' => 'WO/IT/2026/09/0007', 'status' => 'diajukan']);
        ownWorkOrder(['title' => 'Tidak cocok']);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index', ['search' => 'ac ruang']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page->has('workOrders.data', 1));

        $this->get(route('work-orders.index', ['search' => '0007']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('workOrders.data', 1)
                ->where('workOrders.data.0.number', 'WO/IT/2026/09/0007'));
    });

    it('filters by status and category', function () {
        $other = WorkOrderCategory::factory()->create();
        $match = ownWorkOrder(['status' => 'diajukan', 'number' => 'WO/IT/2026/09/0001']);
        ownWorkOrder(['status' => 'diajukan', 'number' => 'WO/IT/2026/09/0002', 'work_order_category_id' => $other->id]);
        ownWorkOrder(['work_order_category_id' => $match->work_order_category_id]);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index', ['status' => 'diajukan', 'category' => $match->work_order_category_id]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('workOrders.data', 1)
                ->where('workOrders.data.0.id', $match->id));
    });

    it('filters the created date range by WITA days', function () {
        // 2026-09-24 23:30 UTC is 25 Sep 07:30 WITA.
        $inside = ownWorkOrder(['created_at' => Carbon::parse('2026-09-24 23:30', 'UTC')]);
        ownWorkOrder(['created_at' => Carbon::parse('2026-09-24 15:30', 'UTC')]);
        ownWorkOrder(['created_at' => Carbon::parse('2026-09-25 16:30', 'UTC')]);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index', ['from' => '2026-09-25', 'to' => '2026-09-25']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('workOrders.data', 1)
                ->where('workOrders.data.0.id', $inside->id));
    });

    it('sorts by last activity, most recent first, when asked', function () {
        $this->travelTo(Carbon::parse('2026-09-25 02:00', 'UTC'));
        $user = userInDepartment($this->department, Permission::WorkOrdersView);
        $oldest = ownWorkOrder(['created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);
        $middle = ownWorkOrder(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        $newest = ownWorkOrder(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        app(AddWorkOrderComment::class)->handle($oldest, $user, 'Masih ditunggu.');

        $this->actingAs($user)
            ->get(route('work-orders.index', ['sort' => 'diperbarui']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('workOrders.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [
                    $oldest->id, $newest->id, $middle->id,
                ])
                ->where('workOrders.data.0.updated_at', '2026-09-25T02:00:00+00:00')
                ->where('filters.sort', 'diperbarui'));
    });

    it('rejects an unknown status and an inverted date range', function () {
        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index', ['status' => 'disetujui', 'from' => '2026-09-25', 'to' => '2026-09-24']))
            ->assertSessionHasErrors(['status', 'to']);
    });

    it('hides soft-deleted work orders by default and lists them with the restore permission', function () {
        ownWorkOrder();
        $deleted = ownWorkOrder();
        $deleted->delete();

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersRestore))
            ->get(route('work-orders.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page->has('workOrders.data', 1));

        $this->get(route('work-orders.index', ['trashed' => 1]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('workOrders.data', 1)
                ->where('workOrders.data.0.id', $deleted->id));
    });

    it('forbids the deleted list without the restore permission', function () {
        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index', ['trashed' => 1]))
            ->assertForbidden();
    });

    it('paginates 15 work orders per page', function () {
        WorkOrder::factory()->count(16)->create(['requester_department_id' => $this->department->id]);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('workOrders.data', 1)
                ->where('workOrders.total', 16));
    });

    it('counts the visible work orders per status, ignoring list filters and deleted ones', function () {
        ownWorkOrder();
        ownWorkOrder();
        ownWorkOrder()->delete();
        WorkOrder::factory()->submitted()->create(['requester_department_id' => $this->department->id]);
        WorkOrder::factory()->cancelled()->create(['requester_department_id' => $this->department->id]);
        WorkOrder::factory()->submitted()->create();

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.index', ['status' => 'draft', 'search' => 'tidak ada']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('stats', [
                    'total' => 4,
                    'statuses' => ['draft' => 2, 'diajukan' => 1, 'dibatalkan' => 1],
                ]));
    });

    it('counts only their own department\'s work orders for a client user holding work-orders.view-all', function () {
        $client = Department::factory()->client()->create();
        WorkOrder::factory()->create(['requester_department_id' => $client->id]);
        ownWorkOrder();

        $this->actingAs(userInDepartment($client, Permission::WorkOrdersView, Permission::WorkOrdersViewAll))
            ->get(route('work-orders.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('stats.total', 1)
                ->where('departments', null));
    });

    it('counts every department\'s submitted work orders with work-orders.view-all, but no drafts', function () {
        ownWorkOrder();
        WorkOrder::factory()->submitted()->count(2)->create();

        $this->actingAs(unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersViewAll))
            ->get(route('work-orders.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('stats.total', 2)
                ->where('stats.statuses.diajukan', 2)
                ->where('stats.statuses.draft', 0));
    });
});

describe('create and store', function () {
    it('offers only active, non-deleted categories', function () {
        WorkOrderCategory::factory()->inactive()->create();
        WorkOrderCategory::factory()->create()->delete();

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersCreate))
            ->get(route('work-orders.create'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->component('work-orders/Create')
                ->where('department.code', 'IT')
                ->has('categories', 1)
                ->where('categories.0.id', $this->category->id));
    });

    it('creates a numberless draft in the requester\'s department with its first history row', function () {
        $this->travelTo(Carbon::parse('2026-09-25 02:00', 'UTC'));
        $user = userInDepartment($this->department, Permission::WorkOrdersCreate);

        $response = $this->actingAs($user)->post(route('work-orders.store'), [
            'title' => 'Lampu kantor mati',
            'description' => 'Lantai 2',
            'work_order_category_id' => $this->category->id,
            'urgency' => 'tinggi',
            'target_date' => '2026-09-25',
            // Fixed by the server: the requester's own department and account.
            'requester_department_id' => Department::factory()->client()->create()->id,
            'requester_id' => User::factory()->create()->id,
        ]);

        $workOrder = WorkOrder::sole();
        $response->assertRedirect(route('work-orders.show', $workOrder));
        expect($workOrder)
            ->number->toBeNull()
            ->status->getValue()->toBe('draft')
            ->requester_department_id->toBe($this->department->id)
            ->requester_id->toBe($user->id)
            ->requester_name->toBeNull()
            ->created_by->toBe($user->id)
            ->target_date->toDateString()->toBe('2026-09-25');
        expect($workOrder->statusHistories()->sole())
            ->from_status->toBeNull()
            ->to_status->toBe('draft')
            ->user_id->toBe($user->id);
    });

    it('requires the title and category', function () {
        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersCreate))
            ->post(route('work-orders.store'), [])
            ->assertSessionHasErrors(['title', 'work_order_category_id']);

        expect(WorkOrder::count())->toBe(0);
    });

    it('rejects an inactive or deleted category', function (Closure $category) {
        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersCreate))
            ->post(route('work-orders.store'), ['title' => 'X', 'work_order_category_id' => $category()->id])
            ->assertSessionHasErrors('work_order_category_id');
    })->with([
        'inactive' => [fn (): WorkOrderCategory => WorkOrderCategory::factory()->inactive()->create()],
        'deleted' => [fn (): WorkOrderCategory => tap(WorkOrderCategory::factory()->create())->delete()],
    ]);

    it('rejects a target date before today in WITA', function () {
        // 25 Sep 17:00 UTC is already 26 Sep in WITA.
        $this->travelTo(Carbon::parse('2026-09-25 17:00', 'UTC'));

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersCreate))
            ->post(route('work-orders.store'), [
                'title' => 'X',
                'work_order_category_id' => $this->category->id,
                'target_date' => '2026-09-25',
            ])
            ->assertSessionHasErrors(['target_date' => 'Target selesai tidak boleh sebelum hari ini.']);
    });

    it('attaches the documents chosen on the form', function () {
        $disk = Storage::fake('attachments');
        $user = userInDepartment($this->department, Permission::WorkOrdersCreate);

        $this->actingAs($user)->post(route('work-orders.store'), [
            'title' => 'Lampu kantor mati',
            'work_order_category_id' => $this->category->id,
            'urgency' => 'normal',
            'attachments' => [attachmentUpload('dokumen.pdf', 'Surat.pdf'), attachmentUpload('foto.jpg', 'Foto lampu.jpg')],
        ])->assertRedirect();

        expect(WorkOrder::sole()->attachmentsIn(WorkOrder::DOCUMENTS)->pluck('name')->all())->toBe(['Surat.pdf', 'Foto lampu.jpg'])
            ->and(Media::query()->pluck('uploaded_by')->unique()->all())->toBe([$user->id])
            ->and($disk->allFiles())->toHaveCount(2);
    });

    it('creates nothing when a document is refused', function (array $attachments, string $errorKey, string $message) {
        $disk = Storage::fake('attachments');
        config(['work_order.attachments.dokumen.max_files' => 1]);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersCreate))
            ->post(route('work-orders.store'), [
                'title' => 'Lampu kantor mati',
                'work_order_category_id' => $this->category->id,
                'urgency' => 'normal',
                'attachments' => array_map(fn (array $upload): UploadedFile => attachmentUpload(...$upload), $attachments),
            ])
            ->assertSessionHasErrors([$errorKey => $message]);

        expect(WorkOrder::query()->count())->toBe(0)
            ->and(Media::query()->count())->toBe(0)
            ->and($disk->allFiles())->toBe([]);
    })->with([
        'disguised executable' => [[['program.exe', 'invoice.pdf']], 'attachments.0', 'Jenis berkas lampiran tidak diizinkan. Gunakan PDF, JPG, JPEG, PNG, WEBP, DOCX, XLSX.'],
        'more than the limit' => [[['dokumen.pdf'], ['foto.png']], 'attachments', 'Lampiran maksimal terdiri dari 1 anggota.'],
    ]);
});

describe('show', function () {
    it('shows the work order with its timeline and available transitions', function () {
        $user = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersUpdate);
        $workOrder = ownWorkOrder();
        $workOrder->statusHistories()->create(['to_status' => 'draft', 'user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->component('work-orders/Show')
                ->where('workOrder.display_number', 'Draft')
                ->has('timeline', 1)
                ->where('timeline.0.to', ['value' => 'draft', 'label' => 'Draft'])
                ->where('timeline.0.user.name', $user->name)
                ->where('transitions', [
                    ['value' => 'diajukan', 'label' => 'Ajukan', 'tone' => 'warning', 'requires_note' => false, 'requires_target_department' => true],
                    ['value' => 'dibatalkan', 'label' => 'Batalkan', 'tone' => 'destructive', 'requires_note' => true, 'requires_target_department' => false],
                ])
                ->where('can.update', true));
    });

    it('lists the documents and allows changing them only on drafts', function (Closure $workOrder, bool $changeable) {
        Storage::fake('attachments');
        $user = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersUpdate);
        $workOrder = $workOrder();
        app(AddAttachment::class)->handle($workOrder, $workOrder->documentsCollection(), attachmentUpload('dokumen.pdf', 'Surat.pdf'), $user);

        $this->actingAs($user)
            ->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('attachments.target', ['type' => 'work-order', 'id' => $workOrder->id, 'collection' => 'dokumen'])
                ->where('attachments.rules.max_files', 10)
                ->has('attachments.items', 1)
                ->where('attachments.items.0.name', 'Surat.pdf')
                ->where('attachments.items.0.previewable', true)
                ->where('attachments.items.0.uploader.name', $user->name)
                ->where('attachments.can', ['upload' => $changeable, 'delete' => $changeable]));
    })->with([
        'draft' => [fn (): WorkOrder => ownWorkOrder(), true],
        'submitted' => [fn () => WorkOrder::factory()->submitted()->create(['requester_department_id' => test()->department->id]), false],
    ]);

    it('offers no transitions without the update permission', function () {
        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.show', ownWorkOrder()))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('transitions', []));
    });

    it('returns 404 for another department\'s work order', function () {
        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('work-orders.show', WorkOrder::factory()->create()))
            ->assertNotFound();
    });
});

describe('edit and update', function () {
    it('keeps the work order\'s deactivated category selectable', function () {
        $retired = WorkOrderCategory::factory()->inactive()->create();
        $workOrder = ownWorkOrder(['work_order_category_id' => $retired->id]);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersUpdate))
            ->get(route('work-orders.edit', $workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->component('work-orders/Edit')
                ->has('categories', 2));

        $this->put(route('work-orders.update', $workOrder), ['title' => 'Baru', 'work_order_category_id' => $retired->id, 'urgency' => 'normal'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('work-orders.show', $workOrder));

        expect($workOrder->refresh()->title)->toBe('Baru');
    });

    it('keeps an unchanged past target date valid but rejects moving it into the past', function () {
        $this->travelTo(Carbon::parse('2026-09-25 02:00', 'UTC'));
        $workOrder = ownWorkOrder(['target_date' => '2026-09-01', 'work_order_category_id' => $this->category->id]);
        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersUpdate));

        $this->put(route('work-orders.update', $workOrder), ['title' => 'Baru', 'work_order_category_id' => $this->category->id, 'urgency' => 'normal', 'target_date' => '2026-09-01'])
            ->assertSessionHasNoErrors();
        $this->put(route('work-orders.update', $workOrder), ['title' => 'Baru', 'work_order_category_id' => $this->category->id, 'urgency' => 'normal', 'target_date' => '2026-09-02'])
            ->assertSessionHasErrors('target_date');

        expect($workOrder->refresh()->target_date->toDateString())->toBe('2026-09-01');
    });

    it('forbids editing a submitted work order', function () {
        $workOrder = ownWorkOrder(['status' => 'diajukan', 'number' => 'WO/IT/2026/09/0001', 'title' => 'Lama']);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersUpdate))
            ->put(route('work-orders.update', $workOrder), ['title' => 'Baru', 'work_order_category_id' => $workOrder->work_order_category_id])
            ->assertForbidden();

        expect($workOrder->refresh()->title)->toBe('Lama');
    });
});

describe('destroy and restore', function () {
    it('soft-deletes a draft and restores it', function () {
        $workOrder = ownWorkOrder();
        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersDelete, Permission::WorkOrdersRestore));

        $this->delete(route('work-orders.destroy', $workOrder))->assertRedirect(route('work-orders.index'));
        $this->assertSoftDeleted($workOrder);

        $this->patch(route('work-orders.restore', $workOrder))->assertRedirect();
        $this->assertNotSoftDeleted($workOrder);
    });

    it('refuses to delete a work order that left the draft status', function () {
        $workOrder = ownWorkOrder(['status' => 'diajukan', 'number' => 'WO/IT/2026/09/0001']);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersDelete))
            ->from(route('work-orders.show', $workOrder))
            ->delete(route('work-orders.destroy', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder))
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertNotSoftDeleted($workOrder);
    });
});

it('forbids users without the matching permission', function (string $method, Closure $url, array $payload) {
    $workOrder = ownWorkOrder(['title' => 'Lama']);
    $deleted = ownWorkOrder();
    $deleted->delete();
    $user = userInDepartment($this->department, ...array_filter(Permission::cases(), fn (Permission $permission): bool => ! str_starts_with($permission->value, 'work-orders.')));

    $this->actingAs($user)
        ->{$method}($url($workOrder, $deleted), $payload)
        ->assertForbidden();

    expect(WorkOrder::count())->toBe(1)
        ->and(WorkOrder::onlyTrashed()->count())->toBe(1)
        ->and($workOrder->refresh())
        ->title->toBe('Lama')
        ->status->getValue()->toBe('draft');
})->with([
    'index' => ['get', fn (): string => route('work-orders.index'), []],
    'create' => ['get', fn (): string => route('work-orders.create'), []],
    'store' => ['post', fn (): string => route('work-orders.store'), ['title' => 'X', 'work_order_category_id' => 1]],
    'show' => ['get', fn (WorkOrder $workOrder): string => route('work-orders.show', $workOrder), []],
    'edit' => ['get', fn (WorkOrder $workOrder): string => route('work-orders.edit', $workOrder), []],
    'update' => ['put', fn (WorkOrder $workOrder): string => route('work-orders.update', $workOrder), ['title' => 'X', 'work_order_category_id' => 1]],
    'destroy' => ['delete', fn (WorkOrder $workOrder): string => route('work-orders.destroy', $workOrder), []],
    'restore' => ['patch', fn (WorkOrder $workOrder, WorkOrder $deleted): string => route('work-orders.restore', $deleted), []],
    'transition' => ['post', fn (WorkOrder $workOrder): string => route('work-orders.transitions.store', $workOrder), ['status' => 'diajukan']],
]);

it('returns 404 on every record endpoint for another department\'s work order', function (string $method, string $route) {
    $workOrder = WorkOrder::factory()->create();
    if ($route === 'work-orders.restore') {
        $workOrder->delete();
    }

    $this->actingAs(userInDepartment($this->department, ...Permission::cases())->revokePermissionTo(Permission::WorkOrdersViewAll->value))
        ->{$method}(route($route, $workOrder), ['title' => 'X', 'work_order_category_id' => $this->category->id, 'status' => 'diajukan'])
        ->assertNotFound();
})->with([
    'show' => ['get', 'work-orders.show'],
    'edit' => ['get', 'work-orders.edit'],
    'update' => ['put', 'work-orders.update'],
    'destroy' => ['delete', 'work-orders.destroy'],
    'restore' => ['patch', 'work-orders.restore'],
    'transition' => ['post', 'work-orders.transitions.store'],
]);

it('keeps a guest out', function () {
    $this->get(route('work-orders.index'))->assertRedirect(route('login'));
});

describe('target department', function () {
    /**
     * A valid store payload with the given overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function draftPayload(array $overrides = []): array
    {
        return [
            'title' => 'Lampu kantor mati',
            'work_order_category_id' => test()->category->id,
            'urgency' => 'normal',
            ...$overrides,
        ];
    }

    it('saves a draft with or without an active executor department as its target', function (bool $withTarget) {
        $target = Department::factory()->create(['code' => 'ENG']);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersCreate))
            ->post(route('work-orders.store'), draftPayload(['target_department_id' => $withTarget ? $target->id : null]))
            ->assertSessionHasNoErrors();

        expect(WorkOrder::sole()->target_department_id)->toBe($withTarget ? $target->id : null);
    })->with(['with a target' => true, 'without a target' => false]);

    it('refuses a target that is not an active executor department', function (Closure $target) {
        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersCreate))
            ->post(route('work-orders.store'), draftPayload(['target_department_id' => $target()->id]))
            ->assertSessionHasErrors(['target_department_id' => 'Pilih departemen aktif dari perusahaan pelaksana.']);

        expect(WorkOrder::count())->toBe(0);
    })->with([
        'an IC department' => [fn (): Department => Department::factory()->client()->create()],
        'an inactive executor department' => [fn (): Department => Department::factory()->inactive()->create()],
        'a deleted executor department' => [fn (): Department => tap(Department::factory()->create())->delete()],
    ]);

    it('changes the target of a draft and keeps a target deactivated since', function () {
        $old = Department::factory()->create(['code' => 'OLD']);
        $new = Department::factory()->create(['code' => 'NEW']);
        $draft = ownWorkOrder(['target_department_id' => $old->id]);
        $old->update(['is_active' => false]);
        $user = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersUpdate);

        $this->actingAs($user)
            ->put(route('work-orders.update', $draft), draftPayload(['target_department_id' => $old->id]))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->put(route('work-orders.update', $draft), draftPayload(['target_department_id' => $new->id]))
            ->assertSessionHasNoErrors();

        expect($draft->refresh()->target_department_id)->toBe($new->id);
    });

    it('offers the active executor departments as targets on the forms', function () {
        $active = Department::factory()->create(['code' => 'ENG']);
        Department::factory()->inactive()->create(['code' => 'OFF']);
        $current = Department::factory()->create(['code' => 'CUR']);
        $draft = ownWorkOrder(['target_department_id' => $current->id]);
        $current->delete();
        $user = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersCreate, Permission::WorkOrdersUpdate);

        $codes = fn ($targets): array => collect($targets)->pluck('code')->all();

        $this->actingAs($user)->get(route('work-orders.create'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('targetDepartments', fn ($targets): bool => in_array('ENG', $codes($targets), true)
                    && array_intersect(['OFF', 'CUR', 'IT'], $codes($targets)) === []
                    && collect($targets)->firstWhere('code', 'ENG') === ['id' => $active->id, 'code' => 'ENG', 'name' => $active->name]));

        $this->actingAs($user)->get(route('work-orders.edit', $draft))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('targetDepartments', fn ($targets): bool => array_intersect(['CUR', 'ENG'], $codes($targets)) === ['CUR', 'ENG']
                    && ! in_array('OFF', $codes($targets), true)));
    });

    it('shows the requester, the entered-by user, and both departments', function () {
        $target = Department::factory()->create(['code' => 'ENG']);
        $user = userInDepartment($this->department, Permission::WorkOrdersView);
        $workOrder = ownWorkOrder(['target_department_id' => $target->id]);

        $this->actingAs($user)->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('workOrder.requester_department.code', 'IT')
                ->where('workOrder.target_department.code', 'ENG')
                ->where('workOrder.requester', ['id' => $workOrder->requester_id, 'name' => $workOrder->requester->name])
                ->where('workOrder.entered_by', ['name' => $workOrder->enteredBy->name]));
    });

    it('names a contact requester without an account', function () {
        $koordinator = unggulUser(Permission::WorkOrdersView);
        $workOrder = WorkOrder::factory()->onBehalf($koordinator, contactName: 'Pak Andi')->create(['requester_department_id' => $this->department->id]);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))->get(route('work-orders.show', $workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('workOrder.requester', ['id' => null, 'name' => 'Pak Andi'])
                ->where('workOrder.entered_by', ['name' => $koordinator->name]));
    });
});

describe('who creates work orders', function () {
    it('lets only IC users create, for their own department', function () {
        $unggul = unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersCreate);

        $this->actingAs($unggul)->get(route('work-orders.create'))->assertForbidden();
        $this->actingAs($unggul)
            ->post(route('work-orders.store'), ['title' => 'X', 'work_order_category_id' => $this->category->id, 'urgency' => 'normal'])
            ->assertForbidden();

        expect(WorkOrder::count())->toBe(0);
    });
});

describe('requester integrity', function () {
    it('refuses a work order with both or neither of a requester account and a contact name', function (?bool $account, ?string $name) {
        $workOrder = WorkOrder::factory()->create(['requester_department_id' => $this->department->id]);

        expect(fn () => DB::table('work_orders')->where('id', $workOrder->id)->update([
            'requester_id' => $account ? $workOrder->created_by : null,
            'requester_name' => $name,
        ]))->toThrow(QueryException::class, 'work_orders_requester_check');
    })->with([
        'both' => [true, 'Pak Andi'],
        'neither' => [false, null],
    ]);
});

describe('submitted number integrity', function () {
    it('refuses a Diajukan work order without a number', function () {
        $submitted = WorkOrder::factory()->submitted()->create(['requester_department_id' => $this->department->id]);

        // Each attempt in its own savepoint, so the first failure does not abort the test's transaction.
        expect(fn () => DB::transaction(fn () => DB::table('work_orders')->where('id', $submitted->id)->update(['number' => null])))
            ->toThrow(QueryException::class, 'work_orders_submitted_number_check');

        $category = WorkOrderCategory::factory()->create();

        expect(fn () => DB::transaction(fn () => WorkOrder::factory()->create([
            'requester_department_id' => $this->department->id,
            'created_by' => $submitted->created_by,
            'requester_id' => $submitted->created_by,
            'work_order_category_id' => $category->id,
            'status' => 'diajukan',
            'number' => null,
        ])))->toThrow(QueryException::class, 'work_orders_submitted_number_check');
    });

    it('allows a draft or a draft cancelled before submission without a number', function () {
        WorkOrder::factory()->create(['requester_department_id' => $this->department->id]);
        WorkOrder::factory()->cancelled()->create(['requester_department_id' => $this->department->id]);

        expect(WorkOrder::whereNull('number')->count())->toBe(2);
    });
});
