<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Package;
use App\Models\ActivePackage;

class CheckPackageData extends Command
{
    protected $signature = 'package:check-data';
    protected $description = 'Check current package and active package data';

    public function handle()
    {
        $this->info('=== PACKAGES ===');
        $packages = Package::all(['id', 'packageName', 'day']);
        foreach ($packages as $package) {
            $this->line("ID: {$package->id}, Name: {$package->packageName}, Days: {$package->day}");
        }

        $this->info('=== ACTIVE PACKAGES ===');
        $activePackages = ActivePackage::with('package')->get();
        foreach ($activePackages as $ap) {
            $this->line("ID: {$ap->id}, User: {$ap->userId}, Package: {$ap->package->packageName}");
            $this->line("  Days: {$ap->day}, Start: {$ap->startDate}, End: {$ap->endDate}");
            $this->line("  Status: {$ap->status}, LastReset: " . ($ap->lastMonthlyReset ?? 'NULL'));
            $this->line("  Monthly Used: {$ap->monthlyUsedMsgCount}");
            $this->line("---");
        }

        return 0;
    }
}
