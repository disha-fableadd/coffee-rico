<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bulk_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('userId')->constrained('users')->onDelete('cascade');
            $table->foreignId('templateId')->nullable()->constrained('templates')->onDelete('set null');
            $table->json('variables')->nullable();
            $table->json('headerVariables')->nullable();
            $table->json('contactIds')->nullable();
            $table->foreignId('contactId')->nullable()->constrained('contacts')->onDelete('set null');
            $table->timestamp('scheduleAt')->nullable();
            $table->enum('status', ['scheduled', 'sending', 'completed', 'failed'])->default('sending');
            $table->timestamp('sendingDate')->useCurrent();
            $table->json('sentStatus')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulk_messages');
    }
};
