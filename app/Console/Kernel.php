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
        // Run portfolio snapshot daily at 00:05
        $schedule->command('portfolio:snapshot')->dailyAt('00:05');

        // Optional: add more scheduled commands here
        // e.g., $schedule->command('queue:work --tries=3')->everyMinute();
    }

    protected function commands(): void
    {
        // Load custom commands from app/Console/Commands
        $this->load(__DIR__ . '/Commands');

        // Include any console route commands
        require base_path('routes/console.php');
    }
}
