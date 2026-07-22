<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update all packages to be 365 days (1 year) for yearly auto-renewal
        DB::table('packages')->update(['day' => 365]);

        // Update existing active packages to have 365 days duration
        DB::table('active_packages')->update([
            'day' => 365,
            'endDate' => DB::raw('DATE_ADD(startDate, INTERVAL 365 DAY)')
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert packages back to 30 days
        DB::table('packages')->update(['day' => 30]);

        // Revert active packages back to 30 days
        DB::table('active_packages')->update([
            'day' => 30,
            'endDate' => DB::raw('DATE_ADD(startDate, INTERVAL 30 DAY)')
        ]);
    }
};
