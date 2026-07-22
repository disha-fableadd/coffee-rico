<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ActivePackage;
use Carbon\Carbon;

class TestMonthlyRenewal extends Command
{
    protected $signature = 'package:test-renewal';
    protected $description = 'Test monthly renewal by forcing a reset';

    public function handle()
    {
        $this->info('Testing monthly renewal...');

        $activePackage = ActivePackage::where('status', 1)->first();

        if (!$activePackage) {
            $this->error('No active package found');
            return 1;
        }

        $this->info("Current package: {$activePackage->package->packageName}");
        $this->info("Last reset: " . ($activePackage->lastMonthlyReset ?? 'NULL'));
        $this->info("Monthly used: {$activePackage->monthlyUsedMsgCount}");
        $this->info("Start date: {$activePackage->startDate}");
        $this->info("End date: {$activePackage->endDate}");

        // Force a renewal by setting lastMonthlyReset to 31 days ago
        $fakeLastReset = Carbon::now()->subDays(31);
        $activePackage->update(['lastMonthlyReset' => $fakeLastReset]);

        $this->info("Set lastMonthlyReset to: {$fakeLastReset}");

        // Now run the renewal command
        $this->call('package:monthly-renewal');

        // Check the result
        $activePackage->refresh();
        $this->info("After renewal:");
        $this->info("Last reset: " . ($activePackage->lastMonthlyReset ?? 'NULL'));
        $this->info("Monthly used: {$activePackage->monthlyUsedMsgCount}");

        return 0;
    }
}
