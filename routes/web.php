<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WorkOrders\WorkOrderController;
use App\Http\Controllers\WorkOrders\WorkOrderTransitionController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request): RedirectResponse => to_route($request->user() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('work-orders', WorkOrderController::class)->parameters(['work-orders' => 'workOrder']);
    Route::patch('work-orders/{workOrder}/restore', [WorkOrderController::class, 'restore'])
        ->withTrashed()
        ->name('work-orders.restore');
    Route::post('work-orders/{workOrder}/transitions', [WorkOrderTransitionController::class, 'store'])
        ->name('work-orders.transitions.store');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
