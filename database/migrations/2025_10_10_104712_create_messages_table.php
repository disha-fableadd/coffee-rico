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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id'); // Reference to conversation
            $table->string('whatsapp_message_id')->unique(); // WhatsApp message ID
            $table->enum('direction', ['inbound', 'outbound']); // Message direction
            $table->enum('type', ['text', 'image', 'video', 'audio', 'document', 'location', 'contact', 'sticker'])->default('text');
            $table->text('content'); // Message content
            $table->string('media_url')->nullable(); // Media URL if applicable
            $table->string('media_type')->nullable(); // Media MIME type
            $table->string('media_filename')->nullable(); // Media filename
            $table->json('metadata')->nullable(); // Additional message metadata
            $table->enum('status', ['sent', 'delivered', 'read', 'failed'])->default('sent');
            $table->timestamp('whatsapp_timestamp'); // WhatsApp timestamp
            $table->boolean('is_read')->default(false); // Is message read by user
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            $table->index(['conversation_id', 'direction']);
            $table->index(['whatsapp_timestamp']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
