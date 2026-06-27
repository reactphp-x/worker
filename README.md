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
| Event Loop（Select/Swoole 等） | ✅ | 可选（[react/event-loop](https://github.com/reactphp/event-loop)） |

Worker 通过传入 **callable** 定义子进程逻辑，而不是 Workerman 的 `$onMessage` / `$onWorkerStart` 回调属性。

## 要求

- PHP >= 8.1
- 扩展：`pcntl`、`posix`（Linux / macOS）
- 可选：`react/event-loop`（在 Worker 中使用定时器、异步 I/O 时）

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

## Worker 保活与事件循环

每个 Worker 子进程执行完 handler 回调后，若进程尚未 shutdown，会进入 `waitUntilStop()` 保持存活：

| 场景 | 行为 |
|------|------|
| handler 中使用了 `React\EventLoop\Loop`（如 `addPeriodicTimer`） | 自动调用 `$loop->run()`，无需手动 `Loop::run()` |
| 未使用 React Loop | 每 100ms 调用 `pcntl_signal_dispatch()` 处理信号（配合 `pcntl_alarm` 等） |

安装 React Event Loop（可选）：

```bash
composer require react/event-loop
```

使用 React 定时器的示例：

```php
use React\EventLoop\Loop;
use ReactX\Worker\Worker;

$worker = new Worker(function (): void {
    Loop::addPeriodicTimer(1, static function (): void {
        echo "tick\n";
    });
    // 不需要 Loop::run()，框架会在 handler 返回后自动运行事件循环
});

Worker::runAll();
```

## 信号说明

Master 进程注册以下信号（与 Workerman 一致）：

| 信号 | CLI 触发 | 作用 |
|------|----------|------|
| `SIGINT` | `stop` | 快速停止 |
| `SIGQUIT` | `stop -g` | 优雅停止 |
| `SIGUSR1` | `reload` | 快速热重载 Worker |
| `SIGUSR2` | `reload -g` | 优雅热重载 Worker |
| `SIGTERM` / `SIGHUP` | `kill` 等 | 同快速停止 |

## 守护进程（`-d`）

`start -d` 启用守护进程模式，Master 通过 **双 fork + setsid** 脱离终端在后台运行：

1. 第一次 fork，父进程退出，Shell 立即返回
2. `setsid()` 创建新会话，脱离控制终端
3. 第二次 fork，避免重新获得控制终端

守护进程模式下标准输出重定向到 `Worker::$stdoutFile`（默认 `/dev/null`），日志写入 `Worker::$logFile`。

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

### 示例（`examples/demo.php`）

包含两个 Worker：

- **demo**：2 个子进程，用 `pcntl_alarm` 每 2 秒输出 tick
- **FileMonitor**：DEBUG 模式下监听 PHP 文件变更，自动向 Master 发送 `SIGUSR1` 触发热重载（`reloadable = false`，自身不参与 reload）

```bash
php examples/demo.php start          # DEBUG 模式，含文件热重载
php examples/demo.php status         # 查看进程与内存
php examples/demo.php reload         # 手动热重载
php examples/demo.php stop           # 停止
```

修改 `examples/app/test.php` 后，FileMonitor 会在约 1 秒内检测到变更并触发 reload。

## License

MIT
