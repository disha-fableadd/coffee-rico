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
        Schema::table('settings', function (Blueprint $table) {
            $table->integer('batch_size')->default(50)->after('isActive');
            $table->integer('batch_delay_seconds')->default(2)->after('batch_size');
            $table->boolean('enable_batch_processing')->default(true)->after('batch_delay_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['batch_size', 'batch_delay_seconds', 'enable_batch_processing']);
        });
    }
};
