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
        Schema::table('message_delivery_statuses', function (Blueprint $table) {
            // Add failure tracking fields
            $table->string('failure_reason')->nullable()->after('error_message');
            $table->string('failure_code')->nullable()->after('failure_reason');
            
            // Add index for faster lookups
            $table->index('failure_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_delivery_statuses', function (Blueprint $table) {
            $table->dropIndex(['failure_code']);
            $table->dropColumn(['failure_reason', 'failure_code']);
        });
    }
};



