<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('module', 50)->index();
            $table->string('label', 150);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete(); // null = default template
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->unsignedTinyInteger('level')->default(70);
            $table->enum('data_scope', ['own', 'team', 'department', 'organization'])->default('own');
            $table->boolean('is_external')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'slug']);
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        // Prerequisites for membership roles. The rest of phase 3 (hierarchy, periods, invitations) comes later.
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name', 150);
            $table->string('code', 20)->nullable();
            $table->unsignedBigInteger('head_membership_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('role_id')->constrained('roles');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('reports_to_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->enum('user_type', ['internal', 'external'])->default('internal');
            $table->string('employee_code', 50)->nullable();
            $table->string('designation', 100)->nullable();
            $table->enum('status', ['invited', 'active', 'exited'])->default('active');
            $table->date('joined_at')->nullable();
            $table->date('scheduled_exit_at')->nullable();
            $table->date('left_at')->nullable();
            $table->enum('exit_type', ['resigned', 'terminated', 'contract_ended'])->nullable();
            $table->text('exit_reason')->nullable();
            $table->foreignId('exited_by')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'user_id']);
            $table->unique(['organization_id', 'employee_code']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'reports_to_id']);
            $table->index(['organization_id', 'department_id']);
        });

        Schema::create('membership_permission_overrides', function (Blueprint $table) {
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->enum('effect', ['grant', 'revoke']);
            $table->foreignId('granted_by')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamps();

            $table->primary(['membership_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_permission_overrides');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
