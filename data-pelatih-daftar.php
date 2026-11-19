<?php
// ============================================================
// data-pelatih-daftar.php — Data Pelatih (daftar)
// Aksi tambah / edit / hapus / export ada di proses-pelatih.php
// ============================================================
// Tampilkan error di layar (hapus 2 baris ini kalau sudah production)
ini_set('display_errors', '1');
error_reporting(E_ALL);

function plGagal(string $judul, string $detail): void
{
    http_response_code(500);
    echo '<div style="max-width:720px;margin:40px auto;padding:20px 24px;border:1px solid #fecaca;background:#fef2f2;border-radius:12px;font:14px/1.6 sans-serif;color:#7f1d1d">'
       . '<b>' . htmlspecialchars($judul) . '</b><br><code style="white-space:pre-wrap">' . htmlspecialchars($detail) . '</code></div>';
    exit;
}

if (!is_file(__DIR__ . '/config/koneksi.php')) plGagal('File config/koneksi.php tidak ditemukan.', 'Folder halaman: ' . __DIR__);
if (!is_file(__DIR__ . '/proses-pelatih.php')) plGagal('File proses-pelatih.php tidak ditemukan.', 'Taruh proses-pelatih.php di folder yang sama dengan halaman ini: ' . __DIR__);

require_once __DIR__ . '/config/koneksi.php';   // menghasilkan $pdo
require_once __DIR__ . '/proses-pelatih.php';   // hanya memuat fungsi (plDaftar, dll.)
if (!function_exists('plDaftar')) plGagal('proses-pelatih.php bukan versi terbaru (fungsi plDaftar tidak ditemukan).', 'Ganti proses-pelatih.php dengan versi terbaru. File dibaca dari: ' . __DIR__ . '/proses-pelatih.php');

// ─── FILTER DARI URL ───
$q       = trim($_GET['q'] ?? '');
$fSpes   = trim($_GET['spesialisasi'] ?? '');
$fStatus = $_GET['status'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;

$daftarStatus = ['aktif' => 'Aktif', 'penuh' => 'Penuh', 'cuti' => 'Cuti', 'nonaktif' => 'Nonaktif'];
if (!isset($daftarStatus[$fStatus])) $fStatus = '';

try {
// ─── DATA ───
$semua = plDaftar($pdo, $q, $fSpes);
if ($fStatus !== '') {   // "Penuh" hasil hitungan, jadi difilter di PHP
    $semua = array_values(array_filter($semua, fn($r) => $r['tampil'] === $fStatus));
}

$totalData = count($semua);
$totalPage = max(1, (int)ceil($totalData / $perPage));
$page      = min($page, $totalPage);
$mulai     = ($page - 1) * $perPage;
$tampil    = array_slice($semua, $mulai, $perPage);

$stat       = plStatistik($pdo);
$spesDiDb   = plSpesDiDb($pdo);
$spesSaran  = array_values(array_unique(array_merge(PL_SPES_DEFAULT, $spesDiDb)));
$adaMaksJam = plAdaKolom($pdo, 'pelatih', 'maks_jam');
} catch (Throwable $ex) {
    plGagal('Gagal memuat data pelatih:', $ex->getMessage());
}

// ─── FUNGSI BANTU ───
function e($t) { return htmlspecialchars((string)($t ?? ''), ENT_QUOTES, 'UTF-8'); }

function inisial($nama) {
    $kata = preg_split('/\s+/', trim((string)$nama));
    $h = strtoupper(substr($kata[0] ?? '', 0, 1) . substr($kata[1] ?? '', 0, 1));
    return $h ?: '?';
}

function kelasSpes($s) {
    $s = strtolower((string)$s);
    if (strpos($s, 'private') !== false) return 'private';
    if (strpos($s, 'baby') !== false)    return 'baby';
    if (preg_match('/kids|anak/', $s))   return 'kids';
    if (strpos($s, 'dewasa') !== false)  return 'dewasa';
    return 'lain';
}

// Membuat URL dengan filter yang tetap terbawa
function urlFilter($ubah) {
    $p = array_merge($_GET, $ubah);
    foreach ($p as $k => $v) { if ($v === '' || $v === null) unset($p[$k]); }
    return '?' . http_build_query($p);
}

$adaFilter  = ($q !== '' || $fSpes !== '' || $fStatus !== '');
$urlExport  = 'proses-pelatih.php?' . http_build_query(array_filter(
    ['aksi' => 'export', 'q' => $q, 'spesialisasi' => $fSpes, 'status' => $fStatus], fn($v) => $v !== ''));
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
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Data Pelatih — Natix Swim</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/reset.css" />
    <link rel="stylesheet" href="css/global.css" />
    <link rel="stylesheet" href="css/data-pelatih-daftar.css?v=<?= time() ?>" />
    <style>
        /* Semua class halaman ini memakai prefix "pl-" supaya tidak bentrok dengan CSS export Figma */
        .main { height: auto !important; min-height: 100vh; }
        .pl-topbar, .pl-topbar *, .pl-page, .pl-page *, .pl-overlay, .pl-overlay * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
        .pl-page p, .pl-page h1, .pl-page h2, .pl-page h3 { margin: 0; }

        /* Topbar */
        .pl-topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 32px; background: #fff; border-bottom: 1px solid #eef0f3; }
        .pl-search { display: flex; align-items: center; gap: 10px; background: #f6f7fb; border-radius: 12px; padding: 0 16px; height: 44px; width: min(420px, 100%); color: #9ca3af; margin: 0; }
        .pl-search input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font-size: 14px; color: #111827; }
        .pl-search input::placeholder { color: #9ca3af; }
        .pl-top-right { display: flex; align-items: center; gap: 14px; }
        .pl-wa { display: inline-flex; align-items: center; gap: 8px; background: #ecfdf3; color: #15803d; font-size: 13px; font-weight: 600; padding: 9px 16px; border-radius: 999px; white-space: nowrap; }
        .pl-wa i { width: 8px; height: 8px; border-radius: 50%; background: #22c55e; }
        .pl-avatar { width: 40px; height: 40px; border-radius: 50%; background: #eef2ff; color: #2563eb; font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; }

        /* Header halaman */
        .pl-page { padding: 28px 32px 48px; color: #111827; width: 100%; }
        .pl-crumb { font-size: 14px; color: #9ca3af; }
        .pl-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-top: 6px; }
        .pl-title { font-size: 30px; font-weight: 700; line-height: 1.2; }
        .pl-sub { font-size: 15px; color: #6b7280; margin-top: 4px !important; }
        .pl-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .pl-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; white-space: nowrap; font-size: 14px; font-weight: 600; border-radius: 12px; padding: 11px 18px; cursor: pointer; border: 1px solid #e5e7eb; background: #fff; color: #111827; text-decoration: none; transition: background .15s; }
        .pl-btn:hover { background: #f9fafb; }
        .pl-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        .pl-btn.primary:hover { background: #1d4ed8; }
        .pl-btn.danger { background: #dc2626; border-color: #dc2626; color: #fff; }
        .pl-btn.danger:hover { background: #b91c1c; }
        .pl-btn:disabled { opacity: .55; cursor: not-allowed; }

        /* Kartu statistik */
        .pl-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin: 24px 0; }
        .pl-stat { background: #fff; border: 1px solid #eceef2; border-radius: 16px; padding: 20px 22px; display: flex; align-items: center; gap: 16px; min-width: 0; }
        .pl-stat-ico { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .pl-stat-ico.blue { background: #eef2ff; color: #2563eb; }
        .pl-stat-ico.green { background: #dcfce7; color: #16a34a; }
        .pl-stat-ico.orange { background: #fff3e0; color: #e8890c; }
        .pl-stat-lbl { font-size: 13px; color: #6b7280; }
        .pl-stat-num { font-size: 28px; font-weight: 700; line-height: 1.15; margin-top: 2px !important; }
        .pl-stat-num small { font-size: 15px; font-weight: 600; color: #6b7280; }

        /* Kartu tabel */
        .pl-card { background: #fff; border: 1px solid #eceef2; border-radius: 16px; overflow: hidden; }
        .pl-card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 20px 24px; }
        .pl-card-title { font-size: 17px; font-weight: 700; }
        .pl-card-sub { font-size: 13px; color: #9ca3af; margin-top: 2px !important; }
        .pl-filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 0; }
        .pl-select { appearance: none; -webkit-appearance: none; background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%236b7280' stroke-width='1.6' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 14px center; border: 1px solid #e5e7eb; border-radius: 10px; padding: 9px 34px 9px 14px; font-size: 13px; color: #374151; cursor: pointer; max-width: 230px; }
        .pl-select:focus { outline: 2px solid #bfdbfe; }
        .pl-reset { font-size: 13px; color: #2563eb; text-decoration: none; font-weight: 600; white-space: nowrap; }
        .pl-reset:hover { text-decoration: underline; }

        .pl-scroll { overflow-x: auto; border-top: 1px solid #f1f2f5; }
        .pl-table { width: 100%; min-width: 860px; border-collapse: collapse; }
        .pl-table th { text-align: left; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #9ca3af; padding: 12px 16px; background: #fafafb; white-space: nowrap; border-bottom: 1px solid #eceef2; }
        .pl-table td { padding: 14px 16px; border-bottom: 1px solid #f1f2f5; font-size: 14px; color: #1f2937; vertical-align: middle; }
        .pl-table tr:last-child td { border-bottom: 0; }
        .pl-table tbody tr:hover td { background: #fbfcfe; }
        .pl-table .c-aksi { text-align: right; white-space: nowrap; }

        .pl-person { display: flex; align-items: center; gap: 12px; min-width: 200px; }
        .pl-av { width: 40px; height: 40px; border-radius: 50%; background: #eff1f4; color: #1f2937; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .pl-name { font-size: 14px; font-weight: 600; color: #111827; white-space: nowrap; }
        .pl-meta { font-size: 12px; color: #9ca3af; margin-top: 2px !important; white-space: nowrap; }
        .pl-main { font-weight: 600; white-space: nowrap; }
        .pl-muted { font-size: 12px; color: #9ca3af; margin-top: 2px !important; white-space: nowrap; }

        .pl-chip { display: inline-block; font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 999px; white-space: nowrap; }
        .pl-chip.kids    { background: #eef2ff; color: #2563eb; }
        .pl-chip.dewasa  { background: #dcfce7; color: #16a34a; }
        .pl-chip.baby    { background: #f3e8ff; color: #7e22ce; }
        .pl-chip.private { background: #ffedd5; color: #c2410c; }
        .pl-chip.lain    { background: #f3f4f6; color: #4b5563; }
        .pl-chip.s-aktif    { background: #dcfce7; color: #16a34a; }
        .pl-chip.s-penuh    { background: #fee2e2; color: #dc2626; }
        .pl-chip.s-cuti     { background: #fef3c7; color: #b45309; }
        .pl-chip.s-nonaktif { background: #f3f4f6; color: #6b7280; }

        .pl-bar { width: 140px; height: 7px; border-radius: 999px; background: #eef0f3; margin-top: 7px; overflow: hidden; }
        .pl-bar i { display: block; height: 100%; border-radius: 999px; background: #2563eb; }
        .pl-bar i.warn { background: #f59e0b; }
        .pl-bar i.full { background: #ef4444; }

        .pl-ibtn { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 10px; border: 1px solid #e5e7eb; background: #fff; color: #6b7280; cursor: pointer; margin-left: 6px; text-decoration: none; padding: 0; }
        .pl-ibtn:hover { background: #f3f4f6; color: #111827; }
        .pl-ibtn.del:hover { background: #fef2f2; border-color: #fecaca; color: #dc2626; }

        .pl-empty { padding: 48px 16px !important; text-align: center; color: #9ca3af; }

        /* Pagination */
        .pl-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 16px 24px; border-top: 1px solid #f1f2f5; }
        .pl-foot p { font-size: 13px; color: #6b7280; }
        .pl-pager { display: flex; gap: 6px; flex-wrap: wrap; }
        .pl-pg { min-width: 36px; height: 36px; padding: 0 10px; border-radius: 10px; border: 1px solid #e5e7eb; background: #fff; color: #374151; font-size: 13px; font-weight: 500; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
        .pl-pg:hover { background: #f9fafb; }
        .pl-pg.on { background: #2563eb; border-color: #2563eb; color: #fff; }
        .pl-pg.off { opacity: .45; pointer-events: none; }

        /* Modal */
        .pl-overlay { position: fixed; inset: 0; background: rgba(17,24,39,.45); display: none; align-items: center; justify-content: center; padding: 20px; z-index: 1000; }
        .pl-overlay.show { display: flex; }
        .pl-modal { background: #fff; border-radius: 18px; width: 100%; max-width: 520px; max-height: calc(100vh - 40px); overflow-y: auto; padding: 26px; box-shadow: 0 24px 60px rgba(16,24,40,.25); }
        .pl-modal.sm { max-width: 420px; }
        .pl-modal-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
        .pl-modal h3 { font-size: 20px; font-weight: 700; }
        .pl-x { border: 0; background: #f3f4f6; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 18px; line-height: 1; color: #6b7280; }
        .pl-x:hover { background: #e5e7eb; }
        .pl-form { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 0; }
        .pl-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .pl-field.full { grid-column: 1 / -1; }
        .pl-field label { font-size: 13px; font-weight: 600; color: #374151; }
        .pl-input { width: 100%; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; font-size: 14px; color: #111827; background: #fff; }
        .pl-input:focus { outline: 2px solid #bfdbfe; border-color: #93c5fd; }
        .pl-hint { font-size: 12px; color: #9ca3af; }
        .pl-err { grid-column: 1 / -1; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 10px 12px; font-size: 13px; line-height: 1.4; display: none; }
        .pl-err.show { display: block; }
        .pl-note { grid-column: 1 / -1; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 10px; padding: 10px 12px; font-size: 12px; line-height: 1.4; }
        .pl-foot-btns { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; flex-wrap: wrap; }
        .pl-text { font-size: 14px; color: #4b5563; line-height: 1.55; }
        .pl-cred { background: #f6f7f9; border-radius: 12px; padding: 14px 16px; margin-top: 14px; font-size: 14px; display: grid; grid-template-columns: 100px 1fr; gap: 8px; }
        .pl-cred dt { color: #9ca3af; margin: 0; }
        .pl-cred dd { margin: 0; font-weight: 600; color: #111827; word-break: break-all; font-family: ui-monospace, Menlo, monospace; }

        .pl-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(20px); background: #111827; color: #fff; padding: 12px 20px; border-radius: 12px; font-size: 14px; opacity: 0; pointer-events: none; transition: .25s; z-index: 2000; max-width: 90vw; }
        .pl-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .pl-toast.err { background: #dc2626; }

        @media (max-width: 900px) { .pl-stats { grid-template-columns: 1fr; } }
        @media (max-width: 640px) {
            .pl-page { padding: 20px 16px 40px; }
            .pl-topbar { padding: 14px 16px; }
            .pl-wa { display: none; }
            .pl-form { grid-template-columns: 1fr; }
        }
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

.pl-table th.c-aksi, .pl-table td.c-aksi { text-align: center; width: 150px; }
.pl-table td.c-aksi { font-size: 0; line-height: 0; white-space: nowrap; }
.pl-table td.c-aksi .pl-ibtn { margin: 0 3px; vertical-align: middle; }
</style>
</head>

<body>
	<?php nvSidebar(); ?>


    <div class="main">
        <!-- Topbar -->
        <header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div></header>

        <div class="pl-page">
            <?= nvCrumb() ?>

            <div class="pl-head">
                <div>
                    <h1 class="pl-title">Data Pelatih</h1>
                    <p class="pl-sub">Kelola profil, spesialisasi, dan beban mengajar pelatih Natix Swim</p>
                </div>
                <div class="pl-actions">
                    <a class="pl-btn" href="<?= e($urlExport) ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
                        Export
                    </a>
                    <button type="button" class="pl-btn primary" onclick="bukaModalTambah()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        Tambah Pelatih
                    </button>
                </div>
            </div>

            <!-- Statistik -->
            <div class="pl-stats">
                <div class="pl-stat">
                    <div class="pl-stat-ico blue"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0113 0M16 4.5a3.5 3.5 0 010 7M21.5 20a6.5 6.5 0 00-4-6"/></svg></div>
                    <div><p class="pl-stat-lbl">Total Pelatih</p><p class="pl-stat-num"><?= $stat['total'] ?></p></div>
                </div>
                <div class="pl-stat">
                    <div class="pl-stat-ico green"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/></svg></div>
                    <div><p class="pl-stat-lbl">Aktif Mengajar</p><p class="pl-stat-num"><?= $stat['aktif'] ?></p></div>
                </div>
                <div class="pl-stat">
                    <div class="pl-stat-ico orange"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
                    <div><p class="pl-stat-lbl">Total Jam / Minggu</p><p class="pl-stat-num"><?= $stat['jam'] + 0 ?> <small>jam</small></p></div>
                </div>
            </div>

            <!-- Daftar -->
            <div class="pl-card">
                <div class="pl-card-head">
                    <div>
                        <p class="pl-card-title">Daftar Pelatih</p>
                        <p class="pl-card-sub"><?= $totalData ?> pelatih ditemukan</p>
                    </div>
                    <form class="pl-filters" method="get" action="">
                        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
                        <select class="pl-select" name="spesialisasi" onchange="this.form.submit()">
                            <option value="">Spesialisasi: Semua</option>
                            <?php foreach ($spesDiDb as $s): ?>
                                <option value="<?= e($s) ?>" <?= $s === $fSpes ? 'selected' : '' ?>><?= e($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="pl-select" name="status" onchange="this.form.submit()">
                            <option value="">Status: Semua</option>
                            <?php foreach ($daftarStatus as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= $k === $fStatus ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($adaFilter): ?><a class="pl-reset" href="?">Reset</a><?php endif; ?>
                    </form>
                </div>

                <div class="pl-scroll">
                    <table class="pl-table">
                        <thead>
                            <tr>
                                <th>Pelatih</th>
                                <th>Spesialisasi</th>
                                <th>Kelas Diampu</th>
                                <th>Beban Mengajar</th>
                                <th>Status</th>
                                <th class="c-aksi">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($tampil)): ?>
                            <tr><td colspan="6" class="pl-empty"><?= $adaFilter ? 'Tidak ada pelatih yang cocok dengan pencarian / filter.' : 'Belum ada data pelatih.' ?></td></tr>
                        <?php endif; ?>

                        <?php foreach ($tampil as $r):
                            $persen = $r['maks_jam'] > 0 ? min(100, $r['jam'] / $r['maks_jam'] * 100) : 0;
                            $barCls = $persen >= 100 ? 'full' : ($persen >= 80 ? 'warn' : '');
                        ?>
                            <tr>
                                <td>
                                    <div class="pl-person">
                                        <div class="pl-av"><?= e(inisial($r['nama'])) ?></div>
                                        <div>
                                            <p class="pl-name"><?= e($r['nama'] ?: '(tanpa nama)') ?></p>
                                            <p class="pl-meta"><?= sprintf('PLT-%03d', $r['id']) ?> • <?= e($r['no_hp'] ?: '-') ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="pl-chip <?= kelasSpes($r['spesialisasi']) ?>"><?= e($r['spesialisasi'] ?: '-') ?></span></td>
                                <td>
                                    <p class="pl-main"><?= $r['jml_kelas'] ?> <?= $r['satuan'] ?></p>
                                    <p class="pl-muted"><?= $r['status_key'] === 'cuti' ? 'Sedang cuti' : $r['jml_member'] . ' member' ?></p>
                                </td>
                                <td>
                                    <p class="pl-main"><?= $r['jam'] + 0 ?> / <?= $r['maks_jam'] ?> jam</p>
                                    <div class="pl-bar"><i class="<?= $barCls ?>" style="width:<?= round($persen) ?>%"></i></div>
                                </td>
                                <td><span class="pl-chip s-<?= $r['tampil'] ?>"><?= plLabelStatus($r['tampil']) ?></span></td>
                                <td class="c-aksi">
                                    <a class="pl-ibtn" title="Lihat detail" href="data-pelatih-detail.php?id=<?= (int)$r['id'] ?>">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </a>
                                    <button type="button" class="pl-ibtn" title="Edit"
                                        data-id="<?= (int)$r['id'] ?>" data-nama="<?= e($r['nama']) ?>" data-email="<?= e($r['email']) ?>"
                                        data-hp="<?= e($r['no_hp']) ?>" data-spes="<?= e($r['spesialisasi']) ?>"
                                        data-maks="<?= (int)$r['maks_jam'] ?>" data-status="<?= e($r['status_key']) ?>"
                                        onclick="bukaModalEdit(this)">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9a2.8 2.8 0 00-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/></svg>
                                    </button>
                                    <button type="button" class="pl-ibtn del" title="Hapus"
                                        data-id="<?= (int)$r['id'] ?>" data-nama="<?= e($r['nama']) ?>" onclick="bukaModalHapus(this)">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pl-foot">
                    <p>
                        <?php if ($totalData > 0): ?>
                            Menampilkan <?= $mulai + 1 ?>–<?= $mulai + count($tampil) ?> dari <?= $totalData ?> pelatih
                        <?php else: ?>
                            Tidak ada data
                        <?php endif; ?>
                    </p>
                    <div class="pl-pager">
                        <a class="pl-pg <?= $page <= 1 ? 'off' : '' ?>" href="<?= e(urlFilter(['page' => max(1, $page - 1)])) ?>">‹</a>
                        <?php for ($p = 1; $p <= $totalPage; $p++): ?>
                            <a class="pl-pg <?= $p == $page ? 'on' : '' ?>" href="<?= e(urlFilter(['page' => $p])) ?>"><?= $p ?></a>
                        <?php endfor; ?>
                        <a class="pl-pg <?= $page >= $totalPage ? 'off' : '' ?>" href="<?= e(urlFilter(['page' => min($totalPage, $page + 1)])) ?>">›</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== MODAL TAMBAH / EDIT ===================== -->
    <div class="pl-overlay" id="modalForm">
        <div class="pl-modal">
            <div class="pl-modal-top">
                <h3 id="judulModal">Tambah Pelatih</h3>
                <button type="button" class="pl-x" onclick="tutupModal('modalForm')">×</button>
            </div>

            <form id="formPelatih" class="pl-form" novalidate>
                <input type="hidden" name="aksi" id="f_aksi" value="tambah">
                <input type="hidden" name="id" id="f_id">

                <div class="pl-field full">
                    <label for="f_nama">Nama Lengkap</label>
                    <input class="pl-input" type="text" name="nama" id="f_nama" maxlength="100" placeholder="cth. Rizky Aditya">
                </div>
                <div class="pl-field">
                    <label for="f_email">Email</label>
                    <input class="pl-input" type="email" name="email" id="f_email" maxlength="100" placeholder="nama@email.com">
                </div>
                <div class="pl-field">
                    <label for="f_no_hp">No. HP</label>
                    <input class="pl-input" type="text" name="no_hp" id="f_no_hp" maxlength="20" placeholder="0812-xxxx-xxxx">
                </div>
                <div class="pl-field">
                    <label for="f_spesialisasi">Spesialisasi</label>
                    <input class="pl-input" type="text" name="spesialisasi" id="f_spesialisasi" maxlength="100" list="dl-spes" placeholder="Pilih / ketik sendiri">
                    <datalist id="dl-spes">
                        <?php foreach ($spesSaran as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="pl-field">
                    <label for="f_maks_jam">Maks. Jam / Minggu</label>
                    <input class="pl-input" type="number" name="maks_jam" id="f_maks_jam" min="1" max="60" value="20" <?= $adaMaksJam ? '' : 'disabled title="Kolom maks_jam belum ada di database"' ?>>
                    <?php if (!$adaMaksJam): ?><span class="pl-hint">Jalankan pelatih_migrasi.sql dulu agar bisa diatur.</span><?php endif; ?>
                </div>
                <div class="pl-field full">
                    <label for="f_status">Status</label>
                    <select class="pl-input" name="status" id="f_status">
                        <option value="aktif">Aktif</option>
                        <option value="cuti">Cuti</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                </div>
                <p class="pl-note" id="f_note">Akun login pelatih dibuat otomatis. Password awal acak akan ditampilkan sekali setelah disimpan.</p>
                <div class="pl-err" id="f_err"></div>

                <div class="pl-foot-btns" style="grid-column:1/-1">
                    <button type="button" class="pl-btn" onclick="tutupModal('modalForm')">Batal</button>
                    <button type="submit" class="pl-btn primary" id="btnSimpan">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODAL HAPUS ===================== -->
    <div class="pl-overlay" id="modalHapus">
        <div class="pl-modal sm">
            <div class="pl-modal-top">
                <h3>Hapus Pelatih</h3>
                <button type="button" class="pl-x" onclick="tutupModal('modalHapus')">×</button>
            </div>
            <p class="pl-text">Yakin ingin menghapus <b id="hapusNama"></b>? Akun login pelatih ikut terhapus dan data tidak bisa dikembalikan.</p>
            <input type="hidden" id="hapusId">
            <div class="pl-err" id="h_err" style="margin-top:14px"></div>
            <div class="pl-foot-btns">
                <button type="button" class="pl-btn" onclick="tutupModal('modalHapus')">Batal</button>
                <button type="button" class="pl-btn danger" id="btnHapus" onclick="konfirmasiHapus()">Ya, Hapus</button>
            </div>
        </div>
    </div>

    <!-- ===================== MODAL PASSWORD AWAL ===================== -->
    <div class="pl-overlay" id="modalPass">
        <div class="pl-modal sm">
            <div class="pl-modal-top"><h3>Pelatih Ditambahkan</h3></div>
            <p class="pl-text">Akun login sudah dibuat. Catat password awal di bawah, karena <b>tidak akan ditampilkan lagi</b>.</p>
            <dl class="pl-cred">
                <dt>Email</dt><dd id="p_email"></dd>
                <dt>Password</dt><dd id="p_pass"></dd>
            </dl>
            <div class="pl-foot-btns">
                <button type="button" class="pl-btn" onclick="salinPassword()" id="btnSalin">Salin Password</button>
                <button type="button" class="pl-btn primary" onclick="location.reload()">Selesai</button>
            </div>
        </div>
    </div>

    <div class="pl-toast" id="plToast"></div>

    <script>
    var $ = function (id) { return document.getElementById(id); };

    // ===== TOAST =====
    function toast(msg, err) {
        var t = $('plToast');
        t.textContent = msg;
        t.classList.toggle('err', !!err);
        t.classList.add('show');
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { t.classList.remove('show'); }, 2800);
    }

    // ===== BUKA / TUTUP MODAL =====
    function bukaModal(id)  { $(id).classList.add('show'); }
    function tutupModal(id) { $(id).classList.remove('show'); }

    document.querySelectorAll('.pl-overlay').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (e.target === el && el.id !== 'modalPass') tutupModal(el.id);   // modal password harus ditutup lewat "Selesai"
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { tutupModal('modalForm'); tutupModal('modalHapus'); }
    });

    function tampilError(id, pesan) {
        var el = $(id);
        el.textContent = pesan || '';
        el.classList.toggle('show', !!pesan);
    }

    // ===== KIRIM KE SERVER =====
    function kirim(formData) {
        var status = 0, txt = '';
        return fetch('proses-pelatih.php', { method: 'POST', body: formData })
            .then(function (res) { status = res.status; return res.text(); })
            .then(function (t) { txt = t; return JSON.parse(t); })
            .catch(function (err) {
                console.error('[proses-pelatih] HTTP', status, txt.slice(0, 800), err);
                return { sukses: false, pesan: status
                    ? 'Server membalas bukan JSON (HTTP ' + status + '). Buka Console browser (F12) untuk melihat isinya.'
                    : 'Gagal terhubung ke server.' };
            });
    }

    // ===== TAMBAH =====
    function bukaModalTambah() {
        $('formPelatih').reset();
        $('f_aksi').value = 'tambah';
        $('f_id').value = '';
        $('judulModal').textContent = 'Tambah Pelatih';
        $('f_note').style.display = '';
        tampilError('f_err', '');
        bukaModal('modalForm');
        $('f_nama').focus();
    }

    // ===== EDIT (data dari atribut data-* tombol) =====
    function bukaModalEdit(tombol) {
        var d = tombol.dataset;
        $('formPelatih').reset();
        $('f_aksi').value = 'edit';
        $('f_id').value = d.id;
        $('f_nama').value = d.nama || '';
        $('f_email').value = d.email || '';
        $('f_no_hp').value = d.hp || '';
        $('f_spesialisasi').value = d.spes || '';
        $('f_maks_jam').value = d.maks || 20;
        $('f_status').value = d.status || 'aktif';
        $('judulModal').textContent = 'Edit Pelatih';
        $('f_note').style.display = 'none';
        tampilError('f_err', '');
        bukaModal('modalForm');
    }

    // ===== SIMPAN (tambah & edit) =====
    $('formPelatih').addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = $('btnSimpan'), form = this;
        tampilError('f_err', '');

        // validasi ringan di browser (server tetap memvalidasi ulang)
        var nama = $('f_nama').value.trim(), email = $('f_email').value.trim();
        if (!nama)  { tampilError('f_err', 'Nama wajib diisi.'); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { tampilError('f_err', 'Format email tidak valid.'); return; }
        if (!$('f_spesialisasi').value.trim()) { tampilError('f_err', 'Spesialisasi wajib diisi.'); return; }

        btn.disabled = true; btn.textContent = 'Menyimpan...';
        kirim(new FormData(form)).then(function (h) {
            if (h.sukses) {
                if (h.password_awal) {
                    tutupModal('modalForm');
                    $('p_email').textContent = h.email;
                    $('p_pass').textContent  = h.password_awal;
                    bukaModal('modalPass');
                } else {
                    toast(h.pesan);
                    setTimeout(function () { location.reload(); }, 700);
                }
            } else {
                tampilError('f_err', h.pesan);
                btn.disabled = false; btn.textContent = 'Simpan';
            }
        });
    });

    function salinPassword() {
        var t = $('p_pass').textContent;
        var ok = function () { $('btnSalin').textContent = 'Tersalin ✓'; };
        if (navigator.clipboard) { navigator.clipboard.writeText(t).then(ok); }
        else { var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); ok(); }
    }

    // ===== HAPUS =====
    function bukaModalHapus(tombol) {
        $('hapusId').value = tombol.dataset.id;
        $('hapusNama').textContent = tombol.dataset.nama || '';
        tampilError('h_err', '');
        bukaModal('modalHapus');
    }
    function konfirmasiHapus() {
        var btn = $('btnHapus'), fd = new FormData();
        fd.append('aksi', 'hapus');
        fd.append('id', $('hapusId').value);
        btn.disabled = true; btn.textContent = 'Menghapus...';
        kirim(fd).then(function (h) {
            if (h.sukses) { toast(h.pesan); setTimeout(function () { location.reload(); }, 600); }
            else { tampilError('h_err', h.pesan); btn.disabled = false; btn.textContent = 'Ya, Hapus'; }
        });
    }
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