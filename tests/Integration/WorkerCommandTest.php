<?php

declare(strict_types=1);

namespace ReactX\Worker\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReactX\Worker\Tests\Support\ProcessRunner;

/**
 * @requires OS Linux|Darwin
 * @requires extension pcntl
 * @requires extension posix
 */
final class WorkerCommandTest extends TestCase
{
    private string $testDir;
    private ProcessRunner $runner;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/reactphp-x-worker-' . uniqid('', true);
        mkdir($this->testDir, 0777, true);
        $this->runner = new ProcessRunner($this->testDir);
    }

    protected function tearDown(): void
    {
        $this->runner->stopIfRunning();
        $this->removeDir($this->testDir);
    }

    public function testMissingCommandPrintsUsage(): void
    {
        $result = $this->runner->run([]);

        $this->assertStringContainsString('Usage: php yourfile <command>', $result->output());
    }

    public function testStopWhenNotRunning(): void
    {
        $result = $this->runner->run(['stop']);

        $this->assertStringContainsString('not run', $result->output());
    }

    public function testStartStopLifecycle(): void
    {
        $this->runner->startInBackground(['start']);

        $this->assertTrue(
            $this->runner->waitUntil(fn (): bool => is_file($this->runner->pidFile())),
            'pid file was not created',
        );

        $masterPid = (int) file_get_contents($this->runner->pidFile());
        $this->assertGreaterThan(0, $masterPid);

        $this->assertTrue(
            $this->runner->waitUntil(fn (): bool => is_file($this->runner->heartbeatFile())),
            'worker heartbeat was not written',
        );

        $stop = $this->runner->run(['stop'], 15);
        $this->assertStringContainsString('stop success', $stop->output());
        $this->assertSame(0, $stop->exitCode);
        $this->assertFileDoesNotExist($this->runner->pidFile());
    }

    public function testStartTwiceReportsAlreadyRunning(): void
    {
        $this->runner->startInBackground(['start']);
        $this->assertTrue($this->runner->waitUntil(fn (): bool => is_file($this->runner->pidFile())));

        $second = $this->runner->run(['start']);
        $this->assertStringContainsString('already running', $second->output());
    }

    public function testStatusShowsProcessInfo(): void
    {
        putenv('WORKER_TEST_COUNT=2');
        $this->runner = new ProcessRunner($this->testDir);

        $this->runner->startInBackground(['start']);
        $this->assertTrue($this->runner->waitUntil(fn (): bool => is_file($this->runner->heartbeatFile())));

        $status = $this->runner->run(['status'], 15);
        $this->assertSame(0, $status->exitCode);
        $this->assertStringContainsString('PROCESS STATUS', $status->output());
        $this->assertStringContainsString('test', $status->output());

        putenv('WORKER_TEST_COUNT');
    }

    public function testReloadRestartsWorkers(): void
    {
        $this->runner->startInBackground(['start']);
        $this->assertTrue($this->runner->waitUntil(fn (): bool => is_file($this->runner->workerMarkerFile())));

        $pidBefore = (int) file_get_contents($this->runner->workerMarkerFile());

        $reload = $this->runner->run(['reload'], 15);
        $this->assertSame(0, $reload->exitCode);

        $this->assertTrue(
            $this->runner->waitUntil(function () use ($pidBefore): bool {
                if (!is_file($this->runner->workerMarkerFile())) {
                    return false;
                }

                return (int) file_get_contents($this->runner->workerMarkerFile()) !== $pidBefore;
            }, 10.0),
            'worker pid did not change after reload',
        );
    }

    public function testConnectionsCommand(): void
    {
        $this->runner->startInBackground(['start']);
        $this->assertTrue($this->runner->waitUntil(fn (): bool => is_file($this->runner->pidFile())));

        $result = $this->runner->run(['connections'], 15);
        $this->assertSame(0, $result->exitCode);
        $this->assertStringContainsString('CONNECTION STATUS', $result->output());
        $this->assertStringContainsString('test', $result->output());
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
