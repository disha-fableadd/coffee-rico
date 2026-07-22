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
        // Update existing packages to be monthly (30 days)
        DB::table('packages')->update(['day' => 30]);

        // Add monthly renewal tracking fields to active_packages
        Schema::table('active_packages', function (Blueprint $table) {
            $table->timestamp('lastMonthlyReset')->nullable()->after('usedMsgCount');
            $table->integer('monthlyUsedMsgCount')->default(0)->after('lastMonthlyReset');
            $table->integer('monthlyUsedTemplateCount')->default(0)->after('monthlyUsedMsgCount');
            $table->integer('monthlyUsedContactCount')->default(0)->after('monthlyUsedTemplateCount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('active_packages', function (Blueprint $table) {
            $table->dropColumn(['lastMonthlyReset', 'monthlyUsedMsgCount', 'monthlyUsedTemplateCount', 'monthlyUsedContactCount']);
        });
    }
};
