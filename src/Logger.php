<?php

namespace Qolio\Helper;

use DateTime;
use DateTimeImmutable;

class Logger
{
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const DB_ERROR = 'DB_ERROR';
    public static string $request_id = '';
    private static string $organizationId = '';
    private static string $integrationId = '';
    private static string $uid = '';

    private static array $metaData = [];
    private static float $start;
    private static string $scriptType;
    private static string $tag = '';
    private static array $logModel = [
        'type' => '',
        'message' => '',
        'time' => '',
        'method' => '',
        'code' => '',
        'line' => '',
        'created_at' => '',
        'endpoint' => '',
        'controller' => '',
        'pid' => '',
        'script_type' => '',
        'container_id' => '',
        'container_name' => '',
        'cpu_usage' => '',
        'work_time' => '',
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

    public static function setUid($uid)
    {
        self::$uid = $uid;
    }

    public static function getUid()
    {
        return self::$uid;
    }

    public static function init(string $scriptType)
    {
        if(!isset(self::$start))
            self::$start = microtime(true);
        if(!isset(self::$scriptType))
            self::$scriptType = strtoupper($scriptType);
        if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            self::$request_id = $_SERVER['HTTP_X_REQUEST_ID'];
        }
        if (isset($_SERVER['HTTP_UID'])) {
            self::setUid($_SERVER['HTTP_UID']);
        }
        set_error_handler([self::class, 'errorHandler']);
        register_shutdown_function(([self::class, 'shutdown']));
        date_default_timezone_set('UTC');
        self::createMeta();
    }

    private static function richLog(array $log): array
    {
        foreach (self::$logModel as $key => $value) {
            if (!isset($log[$key])) {
                $log[$key] = $value;
            }
        }
        return $log;
    }

    public static function errorHandler($errno, $errstr, $errfile, $errline): void
    {
        // Получаем тип ошибки
        $errorTypes = array(
            E_ERROR => 'error',
            E_WARNING => 'warning',
            E_PARSE => 'error',
            E_NOTICE => 'notice',
            E_CORE_ERROR => 'error',
            E_CORE_WARNING => 'warning',
            E_COMPILE_ERROR => 'error',
            E_COMPILE_WARNING => 'warning',
            E_USER_ERROR => 'error',
            E_USER_WARNING => 'warning',
            E_USER_NOTICE => 'notice',
            E_RECOVERABLE_ERROR => 'warning',
        );
        $errorType = isset($errorTypes[$errno]) ? $errorTypes[$errno] : 'Unknown';
        $logMessage = [];
        $logMessage['type'] = $errorType;
        $logMessage['message'] = $errstr;
        $logMessage['code'] = $errno;
        $logMessage['line'] = $errline;
        self::createMeta();
        $log = $logMessage + self::$metaData;
        $log = self::richLog($log);
        self::createLog($log);
    }

    private static function createLog(array $log): void
    {
        $log = json_encode($log);
        file_put_contents('php://stdout', $log . PHP_EOL);
    }

    private static function shutdown(): void
    {
        self::richMeta();
        self::log('FINAL LOG');
        self::getLastError();
    }

    private static function createMeta(): void
    {
        $pid = getmypid();
        $endpoint = $_SERVER['SCRIPT_FILENAME'];
        $controller = isset(debug_backtrace()[2]) && isset(debug_backtrace()[2]['class']) ? debug_backtrace()[2]['class'] : null;
        $createdAt = time();
        $memoryUsage = memory_get_peak_usage();
        $memoryUsageMb = round($memoryUsage / 1024 / 1024, 2);
        $containerId = getenv('CONTAINER_ID');
        $containerName = getenv('CONTAINER_NAME');
        //
        self::$metaData['created_at'] = $createdAt;
        self::$metaData['container_id'] = $containerId;
        self::$metaData['container_name'] = $containerName;
        self::$metaData['endpoint'] = $endpoint;
        self::$metaData['controller'] = $controller;
        self::$metaData['pid'] = $pid;
        self::$metaData['script_type'] = self::$scriptType;
        self::$metaData['memory_usage'] = $memoryUsageMb . 'Mb';
    }

    private static function richMeta(): void
    {
        $end = microtime(true);
        $executionTime = number_format($end - self::$start, 5) . ' sec.';


        $cpu = getrusage();
        self::$metaData['work_time'] = $executionTime;
        self::$metaData['cpu_usage'] = $cpu['ru_utime.tv_sec'];
    }

    private static function getLastError(): void
    {
        $error = error_get_last();
        if ($error !== null) {
            self::errorHandler($error['type'], $error['message'], $error['file'], $error['line']);

        }
    }

    public static function logError(string $message, mixed $code, string $type): void
    {
        $trace = debug_backtrace();
        $function = isset($trace[1]['function']) ? $trace[1]['function'] : null;
        $date = new DateTimeImmutable();
        $date = date_format($date, 'd-m-y H:i:s');
        $tag = self::$tag;

        self::createMeta();
        $log = [
                'message' => $message,
                'time'    => $date,
                'method'  => $function,
                'type'    => $type,
                'code'    => $code,
                'tag'     => $tag,
            ] + self::$metaData;
        $log = self::richLog($log);
        self::createLog($log);
    }

    public static function log($message = '', $organizationId = '', $integrationId = ''): void
    {
        $trace = debug_backtrace();
        $function = isset($trace[1]['function']) ? $trace[1]['function'] : null;
        $date = new DateTimeImmutable();
        $controller = isset($trace[2]['class']) ? $trace[2]['class'] : null;
        $file = isset($trace[1]['file']) ? $trace[1]['file'] : null;
        $line = isset($trace[1]['line']) ? $trace[1]['line'] : null;
        $tag = self::$tag;
        $date = (new DateTimeImmutable())->format(DateTime::ATOM);
        if (is_array($message) || is_object($message)) $message = print_r($message, true);
        self::createMeta();
        $log = [
                'message' => $message,
                'time'    => $date,
                'method'  => $function,
                'type'    => 'info',
                'tag'     => $tag,
                'organization_id' => self::getOrganizationId(),
                'integration_id' => self::getIntegrationId(),
                'uid' => self::getUid(),
                'controller' => $controller,
                'file' => $file,
                'line' => $line,
//                'load' => sys_getloadavg()[0] ?? 0,
                'memoryUsage' => memory_get_peak_usage(),
                'memoryUsageMb' => round(memory_get_peak_usage() / 1024 / 1024, 2) . 'Mb',
                'time_miliseconds' => new DateTime(),
                'pid' => getmypid(),
                'request_id' => self::$request_id,
                'pod_name' => getenv("HOSTNAME"),
                'work_time' => microtime(true) - self::$start,
            ] + self::$metaData;
        $log = self::richLog($log);
        self::createLog($log);
    }

    public static function setTag(string $tag){
        self::$tag = $tag;
    }
}
