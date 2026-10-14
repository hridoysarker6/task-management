<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('key', 10);
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->foreignId('manager_membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->enum('status', ['active', 'on_hold', 'completed', 'archived'])->default('active');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('uses_sprints')->default(true); // off = Kanban mode
            $table->enum('progress_mode', ['count', 'points'])->default('count');
            $table->enum('triage_mode', ['off', 'external_only', 'all_stakeholders'])->default('external_only');
            $table->boolean('client_can_see_all')->default(false);
            $table->boolean('allow_self_close')->default(false);
            $table->unsignedInteger('task_counter')->default(0); // next task number, row-locked on increment
            $table->foreignId('created_by')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->enum('project_role', ['manager', 'member', 'stakeholder', 'viewer'])->default('member');
            $table->foreignId('added_by')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable(); // set on removal or exit; cleared on re-add

            $table->unique(['project_id', 'membership_id']);
            $table->index(['membership_id', 'left_at']);
        });

        Schema::create('project_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->enum('category', ['triage', 'todo', 'in_progress', 'done', 'cancelled']); // drives progress %
            $table->string('color', 7)->default('#6b7280');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_initial')->default(false);
            $table->boolean('show_on_board')->default(true);

            $table->index(['project_id', 'position']);
        });

        // Optional: when a project has no rows here, every move is allowed.
        Schema::create('status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_status_id')->constrained('project_statuses')->cascadeOnDelete();
            $table->foreignId('to_status_id')->constrained('project_statuses')->cascadeOnDelete();
            $table->string('required_permission', 100)->nullable(); // e.g. task.close

            $table->unique(['from_status_id', 'to_status_id']);
        });

        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('color', 7)->default('#6366f1');

            $table->unique(['project_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labels');
        Schema::dropIfExists('status_transitions');
        Schema::dropIfExists('project_statuses');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};
