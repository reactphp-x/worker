<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use ReactX\Worker\Worker;

$dir = getenv('WORKER_TEST_DIR') ?: sys_get_temp_dir();

Worker::$pidFile = $dir . '/test.pid';
Worker::$logFile = $dir . '/test.log';
Worker::$statusFile = $dir . '/test.status';

$markerFile = $dir . '/worker.pid';
$heartbeatFile = $dir . '/heartbeat';

$worker = new Worker(function (Worker $worker) use ($markerFile, $heartbeatFile): void {
    file_put_contents($markerFile, (string) posix_getpid());

    pcntl_signal(SIGALRM, static function () use ($heartbeatFile): void {
        file_put_contents($heartbeatFile, (string) time());
        pcntl_alarm(1);
    });
    pcntl_alarm(1);
});

$worker->name = 'test';
$worker->count = max(1, (int) (getenv('WORKER_TEST_COUNT') ?: 1));

Worker::runAll();
