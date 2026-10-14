<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete(); // denormalized for scoping
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number'); // with the project key: ECP-42
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->nullOnDelete(); // epic > story > subtask
            $table->enum('type', ['epic', 'story', 'task', 'bug', 'subtask'])->default('task');
            $table->string('title', 255);
            $table->longText('description')->nullable(); // sanitized HTML
            $table->foreignId('status_id')->constrained('project_statuses');
            $table->unsignedTinyInteger('priority')->default(3); // 1 lowest ... 5 highest
            $table->foreignId('reporter_id')->constrained('memberships');
            $table->foreignId('assignee_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->unsignedBigInteger('sprint_id')->nullable()->index(); // constrained once sprints exist (phase 7)
            $table->decimal('story_points', 5, 1)->nullable();
            $table->unsignedInteger('original_estimate_min')->nullable();
            $table->unsignedInteger('remaining_estimate_min')->nullable();
            $table->unsignedInteger('time_spent_min')->default(0); // cached sum of work logs
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('board_position', 20, 10)->default(0); // order inside a column
            $table->decimal('backlog_rank', 20, 10)->default(0); // order in the backlog
            $table->text('resolution_note')->nullable(); // rejection / cancel reason
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('memberships')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status_id', 'board_position']);
            $table->index(['organization_id', 'assignee_id', 'status_id']);
            $table->index(['organization_id', 'reporter_id']);
            $table->index('parent_id');
            $table->index(['organization_id', 'due_date']);
            $table->index(['organization_id', 'completed_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('tasks', fn (Blueprint $table) => $table->fullText('title'));
        }

        Schema::create('task_watchers', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_id', 'membership_id']);
            $table->index('membership_id');
        });

        Schema::create('task_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('target_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->enum('type', ['blocks', 'relates_to', 'duplicates', 'clones']); // "is blocked by" etc. are derived when reading
            $table->foreignId('created_by')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_task_id', 'target_task_id', 'type']);
            $table->index('target_task_id');
        });

        Schema::create('label_task', function (Blueprint $table) {
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->primary(['label_id', 'task_id']);
            $table->index('task_id');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('memberships'); // author
            $table->text('body');
            $table->boolean('is_internal')->default(false); // hidden from external stakeholders
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['task_id', 'created_at']);
        });

        Schema::create('comment_mentions', function (Blueprint $table) {
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->primary(['comment_id', 'membership_id']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('attachable_type', 100);
            $table->unsignedBigInteger('attachable_id');
            $table->foreignId('membership_id')->constrained('memberships'); // uploader
            $table->string('disk', 30)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['attachable_type', 'attachable_id']);
        });

        Schema::create('task_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->nullOnDelete(); // null = system
            $table->string('event', 40); // created, updated, status_changed, assigned, commented, attachment_added, linked, ...
            $table->string('field', 50)->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activities');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('comment_mentions');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('label_task');
        Schema::dropIfExists('task_links');
        Schema::dropIfExists('task_watchers');
        Schema::dropIfExists('tasks');
    }
};
