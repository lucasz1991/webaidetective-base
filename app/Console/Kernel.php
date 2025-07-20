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
        // Schedule the shelf rentals check command to run at 9 AM, 4 PM, and 7 PM daily
        // $schedule->command('shelf:check-rentals')->cron('0 9,16,19 * * *');

        // Schedule the rental reminders command to run daily at 12:00 PM
        // $schedule->command('rental:send-reminders')->dailyAt('12:00');

        // Schedule the exports command to run monthly on the 1st at 2:00 AM
        // $schedule->command('exports:run')->monthlyOn(1, '02:00');
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
