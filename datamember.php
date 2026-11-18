<?php
require_once 'config/koneksi.php';
require_once 'config/helpers.php';

// ---------------------------------------------------------
// Helper tampilan
// ---------------------------------------------------------
function dm_sub($s, $start, $len)
{
    return function_exists('mb_substr') ? mb_substr($s, $start, $len) : substr($s, $start, $len);
}

function dm_upper($s)
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($s) : strtoupper($s);
}

// Inisial 2 huruf: "Dinda Nurul" -> DN, "Coach Raka" -> RA (kalau $buangCoach)
function dm_inisial($nama, $buangCoach = false)
{
    $nama = trim((string)$nama);
    if ($buangCoach) {
        $nama = trim(preg_replace('/^coach\s+/i', '', $nama));
    }
    if ($nama === '') {
        return '-';
    }
    $parts = preg_split('/\s+/', $nama);
    $a = dm_sub($parts[0], 0, 1);
    $b = count($parts) > 1 ? dm_sub($parts[1], 0, 1) : dm_sub($parts[0], 1, 1);
    return dm_upper($a . $b);
}

// Warna angka sisa kuota (class bawaan desain: bold-red / bold-orange / bold-blue)
function dm_kelas_sisa($sisa)
{
    if ($sisa <= 1) return 'bold-red';
    if ($sisa == 2) return 'bold-orange';
    return 'bold-blue';
}

// Daftar nomor halaman: 1 … 4 5 6 … 69
function dm_halaman($cur, $total)
{
    $total = max(1, $total);
    if ($total <= 7) {
        return range(1, $total);
    }
    $pages = [1];
    $start = max(2, $cur - 1);
    $end = min($total - 1, $cur + 1);
    if ($start > 2) $pages[] = '...';
    for ($i = $start; $i <= $end; $i++) $pages[] = $i;
    if ($end < $total - 1) $pages[] = '...';
    $pages[] = $total;
    return $pages;
}

// ---------------------------------------------------------
// Filter & pencarian
// ---------------------------------------------------------
$perPage = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$program_filter = isset($_GET['program']) ? trim($_GET['program']) : '';
$pelatih_filter = isset($_GET['pelatih']) ? trim($_GET['pelatih']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : 'semua';
if (!in_array($status_filter, ['semua', 'aktif', 'nonaktif'], true)) {
    $status_filter = 'semua';
}

$where = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (m.nama LIKE :search OR m.no_whatsapp LIKE :search2 OR m.kode_member LIKE :search3)";
    $params[':search'] = "%$search%";
    $params[':search2'] = "%$search%";
    $params[':search3'] = "%$search%";
}
if ($program_filter !== '') {
    $where .= " AND pm.program_id = :program_id";
    $params[':program_id'] = $program_filter;
}
if ($pelatih_filter !== '') {
    // pm.pelatih_id berisi pelatih.id (bukan users.id)
    $where .= " AND pm.pelatih_id = :pelatih_id";
    $params[':pelatih_id'] = $pelatih_filter;
}
if ($status_filter === 'aktif') {
    $where .= " AND m.status = 'aktif'";
} elseif ($status_filter === 'nonaktif') {
    $where .= " AND m.status = 'non_aktif'";
}

// Satu baris per member: pakai paket aktif terbaru (kalau tidak ada, paket terbaru)
$joinPaket = "LEFT JOIN paket_member pm ON pm.id = (
                  SELECT p2.id FROM paket_member p2
                   WHERE p2.member_id = m.id
                   ORDER BY (p2.status = 'aktif') DESC, p2.id DESC
                   LIMIT 1)";

// Total data (sesuai filter)
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM members m $joinPaket $where");
$stmtCount->execute($params);
$totalData = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalData / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Data member (nama pelatih: paket_member -> pelatih -> users)
$sql = "SELECT m.*, kp.nama_program, pm.id AS paket_id, pm.total_sesi, pm.sisa_sesi,
               pm.status AS paket_status, pm.pelatih_id, u.nama AS nama_pelatih
        FROM members m
        $joinPaket
        LEFT JOIN kelas_program kp ON kp.id = pm.program_id
        LEFT JOIN pelatih pl ON pl.id = pm.pelatih_id
        LEFT JOIN users u ON u.id = pl.user_id
        $where
        ORDER BY m.id DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Dropdown
$programs = ambil($pdo, "SELECT id, nama_program FROM kelas_program ORDER BY nama_program ASC");
// value = pelatih.id, label = users.nama
$pelatihList = ambil($pdo, "SELECT pl.id, u.nama
                            FROM pelatih pl
                            JOIN users u ON u.id = pl.user_id
                            WHERE pl.status = 'aktif'
                            ORDER BY u.nama ASC");

$progLabel = 'Semua Program';
foreach ($programs as $p) {
    if ((string)$p['id'] === $program_filter) $progLabel = $p['nama_program'];
}
$pelLabel = 'Semua Pelatih';
foreach ($pelatihList as $pl) {
    if ((string)$pl['id'] === $pelatih_filter) $pelLabel = $pl['nama'];
}

// ---------------------------------------------------------
// Kartu statistik (selalu seluruh member, tidak ikut filter)
// ---------------------------------------------------------
$st = ambil($pdo, "SELECT COUNT(*) AS total,
                          SUM(status = 'aktif') AS aktif,
                          SUM(status = 'non_aktif') AS nonaktif
                     FROM members");
$totalMember = (int)($st[0]['total'] ?? 0);
$memberAktif = (int)($st[0]['aktif'] ?? 0);
$memberNonaktif = (int)($st[0]['nonaktif'] ?? 0);
$persenAktif = $totalMember > 0 ? number_format($memberAktif / $totalMember * 100, 1, ',', '.') : '0';

$prev = ambil($pdo, "SELECT COUNT(*) AS jml FROM members
                      WHERE tanggal_daftar < DATE_FORMAT(CURDATE(), '%Y-%m-01')");
$prevMember = (int)($prev[0]['jml'] ?? 0);
$deltaTxt = null;
if ($prevMember > 0) {
    $pct = (int)round(($totalMember - $prevMember) / $prevMember * 100);
    $deltaTxt = ($pct >= 0 ? '+' : '') . $pct . '%';
}

$kuota = ambil($pdo, "SELECT COUNT(*) AS jml FROM paket_member
                       WHERE status = 'aktif' AND sisa_sesi <= 2");
$kuotaHampirHabis = (int)($kuota[0]['jml'] ?? 0);

$from = $totalData > 0 ? $offset + 1 : 0;
$to = min($offset + $perPage, $totalData);
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
            ]],
            ['Administrasi', [
                ['Transaksi', 'administrasi.php', 'card', ['administrasi-detail.php'], false],
                ['Laporan Keuangan', 'administrasi-laporan.php', 'chart', [], false],
                ['Gaji', 'gaji.php', 'wallet', [], false],
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
	<title>Data Member - Natix Swim</title>
	<link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="css/reset.css" />
	<link rel="stylesheet" href="css/global.css" />
	<link rel="stylesheet" href="css/cdm-data-member.css" />
	<style>
		/* ===== Tambahan fungsional (tidak ada di desain asli) ===== */

		/* Kolom pencarian asli diganti input sungguhan, tampilan tetap */
		.main-search input.main-text-cari-nama {
			border: 0; background: transparent; outline: 0;
			padding: 0; margin: 0; width: 100%; min-width: 0;
			font-family: inherit; color: #111827;
		}
		.main-search input.main-text-cari-nama::placeholder { color: #9CA3AF; opacity: 1; }

		/* Dropdown asli: <select> transparan di atasnya, teks & panah desain tetap terlihat */
		.dm-select-overlay {
			position: absolute; top: 0; left: 0; width: 100%; height: 100%;
			opacity: 0; cursor: pointer; z-index: 2; border: 0;
		}

		/* Ikon <object> menangkap klik, jadi biarkan klik tembus ke tombol di bawahnya */
		.act, .group-act1, .btn-btn-a, .main-btn-pill, .main-btn-pg { cursor: pointer; }
		.act object, .group-act1 object, .btn-btn-a object { pointer-events: none; }
		.main-btn-pg:disabled { cursor: default; opacity: 0.5; }

		/* Badge status Non-Aktif (di HTML asli kelasnya sama dengan Aktif) */
		.group-btn-badge2.dm-badge-off { background: #FEF2F2; color: #DC2626; }

		/* Modal tambah/edit */
		.dm-modal-overlay, .dm-confirm-overlay {
			display: none; position: fixed; top: 0; right: 0; bottom: 0; left: 0;
			background: rgba(0,0,0,0.5); z-index: 999;
			align-items: center; justify-content: center;
		}
		.dm-modal-overlay.active, .dm-confirm-overlay.active { display: flex; }
		.dm-modal {
			background: #fff; border-radius: 16px; width: 100%; max-width: 520px;
			max-height: 90vh; overflow-y: auto; font-family: 'Inter', sans-serif; color: #1F2937;
			box-shadow: 0 20px 60px rgba(0,0,0,0.15);
		}
		.dm-modal-header {
			display: flex; align-items: center; justify-content: space-between;
			padding: 20px 24px 16px; border-bottom: 1px solid #E5E7EB;
		}
		.dm-modal-title { font-size: 18px; font-weight: 600; color: #111827; }
		.dm-modal-close {
			width: 32px; height: 32px; border-radius: 8px; border: 1px solid #E5E7EB;
			background: #fff; font-size: 16px; color: #6B7280; cursor: pointer;
		}
		.dm-modal-close:hover { background: #F3F4F6; }
		.dm-modal-body { padding: 20px 24px; }
		.dm-form-group { margin-bottom: 16px; }
		.dm-form-group label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 6px; }
		.dm-required { color: #DC2626; }
		.dm-form-group input, .dm-form-group select {
			width: 100%; padding: 10px 14px; border: 1px solid #D1D5DB; border-radius: 8px;
			font-family: inherit; font-size: 14px; color: #1F2937; background: #fff; outline: 0;
		}
		.dm-form-group input:focus, .dm-form-group select:focus {
			border-color: #1D4ED8; box-shadow: 0 0 0 3px rgba(29,78,216,0.1);
		}
		.dm-modal-footer {
			display: flex; align-items: center; justify-content: flex-end; gap: 12px;
			padding: 16px 24px 20px; border-top: 1px solid #E5E7EB;
		}
		.dm-btn-cancel, .dm-btn-no {
			padding: 10px 20px; border: 1px solid #D1D5DB; border-radius: 10px;
			font-family: inherit; font-size: 14px; font-weight: 500; color: #374151; background: #fff; cursor: pointer;
		}
		.dm-btn-cancel:hover, .dm-btn-no:hover { background: #F3F4F6; }
		.dm-btn-save, .dm-btn-yes {
			padding: 10px 24px; border: 0; border-radius: 10px; font-family: inherit;
			font-size: 14px; font-weight: 600; color: #fff; cursor: pointer;
		}
		.dm-btn-save { background: #1D4ED8; }
		.dm-btn-save:hover { background: #1E40AF; }
		.dm-btn-yes { background: #DC2626; }
		.dm-btn-yes:hover { background: #B91C1C; }
		.dm-btn-save:disabled, .dm-btn-yes:disabled { opacity: 0.6; cursor: not-allowed; }

		/* Dialog hapus */
		.dm-confirm-box {
			background: #fff; border-radius: 16px; padding: 32px; max-width: 400px; width: 100%;
			text-align: center; font-family: 'Inter', sans-serif; box-shadow: 0 20px 60px rgba(0,0,0,0.15);
		}
		.dm-confirm-icon { font-size: 48px; margin-bottom: 16px; }
		.dm-confirm-box h3 { font-size: 18px; font-weight: 600; margin-bottom: 8px; color: #111827; }
		.dm-confirm-box p { font-size: 14px; color: #6B7280; margin-bottom: 24px; }
		.dm-confirm-actions { display: flex; gap: 12px; justify-content: center; }

		/* Toast */
		.dm-toast {
			position: fixed; top: 24px; right: 24px; z-index: 9999;
			padding: 14px 20px; border-radius: 10px; font-family: 'Inter', sans-serif;
			font-size: 14px; font-weight: 500; color: #fff;
			box-shadow: 0 4px 20px rgba(0,0,0,0.15); display: none;
		}
		.dm-toast.show { display: block; }
		.dm-toast.success { background: #059669; }
		.dm-toast.error { background: #DC2626; }
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

/* ===== Data Member: tampilan mengikuti desain Figma; tata letak mandiri (tidak bergantung posisi absolut CSS Figma) ===== */
.dmx, .dmx *, .dmx *::before, .dmx *::after { box-sizing: border-box; }
.dmx * { position: static !important; left: auto !important; top: auto !important; right: auto !important; bottom: auto !important; transform: none !important; float: none !important; width: auto !important; height: auto !important; min-width: 0 !important; min-height: 0 !important; max-width: none !important; margin: 0 !important; }
.dmx { padding: 28px 32px 48px !important; margin: 0 !important; width: 100% !important; max-width: none !important; position: relative !important; height: auto !important; font-family: 'Inter', sans-serif; color: #111827; }
.dmx * { background: none !important; border: 0 !important; border-radius: 0 !important; box-shadow: none !important; outline: 0 !important; padding: 0 !important; text-decoration: none; }
.dmx *::before, .dmx *::after { content: none !important; display: none !important; }
.dmx p, .dmx h2 { margin: 0 !important; }
.dmx .dmx-i { width: 18px !important; height: 18px !important; flex: 0 0 18px; display: block; }
.dmx button { font-family: inherit; }
.dmx .nv-crumb { margin: 0 0 8px !important; }

/* Kepala halaman */
.dmx .main-page-head { display: flex !important; align-items: center; justify-content: space-between; gap: 12px 16px; flex-wrap: wrap; }
.dmx .main-text-data-member { font-size: 30px !important; font-weight: 700 !important; line-height: 1.2 !important; color: #111827 !important; }
.dmx .main-text-kelola-seluruh { font-size: 14px !important; color: #6b7280 !important; margin-top: 4px !important; font-weight: 400 !important; line-height: 1.4 !important; }
.dmx .main-group4 { display: flex !important; gap: 12px; flex-wrap: wrap; }
.dmx .btn-btn-a { display: inline-flex !important; align-items: center; justify-content: center; gap: 8px; height: 40px !important; padding: 0 20px !important; border-radius: 10px !important; font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.dmx .btn-btn-a .btn-btn-label1 { font-size: 14px !important; font-weight: 600 !important; line-height: 1 !important; color: inherit !important; }
.dmx .btn-btn-secondary1 { background: #fff !important; border: 1px solid #e5e7eb !important; color: #111827 !important; }
.dmx .btn-btn-primary1 { background: #2563eb !important; border: 1px solid #2563eb !important; color: #fff !important; }
.dmx .btn-btn-primary1:hover { background: #1d4ed8 !important; }

/* Kartu statistik */
.dmx .main-stats { display: grid !important; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-top: 20px !important; }
.dmx .main-stats > div { background: #fff !important; border: 1px solid #eceef2 !important; border-radius: 16px !important; padding: 18px 20px !important; display: flex !important; flex-direction: column; gap: 10px; box-shadow: none !important; }
.dmx .main-stats > div > [class^="card-stat-top"] { display: flex !important; align-items: flex-start; justify-content: space-between; gap: 8px; }
.dmx .member-a { font-size: 13px !important; color: #4b5563 !important; font-weight: 400 !important; line-height: 1.3 !important; }
.dmx [class^="card-stat-stat-ic"] { width: 36px !important; height: 36px !important; flex: 0 0 36px; border-radius: 10px !important; background: #f3f4f6 !important; color: #9ca3af; display: flex !important; align-items: center; justify-content: center; }
.dmx [class^="card-stat-stat-ic"] .dmx-i { width: 16px !important; height: 16px !important; flex-basis: 16px; }
.dmx .stat-val { display: flex !important; align-items: center; gap: 10px; flex-wrap: wrap; }
.dmx .stat-val-subtitle, .dmx .subtitle-a { font-size: 34px !important; font-weight: 700 !important; line-height: 1.1 !important; color: #111827 !important; }
.dmx .stat-val-btn-delta { border: 0 !important; background: #dcfce7 !important; color: #15803d !important; font-size: 12px !important; font-weight: 600 !important; padding: 2px 9px !important; border-radius: 999px !important; line-height: 1.4 !important; cursor: default; }
.dmx .text-a { font-size: 13px !important; color: #6b7280 !important; line-height: 1.3 !important; margin-top: 2px !important; }

/* Kartu tabel */
.dmx .main-card { margin-top: 16px !important; background: #fff !important; border: 1px solid #eceef2 !important; border-radius: 16px !important; padding: 20px !important; }
.dmx .main-toolbar { display: flex !important; align-items: center; gap: 12px; flex-wrap: wrap; }
.dmx .main-search { display: flex !important; align-items: center; gap: 10px; flex: 1 1 260px; height: 40px !important; border: 1px solid #e5e7eb !important; border-radius: 10px !important; padding: 0 14px !important; background: #fff !important; color: #9ca3af; }
.dmx .main-search .main-text-cari-nama { flex: 1; font-size: 14px !important; color: #111827 !important; border: 0 !important; background: transparent !important; line-height: 1.4 !important; }
.dmx .select { position: relative !important; display: flex !important; align-items: center; justify-content: space-between; gap: 22px; flex: 0 0 auto; height: 40px !important; border: 1px solid #e5e7eb !important; border-radius: 10px !important; padding: 0 14px !important; background: #fff !important; cursor: pointer; }
.dmx .select-text { font-size: 14px !important; color: #374151 !important; white-space: nowrap; line-height: 1 !important; }
.dmx .select-text-symbol { font-size: 10px !important; color: #6b7280 !important; line-height: 1 !important; }
.dmx .dm-select-overlay { position: absolute !important; inset: 0 !important; width: 100% !important; height: 100% !important; opacity: 0; cursor: pointer; }
.dmx .main-pills { display: flex !important; gap: 8px; flex-wrap: wrap; }
.dmx .main-btn-pill { display: inline-flex !important; align-items: center; justify-content: center; height: 32px !important; padding: 0 22px !important; border-radius: 999px !important; border: 1px solid #e5e7eb !important; background: #fff !important; color: #374151 !important; font-size: 13px !important; font-weight: 500 !important; line-height: 1 !important; cursor: pointer; white-space: nowrap; }
.dmx .main-btn-pill.main-btn-pill1 { background: #111827 !important; border-color: #111827 !important; color: #fff !important; }

/* Tabel */
.dmx .main-group6 { margin-top: 14px !important; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.dmx .main-group7, .dmx .group-a, .dmx .main-group9 { display: grid !important; grid-template-columns: minmax(130px, 2fr) minmax(100px, 1.2fr) minmax(96px, 1.2fr) minmax(100px, 1.3fr) minmax(78px, .9fr) minmax(66px, .8fr) 96px; gap: 10px; align-items: center; min-width: 738px !important; padding: 0 12px !important; }
.dmx .main-group7 { padding-top: 12px !important; padding-bottom: 12px !important; border-bottom: 1px solid #eceef2 !important; }
.dmx .main-text-group-a { font-size: 11px !important; font-weight: 600 !important; letter-spacing: .05em; text-transform: uppercase; color: #6b7280 !important; line-height: 1.2 !important; white-space: nowrap; }
.dmx .main-group8 { display: block !important; min-width: 738px !important; }
.dmx .group-a, .dmx .main-group9 { padding-top: 10px !important; padding-bottom: 10px !important; border-bottom: 1px solid #eceef2 !important; background: transparent !important; }
.dmx .group-a:hover { background: #fafbfc !important; }
.dmx .group-b { display: flex !important; align-items: center; gap: 12px; min-width: 0 !important; }
.dmx .group-btn-av { width: 36px !important; height: 36px !important; flex: 0 0 36px; border-radius: 50% !important; border: 0 !important; background: #eff1f4 !important; color: #111827 !important; font-size: 13px !important; font-weight: 700 !important; display: flex !important; align-items: center; justify-content: center; padding: 0 !important; cursor: default; }
.dmx .group-group1 { min-width: 0 !important; }
.dmx .group-text1 { font-size: 14px !important; font-weight: 700 !important; color: #111827 !important; line-height: 1.3 !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dmx .group-text2 { font-size: 12px !important; color: #6b7280 !important; line-height: 1.3 !important; margin-top: 1px !important; }
.dmx .text-b { font-size: 13px !important; color: #1f2937 !important; line-height: 1.3 !important; white-space: nowrap; }
.dmx .group-text-quota1, .dmx .main-text-quota { color: #6b7280 !important; }
.dmx .group-text-quota1 b, .dmx .main-text-quota b { font-weight: 700 !important; color: #111827; }
.dmx .group-text-quota1 b.bold-red { color: #dc2626 !important; } .dmx .group-text-quota1 b.bold-orange { color: #ea580c !important; }
.dmx .group-c, .dmx .group-e { display: flex !important; align-items: center; min-width: 0 !important; }
.dmx .group-btn-badge1, .dmx .group-btn-badge2 { display: inline-flex !important; align-items: center; justify-content: center; height: 22px !important; border: 0 !important; border-radius: 999px !important; padding: 0 12px !important; font-size: 12px !important; line-height: 1 !important; white-space: nowrap; cursor: default; }
.dmx .group-btn-badge1 { background: #e8eeff !important; color: #3b5bdb !important; font-weight: 500 !important; }
.dmx .group-btn-badge2 { background: #dcfce7 !important; color: #15803d !important; font-weight: 600 !important; }
.dmx .group-btn-badge2.dm-badge-off { background: #f1f2f4 !important; color: #6b7280 !important; }
.dmx .group-d { display: flex !important; align-items: center; gap: 8px; min-width: 0 !important; }
.dmx .group-btn-av-sm1 { width: 26px !important; height: 26px !important; flex: 0 0 26px; border-radius: 50% !important; border: 0 !important; background: #eff1f4 !important; color: #111827 !important; font-size: 10px !important; font-weight: 700 !important; display: flex !important; align-items: center; justify-content: center; padding: 0 !important; cursor: default; }
.dmx .group-text4 { font-size: 13px !important; color: #1f2937 !important; line-height: 1.3 !important; white-space: nowrap; }
.dmx .group-group2, .dmx .main-group10 { display: flex !important; align-items: center; justify-content: flex-start; gap: 6px; }
.dmx .act, .dmx .group-act1 { width: 28px !important; height: 28px !important; flex: 0 0 28px; border: 1px solid #e5e7eb !important; border-radius: 8px !important; background: #fff !important; display: flex !important; align-items: center; justify-content: center; cursor: pointer; color: #6b7280; }
.dmx .act .dmx-i, .dmx .group-act1 .dmx-i { width: 16px !important; height: 16px !important; flex-basis: 16px; pointer-events: none; }
.dmx .act:hover, .dmx .group-act1:hover { background: #f3f4f6 !important; }
.dmx .act-view { color: #2563eb; } .dmx .act-del { color: #dc2626; border-color: #fecaca !important; }
.dmx .act-del:hover { background: #fef2f2 !important; }

/* Pager */
.dmx .main-pager { display: flex !important; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 16px !important; }
.dmx .main-text-menampilkan { font-size: 13px !important; color: #6b7280 !important; }
.dmx .main-pages { display: flex !important; gap: 8px; flex-wrap: wrap; }
.dmx .main-btn-pg { min-width: 30px !important; height: 30px !important; padding: 0 9px !important; border: 1px solid #e5e7eb !important; border-radius: 8px !important; background: #fff !important; color: #374151 !important; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
.dmx .main-btn-pg.main-btn-pg-on { background: #2563eb !important; border-color: #2563eb !important; color: #fff !important; }

@media (max-width: 1100px) { .dmx .main-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 1000px) { .dmx { padding: 20px 16px 40px !important; } }
@media (max-width: 640px) {
  .dmx .main-stats { grid-template-columns: 1fr; }
  .dmx .main-page-head, .dmx .main-group4 { width: 100%; }
  .dmx .btn-btn-a { flex: 1 1 auto; }
  .dmx .main-card { padding: 14px !important; }
  .dmx .main-search, .dmx .select { flex: 1 1 100%; }
  .dmx .stat-val-subtitle, .dmx .subtitle-a { font-size: 28px !important; }
}
</style>
</head>

<body>
	<?php nvSidebar(); ?>

	<div class="main">
		<header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div></header>

		<div class="main-content dmx">
			<?= nvCrumb() ?>
<div class="main-page-head">
				<div class="main-group3">
					<p class="main-text-data-member">Data Member</p>
					<p class="main-text-kelola-seluruh">
						Kelola seluruh data pelanggan dan siswa Natix Swim
					</p>
				</div>

				<div class="main-group4">
					<button class="btn-btn-a btn-btn-secondary1 hover-dark" onclick="exportCSV()">
						<svg class="dmx-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M5 20h14"/></svg>
						<p class="btn-btn-label1">Export</p>
					</button>

					<button class="btn-btn-a btn-btn-primary1 hover-bright" onclick="openAddModal()">
						<svg class="dmx-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
						<p class="btn-btn-label1">Tambah Member</p>
					</button>
				</div>
			</div>

			<div class="main-stats">
				<div class="card-stat1 card-white3">
					<div class="card-stat-top1">
						<p class="member-a">Total Member</p>

						<div class="card-stat-stat-ic1 card-light1">
							<svg class="dmx-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/></svg>
						</div>
					</div>

					<div class="stat-val stat-val1">
						<h2 class="stat-val-subtitle"><?= $totalMember ?></h2>
						<?php if ($deltaTxt !== null): ?>
						<button class="stat-val-btn-delta hover-dark"><?= e($deltaTxt) ?></button>
						<?php endif; ?>
					</div>

					<p class="text-a">Dibanding bulan lalu</p>
				</div>

				<div class="card-stat2 card-white3">
					<div class="card-stat-top2">
						<p class="member-a">Member Aktif</p>

						<div class="card-stat-stat-ic2 card-light1">
							<svg class="dmx-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
						</div>
					</div>

					<div class="stat-val stat-val2">
						<h2 class="stat-val-subtitle"><?= $memberAktif ?></h2>
					</div>

					<p class="text-a"><?= e($persenAktif) ?>% dari total member</p>
				</div>

				<div class="card-stat3 card-white3">
					<div class="card-stat-top3">
						<p class="member-a">Member Non-Aktif</p>

						<div class="card-stat-stat-ic3 card-light1"><svg class="dmx-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12h8"/></svg></div>
					</div>

					<h2 class="subtitle-a"><?= $memberNonaktif ?></h2>
					<p class="text-a">Tidak hadir &gt; 30 hari</p>
				</div>

				<div class="card-stat4 card-white3">
					<div class="card-stat-top4">
						<p class="member-a">Kuota Hampir Habis</p>

						<div class="card-stat-stat-ic4 card-light1">
							<svg class="dmx-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5l9 16h-18z"/><path d="M12 10v4.5M12 17.5v.01"/></svg>
						</div>
					</div>

					<h2 class="subtitle-a"><?= $kuotaHampirHabis ?></h2>
					<p class="text-a">Sisa kuota ≤ 2 sesi</p>
				</div>
			</div>

			<div class="main-card">
				<div class="main-toolbar">
					<div class="main-search">
						<svg class="dmx-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
						<input type="text" id="searchInput" class="main-text-cari-nama" placeholder="Cari nama, ID, atau nomor WhatsApp…" value="<?= e($search) ?>" autocomplete="off" />
					</div>

					<div class="select select1">
						<p class="select-text"><?= e($progLabel) ?></p>
						<p class="select-text-symbol">▼</p>
						<select id="programFilter" class="dm-select-overlay" onchange="applyFilter()" aria-label="Filter program">
							<option value="">Semua Program</option>
							<?php foreach ($programs as $p): ?>
							<option value="<?= (int)$p['id'] ?>" <?= $program_filter === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['nama_program']) ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="select select2">
						<p class="select-text"><?= e($pelLabel) ?></p>
						<p class="select-text-symbol">▼</p>
						<select id="pelatihFilter" class="dm-select-overlay" onchange="applyFilter()" aria-label="Filter pelatih">
							<option value="">Semua Pelatih</option>
							<?php foreach ($pelatihList as $pl): ?>
							<option value="<?= (int)$pl['id'] ?>" <?= $pelatih_filter === (string)$pl['id'] ? 'selected' : '' ?>><?= e($pl['nama']) ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="main-pills">
						<?php
						$pills = ['semua' => 'Semua', 'aktif' => 'Aktif', 'nonaktif' => 'Non-Aktif'];
						$pillOff = ['semua' => 'main-btn-pill2', 'aktif' => 'main-btn-pill2', 'nonaktif' => 'main-btn-pill3'];
						foreach ($pills as $key => $label):
							$kelasPill = ($status_filter === $key)
								? 'main-btn-pill main-btn-pill1 hover-bright'
								: 'main-btn-pill ' . $pillOff[$key] . ' hover-zoom';
						?>
						<button class="<?= $kelasPill ?>" onclick="setStatus('<?= $key ?>')"><?= $label ?></button>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="main-group6">
					<div class="main-group7">
						<p class="main-text-group-a main-text-group1">Member</p>
						<p class="main-text-group-a main-text-group2">Kontak</p>
						<p class="main-text-group-a main-text-group3">Program</p>
						<p class="main-text-group-a main-text-group4">Pelatih</p>
						<p class="main-text-group-a main-text-group5">Sisa Kuota</p>
						<p class="main-text-group-a main-text-group6">Status</p>
						<p class="main-text-group-a main-text-group7">Aksi</p>
					</div>

					<div class="main-group8">
						<?php if (count($members) === 0): ?>
						<p class="text-b" style="padding:40px;text-align:center;">Tidak ada data member ditemukan.</p>
						<?php endif; ?>

						<?php
						$jmlBaris = count($members);
						foreach ($members as $i => $m):
							// Kelas baris mengikuti HTML asli: baris pertama, tengah, dan terakhir
							$kelasBaris = ($i === 0) ? 'group18' : (($i === $jmlBaris - 1) ? 'group26' : 'group19');
							$idMember = (int)$m['id'];
							$punyaPaket = !empty($m['paket_id']);
							$sisa = (int)($m['sisa_sesi'] ?? 0);
							$aktif = ($m['status'] ?? 'aktif') === 'aktif';
						?>
						<div class="group-a <?= $kelasBaris ?>">
							<div class="group-b group3">
								<button class="group-btn-av btn-light1 hover-dark"><?= e(dm_inisial($m['nama'])) ?></button>

								<div class="group-group1">
									<p class="group-text1"><?= e($m['nama']) ?></p>
									<p class="group-text2"><?= e($m['kode_member'] ?? '-') ?></p>
								</div>
							</div>

							<p class="text-b group-text3"><?= e($m['no_whatsapp'] ?? '-') ?></p>

							<div class="group-c group4">
								<button class="group-btn-badge1 hover-dark btn3"><?= e($m['nama_program'] ?? '-') ?></button>
							</div>

							<div class="group-d group5">
								<?php if (!empty($m['nama_pelatih'])): ?>
								<button class="group-btn-av-sm1 hover-dark"><?= e(dm_inisial($m['nama_pelatih'], true)) ?></button>
								<p class="group-text4"><?= e($m['nama_pelatih']) ?></p>
								<?php else: ?>
								<p class="group-text4">-</p>
								<?php endif; ?>
							</div>

							<p class="text-b group-text-quota1">
								<?php if ($punyaPaket): ?>
								<b class="<?= dm_kelas_sisa($sisa) ?>"><?= $sisa ?></b> / <?= (int)($m['total_sesi'] ?? 0) ?> sesi
								<?php else: ?>
								-
								<?php endif; ?>
							</p>

							<div class="group-e group6">
								<button class="group-btn-badge2 btn3 hover-dark<?= $aktif ? '' : ' dm-badge-off' ?>"><?= $aktif ? 'Aktif' : 'Non-Aktif' ?></button>
							</div>

							<div class="group-group2">
								<div class="act act-view" title="Detail" onclick="location.href='cdm-detail-profil.php?id=<?= $idMember ?>'">
									<svg class="dmx-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
								</div>

								<div class="group-act1" title="Edit" onclick="openEditModal(<?= $idMember ?>)">
									<svg class="dmx-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9a2.8 2.8 0 00-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg>
								</div>

								<div class="act act-del" title="Hapus" onclick="confirmDelete(<?= $idMember ?>)">
									<svg class="dmx-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>
								</div>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="main-pager">
					<p class="main-text-menampilkan">Menampilkan <?= $from ?>–<?= $to ?> dari <?= $totalData ?> member</p>

					<div class="main-pages">
						<button class="main-btn-pg hover-zoom" onclick="goToPage(<?= max(1, $page - 1) ?>)" <?= $page <= 1 ? 'disabled' : '' ?>>‹</button>
						<?php foreach (dm_halaman($page, $totalPages) as $h): ?>
							<?php if ($h === '...'): ?>
							<button class="main-btn-pg hover-zoom" disabled>…</button>
							<?php else: ?>
							<button class="main-btn-pg <?= $h == $page ? 'main-btn-pg-on hover-bright' : 'hover-zoom' ?><?= $h >= 10 ? ' main-btn-pg5' : '' ?>" onclick="goToPage(<?= (int)$h ?>)"><?= (int)$h ?></button>
							<?php endif; ?>
						<?php endforeach; ?>
						<button class="main-btn-pg hover-zoom" onclick="goToPage(<?= min($totalPages, $page + 1) ?>)" <?= $page >= $totalPages ? 'disabled' : '' ?>>›</button>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Modal Tambah/Edit Member -->
	<div class="dm-modal-overlay" id="memberModal">
		<div class="dm-modal">
			<div class="dm-modal-header">
				<h3 class="dm-modal-title" id="modalTitle">Tambah Member</h3>
				<button class="dm-modal-close" type="button" onclick="closeModal()">✕</button>
			</div>
			<form id="memberForm" onsubmit="return false;">
				<input type="hidden" name="id" id="memberId" value="" />
				<div class="dm-modal-body">
					<div class="dm-form-group">
						<label>Nama Lengkap <span class="dm-required">*</span></label>
						<input type="text" name="nama" id="fieldNama" required placeholder="Masukkan nama lengkap" />
					</div>
					<div class="dm-form-group">
						<label>No. WhatsApp <span class="dm-required">*</span></label>
						<input type="text" name="no_whatsapp" id="fieldWA" required placeholder="08xxxxxxxxxx" />
					</div>
					<div class="dm-form-group">
						<label>Program <span class="dm-required">*</span></label>
						<select name="program_id" id="fieldProgram" required>
							<option value="">-- Pilih Program --</option>
							<?php foreach ($programs as $p): ?>
							<option value="<?= (int)$p['id'] ?>"><?= e($p['nama_program']) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="dm-form-group">
						<label>Total Sesi</label>
						<input type="number" name="total_sesi" id="fieldTotalSesi" min="1" value="8" placeholder="Jumlah sesi" />
					</div>
					<div class="dm-form-group">
						<label>Pelatih <span class="dm-required">*</span></label>
						<select name="pelatih_id" id="fieldPelatih" required>
							<option value="">-- Pilih Pelatih --</option>
							<?php foreach ($pelatihList as $pl): ?>
							<option value="<?= (int)$pl['id'] ?>"><?= e($pl['nama']) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div class="dm-modal-footer">
					<button type="button" class="dm-btn-cancel" onclick="closeModal()">Batal</button>
					<button type="submit" class="dm-btn-save" id="btnSave">Simpan</button>
				</div>
			</form>
		</div>
	</div>

	<!-- Konfirmasi Hapus -->
	<div class="dm-confirm-overlay" id="confirmOverlay">
		<div class="dm-confirm-box">
			<div class="dm-confirm-icon">🗑️</div>
			<h3>Hapus Member?</h3>
			<p>Data member dan paket terkait akan dihapus permanen.</p>
			<div class="dm-confirm-actions">
				<button class="dm-btn-no" type="button" onclick="closeConfirm()">Batal</button>
				<button class="dm-btn-yes" type="button" id="btnConfirmDelete">Ya, Hapus</button>
			</div>
		</div>
	</div>

	<div class="dm-toast" id="toast"></div>

	<script>
	const CURRENT_STATUS = <?= json_encode($status_filter) ?>;

	// Dropdown: <select> transparan harus menempel di kotak .select
	document.querySelectorAll('.select').forEach(function (el) {
		if (getComputedStyle(el).position === 'static') el.style.position = 'relative';
	});

	// === FILTER ===
	let searchTimeout;
	document.getElementById('searchInput').addEventListener('keyup', function (e) {
		clearTimeout(searchTimeout);
		if (e.key === 'Enter') { applyFilter(); return; }
		searchTimeout = setTimeout(applyFilter, 600);
	});

	function applyFilter() {
		const search = document.getElementById('searchInput').value.trim();
		const program = document.getElementById('programFilter').value;
		const pelatih = document.getElementById('pelatihFilter').value;
		const params = new URLSearchParams();
		if (search) params.set('search', search);
		if (program) params.set('program', program);
		if (pelatih) params.set('pelatih', pelatih);
		if (CURRENT_STATUS && CURRENT_STATUS !== 'semua') params.set('status', CURRENT_STATUS);
		window.location.href = 'datamember.php?' + params.toString();
	}

	function setStatus(status) {
		const params = new URLSearchParams(window.location.search);
		params.set('status', status);
		params.delete('page');
		window.location.href = 'datamember.php?' + params.toString();
	}

	function goToPage(page) {
		const params = new URLSearchParams(window.location.search);
		params.set('page', page);
		window.location.href = 'datamember.php?' + params.toString();
	}

	// === MODAL ===
	function openAddModal() {
		document.getElementById('modalTitle').textContent = 'Tambah Member';
		document.getElementById('memberForm').reset();
		document.getElementById('memberId').value = '';
		document.getElementById('btnSave').textContent = 'Simpan';
		document.getElementById('memberModal').classList.add('active');
	}

	function closeModal() {
		document.getElementById('memberModal').classList.remove('active');
	}

	function openEditModal(id) {
		document.getElementById('modalTitle').textContent = 'Edit Member';
		document.getElementById('btnSave').textContent = 'Update';

		fetch('proses-member.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=get&id=' + id
		})
		.then(res => res.json())
		.then(data => {
			if (data.success) {
				document.getElementById('memberId').value = data.data.id;
				document.getElementById('fieldNama').value = data.data.nama;
				document.getElementById('fieldWA').value = data.data.no_whatsapp;
				document.getElementById('fieldProgram').value = data.data.program_id || '';
				document.getElementById('fieldTotalSesi').value = data.data.total_sesi || 8;
				document.getElementById('fieldPelatih').value = data.data.pelatih_id || '';
				document.getElementById('memberModal').classList.add('active');
			} else {
				showToast('Gagal memuat data: ' + data.message, 'error');
			}
		})
		.catch(err => showToast('Error: ' + err.message, 'error'));
	}

	// === SIMPAN ===
	document.getElementById('memberForm').addEventListener('submit', function (e) {
		e.preventDefault();

		const id = document.getElementById('memberId').value;
		const action = id ? 'edit' : 'tambah';
		const btn = document.getElementById('btnSave');
		btn.disabled = true;
		btn.textContent = 'Menyimpan...';

		const formData = new FormData(this);
		formData.append('action', action);

		fetch('proses-member.php', { method: 'POST', body: formData })
		.then(res => res.json())
		.then(data => {
			if (data.success) {
				showToast(data.message || 'Berhasil disimpan!', 'success');
				closeModal();
				setTimeout(() => location.reload(), 800);
			} else {
				showToast('Gagal: ' + (data.message || 'Unknown error'), 'error');
				btn.disabled = false;
				btn.textContent = id ? 'Update' : 'Simpan';
			}
		})
		.catch(err => {
			showToast('Error: ' + err.message, 'error');
			btn.disabled = false;
			btn.textContent = id ? 'Update' : 'Simpan';
		});
	});

	// === HAPUS ===
	let deleteId = null;
	function confirmDelete(id) {
		deleteId = id;
		document.getElementById('confirmOverlay').classList.add('active');
	}
	function closeConfirm() {
		document.getElementById('confirmOverlay').classList.remove('active');
		deleteId = null;
	}

	document.getElementById('btnConfirmDelete').addEventListener('click', function () {
		if (!deleteId) return;
		const btn = this;
		btn.disabled = true;
		btn.textContent = 'Menghapus...';

		fetch('proses-member.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=hapus&id=' + deleteId
		})
		.then(res => res.json())
		.then(data => {
			if (data.success) {
				showToast(data.message || 'Berhasil dihapus!', 'success');
				closeConfirm();
				setTimeout(() => location.reload(), 800);
			} else {
				showToast('Gagal: ' + (data.message || 'Unknown error'), 'error');
				btn.disabled = false;
				btn.textContent = 'Ya, Hapus';
			}
		})
		.catch(err => {
			showToast('Error: ' + err.message, 'error');
			btn.disabled = false;
			btn.textContent = 'Ya, Hapus';
		});
	});

	// === TOAST ===
	function showToast(message, type) {
		const toast = document.getElementById('toast');
		toast.textContent = message;
		toast.className = 'dm-toast ' + (type || 'success') + ' show';
		setTimeout(function () { toast.classList.remove('show'); }, 3000);
	}

	// === EXPORT CSV ===
	function exportCSV() {
		const params = new URLSearchParams(window.location.search);
		params.set('export', 'csv');
		window.location.href = 'proses-member.php?' + params.toString();
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
        if (a.dataset.belum) { e.preventDefault(); toast('Halaman ' + a.dataset.belum + ' belum tersedia.'); return; }
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