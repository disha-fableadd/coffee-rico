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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('whatsapp_id')->unique(); // WhatsApp conversation ID
            $table->string('phone_number'); // Contact phone number
            $table->string('contact_name')->nullable(); // Contact name if available
            $table->unsignedBigInteger('user_id'); // User who owns this conversation
            $table->enum('status', ['active', 'archived', 'blocked'])->default('active');
            $table->timestamp('last_message_at')->nullable(); // Last message timestamp
            $table->text('last_message_preview')->nullable(); // Preview of last message
            $table->boolean('is_unread')->default(false); // Has unread messages
            $table->json('metadata')->nullable(); // Additional metadata
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'status']);
            $table->index(['phone_number', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
