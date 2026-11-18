<?php
// Koneksi database. Otomatis memakai variabel Railway (MYSQLHOST, dst.)
// bila ada; kalau tidak, memakai pengaturan XAMPP lokal di bawah.
$dbHost = getenv('MYSQLHOST')     ?: 'localhost';
$dbPort = getenv('MYSQLPORT')     ?: '3306';
$dbUser = getenv('MYSQLUSER')     ?: 'root';
$dbPass = getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '';
$dbName = getenv('MYSQLDATABASE') ?: 'natix_swim';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    try { $pdo->exec("SET time_zone = '+07:00'"); } catch (Throwable $e) {}
} catch (PDOException $e) {
    error_log('[koneksi] ' . $e->getMessage());
    http_response_code(500);
    exit('Koneksi database gagal. Periksa pengaturan database.');
}
