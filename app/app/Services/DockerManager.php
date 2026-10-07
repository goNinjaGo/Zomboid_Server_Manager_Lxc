<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Controls the configured game-server runtime (Docker, host systemd, or LXC).
 * The historical class name remains for compatibility with existing callers.
 */
class DockerManager
{
    public function __construct(
        private readonly string $proxyUrl,
        private readonly string $containerName,
        private readonly string $runtime = 'docker',
        private readonly string $systemdService = 'pz-server',
        private readonly string $lxcManager = '/usr/local/sbin/zomboid-lxc-manager',
    ) {}

    /**
     * @return array{exists: bool, running: bool, status: string, health_status?: string|null, started_at?: string|null, finished_at?: string|null, restart_count?: int}
     */
    public function getContainerStatus(): array
    {
        if ($this->runtime === 'lxc') {
            return $this->getLxcStatus();
        }

        if ($this->runtime === 'systemd') {
            return $this->getSystemdStatus();
        }

        $response = $this->request('GET', "/containers/{$this->containerName}/json");

        if ($response === null) {
            return [
                'exists' => false,
                'running' => false,
                'status' => 'not_found',
            ];
        }

        $state = $response['State'] ?? [];

        return [
            'exists' => true,
            'running' => $state['Running'] ?? false,
            'status' => $state['Status'] ?? 'unknown',
            'health_status' => $state['Health']['Status'] ?? null,
            'started_at' => $state['StartedAt'] ?? null,
            'finished_at' => $state['FinishedAt'] ?? null,
            'restart_count' => $response['RestartCount'] ?? 0,
        ];
    }

    public function startContainer(): bool
    {
        if ($this->runtime === 'lxc') {
            return $this->runLxcManager(['start']);
        }

        if ($this->runtime === 'systemd') {
            return $this->runSystemctl('start');
        }

        $response = $this->request('POST', "/containers/{$this->containerName}/start");

        return $response !== null;
    }

    public function stopContainer(int $timeout = 30): bool
    {
        if ($this->runtime === 'lxc') {
            return $this->runLxcManager(['stop']);
        }

        if ($this->runtime === 'systemd') {
            return $this->runSystemctl('stop');
        }

        $response = $this->request('POST', "/containers/{$this->containerName}/stop", [
            'query' => ['t' => $timeout],
            'timeout' => $timeout + 15,
        ]);

        return $response !== null;
    }

    public function restartContainer(int $timeout = 30): bool
    {
        if ($this->runtime === 'lxc') {
            return $this->runLxcManager(['restart']);
        }

        if ($this->runtime === 'systemd') {
            return $this->runSystemctl('restart');
        }

        $response = $this->request('POST', "/containers/{$this->containerName}/restart", [
            'query' => ['t' => $timeout],
            'timeout' => $timeout + 30,
        ]);

        return $response !== null;
    }

    /**
     * @return string[]
     */
    public function getContainerLogs(int $tail = 100, ?string $since = null): array
    {
        if ($this->runtime === 'lxc') {
            return $this->getLxcLogs($tail, $since);
        }

        if ($this->runtime === 'systemd') {
            $service = $this->systemdService;
            $command = ['sudo', '-n', 'journalctl', '-u', $service, '-n', (string) max(1, min($tail, 1000)), '--no-pager', '-o', 'short-iso'];
            if ($since !== null && ctype_digit($since)) {
                $command[] = '--since=@'.$since;
            }
            $process = new Process($command);
            $process->setTimeout(120);
            $process->run();

            return $process->isSuccessful() ? array_values(array_filter(explode("\n", trim($process->getOutput())))) : [];
        }

        $query = [
            'stdout' => true,
            'stderr' => true,
            'tail' => $tail,
            'timestamps' => true,
        ];

        if ($since !== null) {
            $query['since'] = $since;
        }

        $response = $this->requestRaw('GET', "/containers/{$this->containerName}/logs", [
            'query' => $query,
        ]);

        if ($response === null) {
            return [];
        }

        return $this->parseLogOutput($response);
    }

    private function getSystemdStatus(): array
    {
        $service = $this->systemdService;
        $process = new Process(['sudo', '-n', 'systemctl', 'show', $service]);
        $process->run();

        if (! $process->isSuccessful()) {
            return ['exists' => false, 'running' => false, 'status' => 'not_found'];
        }

        $properties = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $properties[$key] = $value;
            }
        }

        $state = $properties['ActiveState'] ?? 'unknown';
        $startedAt = $properties['ActiveEnterTimestamp'] ?? '';

        return [
            'exists' => ($properties['LoadState'] ?? '') !== 'not-found',
            'running' => $state === 'active',
            'status' => $state === 'active' ? ($properties['SubState'] ?? 'running') : $state,
            'health_status' => $state === 'active' ? 'healthy' : null,
            'started_at' => $startedAt !== '' && ($timestamp = strtotime($startedAt)) !== false ? date(DATE_ATOM, $timestamp) : null,
            'finished_at' => null,
            'restart_count' => 0,
        ];
    }

    private function runSystemctl(string $action): bool
    {
        $service = $this->systemdService;
        $process = new Process(['sudo', '-n', 'systemctl', $action, $service]);
        $process->setTimeout(120);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @return array{exists: bool, running: bool, status: string, health_status: string|null, started_at: string|null, finished_at: null, restart_count: int}
     */
    private function getLxcStatus(): array
    {
        $process = $this->runLxcManager(['status']);
        if (! $process->isSuccessful()) {
            return ['exists' => false, 'running' => false, 'status' => 'not_found'];
        }

        $values = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $values[$key] = $value;
            }
        }

        $exists = ($values['exists'] ?? '0') === '1';
        $running = $exists && ($values['service'] ?? '') === 'active';
        $state = strtoupper($values['container_state'] ?? 'UNKNOWN');
        $status = $running ? 'running' : ($state === 'RUNNING' ? 'server_stopped' : strtolower($state));

        return [
            'exists' => $exists,
            'running' => $running,
            'status' => $status,
            'health_status' => $running ? 'healthy' : null,
            'started_at' => $values['started_at'] ?? null,
            'finished_at' => null,
            'restart_count' => 0,
        ];
    }

    /**
     * @return string[]
     */
    private function getLxcLogs(int $tail, ?string $since): array
    {
        $args = ['logs', (string) max(1, min($tail, 1000))];
        if ($since !== null && ctype_digit($since)) {
            $args[] = $since;
        }

        $process = $this->runLxcManager($args, 120);

        return $process->isSuccessful()
            ? array_values(array_filter(explode("\n", trim($process->getOutput()))))
            : [];
    }

    private function runLxcManager(array $arguments, int $timeout = 120): Process
    {
        $process = new Process(['sudo', '-n', $this->lxcManager, ...$arguments]);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, array $options = []): ?array
    {
        try {
            $timeout = $options['timeout'] ?? 30;

            $client = Http::baseUrl($this->proxyUrl)
                ->timeout($timeout)
                ->connectTimeout(5);

            $url = $path;
            if (isset($options['query'])) {
                $url .= '?'.http_build_query($options['query']);
            }

            $response = match (strtoupper($method)) {
                'GET' => $client->get($url),
                'POST' => $client->post($url, $options['body'] ?? []),
                'DELETE' => $client->delete($url),
                default => throw new RuntimeException("Unsupported HTTP method: {$method}"),
            };

            if ($response->status() === 404) {
                return null;
            }

            if ($response->status() === 204 || $response->status() === 304) {
                return [];
            }

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            return null;
        } catch (ConnectionException) {
            throw new RuntimeException("Cannot connect to Docker daemon at {$this->proxyUrl}");
        }
    }

    private function requestRaw(string $method, string $path, array $options = []): ?string
    {
        try {
            $client = Http::baseUrl($this->proxyUrl);

            $url = $path;
            if (isset($options['query'])) {
                $url .= '?'.http_build_query($options['query']);
            }

            $response = $client->get($url);

            if ($response->successful()) {
                return $response->body();
            }

            return null;
        } catch (ConnectionException) {
            return null;
        }
    }

    /**
     * @return string[]
     */
    private function parseLogOutput(string $raw): array
    {
        $lines = [];
        $offset = 0;
        $length = strlen($raw);

        while ($offset < $length) {
            if ($offset + 8 > $length) {
                $lines = array_merge($lines, array_filter(explode("\n", substr($raw, $offset))));

                break;
            }

            $header = unpack('Ctype/x3/Nsize', substr($raw, $offset, 8));

            if ($header === false || $header['size'] === 0) {
                $offset += 8;

                continue;
            }

            $frameSize = $header['size'];

            if ($offset + 8 + $frameSize > $length) {
                $lines[] = trim(substr($raw, $offset + 8));

                break;
            }

            $content = trim(substr($raw, $offset + 8, $frameSize));
            if ($content !== '') {
                $lines[] = $content;
            }

            $offset += 8 + $frameSize;
        }

        return $lines;
    }
}
