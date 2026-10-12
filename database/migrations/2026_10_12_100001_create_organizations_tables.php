<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('slug', 100)->unique();
            $table->string('industry')->nullable();
            $table->string('size')->nullable();
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('registration_no', 100)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('timezone', 50)->default('Asia/Dhaka');
            $table->string('date_format', 20)->default('d M Y');
            $table->json('working_days')->nullable();
            $table->enum('status', ['pending', 'info_requested', 'active', 'rejected', 'suspended'])->default('pending')->index();
            $table->unsignedInteger('user_limit')->nullable();
            $table->foreignId('applicant_user_id')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->enum('action', ['submitted', 'resubmitted', 'approved', 'rejected', 'info_requested', 'suspended', 'reactivated']);
            $table->text('note')->nullable();
            $table->foreignId('acted_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('organization_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->default('other'); // trade_license | other
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_documents');
        Schema::dropIfExists('organization_reviews');
        Schema::dropIfExists('organizations');
    }
};
