<?php

namespace Qolio\Helper;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const NOTICE = 'notice';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';
    public const ALERT = 'alert';
    public const EMERGENCY = 'emergency';
    public const DB_ERROR = 'db_error';

    public static string $request_id = '';
    private static string $organizationId = '';
    private static string $integrationId = '';
    private static string $uid = '';
    private static string $tag = '';
    private static float $start;
    private static string $scriptType;
    private static array $metaData = [];

    private static array $logModel = [
        'type' => '',
        'message' => '',
        'created_at' => '',
        'method' => '',
        'code' => '',
        'line' => '',
        'endpoint' => '',
        'controller' => '',
        'pid' => '',
        'script_type' => '',
        'container_id' => '',
        'container_name' => '',
        'cpu_usage' => '',
        'work_time' => '',
        'organization_id' => '',
        'integration_id' => '',
        'uid' => '',
        'file' => '',
        'memory_usage' => '',
        'pod_name' => '',
        'request_id' => '',
        'tag' => '',
    ];

    public static function setOrganizationId($organizationId)
    {
        self::$organizationId = $organizationId;
    }

    public static function getOrganizationId()
    {
        return self::$organizationId;
    }

    public static function setIntegrationId($integrationId)
    {
        self::$integrationId = $integrationId;
    }

    public static function getIntegrationId()
    {
        return self::$integrationId;
    }

    public static function setUid(string $uid): void
    {
        self::$uid = $uid;
    }

    public static function getUid(): string
    {
        return self::$uid;
    }

    public static function init(string $scriptType): void
    {
        if (!isset(self::$start)) {
            self::$start = microtime(true);
        }

        if (!isset(self::$scriptType)) {
            self::$scriptType = strtoupper($scriptType);
        }

        if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            self::$request_id = $_SERVER['HTTP_X_REQUEST_ID'];
        } elseif (empty(self::$request_id)) {
            self::$request_id = uniqid('req_', true);

            $log = [
                'message' => 'REQUEST_ID не был найден в заголовках, сгенерирован новый',
                'created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                'type' => self::INFO,
                'request_id' => self::$request_id,
            ];

            self::writeLog($log);
        }

        if (isset($_SERVER['HTTP_UID'])) {
            self::setUid($_SERVER['HTTP_UID']);
        }

        set_error_handler([self::class, 'errorHandler']);
        register_shutdown_function([self::class, 'shutdown']);
//        date_default_timezone_set('UTC');

        self::createMeta();
    }

    private static function enrichLog(array $log): array
    {
        foreach (self::$logModel as $key => $value) {
            if (!isset($log[$key])) {
                $log[$key] = $value;
            }
        }
        return $log;
    }

    public static function errorHandler(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        $errorTypes = [
            E_ERROR => self::ERROR,
            E_WARNING => self::WARNING,
            E_PARSE => self::ERROR,
            E_NOTICE => self::NOTICE,
            E_CORE_ERROR => self::ERROR,
            E_CORE_WARNING => self::WARNING,
            E_COMPILE_ERROR => self::ERROR,
            E_COMPILE_WARNING => self::WARNING,
            E_USER_ERROR => self::ERROR,
            E_USER_WARNING => self::WARNING,
            E_USER_NOTICE => self::NOTICE,
            E_RECOVERABLE_ERROR => self::WARNING,
            E_DEPRECATED => self::NOTICE,
            E_USER_DEPRECATED => self::NOTICE,
        ];

        $errorType = $errorTypes[$errno] ?? 'unknown';

        $logMessage = [
            'type' => $errorType,
            'message' => $errstr,
            'code' => $errno,
            'file' => $errfile,
            'line' => $errline,
        ];

        self::createMeta();

        $log = array_merge($logMessage, self::$metaData);

        $log = self::enrichLog($log);
        self::writeLog($log);

        return false;
    }

    private static function writeLog(array $log): void
    {
        try {
            $encodedLog = json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($encodedLog === false) {
                $simpleLog = json_encode([
                    'type' => self::ERROR,
                    'message' => 'Failed to encode log to JSON',
                    'original_message' => print_r($log, true),
                    'created_at' => (new DateTimeImmutable())->format(DateTime::ATOM),
                ]);

                file_put_contents('php://stdout', $simpleLog . PHP_EOL);

                return;
            }
            file_put_contents('php://stdout', $encodedLog . PHP_EOL);

        } catch (Throwable $e) {
            file_put_contents('php://stderr', "Logger error: {$e->getMessage()}" . PHP_EOL);
        }
    }

    public static function shutdown(): void
    {
        self::richMeta();
        self::log('FINAL LOG', self::INFO);
        self::getLastError();
    }

    private static function createMeta(): void
    {
        $pid = getmypid() ?: 0;
        $endpoint = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '';

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $controller = $backtrace[3]['class'] ?? null;

        $memoryUsage = memory_get_peak_usage();
        $memoryUsageMb = round($memoryUsage / 1024 / 1024, 2);
        $containerId = getenv('CONTAINER_ID') ?: '';
        $containerName = getenv('CONTAINER_NAME') ?: '';

        self::$metaData = [
            'created_at' => (new DateTimeImmutable())->format(DateTime::ATOM),
            'container_id' => $containerId,
            'container_name' => $containerName,
            'endpoint' => $endpoint,
            'controller' => $controller,
            'pid' => $pid,
            'script_type' => self::$scriptType ?? 'UNKNOWN',
            'memory_usage' => $memoryUsageMb . 'Mb',
            'request_id' => self::$request_id,
            'uid' => self::$uid,
            'pod_name' => getenv('HOSTNAME') ?: '',
        ];
    }

    private static function richMeta(): void
    {
        $end = microtime(true);
        $executionTime = number_format($end - (self::$start ?? $end), 5) . ' sec.';

        $cpu = getrusage();
        self::$metaData['work_time'] = $executionTime;
        self::$metaData['cpu_usage'] = $cpu['ru_utime.tv_sec'];

    }

    private static function getLastError(): void
    {
        $error = error_get_last();
        if ($error !== null) {
            self::errorHandler(
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
        }
    }

    public static function logError(string $message, mixed $code, string $type): void
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $function = $backtrace[1]['function'] ?? null;
        $file = $backtrace[1]['file'] ?? null;
        $line = $backtrace[1]['line'] ?? null;

        self::createMeta();

        $log = [
            'message' => $message,
            'method' => $function,
            'type' => $type,
            'code' => $code,
            'tag' => self::$tag,
            'file' => $file,
            'line' => $line,
        ];

        $log = array_merge($log, self::$metaData);

        $log = self::enrichLog($log);
        self::writeLog($log);
    }

    public static function log($message = '', string $level = self::INFO, array $context = []): void
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $function = $backtrace[2]['function'] ?? null;
        $controller = $backtrace[3]['class'] ?? null;
        $file = $backtrace[1]['file'] ?? null;
        $line = $backtrace[1]['line'] ?? null;

        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        self::createMeta();

        $log = [
            'message' => $message,
            'method' => $function,
            'type' => $level,
            'tag' => self::$tag,
            'organization_id' => self::$organizationId ?? QolioHelper::getRequestData('organization_id'),
            'integration_id' => self::$integrationId ?? QolioHelper::getRequestData('integration_id'),
            'controller' => $controller,
            'file' => $file,
            'line' => $line,
            'work_time' => microtime(true) - (self::$start ?? microtime(true)),
        ];

        if (!empty($context)) {
            $log['context'] = $context;
        }

        $log = array_merge($log, self::$metaData);

        $log = self::enrichLog($log);
        self::writeLog($log);
    }

    public static function setTag(string $tag): void
    {
        self::$tag = $tag;
    }

    public static function debug($message, array $context = []): void
    {
        self::log($message, self::DEBUG, $context);
    }

    public static function info($message, array $context = []): void
    {
        self::log($message, self::INFO, $context);
    }

    public static function warning($message, array $context = []): void
    {
        self::log($message, self::WARNING, $context);
    }

    public static function error($message, array $context = []): void
    {
        self::log($message, self::ERROR, $context);
    }

    public static function critical($message, array $context = []): void
    {
        self::log($message, self::CRITICAL, $context);
    }

    public static function exception(Throwable $exception, string $level = self::ERROR, array $context = []): void
    {
        $context = array_merge($context, [
            'class' => get_class($exception),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        self::log($exception->getMessage(), $level, $context);
    }
}