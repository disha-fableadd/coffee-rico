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
        Schema::create('active_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('userId')->constrained('users')->onDelete('cascade');
            $table->foreignId('packageId')->constrained('packages')->onDelete('cascade');
            $table->timestamp('startDate')->nullable();
            $table->timestamp('endDate')->nullable();
            $table->integer('day');
            $table->integer('status')->default(1); // 1 = active, 0 = expired
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('active_packages');
    }
};
