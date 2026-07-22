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
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('content')->nullable();
            $table->string('type')->default('text');
            $table->json('variables')->nullable();
            $table->json('components')->nullable();
            $table->string('language')->default('en_US');
            $table->string('category')->nullable();
            $table->enum('status', ['active', 'inactive', 'PENDING', 'APPROVED', 'REJECTED'])->default('active');
            $table->foreignId('userId')->nullable()->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
