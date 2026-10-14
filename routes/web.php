<?php

use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\RoleTemplateController;
use App\Http\Controllers\Company\ApplicationController;
use App\Http\Controllers\Company\RegisterCompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\People\AcceptInvitationController;
use App\Http\Controllers\People\ImportController;
use App\Http\Controllers\People\InvitationController;
use App\Http\Controllers\People\OffboardController;
use App\Http\Controllers\People\OrgChartController;
use App\Http\Controllers\People\PersonController;
use App\Http\Controllers\People\RehireController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Projects\LabelController;
use App\Http\Controllers\Projects\MemberController as ProjectMemberController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\SettingsController as ProjectSettingsController;
use App\Http\Controllers\Projects\WorkflowController;
use App\Http\Controllers\Settings\CompanySettingsController;
use App\Http\Controllers\Settings\DepartmentController;
use App\Http\Controllers\Settings\OnboardingController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Tasks\AttachmentController;
use App\Http\Controllers\Tasks\BacklogController;
use App\Http\Controllers\Tasks\BoardController;
use App\Http\Controllers\Tasks\CommentController as TaskCommentController;
use App\Http\Controllers\Tasks\LinkController;
use App\Http\Controllers\Tasks\MyWorkController;
use App\Http\Controllers\Tasks\SavedFilterController;
use App\Http\Controllers\Tasks\TaskController;
use App\Http\Controllers\Tasks\TaskListController;
use App\Http\Controllers\Tasks\TeamController;
use App\Http\Controllers\Tasks\TrashController;
use App\Http\Controllers\Tasks\TriageController;
use App\Models\Task;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// A task is found by its key (ECP-42) in the current company; the middleware has already chosen the company.
Route::pattern('task', '[A-Za-z][A-Za-z0-9]{1,9}-[0-9]+');
Route::bind('task', fn (string $key) => Task::findByKey($key) ?? abort(404));

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

// Accepting an invitation: public, because the invitee may not have an account yet.
Route::get('invitations/{token}', [AcceptInvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}/accept', [AcceptInvitationController::class, 'accept'])
    ->middleware('throttle:10,1')->name('invitations.accept');

// Inside a company (needs an active company context).
Route::middleware(['auth', 'verified', 'company'])->group(function () {
    Route::prefix('settings')->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('roles/matrix', [RoleController::class, 'updateMatrix'])->name('roles.matrix');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

        Route::get('company', [CompanySettingsController::class, 'edit'])->name('settings.company.edit');
        Route::post('company', [CompanySettingsController::class, 'update'])->name('settings.company.update');

        Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');

        Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
        Route::post('onboarding', [OnboardingController::class, 'update'])->name('onboarding.update');
    });

    Route::get('org-chart', OrgChartController::class)->name('orgchart');

    // Tasks are addressed by their key (ECP-42), always inside the current company.
    Route::get('my-work', MyWorkController::class)->name('my-work');
    Route::get('tasks/search', [TaskController::class, 'search'])->name('tasks.search');
    Route::get('tasks/create-options', [TaskController::class, 'createOptions'])->name('tasks.create-options');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::post('tasks/{task}/restore', [TaskController::class, 'restore'])->name('tasks.restore');
    Route::post('tasks/{task}/status', [TaskController::class, 'status'])->name('tasks.status');
    Route::post('tasks/{task}/assign', [TaskController::class, 'assign'])->name('tasks.assign');
    Route::post('tasks/{task}/watch', [TaskController::class, 'watch'])->name('tasks.watch');
    Route::delete('tasks/{task}/watch', [TaskController::class, 'unwatch'])->name('tasks.unwatch');
    Route::post('tasks/{task}/comments', [TaskCommentController::class, 'store'])->name('tasks.comments.store');
    Route::post('tasks/{task}/attachments', [AttachmentController::class, 'store'])->name('tasks.attachments.store');
    Route::post('tasks/{task}/links', [LinkController::class, 'store'])->name('tasks.links.store');
    Route::post('tasks/{task}/triage/accept', [TriageController::class, 'accept'])->name('tasks.triage.accept');
    Route::post('tasks/{task}/triage/reject', [TriageController::class, 'reject'])->name('tasks.triage.reject');
    Route::post('tasks/{task}/triage/info', [TriageController::class, 'askForInfo'])->name('tasks.triage.info');
    Route::put('comments/{comment}', [TaskCommentController::class, 'update'])->name('comments.update');
    Route::delete('comments/{comment}', [TaskCommentController::class, 'destroy'])->name('comments.destroy');
    Route::get('attachments/{attachment}', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
    Route::delete('links/{link}', [LinkController::class, 'destroy'])->name('links.destroy');
    Route::get('projects/{project}/triage', [TriageController::class, 'index'])->name('projects.triage.index');

    // Views of a project's tasks.
    Route::get('projects/{project}/board', [BoardController::class, 'index'])->name('projects.board');
    Route::get('projects/{project}/tasks', [TaskListController::class, 'index'])->name('projects.tasks.index');
    Route::post('projects/{project}/tasks/bulk', [TaskListController::class, 'bulk'])->name('projects.tasks.bulk');
    Route::get('projects/{project}/backlog', [BacklogController::class, 'index'])->name('projects.backlog');
    Route::post('tasks/{task}/move', [BoardController::class, 'move'])->name('tasks.move');
    Route::post('tasks/{task}/rank', [BacklogController::class, 'rank'])->name('tasks.rank');
    Route::post('saved-filters', [SavedFilterController::class, 'store'])->name('saved-filters.store');
    Route::delete('saved-filters/{savedFilter}', [SavedFilterController::class, 'destroy'])->name('saved-filters.destroy');
    Route::get('team', TeamController::class)->name('team');
    Route::get('projects/{project}/trash', TrashController::class)->name('projects.trash');

    Route::prefix('projects')->name('projects.')->group(function () {
        Route::get('/', [ProjectController::class, 'index'])->name('index');
        Route::get('create', [ProjectController::class, 'create'])->name('create');
        Route::post('/', [ProjectController::class, 'store'])->name('store');

        Route::get('{project}', [ProjectController::class, 'show'])->name('show');
        Route::get('{project}/edit', [ProjectController::class, 'edit'])->name('edit');
        Route::put('{project}', [ProjectController::class, 'update'])->name('update');
        Route::post('{project}/status', [ProjectController::class, 'changeStatus'])->name('status');
        Route::delete('{project}', [ProjectController::class, 'destroy'])->name('destroy');

        Route::get('{project}/members', [ProjectMemberController::class, 'index'])->name('members.index');
        Route::post('{project}/members', [ProjectMemberController::class, 'store'])->name('members.store');
        Route::put('{project}/members/{member}', [ProjectMemberController::class, 'update'])->name('members.update');
        Route::delete('{project}/members/{member}', [ProjectMemberController::class, 'destroy'])->name('members.destroy');

        Route::get('{project}/workflow', [WorkflowController::class, 'index'])->name('workflow.index');
        Route::post('{project}/workflow/statuses', [WorkflowController::class, 'store'])->name('workflow.statuses.store');
        Route::put('{project}/workflow/order', [WorkflowController::class, 'reorder'])->name('workflow.statuses.reorder');
        Route::put('{project}/workflow/statuses/{status}', [WorkflowController::class, 'update'])->name('workflow.statuses.update');
        Route::delete('{project}/workflow/statuses/{status}', [WorkflowController::class, 'destroy'])->name('workflow.statuses.destroy');
        Route::put('{project}/workflow/transitions', [WorkflowController::class, 'transitions'])->name('workflow.transitions');

        Route::get('{project}/settings', [ProjectSettingsController::class, 'edit'])->name('settings.edit');
        Route::put('{project}/settings', [ProjectSettingsController::class, 'update'])->name('settings.update');

        Route::get('{project}/labels', [LabelController::class, 'index'])->name('labels.index');
        Route::post('{project}/labels', [LabelController::class, 'store'])->name('labels.store');
        Route::put('{project}/labels/{label}', [LabelController::class, 'update'])->name('labels.update');
        Route::delete('{project}/labels/{label}', [LabelController::class, 'destroy'])->name('labels.destroy');
    });

    Route::prefix('people')->name('people.')->group(function () {
        Route::get('/', [PersonController::class, 'index'])->name('index');

        Route::get('invite', [InvitationController::class, 'create'])->name('invite');
        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

        Route::get('import', [ImportController::class, 'create'])->name('import.create');
        Route::get('import/template', [ImportController::class, 'template'])->name('import.template');
        Route::post('import', [ImportController::class, 'preview'])->name('import.preview');
        Route::get('import/{token}', [ImportController::class, 'show'])->name('import.show');
        Route::post('import/{token}', [ImportController::class, 'confirm'])->name('import.confirm');
        Route::get('import/{token}/errors', [ImportController::class, 'errors'])->name('import.errors');

        Route::get('{membership}', [PersonController::class, 'show'])->whereNumber('membership')->withTrashed()->name('show');
        Route::put('{membership}', [PersonController::class, 'update'])->whereNumber('membership')->name('update');

        Route::get('{membership}/offboard', [OffboardController::class, 'create'])->whereNumber('membership')->name('offboard.create');
        Route::post('{membership}/offboard', [OffboardController::class, 'store'])->whereNumber('membership')->name('offboard.store');
        Route::delete('{membership}/offboard', [OffboardController::class, 'cancel'])->whereNumber('membership')->name('offboard.cancel');

        Route::get('{membership}/rehire', [RehireController::class, 'create'])->whereNumber('membership')->withTrashed()->name('rehire.create');
        Route::post('{membership}/rehire', [RehireController::class, 'store'])->whereNumber('membership')->withTrashed()->name('rehire.store');
        Route::post('{membership}/rehire/different-person', [RehireController::class, 'differentPerson'])
            ->whereNumber('membership')->withTrashed()->name('rehire.different-person');
    });
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
