<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('goal')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['planned', 'active', 'completed'])->default('planned'); // at most one active per project (checked in code)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('committed_points', 6, 1)->nullable(); // snapshot when started
            $table->decimal('completed_points', 6, 1)->nullable(); // snapshot when completed
            $table->foreignId('created_by')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status']);
            $table->index(['organization_id', 'status']);
        });

        // Which tasks were in which sprint, and what became of them. Reports (burndown, velocity) read this.
        Schema::create('sprint_task', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sprint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestamp('added_at');
            $table->timestamp('removed_at')->nullable();
            $table->decimal('points_at_start', 5, 1)->nullable();
            $table->enum('outcome', ['completed', 'carried_over', 'removed'])->nullable();

            $table->index(['sprint_id', 'task_id']);
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sprint_task');
        Schema::dropIfExists('sprints');
    }
};
