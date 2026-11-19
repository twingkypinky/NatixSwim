<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Jakarta');
require_once 'config/koneksi.php';
require_once 'config/helpers.php';

function pg_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pg_json(array $d, int $c = 200): void
{
    while (ob_get_level()) ob_end_clean();
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function pg_body(): array
{
    $in = json_decode((string)file_get_contents('php://input'), true);
    return is_array($in) ? $in : $_POST;
}
function pg_mysql(PDO $pdo): bool { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }
function pg_kolom_ada(PDO $pdo, string $t, string $k): bool
{
    try { $pdo->query("SELECT `$k` FROM `$t` LIMIT 0"); return true; } catch (Throwable $e) { return false; }
}

// Tabel pendukung dibuat otomatis (aman dijalankan berulang)
function pg_migrasi(PDO $pdo): void
{
    try {
        $ai = pg_mysql($pdo) ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec("CREATE TABLE IF NOT EXISTS pengaturan (kunci VARCHAR(60) NOT NULL PRIMARY KEY, nilai TEXT NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fasilitas_kolam (id $ai, nama VARCHAR(100) NOT NULL, keterangan VARCHAR(150) NULL, urutan INT NOT NULL DEFAULT 0)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS log_pengaturan (id $ai, aksi VARCHAR(200) NOT NULL, waktu DATETIME NOT NULL)");
        if (!(int)$pdo->query('SELECT COUNT(*) FROM fasilitas_kolam')->fetchColumn()) {
            $ins = $pdo->prepare('INSERT INTO fasilitas_kolam (nama, keterangan, urutan) VALUES (?, ?, ?)');
            foreach ([['Kolam A (Dewasa)', '25 m • 1,2–1,8 m'], ['Kolam B (Anak)', '12 m • 0,6–1 m'], ['Kolam Baby Swim', 'Air hangat']] as $i => $f) $ins->execute([$f[0], $f[1], $i + 1]);
        }
        if (!pg_kolom_ada($pdo, 'users', 'last_login')) {
            try { $pdo->exec('ALTER TABLE users ADD COLUMN last_login ' . (pg_mysql($pdo) ? 'DATETIME' : 'TEXT') . ' NULL'); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) { error_log('[pengaturan] migrasi: ' . $e->getMessage()); }
}
pg_migrasi($pdo);

function pg_semua(PDO $pdo): array
{
    $o = [];
    try { foreach ($pdo->query('SELECT kunci, nilai FROM pengaturan') as $r) $o[$r['kunci']] = (string)$r['nilai']; } catch (Throwable $e) {}
    return $o;
}
function pg_simpan(PDO $pdo, string $k, ?string $v): void
{
    $a = $pdo->prepare('SELECT COUNT(*) FROM pengaturan WHERE kunci = ?'); $a->execute([$k]);
    if ($v === null) { $pdo->prepare('DELETE FROM pengaturan WHERE kunci = ?')->execute([$k]); return; }
    if ((int)$a->fetchColumn()) $pdo->prepare('UPDATE pengaturan SET nilai = ? WHERE kunci = ?')->execute([$v, $k]);
    else $pdo->prepare('INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?)')->execute([$k, $v]);
}
function pg_log(PDO $pdo, string $teks): void
{
    try { $pdo->prepare('INSERT INTO log_pengaturan (aksi, waktu) VALUES (?, ?)')->execute([$teks, date('Y-m-d H:i:s')]); } catch (Throwable $e) {}
}
function pg_waktu($dt): string
{
    $t = strtotime((string)$dt);
    if (!$t) return '–';
    $b = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return date('j', $t) . ' ' . $b[(int)date('n', $t)] . ' ' . date('Y', $t) . ' • ' . date('H:i', $t);
}
function pg_tabs(string $aktif): void
{
    $t = ['profil' => ['Profil Bisnis', 'pengaturan-profil.php'], 'akun' => ['Akun & Hak Akses', 'pengaturan-akun.php'], 'integrasi' => ['Integrasi WhatsApp', 'pengaturan-integrasi.php']];
    echo '<nav class="pg-tabs" aria-label="Bagian pengaturan">';
    foreach ($t as $k => $v) echo '<a href="' . $v[1] . '" class="pg-tab' . ($k === $aktif ? ' is-on' : '') . '"' . ($k === $aktif ? ' aria-current="page"' : '') . '>' . pg_e($v[0]) . '</a>';
    echo '</nav>';
}


const PG_PERAN = ['owner' => 'Owner', 'admin' => 'Admin', 'cs' => 'Kasir'];
function pg_sandi(): string { $s = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $o = ''; for ($i = 0; $i < 10; $i++) $o .= $s[random_int(0, strlen($s) - 1)]; return $o; }
function pg_inisial(string $s): string { $p = preg_split('/\s+/', trim($s)); $a = mb_substr($p[0] ?? '', 0, 1); $b = count($p) > 1 ? mb_substr($p[1], 0, 1) : mb_substr($p[0] ?? '', 1, 1); return mb_strtoupper($a . $b) ?: '--'; }
function pg_login_label($dt): string
{
    $t = strtotime((string)$dt); if (!$dt || !$t) return '–';
    $b = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    if (date('Y-m-d', $t) === date('Y-m-d')) return 'Hari ini, ' . date('H.i', $t);
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Kemarin, ' . date('H.i', $t);
    return date('j', $t) . ' ' . $b[(int)date('n', $t)] . ' ' . date('Y', $t);
}

$aksi = $_GET['aksi'] ?? '';
if ($aksi !== '') {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pg_json(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
    $in = pg_body();
    try {
        if ($aksi === 'tambah') {
            $nama = trim((string)($in['nama'] ?? '')); $email = strtolower(trim((string)($in['email'] ?? ''))); $peran = (string)($in['peran'] ?? '');
            if ($nama === '' || mb_strlen($nama) > 100) pg_json(['ok' => false, 'pesan' => 'Nama wajib diisi (maksimal 100 karakter).'], 422);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) pg_json(['ok' => false, 'pesan' => 'Format email tidak valid.'], 422);
            if (!in_array($peran, ['admin', 'cs'], true)) pg_json(['ok' => false, 'pesan' => 'Peran tidak valid.'], 422);
            $c = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?'); $c->execute([$email]);
            if ((int)$c->fetchColumn()) pg_json(['ok' => false, 'pesan' => 'Email ' . $email . ' sudah terdaftar.'], 409);
            $sandi = pg_sandi();
            $pdo->prepare("INSERT INTO users (nama, email, password_hash, role, status) VALUES (?, ?, ?, ?, 'aktif')")->execute([$nama, $email, password_hash($sandi, PASSWORD_DEFAULT), $peran]);
            pg_log($pdo, 'Akun ditambah: ' . $nama . ' (' . PG_PERAN[$peran] . ')');
            pg_json(['ok' => true, 'pesan' => 'Akun ' . $nama . ' berhasil dibuat.', 'sandi' => $sandi]);
        }

        if ($aksi === 'edit') {
            $id = (int)($in['id'] ?? 0);
            $s = $pdo->prepare('SELECT id, nama, role FROM users WHERE id = ?'); $s->execute([$id]);
            $u = $s->fetch(PDO::FETCH_ASSOC);
            if (!$u || !isset(PG_PERAN[$u['role']])) pg_json(['ok' => false, 'pesan' => 'Akun tidak ditemukan.'], 404);
            $nama = trim((string)($in['nama'] ?? '')); $peran = (string)($in['peran'] ?? $u['role']); $status = (string)($in['status'] ?? 'aktif');
            if ($nama === '' || mb_strlen($nama) > 100) pg_json(['ok' => false, 'pesan' => 'Nama wajib diisi (maksimal 100 karakter).'], 422);
            if ($u['role'] === 'owner') { $peran = 'owner'; $status = 'aktif'; }
            elseif (!in_array($peran, ['admin', 'cs'], true)) pg_json(['ok' => false, 'pesan' => 'Peran tidak valid.'], 422);
            if (!in_array($status, ['aktif', 'non_aktif'], true)) pg_json(['ok' => false, 'pesan' => 'Status tidak valid.'], 422);
            $pdo->prepare('UPDATE users SET nama = ?, role = ?, status = ? WHERE id = ?')->execute([$nama, $peran, $status, $id]);
            $o = ['ok' => true, 'pesan' => 'Data akun ' . $nama . ' berhasil diperbarui.'];
            if (!empty($in['reset'])) {
                $sandi = pg_sandi();
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($sandi, PASSWORD_DEFAULT), $id]);
                $o['sandi'] = $sandi;
            }
            pg_log($pdo, 'Akun diubah: ' . $nama . (!empty($in['reset']) ? ' (password direset)' : ''));
            pg_json($o);
        }

        if ($aksi === 'hapus') {
            $id = (int)($in['id'] ?? 0);
            $s = $pdo->prepare('SELECT nama, role FROM users WHERE id = ?'); $s->execute([$id]);
            $u = $s->fetch(PDO::FETCH_ASSOC);
            if (!$u || !isset(PG_PERAN[$u['role']])) pg_json(['ok' => false, 'pesan' => 'Akun tidak ditemukan.'], 404);
            if ($u['role'] === 'owner') pg_json(['ok' => false, 'pesan' => 'Akun Owner tidak dapat dihapus.'], 403);
            try { $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]); }
            catch (Throwable $e) { pg_json(['ok' => false, 'pesan' => 'Akun ini masih terhubung dengan data lain. Nonaktifkan akun lewat tombol Ubah.'], 409); }
            pg_log($pdo, 'Akun dihapus: ' . $u['nama']);
            pg_json(['ok' => true, 'pesan' => 'Akun ' . $u['nama'] . ' telah dihapus.']);
        }

        if ($aksi === 'keamanan') {
            $kunci = (string)($in['kunci'] ?? ''); $nilai = !empty($in['nilai']) ? '1' : '0';
            $label = ['sec_2fa' => 'Verifikasi 2 Langkah', 'sec_logout' => 'Logout Otomatis'];
            if (!isset($label[$kunci])) pg_json(['ok' => false, 'pesan' => 'Pengaturan tidak dikenal.'], 422);
            pg_simpan($pdo, $kunci, $nilai);
            pg_log($pdo, $label[$kunci] . ($nilai === '1' ? ' diaktifkan' : ' dinonaktifkan'));
            pg_json(['ok' => true, 'pesan' => $label[$kunci] . ($nilai === '1' ? ' diaktifkan.' : ' dinonaktifkan.')]);
        }

        pg_json(['ok' => false, 'pesan' => 'Aksi tidak dikenal.'], 400);
    } catch (Throwable $e) {
        error_log('[pengaturan-akun] ' . $e->getMessage());
        pg_json(['ok' => false, 'pesan' => 'Terjadi kesalahan pada server: ' . $e->getMessage()], 500);
    }
}

$cfg = pg_semua($pdo);
$sec2fa = ($cfg['sec_2fa'] ?? '1') === '1';
$secOut = ($cfg['sec_logout'] ?? '1') === '1';
$ada = pg_kolom_ada($pdo, 'users', 'last_login');
$akun = ambil($pdo, "SELECT id, nama, email, role, status" . ($ada ? ', last_login' : ", NULL AS last_login") . " FROM users WHERE role IN ('owner','admin','cs')
                     ORDER BY CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, nama");
$ic_edit = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4l10.5-10.5a2.1 2.1 0 00-3-3L5 17v3z"/><path d="M13.5 8.5l3 3"/></svg>';
$ic_del = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>';
?>
<?php
/* ===== NATIX LAYOUT: menu, sidebar & breadcrumb — blok ini sama di semua halaman ===== */
if (!function_exists('nvSidebar')) {
    function nvH($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

    // Daftar menu. Ubah di sini (dan samakan di semua halaman) kalau nama file berubah.
    // [label, file, ikon, halaman turunan yang ikut menyalakan menu ini, belum dibuat?]
    function nvMenu(): array
    {
        return [
            ['Natix Swim', [
                ['Dashboard', 'dashboard-admin.php', 'grid', [], false],
            ]],
            ['Customer Data', [
                ['Data Member', 'datamember.php', 'user', ['cdm-detail-profil.php'], false],
                ['Segmentasi', 'cdm-segmentasi.php', 'pie', [], false],
            ]],
            ['Operasional', [
                ['Kelas & Program', 'manajemen-kelas.php', 'box', [], false],
                ['Jadwal Kelas', 'jadwal-kelas.php', 'calendar', [], false],
                ['Data Pelatih', 'data-pelatih-daftar.php', 'user', ['data-pelatih-detail.php'], false],
                ['Kehadiran', 'kehadiran.php', 'check', [], false],
                ['Progres Murid', 'progres.php', 'award', [], false],
            ]],
            ['Administrasi', [
                ['Transaksi', 'administrasi.php', 'card', ['administrasi-detail.php'], false],
                ['Laporan Keuangan', 'administrasi-laporan.php', 'chart', [], false],
                ['Payroll', 'gaji.php', 'wallet', [], false],
            ]],
            ['AI Assistant', [
                ['Chat Log', 'manajemen-ai-chat.php', 'chat', [], true],
                ['Knowledge Base', 'manajemen-ai1.php', 'note', [], false],
                ['Otomasi Pesan', 'manajemen-ai2.php', 'zap', [], false],
                ['Analitik AI', 'manajemen-ai-analitik.php', 'trend', [], true],
            ]],
            ['Sistem', [
                ['Pengaturan', 'pengaturan-profil.php', 'gear', ['pengaturan-akun.php', 'pengaturan-integrasi.php'], false],
            ]],
        ];
    }

    function nvIkon(string $n): string
    {
        $p = [
            'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/>',
            'pie'      => '<path d="M21 12A9 9 0 1112 3v9z"/><path d="M15 3.5A9 9 0 0120.5 9H15z"/>',
            'box'      => '<path d="M12 2.5l8.5 4.9v9.2L12 21.5l-8.5-4.9V7.4z"/>',
            'calendar' => '<rect x="3.5" y="5" width="17" height="16" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
            'check'    => '<path d="M20 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2h9"/><path d="M8.5 11.5l3 3L21 5"/>',
            'card'     => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19"/>',
            'award'    => '<circle cx="12" cy="9" r="6"/><path d="M8.5 14.2L7 22l5-3 5 3-1.5-7.8"/>',
            'wallet'   => '<path d="M3 7a2 2 0 012-2h13v4"/><path d="M3 7v11a2 2 0 002 2h15a1 1 0 001-1v-3"/><path d="M21 9h-4a2 2 0 000 4h4z"/>',
            'chart'    => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
            'chat'     => '<path d="M21 11.5a8.5 8.5 0 01-12.6 7.4L3 20.5l1.6-5A8.5 8.5 0 1121 11.5z"/>',
            'note'     => '<path d="M21 15a2 2 0 01-2 2H8l-5 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>',
            'zap'      => '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
            'trend'    => '<path d="M3 3v18h18"/><path d="M7 16l4-5 3 2 5-7"/>',
            'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
        ];
        return '<svg class="nv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? $p['grid']) . '</svg>';
    }

    // [grup, item] untuk halaman yang sedang dibuka (null kalau tidak ada di menu)
    function nvCari(): ?array
    {
        $f = basename((string)parse_url((string)($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH));
        foreach (nvMenu() as $g)
            foreach ($g[1] as $it)
                if ($it[1] === $f || in_array($f, $it[3], true)) return [$g[0], $it, $it[1] === $f];
        return null;
    }

    function nvSidebar(): void
    {
        $cur = nvCari();
        echo '<aside class="nv-side" id="nv-side" aria-label="Navigasi utama">';
        echo '<a class="nv-brand" href="dashboard-admin.php"><img src="assets/brand/navbar-logo.png" alt=""><span>Natix Swim</span></a>';
        echo '<nav class="nv-scroll">';
        foreach (nvMenu() as $gi => $g) {
            if ($gi > 0) echo '<p class="nv-group">' . nvH($g[0]) . '</p>';
            foreach ($g[1] as $it) {
                $aktif = $cur && $cur[1][1] === $it[1];
                $href = $it[4] ? '#' : $it[1];
                echo '<a class="nv-item' . ($aktif ? ' is-active' : '') . '" href="' . nvH($href) . '"'
                    . ($aktif ? ' aria-current="page"' : '') . ($it[4] ? ' data-belum="' . nvH($it[0]) . '"' : '') . '>'
                    . nvIkon($it[2]) . '<span>' . nvH($it[0]) . '</span>'
                    . ($it[1] === 'manajemen-ai-chat.php' ? '<b class="nv-badge" id="nv-badge" hidden>0</b>' : '') . '</a>';
            }
        }
        echo '</nav>';
        echo '<div class="nv-user"><div class="nv-user-row"><span class="nv-av">AD</span><div><strong>Admin Natix</strong><small>Owner</small></div></div>'
            . '<a class="nv-logout" href="login.php" title="Logout" aria-label="Logout"><svg class="nv-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg></a></div>';
        echo '</aside><div class="nv-scrim" id="nv-scrim"></div>';
    }

    // Breadcrumb dari menu: "Operasional / Kelas & Program". $extra = segmen tambahan, mis. [[$nomorInvoice]] atau [['Nama', 'url']]
    function nvCrumb(array $extra = []): string
    {
        $cur = nvCari();
        $seg = [];
        if ($cur) {
            $seg[] = [$cur[0], null];
            $seg[] = [$cur[1][0], ($extra || !$cur[2]) ? $cur[1][1] : null];
        } else {
            $seg[] = ['Natix Swim', null];
        }
        foreach ($extra as $e) $seg[] = [(string)$e[0], $e[1] ?? null];
        $o = '<nav class="nv-crumb" aria-label="Breadcrumb">';
        foreach ($seg as $i => $s) {
            if ($i) $o .= '<span class="nv-sep">/</span>';
            $akhir = $i === count($seg) - 1;
            $o .= $s[1] ? '<a href="' . nvH($s[1]) . '">' . nvH($s[0]) . '</a>'
                        : '<span' . ($akhir ? ' aria-current="page" class="is-now"' : '') . '>' . nvH($s[0]) . '</span>';
        }
        return $o . '</nav>';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<title>Pengaturan – Akun & Hak Akses — Natix Swim</title>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
	<style>
/* ===== Popup (di luar .dpx) ===== */
.dpd-overlay { position: fixed; inset: 0; background: rgba(17,24,39,.5); z-index: 2000; display: none; align-items: center; justify-content: center; padding: 16px; }
.dpd-overlay.show { display: flex; animation: dpd-fade .15s ease-out; }
@keyframes dpd-fade { from { opacity: 0; } to { opacity: 1; } }
.dpd-box { background: #fff; border-radius: 18px; width: 100%; max-width: 400px; padding: 26px 24px 22px; box-shadow: 0 24px 60px rgba(16,24,40,.28); font-family: 'Inter', sans-serif; color: #111827; text-align: center; max-height: calc(100vh - 32px); overflow-y: auto; animation: dpd-pop .18s ease-out; }
.dpd-box.wide { max-width: 480px; text-align: left; }
@keyframes dpd-pop { from { opacity: 0; transform: translateY(8px) scale(.97); } to { opacity: 1; transform: none; } }
.dpd-box h3 { font-size: 18px; font-weight: 700; margin: 0 0 8px; }
.dpd-box > p { font-size: 14px; color: #6b7280; line-height: 1.55; margin: 0; }
.dpd-ico { width: 54px; height: 54px; border-radius: 50%; margin: 0 auto 14px; display: flex; align-items: center; justify-content: center; background: #eff6ff; color: #2563eb; }
.dpd-ico svg { width: 28px; height: 28px; }
.dpd-box.bahaya .dpd-ico, .dpd-box.gagal .dpd-ico { background: #fef2f2; color: #dc2626; }
.dpd-box.sukses .dpd-ico { background: #dcfce7; color: #16a34a; }
.dpd-box.wide .dpd-ico { display: none; }
.dpd-act { display: flex; gap: 10px; justify-content: flex-end; margin-top: 22px; }
.dpd-box:not(.wide) .dpd-act { justify-content: center; }
.dpd-btn { font-family: inherit; font-size: 14px; font-weight: 600; height: 40px; padding: 0 20px; border-radius: 10px; border: 1px solid #e5e7eb; background: #fff; color: #374151; cursor: pointer; }
.dpd-btn:hover { background: #f9fafb; }
.dpd-btn.pri { background: #2563eb; border-color: #2563eb; color: #fff; } .dpd-btn.pri:hover { background: #1d4ed8; }
.dpd-btn.pri.red { background: #dc2626; border-color: #dc2626; } .dpd-btn.pri.red:hover { background: #b91c1c; }
.dpd-btn:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; }
.dpd-fg { margin-top: 14px; flex: 1; min-width: 0; } .dpd-fg label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
.dpd-fg input, .dpd-fg select, .dpd-fg textarea { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 10px; font: inherit; font-size: 14px; color: #111827; background: #fff; }
.dpd-fg textarea { resize: vertical; min-height: 110px; line-height: 1.5; }
.dpd-fg input:focus, .dpd-fg select:focus, .dpd-fg textarea:focus { outline: 0; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
.dpd-row { display: flex; gap: 12px; }
.dpd-sub { font-size: 13px !important; margin-top: -2px !important; }
#dpd-info { z-index: 2100; }
.dpd-fg textarea { min-height: 64px; }
#dpd-info { z-index: 2100; }
/* ===== Pengaturan (prefix pg-) ===== */
.pg-page, .pg-page * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
.pg-page { padding: 28px 32px 56px; color: #111827; width: 100%; }
.pg-page h1, .pg-page h2, .pg-page h3, .pg-page p { margin: 0; }
.pg-title { font-size: 30px; font-weight: 700; line-height: 1.2; }
.pg-sub { font-size: 15px; color: #6b7280; margin-top: 4px !important; }
.pg-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-top: 6px; }
.pg-tabs { display: flex; gap: 28px; margin: 24px 0 22px; border-bottom: 1px solid #e5e7eb; overflow-x: auto; scrollbar-width: none; }
.pg-tabs::-webkit-scrollbar { display: none; }
.pg-tab { padding: 0 2px 12px; font-size: 15px; font-weight: 500; color: #6b7280; text-decoration: none; white-space: nowrap; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.pg-tab:hover { color: #111827; }
.pg-tab.is-on { color: #2563eb; font-weight: 600; border-bottom-color: #2563eb; }

.pg-grid { display: grid; grid-template-columns: minmax(0, 1.65fr) minmax(0, 1fr); gap: 20px; align-items: start; }
.pg-col { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
.pg-card { background: #fff; border: 1px solid #eceef2; border-radius: 18px; padding: 24px; min-width: 0; }
.pg-card h2 { font-size: 17px; font-weight: 700; }
.pg-card .pg-hint { font-size: 13.5px; color: #6b7280; margin-top: 4px !important; }
.pg-card-h { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }

/* Tombol & form */
.pg-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 42px; padding: 0 18px; border-radius: 12px; border: 1px solid #e5e7eb; background: #fff; color: #111827; font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.pg-btn:hover { background: #f9fafb; }
.pg-btn.pri { background: #2563eb; border-color: #2563eb; color: #fff; } .pg-btn.pri:hover { background: #1d4ed8; }
.pg-btn.red { color: #dc2626; border-color: #fecaca; } .pg-btn.red:hover { background: #fef2f2; }
.pg-btn.sm { height: 36px; padding: 0 14px; font-size: 13px; border-radius: 10px; }
.pg-btn:disabled { opacity: .5; cursor: not-allowed; }
.pg-btn svg { width: 16px; height: 16px; }
.pg-form { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 18px; }
.pg-f { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.pg-f.full { grid-column: 1 / -1; }
.pg-f label { font-size: 13px; font-weight: 600; color: #374151; }
.pg-in, .pg-sel, .pg-ta { width: 100%; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; padding: 11px 14px; font-size: 14px; color: #111827; }
.pg-in:focus, .pg-sel:focus, .pg-ta:focus { outline: 2px solid #bfdbfe; border-color: #93c5fd; }
.pg-sel { appearance: none; -webkit-appearance: none; padding-right: 38px; cursor: pointer; background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%236b7280' stroke-width='1.6' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 14px center; }
.pg-ta { resize: vertical; min-height: 84px; line-height: 1.5; }
.pg-foot { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; flex-wrap: wrap; }

/* Logo */
.pg-logo { display: flex; align-items: center; gap: 18px; padding: 16px; border: 1px dashed #d1d5db; border-radius: 14px; margin-bottom: 20px; flex-wrap: wrap; }
.pg-logo-img { width: 76px; height: 76px; border-radius: 16px; background: #eef2ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 700; overflow: hidden; flex: 0 0 auto; }
.pg-logo-img img { width: 100%; height: 100%; object-fit: cover; }
.pg-logo b { display: block; font-size: 14px; } .pg-logo small { display: block; font-size: 13px; color: #6b7280; margin: 2px 0 10px; }
.pg-logo-act { display: flex; gap: 8px; flex-wrap: wrap; }

/* Daftar kolam */
.pg-kolam { display: flex; flex-direction: column; margin-top: 14px; }
.pg-kolam li { list-style: none; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-bottom: 1px solid #f1f2f5; font-size: 14px; }
.pg-kolam li:last-child { border-bottom: 0; }
.pg-kolam span { color: #6b7280; font-size: 13px; text-align: right; }
.pg-kolam .x { border: 0; background: none; color: #9ca3af; cursor: pointer; font-size: 18px; line-height: 1; padding: 2px 6px; border-radius: 6px; flex: 0 0 auto; }
.pg-kolam .x:hover { color: #dc2626; background: #fef2f2; }
.pg-kolam ul { margin: 0; padding: 0; }
.pg-tip { background: #f5f9ff; border: 1px solid #dbeafe; color: #1e40af; border-radius: 14px; padding: 14px 16px; font-size: 13.5px; line-height: 1.55; }

/* Akun */
.pg-search { width: min(240px, 100%); }
.pg-tw { overflow-x: auto; margin: 0 -4px; padding: 0 4px; }
.pg-t { width: 100%; border-collapse: collapse; min-width: 560px; }
.pg-t th { text-align: left; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #9ca3af; padding: 0 10px 10px; border-bottom: 1px solid #f1f2f5; white-space: nowrap; }
.pg-t td { padding: 14px 10px; border-bottom: 1px solid #f6f7f9; font-size: 14px; vertical-align: middle; }
.pg-t tr:last-child td { border-bottom: 0; }
.pg-t .who { display: flex; align-items: center; gap: 12px; min-width: 0; }
.pg-av { width: 38px; height: 38px; border-radius: 50%; background: #eef2ff; color: #2563eb; font-weight: 700; font-size: 12.5px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
.pg-t .who b { display: block; font-weight: 600; } .pg-t .who small { display: block; color: #6b7280; font-size: 12.5px; overflow-wrap: anywhere; }
.pg-pill { display: inline-block; padding: 3px 12px; border-radius: 999px; font-size: 12.5px; font-weight: 600; white-space: nowrap; background: #f3f4f6; color: #4b5563; }
.pg-pill.owner { background: #fef3c7; color: #92400e; } .pg-pill.admin { background: #eef2ff; color: #2563eb; } .pg-pill.kasir { background: #f3f4f6; color: #4b5563; }
.pg-pill.ok { background: #dcfce7; color: #15803d; } .pg-pill.off { background: #fee2e2; color: #b91c1c; }
.pg-ic { width: 34px; height: 34px; border-radius: 10px; border: 1px solid #e5e7eb; background: #fff; color: #4b5563; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
.pg-ic:hover { background: #f9fafb; color: #111827; } .pg-ic.red { color: #dc2626; border-color: #fecaca; } .pg-ic.red:hover { background: #fef2f2; }
.pg-ic svg { width: 16px; height: 16px; }
.pg-acts { display: flex; gap: 8px; }
.pg-none { padding: 26px 8px; text-align: center; color: #9ca3af; font-size: 14px; }
.pg-mx { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 14px; }
.pg-mx th { font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #9ca3af; padding: 0 0 10px; text-align: center; border-bottom: 1px solid #f1f2f5; }
.pg-mx th:first-child, .pg-mx td:first-child { text-align: left; }
.pg-mx td { padding: 11px 0; border-bottom: 1px solid #f6f7f9; text-align: center; color: #9ca3af; }
.pg-mx tr:last-child td { border-bottom: 0; } .pg-mx td:first-child { color: #111827; } .pg-mx .y { color: #16a34a; font-weight: 700; }
.pg-sw-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 0; border-bottom: 1px solid #f1f2f5; }
.pg-sw-row:last-child { border-bottom: 0; padding-bottom: 0; }
.pg-sw-row b { display: block; font-size: 14px; font-weight: 600; } .pg-sw-row small { display: block; font-size: 13px; color: #6b7280; margin-top: 2px; }
.pg-sw { position: relative; width: 46px; height: 26px; border-radius: 999px; border: 0; background: #d1d5db; cursor: pointer; flex: 0 0 auto; padding: 0; transition: background .15s; }
.pg-sw::after { content: ''; position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: transform .15s; }
.pg-sw.on { background: #2563eb; } .pg-sw.on::after { transform: translateX(20px); }
.pg-sw:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; }

/* Integrasi */
.pg-banner { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 20px 24px; border-radius: 18px; border: 1px solid #bbf7d0; background: #f0fdf4; margin-bottom: 20px; }
.pg-banner.off { border-color: #fecaca; background: #fef2f2; } .pg-banner.wait { border-color: #fde68a; background: #fffbeb; }
.pg-banner-t { display: flex; gap: 14px; align-items: flex-start; min-width: 0; flex: 1 1 320px; }
.pg-dot { width: 12px; height: 12px; border-radius: 50%; background: #16a34a; margin-top: 6px; flex: 0 0 auto; box-shadow: 0 0 0 4px rgba(22,163,74,.18); }
.pg-banner.off .pg-dot { background: #dc2626; box-shadow: 0 0 0 4px rgba(220,38,38,.15); } .pg-banner.wait .pg-dot { background: #d97706; box-shadow: 0 0 0 4px rgba(217,119,6,.15); }
.pg-banner b { display: block; font-size: 16px; } .pg-banner p { font-size: 13.5px; color: #4b5563; margin-top: 3px !important; overflow-wrap: anywhere; }
.pg-banner-a { display: flex; gap: 10px; flex-wrap: wrap; }
.pg-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
.pg-stat { border: 1px solid #eceef2; border-radius: 14px; padding: 14px 16px; min-width: 0; }
.pg-stat small { display: block; font-size: 13px; color: #6b7280; } .pg-stat b { display: block; font-size: 24px; font-weight: 700; margin: 4px 0 2px; } .pg-stat span { font-size: 12.5px; color: #16a34a; }
.pg-bar { height: 8px; border-radius: 999px; background: #eef0f3; overflow: hidden; margin-top: 8px; } .pg-bar i { display: block; height: 100%; background: #2563eb; border-radius: 999px; }
.pg-q { display: flex; justify-content: space-between; gap: 10px; font-size: 14px; color: #4b5563; align-items: center; flex-wrap: wrap; }
.pg-q b { color: #111827; } .pg-link { border: 0; background: none; color: #2563eb; font-size: 13px; font-weight: 600; cursor: pointer; padding: 0; }
.pg-kv { display: flex; justify-content: space-between; gap: 14px; padding: 12px 0; border-bottom: 1px solid #f1f2f5; font-size: 14px; align-items: center; }
.pg-kv:last-child { border-bottom: 0; } .pg-kv span { color: #6b7280; } .pg-kv b { font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.pg-log { list-style: none; margin: 14px 0 0; padding: 0; }
.pg-log li { display: flex; gap: 12px; padding: 10px 0; }
.pg-log li::before { content: ''; width: 9px; height: 9px; border-radius: 50%; background: #2563eb; margin-top: 6px; flex: 0 0 auto; }
.pg-log b { display: block; font-size: 14px; font-weight: 500; } .pg-log small { display: block; font-size: 12.5px; color: #9ca3af; margin-top: 2px; }
.pg-logall { display: block; width: 100%; margin-top: 8px; padding: 11px; text-align: center; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; font-size: 14px; font-weight: 600; color: #2563eb; cursor: pointer; }
.pg-logall:hover { background: #f9fafb; }
.pg-qr { text-align: center; margin: 14px 0 0; } .pg-qr img { width: 200px; max-width: 100%; border: 1px solid #e5e7eb; border-radius: 12px; }

/* Modal form (memakai dasar .dpd-*) */
.pg-modal-list { max-height: 50vh; overflow-y: auto; margin-top: 12px; }
.pg-chk { display: flex; align-items: center; gap: 10px; margin-top: 14px; font-size: 14px; color: #374151; }
.pg-chk input { width: 18px; height: 18px; }

@media (max-width: 1100px) { .pg-grid { grid-template-columns: minmax(0, 1fr); } }
@media (max-width: 1000px) { .pg-page { padding: 24px 20px 48px; } }
@media (max-width: 640px) {
  .pg-page { padding: 20px 14px 40px; } .pg-title { font-size: 24px; } .pg-sub { font-size: 14px; }
  .pg-card { padding: 18px 16px; border-radius: 16px; } .pg-tabs { gap: 20px; }
  .pg-form { grid-template-columns: 1fr; } .pg-stats { grid-template-columns: 1fr; }
  .pg-foot .pg-btn, .pg-banner-a .pg-btn { flex: 1 1 auto; } .pg-search { width: 100%; }
  .pg-head > .pg-btn { width: 100%; }
}
.pg-page [hidden], .dpd-overlay [hidden], .pg-banner[hidden] { display: none !important; }
.pg-kolam { margin: 14px 0 0; padding: 0; }

	</style>
<style id="nv-css">
/* ===== NATIX LAYOUT (sidebar, topbar, breadcrumb) — prefix nv- agar tidak bentrok dengan CSS Figma ===== */
:root { --nv-w: clamp(236px, 17.22vw, 300px); }
html, body { margin: 0; }
body { display: block !important; grid-template-columns: none !important; }
.nv-side, .nv-side *, .nv-topbar, .nv-topbar *, .nv-crumb, .nv-crumb * { box-sizing: border-box; font-family: 'Inter', sans-serif; }

/* Sidebar */
.nv-side { position: fixed; top: 0; left: 0; bottom: 0; width: var(--nv-w); background: #fff; border-right: 1px solid #eef0f3; display: flex; flex-direction: column; z-index: 1200; transition: transform .25s ease; }
.nv-brand { display: flex; align-items: center; gap: 12px; padding: 20px 24px 12px; text-decoration: none; color: #111827; font-size: 20px; font-weight: 700; flex: 0 0 auto; }
.nv-brand img { width: 40px; height: 40px; object-fit: contain; }
.nv-scroll { flex: 1 1 auto; overflow-y: auto; padding: 0 14px 12px; scrollbar-width: thin; scrollbar-color: #e5e7eb transparent; }
.nv-group { margin: 16px 0 4px !important; padding: 0 12px; font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #9ca3af; }
.nv-item { display: flex; align-items: center; gap: 12px; height: 40px; padding: 0 12px; margin: 1px 0; border-radius: 12px; color: #374151; text-decoration: none; font-size: 15px; font-weight: 500; position: relative; transition: background .15s, color .15s; }
.nv-item span { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.nv-ico { width: 20px; height: 20px; flex: 0 0 auto; color: #6b7280; transition: color .15s; }
.nv-item:hover { background: #f6f7fb; color: #111827; }
.nv-item:hover .nv-ico { color: #111827; }
.nv-item:focus-visible, .nv-logout:focus-visible, .nv-brand:focus-visible, .nv-crumb a:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; }
.nv-item.is-active { background: #eff4ff; color: #2563eb; font-weight: 600; }
.nv-item.is-active .nv-ico { color: #2563eb; }
.nv-item.is-active::before { content: ''; position: absolute; left: -14px; top: 8px; bottom: 8px; width: 4px; border-radius: 0 4px 4px 0; background: #2563eb; }
.nv-badge { min-width: 24px; height: 22px; padding: 0 8px; border-radius: 999px; background: #2563eb; color: #fff; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; font-style: normal; }
.nv-badge[hidden] { display: none; }
.nv-user { flex: 0 0 auto; display: flex; align-items: center; gap: 8px; margin: 6px 14px 14px; padding: 10px 12px; border: 1px solid #eceef2; border-radius: 16px; background: #fff; }
.nv-user-row { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
.nv-av { width: 40px; height: 40px; border-radius: 50%; background: #eef2ff; color: #2563eb; font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
.nv-user strong { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 14px; color: #111827; line-height: 1.3; }
.nv-user small { display: block; font-size: 13px; color: #6b7280; }
.nv-logout { display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; flex: 0 0 auto; border: 1px solid #fecaca; border-radius: 10px; color: #dc2626; text-decoration: none; transition: background .15s; }
.nv-logout .nv-ico { color: #dc2626; width: 18px; height: 18px; }
.nv-logout:hover { background: #fef2f2; }
.nv-scrim { display: none; position: fixed; inset: 0; background: rgba(17,24,39,.45); z-index: 1150; opacity: 0; transition: opacity .25s; }

/* Konten bergeser mengikuti lebar sidebar */
.main { margin-left: var(--nv-w) !important; position: relative !important; left: auto !important; top: auto !important; width: auto !important; max-width: none !important; min-width: 0 !important; height: auto !important; min-height: 100vh; animation: nv-in .22s ease-out; }
@keyframes nv-in { from { opacity: 0; } to { opacity: 1; } }
html.nv-leaving .main { opacity: 0; transition: opacity .15s ease; }

/* Top header */
.nv-topbar { display: flex; align-items: center; justify-content: flex-end; gap: 16px; padding: 18px 32px; background: #fff; border-bottom: 1px solid #eef0f3; position: sticky; top: 0; z-index: 900; width: 100%; }
.nv-top-left { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1 1 auto; }
.nv-burger { display: none; width: 44px; height: 44px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; color: #374151; cursor: pointer; align-items: center; justify-content: center; flex: 0 0 auto; padding: 0; }
.nv-search { display: flex; align-items: center; gap: 10px; background: #f6f7fb; border-radius: 12px; padding: 0 16px; height: 40px; flex: 0 1 340px; width: 340px; max-width: 100%; min-width: 0; color: #9ca3af; margin: 0; border: 1px solid transparent; transition: border-color .15s, background .15s; }
.nv-search:focus-within { background: #fff; border-color: #93c5fd; }
.nv-search input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font-size: 14px; color: #111827; }
.nv-search input::placeholder { color: #9ca3af; }
.nv-top-right { display: flex; align-items: center; gap: 12px; flex: 0 0 auto; margin-left: auto; }
.nv-bell { position: relative; width: 40px; height: 40px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; color: #4b5563; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; transition: background .15s; }
.nv-bell:hover { background: #f9fafb; }
.nv-bell b { position: absolute; top: 7px; right: 8px; width: 9px; height: 9px; border-radius: 50%; background: #ef4444; border: 2px solid #fff; }
.nv-bell b[hidden] { display: none; }
.nv-avatar { width: 40px; height: 40px; border-radius: 50%; background: #eef2ff; color: #2563eb; font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }

/* Breadcrumb */
.nv-crumb { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 14px; line-height: 1.4; color: #9ca3af; margin: 0 0 6px; }
.nv-crumb a { color: #6b7280; text-decoration: none; border-radius: 4px; }
.nv-crumb a:hover { color: #2563eb; text-decoration: underline; }
.nv-crumb .is-now { color: #4b5563; font-weight: 500; }
.nv-sep { color: #d1d5db; }

.nv-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(20px); background: #111827; color: #fff; padding: 12px 20px; border-radius: 12px; font: 500 14px 'Inter', sans-serif; opacity: 0; pointer-events: none; transition: .25s; z-index: 4000; max-width: 90vw; }
.nv-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

@media (max-width: 1000px) {
    .nv-side { transform: translateX(-100%); box-shadow: none; }
    .nv-open .nv-side { transform: none; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
    .nv-open .nv-scrim { display: block; opacity: 1; }
    .main { margin-left: 0 !important; }
    .nv-burger { display: inline-flex; }
}
@media (max-width: 640px) { .nv-topbar { padding: 14px 16px; } }
@media (prefers-reduced-motion: reduce) { .main { animation: none; } html.nv-leaving .main { transition: none; } .nv-side, .nv-scrim { transition: none; } }
@media print { .nv-side, .nv-topbar, .nv-scrim, .nv-toast { display: none !important; } .main { margin-left: 0 !important; } }

/* ===== Lapisan responsif bersama (desktop > 1000px, tablet <= 1000px, mobile <= 640px) ===== */
img, svg, video { max-width: 100%; }
.nv-top-right { gap: 10px; }
@media (max-width: 1000px) {
    .nv-topbar { padding: 14px 20px; gap: 12px; }
    .ch-wa span { max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
}
@media (max-width: 640px) {
    .nv-topbar { padding: 12px 14px; gap: 8px; }
    .nv-top-left { gap: 8px; }
    .nv-top-right { gap: 8px; }
    .nv-bell, .nv-avatar { width: 40px; height: 40px; }
    .ch-wa span { display: none; }
    .ch-wa { padding: 0; width: 40px; height: 40px; justify-content: center; border-radius: 12px; }
}

/* Baris breadcrumb + alat halaman (kolom cari / status WhatsApp khusus Chat Log) */
.nv-crumbbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px 16px; margin: 0 0 8px; }
.nv-crumbbar .nv-crumb { margin: 0; flex: 1 1 auto; min-width: 0; }
.nv-tools { display: flex; align-items: center; gap: 10px; flex: 0 1 auto; min-width: 0; max-width: 100%; }
.nv-tools .nv-search { flex: 1 1 280px; }
.nv-tools .ch-wa { flex: 0 0 auto; }

/* Lonceng notifikasi + panel riwayat */
.nv-notif { position: relative; }
.nv-bell { cursor: pointer; padding: 0; font: inherit; }
.nv-bell[aria-expanded="true"] { background: #eff6ff; border-color: #bfdbfe; color: #2563eb; }
.nv-panel { position: absolute; right: 0; top: calc(100% + 10px); width: 380px; max-width: calc(100vw - 24px); background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; box-shadow: 0 18px 48px rgba(17,24,39,.16); z-index: 1000; overflow: hidden; transform-origin: top right; animation: nv-pop .14s ease-out; }
.nv-panel[hidden] { display: none; }
@keyframes nv-pop { from { opacity: 0; transform: translateY(-6px) scale(.98); } to { opacity: 1; transform: none; } }
.nv-ph { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; border-bottom: 1px solid #f1f2f5; }
.nv-ph strong { font-size: 15px; color: #111827; }
.nv-ph button { border: 0; background: none; color: #2563eb; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; padding: 4px 6px; border-radius: 6px; }
.nv-ph button:hover { background: #eff6ff; }
.nv-ph button:disabled { color: #9ca3af; cursor: default; background: none; }
.nv-list { max-height: min(60vh, 380px); overflow-y: auto; overscroll-behavior: contain; }
.nv-n { display: flex; gap: 12px; padding: 13px 18px; text-decoration: none; color: inherit; border-bottom: 1px solid #f6f7f9; transition: background .12s; }
.nv-n:hover, .nv-n:focus-visible { background: #f9fafb; outline: 0; }
.nv-n.baru { background: #f5f9ff; } .nv-n.baru:hover { background: #ecf3ff; }
.nv-n-av { width: 36px; height: 36px; border-radius: 50%; background: #fef2f2; color: #dc2626; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
.nv-n-t { min-width: 0; flex: 1; }
.nv-n-t b { display: block; font-size: 14px; color: #111827; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.nv-n-t span { display: block; font-size: 13px; color: #6b7280; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.nv-n-t em { display: block; font-style: normal; font-size: 12px; color: #dc2626; margin-top: 3px; }
.nv-n time { font-size: 12px; color: #9ca3af; flex: 0 0 auto; white-space: nowrap; }
.nv-empty { padding: 34px 18px; text-align: center; color: #9ca3af; font-size: 14px; }
.nv-pf { display: block; padding: 12px 18px; text-align: center; font-size: 13px; font-weight: 600; color: #2563eb; text-decoration: none; border-top: 1px solid #f1f2f5; background: #fafbfc; }
.nv-pf:hover { background: #f1f5fb; }
@media (max-width: 1000px) { .nv-tools .nv-search { flex-basis: 240px; } }
@media (max-width: 640px) {
    .nv-panel { position: fixed; left: 12px; right: 12px; top: 68px; width: auto; max-width: none; }
    .nv-crumbbar { flex-direction: column; align-items: stretch; }
    .nv-tools { width: 100%; }
    .nv-tools .nv-search { flex: 1 1 auto; width: auto; }
}
</style>
</head>
<body>
	<?php nvSidebar(); ?>
	<div class="main">
		<header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div></header>
		<div class="main-content pg-page">
			<?= nvCrumb([['Akun & Hak Akses']]) ?>
			<div class="pg-head">
				<div>
					<h1 class="pg-title">Pengaturan</h1>
					<p class="pg-sub">Kelola profil bisnis, akses admin, dan integrasi WhatsApp Natix Swim</p>
				</div>
				<button type="button" class="pg-btn pri" id="pg-tambah"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>Tambah Admin</button>
			</div>
			<?php pg_tabs('akun'); ?>

			<div class="pg-grid">
				<div class="pg-card">
					<div class="pg-card-h">
						<div><h2>Daftar Admin</h2><p class="pg-hint"><span id="pg-jml"><?= count($akun) ?></span> akun terdaftar</p></div>
						<input class="pg-in pg-search" id="pg-cari" type="search" placeholder="Cari admin..." aria-label="Cari admin">
					</div>
					<div class="pg-tw">
						<table class="pg-t">
							<thead><tr><th>Nama</th><th>Peran</th><th>Login Terakhir</th><th>Status</th><th>Aksi</th></tr></thead>
							<tbody id="pg-tb">
							<?php foreach ($akun as $a):
								$kode = $a['role'] === 'cs' ? 'kasir' : $a['role']; $aktif = $a['status'] === 'aktif'; ?>
								<tr data-id="<?= (int)$a['id'] ?>" data-nama="<?= pg_e($a['nama']) ?>" data-email="<?= pg_e($a['email']) ?>" data-peran="<?= pg_e($a['role']) ?>" data-status="<?= pg_e($a['status']) ?>" data-cari="<?= pg_e(strtolower($a['nama'] . ' ' . $a['email'] . ' ' . PG_PERAN[$a['role']])) ?>">
									<td><div class="who"><span class="pg-av"><?= pg_e(pg_inisial($a['nama'])) ?></span><div><b><?= pg_e($a['nama']) ?></b><small><?= pg_e($a['email']) ?></small></div></div></td>
									<td><span class="pg-pill <?= $kode ?>"><?= pg_e(PG_PERAN[$a['role']]) ?></span></td>
									<td style="color:#4b5563;white-space:nowrap"><?= pg_e(pg_login_label($a['last_login'])) ?></td>
									<td><span class="pg-pill <?= $aktif ? 'ok' : 'off' ?>"><?= $aktif ? 'Aktif' : 'Nonaktif' ?></span></td>
									<td><div class="pg-acts"><button type="button" class="pg-ic" data-act="edit" title="Ubah" aria-label="Ubah <?= pg_e($a['nama']) ?>"><?= $ic_edit ?></button><?php if ($a['role'] !== 'owner'): ?><button type="button" class="pg-ic red" data-act="hapus" title="Hapus" aria-label="Hapus <?= pg_e($a['nama']) ?>"><?= $ic_del ?></button><?php endif; ?></div></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<div class="pg-none" id="pg-kosong" <?= $akun ? 'hidden' : '' ?>><?= $akun ? 'Tidak ada akun yang cocok.' : 'Belum ada akun.' ?></div>
					</div>
				</div>

				<div class="pg-col">
					<div class="pg-card">
						<h2>Matriks Hak Akses</h2>
						<table class="pg-mx">
							<thead><tr><th>Modul</th><th>Owner</th><th>Admin</th><th>Kasir</th></tr></thead>
							<tbody>
								<tr><td>Member &amp; Segmentasi</td><td class="y">✓</td><td class="y">✓</td><td>—</td></tr>
								<tr><td>Jadwal &amp; Kehadiran</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td></tr>
								<tr><td>Transaksi</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td></tr>
								<tr><td>Laporan Keuangan</td><td class="y">✓</td><td>—</td><td>—</td></tr>
								<tr><td>AI &amp; Knowledge Base</td><td class="y">✓</td><td class="y">✓</td><td>—</td></tr>
								<tr><td>Pengaturan</td><td class="y">✓</td><td>—</td><td>—</td></tr>
							</tbody>
						</table>
					</div>
					<div class="pg-card">
						<h2>Keamanan</h2>
						<div class="pg-sw-row"><div><b>Verifikasi 2 Langkah</b><small>Kode OTP via WhatsApp saat login</small></div><button type="button" class="pg-sw<?= $sec2fa ? ' on' : '' ?>" role="switch" aria-checked="<?= $sec2fa ? 'true' : 'false' ?>" aria-label="Verifikasi 2 Langkah" data-kunci="sec_2fa" data-label="Verifikasi 2 Langkah"></button></div>
						<div class="pg-sw-row"><div><b>Logout Otomatis</b><small>Setelah 30 menit tidak aktif</small></div><button type="button" class="pg-sw<?= $secOut ? ' on' : '' ?>" role="switch" aria-checked="<?= $secOut ? 'true' : 'false' ?>" aria-label="Logout Otomatis" data-kunci="sec_logout" data-label="Logout Otomatis"></button></div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="dpd-overlay" id="pg-m-akun" data-modal role="dialog" aria-modal="true" aria-labelledby="pg-m-akun-h">
		<form class="dpd-box wide" id="pg-f-akun" autocomplete="off">
			<h3 id="pg-m-akun-h">Tambah Admin</h3>
			<div class="dpd-fg"><label for="a-nama">Nama Lengkap</label><input type="text" id="a-nama" maxlength="100" required></div>
			<div class="dpd-fg"><label for="a-email">Email</label><input type="email" id="a-email" maxlength="100" required placeholder="nama@natixswim.id"></div>
			<div class="dpd-fg"><label for="a-peran">Peran</label><select id="a-peran"><option value="admin">Admin</option><option value="cs">Kasir</option></select></div>
			<div class="dpd-fg" id="a-status-w" hidden><label for="a-status">Status</label><select id="a-status"><option value="aktif">Aktif</option><option value="non_aktif">Nonaktif</option></select></div>
			<label class="pg-chk" id="a-reset-w" hidden><input type="checkbox" id="a-reset"> Buat ulang password sementara</label>
			<p id="a-info" style="font-size:13px;color:#6b7280;margin-top:12px"></p>
			<div class="dpd-act"><button type="button" class="dpd-btn" id="a-batal">Batal</button><button type="submit" class="dpd-btn pri" id="a-simpan">Simpan</button></div>
		</form>
	</div>

	<!-- Popup konfirmasi / batal / berhasil / gagal -->
	<div class="dpd-overlay" id="dpd-info" role="dialog" aria-modal="true" aria-labelledby="dpd-judul">
		<div class="dpd-box">
			<div class="dpd-ico" id="dpd-ico"></div>
			<h3 id="dpd-judul"></h3>
			<p id="dpd-pesan"></p>
			<div class="dpd-act">
				<button type="button" class="dpd-btn" id="dpd-batal">Batal</button>
				<button type="button" class="dpd-btn pri" id="dpd-ya">OK</button>
			</div>
		</div>
	</div>


	<script>
var $d = function (i) { return document.getElementById(i); };
var PGICO = {
	tanya: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.6 2.6 0 015 .8c0 1.7-2.5 2-2.5 3.7M12 17v.01"/></svg>',
	hapus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>',
	ok: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/></svg>',
	gagal: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5l9 16h-18z"/><path d="M12 10v4.5M12 17.5v.01"/></svg>'
};
/* tipe: konfirmasi | bahaya | sukses | gagal. Promise<boolean>: true = tombol utama */
function pgDialog(o) {
	return new Promise(function (res) {
		var ov = $d('dpd-info'), box = ov.firstElementChild, yes = $d('dpd-ya'), no = $d('dpd-batal'), t = o.tipe || 'konfirmasi';
		box.className = 'dpd-box ' + t;
		$d('dpd-ico').innerHTML = PGICO[t === 'konfirmasi' ? 'tanya' : (t === 'bahaya' ? 'hapus' : (t === 'sukses' ? 'ok' : 'gagal'))];
		$d('dpd-judul').textContent = o.judul; $d('dpd-pesan').textContent = o.pesan || '';
		yes.textContent = o.ya || 'OK'; no.textContent = o.batal || 'Batal'; no.style.display = o.batal === false ? 'none' : '';
		yes.className = 'dpd-btn pri' + (t === 'bahaya' ? ' red' : '');
		function tutup(v) { ov.classList.remove('show'); yes.onclick = no.onclick = ov.onclick = null; document.removeEventListener('keydown', esc); res(v); }
		function esc(e) { if (e.key === 'Escape') tutup(false); }
		yes.onclick = function () { tutup(true); }; no.onclick = function () { tutup(false); };
		ov.onclick = function (e) { if (e.target === ov && o.batal !== false) tutup(false); };
		document.addEventListener('keydown', esc);
		ov.classList.add('show'); yes.focus();
	});
}
function pgModal(id, v) { var e = $d(id); if (e) e.classList.toggle('show', v); }
function pgPost(url, body, mentah) {
	var opt = { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
	if (mentah) opt.body = body; else { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body || {}); }
	return fetch(url, opt).then(function (r) { return r.text().then(function (tx) { try { return JSON.parse(tx); } catch (e) { throw new Error('Respons server tidak valid (HTTP ' + r.status + ').'); } }); });
}
function pgGet(url) {
	return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) { return r.text().then(function (tx) { try { return JSON.parse(tx); } catch (e) { throw new Error('Respons server tidak valid (HTTP ' + r.status + ').'); } }); });
}
/* Alur standar: konfirmasi -> (batal = selesai) -> kerja -> berhasil / gagal.
   o = { k:{...dialog konfirmasi}, kerja:fn()->Promise<data>, sukses:fn(d)->{judul,pesan,ya}, gagal:'judul', tutup:['id modal'], sesudah:fn(d) } */
function pgFlow(o) {
	return pgDialog(o.k).then(function (y) {
		if (!y) return false;
		return o.kerja().then(function (d) {
			if (d && (d.ok || d.success)) {
				(o.tutup || []).forEach(function (i) { pgModal(i, false); });
				var s = o.sukses(d);
				return pgDialog({ tipe: 'sukses', judul: s.judul, pesan: s.pesan || '', ya: s.ya || 'OK', batal: false }).then(function () { if (o.sesudah) o.sesudah(d); return true; });
			}
			return pgDialog({ tipe: 'gagal', judul: o.gagal || 'Gagal', pesan: (d && (d.pesan || d.message)) || 'Terjadi kesalahan.', ya: 'Tutup', batal: false }).then(function () { return false; });
		}).catch(function (e) { return pgDialog({ tipe: 'gagal', judul: o.gagal || 'Gagal', pesan: e.message, ya: 'Tutup', batal: false }).then(function () { return false; }); });
	});
}
document.querySelectorAll('.dpd-overlay[data-modal]').forEach(function (ov) {
	ov.addEventListener('mousedown', function (e) { if (e.target === ov) pgModal(ov.id, false); });
});

	(function () {
		var PERAN = { owner: 'Owner', admin: 'Admin', cs: 'Kasir' }, mode = 'tambah', idEdit = 0;
		function reload() { location.reload(); }

		/* Cari admin */
		$d('pg-cari').addEventListener('input', function () {
			var q = this.value.trim().toLowerCase(), n = 0;
			document.querySelectorAll('#pg-tb tr').forEach(function (tr) { var ok = !q || tr.dataset.cari.indexOf(q) >= 0; tr.hidden = !ok; if (ok) n++; });
			$d('pg-kosong').hidden = n > 0; $d('pg-kosong').textContent = 'Tidak ada akun yang cocok.';
		});

		/* Buka form */
		function buka(m, tr) {
			mode = m; idEdit = tr ? +tr.dataset.id : 0; $d('pg-f-akun').reset();
			var edit = m === 'edit', owner = edit && tr.dataset.peran === 'owner';
			$d('pg-m-akun-h').textContent = edit ? 'Ubah Akun' : 'Tambah Admin';
			$d('a-email').disabled = edit; $d('a-status-w').hidden = !edit || owner; $d('a-reset-w').hidden = !edit;
			$d('a-peran').closest('.dpd-fg').hidden = owner;
			if (edit) { $d('a-nama').value = tr.dataset.nama; $d('a-email').value = tr.dataset.email; $d('a-peran').value = tr.dataset.peran; $d('a-status').value = tr.dataset.status; }
			$d('a-info').textContent = edit ? '' : 'Password sementara dibuat otomatis dan ditampilkan sekali setelah akun dibuat.';
			pgModal('pg-m-akun', true); $d('a-nama').focus();
		}
		$d('pg-tambah').onclick = function () { buka('tambah'); };
		$d('a-batal').onclick = function () { pgModal('pg-m-akun', false); };

		function infoSandi(r, judul) { return { judul: judul, pesan: r.pesan + (r.sandi ? ' Password sementara: ' + r.sandi + ' — catat dan berikan kepada pengguna, password ini tidak ditampilkan lagi.' : '') }; }

		$d('pg-f-akun').addEventListener('submit', function (ev) {
			ev.preventDefault();
			var b = { nama: $d('a-nama').value.trim(), email: $d('a-email').value.trim(), peran: $d('a-peran').value };
			if (!b.nama) return;
			if (mode === 'tambah') {
				pgFlow({ k: { tipe: 'konfirmasi', judul: 'Tambah akun baru?', pesan: b.nama + ' (' + b.email + ') akan didaftarkan sebagai ' + PERAN[b.peran] + '.', ya: 'Ya, Tambah', batal: 'Batal' },
					kerja: function () { return pgPost('?aksi=tambah', b); }, tutup: ['pg-m-akun'], gagal: 'Gagal menambah akun',
					sukses: function (r) { return infoSandi(r, 'Akun berhasil dibuat'); }, sesudah: reload });
			} else {
				b.id = idEdit; b.status = $d('a-status').value; b.reset = $d('a-reset').checked;
				pgFlow({ k: { tipe: 'konfirmasi', judul: 'Simpan perubahan?', pesan: 'Data akun ' + b.nama + ' akan diperbarui' + (b.reset ? ' dan password dibuat ulang.' : '.'), ya: 'Ya, Simpan', batal: 'Batal' },
					kerja: function () { return pgPost('?aksi=edit', b); }, tutup: ['pg-m-akun'], gagal: 'Gagal menyimpan',
					sukses: function (r) { return infoSandi(r, 'Berhasil disimpan'); }, sesudah: reload });
			}
		});

		$d('pg-tb').addEventListener('click', function (e) {
			var bt = e.target.closest('[data-act]'); if (!bt) return;
			var tr = bt.closest('tr');
			if (bt.dataset.act === 'edit') return buka('edit', tr);
			pgFlow({ k: { tipe: 'bahaya', judul: 'Hapus akun ini?', pesan: tr.dataset.nama + ' (' + tr.dataset.email + ') tidak akan bisa masuk lagi. Tindakan ini tidak dapat dibatalkan.', ya: 'Ya, Hapus', batal: 'Batal' },
				kerja: function () { return pgPost('?aksi=hapus', { id: +tr.dataset.id }); }, gagal: 'Gagal menghapus',
				sukses: function (r) { return { judul: 'Akun dihapus', pesan: r.pesan }; }, sesudah: reload });
		});

		/* Keamanan */
		document.querySelectorAll('.pg-sw').forEach(function (sw) {
			sw.addEventListener('click', function () {
				var mau = !sw.classList.contains('on');
				pgFlow({ k: { tipe: 'konfirmasi', judul: (mau ? 'Aktifkan ' : 'Nonaktifkan ') + sw.dataset.label + '?', pesan: mau ? 'Pengaturan ini akan diaktifkan untuk semua akun.' : 'Pengaturan ini akan dimatikan untuk semua akun.', ya: mau ? 'Ya, Aktifkan' : 'Ya, Nonaktifkan', batal: 'Batal' },
					kerja: function () { return pgPost('?aksi=keamanan', { kunci: sw.dataset.kunci, nilai: mau }); }, gagal: 'Gagal menyimpan',
					sukses: function (r) { return { judul: 'Berhasil disimpan', pesan: r.pesan }; },
					sesudah: function () { sw.classList.toggle('on', mau); sw.setAttribute('aria-checked', mau ? 'true' : 'false'); } });
			});
		});
	})();
	</script>
<script>
/* ===== NATIX LAYOUT: navigasi, transisi halaman, status WhatsApp ===== */
(function () {
    var root = document.documentElement, side = document.getElementById('nv-side');
    if (!side) return;
    var scroller = side.querySelector('.nv-scroll');

    // Ingat posisi scroll sidebar antar halaman supaya tidak "loncat"
    try { var sc = sessionStorage.getItem('nv-scroll'); if (sc) scroller.scrollTop = +sc; } catch (e) {}
    var aktif = side.querySelector('.is-active');
    if (aktif) { var r = aktif.getBoundingClientRect(), q = scroller.getBoundingClientRect(); if (r.bottom > q.bottom || r.top < q.top) aktif.scrollIntoView({ block: 'nearest' }); }
    function simpanScroll() { try { sessionStorage.setItem('nv-scroll', scroller.scrollTop); } catch (e) {} }

    function toast(t) {
        var el = document.getElementById('nv-toast');
        if (!el) { el = document.createElement('div'); el.id = 'nv-toast'; el.className = 'nv-toast'; document.body.appendChild(el); }
        el.textContent = t; el.classList.add('show'); clearTimeout(toast._t); toast._t = setTimeout(function () { el.classList.remove('show'); }, 2600);
    }
    function tutup() { document.body.classList.remove('nv-open'); }

    // Klik menu / breadcrumb / header: halaman memudar halus lalu pindah
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.nv-burger'); if (b) { document.body.classList.toggle('nv-open'); return; }
        if (e.target.id === 'nv-scrim') { tutup(); return; }
        var a = e.target.closest('.nv-side a, .nv-crumb a, .nv-topbar a'); if (!a) return;
        if (a.dataset.belum) { e.preventDefault(); toast('Halaman ' + a.dataset.belum + ' tidak tersedia.'); return; }
        var h = a.getAttribute('href') || '';
        if (e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || a.target === '_blank' || a.hasAttribute('download') || /^(#|mailto:|tel:|javascript:)/i.test(h)) return;
        var u; try { u = new URL(a.href, location.href); } catch (x) { return; }
        if (u.origin !== location.origin || (u.pathname === location.pathname && u.search === location.search)) return;
        e.preventDefault(); simpanScroll(); tutup(); root.classList.add('nv-leaving');
        setTimeout(function () { location.href = u.href; }, 140);
    });
    window.addEventListener('pageshow', function () { root.classList.remove('nv-leaving'); });   // tombol Back (bfcache)
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') tutup(); });
    window.addEventListener('resize', function () { if (window.innerWidth > 1000) tutup(); });
    window.addEventListener('beforeunload', simpanScroll);

    // Muat halaman tujuan lebih awal saat kursor di atas menu
    var pre = {};
    function prefetch(ev) {
        var a = ev.target.closest && ev.target.closest('.nv-side a[href]'); if (!a || a.dataset.belum || pre[a.href] || a.classList.contains('is-active')) return;
        pre[a.href] = 1; var l = document.createElement('link'); l.rel = 'prefetch'; l.href = a.href; document.head.appendChild(l);
    }
    side.addEventListener('mouseover', prefetch); side.addEventListener('focusin', prefetch);

    // Notifikasi: chat yang butuh admin (cache 60 dtk) + panel riwayat pada lonceng
    var badge = document.getElementById('nv-badge'), dot = document.getElementById('nv-bell-dot');
    var btn = document.getElementById('nv-bell'), panel = document.getElementById('nv-panel'), list = document.getElementById('nv-list'), tandai = document.getElementById('nv-readall');
    var DATA = null;
    var NV_IKON = {
        bayar: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M16 14.5h2"/></svg>',
        tagihan: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3.5h8l4 4v13H6z"/><path d="M14 3.5v4h4M9 12h6M9 16h6"/></svg>',
        member: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.4 3.2-5 6.5-5s5.9 1.6 6.5 5M19 8v6M16 11h6"/></svg>',
        hadir: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="3.5" width="17" height="17" rx="3"/><path d="M8 12.5l3 3 5-6"/></svg>',
        eval: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.8l-5.2 2.8 1-5.8-4.3-4.1 5.9-.9z"/></svg>',
        kuota: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
        jadwal: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>',
        set: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 3v2.5M12 18.5V21M3 12h2.5M18.5 12H21M5.6 5.6l1.8 1.8M16.6 16.6l1.8 1.8M5.6 18.4l1.8-1.8M16.6 7.4l1.8-1.8"/></svg>',
        info: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8v.01"/></svg>'
    };
    (function () { var s = document.createElement('style'); s.textContent = '.nv-n-av svg{display:block}.nv-t-bayar{background:#dcfce7;color:#16a34a}.nv-t-tagihan{background:#fee2e2;color:#dc2626}.nv-t-member{background:#dbeafe;color:#2563eb}.nv-t-hadir{background:#ede9fe;color:#7c3aed}.nv-t-eval{background:#fef3c7;color:#d97706}.nv-t-kuota{background:#ffedd5;color:#ea580c}.nv-t-jadwal{background:#cffafe;color:#0891b2}.nv-t-set{background:#f3f4f6;color:#4b5563}.nv-t-info{background:#f3f4f6;color:#4b5563}'; document.head.appendChild(s); })();
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function dibaca() { try { return JSON.parse(localStorage.getItem('nv-read') || '[]'); } catch (e) { return []; } }
    function simpan(a) { try { localStorage.setItem('nv-read', JSON.stringify(a.slice(-200))); } catch (e) {} }
    function belum() { var r = dibaca(); return (DATA ? DATA.rows : []).filter(function (x) { return r.indexOf(x.id) < 0; }); }
    function badgeSet(n) { if (badge) { badge.textContent = n > 99 ? '99+' : n; badge.hidden = !n; } }
    function render() {
        var b = belum().length;
        if (dot) dot.hidden = !b;
        if (tandai) tandai.disabled = !b;
        badgeSet(DATA ? DATA.admin : 0);
        if (!list) return;
        if (!DATA) { list.innerHTML = '<div class="nv-empty">Memuat…</div>'; return; }
        if (DATA.gagal) { list.innerHTML = '<div class="nv-empty">Notifikasi belum bisa dimuat.</div>'; return; }
        if (!DATA.rows.length) { list.innerHTML = '<div class="nv-empty">Tidak ada notifikasi baru.</div>'; return; }
        var r = dibaca();
        list.innerHTML = DATA.rows.map(function (x) {
            return '<a class="nv-n' + (r.indexOf(x.id) < 0 ? ' baru' : '') + '" href="' + esc(x.href || '#') + '" data-id="' + esc(x.id) + '"><span class="nv-n-av nv-t-' + esc(x.tipe || 'info') + '">' + (NV_IKON[x.tipe] || NV_IKON.info) + '</span>'
                + '<span class="nv-n-t"><b>' + esc(x.judul) + '</b><span>' + esc(x.isi || '') + '</span></span><time>' + esc(x.jam) + '</time></a>';
        }).join('');
    }
    function muat() {
        var cache = null; try { cache = JSON.parse(sessionStorage.getItem('nv-notif2')); } catch (e) {}
        if (cache && Date.now() - cache.t < 60000) { DATA = cache.d; render(); return; }
        render();
        fetch('notifikasi.php?aksi=data', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                DATA = { admin: (DATA && DATA.admin) || 0, rows: ((d && d.rows) || []).slice(0, 20) };
                fetch('manajemen-ai-chat.php?aksi=data&tab=admin', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (c) {
                        if (!DATA) return;
                        DATA.admin = (c && c.hitung && c.hitung.admin) || 0; badgeSet(DATA.admin);
                        try { sessionStorage.setItem('nv-notif2', JSON.stringify({ t: Date.now(), d: DATA })); } catch (e) {}
                    }).catch(function () {});
                try { sessionStorage.setItem('nv-notif2', JSON.stringify({ t: Date.now(), d: DATA })); } catch (e) {}
                render();
            }).catch(function () { DATA = { admin: 0, rows: [], gagal: true }; render(); });
    }
    function buka(v) {
        if (!panel) return;
        panel.hidden = !v; btn.setAttribute('aria-expanded', v ? 'true' : 'false');
        if (v) muat();
    }
    if (btn && panel) {
        btn.addEventListener('click', function (e) { e.stopPropagation(); buka(panel.hidden); });
        document.addEventListener('click', function (e) { if (!panel.hidden && !panel.contains(e.target)) buka(false); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { buka(false); btn.focus(); } });
        if (tandai) tandai.addEventListener('click', function () { var r = dibaca(); (DATA ? DATA.rows : []).forEach(function (x) { if (r.indexOf(x.id) < 0) r.push(x.id); }); simpan(r); render(); });
        if (list) list.addEventListener('click', function (e) { var a = e.target.closest('a[data-id]'); if (a) { var r = dibaca(); r.push(a.dataset.id); simpan(r); } });
        muat();
    }
})();
</script>
</body>
</html>