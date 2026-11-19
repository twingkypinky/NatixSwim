<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config/koneksi.php';
require_once 'config/helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('ID member tidak valid');
}

function dp_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dp_upper($s): string { return function_exists('mb_strtoupper') ? mb_strtoupper((string)$s) : strtoupper((string)$s); }
function dp_sub($s, $a, $b): string { return function_exists('mb_substr') ? mb_substr((string)$s, $a, $b) : substr((string)$s, $a, $b); }
function dp_inisial($nama): string
{
    $p = preg_split('/\s+/', trim((string)$nama));
    $a = dp_sub($p[0] ?? '', 0, 1);
    $b = count($p) > 1 ? dp_sub($p[1], 0, 1) : dp_sub($p[0] ?? '', 1, 1);
    return dp_upper($a . $b) ?: '-';
}
function dp_tgl($d, $pendek = true): string
{
    if (!$d) return '-';
    $bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime((string)$d);
    return $t ? date('d', $t) . ' ' . $bln[(int)date('n', $t)] . ' ' . date('Y', $t) : '-';
}
function dp_rp($n): string { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }
function dp_q($pdo, $sql, $p = []): array
{
    try { return ambil($pdo, $sql, $p) ?: []; } catch (Throwable $e) { return []; }
}

// ===== Data murid =====
$dataMember = ambil($pdo, "SELECT * FROM members WHERE id = ?", [$id]);
if (empty($dataMember)) {
    die('Member tidak ditemukan');
}
$member = $dataMember[0];
$nama = (string)$member['nama'];

// ===== Simpan Data Pribadi (dipanggil dari popup Ubah Data) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ubah_pribadi') {
    header('Content-Type: application/json; charset=utf-8');
    $in = [];
    foreach (['nama', 'tanggal_lahir', 'jenis_kelamin', 'nama_wali', 'no_whatsapp', 'alamat', 'email', 'catatan'] as $k) {
        if (array_key_exists($k, $member) && isset($_POST[$k])) $in[$k] = trim((string)$_POST[$k]);
    }
    if (($in['nama'] ?? '') === '') { echo json_encode(['success' => false, 'message' => 'Nama lengkap wajib diisi.']); exit; }
    if (isset($in['no_whatsapp']) && $in['no_whatsapp'] === '') { echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp wajib diisi.']); exit; }
    if (isset($in['jenis_kelamin']) && !in_array($in['jenis_kelamin'], ['L', 'P'], true)) $in['jenis_kelamin'] = null;
    foreach (['tanggal_lahir', 'nama_wali', 'alamat', 'email', 'catatan'] as $k) { if (array_key_exists($k, $in) && $in[$k] === '') $in[$k] = null; }
    try {
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($in)));
        $st = $pdo->prepare("UPDATE members SET $set WHERE id = ?");
        $st->execute(array_merge(array_values($in), [$id]));
        echo json_encode(['success' => true, 'message' => 'Data pribadi berhasil diperbarui.']);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}

// ===== Paket / kelas yang diikuti =====
$paketList = dp_q($pdo, "SELECT pm.*, kp.nama_program, kp.level, u.nama AS nama_pelatih
                         FROM paket_member pm
                         LEFT JOIN kelas_program kp ON kp.id = pm.program_id
                         LEFT JOIN pelatih pl ON pl.id = pm.pelatih_id
                         LEFT JOIN users u ON u.id = pl.user_id
                         WHERE pm.member_id = ?
                         ORDER BY (pm.status = 'aktif') DESC, pm.id DESC", [$id]);
if (!$paketList) {   // tabel tanpa kolom pelatih_id
    $paketList = dp_q($pdo, "SELECT pm.*, kp.nama_program, kp.level, NULL AS nama_pelatih
                             FROM paket_member pm LEFT JOIN kelas_program kp ON kp.id = pm.program_id
                             WHERE pm.member_id = ? ORDER BY (pm.status = 'aktif') DESC, pm.id DESC", [$id]);
}
$paketAktif = null;
foreach ($paketList as $pk) { if (($pk['status'] ?? '') === 'aktif') { $paketAktif = $pk; break; } }
$hariNama = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
function dp_jadwal($pdo, $memberId, $programId, $hariNama): string
{
    $r = dp_q($pdo, "SELECT j.tanggal, j.jam_mulai, j.jam_selesai, j.kolam
                     FROM jadwal_peserta jp JOIN jadwal j ON j.id = jp.jadwal_id
                     WHERE jp.member_id = ? AND j.program_id = ? ORDER BY j.tanggal DESC LIMIT 8", [$memberId, $programId]);
    if (!$r) return '';
    $hari = [];
    foreach ($r as $x) { $n = (int)date('N', strtotime($x['tanggal'])); $hari[$n] = $hariNama[$n]; }
    ksort($hari);
    $h = array_values($hari);
    $txt = count($h) > 1 ? implode(', ', array_slice($h, 0, -1)) . ' & ' . end($h) : ($h[0] ?? '');
    return $txt . ', ' . substr($r[0]['jam_mulai'], 0, 5) . '–' . substr($r[0]['jam_selesai'], 0, 5) . ' · ' . $r[0]['kolam'];
}

// ===== Pembayaran =====
$pembayaran = dp_q($pdo, "SELECT t.nomor_tagihan, t.jumlah, t.status, t.created_at, kp.nama_program, pm.total_sesi
                          FROM tagihan t
                          LEFT JOIN paket_member pm ON pm.id = t.paket_id
                          LEFT JOIN kelas_program kp ON kp.id = pm.program_id
                          WHERE t.member_id = ? ORDER BY t.id DESC LIMIT 5", [$id]);
$labelBayar = ['lunas' => 'Lunas', 'belum_bayar' => 'Belum Bayar', 'sebagian' => 'Sebagian', 'jatuh_tempo' => 'Jatuh Tempo'];

// ===== Kehadiran =====
$presensi = array_reverse(dp_q($pdo, "SELECT pr.status, j.tanggal FROM presensi pr JOIN jadwal j ON j.id = pr.jadwal_id
                                      WHERE pr.member_id = ? ORDER BY j.tanggal DESC, pr.id DESC LIMIT 12", [$id]));
$cnt = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0];
foreach ($presensi as $p) { if (isset($cnt[$p['status']])) $cnt[$p['status']]++; }
$totalPresensi = count($presensi);
$persenHadir = $totalPresensi ? (int)round($cnt['hadir'] / $totalPresensi * 100) : 0;
$akan = dp_q($pdo, "SELECT COUNT(*) AS n FROM jadwal_peserta jp JOIN jadwal j ON j.id = jp.jadwal_id
                    WHERE jp.member_id = ? AND j.tanggal >= ? AND j.status = 'akan_datang'", [$id, date('Y-m-d')]);
$belumBerjalan = min((int)($akan[0]['n'] ?? 0), max(0, 12 - $totalPresensi));
$catatanRow = dp_q($pdo, "SELECT e.catatan, e.updated_at, u.nama AS pelatih
                          FROM evaluasi e JOIN pelatih pl ON pl.id = e.pelatih_id JOIN users u ON u.id = pl.user_id
                          WHERE e.member_id = ? AND e.catatan IS NOT NULL AND e.catatan <> '' ORDER BY e.id DESC LIMIT 1", [$id]);
$catatan = $catatanRow[0] ?? null;

// ===== Riwayat chat =====
$chat = [];
$cv = dp_q($pdo, "SELECT id FROM percakapan WHERE member_id = ? ORDER BY id DESC LIMIT 1", [$id]);
if ($cv) {
    $chat = array_reverse(dp_q($pdo, "SELECT pengirim, isi, waktu FROM pesan WHERE percakapan_id = ? ORDER BY id DESC LIMIT 6", [(int)$cv[0]['id']]));
}

// ===== Pilihan untuk form Ubah Data =====
$programs = dp_q($pdo, "SELECT id, nama_program FROM kelas_program ORDER BY nama_program ASC");
$pelatihList = dp_q($pdo, "SELECT pl.id, u.nama FROM pelatih pl JOIN users u ON u.id = pl.user_id WHERE pl.status = 'aktif' ORDER BY u.nama ASC");

$aktif = ($member['status'] ?? 'aktif') === 'aktif';
$sisa = $paketAktif ? (int)$paketAktif['sisa_sesi'] : null;
$umur = '';
if (!empty($member['tanggal_lahir'])) {
    $lh = new DateTime($member['tanggal_lahir']);
    $umur = ' (' . $lh->diff(new DateTime())->y . ' thn)';
}
$jk = ($member['jenis_kelamin'] ?? '') === 'L' ? 'Laki-laki' : (($member['jenis_kelamin'] ?? '') === 'P' ? 'Perempuan' : '-');
$kode = (string)($member['kode_member'] ?? '');
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
                ['Simulator Chat', 'simulator-wa.php', 'chat', [], false],
                ['Knowledge Base', 'manajemen-ai1.php', 'note', [], false],
                ['Otomasi Pesan', 'manajemen-ai2.php', 'zap', [], false],
                ['Analitik AI', 'manajemen-ai-analitik.php', 'trend', [], true],
            ]],
            ['Sistem', [
                ['Pengaturan', 'pengaturan-pengaturan.php', 'gear', [], true],
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
	<title>Detail Profil - <?= dp_e($nama) ?></title>
	<link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="css/reset.css" />
	<link rel="stylesheet" href="css/global.css" />
	<style>
/* ===== Detail Profil: tampilan mandiri mengikuti desain Figma (nama class template dipertahankan) ===== */
.dpx, .dpx *, .dpx *::before, .dpx *::after { box-sizing: border-box; }
.dpx * { position: static !important; left: auto !important; top: auto !important; right: auto !important; bottom: auto !important; transform: none !important; float: none !important; width: auto !important; height: auto !important; min-width: 0 !important; min-height: 0 !important; max-width: none !important; margin: 0 !important; background: none !important; border: 0 !important; border-radius: 0 !important; box-shadow: none !important; outline: 0 !important; padding: 0 !important; text-decoration: none; }
.dpx *::before, .dpx *::after { content: none !important; display: none !important; }
.dpx { padding: 28px 32px 48px !important; margin: 0 !important; width: 100% !important; max-width: none !important; position: relative !important; height: auto !important; font-family: 'Inter', sans-serif; color: #111827; display: block !important; }
.dpx p, .dpx h2, .dpx h3 { margin: 0 !important; }
.dpx .dpx-i { width: 16px !important; height: 16px !important; flex: 0 0 16px; display: block; }
.dpx .nv-crumb { margin: 0 0 12px !important; }
.dpx .dpx-kosong { padding: 18px 4px !important; color: #9ca3af; font-size: 13px; text-align: center; }
.dpx a { color: inherit; cursor: pointer; }
.dpx .card-white5, .dpx .card-white6, .dpx .main-card1, .dpx .main-card2 { background: #fff !important; border: 1px solid #eceef2 !important; border-radius: 16px !important; padding: 20px !important; }

/* Kartu profil */
.dpx .main-card1 { display: flex !important; align-items: center; gap: 18px; flex-wrap: wrap; }
.dpx .main-btn-av-lg { width: 64px !important; height: 64px !important; flex: 0 0 64px; border-radius: 50% !important; background: #eff1f4 !important; color: #111827 !important; font-size: 20px !important; font-weight: 700 !important; display: flex !important; align-items: center; justify-content: center; cursor: default; }
.dpx .main-group3 { flex: 1 1 260px; min-width: 0 !important; }
.dpx .main-text-top { font-size: 22px !important; font-weight: 700 !important; line-height: 1.25 !important; color: #111827 !important; }
.dpx .main-prof-meta { display: flex !important; gap: 6px 18px; flex-wrap: wrap; margin-top: 4px !important; }
.dpx .main-text-a { font-size: 13px !important; color: #6b7280 !important; line-height: 1.4 !important; }
.dpx .main-prof-badges { display: flex !important; gap: 8px; flex-wrap: wrap; margin-top: 10px !important; align-items: center; }
.dpx .main-btn-badge-b, .dpx .main-badge-b-red, .dpx .card-badge-b-red, .dpx .card-badge-b-ok, .dpx .card-badge-b-green, .dpx .card-btn-badge, .dpx .group-btn-badge3 { display: inline-flex !important; align-items: center; justify-content: center; height: 22px !important; padding: 0 12px !important; border-radius: 999px !important; font-size: 12px !important; font-weight: 600 !important; line-height: 1 !important; white-space: nowrap; }
.dpx .main-btn-badge1 { background: #dcfce7 !important; color: #15803d !important; }
.dpx .main-btn-badge-off { background: #f1f2f4 !important; color: #6b7280 !important; }
.dpx .main-btn-badge2 { background: #e8eeff !important; color: #3b5bdb !important; font-weight: 500 !important; }
.dpx .main-badge-b-red, .dpx .card-badge-b-red { background: #fef2f2 !important; color: #dc2626 !important; }
.dpx .card-badge-b-ok { background: #dcfce7 !important; color: #15803d !important; }
.dpx .card-badge-b-green { background: #dcfce7 !important; color: #15803d !important; }
.dpx .card-btn-badge { background: #f1f2f4 !important; color: #6b7280 !important; }
.dpx .main-prof-acts { display: flex !important; gap: 10px; flex-wrap: wrap; }
.dpx .btn-btn-secondary-a { display: inline-flex !important; align-items: center; gap: 8px; height: 38px !important; padding: 0 16px !important; border: 1px solid #e5e7eb !important; border-radius: 10px !important; background: #fff !important; color: #111827 !important; font-size: 13px !important; font-weight: 600 !important; cursor: pointer; white-space: nowrap; font-family: inherit; }
.dpx .btn-btn-secondary-a:hover { background: #f9fafb !important; }
.dpx .btn-btn-secondary-label { font-size: 13px !important; font-weight: 600 !important; line-height: 1 !important; color: inherit !important; }
.dpx .btn-btn-ghost-red { color: #dc2626 !important; border-color: #fecaca !important; }
.dpx .btn-btn-ghost-red:hover { background: #fef2f2 !important; }

/* Grid kartu */
.dpx .main-grid { display: grid !important; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 16px; margin-top: 16px !important; align-items: start; }
.dpx .main-grid-2b { display: grid !important; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 16px; margin-top: 16px !important; align-items: start; }
.dpx .main-card2 { margin-top: 16px !important; }
.dpx .group-text5, .dpx .group-text7, .dpx .card-text-kelas-yang { font-size: 16px !important; font-weight: 700 !important; color: #111827 !important; line-height: 1.3 !important; }
.dpx .group-text6, .dpx .group-text8, .dpx .card-group3 p + p { font-size: 13px !important; color: #9ca3af !important; margin-top: 2px !important; line-height: 1.3 !important; }
.dpx .card-head1, .dpx .card-head2, .dpx .card-head3, .dpx .main-card-head { display: flex !important; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 14px !important; }
.dpx .card-text-lihat-jadwal, .dpx .card-text-lihat-semua, .dpx .main-text-buka-di-riwayat { font-size: 13px !important; font-weight: 600 !important; color: #2563eb !important; white-space: nowrap; }
.dpx a.card-text-lihat-jadwal:hover, .dpx a.card-text-lihat-semua:hover, .dpx a.main-text-buka-di-riwayat:hover { text-decoration: underline; }

/* Data pribadi */
.dpx .card-info { display: block !important; }
.dpx .row-b { display: flex !important; justify-content: space-between; gap: 16px; padding: 11px 0 !important; border-bottom: 1px solid #f1f2f5 !important; }
.dpx .row-b:last-child { border-bottom: 0 !important; padding-bottom: 0 !important; }
.dpx .row-text1 { font-size: 13px !important; color: #6b7280 !important; flex: 0 0 120px; }
.dpx .row-text2 { font-size: 14px !important; font-weight: 600 !important; color: #111827 !important; text-align: right; min-width: 0 !important; overflow-wrap: anywhere; }

/* Kelas yang diikuti */
.dpx .card5 { background: #fafbfc !important; border: 1px solid #eceef2 !important; border-radius: 12px !important; padding: 14px 16px !important; margin-top: 10px !important; }
.dpx .card-cls-top1, .dpx .card-cls-top2 { display: flex !important; justify-content: space-between; align-items: center; gap: 10px; }
.dpx .card-text-private-kids { font-size: 14px !important; font-weight: 700 !important; color: #111827 !important; }
.dpx .card-text-a { font-size: 12.5px !important; color: #6b7280 !important; margin-top: 6px !important; line-height: 1.4 !important; }
.dpx .card-bar1 { display: block !important; height: 6px !important; background: #e5e7eb !important; border-radius: 99px !important; margin-top: 10px !important; overflow: hidden; }
.dpx .card-bar1 .card-group4 { display: block !important; height: 100% !important; background: #2563eb !important; border-radius: 99px !important; }
.dpx .card-cls2 .card-bar1 .card-group4 { background: #9ca3af !important; }

/* Pembayaran */
.dpx .card-group5 { display: block !important; overflow-x: auto; }
.dpx .card-group6, .dpx .group-h { display: grid !important; grid-template-columns: minmax(104px, 1.3fr) minmax(80px, 1fr) minmax(90px, 1.2fr) minmax(96px, 1.1fr) minmax(78px, .9fr); gap: 10px; align-items: center; min-width: 520px !important; }
.dpx .card-group6 { padding: 0 0 10px !important; border-bottom: 1px solid #eceef2 !important; }
.dpx .card-text-group-a { font-size: 11px !important; font-weight: 600 !important; letter-spacing: .05em; text-transform: uppercase; color: #6b7280 !important; }
.dpx .card-group7 { display: block !important; min-width: 520px !important; }
.dpx .group-h { padding: 11px 0 !important; border-bottom: 1px solid #f1f2f5 !important; }
.dpx .group-h:last-child { border-bottom: 0 !important; }
.dpx .group-text-group-a { font-size: 13px !important; color: #1f2937 !important; white-space: nowrap; }
.dpx .group-text9 { font-weight: 600 !important; }
.dpx .group-group4 { display: flex !important; }
.dpx .group-btn-badge3 { background: #dcfce7 !important; color: #15803d !important; }
.dpx .group-btn-badge3.st-belum_bayar, .dpx .group-btn-badge3.st-jatuh_tempo { background: #fef2f2 !important; color: #dc2626 !important; }
.dpx .group-btn-badge3.st-sebagian { background: #fff7ed !important; color: #ea580c !important; }

/* Kehadiran */
.dpx .card-att { display: grid !important; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; margin-top: 4px !important; }
.dpx .card-btn-h { display: flex !important; align-items: center; justify-content: center; height: 34px !important; border-radius: 8px !important; background: #dcfce7 !important; color: #15803d !important; font-size: 13px !important; font-weight: 700 !important; }
.dpx .card-btn-h.card-btn-i { background: #fff7ed !important; color: #ea580c !important; }
.dpx .card-btn-h.card-btn-a { background: #fef2f2 !important; color: #dc2626 !important; }
.dpx .card-btn-h.card-btn-e { background: #f1f2f4 !important; color: #9ca3af !important; }
.dpx .card-legend { display: flex !important; flex-wrap: wrap; gap: 8px 16px; margin-top: 14px !important; }
.dpx .group-i { display: flex !important; align-items: center; gap: 6px; }
.dpx .group-dot { display: block !important; width: 8px !important; height: 8px !important; border-radius: 50% !important; background: #16a34a !important; flex: 0 0 8px; }
.dpx .group25 .group-dot { background: #ea580c !important; } .dpx .group26 .group-dot { background: #dc2626 !important; } .dpx .group27 .group-dot { background: #9ca3af !important; }
.dpx .group-text13 { font-size: 12.5px !important; color: #4b5563 !important; }
.dpx .card-cls3 { margin-top: 14px !important; }
.dpx .card-text-catatan-pelatih { font-size: 12px !important; font-weight: 600 !important; color: #6b7280 !important; }
.dpx .card-text-gaya-bebas { font-size: 13px !important; color: #374151 !important; margin-top: 4px !important; line-height: 1.5 !important; }

/* Chat */
.dpx .main-chat { display: flex !important; flex-direction: column; gap: 10px; }
.dpx .msg-in, .dpx .main-msg-out1 { max-width: min(560px, 88%) !important; padding: 10px 14px !important; border-radius: 14px !important; }
.dpx .msg-in { background: #f3f4f6 !important; align-self: flex-start; border-top-left-radius: 4px !important; }
.dpx .main-msg-out1 { background: #eff6ff !important; align-self: flex-end; border-top-right-radius: 4px !important; }
.dpx .msg-in-text1, .dpx .main-text-b { font-size: 13.5px !important; color: #1f2937 !important; line-height: 1.5 !important; overflow-wrap: anywhere; }
.dpx .msg-in-text2, .dpx .main-text-16-okt { font-size: 11px !important; color: #9ca3af !important; margin-top: 4px !important; }
.dpx .main-ai-tag1 { display: flex !important; margin-bottom: 4px !important; }
.dpx .main-text-ai-assistant1 { font-size: 11px !important; font-weight: 700 !important; color: #2563eb !important; letter-spacing: .02em; }

@media (max-width: 1100px) { .dpx .main-grid, .dpx .main-grid-2b { grid-template-columns: 1fr; } }
@media (max-width: 1000px) { .dpx { padding: 20px 16px 40px !important; } }
@media (max-width: 640px) {
  .dpx .main-card1, .dpx .card-white5, .dpx .card-white6, .dpx .main-card2 { padding: 16px !important; }
  .dpx .main-prof-acts { width: 100%; } .dpx .btn-btn-secondary-a { flex: 1 1 auto; justify-content: center; }
  .dpx .card-att { grid-template-columns: repeat(6, minmax(0, 1fr)); }
  .dpx .row-text1 { flex-basis: 96px; }
}

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
@media (max-width: 480px) { .dpd-row { flex-direction: column; gap: 0; } .dpd-act .dpd-btn { flex: 1; } }
#dpd-info { z-index: 2100; }
.dpx .card-head1, .dpx .card-head2, .dpx .card-head3, .dpx .main-card-head { flex-wrap: wrap; }
.dpx .card-head1 > :first-child, .dpx .card-head2 > :first-child, .dpx .card-head3 > :first-child, .dpx .main-card-head > :first-child { flex: 1 1 180px; min-width: 0 !important; }
/* pengaman terhadap CSS eksternal */
.dpx * { text-align: left; writing-mode: horizontal-tb !important; }
.dpx .row-b { flex-direction: row !important; align-items: flex-start !important; width: 100% !important; }
.dpx .row-text1 { width: 120px !important; text-align: left !important; }
.dpx .row-text2 { flex: 1 1 auto; text-align: right !important; }
.dpx .card-info { display: block !important; width: 100% !important; }
.dpx .group-f { display: block !important; text-align: left !important; }
.dpx .dpx-kosong { text-align: center !important; }
.dpd-fg textarea { min-height: 64px; }

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

@media (max-width: 1000px) {
  .content { padding-left: 16px !important; padding-right: 16px !important; box-sizing: border-box; }
  .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .table { min-width: 640px; }
  .bars { overflow-x: auto; }
}
</style>
</head>

<body>
	<?php nvSidebar(); ?>

	<div class="main">
		<header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div></header>

		<div class="main-content dpx">
			<?= nvCrumb([[$nama]]) ?>

			<div class="main-card1">
				<button class="main-btn-av-lg hover-dark" type="button" tabindex="-1"><?= dp_e(dp_inisial($nama)) ?></button>

				<div class="main-group3">
					<p class="main-text-top"><?= dp_e($nama) ?></p>

					<div class="main-prof-meta">
						<p class="main-text-a"><?= dp_e($kode ?: '-') ?></p>
						<p class="main-text-a">Bergabung <?= dp_e(dp_tgl($member['tanggal_daftar'] ?? null)) ?></p>
						<p class="main-text-a main-text2"><?= dp_e($member['no_whatsapp'] ?? '-') ?></p>
					</div>

					<div class="main-prof-badges">
						<span class="main-btn-badge-b <?= $aktif ? 'main-btn-badge1' : 'main-btn-badge-off' ?>"><?= $aktif ? 'Aktif' : 'Non-Aktif' ?></span>
						<?php if ($paketAktif): ?><span class="main-btn-badge-b main-btn-badge2"><?= dp_e($paketAktif['nama_program'] ?? '-') ?></span><?php endif; ?>
						<?php if ($sisa !== null && $sisa <= 2): ?><div class="main-badge-b-red card4">Kuota hampir habis</div><?php endif; ?>
					</div>
				</div>

				<div class="main-prof-acts">
					<button class="btn-btn-secondary-a btn-btn-secondary1 hover-dark" type="button" onclick="dpBukaWA()">
						<svg class="dpx-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.5 8.5 0 01-12.6 7.4L3 20.5l1.6-5A8.5 8.5 0 1121 11.5z"/></svg>
						<p class="btn-btn-secondary-label">Kirim WhatsApp</p>
					</button>

					<button class="btn-btn-secondary-a btn-btn-secondary2 hover-dark" type="button" onclick="dpBukaEdit()">
						<svg class="dpx-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9a2.8 2.8 0 00-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg>
						<p class="btn-btn-secondary-label">Ubah Data</p>
					</button>

					<button class="btn-btn-secondary-a btn-btn-ghost-red hover-dark" type="button" onclick="dpHapus()">
						<svg class="dpx-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>
						<p class="btn-btn-secondary-label">Hapus</p>
					</button>
				</div>
			</div>

			<div class="main-grid">
				<div class="card27 card-white5">
					<div class="group-f group18">
						<p class="group-text5">Data Pribadi</p>
						<p class="group-text6">Informasi member &amp; wali</p>
					</div>

					<div class="card-info">
						<?php
						$baris = [
							'Nama Lengkap' => $nama,
							'Tanggal Lahir' => !empty($member['tanggal_lahir']) ? dp_tgl($member['tanggal_lahir']) . $umur : '-',
							'Jenis Kelamin' => $jk,
							'Nama Wali' => $member['nama_wali'] ?? '-',
							'WhatsApp' => $member['no_whatsapp'] ?? '-',
						];
						if (array_key_exists('email', $member)) $baris['Email'] = $member['email'] ?: '-';
						$baris['Alamat'] = $member['alamat'] ?? '-';
						if (array_key_exists('catatan', $member)) $baris['Catatan'] = $member['catatan'] ?: '-';
						$ri = 2;
						foreach ($baris as $lbl => $val):
						?>
						<div class="row-b row<?= $ri++ ?>">
							<p class="row-text1"><?= dp_e($lbl) ?></p>
							<p class="row-text2"><?= dp_e(($val === null || $val === '') ? '-' : $val) ?></p>
						</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="card28 card-white6">
					<div class="card-head1">
						<div class="card-group3">
							<p class="card-text-kelas-yang">Kelas yang Diikuti</p>
							<p>Program dan sisa kuota sesi</p>
						</div>

						<a class="card-text-lihat-jadwal text-blue2" href="jadwal-kelas.php">Lihat jadwal</a>
					</div>

					<?php if (!$paketList): ?>
					<div class="dpx-kosong">Belum mengikuti kelas apa pun.</div>
					<?php endif; ?>
					<?php foreach ($paketList as $i => $pk):
						$tot = max(1, (int)$pk['total_sesi']); $pakai = max(0, $tot - (int)$pk['sisa_sesi']);
						$pct = min(100, (int)round($pakai / $tot * 100));
						$selesai = ($pk['status'] ?? '') !== 'aktif';
						$jd = (($pk['status'] ?? '') === 'aktif') ? dp_jadwal($pdo, $id, (int)$pk['program_id'], $hariNama) : '';
					?>
					<div class="card-cls<?= $selesai ? '2' : '1' ?> card5">
						<div class="card-cls-top<?= $selesai ? '2' : '1' ?>">
							<p class="card-text-private-kids"><?= dp_e($pk['nama_program'] ?? '-') ?></p>
							<?php if ($selesai): ?>
								<span class="card-btn-badge text1"><?= ($pk['status'] ?? '') === 'habis' ? 'Selesai' : 'Kadaluarsa' ?></span>
							<?php elseif ((int)$pk['sisa_sesi'] <= 2): ?>
								<div class="card-badge-b-red text1"><?= (int)$pk['sisa_sesi'] ?> sesi tersisa</div>
							<?php else: ?>
								<div class="card-badge-b-ok text1"><?= (int)$pk['sisa_sesi'] ?> sesi tersisa</div>
							<?php endif; ?>
						</div>

						<p class="card-text-a"><?= dp_e(trim(($pk['nama_pelatih'] ? $pk['nama_pelatih'] : '') . ($jd ? ' · ' . $jd : ''), ' ·') ?: '-') ?></p>
						<div class="card-bar1"><div class="card-group-a card-group4" style="width:<?= $pct ?>%"></div></div>
						<p class="card-text-a"><?= $pakai ?> dari <?= $tot ?> sesi terpakai<?= !empty($pk['tanggal_berakhir']) ? ' · Berlaku s.d. ' . dp_e(dp_tgl($pk['tanggal_berakhir'])) : '' ?></p>
					</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="main-grid-2b">
				<div class="card29 card-white6">
					<div class="card-head2">
						<div class="group-g group19">
							<p class="group-text7">Riwayat Pembayaran</p>
							<p class="group-text8">Transaksi tercatat manual oleh admin</p>
						</div>

						<a class="card-text-lihat-semua" href="administrasi.php?q=<?= urlencode($kode ?: $nama) ?>">Lihat semua</a>
					</div>

					<div class="card-group5">
						<div class="card-group6">
							<p class="card-text-group-a card-text-group1">Invoice</p>
							<p class="card-text-group-a card-text-group2">Tanggal</p>
							<p class="card-text-group-a card-text-group3">Paket</p>
							<p class="card-text-group-a card-text-group4">Nominal</p>
							<p class="card-text-group-a card-text-group5">Status</p>
						</div>

						<div class="card-group7">
							<?php if (!$pembayaran): ?><div class="dpx-kosong">Belum ada riwayat pembayaran.</div><?php endif; ?>
							<?php foreach ($pembayaran as $b): ?>
							<div class="group-h group20">
								<p class="group-text-group-a group-text9"><?= dp_e($b['nomor_tagihan']) ?></p>
								<p class="group-text-group-a group-text10"><?= dp_e(dp_tgl($b['created_at'])) ?></p>
								<p class="group-text-group-a group-text11"><?= dp_e(($b['nama_program'] ?? '-') . ($b['total_sesi'] ? ' ' . (int)$b['total_sesi'] . 'x' : '')) ?></p>
								<p class="group-text-group-a group-text12"><?= dp_e(dp_rp($b['jumlah'])) ?></p>

								<div class="group-group4">
									<span class="group-btn-badge3 st-<?= dp_e($b['status']) ?>"><?= dp_e($labelBayar[$b['status']] ?? $b['status']) ?></span>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div class="card30 card-white5">
					<div class="card-head3">
						<div class="group-g group23">
							<p class="group-text7">Riwayat Kehadiran</p>
							<p class="group-text8"><?= $totalPresensi ?: 0 ?> sesi terakhir</p>
						</div>

						<div class="card-badge-b-green card4">Kehadiran <?= $persenHadir ?>%</div>
					</div>

					<div class="card-att">
						<?php if (!$presensi && !$belumBerjalan): ?><div class="dpx-kosong" style="grid-column:1/-1">Belum ada data kehadiran.</div><?php endif; ?>
						<?php foreach ($presensi as $p):
							$st = $p['status']; $huruf = ['hadir' => 'H', 'izin' => 'I', 'sakit' => 'S', 'alpa' => 'A'][$st] ?? '?';
							$kls = ['izin' => 'card-btn-i', 'sakit' => 'card-btn-i', 'alpa' => 'card-btn-a'][$st] ?? '';
						?>
						<span class="card-btn-h <?= $kls ?>" title="<?= dp_e(dp_tgl($p['tanggal']) . ' · ' . ucfirst($st)) ?>"><?= $huruf ?></span>
						<?php endforeach; ?>
						<?php for ($k = 0; $k < $belumBerjalan; $k++): ?><span class="card-btn-h card-btn-e" title="Belum berjalan">–</span><?php endfor; ?>
					</div>

					<div class="card-legend">
						<div class="group-i group24"><div class="group-dot"></div><p class="group-text13">Hadir (<?= $cnt['hadir'] ?>)</p></div>
						<div class="group-i group25"><div class="group-dot"></div><p class="group-text13">Izin/Sakit (<?= $cnt['izin'] + $cnt['sakit'] ?>)</p></div>
						<div class="group-i group26"><div class="group-dot"></div><p class="group-text13">Alpa (<?= $cnt['alpa'] ?>)</p></div>
						<div class="group-i group27"><div class="group-dot"></div><p class="group-text13">Belum berjalan<?= $belumBerjalan ? ' (' . $belumBerjalan . ')' : '' ?></p></div>
					</div>

					<?php if ($catatan): ?>
					<div class="card-cls3 card5">
						<p class="card-text-catatan-pelatih">Catatan pelatih terakhir</p>
						<p class="card-text-gaya-bebas">“<?= dp_e($catatan['catatan']) ?>” — <?= dp_e($catatan['pelatih']) ?>, <?= dp_e(dp_tgl($catatan['updated_at'])) ?></p>
					</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="main-card2">
				<div class="main-card-head">
					<div class="group-f group28">
						<p class="group-text5">Riwayat Chat</p>
						<p class="group-text6">Percakapan WhatsApp dengan AI Virtual Assistant</p>
					</div>

					<a class="main-text-buka-di-riwayat text-blue2" href="manajemen-ai-chat.php">Buka di Riwayat Chat</a>
				</div>

				<div class="main-chat">
					<?php if (!$chat): ?><div class="dpx-kosong">Belum ada percakapan WhatsApp dengan member ini.</div><?php endif; ?>
					<?php foreach ($chat as $m): $masuk = $m['pengirim'] === 'member'; ?>
					<div class="<?= $masuk ? 'msg-in' : 'main-msg-out1 card-light2' ?>">
						<?php if (!$masuk): ?><div class="main-ai-tag1"><p class="main-text-ai-assistant1"><?= $m['pengirim'] === 'bot' ? 'AI Assistant' : 'Admin' ?></p></div><?php endif; ?>
						<p class="<?= $masuk ? 'msg-in-text1' : 'main-text-b' ?>"><?= nl2br(dp_e($m['isi'])) ?></p>
						<p class="<?= $masuk ? 'msg-in-text2' : 'main-text-16-okt' ?>"><?= dp_e(dp_tgl($m['waktu']) . ' · ' . date('H.i', strtotime($m['waktu']))) ?></p>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- ===== Popup (konfirmasi / batal / berhasil / gagal) ===== -->
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

	<div class="dpd-overlay" id="dpd-edit" role="dialog" aria-modal="true" aria-labelledby="dpd-edit-judul">
		<form class="dpd-box wide" id="dpd-form" autocomplete="off">
			<h3 id="dpd-edit-judul">Ubah Data Pribadi</h3>
			<div class="dpd-fg"><label for="e_nama">Nama Lengkap</label><input type="text" id="e_nama" name="nama" required value="<?= dp_e($nama) ?>"></div>
			<div class="dpd-row">
				<div class="dpd-fg"><label for="e_lahir">Tanggal Lahir</label><input type="date" id="e_lahir" name="tanggal_lahir" value="<?= dp_e(!empty($member['tanggal_lahir']) ? date('Y-m-d', strtotime($member['tanggal_lahir'])) : '') ?>"></div>
				<div class="dpd-fg"><label for="e_jk">Jenis Kelamin</label>
					<select id="e_jk" name="jenis_kelamin">
						<option value="">-</option>
						<option value="L" <?= ($member['jenis_kelamin'] ?? '') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
						<option value="P" <?= ($member['jenis_kelamin'] ?? '') === 'P' ? 'selected' : '' ?>>Perempuan</option>
					</select></div>
			</div>
			<div class="dpd-fg"><label for="e_wali">Nama Wali</label><input type="text" id="e_wali" name="nama_wali" value="<?= dp_e($member['nama_wali'] ?? '') ?>"></div>
			<div class="dpd-fg"><label for="e_wa">WhatsApp</label><input type="text" id="e_wa" name="no_whatsapp" required value="<?= dp_e($member['no_whatsapp'] ?? '') ?>" placeholder="08xxxxxxxxxx"></div>
			<?php if (array_key_exists('email', $member)): ?><div class="dpd-fg"><label for="e_email">Email</label><input type="email" id="e_email" name="email" value="<?= dp_e($member['email'] ?? '') ?>"></div><?php endif; ?>
			<div class="dpd-fg"><label for="e_alamat">Alamat</label><textarea id="e_alamat" name="alamat" rows="3"><?= dp_e($member['alamat'] ?? '') ?></textarea></div>
			<?php if (array_key_exists('catatan', $member)): ?><div class="dpd-fg"><label for="e_cat">Catatan</label><textarea id="e_cat" name="catatan" rows="2"><?= dp_e($member['catatan'] ?? '') ?></textarea></div><?php endif; ?>
			<div class="dpd-act">
				<button type="button" class="dpd-btn" id="e_batal">Batal</button>
				<button type="submit" class="dpd-btn pri">Simpan</button>
			</div>
		</form>
	</div>

	<div class="dpd-overlay" id="dpd-wa" role="dialog" aria-modal="true" aria-labelledby="dpd-wa-judul">
		<form class="dpd-box wide" id="dpd-waform" autocomplete="off">
			<h3 id="dpd-wa-judul">Kirim WhatsApp</h3>
			<p class="dpd-sub">Ke <b><?= dp_e($nama) ?></b> · <?= dp_e($member['no_whatsapp'] ?? '-') ?></p>
			<div class="dpd-fg"><label for="w_isi">Pesan</label>
				<textarea id="w_isi" rows="6" maxlength="1000" required><?= dp_e('Halo ' . ($member['nama_wali'] ?: $nama) . ', ' . ($paketAktif ? 'sisa sesi ' . $nama . ' di paket ' . ($paketAktif['nama_program'] ?? '') . ' tinggal ' . (int)$paketAktif['sisa_sesi'] . ' sesi. Mau kami bantu perpanjang paketnya?' : 'ini admin Natix Swim. Ada yang bisa kami bantu?')) ?></textarea>
			</div>
			<div class="dpd-act">
				<button type="button" class="dpd-btn" id="w_batal">Batal</button>
				<button type="submit" class="dpd-btn pri">Kirim</button>
			</div>
		</form>
	</div>

	<script>
	var DP = { id: <?= (int)$id ?>, nama: <?= json_encode($nama) ?>, wa: <?= json_encode((string)($member['no_whatsapp'] ?? '')) ?> };
	var $d = function (i) { return document.getElementById(i); };
	var ICO = {
		tanya: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.6 2.6 0 015 .8c0 1.7-2.5 2-2.5 3.7M12 17v.01"/></svg>',
		hapus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>',
		ok: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/></svg>',
		gagal: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5l9 16h-18z"/><path d="M12 10v4.5M12 17.5v.01"/></svg>'
	};
	/* Popup umum. tipe: konfirmasi | bahaya | sukses | gagal. Mengembalikan Promise<boolean> (true = tombol utama). */
	function dpDialog(o) {
		return new Promise(function (res) {
			var ov = $d('dpd-info'), box = ov.firstElementChild, yes = $d('dpd-ya'), no = $d('dpd-batal'), t = o.tipe || 'konfirmasi';
			box.className = 'dpd-box ' + t;
			$d('dpd-ico').innerHTML = ICO[t === 'konfirmasi' ? 'tanya' : (t === 'bahaya' ? 'hapus' : (t === 'sukses' ? 'ok' : 'gagal'))];
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
	function dpModal(id, v) { $d(id).classList.toggle('show', v); }
	function dpPost(url, body, json) {
		var opt = { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
		if (json) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); } else { opt.body = body; }
		return fetch(url, opt).then(function (r) { return r.text().then(function (tx) { try { return JSON.parse(tx); } catch (e) { throw new Error('Respons server tidak valid.'); } }); });
	}

	/* ---- Hapus ---- */
	function dpHapus() {
		dpDialog({ tipe: 'bahaya', judul: 'Hapus member ini?', pesan: DP.nama + ' beserta paket, tagihan, dan riwayat kehadirannya akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.', ya: 'Ya, Hapus', batal: 'Batal' }).then(function (y) {
			if (!y) return;
			var f = new FormData(); f.append('action', 'hapus'); f.append('id', DP.id);
			dpPost('proses-member.php', f).then(function (d) {
				if (d && d.success) return dpDialog({ tipe: 'sukses', judul: 'Berhasil dihapus', pesan: d.message || 'Data member telah dihapus.', ya: 'Kembali ke Data Member', batal: false }).then(function () { location.href = 'datamember.php'; });
				return dpDialog({ tipe: 'gagal', judul: 'Gagal menghapus', pesan: (d && d.message) || 'Terjadi kesalahan.', ya: 'Tutup', batal: false });
			}).catch(function (e) { dpDialog({ tipe: 'gagal', judul: 'Gagal menghapus', pesan: e.message, ya: 'Tutup', batal: false }); });
		});
	}

	/* ---- Ubah data ---- */
	function dpBukaEdit() { dpModal('dpd-edit', true); $d('e_nama').focus(); }
	$d('e_batal').onclick = function () { dpModal('dpd-edit', false); };
	$d('dpd-form').addEventListener('submit', function (ev) {
		ev.preventDefault();
		var fd = new FormData(this); fd.append('action', 'ubah_pribadi');
		dpDialog({ tipe: 'konfirmasi', judul: 'Simpan perubahan?', pesan: 'Data ' + DP.nama + ' akan diperbarui.', ya: 'Ya, Simpan', batal: 'Batal' }).then(function (y) {
			if (!y) return;   // Batal: kembali ke form tanpa menyimpan
			dpPost('cdm-detail-profil.php?id=' + DP.id, fd).then(function (d) {
				if (d && d.success) { dpModal('dpd-edit', false); return dpDialog({ tipe: 'sukses', judul: 'Berhasil disimpan', pesan: d.message || 'Data pribadi berhasil diperbarui.', ya: 'OK', batal: false }).then(function () { location.reload(); }); }
				return dpDialog({ tipe: 'gagal', judul: 'Gagal menyimpan', pesan: (d && d.message) || 'Terjadi kesalahan.', ya: 'Tutup', batal: false });
			}).catch(function (e) { dpDialog({ tipe: 'gagal', judul: 'Gagal menyimpan', pesan: e.message, ya: 'Tutup', batal: false }); });
		});
	});

	/* ---- Kirim WhatsApp ---- */
	function dpBukaWA() { dpModal('dpd-wa', true); $d('w_isi').focus(); }
	$d('w_batal').onclick = function () { dpModal('dpd-wa', false); };
	$d('dpd-waform').addEventListener('submit', function (ev) {
		ev.preventDefault();
		var isi = $d('w_isi').value.trim(); if (!isi) return;
		dpDialog({ tipe: 'konfirmasi', judul: 'Kirim pesan WhatsApp?', pesan: 'Pesan akan dikirim ke ' + DP.nama + ' (' + DP.wa + ').', ya: 'Ya, Kirim', batal: 'Batal' }).then(function (y) {
			if (!y) return;
			dpPost('manajemen-ai-chat.php?aksi=baru', { no: DP.wa, isi: isi }, true).then(function (d) {
				if (d && d.ok) { dpModal('dpd-wa', false); return dpDialog({ tipe: 'sukses', judul: 'Pesan terkirim', pesan: d.pesan || 'Pesan WhatsApp berhasil dikirim.', ya: 'Buka Chat Log', batal: 'Tutup' }).then(function (b) { if (b) location.href = 'manajemen-ai-chat.php'; }); }
				return dpDialog({ tipe: 'gagal', judul: 'Pesan gagal dikirim', pesan: (d && (d.pesan || d.message || d.error)) || 'Periksa koneksi WhatsApp di halaman Chat Log.', ya: 'Tutup', batal: false });
			}).catch(function (e) { dpDialog({ tipe: 'gagal', judul: 'Pesan gagal dikirim', pesan: e.message, ya: 'Tutup', batal: false }); });
		});
	});
	['dpd-edit', 'dpd-wa'].forEach(function (i) { $d(i).addEventListener('mousedown', function (e) { if (e.target === this) dpModal(i, false); }); });
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