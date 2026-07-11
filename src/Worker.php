<?php

declare(strict_types=1);

namespace ReactX\Worker;

use RuntimeException;
use Stringable;
use Throwable;

use function cli_set_process_title;
use function fclose;
use function feof;
use function fflush;
use function flock;
use function fopen;
use function fwrite;
use function get_resource_type;
use function is_file;
use function is_readable;
use function is_resource;
use function stream_isatty;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_UN;
use const PHP_SAPI;
use const STDOUT;

/**
 * Master/worker process model for PHP CLI (start/stop/reload/status).
 *
 * Worker accepts a callable that runs in each child process.
 */
class Worker
{
    public const VERSION = '1.0.0';

    public const STATUS_INITIAL = 0;
    public const STATUS_STARTING = 1;
    public const STATUS_RUNNING = 2;
    public const STATUS_SHUTDOWN = 4;
    public const STATUS_RELOADING = 8;

    public const UI_SAFE_LENGTH = 4;

    public int $id = 0;
    public string $name = 'none';
    public int $count = 1;
    public string $user = '';
    public string $group = '';
    public bool $reloadable = true;

    /** @var ?callable(self): void */
    public $handler = null;

    /** @var ?callable(self): void */
    public $onWorkerStop = null;

    /** @var ?callable(self): void */
    public $onWorkerReload = null;

    public bool $stopping = false;

    public static bool $daemonize = false;
    /** @var resource */
    public static $outputStream;
    public static string $stdoutFile = '/dev/null';
    public static string $pidFile = '';
    public static string $statusFile = '';
    public static string $logFile = '';
    public static int $logFileMaxSize = 10_485_760;
    public static int $stopTimeout = 2;
    public static int $gracefulStopTimeout = 30;
    public static string $command = '';

    /** @var ?callable(): void */
    public static $onMasterReload = null;

    /** @var ?callable(): void */
    public static $onMasterStop = null;

    /** @var ?callable(self, int, int): void */
    public static $onWorkerExit = null;

    protected static int $masterPid = 0;
    protected static array $workers = [];
    protected static array $pidMap = [];
    protected static array $pidsToRestart = [];
    protected static array $idMap = [];
    protected static int $status = self::STATUS_INITIAL;
    protected static array $uiLengthData = [];
    protected static string $statisticsFile = '';
    protected static string $connectionsFile = '';
    protected static string $startFile = '';
    protected static bool $gracefulStop = false;
    protected static int $shutdownStartedAt = 0;
    protected static bool $outputDecorated;
    protected static array $globalStatistics = [
        'start_timestamp' => 0,
        'worker_exit_info' => [],
    ];

    protected ?string $workerId = null;

    /** @var object */
    protected object $context;

    /**
     * @param callable(self): void|null $handler Runs in each child worker process.
     */
    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler;
        $this->workerId = spl_object_hash($this);
        $this->context = (object) [
            'statusState' => '<g> [OK] </g>',
        ];
        static::$workers[$this->workerId] = $this;
        static::$pidMap[$this->workerId] = [];
    }

    public function setListen(string $listen): void
    {
        $this->context->listen = $listen;
    }

    /** @return object{connection_count: int, send_fail: int, total_request: int} */
    public function statistics(): object
    {
        if (isset($this->context->statistics) && is_object($this->context->statistics)) {
            return $this->context->statistics;
        }

        $this->context->statistics = (object) [
            'connection_count' => 0,
            'send_fail' => 0,
            'total_request' => 0,
        ];

        return $this->context->statistics;
    }

    /** @param list<string> $lines */
    public function setConnectionStatusLines(array $lines): void
    {
        $this->context->connectionStatusLines = $lines;
    }

    public static function runAll(): void
    {
        try {
            static::checkSapiEnv();
            static::initStdOut();
            static::init();
            static::parseCommand();
            static::lock();
            static::daemonize();
            static::initWorkers();
            static::installSignal();
            static::saveMasterPid();
            static::lock(LOCK_UN);
            static::displayUI();
            static::forkWorkers();
            static::resetStd();
            static::monitorWorkers();
        } catch (Throwable $e) {
            static::log($e);
        }
    }

    protected static function checkSapiEnv(): void
    {
        if (!in_array(PHP_SAPI, ['cli', 'micro'], true)) {
            exit("Only run in command line mode" . PHP_EOL);
        }

        if (DIRECTORY_SEPARATOR === '/') {
            foreach (['pcntl', 'posix'] as $name) {
                if (!extension_loaded($name)) {
                    exit("Please install $name extension" . PHP_EOL);
                }
            }
        }
    }

    protected static function initStdOut(): void
    {
        $defaultStream = static fn () => defined('STDOUT') ? STDOUT : (@fopen('php://stdout', 'w') ?: fopen('php://output', 'w'));
        static::$outputStream ??= $defaultStream();
        if (!is_resource(self::$outputStream) || get_resource_type(self::$outputStream) !== 'stream') {
            static::$outputStream = $defaultStream();
        }
        static::$outputDecorated ??= self::hasColorSupport();
    }

    private static function hasColorSupport(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        if (getenv('TERM_PROGRAM') === 'Hyper') {
            return true;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return (function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(self::$outputStream))
                || getenv('ANSICON') !== false
                || getenv('ConEmuANSI') === 'ON'
                || getenv('TERM') === 'xterm';
        }
        return stream_isatty(self::$outputStream);
    }

    protected static function init(): void
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        static::$startFile = static::$startFile ?: end($backtrace)['file'];
        $startFilePrefix = basename(static::$startFile);
        $startFileDir = dirname(static::$startFile);

        static::$pidFile = static::$pidFile ?: sprintf('%s/reactphp-x-worker.%s.pid', $startFileDir, $startFilePrefix);
        static::$statusFile = static::$statusFile ?: sprintf('%s/reactphp-x-worker.%s.status', $startFileDir, $startFilePrefix);
        static::$statisticsFile = static::$statisticsFile ?: static::$statusFile;
        static::$connectionsFile = static::$connectionsFile ?: static::$statusFile . '.connection';
        static::$logFile = static::$logFile ?: sprintf('%s/reactphp-x-worker.log', $startFileDir);

        if (static::$logFile !== '/dev/null' && !is_file(static::$logFile) && !str_contains(static::$logFile, '://')) {
            if (!is_dir(dirname(static::$logFile))) {
                mkdir(dirname(static::$logFile), 0777, true);
            }
            touch(static::$logFile);
            chmod(static::$logFile, 0644);
        }

        static::$status = static::STATUS_STARTING;
        static::$globalStatistics['start_timestamp'] = time();
        static::setProcessTitle('reactphp-x-worker: master process  start_file=' . static::$startFile);
        static::initId();
    }

    protected static function lock(int $flag = LOCK_EX): void
    {
        static $fd;
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        $lockFile = static::$pidFile . '.lock';
        $fd = $fd ?: fopen($lockFile, 'a+');
        if ($fd) {
            flock($fd, $flag);
            if ($flag === LOCK_UN) {
                fclose($fd);
                $fd = null;
                clearstatcache();
                if (is_file($lockFile)) {
                    unlink($lockFile);
                }
            }
        }
    }

    protected static function initWorkers(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        foreach (static::$workers as $worker) {
            if ($worker->name === 'none' || $worker->name === '') {
                $worker->name = 'none';
            }
            if ($worker->user === '') {
                $worker->user = static::getCurrentUser();
            }

            foreach (static::getUiColumns() as $columnName => $prop) {
                $value = (string) ($worker->$prop ?? $worker->context->$prop ?? '');
                $key = 'max' . ucfirst(strtolower($columnName)) . 'NameLength';
                static::$uiLengthData[$key] = max(static::$uiLengthData[$key] ?? 2 * static::UI_SAFE_LENGTH, strlen($value));
            }

            $listen = static::getWorkerListenName($worker);
            static::$uiLengthData['maxListenNameLength'] = max(
                static::$uiLengthData['maxListenNameLength'] ?? 2 * static::UI_SAFE_LENGTH,
                strlen($listen),
            );
        }
    }

    protected static function initId(): void
    {
        foreach (static::$workers as $workerId => $worker) {
            $newIdMap = [];
            $worker->count = max($worker->count, 1);
            for ($key = 0; $key < $worker->count; $key++) {
                $newIdMap[$key] = static::$idMap[$workerId][$key] ?? 0;
            }
            static::$idMap[$workerId] = $newIdMap;
        }
    }

    protected static function getCurrentUser(): string
    {
        $userInfo = posix_getpwuid(posix_getuid());
        return $userInfo['name'] ?? 'unknown';
    }

    protected static function displayUI(): void
    {
        $tmpArgv = static::getArgv();
        if (in_array('-q', $tmpArgv, true)) {
            return;
        }

        $lineVersion = static::getVersionLine();
        if (DIRECTORY_SEPARATOR !== '/') {
            static::safeEcho("---------------------------------------------- REACTPHP-X-WORKER -----------------------------------------------\r\n");
            static::safeEcho($lineVersion);
            static::safeEcho("----------------------------------------------- WORKERS ------------------------------------------------\r\n");
            static::safeEcho("worker                                          listen                              processes   status\r\n");
            return;
        }

        !defined('LINE_VERSION_LENGTH') && define('LINE_VERSION_LENGTH', strlen($lineVersion));
        $totalLength = static::getSingleLineTotalLength();
        $lineOne = '<n>' . str_pad('<w> REACTPHP-X-WORKER </w>', $totalLength + strlen('<w></w>'), '-', STR_PAD_BOTH) . '</n>' . PHP_EOL;
        $lineTwo = str_pad('<w> WORKERS </w>', $totalLength + strlen('<w></w>'), '-', STR_PAD_BOTH) . PHP_EOL;
        static::safeEcho($lineOne . $lineVersion . $lineTwo);

        $title = '';
        foreach (static::getUiColumns() as $columnName => $prop) {
            $key = 'max' . ucfirst(strtolower($columnName)) . 'NameLength';
            $title .= "<w>$columnName</w>" . str_pad('', static::getUiColumnLength($key) + static::UI_SAFE_LENGTH - strlen($columnName));
        }
        $title && static::safeEcho($title . PHP_EOL);

        $content = '';
        foreach (static::$workers as $worker) {
            $content = '';
            foreach (static::getUiColumns() as $columnName => $prop) {
                $propValue = (string) ($worker->$prop ?? $worker->context->$prop ?? 'none');
                $key = 'max' . ucfirst(strtolower($columnName)) . 'NameLength';
                preg_match_all("/(<n>|<\/n>|<w>|<\/w>|<g>|<\/g>)/i", $propValue, $matches);
                $placeHolderLength = !empty($matches[0]) ? strlen(implode('', $matches[0])) : 0;
                $content .= str_pad($propValue, static::getUiColumnLength($key) + static::UI_SAFE_LENGTH + $placeHolderLength);
            }
            $content && static::safeEcho($content . PHP_EOL);
        }

        $lineLast = str_pad('', static::getSingleLineTotalLength(), '-') . PHP_EOL;
        !empty($content) && static::safeEcho($lineLast);

        if (static::$daemonize) {
            static::safeEcho('Input "php ' . basename(static::$startFile) . ' stop" to stop. Start success.' . "\n\n");
        } elseif (static::$command !== '') {
            static::safeEcho("Start success.\n");
        } else {
            static::safeEcho("Press Ctrl+C to stop. Start success.\n");
        }
    }

    protected static function getVersionLine(): string
    {
        $jitStatus = function_exists('opcache_get_status') && (opcache_get_status()['jit']['on'] ?? false) === true ? 'on' : 'off';
        $version = str_pad('reactphp-x-worker/' . static::VERSION, 24);
        $version .= str_pad('PHP/' . PHP_VERSION . ' (JIT ' . $jitStatus . ')', 30);
        $version .= php_uname('s') . '/' . php_uname('r') . PHP_EOL;
        return $version;
    }

    /** @return array<string, string> */
    public static function getUiColumns(): array
    {
        return [
            'user' => 'user',
            'worker' => 'name',
            'count' => 'count',
            'state' => 'statusState',
        ];
    }

    public static function getSingleLineTotalLength(): int
    {
        $totalLength = 0;
        foreach (static::getUiColumns() as $columnName => $prop) {
            $key = 'max' . ucfirst(strtolower($columnName)) . 'NameLength';
            $totalLength += static::getUiColumnLength($key) + static::UI_SAFE_LENGTH;
        }
        !defined('LINE_VERSION_LENGTH') && define('LINE_VERSION_LENGTH', 0);
        return max($totalLength, LINE_VERSION_LENGTH);
    }

    protected static function parseCommand(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        $startFile = basename(static::$startFile);
        $usage = "Usage: php yourfile <command> [mode]\nCommands: \nstart\t\tStart worker in DEBUG mode.\n\t\tUse mode -d to start in DAEMON mode.\nstop\t\tStop worker.\n\t\tUse mode -g to stop gracefully.\nrestart\t\tRestart workers.\n\t\tUse mode -d to start in DAEMON mode.\n\t\tUse mode -g to stop gracefully.\nreload\t\tReload codes.\n\t\tUse mode -g to reload gracefully.\nstatus\t\tGet worker status.\n\t\tUse mode -d to show live status.\nconnections\tGet worker connections.\n";
        $availableCommands = ['start', 'stop', 'restart', 'reload', 'status', 'connections'];
        $availableMode = ['-d', '-g'];
        $command = $mode = '';

        foreach (static::getArgv() as $value) {
            if (!$command && in_array($value, $availableCommands, true)) {
                $command = $value;
            }
            if (!$mode && in_array($value, $availableMode, true)) {
                $mode = $value;
            }
        }

        if (!$command) {
            exit($usage);
        }

        $modeStr = '';
        if ($command === 'start') {
            $modeStr = ($mode === '-d' || static::$daemonize) ? 'in DAEMON mode' : 'in DEBUG mode';
        }
        static::log("reactphp-x-worker[$startFile] $command $modeStr");

        $masterPid = is_file(static::$pidFile) ? (int) file_get_contents(static::$pidFile) : 0;
        if (static::checkMasterIsAlive($masterPid)) {
            if ($command === 'start') {
                static::log("reactphp-x-worker[$startFile] already running");
                exit;
            }
        } elseif ($command !== 'start' && $command !== 'restart') {
            static::log("reactphp-x-worker[$startFile] not run");
            exit;
        }

        switch ($command) {
            case 'start':
                if ($mode === '-d') {
                    static::$daemonize = true;
                }
                break;
            case 'status':
                while (1) {
                    if (is_file(static::$statisticsFile)) {
                        @unlink(static::$statisticsFile);
                    }
                    posix_kill($masterPid, SIGIOT);
                    sleep(1);
                    if ($mode === '-d') {
                        static::safeEcho("\33[H\33[2J\33(B\33[m", true);
                    }
                    static::safeEcho(static::formatStatusData(static::$statisticsFile));
                    if ($mode !== '-d') {
                        exit(0);
                    }
                    static::safeEcho("\nPress Ctrl+C to quit.\n\n");
                }
            case 'connections':
                register_shutdown_function(unlink(...), static::$connectionsFile);
                posix_kill($masterPid, SIGIO);
                usleep(500000);
                static::safeEcho(static::formatConnectionStatusData());
                exit(0);
            case 'restart':
            case 'stop':
                if ($mode === '-g') {
                    static::$gracefulStop = true;
                    $sig = SIGQUIT;
                    static::log("reactphp-x-worker[$startFile] is gracefully stopping ...");
                } else {
                    static::$gracefulStop = false;
                    $sig = SIGINT;
                    static::log("reactphp-x-worker[$startFile] is stopping ...");
                }
                $masterPid && posix_kill($masterPid, $sig);
                $timeout = static::getGracefulStop()
                    ? static::$gracefulStopTimeout
                    : static::$stopTimeout + 3;
                $startTime = time();
                while (1) {
                    $masterIsAlive = $masterPid && static::checkMasterIsAlive($masterPid);
                    if ($masterIsAlive) {
                        if (time() - $startTime >= $timeout) {
                            posix_kill($masterPid, SIGKILL);
                            usleep(100000);
                            static::log("reactphp-x-worker[$startFile] stop fail (timeout {$timeout}s)");
                            exit(1);
                        }
                        usleep(100000);
                        continue;
                    }
                    static::log("reactphp-x-worker[$startFile] stop success");
                    if ($command === 'stop') {
                        exit(0);
                    }
                    if ($mode === '-d') {
                        static::$daemonize = true;
                    }
                    break;
                }
                break;
            case 'reload':
                $sig = $mode === '-g' ? SIGUSR2 : SIGUSR1;
                posix_kill($masterPid, $sig);
                exit;
            default:
                static::safeEcho('Unknown command: ' . $command . "\n");
                exit($usage);
        }
    }

    /** @return list<string> */
    public static function getArgv(): array
    {
        global $argv;
        return static::$command ? [...$argv, ...explode(' ', static::$command)] : $argv;
    }

    protected static function formatStatusData(string $statisticsFile): string
    {
        static $totalRequestCache = [];

        if (!is_readable($statisticsFile)) {
            return '';
        }

        $info = file($statisticsFile, FILE_IGNORE_NEW_LINES);
        if (!$info) {
            return '';
        }

        $statusStr = '';
        $currentTotalRequest = [];
        $workerInfo = [];
        try {
            $workerInfo = unserialize($info[0], ['allowed_classes' => false]);
        } catch (Throwable) {
        }
        if (!is_array($workerInfo)) {
            $workerInfo = [];
        }
        ksort($workerInfo, SORT_NUMERIC);
        unset($info[0]);

        $dataWaitingSort = [];
        $readProcessStatus = false;
        $totalRequests = 0;
        $totalQps = 0;
        $totalConnections = 0;
        $totalFails = 0;
        $totalMemory = 0;
        $totalTimers = 0;
        $maxLen1 = max(static::getUiColumnLength('maxListenNameLength'), 2 * static::UI_SAFE_LENGTH);
        $maxLen2 = max(static::getUiColumnLength('maxWorkerNameLength'), 2 * static::UI_SAFE_LENGTH);

        foreach ($info as $value) {
            if (!$readProcessStatus) {
                $statusStr .= $value . "\n";
                if (preg_match('/^pid.*?memory.*?listening/', $value)) {
                    $readProcessStatus = true;
                }
                continue;
            }
            if (preg_match('/^[0-9]+/', $value, $pidMatch)) {
                $pid = $pidMatch[0];
                $dataWaitingSort[$pid] = $value;
                if (preg_match('/^\S+?\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?/', $value, $match)) {
                    $totalMemory += (int) str_ireplace('M', '', $match[1]);
                    $maxLen1 = max($maxLen1, strlen($match[2]));
                    $maxLen2 = max($maxLen2, strlen($match[3]));
                    $totalConnections += (int) $match[4];
                    $totalFails += (int) $match[5];
                    $totalTimers += (int) $match[6];
                    $currentTotalRequest[$pid] = $match[7];
                    $totalRequests += (int) $match[7];
                }
            }
        }

        foreach ($workerInfo as $pid => $infoItem) {
            if (!isset($dataWaitingSort[$pid])) {
                $statusStr .= "$pid\t" . str_pad('N/A', 7) . ' '
                    . str_pad((string) ($infoItem['listen'] ?? 'none'), $maxLen1) . ' '
                    . str_pad((string) ($infoItem['name'] ?? 'none'), $maxLen2) . ' '
                    . str_pad('N/A', 11) . ' ' . str_pad('N/A', 9) . ' '
                    . str_pad('N/A', 7) . ' ' . str_pad('N/A', 13) . " N/A    [busy] \n";
                continue;
            }
            if (!isset($totalRequestCache[$pid]) || !isset($currentTotalRequest[$pid])) {
                $qps = 0;
            } else {
                $qps = (int) $currentTotalRequest[$pid] - (int) $totalRequestCache[$pid];
                $totalQps += $qps;
            }
            $statusStr .= $dataWaitingSort[$pid] . ' ' . str_pad((string) $qps, 6) . " [idle]\n";
        }
        $totalRequestCache = $currentTotalRequest;

        $statusStr .= "---------------------------------------------------PROCESS STATUS--------------------------------------------------------\n";
        $statusStr .= 'Summary' . "\t" . str_pad($totalMemory . 'M', 7) . ' '
            . str_pad('-', $maxLen1) . ' '
            . str_pad('-', $maxLen2) . ' '
            . str_pad((string) $totalConnections, 11) . ' ' . str_pad((string) $totalFails, 9) . ' '
            . str_pad((string) $totalTimers, 7) . ' ' . str_pad((string) $totalRequests, 13) . ' '
            . str_pad((string) $totalQps, 6) . " [Summary] \n";

        return $statusStr;
    }

    protected static function formatConnectionStatusData(): string
    {
        if (!is_readable(static::$connectionsFile)) {
            return "No connection data.\n";
        }
        return (string) file_get_contents(static::$connectionsFile);
    }

    protected static function installSignal(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        pcntl_async_signals(true);
        foreach (static::getWorkerSignals() as $signal) {
            pcntl_signal($signal, static::signalHandler(...), true);
        }
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    /**
     * Register worker signal handlers on a React event loop (preferred when a loop is active).
     */
    protected static function installLoopSignal(object $loop): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !method_exists($loop, 'addSignal')) {
            static::installSignal();
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGPIPE, SIG_IGN, false);

        foreach (static::getWorkerSignals() as $signal) {
            try {
                $loop->addSignal($signal, static function () use ($signal): void {
                    static::signalHandler($signal);
                });
            } catch (Throwable) {
                pcntl_signal($signal, static::signalHandler(...), true);
            }
        }
    }

    /** @return list<int> */
    protected static function getWorkerSignals(): array
    {
        return [SIGINT, SIGTERM, SIGHUP, SIGTSTP, SIGQUIT, SIGUSR1, SIGUSR2, SIGIOT, SIGIO];
    }

    protected static function signalHandler(int $signal): void
    {
        switch ($signal) {
            case SIGINT:
            case SIGTERM:
            case SIGHUP:
            case SIGTSTP:
                static::$gracefulStop = false;
                static::stopAll(0, 'received signal ' . static::getSignalName($signal));
                break;
            case SIGQUIT:
                static::$gracefulStop = true;
                static::stopAll(0, 'received signal ' . static::getSignalName($signal));
                break;
            case SIGUSR2:
            case SIGUSR1:
                if (static::$status === static::STATUS_RELOADING || static::$status === static::STATUS_SHUTDOWN) {
                    return;
                }
                static::$gracefulStop = $signal === SIGUSR2;
                static::$pidsToRestart = static::getAllWorkerPids();
                static::reload();
                break;
            case SIGIOT:
                static::writeStatisticsToStatusFile();
                break;
            case SIGIO:
                static::writeConnectionsStatisticsToStatusFile();
                break;
        }
    }

    protected static function getSignalName(int $signal): string
    {
        return match ($signal) {
            SIGINT => 'SIGINT',
            SIGTERM => 'SIGTERM',
            SIGHUP => 'SIGHUP',
            SIGTSTP => 'SIGTSTP',
            SIGQUIT => 'SIGQUIT',
            SIGUSR1 => 'SIGUSR1',
            SIGUSR2 => 'SIGUSR2',
            SIGIOT => 'SIGIOT',
            SIGIO => 'SIGIO',
            default => (string) $signal,
        };
    }

    protected static function daemonize(): void
    {
        if (!static::$daemonize || DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        umask(0);
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Fork fail');
        }
        if ($pid > 0) {
            exit(0);
        }
        if (posix_setsid() === -1) {
            throw new RuntimeException('Setsid fail');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Fork fail');
        }
        if ($pid !== 0) {
            exit(0);
        }
    }

    public static function resetStd(): void
    {
        if (!static::$daemonize || DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        if (is_resource(STDOUT)) {
            fclose(STDOUT);
        }
        if (is_resource(STDERR)) {
            fclose(STDERR);
        }
        if (is_resource(static::$outputStream)) {
            fclose(static::$outputStream);
        }
        set_error_handler(static fn (): bool => true);
        $stdOutStream = fopen(static::$stdoutFile, 'a');
        restore_error_handler();
        if ($stdOutStream === false) {
            return;
        }
        static::$outputStream = $stdOutStream;
    }

    protected static function saveMasterPid(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        static::$masterPid = posix_getpid();
        if (file_put_contents(static::$pidFile, (string) static::$masterPid) === false) {
            throw new RuntimeException('can not save pid to ' . static::$pidFile);
        }
    }

    /** @return array<int, int> */
    protected static function getAllWorkerPids(): array
    {
        $pidArray = [];
        foreach (static::$pidMap as $workerPidArray) {
            foreach ($workerPidArray as $workerPid) {
                $pidArray[$workerPid] = $workerPid;
            }
        }
        return $pidArray;
    }

    protected static function forkWorkers(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            static::forkWorkerInPlace();
            return;
        }
        foreach (static::$workers as $worker) {
            while (count(static::$pidMap[$worker->workerId]) < $worker->count) {
                static::forkOneWorker($worker);
            }
        }
    }

    protected static function forkWorkerInPlace(): void
    {
        if (count(static::$workers) <= 0) {
            exit("no worker inited\n");
        }
        reset(static::$workers);
        /** @var self $worker */
        $worker = current(static::$workers);
        static::$status = static::STATUS_RUNNING;
        register_shutdown_function(static::checkErrors(...));
        static::setProcessTitle('reactphp-x-worker: worker process  ' . $worker->name);
        $worker->id = 0;
        $worker->run();
    }

    protected static function forkOneWorker(self $worker): void
    {
        $id = static::getId($worker->workerId, 0);
        $pid = pcntl_fork();
        if ($pid > 0) {
            static::$pidMap[$worker->workerId][$pid] = $pid;
            static::$idMap[$worker->workerId][$id] = $pid;
            return;
        }
        if ($pid === 0) {
            srand();
            mt_srand();
            static::$gracefulStop = false;
            if (static::$status === static::STATUS_STARTING) {
                static::resetStd();
            }
            static::$pidsToRestart = static::$pidMap = [];
            foreach (static::$workers as $key => $oneWorker) {
                if ($oneWorker->workerId !== $worker->workerId) {
                    unset(static::$workers[$key]);
                }
            }
            static::$status = static::STATUS_RUNNING;
            register_shutdown_function(static::checkErrors(...));
            static::setProcessTitle('reactphp-x-worker: worker process  ' . $worker->name);
            $worker->setUserAndGroup();
            $worker->id = (int) $id;
            $worker->run();
            exit(static::$status === self::STATUS_SHUTDOWN ? 0 : 250);
        }
        throw new RuntimeException('forkOneWorker fail');
    }

    protected static function getId(string $workerId, int $pid): false|int|string
    {
        return array_search($pid, static::$idMap[$workerId], true);
    }

    public function setUserAndGroup(): void
    {
        $userInfo = posix_getpwnam($this->user);
        if (!$userInfo) {
            static::log("Warning: User $this->user not exists");
            return;
        }
        $uid = $userInfo['uid'];
        if ($this->group !== '') {
            $groupInfo = posix_getgrnam($this->group);
            if (!$groupInfo) {
                static::log("Warning: Group $this->group not exists");
                return;
            }
            $gid = $groupInfo['gid'];
        } else {
            $gid = $userInfo['gid'];
        }
        if ($uid !== posix_getuid() || $gid !== posix_getgid()) {
            if (!posix_setgid($gid) || !posix_initgroups($userInfo['name'], $gid) || !posix_setuid($uid)) {
                static::log('Warning: change gid or uid fail.');
            }
        }
    }

    protected static function setProcessTitle(string $title): void
    {
        set_error_handler(static fn (): bool => true);
        cli_set_process_title($title);
        restore_error_handler();
    }

    protected static function monitorWorkers(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        static::$status = static::STATUS_RUNNING;
        while (true) {
            pcntl_signal_dispatch();
            while (true) {
                $status = 0;
                $pid = pcntl_wait($status, WUNTRACED | WNOHANG);
                pcntl_signal_dispatch();
                if ($pid === -1) {
                    break;
                }
                if ($pid === 0) {
                    break;
                }
                foreach (static::$pidMap as $workerId => $workerPidArray) {
                    if (!isset($workerPidArray[$pid])) {
                        continue;
                    }
                    $worker = static::$workers[$workerId];
                    if ($status === SIGINT && static::$status === static::STATUS_SHUTDOWN) {
                        $status = 0;
                    }
                    if ($status !== 0) {
                        static::log("worker[$worker->name:$pid] exit with status $status");
                    }
                    if (static::$onWorkerExit) {
                        try {
                            (static::$onWorkerExit)($worker, $status, $pid);
                        } catch (Throwable $exception) {
                            static::log("worker[$worker->name] onWorkerExit $exception");
                        }
                    }
                    static::$globalStatistics['worker_exit_info'][$workerId][$status] ??= 0;
                    static::$globalStatistics['worker_exit_info'][$workerId][$status]++;
                    unset(static::$pidMap[$workerId][$pid]);
                    $id = static::getId($workerId, $pid);
                    if ($id !== false) {
                        static::$idMap[$workerId][$id] = 0;
                    }
                    break;
                }
                if (static::$status !== static::STATUS_SHUTDOWN) {
                    static::forkWorkers();
                    if (isset(static::$pidsToRestart[$pid])) {
                        unset(static::$pidsToRestart[$pid]);
                        static::reload();
                    }
                }
            }
            if (static::$status === static::STATUS_SHUTDOWN && static::getAllWorkerPids() === []) {
                static::exitAndClearAll();
            }

            if (static::$status === static::STATUS_SHUTDOWN) {
                static::forceKillWorkersIfTimeout();
            }

            usleep(100_000);
        }
    }

    protected static function forceKillWorkersIfTimeout(): void
    {
        if (static::$shutdownStartedAt === 0) {
            return;
        }

        $timeout = static::getGracefulStop()
            ? static::$gracefulStopTimeout
            : static::$stopTimeout + 3;

        if (time() - static::$shutdownStartedAt < $timeout) {
            return;
        }

        foreach (static::getAllWorkerPids() as $workerPid) {
            if (posix_kill($workerPid, 0)) {
                posix_kill($workerPid, SIGKILL);
            }
        }
    }

    protected static function exitAndClearAll(): void
    {
        clearstatcache();
        if (is_file(static::$pidFile)) {
            @unlink(static::$pidFile);
        }
        static::log('reactphp-x-worker[' . basename(static::$startFile) . '] has been stopped');
        if (static::$onMasterStop) {
            (static::$onMasterStop)();
        }
        exit(0);
    }

    protected static function reload(): void
    {
        if (static::$masterPid === posix_getpid()) {
            $sig = static::getGracefulStop() ? SIGUSR2 : SIGUSR1;
            if (static::$status === static::STATUS_RUNNING) {
                static::log('reactphp-x-worker[' . basename(static::$startFile) . '] reloading');
                static::$status = static::STATUS_RELOADING;
                static::resetStd();
                if (static::$onMasterReload) {
                    try {
                        (static::$onMasterReload)();
                    } catch (Throwable $e) {
                        static::stopAll(250, $e);
                    }
                    static::initId();
                }
                $reloadablePidArray = [];
                foreach (static::$pidMap as $workerId => $workerPidArray) {
                    $worker = static::$workers[$workerId];
                    if ($worker->reloadable) {
                        $reloadablePidArray += $workerPidArray;
                        continue;
                    }
                    array_walk($workerPidArray, static fn ($childPid) => posix_kill($childPid, $sig));
                }
                static::$pidsToRestart = array_intersect(static::$pidsToRestart, $reloadablePidArray);
            }
            if (static::$pidsToRestart === []) {
                if (static::$status !== static::STATUS_SHUTDOWN) {
                    static::$status = static::STATUS_RUNNING;
                }
                return;
            }
            $oneWorkerPid = current(static::$pidsToRestart);
            posix_kill($oneWorkerPid, $sig);
            if (!static::getGracefulStop()) {
                pcntl_alarm(static::$stopTimeout);
                pcntl_signal(SIGALRM, static function () use ($oneWorkerPid): void {
                    posix_kill($oneWorkerPid, SIGKILL);
                }, false);
            }
            return;
        }

        reset(static::$workers);
        $worker = current(static::$workers);
        if ($worker->onWorkerReload) {
            try {
                ($worker->onWorkerReload)($worker);
            } catch (Throwable $e) {
                static::stopAll(250, $e);
            }
        }
        if ($worker->reloadable) {
            static::stopAll();
        } else {
            static::resetStd();
        }
    }

    public static function stopAll(int $code = 0, mixed $log = ''): void
    {
        static::$status = static::STATUS_SHUTDOWN;
        if (DIRECTORY_SEPARATOR === '/' && static::$masterPid === posix_getpid()) {
            if (static::$shutdownStartedAt === 0) {
                static::$shutdownStartedAt = time();
            }
            if ($log) {
                static::log('reactphp-x-worker[' . basename(static::$startFile) . "] $log");
            }
            static::log('reactphp-x-worker[' . basename(static::$startFile) . '] stopping' . ($code ? ", code [$code]" : ''));
            $sig = static::getGracefulStop() ? SIGQUIT : SIGINT;
            foreach (static::getAllWorkerPids() as $workerPid) {
                posix_kill($workerPid, $sig);
            }
            return;
        }

        if ($code && $log) {
            static::log($log);
        }
        $workers = array_values(array_reverse(static::$workers));
        foreach ($workers as $worker) {
            $worker->stop(false);
        }
        exit($code);
    }

    public static function getStatus(): int
    {
        return static::$status;
    }

    public static function getGracefulStop(): bool
    {
        return static::$gracefulStop;
    }

    protected static function getWorkerListenName(self $worker): string
    {
        $listen = (string) ($worker->context->listen ?? '');

        return $listen !== '' ? $listen : 'none';
    }

    /** @return array{connection_count: int, send_fail: int, total_request: int} */
    protected static function getWorkerStatistics(self $worker): array
    {
        $statistics = $worker->context->statistics ?? null;

        if (is_object($statistics)) {
            return [
                'connection_count' => (int) ($statistics->connection_count ?? 0),
                'send_fail' => (int) ($statistics->send_fail ?? 0),
                'total_request' => (int) ($statistics->total_request ?? 0),
            ];
        }

        if (is_array($statistics)) {
            return [
                'connection_count' => (int) ($statistics['connection_count'] ?? 0),
                'send_fail' => (int) ($statistics['send_fail'] ?? 0),
                'total_request' => (int) ($statistics['total_request'] ?? 0),
            ];
        }

        return [
            'connection_count' => 0,
            'send_fail' => 0,
            'total_request' => 0,
        ];
    }

    protected static function getWorkerTimerCount(): int
    {
        $loop = static::getReactEventLoop();
        if ($loop !== null && method_exists($loop, 'getTimerCount')) {
            return (int) $loop->getTimerCount();
        }

        return 0;
    }

    protected static function writeStatisticsToStatusFile(): void
    {
        if (static::$masterPid === posix_getpid()) {
            $allWorkerInfo = [];
            foreach (static::$pidMap as $workerId => $pidArray) {
                $worker = static::$workers[$workerId];
                foreach ($pidArray as $pid) {
                    $allWorkerInfo[$pid] = [
                        'name' => $worker->name,
                        'listen' => static::getWorkerListenName($worker),
                    ];
                }
            }
            file_put_contents(static::$statisticsFile, '');
            chmod(static::$statisticsFile, 0722);
            file_put_contents(static::$statisticsFile, serialize($allWorkerInfo) . "\n", FILE_APPEND);
            $loadavg = function_exists('sys_getloadavg') ? array_map(round(...), sys_getloadavg(), [2, 2, 2]) : ['-', '-', '-'];
            file_put_contents(static::$statisticsFile,
                (static::$daemonize ? 'Start worker in DAEMON mode.' : 'Start worker in DEBUG mode.') . "\n", FILE_APPEND);
            file_put_contents(static::$statisticsFile,
                "---------------------------------------------------GLOBAL STATUS---------------------------------------------------------\n", FILE_APPEND);
            file_put_contents(static::$statisticsFile, static::getVersionLine(), FILE_APPEND);
            file_put_contents(static::$statisticsFile, 'start time:' . date('Y-m-d H:i:s', static::$globalStatistics['start_timestamp'])
                . '   run ' . floor((time() - static::$globalStatistics['start_timestamp']) / 86400)
                . ' days ' . floor(((time() - static::$globalStatistics['start_timestamp']) % 86400) / 3600)
                . " hours   load average: " . implode(', ', $loadavg) . "\n", FILE_APPEND);
            file_put_contents(static::$statisticsFile,
                count(static::$pidMap) . ' workers    ' . count(static::getAllWorkerPids()) . " processes\n", FILE_APPEND);
            file_put_contents(static::$statisticsFile,
                str_pad('name', static::getUiColumnLength('maxWorkerNameLength')) . "     exit_status     exit_count\n", FILE_APPEND);
            foreach (static::$pidMap as $workerId => $workerPidArray) {
                $worker = static::$workers[$workerId];
                if (isset(static::$globalStatistics['worker_exit_info'][$workerId])) {
                    foreach (static::$globalStatistics['worker_exit_info'][$workerId] as $workerExitStatus => $workerExitCount) {
                        file_put_contents(static::$statisticsFile,
                            str_pad($worker->name, static::getUiColumnLength('maxWorkerNameLength')) . '     '
                            . str_pad((string) $workerExitStatus, 16) . str_pad((string) $workerExitCount, 16) . "\n", FILE_APPEND);
                    }
                } else {
                    file_put_contents(static::$statisticsFile,
                        str_pad($worker->name, static::getUiColumnLength('maxWorkerNameLength')) . '     '
                        . str_pad('0', 16) . str_pad('0', 16) . "\n", FILE_APPEND);
                }
            }
            file_put_contents(static::$statisticsFile,
                "---------------------------------------------------PROCESS STATUS--------------------------------------------------------\n", FILE_APPEND);
            file_put_contents(static::$statisticsFile,
                "pid\tmemory  " . str_pad('listening', static::getUiColumnLength('maxListenNameLength'))
                . ' ' . str_pad('worker_name', static::getUiColumnLength('maxWorkerNameLength'))
                . ' connections ' . str_pad('send_fail', 9) . ' '
                . str_pad('timers', 8) . str_pad('total_request', 13) . " qps    status\n", FILE_APPEND);
            foreach (static::getAllWorkerPids() as $workerPid) {
                posix_kill($workerPid, SIGIOT);
            }
            return;
        }

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }

        reset(static::$workers);
        /** @var self $worker */
        $worker = current(static::$workers);
        $listen = static::getWorkerListenName($worker);
        $workerName = $worker->name === $listen ? 'none' : $worker->name;
        $statistics = static::getWorkerStatistics($worker);
        $workerStatusStr = posix_getpid() . "\t" . str_pad(round(memory_get_usage(false) / (1024 * 1024), 2) . 'M', 7)
            . ' ' . str_pad($listen, static::getUiColumnLength('maxListenNameLength'))
            . ' ' . str_pad($workerName, static::getUiColumnLength('maxWorkerNameLength'))
            . ' ';
        $workerStatusStr .= str_pad((string) $statistics['connection_count'], 11)
            . ' ' . str_pad((string) $statistics['send_fail'], 9)
            . ' ' . str_pad((string) static::getWorkerTimerCount(), 7)
            . ' ' . str_pad((string) $statistics['total_request'], 13) . "\n";
        file_put_contents(static::$statisticsFile, $workerStatusStr, FILE_APPEND);
    }

    protected static function writeConnectionsStatisticsToStatusFile(): void
    {
        if (static::$masterPid === posix_getpid()) {
            file_put_contents(static::$connectionsFile, '');
            chmod(static::$connectionsFile, 0722);
            file_put_contents(static::$connectionsFile,
                "--------------------------------------------------------------------- REACTPHP-X-WORKER CONNECTION STATUS --------------------------------------------------------------------------------\n", FILE_APPEND);
            file_put_contents(static::$connectionsFile,
                "PID      Worker          CID       Type    Status        Remote Address\n", FILE_APPEND);
            foreach (static::getAllWorkerPids() as $workerPid) {
                posix_kill($workerPid, SIGIO);
            }
            return;
        }

        reset(static::$workers);
        /** @var self $worker */
        $worker = current(static::$workers);
        $connectionStatusLines = $worker->context->connectionStatusLines ?? null;
        if (is_array($connectionStatusLines) && $connectionStatusLines !== []) {
            file_put_contents(static::$connectionsFile, implode('', $connectionStatusLines), FILE_APPEND);
            return;
        }

        file_put_contents(static::$connectionsFile,
            str_pad((string) posix_getpid(), 9) . str_pad($worker->name, 16) . "running\n", FILE_APPEND);
    }

    protected static function getUiColumnLength(string $name): int
    {
        return static::$uiLengthData[$name] ?? 0;
    }

    protected static function checkErrors(): void
    {
        if (static::$status !== static::STATUS_SHUTDOWN) {
            $errorMsg = 'Worker[' . posix_getpid() . '] process terminated';
            $errors = error_get_last();
            if ($errors && in_array($errors['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
                $errorMsg .= ' with ERROR: ' . $errors['message'] . " in {$errors['file']} on line {$errors['line']}";
            }
            static::log($errorMsg);
        }
    }

    protected static function checkMasterIsAlive(int $masterPid): bool
    {
        if ($masterPid === 0) {
            return false;
        }
        $masterIsAlive = posix_kill($masterPid, 0) && posix_getpid() !== $masterPid;
        if (!$masterIsAlive) {
            static::log("Master pid:$masterPid is not alive");
            return false;
        }
        $cmdline = "/proc/$masterPid/cmdline";
        if (!is_readable($cmdline)) {
            return true;
        }
        $content = file_get_contents($cmdline);
        if ($content === false || $content === '') {
            return true;
        }
        return str_contains($content, 'reactphp-x-worker') || str_contains($content, 'php');
    }

    public static function isRunning(): bool
    {
        return static::$status !== static::STATUS_INITIAL;
    }

    public function run(): void
    {
        if (!$this->handler) {
            throw new RuntimeException('Worker handler is not set');
        }

        try {
            ($this->handler)($this);
        } catch (Throwable $e) {
            sleep(1);
            static::stopAll(250, $e);
        }

        if (static::$status !== static::STATUS_SHUTDOWN) {
            $this->waitUntilStop();
        }
    }

    protected function waitUntilStop(): void
    {
        $loop = static::getReactEventLoop();
        if ($loop !== null) {
            static::installLoopSignal($loop);
            $loop->run();

            if (static::$status === static::STATUS_SHUTDOWN || $this->stopping) {
                exit(0);
            }
        }

        static::installSignal();
        while (static::$status !== static::STATUS_SHUTDOWN && !$this->stopping) {
            pcntl_signal_dispatch();
            usleep(100_000);
        }
    }

    /**
     * Returns the React event loop if react/event-loop is installed and already initialized.
     * Does not create a loop instance (unlike Loop::get()).
     */
    protected static function getReactEventLoop(): ?object
    {
        if (!class_exists(\React\EventLoop\Loop::class, false)) {
            return null;
        }

        $reflection = new \ReflectionClass(\React\EventLoop\Loop::class);
        if (!$reflection->hasProperty('instance')) {
            return null;
        }

        $instance = $reflection->getProperty('instance')->getValue();

        return $instance !== null ? $instance : null;
    }

    public function stop(bool $force = true): void
    {
        if ($this->stopping) {
            return;
        }
        if ($this->onWorkerStop) {
            try {
                ($this->onWorkerStop)($this);
            } catch (Throwable $e) {
                static::log($e);
            }
        }
        $loop = static::getReactEventLoop();
        if ($loop !== null && method_exists($loop, 'stop')) {
            $loop->stop();
        }
        $this->handler = null;
        $this->stopping = true;
    }

    public static function log(Stringable|string $msg, bool $decorated = false): void
    {
        $msg = trim((string) $msg);
        if (!static::$daemonize) {
            static::safeEcho("$msg\n", $decorated);
        }
        if (static::$logFile !== '') {
            $pid = DIRECTORY_SEPARATOR === '/' ? posix_getpid() : 1;
            file_put_contents(static::$logFile, sprintf("%s pid:%d %s\n", date('Y-m-d H:i:s'), $pid, $msg), FILE_APPEND | LOCK_EX);
        }
    }

    public static function safeEcho(string $msg, bool $decorated = false): void
    {
        if ((static::$outputDecorated ?? false) && $decorated) {
            $line = "\033[1A\n\033[K";
            $white = "\033[47;30m";
            $green = "\033[32;40m";
            $end = "\033[0m";
        } else {
            $line = $white = $green = $end = '';
        }
        $msg = str_replace(['<n>', '<w>', '<g>'], [$line, $white, $green], $msg);
        $msg = str_replace(['</n>', '</w>', '</g>'], $end, $msg);
        set_error_handler(static fn (): bool => true);
        if (!feof(self::$outputStream)) {
            fwrite(self::$outputStream, $msg);
            fflush(self::$outputStream);
        }
        restore_error_handler();
    }
}
