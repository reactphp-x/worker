<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ReactX\Worker\Worker;

$worker = new Worker(function (Worker $worker): void {
    echo sprintf("[%s] worker #%d (pid %d) started\n", date('H:i:s'), $worker->id, posix_getpid());

    pcntl_signal(SIGALRM, static function () use ($worker): void {
        echo sprintf("[%s] worker #%d tick\n", date('H:i:s'), $worker->id);
        pcntl_alarm(2);
    });
    pcntl_alarm(2);
});

$worker->name = 'demo';
$worker->count = 2;

Worker::runAll();
