<?php
function ambil(PDO $pdo, string $sql, array $p = []): array {
	$st = $pdo->prepare($sql);
	$st->execute($p);
	return $st->fetchAll(PDO::FETCH_ASSOC);
}
function nilai(PDO $pdo, string $sql, array $p = []) {
	$st = $pdo->prepare($sql);
	$st->execute($p);
	return $st->fetchColumn();
}
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function inisial(?string $nama): string {
	$nama = trim(preg_replace('/^coach\s+/i', '', (string)$nama));
	if ($nama === '') return '?';
	$k = preg_split('/\s+/', $nama);
	$h = mb_substr($k[0], 0, 1) . (count($k) > 1 ? mb_substr(end($k), 0, 1) : mb_substr($k[0], 1, 1));
	return mb_strtoupper($h);
}
/**
 * Format nomor WhatsApp ke format XX-XXXX-XXXX
 */
function formatNoWA(?string $no): string {
    $no = preg_replace('/\D/', '', (string)$no);
    if (strlen($no) < 10) return $no ?: '-';
    return substr($no, 0, 4) . '-' . substr($no, 4, 4) . '-' . substr($no, 8);
}

function urlFilter(array $ubah = []): string {
	$q = array_merge($_GET, $ubah);
	foreach ($q as $k => $v) if ($v === '' || $v === null || $v === '0' || $v === 0) unset($q[$k]);
	return 'datamember.php' . ($q ? '?' . http_build_query($q) : '');
}
function persen($baru, $lama): string {
	if ($lama <= 0) return $baru > 0 ? '+100%' : '0%';
	$d = round(($baru - $lama) / $lama * 100);
	return ($d >= 0 ? '+' : '') . $d . '%';
}
function eksekusi(PDO $pdo, string $sql, array $p = []): bool {
    $st = $pdo->prepare($sql);
    return $st->execute($p);
}
