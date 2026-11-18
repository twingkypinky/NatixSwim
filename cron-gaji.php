<?php
// ============================================================
// cron-gaji.php — dijalankan 1x sehari (mis. pukul 08.00)
//   Terminal : php /path/ke/NatixSwim/cron-gaji.php
//   Via URL  : cron-gaji.php?token=<NATIX_GW_TOKEN>   (untuk layanan cron eksternal, mis. di Railway)
// Tugas: (1) pada tanggal "hitung draft" (default tgl 1) buat draft gaji bulan lalu,
//        (2) kirim slip gaji berstatus Dibayar yang belum terkirim ke WhatsApp coach.
// ============================================================
date_default_timezone_set('Asia/Jakarta');
require __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/gaji-fungsi.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals((string)GW_TOKEN, (string)($_GET['token'] ?? ''))) { http_response_code(403); exit("Token salah.\n"); }
}
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo date('Y-m-d H:i:s') . ' ' . json_encode(gjCron($pdo), JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
