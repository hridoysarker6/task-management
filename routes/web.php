<?php

use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\RoleTemplateController;
use App\Http\Controllers\Company\ApplicationController;
use App\Http\Controllers\Company\RegisterCompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Settings\RoleController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// Public company registration (replaces Breeze's single-user register page).
Route::middleware('guest')->group(function () {
    Route::get('register-company', [RegisterCompanyController::class, 'create'])->name('register');
    Route::post('register-company', [RegisterCompanyController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('register.store');
});

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// The applicant's own application: status page and edit & resubmit (after rejection / info request).
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('awaiting-approval', [ApplicationController::class, 'show'])->name('application.show');
    Route::get('application/edit', [ApplicationController::class, 'edit'])->name('application.edit');
    Route::put('application', [ApplicationController::class, 'update'])->name('application.update');

    Route::post('organizations/{organization}/switch', OrganizationSwitchController::class)->name('organizations.switch');
});

// Inside a company (needs an active company context).
Route::middleware(['auth', 'verified', 'company'])->prefix('settings')->group(function () {
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::put('roles/matrix', [RoleController::class, 'updateMatrix'])->name('roles.matrix');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
});

// Platform area: System Admin only.
Route::middleware(['auth', 'verified', 'system.admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');

    Route::get('companies', [AdminCompanyController::class, 'index'])->name('companies.index');
    Route::get('companies/{organization}', [AdminCompanyController::class, 'show'])->name('companies.show');
    Route::post('companies/{organization}/approve', [AdminCompanyController::class, 'approve'])->name('companies.approve');
    Route::post('companies/{organization}/reject', [AdminCompanyController::class, 'reject'])->name('companies.reject');
    Route::post('companies/{organization}/request-info', [AdminCompanyController::class, 'requestInfo'])->name('companies.request-info');
    Route::post('companies/{organization}/suspend', [AdminCompanyController::class, 'suspend'])->name('companies.suspend');
    Route::post('companies/{organization}/reactivate', [AdminCompanyController::class, 'reactivate'])->name('companies.reactivate');
    Route::patch('companies/{organization}/user-limit', [AdminCompanyController::class, 'updateUserLimit'])->name('companies.user-limit');
    Route::get('companies/{organization}/documents/{document}', [AdminCompanyController::class, 'downloadDocument'])->name('companies.documents.download');

    Route::get('role-template', [RoleTemplateController::class, 'index'])->name('role-template.index');
    Route::put('role-template/matrix', [RoleTemplateController::class, 'updateMatrix'])->name('role-template.matrix');
    Route::post('role-template/roles', [RoleTemplateController::class, 'store'])->name('role-template.roles.store');
    Route::put('role-template/roles/{role}', [RoleTemplateController::class, 'update'])->name('role-template.roles.update');
    Route::delete('role-template/roles/{role}', [RoleTemplateController::class, 'destroy'])->name('role-template.roles.destroy');
});

require __DIR__.'/auth.php';
