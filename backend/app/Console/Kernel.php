<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            $endpoint = config('services.image_search.local_clip_endpoint', env('LOCAL_CLIP_ENDPOINT'));
            if (empty($endpoint)) {
                return;
            }

            try {
                Http::timeout(5)->get($endpoint);
                Log::info('AI Space Ping: success', ['endpoint' => $endpoint]);
            } catch (\Throwable $e) {
                Log::warning('AI Space Ping: failed', [
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);
            }
        })->everyFifteenMinutes()->name('ping-hf-space')->onOneServer();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
