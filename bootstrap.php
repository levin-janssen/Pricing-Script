<?php

/**
 * Project root (directory containing this file). Loaded by every public entry script.
 */
if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

// Umgebungsvariablen laden, falls die Datei existiert
if (file_exists(APP_ROOT . '/config/load_env.php')) {
    require_once APP_ROOT . '/config/load_env.php';
}

$lifetime = 7 * 24 * 60 * 60; // 7 Tage

// 1. Session-Cookie-Parameter modern setzen
// Die Angabe 'path' => '/' ist wichtig, damit das Cookie überall gültig ist
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    'secure' => false,      // Auf 'true' setzen, wenn du HTTPS verwendest (empfohlen!)
    'httponly' => true,     // Schützt vor XSS-Angriffen
    'samesite' => 'Lax'     // Hilft gegen CSRF
]);

// 2. Serverseitige Lebensdauer (Garbage Collector) anpassen
ini_set('session.gc_maxlifetime', $lifetime);
ini_set('default_charset', 'UTF-8');

// Den Namen der aktuell aufgerufenen Datei herausfinden (z. B. "pricing.php")
$current_script = basename($_SERVER['SCRIPT_NAME']);

// Array mit Dateien, die KEINEN Passwortschutz haben sollen
$excluded_scripts = [
    'pricing.php', 
    'preis_update.php'
];

// Prüfen, ob das Skript über die Konsole (Cronjob) ausgeführt wird
$is_cli = php_sapi_name() === 'cli';

// Den Schutz nur ausführen, wenn die aktuelle Datei nicht in den Ausnahmen steht 
// und es sich nicht um einen reinen Konsolen-Aufruf handelt
if (!in_array($current_script, $excluded_scripts) && !$is_cli) {

    // Session starten, um den Login-Status zu speichern
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Passwort aus der .env-Datei auslesen (Fallback auf $_ENV oder getenv)
    $site_password = $_ENV['SITE_PASSWORD'] ?? getenv('SITE_PASSWORD') ?? null;

    // Passwortschutz nur aktivieren, wenn ein Passwort in der .env hinterlegt ist
    if (!empty($site_password)) {

        // Login-Überprüfung
        if (isset($_POST['login_password'])) {
            if ($_POST['login_password'] === $site_password) {
                $_SESSION['is_logged_in'] = true;
                
                // Nach dem Login auf die gleiche Seite weiterleiten
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit;
            } else {
                $error_msg = "Falsches Passwort!";
            }
        }

        // Wenn nicht eingeloggt, zeige das Formular und breche das Skript ab
        if (empty($_SESSION['is_logged_in'])) {
            ?>
            <!DOCTYPE html>
            <html lang="de">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Login erforderlich</title>
                <style>
                    body { font-family: Arial, sans-serif; background-color: #f4f4f9; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
                    .login-container { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center; width: 300px; }
                    input[type="password"] { width: 100%; padding: 10px; margin: 15px 0; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
                    button { background-color: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; width: 100%; }
                    button:hover { background-color: #0056b3; }
                    .error { color: red; margin-bottom: 10px; font-size: 0.9em; }
                </style>
            </head>
            <body>
                <div class="login-container">
                    <h3>Passwortgeschützter Bereich</h3>
                    <?php if (!empty($error_msg)) echo "<div class='error'>$error_msg</div>"; ?>
                    <form method="POST" action="">
                        <input type="password" name="login_password" placeholder="Passwort eingeben" required autofocus>
                        <button type="submit">Anmelden</button>
                    </form>
                </div>
            </body>
            </html>
            <?php
            // Skript hier abbrechen
            exit; 
        }
    }
}