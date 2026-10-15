<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who did what to which record: admin and security actions, kept even when the record is later deleted.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete(); // null = platform level
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete(); // null = the system (scheduler)
            $table->string('action', 60);
            $table->nullableMorphs('auditable');
            $table->string('summary', 255)->nullable(); // a readable name of the record, so the line still makes sense after it is gone
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('actor_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
