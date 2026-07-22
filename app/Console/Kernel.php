<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Process scheduled bulk messages every minute
        $schedule->command('bulk:process-scheduled-messages')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Process pending bulk messages every minute in batches to avoid timeouts
        // Increased limit to 100 messages per minute for faster processing
        $schedule->command('bulk:process-pending-messages --limit=100')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Process monthly package renewals daily at midnight
        $schedule->command('package:monthly-renewal')
            ->dailyAt('00:00')
            ->withoutOverlapping();

        // Check for expired packages that need manual reactivation
        $schedule->command('package:check-expired')
            ->dailyAt('01:00')
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
