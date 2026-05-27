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
     * @param string $level Loglevel (INFO, WARNING, ERROR, DEBUG, PERF)
     * @param string $message Die Log-Nachricht.
     * @param array $context Zusätzliche Kontextdaten als Array (wird als JSON gespeichert).
     * @param string $filePrefix Der Prefix der Log-Datei (z.B. 'app' oder 'performance')
     */
    public static function log($level, $message, $context = [], $filePrefix = 'app') {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            // umask-Probleme umgehen, falls der Server die Rechte beim mkdir einschränkt
            chmod($dir, 0777); 
        }

        $date = date('Y-m-d');
        $time = date('H:i:s');
        $logFile = $dir . "/{$filePrefix}_{$date}.log";

        // Prüfen, ob die Datei bereits existiert, BEVOR wir schreiben
        $fileExists = file_exists($logFile);

        $contextString = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logEntry = "[{$date} {$time}] [{$level}] {$message}{$contextString}" . PHP_EOL;

        // In die Datei schreiben (erstellt sie, falls sie nicht existiert)
        file_put_contents($logFile, $logEntry, FILE_APPEND);

        // Wenn die Datei gerade neu erstellt wurde, Rechte für alle öffnen (0666)
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

    /**
     * Loggt Performance-Daten in eine separate Datei.
     *
     * @param string $message Beschreibung des gemessenen Blocks.
     * @param float $durationSec Die gemessene Zeit in Sekunden.
     * @param array $context Weitere Infos (z.B. ASIN, Marketplace).
     */
    public static function performance($message, $durationSec, $context = []) {
        $context['duration_seconds'] = round($durationSec, 4);
        self::log('PERF', $message, $context, 'performance');
    }
}