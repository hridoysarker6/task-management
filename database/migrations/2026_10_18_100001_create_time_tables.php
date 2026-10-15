<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One per person per week (weeks start on Monday). It is what gets submitted and approved.
        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('memberships');
            $table->date('week_start');
            $table->unsignedInteger('total_min')->default(0); // cached sum of the week's work logs
            $table->enum('status', ['draft', 'submitted', 'approved', 'returned'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['membership_id', 'week_start']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'week_start']);
        });

        Schema::create('work_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('memberships');
            $table->foreignId('timesheet_id')->nullable()->constrained()->nullOnDelete(); // attached to the week it falls in
            $table->date('work_date');
            $table->unsignedInteger('minutes');
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['membership_id', 'work_date']);
            $table->index('task_id');
            $table->index('timesheet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_logs');
        Schema::dropIfExists('timesheets');
    }
};
