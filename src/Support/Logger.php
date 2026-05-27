<?php

class Logger {
    // Stores the unique ID for the current script run
    private static $runId = null;

    /**
     * Setzt die eindeutige ID für den aktuellen Skript-Durchlauf.
     */
    public static function setRunId(string $id) {
        self::$runId = $id;
    }

    private static function logDir(): string
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        return $root . '/logs';
    }

    /**
     * Schreibt eine Log-Nachricht in die aktuelle Log-Datei.
     */
    public static function log($level, $message, $context = [], $filePrefix = 'app') {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            chmod($dir, 0777); 
        }

        $date = date('Y-m-d');
        $time = date('H:i:s');
        $logFile = $dir . "/{$filePrefix}_{$date}.log";

        $fileExists = file_exists($logFile);

        // Format the Run ID if it is set
        $runIdString = self::$runId ? "[RunID: " . self::$runId . "] " : "";

        $contextString = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        
        // Build the log entry including the Run ID
        $logEntry = "[{$date} {$time}] {$runIdString}[{$level}] {$message}{$contextString}" . PHP_EOL;

        file_put_contents($logFile, $logEntry, FILE_APPEND);

        if (!$fileExists) {
            chmod($logFile, 0666);
        }
    }

    public static function info($message, $context = []) {
        self::log('INFO', $message, $context, 'app');
    }

    public static function warning($message, $context = []) {
        self::log('WARNING', $message, $context, 'app');
    }

    public static function error($message, $context = []) {
        self::log('ERROR', $message, $context, 'app');
    }

    public static function debug($message, $context = []) {
        self::log('DEBUG', $message, $context, 'app');
    }

    public static function performance($message, $durationSec, $context = []) {
        $context['duration_seconds'] = round($durationSec, 4);
        self::log('PERF', $message, $context, 'performance');
    }
}