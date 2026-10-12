<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('avatar_path')->nullable()->after('password');
            $table->boolean('is_system_admin')->default(false)->after('avatar_path');
            $table->enum('status', ['active', 'locked', 'anonymized'])->default('active')->after('is_system_admin');
            $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
            $table->unsignedBigInteger('last_organization_id')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'avatar_path', 'is_system_admin', 'status', 'last_login_at', 'last_organization_id']);
        });
    }
};
