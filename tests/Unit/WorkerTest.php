<?php

declare(strict_types=1);

namespace ReactX\Worker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReactX\Worker\Worker;

final class WorkerTest extends TestCase
{
    protected function tearDown(): void
    {
        WorkerTestHelper::resetStaticState();
    }

    public function testVersionConstant(): void
    {
        $this->assertSame('1.0.0', Worker::VERSION);
    }

    public function testGetUiColumns(): void
    {
        $this->assertSame(
            [
                'user' => 'user',
                'worker' => 'name',
                'count' => 'count',
                'state' => 'statusState',
            ],
            Worker::getUiColumns(),
        );
    }

    public function testGetArgvMergesCommandString(): void
    {
        Worker::$command = 'status -d';
        global $argv;
        $savedArgv = $argv;
        $argv = ['test_worker.php', 'start'];

        try {
            $this->assertSame(
                ['test_worker.php', 'start', 'status', '-d'],
                Worker::getArgv(),
            );
        } finally {
            $argv = $savedArgv;
            Worker::$command = '';
        }
    }

    public function testWorkerInstanceDefaults(): void
    {
        $handler = static function (): void {
        };

        $worker = new Worker($handler);

        $this->assertSame('none', $worker->name);
        $this->assertSame(1, $worker->count);
        $this->assertTrue($worker->reloadable);
        $this->assertSame($handler, $worker->handler);
        $this->assertSame(0, $worker->id);
        $this->assertFalse($worker->stopping);
    }

    public function testIsRunningReflectsStatus(): void
    {
        $this->assertFalse(Worker::isRunning());
        WorkerTestHelper::setStatus(Worker::STATUS_STARTING);
        $this->assertTrue(Worker::isRunning());
    }
}
