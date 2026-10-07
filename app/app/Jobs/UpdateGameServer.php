<?php

namespace App\Jobs;

use App\Enums\BackupType;
use App\Services\AuditLogger;
use App\Services\BackupManager;
use App\Services\DockerManager;
use App\Services\GameServerUpdater;
use App\Services\RconClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpdateGameServer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        private readonly string $ip,
        private readonly ?string $branch = null,
    ) {}

    public function handle(
        RconClient $rcon,
        DockerManager $docker,
        BackupManager $backupManager,
        GameServerUpdater $updater,
    ): void {
        Cache::forget('server.pending_action:update');

        // 1. Create pre-update backup
        try {
            $result = $backupManager->createBackup(BackupType::PreUpdate, 'Automatic pre-update backup');
            Log::info('Pre-update backup created', ['backup' => $result['backup']->filename]);
        } catch (\Throwable $e) {
            Log::warning('Pre-update backup failed, proceeding with update', [
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Set branch override if requested
        if ($this->branch !== null) {
            $updater->setBranch($this->branch);
            Log::info('Steam branch set', ['branch' => $this->branch]);
        }

        // 3. Trigger force update flag
        $updater->triggerForceUpdate();

        // 4. Graceful shutdown via RCON
        try {
            $rcon->connect();
            $rcon->command('save');
            sleep(5);
            $rcon->command('quit');
            sleep(2);
        } catch (\Throwable $e) {
            Log::warning('RCON unavailable during update, proceeding with runtime stop', [
                'error' => $e->getMessage(),
            ]);
        }

        // 5. Stop container
        $docker->stopContainer(timeout: 30);

        AuditLogger::record(
            actor: 'system',
            action: 'server.update.executed',
            target: config('zomboid.runtime_name'),
            details: [
                'source' => 'scheduled_job',
                'branch' => $this->branch ?? $updater->getCurrentBranch(),
            ],
            ip: $this->ip,
        );

        // 6. Start the game runtime (startup script picks up the branch + update flag)
        $docker->startContainer();

        // 7. Wait for server to be ready (30 min timeout for SteamCMD downloads)
        WaitForServerReady::dispatch(
            'server.update.completed',
            'system',
            $this->ip,
            1800,
        );
    }
}
