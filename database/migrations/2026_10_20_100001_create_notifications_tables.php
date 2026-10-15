<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's standard table. In-app notifications belong to a membership (a person in one company),
        // or to a user for platform-level ones (System Admins have no membership).
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_unread_index');
        });

        // Only the choices a person changed are stored; the rest follow the defaults in NotificationEvents.
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->string('event', 50);
            $table->boolean('via_mail');
            $table->boolean('via_app');
            $table->timestamps();

            $table->unique(['membership_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
