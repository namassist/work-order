<?php

use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkOrderCategoryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::resource('departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::patch('departments/{department}/restore', [DepartmentController::class, 'restore'])
        ->withTrashed()
        ->name('departments.restore');

    Route::resource('work-order-categories', WorkOrderCategoryController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['work-order-categories' => 'category']);
    Route::patch('work-order-categories/{category}/restore', [WorkOrderCategoryController::class, 'restore'])
        ->withTrashed()
        ->name('work-order-categories.restore');

    Route::resource('users', UserController::class)->except(['show']);
    Route::patch('users/{user}/restore', [UserController::class, 'restore'])
        ->withTrashed()
        ->name('users.restore');

    Route::resource('roles', RoleController::class)->except(['show']);
});
