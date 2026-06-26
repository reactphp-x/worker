# reactphp-x-worker

PHP 多进程 Master/Worker 模型，CLI 命令与 [Workerman](https://github.com/walkor/workerman) 保持一致。

本项目**只实现进程管理模型**：Master 进程 fork 子进程、监控退出、信号控制、守护进程、热重载等。不包含 Workerman 的网络监听、协议解析、连接管理等能力。

## 参考 Workerman

设计参考 Workerman 的 [Worker.php](https://github.com/walkor/workerman/blob/master/src/Worker.php) master/worker 模型：

| 能力 | Workerman | reactphp-x-worker |
|------|-----------|-------------------|
| Master/Worker 进程模型 | ✅ | ✅ |
| CLI：`start` / `stop` / `restart` / `reload` / `status` / `connections` | ✅ | ✅ |
| 守护进程 `-d`、优雅停止/重载 `-g` | ✅ | ✅ |
| PID / Status / Log 文件 | ✅ | ✅ |
| Socket 监听、协议、连接 | ✅ | ❌ |
| Event Loop（Select/Swoole 等） | ✅ | ❌ |

Worker 通过传入 **callable** 定义子进程逻辑，而不是 Workerman 的 `$onMessage` / `$onWorkerStart` 回调属性。

## 要求

- PHP >= 8.1
- 扩展：`pcntl`、`posix`（Linux / macOS）

## 安装

```bash
composer require reactphp-x/worker
```

## 快速开始

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ReactX\Worker\Worker;

$worker = new Worker(function (Worker $worker): void {
    echo sprintf("worker #%d (pid %d) started\n", $worker->id, posix_getpid());

    pcntl_signal(SIGALRM, static function () use ($worker): void {
        echo sprintf("worker #%d tick\n", $worker->id);
        pcntl_alarm(2);
    });
    pcntl_alarm(2);
});

$worker->name = 'demo';
$worker->count = 2;

Worker::runAll();
```

## CLI 命令

与 Workerman 用法一致：

```bash
php yourfile.php start          # DEBUG 模式启动
php yourfile.php start -d       # 守护进程模式
php yourfile.php stop           # 停止
php yourfile.php stop -g        # 优雅停止
php yourfile.php restart        # 重启
php yourfile.php restart -d -g  # 守护进程 + 优雅重启
php yourfile.php reload         # 热重载（重新加载 PHP 代码）
php yourfile.php reload -g      # 优雅热重载
php yourfile.php status         # 查看状态
php yourfile.php status -d      # 实时刷新状态
php yourfile.php connections    # 查看 worker 状态（简化版）
```

## Worker 配置

```php
$worker = new Worker(function (Worker $worker): void {
    // 子进程逻辑
});

$worker->name = 'my-worker';   // worker 名称
$worker->count = 4;            // 子进程数量
$worker->reloadable = true;    // 是否支持 reload
$worker->user = 'www-data';    // 运行用户（需 root）
$worker->group = 'www-data';   // 运行组

// 可选回调
$worker->onWorkerStop = function (Worker $worker): void { };
$worker->onWorkerReload = function (Worker $worker): void { };

Worker::$pidFile = '/path/to/custom.pid';
Worker::$logFile = '/path/to/custom.log';
Worker::$daemonize = false;
Worker::$stopTimeout = 2;

Worker::runAll();
```

## 运行时文件

默认在启动脚本同目录生成：

- `reactphp-x-worker.{script}.pid`
- `reactphp-x-worker.{script}.status`
- `reactphp-x-worker.log`

## 开发

```bash
composer install
composer test
```

示例：

```bash
php examples/demo.php start
php examples/demo.php status
php examples/demo.php stop
```

## License

MIT
