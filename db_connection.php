<?php
define('DB_HOST', getenv('MYSQLHOST'));
define('DB_PORT', getenv('MYSQLPORT'));
define('DB_NAME', getenv('MYSQLDATABASE'));
define('DB_USER', getenv('MYSQLUSER'));
define('DB_PASS', getenv('MYSQLPASSWORD'));
define('DB_CHARSET', 'utf8mb4');

try {
    $dsn = "mysql:host=" . DB_HOST .
           ";port=" . DB_PORT .
           ";dbname=" . DB_NAME .
           ";charset=" . DB_CHARSET;

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

?>