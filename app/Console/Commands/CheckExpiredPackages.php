<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ActivePackage;
use Carbon\Carbon;

class CheckExpiredPackages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:check-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for packages that have expired after 1 year and need manual reactivation';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for expired packages that need manual reactivation...');

        // Get packages that have passed their end date but are still marked as active
        $expiredPackages = ActivePackage::where('status', 1)
            ->where('endDate', '<', now())
            ->with(['package', 'user'])
            ->get();

        $expiredCount = 0;

        foreach ($expiredPackages as $package) {
            // Mark as expired
            $package->update(['status' => 0]);
            $expiredCount++;

            $this->warn("Package expired after 1 year:");
            $this->warn("- Package ID: {$package->id}");
            $this->warn("- User: {$package->user->name} (ID: {$package->userId})");
            $this->warn("- Package: {$package->package->packageName}");
            $this->warn("- Started: {$package->startDate}");
            $this->warn("- Expired: {$package->endDate}");
            $this->warn("- Status: Manual reactivation required");
            $this->warn("---");
        }

        if ($expiredCount > 0) {
            $this->error("Found {$expiredCount} packages that expired after 1 year and need manual reactivation.");
        } else {
            $this->info("No packages found that need manual reactivation.");
        }

        // Also check for packages that will expire soon (within 7 days)
        $soonToExpire = ActivePackage::where('status', 1)
            ->whereBetween('endDate', [now(), now()->addDays(7)])
            ->with(['package', 'user'])
            ->get();

        if ($soonToExpire->count() > 0) {
            $this->warn("Packages expiring soon (within 7 days):");
            foreach ($soonToExpire as $package) {
                $daysLeft = now()->diffInDays($package->endDate);
                $this->warn("- Package ID: {$package->id}, User: {$package->user->name}, Expires in: {$daysLeft} days");
            }
        }

        return 0;
    }
}
