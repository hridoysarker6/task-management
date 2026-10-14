<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete(); // the owner
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('view', 20)->default('list'); // which screen it belongs to: list | board
            $table->json('filters');
            $table->boolean('is_shared')->default(false); // private to the owner, or visible to everyone who can see the project
            $table->timestamps();

            $table->index(['project_id', 'is_shared']);
            $table->index(['membership_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
