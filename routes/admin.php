<?php

use App\Enums\AuditSubject;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\BastTemplateController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\RegistrationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkOrderCategoryController;
use Illuminate\Support\Facades\Route;

/*
| Internal pages of the executor company. The `internal` middleware answers
| 404 to client company (IC) users, so every route that manages users, roles,
| master data, registrations, or the activity log belongs in this group
| (tests/Feature/Isolation/ClientIsolationTest.php checks every admin.* route).
*/
Route::middleware(['auth', 'verified', 'internal'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::resource('companies', CompanyController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::patch('companies/{company}/restore', [CompanyController::class, 'restore'])
        ->withTrashed()
        ->name('companies.restore');

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

    Route::get('registrations', [RegistrationController::class, 'index'])->name('registrations.index');
    Route::post('registrations/{user}/approve', [RegistrationController::class, 'approve'])->name('registrations.approve');
    Route::post('registrations/{user}/reject', [RegistrationController::class, 'reject'])->name('registrations.reject');

    Route::get('bast-template', [BastTemplateController::class, 'edit'])->name('bast-template.edit');
    Route::put('bast-template', [BastTemplateController::class, 'update'])->name('bast-template.update');
    Route::post('bast-template/publish', [BastTemplateController::class, 'publish'])->name('bast-template.publish');
    Route::post('bast-template/versions/{version}/activate', [BastTemplateController::class, 'activate'])->name('bast-template.activate');
    Route::get('bast-template/preview', [BastTemplateController::class, 'preview'])
        ->middleware('throttle:bast-template-preview')
        ->name('bast-template.preview');

    Route::get('activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');
    Route::get('activity-log/{subjectType}/{subjectId}', [ActivityLogController::class, 'history'])
        ->whereIn('subjectType', AuditSubject::withHistoryPanel())
        ->whereNumber('subjectId')
        ->name('activity-log.history');
});
