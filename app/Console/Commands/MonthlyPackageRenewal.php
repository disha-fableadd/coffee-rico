<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ActivePackage;
use Carbon\Carbon;

class MonthlyPackageRenewal extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:monthly-renewal';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process monthly package renewals and reset usage counters';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting monthly package renewal process...');
        \Log::info('Starting monthly package renewal process...');
        // Get all active packages
        $activePackages = ActivePackage::where('status', 1)
            ->with('package')
            ->get();

        $renewedCount = 0;
        $expiredCount = 0;

        foreach ($activePackages as $activePackage) {
            $now = Carbon::now();
            $lastReset = $activePackage->lastMonthlyReset;

            // Check if it's time for monthly renewal (30 days since last reset or first time)
            $shouldRenew = false;

            if (!$lastReset) {
                // First time - set last reset to start date and reset counters
                $activePackage->update([
                    'lastMonthlyReset' => $activePackage->startDate,
                    'monthlyUsedMsgCount' => 0,
                    'monthlyUsedTemplateCount' => 0,
                    'monthlyUsedContactCount' => 0
                ]);
                $shouldRenew = true;
                $this->info("Initialized monthly tracking for package ID: {$activePackage->id} (User: {$activePackage->userId})");
            } else {
                // Check if 30 days have passed since last reset
                $daysSinceLastReset = $now->diffInDays($lastReset);
                if ($daysSinceLastReset >= 30) {
                    $shouldRenew = true;
                }
            }

            if ($shouldRenew && $lastReset) {
                // Check if package is still within overall validity period (1 year from start date)
                if ($now->lte($activePackage->endDate)) {
                    // Reset monthly counters for the new month
                    $activePackage->update([
                        'monthlyUsedMsgCount' => 0,
                        'monthlyUsedTemplateCount' => 0,
                        'monthlyUsedContactCount' => 0,
                        'lastMonthlyReset' => $now
                    ]);

                    $renewedCount++;
                    $this->info("Renewed monthly limits for package ID: {$activePackage->id} (User: {$activePackage->userId}) - Monthly reset completed");
                } else {
                    // Package has expired after 1 year - needs manual reactivation
                    $activePackage->update(['status' => 0]);
                    $expiredCount++;
                    $this->warn("Package expired after 1 year for ID: {$activePackage->id} (User: {$activePackage->userId}) - Manual reactivation required");
                }
            }
        }

        $this->info("Monthly renewal process completed:");
        $this->info("- Renewed packages: {$renewedCount}");
        $this->info("- Expired packages: {$expiredCount}");

        return 0;
    }
}
