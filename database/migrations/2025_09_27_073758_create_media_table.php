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
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Original filename
            $table->string('filename'); // Stored filename
            $table->string('path'); // File path
            $table->string('url'); // Full URL
            $table->string('mime_type'); // MIME type
            $table->unsignedBigInteger('size'); // File size in bytes
            $table->string('extension'); // File extension
            $table->text('description')->nullable(); // Optional description
            $table->string('alt_text')->nullable(); // Alt text for accessibility
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
