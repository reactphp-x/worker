<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ReactX\Worker\Worker;
use React\EventLoop\Loop;

$worker = new Worker(function (Worker $worker): void {
    require __DIR__ . '/app/test.php';
    echo sprintf("[%s] worker #%d (pid %d) started\n", date('H:i:s'), $worker->id, posix_getpid());

    pcntl_signal(SIGALRM, static function () use ($worker): void {
        echo sprintf("[%s] worker #%d tick\n", date('H:i:s'), $worker->id);
        pcntl_alarm(2);
    });
    pcntl_alarm(2);
});

$worker->name = 'demo';
$worker->count = 2;



$monitor_dir = realpath(__DIR__);
$worker = new Worker(function() use ($monitor_dir): void {
//     global $monitor_dir;
    // watch files only in debug mode
    if(!Worker::$daemonize) {
        echo sprintf("[%s] debug mode, check files change\n", date('H:i:s'));
        // chek mtime of files per second 
        Loop::addPeriodicTimer(1, function() use ($monitor_dir): void {
            echo sprintf("[%s] check files change\n", date('H:i:s'));
            try {
                check_files_change($monitor_dir);
            } catch (Throwable $e) {
                echo sprintf("[%s] check files change error: %s\n", date('H:i:s'), $e->getMessage());
            }
        });
    } else {
        echo sprintf("[%s] daemon mode, skip check files change\n", date('H:i:s'));
    }
});
$worker->name = 'FileMonitor';
$worker->reloadable = false;
$last_mtime = time();


// check files func
function check_files_change($monitor_dir)
{
    global $last_mtime;
    // recursive traversal directory
    $dir_iterator = new RecursiveDirectoryIterator($monitor_dir);
    $iterator = new RecursiveIteratorIterator($dir_iterator);
    foreach ($iterator as $file)
    {
        // only check php files
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        // check mtime
        if($last_mtime < $file->getMTime())
        {
            echo $file." update and reload\n";
            // send SIGUSR1 signal to master process for reload
            posix_kill(posix_getppid(), SIGUSR1);
            $last_mtime = $file->getMTime();
            break;
        }
    }
}


Worker::runAll();
