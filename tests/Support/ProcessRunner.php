<?php

declare(strict_types=1);

namespace ReactX\Worker\Tests\Support;

final class ProcessRunner
{
    public function __construct(
        private readonly string $testDir,
        private readonly string $fixture = __DIR__ . '/../fixtures/test_worker.php',
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args, ?int $timeoutSeconds = 10): ProcessResult
    {
        $command = array_merge(['php', $this->fixture], $args);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            null,
            [
                'WORKER_TEST_DIR' => $this->testDir,
                'WORKER_TEST_COUNT' => (string) (getenv('WORKER_TEST_COUNT') ?: '1'),
            ],
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start process');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                break;
            }

            usleep(50_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return new ProcessResult($stdout, $stderr, $exitCode);
    }

    /** @param list<string> $args */
    public function startInBackground(array $args = ['start']): void
    {
        $command = implode(' ', array_map('escapeshellarg', array_merge(['php', $this->fixture], $args)));
        $env = 'WORKER_TEST_DIR=' . escapeshellarg($this->testDir)
            . ' WORKER_TEST_COUNT=' . escapeshellarg((string) (getenv('WORKER_TEST_COUNT') ?: '1'));

        exec($env . ' ' . $command . ' > /dev/null 2>&1 &');
    }

    public function waitUntil(callable $condition, float $timeoutSeconds = 5.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }
            usleep(50_000);
        }

        return false;
    }

    public function pidFile(): string
    {
        return $this->testDir . '/test.pid';
    }

    public function heartbeatFile(): string
    {
        return $this->testDir . '/heartbeat';
    }

    public function workerMarkerFile(): string
    {
        return $this->testDir . '/worker.pid';
    }

    public function stopIfRunning(): void
    {
        if (is_file($this->pidFile())) {
            $this->run(['stop'], 15);
        }
    }
}
