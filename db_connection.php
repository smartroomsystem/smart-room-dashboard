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

    // Railway's managed MySQL runs its server clock in UTC. Every
    // "recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP" column (and any
    // NOW()) was therefore being written in UTC, while the PHP-side
    // date_default_timezone_set('Asia/Manila') only affects PHP's own
    // date()/time() calls — it never touched MySQL's clock. That mismatch
    // is why the Railway table showed 00:59 while it was actually ~08:59
    // in Manila.
    //
    // Setting the session time zone to a fixed +08:00 offset (Manila does
    // not observe DST, so this never needs to change) makes CURRENT_TIMESTAMP
    // and NOW() return Manila local time for every query on this connection,
    // going forward — no PHP/JS changes required elsewhere.
    $pdo->exec("SET time_zone = '+08:00'");

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
