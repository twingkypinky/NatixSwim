<?php
// ============================================================
// cdm-segmentasi.php — Segmentasi Member
// ============================================================

require 'config/koneksi.php';

// ─── QUERY DASAR ───
// PENTING: mulai dari tabel `members` (LEFT JOIN ke paket_member & kelas_program),
// BUKAN dari paket_member. Kalau mulai dari paket_member, member yang belum/tidak
// punya paket (biasanya member non-aktif) hilang total dari hasil query —
// itu sebabnya kolom "Program" & "Sisa Kuota" kelihatan kosong/data ilang.
// Subquery di bawah memilih 1 baris paket_member terbaik per member
// (yang aktif diprioritaskan, kalau tidak ada pakai yang terakhir).
function sgQuery(PDO $pdo, string $where, string $order): array
{
    $sql = "SELECT m.id, m.nama, m.status AS status_member,
                   kp.nama_program, pm.sisa_sesi, pm.total_sesi,
                   pm.tanggal_berakhir, pm.status AS status_paket,
                   lo.status AS status_pengingat
              FROM members m
              LEFT JOIN paket_member pm ON pm.id = (
                    SELECT p2.id FROM paket_member p2
                     WHERE p2.member_id = m.id
                     ORDER BY (p2.status = 'aktif') DESC, p2.id DESC
                     LIMIT 1)
              LEFT JOIN kelas_program kp ON kp.id = pm.program_id
              LEFT JOIN log_otomasi lo ON lo.id = (
                    SELECT l2.id FROM log_otomasi l2
                    JOIN otomasi_pesan o2 ON o2.id = l2.otomasi_id
                     WHERE l2.member_id = m.id AND o2.pemicu = 'kuota_rendah'
                     ORDER BY l2.waktu DESC
                     LIMIT 1)
              $where
              $order";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// ─── CEK AJAX REQUEST ───
$filter = $_GET['filter'] ?? '';

if ($filter && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');

    if ($filter === 'aktif') {
        // Semua member aktif (status member, bukan status paket — selaras dengan kartu di atas)
        $rows = sgQuery(
            $pdo,
            "WHERE m.status = 'aktif'",
            "ORDER BY (pm.sisa_sesi IS NULL) ASC, pm.sisa_sesi ASC, m.nama ASC"
        );
    } elseif ($filter === 'non_aktif') {
        $rows = sgQuery(
            $pdo,
            "WHERE m.status = 'non_aktif'",
            "ORDER BY m.nama ASC"
        );
    } else {
        $rows = sgQuery(
            $pdo,
            "WHERE pm.status = 'aktif' AND pm.sisa_sesi <= 2",
            "ORDER BY pm.sisa_sesi ASC, m.nama ASC"
        );
    }

    echo json_encode($rows);
    exit;
}

// ─── DATA UNTUK CARD ───
$totalMember = (int)$pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
$aktif       = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status = 'aktif'")->fetchColumn();
$nonAktif    = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status = 'non_aktif'")->fetchColumn();
$kuotaHabis  = (int)$pdo->query("
    SELECT COUNT(DISTINCT pm.member_id)
    FROM paket_member pm
    WHERE pm.status = 'aktif' AND pm.sisa_sesi <= 2
")->fetchColumn();

$persenAktif    = $totalMember > 0 ? round(($aktif / $totalMember) * 100, 1) : 0;
$persenNonAktif = round(100 - $persenAktif, 1);

// ─── DATA DEFAULT TABEL (kuota hampir habis) ───
$dataMember = sgQuery(
    $pdo,
    "WHERE pm.status = 'aktif' AND pm.sisa_sesi <= 2",
    "ORDER BY pm.sisa_sesi ASC, m.nama ASC"
);

// ─── FUNGSI BANTU ───
function inisial($nama) {
    $kata = explode(' ', trim((string)$nama));
    $i = '';
    foreach ($kata as $k) $i .= strtoupper($k[0] ?? '');
    return substr($i, 0, 2) ?: '--';
}

function warnaKuota($sisa) {
    if ($sisa === null) return '#9ca3af'; // abu-abu: belum/tidak punya paket
    return $sisa <= 1 ? '#dc2626' : '#f97316';
}

function tglIndo($tgl) {
    if (!$tgl) return '-';
    $bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime($tgl);
    return date('d', $t) . ' ' . $bln[(int)date('n', $t)] . ' ' . date('Y', $t);
}

// Kolom Program & Sisa Kuota, aman kalau member belum punya paket
function kolomProgram($m) {
    return $m['nama_program'] !== null ? htmlspecialchars($m['nama_program']) : '<span class="sg-muted">Belum ada paket</span>';
}

function kolomKuota($m) {
    if ($m['sisa_sesi'] === null) {
        return '<span class="sg-muted">-</span>';
    }
    return '<b style="color:' . warnaKuota($m['sisa_sesi']) . '">' . (int)$m['sisa_sesi'] . '</b> / ' . (int)$m['total_sesi'];
}

// Status pengingat otomasi "Kuota Hampir Habis" (dari tabel log_otomasi)
function pengingatInfo($status) {
    switch ($status) {
        case 'terkirim': return ['label' => 'Terkirim',  'warna' => '#16a34a', 'bg' => '#dcfce7'];
        case 'antri':    return ['label' => 'Terjadwal', 'warna' => '#d97706', 'bg' => '#fef3c7'];
        default:         return ['label' => 'Belum',     'warna' => '#6b7280', 'bg' => '#f3f4f6'];
    }
}

function kolomPengingat($m) {
    $info = pengingatInfo($m['status_pengingat'] ?? null);
    return '<span class="sg-chip" style="color:' . $info['warna'] . ';background:' . $info['bg'] . ';">' . $info['label'] . '</span>';
}
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/reset.css" />
    <link rel="stylesheet" href="css/global.css" />
    <link rel="stylesheet" href="css/cdm-segmentasi.css" />
    <style>
        /* ============================================================
           Halaman Segmentasi — semua class memakai prefix "sg-" supaya
           tidak bentrok dengan CSS hasil export Figma (cdm-segmentasi.css).
           ============================================================ */
        .main-content { position: relative !important; height: auto !important; width: 100% !important; box-sizing: border-box; }
        .sg-page { display: flex; flex-direction: column; gap: 20px; width: 100%; box-sizing: border-box; font-family: 'Inter', sans-serif; color: #111827; }
        .sg-page *, .sg-page *::before, .sg-page *::after { box-sizing: border-box; }
        .sg-page p, .sg-page h1, .sg-page h2 { margin: 0; }
        .sg-muted { color: #9ca3af; }
        .sg-ico { width: 18px; height: 18px; flex: 0 0 auto; pointer-events: none; position: static; }

        /* Header halaman */
        .sg-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .sg-title { font-size: 26px; font-weight: 700; line-height: 1.2; }
        .sg-subtitle { font-size: 14px; color: #6b7280; margin-top: 4px !important; }
        .sg-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; white-space: nowrap; font-family: inherit; font-size: 14px; font-weight: 600; border-radius: 10px; padding: 10px 18px; cursor: pointer; }
        .sg-btn-ghost { background: #fff; color: #111827; border: 1px solid #e5e7eb; }
        .sg-btn-ghost:hover { background: #f9fafb; }
        .sg-btn-primary { background: #2563eb; color: #fff; border: 1px solid #2563eb; }
        .sg-btn-primary:hover { background: #1d4ed8; }
        .sg-btn-primary .sg-ico { filter: brightness(0) invert(1); }

        /* Kartu statistik */
        .sg-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
        .sg-stat { background: #fff; border: 1px solid #eceef2; border-radius: 16px; padding: 20px 22px; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .sg-stat.is-alert { border: 2px solid #2563eb; padding: 19px 21px; }
        .sg-stat-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .sg-stat-title { font-size: 14px; font-weight: 600; white-space: nowrap; }
        .sg-badge { font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 999px; white-space: nowrap; }
        .sg-badge.green { background: #dcfce7; color: #16a34a; }
        .sg-badge.gray  { background: #f3f4f6; color: #6b7280; }
        .sg-badge.red   { background: #fee2e2; color: #dc2626; }
        .sg-stat-num { font-size: 34px; font-weight: 700; line-height: 1.1; margin-top: 4px !important; }
        .sg-stat-desc { font-size: 13px; color: #6b7280; line-height: 1.4; }
        .sg-rule { margin-top: 8px; background: #f6f7f9; border-radius: 10px; padding: 11px 14px; font-size: 13px; color: #4b5563; }

        /* Alert */
        .sg-alert { display: flex; align-items: center; gap: 12px; background: #fef3c7; border: 1px solid #fde68a; border-radius: 12px; padding: 14px 18px; }
        .sg-alert p { flex: 1; min-width: 200px; font-size: 14px; color: #92400e; line-height: 1.4; }
        .sg-alert .sg-ico { width: 20px; height: 20px; }

        /* Grid bawah */
        .sg-grid { display: grid; grid-template-columns: minmax(260px, 0.42fr) minmax(0, 1fr); gap: 16px; align-items: start; }
        .sg-card { background: #fff; border: 1px solid #eceef2; border-radius: 16px; padding: 24px; min-width: 0; }
        .sg-card-title { font-size: 17px; font-weight: 700; line-height: 1.3; }
        .sg-card-sub { font-size: 13px; color: #9ca3af; margin-top: 2px !important; }

        /* Donut + legenda */
        .sg-donut-row { display: flex; flex-wrap: wrap; align-items: center; gap: 20px; margin-top: 20px; }
        .sg-donut { width: clamp(120px, 46%, 170px); aspect-ratio: 1 / 1; border-radius: 50%; flex: 0 0 auto; display: flex; align-items: center; justify-content: center; }
        .sg-donut-hole { width: 68%; height: 68%; border-radius: 50%; background: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .sg-donut-num { font-size: 26px; font-weight: 700; line-height: 1.1; }
        .sg-donut-lbl { font-size: 12px; color: #6b7280; }
        .sg-legend { flex: 1 1 140px; min-width: 140px; display: flex; flex-direction: column; }
        .sg-leg-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; font-size: 14px; color: #374151; line-height: 1.25; }
        .sg-leg-row.sep { border-top: 1px solid #eef0f3; margin-top: 6px; padding-top: 14px; }
        .sg-dot { width: 9px; height: 9px; border-radius: 50%; flex: 0 0 auto; }
        .sg-leg-val { margin-left: auto; font-weight: 700; color: #111827; }
        .sg-leg-note { font-size: 12px; color: #9ca3af; line-height: 1.35; margin-top: 6px !important; }

        /* Tabel */
        .sg-table-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .sg-pills { display: flex; gap: 8px; flex-wrap: wrap; }
        .sg-pill { font-family: inherit; font-size: 13px; font-weight: 500; white-space: nowrap; padding: 8px 16px; border-radius: 999px; border: 1px solid #e5e7eb; background: #fff; color: #374151; cursor: pointer; }
        .sg-pill:hover { background: #f9fafb; }
        .sg-pill.is-active { background: #eff4ff; color: #2563eb; border-color: #2563eb; }
        .sg-table { overflow-x: auto; }
        .sg-tr { display: grid; grid-template-columns: minmax(180px, 2fr) minmax(120px, 1.6fr) minmax(90px, 1.1fr) minmax(110px, 1.3fr) minmax(100px, 1fr); gap: 12px; align-items: center; padding: 14px 8px; min-width: 620px; border-bottom: 1px solid #f1f2f5; }
        .sg-tr:last-child { border-bottom: 0; }
        .sg-tr.head { padding: 12px 8px; border-bottom: 1px solid #eceef2; }
        .sg-th { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #9ca3af; white-space: nowrap; }
        .sg-td { font-size: 14px; color: #1f2937; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
        .sg-member { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .sg-avatar { width: 36px; height: 36px; border-radius: 50%; background: #eff1f4; color: #1f2937; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .sg-name { font-size: 14px; font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sg-quota { color: #9ca3af; }
        .sg-quota b { font-weight: 700; }
        .sg-chip { display: inline-block; font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 999px; white-space: nowrap; }
        .sg-empty { padding: 28px 8px; text-align: center; color: #9ca3af; font-size: 14px; }

        @media (max-width: 1100px) {
            .sg-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 800px) {
            .sg-stats { grid-template-columns: 1fr; }
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

.main-content { padding: 28px 32px 48px !important; margin: 0 !important; max-width: none !important; left: auto !important; top: auto !important; transform: none !important; zoom: 1 !important; box-sizing: border-box; }
.sg-page { gap: 16px; }
.sg-title { font-size: 30px; }
.sg-stats { gap: 14px; }
.sg-stat { padding: 16px 18px; border-radius: 14px; gap: 4px; }
.sg-stat-title { font-size: 14px; }
.sg-stat-num { font-size: 28px; margin-top: 2px !important; }
.sg-stat-desc { font-size: 12.5px; }
.sg-rule { margin-top: 6px; padding: 9px 12px; font-size: 12.5px; }
.sg-alert { padding: 12px 16px; }
.sg-grid { gap: 14px; }
.sg-card { padding: 18px 20px; border-radius: 14px; }
.sg-card-title { font-size: 16px; }
.sg-donut-row { margin-top: 14px; gap: 16px; }
.sg-donut { width: clamp(110px, 40%, 140px); }
.sg-tr { padding: 11px 8px; }

@media (max-width: 1000px) { .main-content { padding: 20px 16px 40px !important; } .nv-crumb { margin-top: 4px; } }
@media (max-width: 800px) { .sg-alert { flex-wrap: wrap; } .sg-alert p { min-width: 0; flex-basis: 100%; } .sg-btn { white-space: normal; } .sg-table-head { align-items: flex-start; } }
</style>
</head>
<body>

    <!-- ─── SIDEBAR ─── -->
    <?php nvSidebar(); ?>

    <!-- ─── MAIN ─── -->
    <div class="main">
        <!-- Topbar -->
        <header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div></header>

        <div class="main-content">
            <div class="sg-page">

                <!-- Header -->
                <?= nvCrumb() ?>
<div class="sg-head">
                    <div>
                        <h1 class="sg-title">Segmentasi Member</h1>
                        <p class="sg-subtitle">Pengelompokan otomatis berdasarkan status kehadiran dan sisa kuota</p>
                    </div>
                    <button class="sg-btn sg-btn-ghost" onclick="exportCSV()">
                        <object data="assets/cdm-segmentasi/btn-btn/btn-btn-icon1.svg" class="sg-ico" type="image/svg+xml"></object>
                        Export
                    </button>
                </div>

                <!-- Kartu statistik -->
                <div class="sg-stats">
                    <div class="sg-stat">
                        <div class="sg-stat-top">
                            <p class="sg-stat-title">Member Aktif</p>
                            <span class="sg-badge green"><?= number_format($persenAktif, 1, ',', '.') ?>%</span>
                        </div>
                        <p class="sg-stat-num"><?= $aktif ?></p>
                        <p class="sg-stat-desc">Member dengan paket berjalan dan rutin hadir.</p>
                        <div class="sg-rule">Aturan: hadir ≤ 30 hari terakhir &amp; kuota &gt; 0</div>
                    </div>

                    <div class="sg-stat">
                        <div class="sg-stat-top">
                            <p class="sg-stat-title">Member Non-Aktif</p>
                            <span class="sg-badge gray"><?= number_format($persenNonAktif, 1, ',', '.') ?>%</span>
                        </div>
                        <p class="sg-stat-num"><?= $nonAktif ?></p>
                        <p class="sg-stat-desc">Member tanpa kehadiran lebih dari 30 hari atau kuota habis.</p>
                        <div class="sg-rule">Aturan: tidak hadir &gt; 30 hari / kuota = 0</div>
                    </div>

                    <div class="sg-stat is-alert">
                        <div class="sg-stat-top">
                            <p class="sg-stat-title">Kuota Hampir Habis</p>
                            <span class="sg-badge red">Perlu tindakan</span>
                        </div>
                        <p class="sg-stat-num"><?= $kuotaHabis ?></p>
                        <p class="sg-stat-desc">Member aktif yang perlu diingatkan untuk perpanjang paket.</p>
                        <div class="sg-rule">Aturan: sisa kuota ≤ 2 sesi</div>
                    </div>
                </div>

                <!-- Alert -->
                <div class="sg-alert">
                    <object data="assets/cdm-segmentasi/main-group4.svg" class="sg-ico" type="image/svg+xml"></object>
                    <p><b><?= $kuotaHabis ?> member</b> memiliki sisa kuota ≤ 2 sesi. AI Assistant dapat mengirim pengingat perpanjangan via WhatsApp.</p>
                    <button class="sg-btn sg-btn-primary" onclick="alert('Fitur AI reminder akan diintegrasikan')">
                        <object data="assets/cdm-segmentasi/btn-btn/btn-btn-icon2.svg" class="sg-ico" type="image/svg+xml"></object>
                        Kirim Pengingat via AI
                    </button>
                </div>

                <!-- Grid bawah -->
                <div class="sg-grid">

                    <!-- Komposisi Member -->
                    <div class="sg-card">
                        <p class="sg-card-title">Komposisi Member</p>
                        <p class="sg-card-sub">Total <?= $totalMember ?> member</p>

                        <div class="sg-donut-row">
                            <div class="sg-donut" style="background: conic-gradient(#3563e9 0% <?= $persenAktif ?>%, #d1d5db <?= $persenAktif ?>% 100%);">
                                <div class="sg-donut-hole">
                                    <p class="sg-donut-num"><?= $totalMember ?></p>
                                    <p class="sg-donut-lbl">Member</p>
                                </div>
                            </div>

                            <div class="sg-legend">
                                <div class="sg-leg-row">
                                    <span class="sg-dot" style="background:#3563e9"></span>
                                    <span>Aktif</span>
                                    <span class="sg-leg-val"><?= $aktif ?></span>
                                </div>
                                <div class="sg-leg-row">
                                    <span class="sg-dot" style="background:#d1d5db"></span>
                                    <span>Non-Aktif</span>
                                    <span class="sg-leg-val"><?= $nonAktif ?></span>
                                </div>
                                <div class="sg-leg-row sep">
                                    <span class="sg-dot" style="background:#dc2626"></span>
                                    <span>Kuota hampir habis</span>
                                    <span class="sg-leg-val"><?= $kuotaHabis ?></span>
                                </div>
                                <p class="sg-leg-note">*bagian dari member aktif</p>
                            </div>
                        </div>
                    </div>

                    <!-- Daftar Member -->
                    <div class="sg-card">
                        <div class="sg-table-head">
                            <div>
                                <p class="sg-card-title" id="table-title">Daftar Member – Kuota Hampir Habis</p>
                                <p class="sg-card-sub" id="table-subtitle">Diurutkan dari sisa kuota hampir habis</p>
                            </div>
                            <div class="sg-pills">
                                <button class="sg-pill" onclick="loadData('aktif', this)">Aktif</button>
                                <button class="sg-pill" onclick="loadData('non_aktif', this)">Non-Aktif</button>
                                <button class="sg-pill is-active" onclick="loadData('kuota_hampir_habis', this)">Kuota Hampir Habis</button>
                            </div>
                        </div>

                        <div class="sg-table">
                            <div class="sg-tr head">
                                <p class="sg-th">Member</p>
                                <p class="sg-th">Program</p>
                                <p class="sg-th">Sisa Kuota</p>
                                <p class="sg-th">Berlaku s.d.</p>
                                <p class="sg-th">Pengingat</p>
                            </div>
                            <div id="table-body">
                                <?php if (empty($dataMember)): ?>
                                    <div class="sg-empty">Tidak ada member dengan kuota hampir habis.</div>
                                <?php else: ?>
                                    <?php foreach ($dataMember as $m): ?>
                                        <div class="sg-tr">
                                            <div class="sg-member">
                                                <span class="sg-avatar"><?= htmlspecialchars(inisial($m['nama'])) ?></span>
                                                <span class="sg-name"><?= htmlspecialchars($m['nama']) ?></span>
                                            </div>
                                            <p class="sg-td sg-prog"><?= kolomProgram($m) ?></p>
                                            <p class="sg-td sg-quota"><?= kolomKuota($m) ?></p>
                                            <p class="sg-td sg-date"><?= htmlspecialchars(tglIndo($m['tanggal_berakhir'])) ?></p>
                                            <div><?= kolomPengingat($m) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

<script>
// ─── LOAD DATA VIA AJAX ───
function loadData(filter, btn) {
    document.querySelectorAll('.sg-pills .sg-pill').forEach(b => b.classList.remove('is-active'));
    btn.classList.add('is-active');

    const titles = {
        kuota_hampir_habis: 'Daftar Member – Kuota Hampir Habis',
        aktif: 'Daftar Member – Aktif',
        non_aktif: 'Daftar Member – Non-Aktif'
    };
    const labels = {
        kuota_hampir_habis: 'Diurutkan dari sisa kuota hampir habis',
        aktif: 'Menampilkan semua member aktif',
        non_aktif: 'Menampilkan semua member non-aktif'
    };
    document.getElementById('table-title').textContent = titles[filter] || 'Daftar Member';
    document.getElementById('table-subtitle').textContent = labels[filter] || '';

    fetch('cdm-segmentasi.php?filter=' + filter, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        const tbody = document.getElementById('table-body');
        if (!data.length) {
            tbody.innerHTML = '<div class="sg-empty">Tidak ada data untuk filter ini.</div>';
            return;
        }
        const bulan = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
        const pengingatMap = {
            terkirim: { label: 'Terkirim',  warna: '#16a34a', bg: '#dcfce7' },
            antri:    { label: 'Terjadwal', warna: '#d97706', bg: '#fef3c7' }
        };
        let html = '';
        data.forEach(m => {
            const kata = (m.nama || '').trim().split(' ');
            let inisial = '';
            kata.forEach(k => inisial += (k[0] || '').toUpperCase());
            inisial = inisial.slice(0, 2) || '--';

            const programHtml = m.nama_program
                ? escapeHtml(m.nama_program)
                : '<span class="sg-muted">Belum ada paket</span>';

            let kuotaHtml;
            if (m.sisa_sesi === null || m.sisa_sesi === undefined) {
                kuotaHtml = '<span class="sg-muted">-</span>';
            } else {
                const warna = parseInt(m.sisa_sesi) <= 1 ? '#dc2626' : '#f97316';
                kuotaHtml = '<b style="color:' + warna + '">' + m.sisa_sesi + '</b> / ' + m.total_sesi;
            }

            let tgl = '-';
            if (m.tanggal_berakhir) {
                const p = String(m.tanggal_berakhir).split('-');
                tgl = p[2] + ' ' + bulan[parseInt(p[1], 10) - 1] + ' ' + p[0];
            }

            const pg = pengingatMap[m.status_pengingat] || { label: 'Belum', warna: '#6b7280', bg: '#f3f4f6' };

            html += '<div class="sg-tr">'
                + '<div class="sg-member"><span class="sg-avatar">' + inisial + '</span>'
                + '<span class="sg-name">' + escapeHtml(m.nama) + '</span></div>'
                + '<p class="sg-td sg-prog">' + programHtml + '</p>'
                + '<p class="sg-td sg-quota">' + kuotaHtml + '</p>'
                + '<p class="sg-td sg-date">' + tgl + '</p>'
                + '<div><span class="sg-chip" style="color:' + pg.warna + ';background:' + pg.bg + ';">' + pg.label + '</span></div>'
                + '</div>';
        });
        tbody.innerHTML = html;
    })
    .catch(() => {
        document.getElementById('table-body').innerHTML =
            '<div class="sg-empty" style="color:#dc2626;">Gagal memuat data. Coba refresh.</div>';
    });
}

function escapeHtml(text) {
    const d = document.createElement('div');
    d.textContent = text == null ? '' : text;
    return d.innerHTML;
}

// ─── EXPORT CSV ───
function exportCSV() {
    const rows = document.querySelectorAll('#table-body .sg-tr');
    if (!rows.length) { alert('Tidak ada data untuk diexport.'); return; }

    const esc = t => {
        t = (t || '').trim();
        return (t.includes(',') || t.includes('"')) ? '"' + t.replace(/"/g, '""') + '"' : t;
    };
    const txt = (row, sel) => { const e = row.querySelector(sel); return e ? e.textContent.trim() : ''; };

    let csv = '﻿';
    csv += 'Member,Program,Sisa Kuota,Total Kuota,Berlaku s.d.,Pengingat\n';

    rows.forEach(row => {
        const parts = txt(row, '.sg-quota').split('/').map(x => x.trim());
        const sisa  = parts[0] && parts[0] !== '-' ? parts[0] : '';
        const total = parts[1] || '';
        csv += [
            esc(txt(row, '.sg-name')),
            esc(txt(row, '.sg-prog')),
            esc(sisa), esc(total),
            esc(txt(row, '.sg-date')),
            esc(txt(row, '.sg-chip'))
        ].join(',') + '\n';
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'segmentasi-member.csv';
    link.click();
    URL.revokeObjectURL(link.href);
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