<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Closure table: every ancestor/descendant pair of the reporting tree (depth 0 = the person themself).
        Schema::create('membership_hierarchy', function (Blueprint $table) {
            $table->foreignId('ancestor_id')->constrained('memberships')->cascadeOnDelete();
            $table->foreignId('descendant_id')->constrained('memberships')->cascadeOnDelete();
            $table->unsignedTinyInteger('depth');

            $table->primary(['ancestor_id', 'descendant_id']);
            $table->index('descendant_id');
        });

        Schema::create('employment_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->date('joined_at');
            $table->date('left_at')->nullable(); // null = current period
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('reports_to_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->string('designation', 100)->nullable();
            $table->enum('exit_type', ['resigned', 'terminated', 'contract_ended'])->nullable();
            $table->text('exit_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamps();

            $table->index(['membership_id', 'joined_at']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email', 191)->index();
            $table->string('name', 150)->nullable(); // optional; pre-fills the accept form (set by bulk import)
            $table->foreignId('role_id')->constrained('roles');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('reports_to_id')->nullable()->constrained('memberships')->nullOnDelete();
            // Bulk import can name a manager who has not joined yet; resolved when the invitation is accepted.
            $table->string('reports_to_email', 191)->nullable();
            $table->enum('user_type', ['internal', 'external'])->default('internal');
            $table->string('designation', 100)->nullable();
            $table->string('employee_code', 50)->nullable();
            $table->foreignId('rehire_membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name', 150);
            $table->foreignId('lead_membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->primary(['team_id', 'membership_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('employment_periods');
        Schema::dropIfExists('membership_hierarchy');
    }
};
