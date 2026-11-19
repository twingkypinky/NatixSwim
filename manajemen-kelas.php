<?php
require_once 'config/koneksi.php';
require_once 'config/helpers.php';

$daftar_program = ambil($pdo, "SELECT * FROM kelas_program ORDER BY id DESC");
$total_program = count($daftar_program);

$program_aktif = 0;
foreach ($daftar_program as $p) {
    if ($p['status'] == 'aktif') $program_aktif++;
}

$kelas_per_minggu = nilai($pdo, "SELECT COUNT(*) FROM jadwal WHERE WEEK(tanggal) = WEEK(CURDATE()) AND YEAR(tanggal) = YEAR(CURDATE())") ?: 0;
$kelas_hampir_penuh = 0;
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
  <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/reset.css" />
  <link rel="stylesheet" href="css/global.css" />
  <link rel="stylesheet" href="css/manajemen-kelas.css" />
  <style>
    /* ========= OVERLAY & MODAL ========= */
    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.45);
      z-index: 9999;
      justify-content: center;
      align-items: center;
      backdrop-filter: blur(2px);
    }
    .modal-overlay.show { display: flex; }

    .modal-box {
      background: #fff;
      width: 520px;
      max-height: 90vh;
      border-radius: 16px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.15);
      padding: 28px 32px;
      overflow-y: auto;
      animation: modalIn 0.2s ease-out;
    }
    @keyframes modalIn {
      from { transform: translateY(20px); opacity: 0; }
      to   { transform: translateY(0);    opacity: 1; }
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 1px solid #e5e7eb;
    }
    .modal-header h3 {
      font-size: 18px;
      font-weight: 700;
      color: #111827;
      margin: 0;
    }
    .modal-close {
      width: 32px; height: 32px;
      border-radius: 8px;
      border: none;
      background: #f3f4f6;
      color: #6b7280;
      font-size: 18px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.2s;
    }
    .modal-close:hover { background: #e5e7eb; }

    /* ========= FORM STYLES ========= */
    .form-group {
      margin-bottom: 16px;
    }
    .form-group label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: #374151;
      margin-bottom: 6px;
    }
    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid #d1d5db;
      border-radius: 8px;
      font-size: 14px;
      font-family: inherit;
      color: #111827;
      background: #fff;
      box-sizing: border-box;
      transition: border 0.2s;
    }
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
    }
    .form-group textarea { resize: vertical; min-height: 60px; }
    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }
    .modal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      margin-top: 24px;
      padding-top: 16px;
      border-top: 1px solid #e5e7eb;
    }
    .btn-cancel {
      padding: 10px 20px;
      border: 1px solid #d1d5db;
      border-radius: 10px;
      background: #fff;
      color: #374151;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      transition: background 0.2s;
    }
    .btn-cancel:hover { background: #f3f4f6; }
    .btn-submit {
      padding: 10px 24px;
      border: none;
      border-radius: 10px;
      background: #2563eb;
      color: #fff;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.2s;
    }
    .btn-submit:hover { background: #1d4ed8; }
    .btn-danger {
      background: #dc2626;
    }
    .btn-danger:hover { background: #b91c1c; }

    /* ========= FILTER FIX (biar ga kepotong) ========= */
    .tools-btn-fil {
      min-width: 160px !important;
      padding: 8px 14px !important;
      border-radius: 8px !important;
      border: 1px solid #e5e7eb !important;
      background: #fff !important;
      font-size: 13px !important;
      cursor: pointer !important;
    }

    /* ========= GRID TABLE ========= */
    .grid-header, .grid-row {
      display: grid;
      grid-template-columns: 3fr 1fr 1.2fr 0.9fr 1.3fr 0.9fr 0.9fr;
      align-items: center;
      padding: 12px 16px;
      gap: 8px;
    }
    .grid-header {
      background: #f8f9fc;
      border-radius: 8px;
      font-weight: 600;
      font-size: 13px;
      color: #6b7280;
      margin-bottom: 8px;
    }
    .grid-header > div { text-align: center; }
    .grid-header > div:first-child { text-align: left; }
    .grid-row {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      margin-bottom: 6px;
      transition: box-shadow 0.2s;
    }
    .grid-row:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .grid-row > div { text-align: center; }
    .col-program {
      display: flex;
      align-items: center;
      gap: 10px;
      text-align: left;
    }
    .prog-name { font-weight: 600; font-size: 14px; color: #111827; }
    .prog-desc { font-size: 12px; color: #9ca3af; }
    .badge-cat {
      display: inline-block; padding: 4px 12px; border-radius: 20px;
      font-size: 12px; font-weight: 500;
      background: #eff6ff; color: #2563eb; border: none;
    }
    .badge-status {
      display: inline-block; padding: 4px 12px; border-radius: 20px;
      font-size: 12px; font-weight: 500; border: none;
    }
    .badge-status.aktif { background: #ecfdf5; color: #059669; }
    .badge-status.nonaktif { background: #fef2f2; color: #dc2626; }
    .col-harga { font-weight: 600; color: #111827; }
    .col-sesi { color: #374151; }
    .col-capacity { display: flex; flex-direction: column; align-items: center; gap: 4px; }
    .cap-text { font-size: 12px; color: #6b7280; }
    .cap-bar { width: 80%; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden; }
    .cap-bar-fill { height: 100%; border-radius: 3px; background: #d1d5db; }
    .btn-avatar-sm {
      width: 36px; height: 36px; border-radius: 50%;
      background: #eef2ff; color: #4f46e5; font-weight: 700; font-size: 14px;
      border: none; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .col-actions { display: flex; justify-content: center; gap: 6px; }
    .btn-icon {
      width: 32px; height: 32px; border-radius: 8px;
      border: 1px solid #e5e7eb; background: #fff;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; transition: all 0.2s;
    }
    .btn-icon:hover { background: #f3f4f6; border-color: #d1d5db; }
    .btn-icon svg { width: 16px; height: 16px; }
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
  .main-content { padding-left: 16px !important; padding-right: 16px !important; box-sizing: border-box; }
  .main-ph, .main-card-head { flex-wrap: wrap; gap: 12px; }
  .main-acts, .tools1 { flex-wrap: wrap; }
  .main-stats { display: grid !important; grid-template-columns: repeat(2, minmax(0, 1fr)) !important; gap: 12px !important; }
  .main-stats > * { width: auto !important; min-width: 0; }
  .main-card.card-white7 { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .main-card.card-white7 .grid-header, .main-card.card-white7 .grid-row { min-width: 900px; }
  .main-pager { flex-wrap: wrap; gap: 10px; }
}
@media (max-width: 560px) { .main-stats { grid-template-columns: 1fr !important; } .modal-box { width: 100%; padding: 22px 18px; } .form-row { grid-template-columns: 1fr; } .tools-btn-fil { min-width: 0 !important; width: 100%; } }

.kp-ico { position: static !important; width: 44px; height: 44px; flex: 0 0 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; overflow: visible; margin: 0; transform: none; }
.kp-ico svg { width: 22px; height: 22px; display: block; flex: none; }
.kp-blue { background: #eff6ff; color: #2563eb; } .kp-green { background: #ecfdf5; color: #059669; } .kp-orange { background: #fff7ed; color: #ea580c; } .kp-red { background: #fef2f2; color: #dc2626; }
.kp-plus { width: 16px; height: 16px; flex: 0 0 16px; display: block; position: static !important; transform: none !important; font-style: normal; }
.btn-btn-primary1 { font-style: normal !important; }
.col-harga { font-weight: 400 !important; color: #374151 !important; }
.badge-cat, .badge-status { white-space: nowrap; line-height: 1.5; overflow: visible; }
/* kp-stat-fix: ke-4 kartu statistik dibuat seragam */
.main-stats { display: grid !important; grid-template-columns: repeat(4, minmax(0, 1fr)) !important; gap: 16px !important; width: 100% !important; align-items: stretch !important; }
.main-stats > * { position: static !important; inset: auto !important; transform: none !important; width: auto !important; height: auto !important; min-height: 92px; margin: 0 !important; padding: 18px 20px !important; box-sizing: border-box !important; display: flex !important; flex-direction: row !important; align-items: center !important; justify-content: flex-start !important; gap: 14px !important; background: #fff !important; border: 1px solid #eef0f4 !important; border-radius: 16px !important; min-width: 0; }
.main-stats .group-o { position: static !important; inset: auto !important; transform: none !important; width: auto !important; height: auto !important; margin: 0 !important; padding: 0 !important; display: block !important; flex: 1 1 auto; min-width: 0; text-align: left !important; }
.main-stats .group-o p { position: static !important; transform: none !important; margin: 0 !important; font-size: 14px !important; line-height: 1.3 !important; color: #6b7280 !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.main-stats .group-o h2 { position: static !important; transform: none !important; margin: 4px 0 0 !important; font-size: 28px !important; line-height: 1.1 !important; font-weight: 700 !important; color: #111827 !important; }
@media (max-width: 1000px) { .main-stats { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; } }
@media (max-width: 560px) { .main-stats { grid-template-columns: 1fr !important; } }
.grid-row > div, .grid-header > div { min-width: 0; }
.tools-btn-fil { height: auto !important; line-height: 1.4 !important; padding-right: 32px !important; text-overflow: clip !important; overflow: visible !important; }
.btn-icon svg { flex: none; overflow: visible; }
</style>
</head>
<body>

<!-- ========= MODAL CONTAINER ========= -->
<div id="modalOverlay" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-header">
      <h3 id="modalTitle">Tambah Program Baru</h3>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div id="modalBody">
      <!-- diisi oleh JavaScript -->
    </div>
  </div>
</div>

<!-- ========= NAVBAR ========= -->
<?php nvSidebar(); ?>

<!-- ========= MAIN CONTENT ========= -->
<div class="main">
  <header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div></header>

  <div class="main-content">
    <div class="main-ph">
      <div class="group-n group18">
        <?= nvCrumb() ?>
        <h2 class="group-subtitle1">Kelas & Program</h2>
        <p class="text-c group-text-bottom">Kelola jenis program, harga paket, kuota sesi, dan kapasitas kelas.</p>
      </div>
      <div class="main-acts">
        <button class="btn-btn-secondary-b main-btn-btn hover-dark" onclick="exportCSV()">Export</button>
        <button class="btn-btn-primary btn-btn-primary1 hover-bright" onclick="openModal('tambah')" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:10px;background:#2563eb;color:#fff;border:none;cursor:pointer;font-size:14px;font-weight:500;">
          <svg class="kp-plus" viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 3v10M3 8h10"/></svg>
          <span>Tambah Program</span>
        </button>
      </div>
    </div>

    <!-- STATS -->
    <div class="main-stats">
      <div class="card-stat1">
        <div class="kp-ico kp-blue"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.5l8.5 4.9v9.2L12 21.5l-8.5-4.9V7.4z"/><path d="M12 12l8.5-4.6M12 12v9.5M12 12L3.5 7.4"/></svg></div>
        <div class="group-o group19"><p class="text-owner group-text20">Total Program</p><h2 class="group-subtitle2"><?= $total_program ?></h2></div>
      </div>
      <div class="card-stat card-stat2">
        <div class="kp-ico kp-green"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/></svg></div>
        <div class="group-o group7"><p class="text-owner group-text20">Program Aktif</p><h2 class="group-subtitle2"><?= $program_aktif ?></h2></div>
      </div>
      <div class="card-stat3">
        <div class="kp-ico kp-orange"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="16" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg></div>
        <div class="group-o group20"><p class="text-owner group-text20">Kelas per Minggu</p><h2 class="group-subtitle2"><?= $kelas_per_minggu ?></h2></div>
      </div>
      <div class="main-stat">
        <div class="kp-ico kp-red"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l9.5 17h-19z"/><path d="M12 10v5M12 18v.01"/></svg></div>
        <div class="group-o group21"><p class="text-owner group-text20">Kelas Hampir Penuh</p><h2 class="group-subtitle2"><?= $kelas_hampir_penuh ?></h2></div>
      </div>
    </div>

    <!-- TABLE -->
    <div class="main-card card-white7">
      <div class="main-card-head">
        <div class="group-p group22">
          <p class="group-text21">Daftar Program</p>
          <p class="text-owner group-text-6-program"><?= $total_program ?> program terdaftar</p>
        </div>
        <div class="tools tools1">
          <select class="tools-btn-fil" id="filterKategori" onchange="filterTable()">
            <option value="">Semua Kategori</option>
            <option value="beginner">Beginner</option>
            <option value="intermediate">Intermediate</option>
            <option value="advanced">Advanced</option>
          </select>
          <select class="tools-btn-fil" id="filterStatus" onchange="filterTable()">
            <option value="">Semua Status</option>
            <option value="aktif">Aktif</option>
            <option value="non_aktif">Non Aktif</option>
          </select>
        </div>
      </div>

      <!-- HEADER -->
      <div class="grid-header">
        <div style="text-align:left">Program</div>
        <div>Kategori</div>
        <div>Harga Paket</div>
        <div>Kuota Sesi</div>
        <div>Kapasitas Terisi</div>
        <div>Status</div>
        <div>Aksi</div>
      </div>

      <!-- DATA -->
      <div id="programList">
        <?php foreach($daftar_program as $row): 
          $kapasitas_total = (int)($row['kapasitas'] ?? 0);
          $kata = explode(' ', $row['nama_program']);
          $inisial = strtoupper(substr($row['nama_program'], 0, 1) . (isset($kata[1]) ? substr($kata[1], 0, 1) : ''));
          $kategori = strtolower($row['level'] ?? '');
          $status_val = $row['status'] ?? '';
          $harga_fmt = 'Rp ' . number_format((float)($row['harga'] ?? 0), 0, ',', '.');
          $sesi = $row['jumlah_sesi'] ?? 0;
          $json = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="grid-row" data-kategori="<?= e($kategori) ?>" data-status="<?= e($status_val) ?>">
          <div class="col-program">
            <button class="btn-avatar-sm"><?= $inisial ?></button>
            <div>
              <div class="prog-name"><?= e($row['nama_program']) ?></div>
              <div class="prog-desc"><?= e($row['deskripsi'] ?? '') ?></div>
            </div>
          </div>
          <div><span class="badge-cat"><?= e(ucfirst($kategori)) ?></span></div>
          <div class="col-harga"><?= $harga_fmt ?></div>
          <div class="col-sesi"><?= $sesi ?> sesi</div>
          <div class="col-capacity">
            <span class="cap-text">0 / <?= $kapasitas_total ?></span>
            <div class="cap-bar"><div class="cap-bar-fill" style="width:0%"></div></div>
          </div>
          <div>
            <span class="badge-status <?= $status_val == 'aktif' ? 'aktif' : 'nonaktif' ?>">
              <?= $status_val == 'aktif' ? 'Aktif' : 'Non Aktif' ?>
            </span>
          </div>
          <div class="col-actions">
            <button class="btn-icon" title="Edit" onclick='openModal("edit", <?= $json ?>)'>
              <svg viewBox="0 0 16 16" fill="none" stroke="#6b7280" stroke-width="1.5"><path d="M11.5 2.5l2 2-7 7H4.5v-2l7-7z"/></svg>
            </button>
            <button class="btn-icon" title="Hapus" onclick='openModal("hapus", <?= $row['id'] ?>, "<?= e($row['nama_program']) ?>")'>
              <svg viewBox="0 0 16 16" fill="none" stroke="#dc2626" stroke-width="1.5"><path d="M3 4h10M6 4V2.5a.5.5 0 01.5-.5h3a.5.5 0 01.5.5V4M5 4v9a1 1 0 001 1h4a1 1 0 001-1V4"/></svg>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="main-pager">
        <p class="text-e main-text5">Menampilkan <?= $total_program ?> dari <?= $total_program ?> program</p>
        <div class="main-pg">
          <button class="main-btn hover-zoom">‹</button>
          <button class="main-btn main-btn-on hover-bright">1</button>
          <button class="main-btn hover-zoom">›</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// ========= BUKA MODAL =========
function openModal(type, data, namaProgram) {
  const overlay = document.getElementById('modalOverlay');
  const title  = document.getElementById('modalTitle');
  const body   = document.getElementById('modalBody');

  overlay.classList.add('show');

  if (type === 'tambah') {
    title.innerText = 'Tambah Program Baru';
    body.innerHTML = `
      <form id="formAksi" onsubmit="return submitForm('tambah')">
        <div class="form-group">
          <label>Nama Program</label>
          <input type="text" name="nama_program" required placeholder="Cth: Renang Pemula">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Level</label>
            <select name="level" required>
              <option value="">-- Pilih --</option>
              <option value="beginner">Beginner</option>
              <option value="intermediate">Intermediate</option>
              <option value="advanced">Advanced</option>
            </select>
          </div>
          <div class="form-group">
            <label>Tipe</label>
            <select name="tipe" required>
              <option value="">-- Pilih --</option>
              <option value="grup">Grup</option>
              <option value="private">Private</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Jumlah Sesi</label>
            <input type="number" name="jumlah_sesi" required min="1" placeholder="Cth: 12">
          </div>
          <div class="form-group">
            <label>Kapasitas</label>
            <input type="number" name="kapasitas" required min="1" placeholder="Cth: 10">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Harga (Rp)</label>
            <input type="number" name="harga" required min="0" placeholder="Cth: 500000">
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="status" required>
              <option value="aktif">Aktif</option>
              <option value="non_aktif">Non Aktif</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Deskripsi</label>
          <textarea name="deskripsi" placeholder="Deskripsi program..."></textarea>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeModal()">Batal</button>
          <button type="submit" class="btn-submit" id="btnSubmit">Simpan Program</button>
        </div>
      </form>
    `;
  }
  else if (type === 'edit') {
    title.innerText = 'Edit Program';
    body.innerHTML = `
      <form id="formAksi" onsubmit="return submitForm('edit', ${data.id})">
        <input type="hidden" name="id" value="${data.id}">
        <div class="form-group">
          <label>Nama Program</label>
          <input type="text" name="nama_program" value="${escapeHtml(data.nama_program || '')}" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Level</label>
            <select name="level" required>
              <option value="beginner" ${data.level === 'beginner' ? 'selected' : ''}>Beginner</option>
              <option value="intermediate" ${data.level === 'intermediate' ? 'selected' : ''}>Intermediate</option>
              <option value="advanced" ${data.level === 'advanced' ? 'selected' : ''}>Advanced</option>
            </select>
          </div>
          <div class="form-group">
            <label>Tipe</label>
            <select name="tipe" required>
              <option value="grup" ${data.tipe === 'grup' ? 'selected' : ''}>Grup</option>
              <option value="private" ${data.tipe === 'private' ? 'selected' : ''}>Private</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Jumlah Sesi</label>
            <input type="number" name="jumlah_sesi" value="${data.jumlah_sesi || 0}" required min="1">
          </div>
          <div class="form-group">
            <label>Kapasitas</label>
            <input type="number" name="kapasitas" value="${data.kapasitas || 0}" required min="1">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Harga (Rp)</label>
            <input type="number" name="harga" value="${data.harga || 0}" required min="0">
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="status" required>
              <option value="aktif" ${data.status === 'aktif' ? 'selected' : ''}>Aktif</option>
              <option value="non_aktif" ${data.status === 'non_aktif' ? 'selected' : ''}>Non Aktif</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Deskripsi</label>
          <textarea name="deskripsi">${escapeHtml(data.deskripsi || '')}</textarea>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeModal()">Batal</button>
          <button type="submit" class="btn-submit" id="btnSubmit">Simpan Perubahan</button>
        </div>
      </form>
    `;
  }
  else if (type === 'hapus') {
    title.innerText = 'Konfirmasi Hapus';
    body.innerHTML = `
      <div style="text-align:center;padding:20px 0">
        <div style="font-size:48px;margin-bottom:12px">🗑️</div>
        <p style="font-size:16px;color:#374151;margin-bottom:8px">Yakin ingin menghapus program:</p>
        <p style="font-size:18px;font-weight:700;color:#111827;margin-bottom:4px">${namaProgram}</p>
        <p style="font-size:13px;color:#9ca3af">Tindakan ini tidak dapat dibatalkan.</p>
      </div>
      <div class="modal-actions" style="justify-content:center">
        <button type="button" class="btn-cancel" onclick="closeModal()">Batal</button>
        <button class="btn-submit btn-danger" onclick="submitHapus(${data})">Ya, Hapus</button>
      </div>
    `;
  }
}

// ========= SUBMIT TAMBAH / EDIT via AJAX =========
function submitForm(type, id) {
  const form = document.getElementById('formAksi');
  const formData = new FormData(form);
  formData.append('aksi', type);
  if (type === 'edit' && id) formData.set('id', id);

  const btn = document.getElementById('btnSubmit');
  btn.disabled = true;
  btn.textContent = 'Menyimpan...';

  fetch('proses-program.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === 'ok') {
      closeModal();
      // Refresh otomatis
      location.reload();
    } else {
      alert('Gagal: ' + data.pesan);
      btn.disabled = false;
      btn.textContent = type === 'tambah' ? 'Simpan Program' : 'Simpan Perubahan';
    }
  })
  .catch(err => {
    alert('Terjadi kesalahan: ' + err.message);
    btn.disabled = false;
    btn.textContent = type === 'tambah' ? 'Simpan Program' : 'Simpan Perubahan';
  });

  return false;
}

// ========= SUBMIT HAPUS via AJAX =========
function submitHapus(id) {
  const btn = document.querySelector('.btn-danger');
  btn.disabled = true;
  btn.textContent = 'Menghapus...';

  const formData = new FormData();
  formData.append('aksi', 'hapus');
  formData.append('id', id);

  fetch('proses-program.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === 'ok') {
      closeModal();
      location.reload();
    } else {
      alert('Gagal: ' + data.pesan);
      btn.disabled = false;
      btn.textContent = 'Ya, Hapus';
    }
  })
  .catch(err => {
    alert('Terjadi kesalahan: ' + err.message);
    btn.disabled = false;
    btn.textContent = 'Ya, Hapus';
  });
}

// ========= TUTUP MODAL =========
function closeModal() {
  document.getElementById('modalOverlay').classList.remove('show');
}
document.getElementById('modalOverlay').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

// ========= FILTER =========
function filterTable() {
  const kat = document.getElementById('filterKategori').value.toLowerCase();
  const sts = document.getElementById('filterStatus').value.toLowerCase();
  const rows = document.querySelectorAll('#programList .grid-row');
  let visible = 0;
  rows.forEach(row => {
    const rKat = row.dataset.kategori.toLowerCase();
    const rSts = row.dataset.status.toLowerCase();
    const match = (!kat || rKat === kat) && (!sts || rSts === sts);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  const info = document.querySelector('.group-text-6-program');
  if (info) info.textContent = visible + ' program terdaftar';
  const pager = document.querySelector('.text-e.main-text5');
  if (pager) pager.textContent = 'Menampilkan ' + visible + ' dari ' + rows.length + ' program';
}

// ========= EXPORT CSV =========
function exportCSV() {
  let csv = "Program,Kategori,Harga Paket,Kuota Sesi,Kapasitas,Status\n";
  document.querySelectorAll('#programList .grid-row').forEach(row => {
    if (row.style.display === 'none') return;
    const nama  = row.querySelector('.prog-name')?.textContent?.trim() || '';
    const kat   = row.querySelector('.badge-cat')?.textContent?.trim() || '';
    const harga = row.querySelector('.col-harga')?.textContent?.trim() || '';
    const sesi  = row.querySelector('.col-sesi')?.textContent?.trim() || '';
    const kap   = row.querySelector('.cap-text')?.textContent?.trim() || '';
    const sts   = row.querySelector('.badge-status')?.textContent?.trim() || '';
    csv += `"${nama}","${kat}","${harga}","${sesi}","${kap}","${sts}"\n`;
  });
  const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = 'daftar_program.csv';
  link.click();
  URL.revokeObjectURL(link.href);
}

// ========= HELPER escape HTML =========
function escapeHtml(str) {
  return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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