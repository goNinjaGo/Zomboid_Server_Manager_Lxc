<?php

namespace App\Console\Commands;

use App\Jobs\RestartGameServer;
use App\Jobs\SendServerWarning;
use App\Models\AutoRestartSetting;
use App\Services\AuditLogger;
use App\Services\DockerManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutoRestartCheck extends Command
{
    protected $signature = 'zomboid:auto-restart-check';

    protected $description = 'Check if an automatic server restart is due';

    public function handle(DockerManager $docker): int
    {
        $settings = AutoRestartSetting::instance();

        if (! $settings->enabled) {
            return self::SUCCESS;
        }

        $nextRestart = $settings->getNextRestartTime();

        if ($nextRestart === null) {
            return self::SUCCESS;
        }

        // Check if server is online
        $status = $docker->getContainerStatus();
        if (! $status['running']) {
            return self::SUCCESS;
        }

        // If a restart is already pending, check if it's overdue
        if (Cache::has('server.auto_restart.pending')) {
            if (now()->gte($nextRestart->copy()->addMinutes(5))) {
                Cache::forget('server.auto_restart.pending');
                Cache::forget('server.pending_action:restart');
                Log::warning('Auto-restart: pending restart was overdue, cleared caches');
            }

            return self::SUCCESS;
        }

        $tz = $settings->timezone ?? 'Asia/Tbilisi';
        $restartInTz = $nextRestart->copy()->setTimezone($tz);
        $dateKey = $restartInTz->format('Y-m-d');
        $timeKey = $restartInTz->format('H:i');

        $secondsUntilRestart = (int) max(0, now()->diffInSeconds($nextRestart, false));

        // Phase 1: Discord heads-up
        $discordWindow = $settings->discord_reminder_minutes * 60;
        $discordCacheKey = "server.auto_restart.discord_reminded:{$dateKey}:{$timeKey}";

        if ($secondsUntilRestart <= $discordWindow && $secondsUntilRestart > 0 && ! Cache::has($discordCacheKey)) {
            Cache::put($discordCacheKey, true, $secondsUntilRestart + 300);

            $minutesUntil = (int) ceil($secondsUntilRestart / 60);

            AuditLogger::record(
                actor: 'system',
                action: 'server.autorestart.upcoming',
                target: config('zomboid.runtime_name'),
                details: [
                    'restart_time' => $timeKey,
                    'timezone' => $tz,
                    'minutes_until' => $minutesUntil,
                    'message' => "Server restart at {$timeKey} ({$tz}) in {$minutesUntil} minutes",
                ],
            );

            Log::info("Auto-restart: Discord heads-up sent for {$timeKey} ({$tz}), {$minutesUntil} min away");
        }

        // Phase 2: Restart pipeline (in-game warnings + restart)
        $warningWindow = $settings->warning_minutes * 60;
        $triggerCacheKey = "server.auto_restart.triggered:{$dateKey}:{$timeKey}";

        if ($secondsUntilRestart <= $warningWindow && ! Cache::has($triggerCacheKey)) {
            Cache::put($triggerCacheKey, true, $secondsUntilRestart + 300);
            Cache::put('server.auto_restart.pending', true, $secondsUntilRestart + 300);
            Cache::put('server.pending_action:restart', true, $secondsUntilRestart + 300);

            // Dispatch the restart job with delay
            RestartGameServer::dispatch('127.0.0.1')
                ->delay(now()->addSeconds($secondsUntilRestart));

            // Dispatch countdown warnings
            if ($secondsUntilRestart > 0) {
                $warningMessage = $settings->warning_message ?? 'restart (automatic)';
                SendServerWarning::dispatchCountdownWarnings(
                    $secondsUntilRestart,
                    $warningMessage,
                    'server.pending_action:restart',
                );
            }

            AuditLogger::record(
                actor: 'system',
                action: 'server.autorestart.scheduled',
                target: config('zomboid.runtime_name'),
                details: [
                    'restart_time' => $timeKey,
                    'timezone' => $tz,
                    'countdown' => $secondsUntilRestart,
                ],
            );

            $this->info("Auto-restart scheduled in {$secondsUntilRestart} seconds (slot {$timeKey} {$tz}).");
        }

        return self::SUCCESS;
    }
}
