<?php
// notifikasi.php — riwayat aktivitas sistem untuk lonceng notifikasi:
// pembayaran, tagihan baru, member baru, kehadiran, evaluasi, kuota hampir habis, pengaturan diubah.
ini_set('display_errors', '0');
ob_start();
date_default_timezone_set('Asia/Jakarta');
require __DIR__ . '/config/koneksi.php';

const NT_HARI = 45;   // aktivitas lebih lama dari ini tidak ditampilkan

function nt_ada(PDO $pdo, string $tabel, string $kolom = '*'): bool
{
    try { $pdo->query("SELECT $kolom FROM `$tabel` LIMIT 0"); return true; } catch (Throwable $e) { return false; }
}
function nt_ambil(PDO $pdo, string $sql, array $p = []): array
{
    try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { error_log('[notifikasi] ' . $e->getMessage()); return []; }
}
function nt_kolom_pertama(PDO $pdo, string $tabel, array $kandidat): ?string
{
    foreach ($kandidat as $k) if (nt_ada($pdo, $tabel, "`$k`")) return $k;
    return null;
}
function nt_rp($n): string { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }
function nt_jam(?string $w): string
{
    if (!$w) return '';
    $t = strtotime($w);
    if (!$t) return '';
    $hariIni = date('Y-m-d');
    $tgl = date('Y-m-d', $t);
    $bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    if (date('H:i:s', $t) === '00:00:00') {            // kolom bertipe tanggal saja
        if ($tgl === $hariIni) return 'Hari ini';
        if ($tgl === date('Y-m-d', strtotime('-1 day'))) return 'Kemarin';
        return (int)date('j', $t) . ' ' . $bln[(int)date('n', $t)];
    }
    $d = time() - $t;
    if ($d < 60) return 'Baru saja';
    if ($d < 3600) return floor($d / 60) . ' mnt lalu';
    if ($tgl === $hariIni) return floor($d / 3600) . ' jam lalu';
    if ($tgl === date('Y-m-d', strtotime('-1 day'))) return 'Kemarin ' . date('H:i', $t);
    return (int)date('j', $t) . ' ' . $bln[(int)date('n', $t)];
}

$batas = date('Y-m-d H:i:s', strtotime('-' . NT_HARI . ' days'));
$rows = [];
$tambah = function (string $id, string $tipe, string $judul, string $isi, string $href, ?string $w) use (&$rows) {
    $rows[] = ['id' => $id, 'tipe' => $tipe, 'judul' => $judul, 'isi' => $isi, 'href' => $href, 'w' => $w, 'jam' => nt_jam($w)];
};

// 1. Pembayaran diterima
foreach (nt_ambil($pdo, "SELECT p.id, p.jumlah, p.tanggal_bayar AS w, m.nama, t.nomor_tagihan
        FROM pembayaran p
        LEFT JOIN tagihan t ON t.id = p.tagihan_id
        LEFT JOIN members m ON m.id = t.member_id
        WHERE p.tanggal_bayar >= ? ORDER BY p.tanggal_bayar DESC, p.id DESC LIMIT 8", [$batas]) as $r) {
    $tambah('bayar-' . $r['id'], 'bayar', 'Pembayaran diterima',
        trim(($r['nama'] ?: 'Pembayaran') . ' · ' . nt_rp($r['jumlah'])), 'administrasi.php', $r['w']);
}

// 2. Tagihan baru dibuat (kolom waktu dicari otomatis)
if ($kt = nt_kolom_pertama($pdo, 'tagihan', ['created_at', 'dibuat_pada', 'tanggal_tagihan', 'tanggal'])) {
    foreach (nt_ambil($pdo, "SELECT t.id, t.nomor_tagihan, t.jumlah, t.`$kt` AS w, m.nama
            FROM tagihan t LEFT JOIN members m ON m.id = t.member_id
            WHERE t.`$kt` >= ? ORDER BY t.`$kt` DESC, t.id DESC LIMIT 5", [$batas]) as $r) {
        $tambah('tagihan-' . $r['id'], 'tagihan', 'Tagihan baru dibuat',
            trim(($r['nama'] ?: ($r['nomor_tagihan'] ?? 'Tagihan')) . ' · ' . nt_rp($r['jumlah'])), 'administrasi.php', $r['w']);
    }
}

// 3. Member baru
foreach (nt_ambil($pdo, "SELECT id, nama, created_at AS w FROM members WHERE created_at >= ? ORDER BY created_at DESC, id DESC LIMIT 6", [$batas]) as $r) {
    $tambah('member-' . $r['id'], 'member', 'Member baru terdaftar', (string)$r['nama'], 'cdm-detail-profil.php?id=' . (int)$r['id'], $r['w']);
}

// 4. Kehadiran dicatat
$labelHadir = ['hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit', 'alpa' => 'Alpa'];
foreach (nt_ambil($pdo, "SELECT a.id, a.status, a.waktu AS w, m.nama, kp.nama_program
        FROM presensi a
        JOIN members m ON m.id = a.member_id
        JOIN jadwal j ON j.id = a.jadwal_id
        JOIN kelas_program kp ON kp.id = j.program_id
        WHERE a.waktu >= ? ORDER BY a.waktu DESC, a.id DESC LIMIT 6", [$batas]) as $r) {
    $tambah('hadir-' . $r['id'], 'hadir', 'Kehadiran dicatat',
        $r['nama'] . ' · ' . ($labelHadir[$r['status']] ?? $r['status']) . ' · ' . $r['nama_program'], 'kehadiran.php', $r['w']);
}

// 5. Evaluasi diperbarui
foreach (nt_ambil($pdo, "SELECT e.id, e.nilai, e.updated_at AS w, m.nama
        FROM evaluasi e JOIN members m ON m.id = e.member_id
        WHERE e.status = 'selesai' AND e.updated_at >= ? ORDER BY e.updated_at DESC, e.id DESC LIMIT 5", [$batas]) as $r) {
    $tambah('eval-' . $r['id'] . '-' . strtotime((string)$r['w']), 'eval', 'Evaluasi diperbarui',
        $r['nama'] . ($r['nilai'] !== null ? ' · nilai ' . $r['nilai'] : ''), 'kehadiran.php', $r['w']);
}

// 6. Permintaan reschedule / jadwal diubah (kalau tabelnya punya kolom waktu)
if (nt_ada($pdo, 'reschedule') && ($kr = nt_kolom_pertama($pdo, 'reschedule', ['created_at', 'dibuat_pada', 'updated_at']))) {
    foreach (nt_ambil($pdo, "SELECT id, `$kr` AS w FROM reschedule WHERE `$kr` >= ? ORDER BY `$kr` DESC, id DESC LIMIT 4", [$batas]) as $r) {
        $tambah('resch-' . $r['id'], 'jadwal', 'Jadwal diubah', 'Ada perubahan atau permintaan reschedule kelas', 'jadwal-kelas.php', $r['w']);
    }
}

// 7. Pengaturan diperbarui
foreach (nt_ambil($pdo, "SELECT id, aksi, waktu AS w FROM log_pengaturan WHERE waktu >= ? ORDER BY waktu DESC, id DESC LIMIT 5", [$batas]) as $r) {
    $tambah('set-' . $r['id'], 'set', 'Pengaturan diperbarui', (string)$r['aksi'], 'pengaturan-profil.php', $r['w']);
}

// urut dari yang terbaru, batasi 16 aktivitas
usort($rows, function ($a, $b) { return strtotime((string)$b['w']) <=> strtotime((string)$a['w']); });
$rows = array_slice($rows, 0, 16);

// 8. Kuota hampir habis (status saat ini, tidak punya waktu kejadian)
foreach (array_slice(nt_ambil($pdo, "SELECT paket_id, member_id, nama, sisa_sesi, total_sesi FROM v_kuota_hampir_habis ORDER BY sisa_sesi ASC"), 0, 4) as $r) {
    $tambah('kuota-' . $r['paket_id'] . '-' . $r['sisa_sesi'], 'kuota', 'Kuota hampir habis',
        $r['nama'] . ' · sisa ' . $r['sisa_sesi'] . '/' . $r['total_sesi'] . ' sesi', 'cdm-detail-profil.php?id=' . (int)$r['member_id'], null);
}

foreach ($rows as &$r) unset($r['w']);
unset($r);
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
