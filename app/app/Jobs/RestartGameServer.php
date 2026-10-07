<?php

namespace App\Jobs;

use App\Services\AuditLogger;
use App\Services\DockerManager;
use App\Services\RconClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RestartGameServer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        private readonly string $ip,
    ) {}

    public function handle(RconClient $rcon, DockerManager $docker): void
    {
        Cache::forget('server.pending_action:restart');

        try {
            $rcon->connect();
            $rcon->command('save');
            sleep(5);
            $rcon->command('quit');
        } catch (\Throwable $e) {
            Log::warning('RCON unavailable during scheduled restart, proceeding with runtime restart', [
                'error' => $e->getMessage(),
            ]);
        }

        AuditLogger::record(
            actor: 'system',
            action: 'server.restart.executed',
            target: config('zomboid.runtime_name'),
            details: ['source' => 'scheduled_job'],
            ip: $this->ip,
        );

        $docker->restartContainer(timeout: 30);

        WaitForServerReady::dispatch(
            'server.restart.completed',
            'system',
            $this->ip,
        );
    }
}
