<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Indexes for the dashboards and reports: they group tasks by assignee and project and logs by day. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['project_id', 'assignee_id'], 'tasks_project_assignee_index');
            $table->index(['assignee_id', 'due_date'], 'tasks_assignee_due_index');
        });

        Schema::table('work_logs', function (Blueprint $table) {
            $table->index(['organization_id', 'work_date'], 'work_logs_org_date_index');
        });

        Schema::table('sprint_task', function (Blueprint $table) {
            $table->index(['task_id', 'sprint_id'], 'sprint_task_task_sprint_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_project_assignee_index');
            $table->dropIndex('tasks_assignee_due_index');
        });
        Schema::table('work_logs', fn (Blueprint $table) => $table->dropIndex('work_logs_org_date_index'));
        Schema::table('sprint_task', fn (Blueprint $table) => $table->dropIndex('sprint_task_task_sprint_index'));
    }
};
