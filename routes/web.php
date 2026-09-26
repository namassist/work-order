<?php

use App\Http\Controllers\Attachments\AttachmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WorkOrders\WorkOrderCommentController;
use App\Http\Controllers\WorkOrders\WorkOrderController;
use App\Http\Controllers\WorkOrders\WorkOrderExportController;
use App\Http\Controllers\WorkOrders\WorkOrderTransitionController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request): RedirectResponse => to_route($request->user() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Before the resource, so work-orders/{workOrder} does not catch it.
    Route::get('work-orders/export', WorkOrderExportController::class)
        ->middleware('throttle:wo-export')
        ->name('work-orders.export');
    Route::resource('work-orders', WorkOrderController::class)->parameters(['work-orders' => 'workOrder']);
    Route::patch('work-orders/{workOrder}/restore', [WorkOrderController::class, 'restore'])
        ->withTrashed()
        ->name('work-orders.restore');
    Route::post('work-orders/{workOrder}/transitions', [WorkOrderTransitionController::class, 'store'])
        ->name('work-orders.transitions.store');
    Route::post('work-orders/{workOrder}/comments', [WorkOrderCommentController::class, 'store'])
        ->middleware('throttle:wo-comment-post')
        ->name('work-orders.comments.store');
    Route::scopeBindings()->middleware('throttle:wo-comment-change')->group(function (): void {
        Route::patch('work-orders/{workOrder}/comments/{comment}', [WorkOrderCommentController::class, 'update'])
            ->name('work-orders.comments.update');
        Route::delete('work-orders/{workOrder}/comments/{comment}', [WorkOrderCommentController::class, 'destroy'])
            ->name('work-orders.comments.destroy');
    });

    Route::post('attachments/{attachableType}/{attachableId}/{collection}', [AttachmentController::class, 'store'])
        ->whereNumber('attachableId')
        ->middleware('throttle:attachment-upload')
        ->name('attachments.store');
    Route::get('attachments/{media:uuid}', [AttachmentController::class, 'show'])
        ->whereUuid('media')
        ->name('attachments.show');
    Route::delete('attachments/{media:uuid}', [AttachmentController::class, 'destroy'])
        ->whereUuid('media')
        ->name('attachments.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
