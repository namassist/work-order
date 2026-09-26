<?php

use App\Enums\Permission;
use App\Models\Department;
use App\Models\WorkOrder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake('attachments');
    config(['work_order.attachments.dokumen.max_files' => 100]);

    $department = Department::factory()->create(['code' => 'IT']);
    $this->user = userInDepartment($department, Permission::WorkOrdersView, Permission::WorkOrdersUpdate, Permission::WorkOrdersExport);
    $this->workOrder = WorkOrder::factory()->create(['department_id' => $department->id]);
    $this->actingAs($this->user);
});

/**
 * Upload a document to the test work order.
 */
function uploadDocument(): TestResponse
{
    return test()->post(
        route('attachments.store', ['work-order', test()->workOrder->id, WorkOrder::DOCUMENTS]),
        ['file' => attachmentUpload('foto.png')],
    );
}

it('keeps the full export allowance after the upload limit is reached', function () {
    foreach (range(1, 30) as $attempt) {
        uploadDocument()->assertRedirect();
    }
    uploadDocument()->assertTooManyRequests();

    foreach (range(1, 10) as $attempt) {
        $this->get(route('work-orders.export'))->assertOk();
    }
});

it('keeps the full upload allowance after the export limit is reached', function () {
    foreach (range(1, 10) as $attempt) {
        $this->get(route('work-orders.export'))->assertOk();
    }
    $this->get(route('work-orders.export'))->assertTooManyRequests();

    foreach (range(1, 30) as $attempt) {
        uploadDocument()->assertRedirect();
    }
});

it('throttles every route through a named limiter', function () {
    $plainThrottles = collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn (RoutingRoute $route): array => array_map(
            fn (string $middleware): string => "{$route->uri()} {$middleware}",
            array_filter($route->gatherMiddleware(), fn (mixed $middleware): bool => is_string($middleware) && preg_match('/^throttle:\d/', $middleware) === 1),
        ));

    expect($plainThrottles->all())->toBe([]);
});
