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
        Schema::create('message_delivery_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bulk_message_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('status')->default('pending'); // pending, sent, delivered, read, failed
            $table->string('whatsapp_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('metadata')->nullable(); // Store additional data like API response
            $table->timestamps();

            $table->foreign('bulk_message_id')->references('id')->on('bulk_messages')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->index(['bulk_message_id', 'contact_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_delivery_statuses');
    }
};
