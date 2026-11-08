<?php

use App\Http\Controllers\{BillingController, InsightsController, ModuleController, WorkspaceController, WorkflowController, PublicSiteController};
use App\Http\Middleware\ClinicSession;
use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));
Route::get('/login', fn () => view('login'))->middleware('guest')->name('login');
Route::post('/login', [WorkspaceController::class, 'login'])->middleware(['guest', 'throttle:6,1']);
Route::post('/logout', [WorkspaceController::class, 'logout'])->middleware('auth');
Route::middleware(ClinicSession::class)->group(function (): void {
    Route::get('/book', [PublicSiteController::class, 'index']);
    Route::get('/book/{branchSlug}', [PublicSiteController::class, 'index']);
    Route::post('/book/{branchSlug}', [PublicSiteController::class, 'book'])->middleware('throttle:10,1');
    Route::get('/page/{branchSlug}/{slug}', [PublicSiteController::class, 'page']);
    Route::middleware('auth')->group(function (): void {
        Route::get('/admin/{path?}', fn () => view('workspace'))->where('path', '.*');
        Route::prefix('api')->group(function (): void {
            Route::get('bootstrap', [WorkspaceController::class, 'bootstrap']);
            Route::post('branch-selection', [OrganizationController::class, 'select']);
            Route::get('organization', [OrganizationController::class, 'index']);
            Route::put('organization', [OrganizationController::class, 'rename']);
            Route::post('organization/branches', [OrganizationController::class, 'save']);
            Route::put('organization/branches/{id}', [OrganizationController::class, 'save'])->whereNumber('id');
            Route::get('organization/branches/{id}/roles', [OrganizationController::class, 'roles'])->whereNumber('id');
            Route::post('organization/memberships', [OrganizationController::class, 'membership']);
            Route::get('appointments/options', [ModuleController::class, 'bookingOptions']);
            Route::get('dashboard', [InsightsController::class, 'dashboard']);
            Route::get('reports', [InsightsController::class, 'reports']);
            Route::get('settings', [WorkspaceController::class, 'settings']);
            Route::put('settings', [WorkspaceController::class, 'saveSettings']);
            Route::get('roles', [WorkspaceController::class, 'roles']);
            Route::post('roles', [WorkspaceController::class, 'saveRole']);
            Route::put('roles/{id}', [WorkspaceController::class, 'saveRole'])->whereNumber('id');
            Route::get('billing/options', [BillingController::class, 'options']);
            Route::post('billing/invoices', [BillingController::class, 'invoice']);
            Route::get('billing/invoices/{id}', [BillingController::class, 'detail'])->whereNumber('id');
            Route::post('billing/invoices/{id}/payments', [BillingController::class, 'payment'])->whereNumber('id');
            Route::post('campaigns/{id}/prepare', [WorkflowController::class, 'campaign'])->whereNumber('id');
            Route::post('leads/{id}/convert', [WorkflowController::class, 'convertLead'])->whereNumber('id');
            Route::get('clients/{id}/profile', [ModuleController::class, 'client'])->whereNumber('id');
            Route::get('photos/{id}/file', [ModuleController::class, 'photo'])->whereNumber('id');
            Route::get('modules/{module}/lookup/{field}', [ModuleController::class, 'lookup']);
            Route::get('modules/{module}', [ModuleController::class, 'index']);
            Route::post('modules/{module}', [ModuleController::class, 'save']);
            Route::put('modules/{module}/{id}', [ModuleController::class, 'save'])->whereNumber('id');
        });
    });
});
