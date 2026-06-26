<?php

declare(strict_types=1);

namespace ReactX\Worker\Tests\Unit;

use ReactX\Worker\Worker;

final class WorkerTestHelper
{
    public static function resetStaticState(): void
    {
        Worker::$command = '';
        Worker::$daemonize = false;
        Worker::$pidFile = '';
        Worker::$statusFile = '';
        Worker::$logFile = '';

        static::setProperty('gracefulStop', false);

        static::setProperty('masterPid', 0);
        static::setProperty('workers', []);
        static::setProperty('pidMap', []);
        static::setProperty('pidsToRestart', []);
        static::setProperty('idMap', []);
        static::setProperty('status', Worker::STATUS_INITIAL);
        static::setProperty('uiLengthData', []);
        static::setProperty('statisticsFile', '');
        static::setProperty('connectionsFile', '');
        static::setProperty('startFile', '');
        static::setProperty('globalStatistics', [
            'start_timestamp' => 0,
            'worker_exit_info' => [],
        ]);
    }

    public static function setStatus(int $status): void
    {
        static::setProperty('status', $status);
    }

    private static function setProperty(string $name, mixed $value): void
    {
        $ref = new \ReflectionClass(Worker::class);
        $prop = $ref->getProperty($name);
        $prop->setValue(null, $value);
    }
}
