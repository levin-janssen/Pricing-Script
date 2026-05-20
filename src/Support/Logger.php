<?php

class Logger {
    private static function logDir(): string
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        return $root . '/logs';
    }

    /**
     * Schreibt eine Log-Nachricht in die aktuelle Log-Datei.
     *
     * @param string $level Loglevel (INFO, WARNING, ERROR, DEBUG)
     * @param string $message Die Log-Nachricht.
     * @param array $context Zusätzliche Kontextdaten als Array (wird als JSON gespeichert).
     */
    public static function log($level, $message, $context = []) {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $date = date('Y-m-d');
        $time = date('H:i:s');
        $logFile = $dir . "/app_{$date}.log";

        $contextString = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logEntry = "[{$date} {$time}] [{$level}] {$message}{$contextString}" . PHP_EOL;

        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }

    public static function info($message, $context = []) {
        self::log('INFO', $message, $context);
    }

    public static function warning($message, $context = []) {
        self::log('WARNING', $message, $context);
    }

    public static function error($message, $context = []) {
        self::log('ERROR', $message, $context);
    }

    public static function debug($message, $context = []) {
        self::log('DEBUG', $message, $context);
    }
}
