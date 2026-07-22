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
        Schema::table('bulk_messages', function (Blueprint $table) {
            // Add 'pending' to the allowed enum values
            $table->enum('status', [
                'scheduled',
                'sending',
                'processing',
                'pending',
                'completed',
                'completed_with_errors',
                'failed',
            ])->default('sending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulk_messages', function (Blueprint $table) {
            // Revert to previous enum values without 'pending'
            $table->enum('status', [
                'scheduled',
                'sending',
                'processing',
                'completed',
                'completed_with_errors',
                'failed',
            ])->default('sending')->change();
        });
    }
};


