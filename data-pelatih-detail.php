<?php
// =====================================================
//  FILE: data-pelatih-detail.php
//  DESKRIPSI: Halaman detail pelatih dengan 4 tab
//  (Ringkasan, Kelas Diampu, Riwayat Kehadiran, Honor)
//  + Modal Edit Profil dengan konfirmasi & notifikasi
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/koneksi.php';

$id_pelatih = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id_pelatih <= 0) {
    header('Location: data-pelatih-daftar.php');
    exit;
}

$activeTab = $_GET['tab'] ?? 'ringkasan';
$daftarTab = ['ringkasan', 'kelas_diampu', 'riwayat_kehadiran', 'honor'];
if (!in_array($activeTab, $daftarTab)) $activeTab = 'ringkasan';

// Cek status edit
$editStatus = $_GET['edit'] ?? '';
$editMsg    = $_GET['msg'] ?? '';

// =====================================================
//  FUNGSI PEMBANTU
// =====================================================
function getInisial($nama) {
    $parts = explode(' ', trim($nama));
    $inisial = '';
    foreach ($parts as $p) if (!empty($p)) $inisial .= strtoupper($p[0]);
    return substr($inisial, 0, 2);
}

function tglIndo($date) {
    $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime($date);
    return date('d', $t) . ' ' . $bulan[(int)date('m', $t)-1] . ' ' . date('Y', $t);
}

// =====================================================
//  AMBIL DATA PELATIH UTAMA
// =====================================================
$pelatih = null;
try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.nama, u.email, u.no_hp
        FROM pelatih p
        JOIN users u ON u.id = p.user_id
        WHERE p.id = :id
    ");
    $stmt->execute([':id' => $id_pelatih]);
    $pelatih = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die('Error database: ' . $e->getMessage());
}

if (!$pelatih) {
    header('Location: data-pelatih-daftar.php');
    exit;
}

$inisial      = getInisial($pelatih['nama']);
$nama         = $pelatih['nama'];
$no_hp        = $pelatih['no_hp'] ?? '-';
$email        = $pelatih['email'] ?? '-';
$gender       = ($pelatih['jenis_kelamin'] ?? 'Laki-laki') == 'Laki-laki' ? 'Laki-laki' : 'Perempuan';
$pengalaman   = ($pelatih['pengalaman_tahun'] ?? 0) . ' Tahun';
$status       = ucfirst($pelatih['status'] ?? 'aktif');
$mapHonor     = ['per_sesi' => 'Per Sesi', 'bulanan' => 'Bulanan', 'persentase' => 'Persentase'];
$sistemHonor  = $mapHonor[$pelatih['sistem_honor']] ?? 'Per Sesi';
$tglGabung    = date('M Y', strtotime($pelatih['bergabung'] ?? 'now'));

// =====================================================
//  STATISTIK RINGKASAN
// =====================================================
$kelasAktif   = 0; $memberDiajar = 0; $persenHadir = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM jadwal WHERE pelatih_id = :id");
    $s->execute([':id' => $id_pelatih]); $kelasAktif = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT COUNT(DISTINCT jp.member_id) FROM jadwal_peserta jp JOIN jadwal j ON j.id = jp.jadwal_id WHERE j.pelatih_id = :id");
    $s->execute([':id' => $id_pelatih]); $memberDiajar = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT COUNT(CASE WHEN pr.status = 'hadir' THEN 1 END) as hadir, COUNT(*) as total FROM presensi pr JOIN jadwal j ON j.id = pr.jadwal_id WHERE j.pelatih_id = :id");
    $s->execute([':id' => $id_pelatih]); $d = $s->fetch(PDO::FETCH_ASSOC);
    $persenHadir = $d['total'] > 0 ? round(($d['hadir'] / $d['total']) * 100) : 0;
} catch (Exception $e) {}

// =====================================================
//  DATA SPESIALISASI
// =====================================================
$spesialisasiList = [];
try {
    $stmt = $pdo->prepare("SELECT nama_spesialisasi FROM pelatih_spesialisasi WHERE pelatih_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $id_pelatih]);
    $spesialisasiList = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
if (empty($spesialisasiList) && !empty($pelatih['spesialisasi'])) $spesialisasiList = [$pelatih['spesialisasi']];
$spesialClass = ['card-bg-plus-border-btn-bg4','card-bg-plus-border-btn-bg5','card-bg-plus-border-btn-bg6','card-bg-plus-border-btn-bg7'];

// =====================================================
//  DATA HARI MENGAJAR
// =====================================================
$hariMengajarList = [];
try {
    $stmt = $pdo->prepare("SELECT hari FROM pelatih_hari_mengajar WHERE pelatih_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $id_pelatih]); $hariMengajarList = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
if (empty($hariMengajarList)) $hariMengajarList = ['Senin','Rabu','Jumat','Sabtu','Minggu'];
$hariClass = ['card-bg-plus-border-btn-bg8','card-bg-plus-border-btn-bg9','card-bg-plus-border-btn-bg10','card-bg-plus-border-btn-bg11','card-bg-plus-border-btn-bg12'];

// =====================================================
//  DATA SERTIFIKASI
// =====================================================
$sertifikasiList = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM pelatih_sertifikasi WHERE pelatih_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $id_pelatih]); $sertifikasiList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// =====================================================
//  DATA PROGRES SISWA
// =====================================================
$progresList = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM progres_siswa_level WHERE pelatih_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $id_pelatih]); $progresList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
if (empty($progresList)) {
    $progresList = [
        ['level_dari' => 'Pemula', 'level_ke' => 'Dasar', 'jumlah_siswa' => 18],
        ['level_dari' => 'Dasar', 'level_ke' => 'Menengah', 'jumlah_siswa' => 14],
        ['level_dari' => 'Menengah', 'level_ke' => 'Mahir', 'jumlah_siswa' => 9],
        ['level_dari' => 'Siap Kompetisi', 'level_ke' => 'Siap Kompetisi', 'jumlah_siswa' => 7],
    ];
}

// =====================================================
//  DATA ULASAN
// =====================================================
$ulasanList = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM ulasan_pelatih WHERE pelatih_id = :id ORDER BY created_at DESC LIMIT 2");
    $stmt->execute([':id' => $id_pelatih]); $ulasanList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
if (empty($ulasanList)) {
    $ulasanList = [
        ['nama_orangtua' => 'Ibu Sari', 'inisial' => 'IS', 'rating' => 5, 'ulasan' => 'Coach sabar banget, anak saya yang tadinya takut air sekarang sudah berani gaya bebas 10 meter.'],
        ['nama_orangtua' => 'Pak Hendra', 'inisial' => 'PH', 'rating' => 5, 'ulasan' => 'Laporan evaluasinya rutin dan jelas, jadi kami tahu perkembangan anak tiap bulan.'],
    ];
}

// Gabung sertifikasi jadi string untuk modal
$sertifikasiString = '';
foreach ($sertifikasiList as $s) {
    $sertifikasiString .= $s['nama_sertifikasi'] ?? '';
    if (!empty($s['berlaku_sampai'])) $sertifikasiString .= ' (' . date('Y', strtotime($s['berlaku_sampai'])) . ')';
    $sertifikasiString .= "\n";
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@100;300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/reset.css" />
    <link rel="stylesheet" href="css/global.css" />
    <link rel="stylesheet" href="css/data-pelatih-detail.css" />
    <style>
        .main-horizontal { display:flex; border-bottom:2px solid #e5e7eb; gap:0; margin-bottom:20px; }
        .main-horizontal a { position:relative; padding:12px 20px; text-decoration:none; color:#6b7280 !important; font-weight:500; font-size:14px; transition:all 0.2s ease; }
        .main-horizontal a p { color:#6b7280 !important; }
        .main-horizontal a.tab-active p { color:#2563eb !important; font-weight:600 !important; }
        .main-horizontal a.tab-active::after { content:''; position:absolute; bottom:-2px; left:0; width:100%; height:3px; background:#2563eb; border-radius:3px 3px 0 0; }
        .main-horizontal a:hover p { color:#374151 !important; }
        .content-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:16px; }
        .content-card h3 { font-size:16px; font-weight:600; color:#1a1a2e; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #f3f4f6; }
        .tabel-presensi { width:100%; border-collapse:collapse; }
        .tabel-presensi th { text-align:left; padding:10px 12px; font-size:12px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.5px; background:#f9fafb; border-bottom:2px solid #e5e7eb; }
        .tabel-presensi td { padding:10px 12px; font-size:13px; color:#374151; border-bottom:1px solid #f3f4f6; }
        .tabel-presensi tr:hover td { background:#f9fafb; }
        .badge-status { display:inline-block; padding:2px 12px; border-radius:6px; font-weight:600; font-size:12px; }
        .badge-hadir  { background:#d1fae5; color:#059669; }
        .badge-izin   { background:#fef3c7; color:#d97706; }
        .badge-sakit  { background:#ffedd5; color:#ea580c; }
        .badge-alpa   { background:#fee2e2; color:#dc2626; }
        .stat-row { display:flex; gap:12px; margin-bottom:20px; }
        .stat-box { flex:1; padding:16px; border-radius:12px; text-align:center; }
        .stat-box .stat-label { font-size:11px; color:#6b7280; margin-bottom:4px; }
        .stat-box .stat-value { font-size:22px; font-weight:700; }
        .honor-grid { display:flex; gap:12px; margin-bottom:20px; }
        .honor-box { flex:1; padding:16px; border-radius:12px; background:#f9fafb; border:1px solid #e5e7eb; }
        .honor-box .h-label { font-size:11px; color:#6b7280; margin-bottom:4px; }
        .honor-box .h-value { font-size:18px; font-weight:700; color:#1a1a2e; }
        .honor-detail-row { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #f3f4f6; font-size:13px; }
        .honor-detail-row:last-child { border-bottom:none; }
        .honor-detail-row .label { color:#374151; }
        .honor-detail-row .value { font-weight:600; }
        .honor-total { display:flex; justify-content:space-between; padding:14px 0 0; margin-top:8px; border-top:2px solid #2563eb; font-size:15px; }
        .honor-total .label { font-weight:600; color:#1a1a2e; }
        .honor-total .value { font-weight:700; color:#2563eb; }
        .kelas-wrapper { width:100%; }
        .kelas-stats { display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
        .kelas-stat-box { flex:1; min-width:120px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 20px; display:flex; align-items:center; gap:14px; transition:box-shadow 0.2s; }
        .kelas-stat-box:hover { box-shadow:0 2px 8px rgba(0,0,0,0.06); }
        .kelas-stat-icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
        .kelas-stat-info .stat-num { font-size:20px; font-weight:700; color:#1a1a2e; line-height:1.2; }
        .kelas-stat-info .stat-lbl { font-size:12px; color:#6b7280; }
        .hari-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; margin-bottom:14px; overflow:hidden; }
        .hari-card-header { display:flex; align-items:center; gap:10px; padding:14px 20px; background:#f9fafb; border-bottom:1px solid #e5e7eb; }
        .hari-badge { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
        .hari-card-header .hari-nama { font-size:14px; font-weight:600; color:#1a1a2e; }
        .hari-card-header .hari-jumlah { font-size:12px; color:#6b7280; margin-left:auto; background:#f3f4f6; padding:2px 10px; border-radius:10px; }
        .kelas-item { display:flex; align-items:center; padding:14px 20px; border-bottom:1px solid #f3f4f6; transition:background 0.15s; gap:16px; }
        .kelas-item:last-child { border-bottom:none; }
        .kelas-item:hover { background:#fafbff; }
        .kelas-time { display:flex; flex-direction:column; align-items:center; min-width:70px; flex-shrink:0; }
        .kelas-time .jam { font-size:14px; font-weight:600; color:#1a1a2e; }
        .kelas-time .separator { font-size:10px; color:#9ca3af; line-height:1; }
        .kelas-time .jam-end { font-size:13px; font-weight:500; color:#6b7280; }
        .kelas-divider { width:1px; height:36px; background:#e5e7eb; flex-shrink:0; }
        .kelas-info { flex:1; min-width:0; }
        .kelas-info .program-name { font-size:14px; font-weight:600; color:#1a1a2e; margin-bottom:2px; }
        .kelas-info .program-detail { font-size:12px; color:#6b7280; }
        .kelas-status-badge { padding:4px 14px; border-radius:8px; font-size:12px; font-weight:600; flex-shrink:0; }
        .kelas-status-badge.akan_datang { background:#fef3c7; color:#d97706; }
        .kelas-status-badge.berlangsung { background:#d1fae5; color:#059669; }
        .kelas-status-badge.selesai { background:#e5e7eb; color:#6b7280; }
        .kelas-status-badge.dibatalkan { background:#fee2e2; color:#dc2626; }
        .kelas-empty { text-align:center; padding:60px 20px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; }
        .kelas-empty .empty-icon { font-size:48px; margin-bottom:12px; }
        .kelas-empty .empty-title { font-size:16px; font-weight:600; color:#1a1a2e; margin-bottom:6px; }
        .kelas-empty .empty-desc { font-size:13px; color:#6b7280; }

        /* ===== STAT CARDS DIKECILKAN ===== */
        .stat-card-modern {
            flex: 1;
            border-radius: 12px;
            padding: 14px 16px !important;
            display: flex;
            align-items: center;
            gap: 12px !important;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .stat-card-modern .stat-icon {
            width: 42px !important;
            height: 42px !important;
            border-radius: 10px !important;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px !important;
            flex-shrink: 0;
            color: #fff;
        }
        .stat-card-modern .stat-content .stat-label {
            font-size: 10px !important;
            font-weight: 700;
            letter-spacing: 0.8px;
            margin-bottom: 2px;
        }
        .stat-card-modern .stat-content .stat-value {
            font-size: 22px !important;
            font-weight: 800;
            line-height: 1.1;
        }

        /* ===== TEXT DESKRIPSI 1 BARIS ===== */
        .container-text10 {
            white-space: nowrap !important;
        }

        /* ===== TOMBOL TEXT PANJANG ===== */
        .btn-bg-plus-border2,
        .btn-background-d {
            white-space: nowrap !important;
        }
        .btn-bg-plus-border2 p,
        .btn-background-d p {
            white-space: nowrap !important;
        }

        /* Container sampingan untuk Sertifikasi & Progres */
        .ringkasan-grid {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
        }
        .ringkasan-grid .content-card {
            flex: 1;
            margin-bottom: 0;
        }

        /* ===== MODAL EDIT PROFIL ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.45);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 16px;
            width: 560px;
            max-width: 94vw;
            max-height: 88vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.18);
            animation: modalIn 0.25s ease;
        }
        @keyframes modalIn {
            from { opacity:0; transform:scale(0.95) translateY(10px); }
            to   { opacity:1; transform:scale(1) translateY(0); }
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px 14px;
            border-bottom: 1px solid #e5e7eb;
        }
        .modal-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: #1a1a2e;
            margin: 0;
        }
        .modal-close {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: #f3f4f6;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            transition: background 0.15s;
        }
        .modal-close:hover { background: #e5e7eb; }
        .modal-body { padding: 20px 24px 24px; }
        .modal-field { margin-bottom: 16px; }
        .modal-field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        .modal-field input,
        .modal-field select,
        .modal-field textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            color: #1a1a2e;
            background: #fff;
            box-sizing: border-box;
            transition: border-color 0.15s;
        }
        .modal-field input:focus,
        .modal-field select:focus,
        .modal-field textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }
        .modal-field textarea {
            min-height: 80px;
            resize: vertical;
        }
        .modal-field-row {
            display: flex;
            gap: 14px;
        }
        .modal-field-row .modal-field { flex: 1; }
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 8px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
        }
        .modal-actions button {
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
            font-family: 'Inter', sans-serif;
        }
        .modal-btn-batal {
            background: #f3f4f6;
            color: #374151;
        }
        .modal-btn-batal:hover { background: #e5e7eb; }
        .modal-btn-simpan {
            background: #2563eb;
            color: #fff;
        }
        .modal-btn-simpan:hover { background: #1d4ed8; }

        /* ===== POPUP KONFIRMASI ===== */
        .confirm-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.50);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        .confirm-overlay.active { display: flex; }
        .confirm-box {
            background: #fff;
            border-radius: 16px;
            width: 400px;
            max-width: 88vw;
            padding: 30px 28px 22px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            animation: modalIn 0.25s ease;
        }
        .confirm-box .confirm-icon { font-size: 48px; margin-bottom: 12px; }
        .confirm-box .confirm-title { font-size: 18px; font-weight: 700; color: #1a1a2e; margin-bottom: 8px; }
        .confirm-box .confirm-desc { font-size: 14px; color: #6b7280; margin-bottom: 24px; line-height: 1.5; }
        .confirm-box .confirm-actions { display: flex; gap: 12px; justify-content: center; }
        .confirm-box .confirm-actions button { padding: 10px 28px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; font-family: 'Inter', sans-serif; transition: all 0.15s; }
        .confirm-box .confirm-batal { background: #f3f4f6; color: #374151; }
        .confirm-box .confirm-batal:hover { background: #e5e7eb; }
        .confirm-box .confirm-yakin { background: #2563eb; color: #fff; }
        .confirm-box .confirm-yakin:hover { background: #1d4ed8; }

        /* ===== POPUP BERHASIL ===== */
        .toast-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.35);
            z-index: 10001;
            align-items: center;
            justify-content: center;
        }
        .toast-overlay.active { display: flex; }
        .toast-box {
            background: #fff;
            border-radius: 16px;
            width: 380px;
            max-width: 86vw;
            padding: 36px 28px 28px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.18);
            animation: modalIn 0.3s ease;
        }
        .toast-box .toast-icon { font-size: 56px; margin-bottom: 14px; }
        .toast-box .toast-title { font-size: 20px; font-weight: 700; color: #1a1a2e; margin-bottom: 6px; }
        .toast-box .toast-desc { font-size: 14px; color: #6b7280; margin-bottom: 24px; line-height: 1.5; }
        .toast-box .toast-btn { padding: 10px 36px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; background: #2563eb; color: #fff; font-family: 'Inter', sans-serif; transition: all 0.15s; }
        .toast-box .toast-btn:hover { background: #1d4ed8; }

        /* search bar */
        .back-bg1 { min-width: 380px !important; }
        .back-text2 { white-space: nowrap !important; }

        /* Style minimalis ala Honor */
        .mini-card {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.15s;
        }
        .mini-card:hover { background: #f3f4f6; }
        .mini-card .left .name { font-size: 13px; font-weight: 600; color: #1a1a2e; }
        .mini-card .left .sub { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .mini-card .badge-valid { background: #d1fae5; color: #059669; padding: 3px 12px; border-radius: 6px; font-size: 11px; font-weight: 600; }
        .progres-item { margin-bottom: 12px; }
        .progres-item:last-child { margin-bottom: 0; }
        .progres-item .progres-header { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .progres-item .progres-header span:first-child { font-size: 12px; font-weight: 600; color: #1a1a2e; }
        .progres-item .progres-header span:last-child { font-size: 12px; font-weight: 600; color: #2563eb; }
        .progres-item .progres-bar { height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden; }
        .progres-item .progres-bar .progres-fill { height: 100%; background: #2563eb; border-radius: 3px; }
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
  .main-container1 { padding-left: 16px !important; padding-right: 16px !important; box-sizing: border-box; max-width: 100% !important; width: auto !important; }
  .container9 { flex-wrap: wrap; gap: 12px; }
  .container9 > * { max-width: 100%; }
  .main-container2 { display: flex !important; flex-direction: column !important; gap: 16px; }
  .main-container2 > * { width: 100% !important; max-width: none !important; flex: none !important; }
  .main-horizontal { overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; }
  .content-card { overflow-x: auto; }
  .tabel-presensi { min-width: 560px; }
  .stat-row, .honor-grid { flex-wrap: wrap; }
  .stat-box, .honor-box { flex: 1 1 140px; }
  .kelas-item { flex-wrap: wrap; gap: 10px; }
}
@media (max-width: 560px) { .kelas-time { flex-direction: row; gap: 6px; } .kelas-divider { display: none; } }
</style>
</head>
<body>

<!-- ========== POPUP KONFIRMASI ========== -->
<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-box">
        <div class="confirm-icon">✋</div>
        <div class="confirm-title">Konfirmasi Simpan</div>
        <div class="confirm-desc">Apakah Anda yakin ingin menyimpan perubahan data pelatih ini?</div>
        <div class="confirm-actions">
            <button class="confirm-batal" onclick="tutupConfirm()">Batal</button>
            <button class="confirm-yakin" onclick="confirmSimpan()">Ya, Simpan</button>
        </div>
    </div>
</div>

<!-- ========== POPUP BERHASIL ========== -->
<div class="toast-overlay" id="toastOverlay">
    <div class="toast-box">
        <div class="toast-icon">✅</div>
        <div class="toast-title">Berhasil Disimpan!</div>
        <div class="toast-desc">Data pelatih berhasil diperbarui di database.</div>
        <button class="toast-btn" onclick="tutupToast()">Tutup</button>
    </div>
</div>

<!-- ========== MODAL EDIT PROFIL ========== -->
<div class="modal-overlay" id="modalEdit">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Edit Profil Pelatih</h2>
            <button class="modal-close" onclick="tutupModal()">✕</button>
        </div>
        <div class="modal-body">
            <form id="formEdit" method="POST" action="proses-edit-pelatih.php" onsubmit="return konfirmasiSebelumSimpan(event)">
                <input type="hidden" name="id" value="<?= $id_pelatih ?>">
                
                <div class="modal-field">
                    <label>Nama Lengkap</label>
                    <input type="text" name="nama" value="<?= htmlspecialchars($nama) ?>" required>
                </div>

                <div class="modal-field-row">
                    <div class="modal-field">
                        <label>No. WhatsApp</label>
                        <input type="text" name="no_hp" value="<?= htmlspecialchars($no_hp) ?>">
                    </div>
                    <div class="modal-field">
                        <label>Email</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($email) ?>">
                    </div>
                </div>

                <div class="modal-field-row">
                    <div class="modal-field">
                        <label>Jenis Kelamin</label>
                        <select name="jenis_kelamin">
                            <option value="Laki-laki" <?= $gender === 'Laki-laki' ? 'selected' : '' ?>>Laki-laki</option>
                            <option value="Perempuan" <?= $gender === 'Perempuan' ? 'selected' : '' ?>>Perempuan</option>
                        </select>
                    </div>
                    <div class="modal-field">
                        <label>Pengalaman (Tahun)</label>
                        <input type="number" name="pengalaman_tahun" value="<?= (int)($pelatih['pengalaman_tahun'] ?? 0) ?>" min="0">
                    </div>
                </div>

                <div class="modal-field-row">
                    <div class="modal-field">
                        <label>Sistem Honor</label>
                        <select name="sistem_honor">
                            <option value="per_sesi" <?= ($pelatih['sistem_honor'] ?? 'per_sesi') === 'per_sesi' ? 'selected' : '' ?>>Per Sesi</option>
                            <option value="bulanan" <?= ($pelatih['sistem_honor'] ?? '') === 'bulanan' ? 'selected' : '' ?>>Bulanan</option>
                            <option value="persentase" <?= ($pelatih['sistem_honor'] ?? '') === 'persentase' ? 'selected' : '' ?>>Persentase</option>
                        </select>
                    </div>
                    <div class="modal-field">
                        <label>Status</label>
                        <select name="status">
                            <option value="aktif" <?= ($pelatih['status'] ?? 'aktif') === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                            <option value="nonaktif" <?= ($pelatih['status'] ?? '') === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                            <option value="cuti" <?= ($pelatih['status'] ?? '') === 'cuti' ? 'selected' : '' ?>>Cuti</option>
                        </select>
                    </div>
                </div>

                <div class="modal-field">
                    <label>Spesialisasi</label>
                    <input type="text" name="spesialisasi" value="<?= htmlspecialchars(implode(', ', $spesialisasiList)) ?>" placeholder="Pisahkan dengan koma, mis: Group Kids, Private">
                </div>

                <div class="modal-field">
                    <label>Sertifikasi</label>
                    <textarea name="sertifikasi" placeholder="Tulis sertifikasi, satu per baris&#10;Contoh:&#10;Certified Swim Coach (2026)&#10;Lifeguard Certification (2025)"><?= htmlspecialchars(trim($sertifikasiString)) ?></textarea>
                    <p style="font-size:11px;color:#9ca3af;margin-top:4px;">Tulis satu sertifikasi per baris. Format: Nama Sertifikasi (Tahun)</p>
                </div>

                <div class="modal-actions">
                    <button type="button" class="modal-btn-batal" onclick="tutupModal()">Batal</button>
                    <button type="submit" class="modal-btn-simpan">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== SIDEBAR ========== -->
<?php nvSidebar(); ?>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">
    <header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div></header>

    <div class="main-container1 group11">
        <div class="container-h container9">
            <div class="container-i container1" style="flex:2;">
                <?= nvCrumb([[$nama]]) ?>
                <h2 class="container-subtitle1">Detail Pelatih</h2>
                <p class="container-text10">Informasi lengkap profil, sertifikasi, dan performa mengajar</p>
            </div>
            <div class="container-container1" style="white-space:nowrap;">
                <button class="btn-bg-plus-border2 hover-bright">
                    <object data="assets/btn-background-plus-border/btn-background-plus-border-icon.svg" class="btn-background-plus-border-icon-component-a btn-background-plus-border-icon2" type="image/svg+xml"></object>
                    <p class="btn-bg-plus-border-label1" style="white-space:nowrap;">Hubungi via WhatsApp</p>
                </button>
                <button class="btn-background-d btn-bg2 hover-bright" onclick="bukaModal()">
                    <object data="assets/data-pelatih-detail/btn-background/btn-background-icon.svg" class="btn-background-icon-component-a btn-background-icon3" type="image/svg+xml"></object>
                    <p class="btn-background-label3" style="white-space:nowrap;">Edit Profil Pelatih</p>
                </button>
            </div>
        </div>

        <div class="main-container2">

            <!-- ===== KOLOM KIRI: KARTU PROFIL ===== -->
            <div class="card-bg-plus-border1 card12">
                <div class="card-bg-plus-border-horizontal">
                    <button class="card-bg-plus-border-btn-bg1 semantic-heading2 btn9 hover-bright"><?= htmlspecialchars($inisial) ?></button>
                    <p class="card-bg-plus-border-text-container1 inter-bold-a4"><?= htmlspecialchars($nama) ?></p>
                    <p class="card-bg-plus-border-text-small1 inter-regular7">PLT-00<?= $id_pelatih ?> &bull; Bergabung <?= $tglGabung ?></p>
                    <div class="card-bg-plus-border-container1">
                        <button class="card-bg-plus-border-btn-background-a card-bg-plus-border-btn-bg2 hover-bright"><?= $status ?></button>
                        <button class="card-bg-plus-border-btn-background-a card-bg-plus-border-btn-bg3 hover-bright"><?= htmlspecialchars($spesialisasiList[0] ?? 'Group Kids') ?></button>
                    </div>
                </div>
                <div class="horizontal-border-d horizontal-border1"><p class="horizontal-border-text-container3">No. WhatsApp</p><p class="horizontal-border-text-container4"><?= htmlspecialchars($no_hp) ?></p></div>
                <div class="horizontal-border-d horizontal-border2"><p class="horizontal-border-text-container3">Email</p><p class="horizontal-border-text-container4"><?= htmlspecialchars($email) ?></p></div>
                <div class="horizontal-border-d horizontal-border3"><p class="horizontal-border-text-container3">Jenis Kelamin</p><p class="horizontal-border-text-container4"><?= $gender ?></p></div>
                <div class="horizontal-border-d horizontal-border4"><p class="horizontal-border-text-container3">Pengalaman</p><p class="horizontal-border-text-container4"><?= $pengalaman ?></p></div>
                <div class="horizontal-border-d horizontal-border5"><p class="horizontal-border-text-container3">Sistem Honor</p><p class="horizontal-border-text-container4"><?= $sistemHonor ?></p></div>
                <p class="card-bg-plus-border-text-container2 inter-bold-a5">SPESIALISASI</p>
                <div class="card-bg-plus-border-container2">
                    <?php $totalSpesial = count($spesialisasiList); $barisAtas = min(3, $totalSpesial);
                    if ($barisAtas > 0): ?>
                    <div class="card-bg-plus-border-row-top">
                        <?php for ($i = 0; $i < $barisAtas; $i++): $class = $spesialClass[$i] ?? 'card-bg-plus-border-btn-bg4'; ?>
                        <button class="card-bg-plus-border-btn-background-b <?= $class ?> hover-bright"><?= htmlspecialchars($spesialisasiList[$i]) ?></button>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    <?php for ($i = 3; $i < $totalSpesial; $i++): ?>
                    <button class="card-bg-plus-border-btn-bg7 inter-medium3 btn10 hover-bright"><?= htmlspecialchars($spesialisasiList[$i]) ?></button>
                    <?php endfor; ?>
                </div>
                <p class="card-bg-plus-border-text-container3 inter-bold-a5">HARI MENGAJAR</p>
                <div class="card-bg-plus-border-container3">
                    <?php foreach ($hariMengajarList as $i => $hari): $class = $hariClass[$i] ?? 'card-bg-plus-border-btn-bg8'; ?>
                    <button class="card-bg-plus-border-btn-background-c <?= $class ?> hover-bright"><?= htmlspecialchars($hari) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ===== KOLOM KANAN: TAB & KONTEN ===== -->
            <div class="main-container3">

                <!-- ===== TAB NAVIGASI ===== -->
                <div class="main-horizontal">
                    <?php 
                    $tabs = [
                        'ringkasan'         => 'Ringkasan',
                        'kelas_diampu'      => 'Kelas Diampu',
                        'riwayat_kehadiran' => 'Riwayat Kehadiran',
                        'honor'             => 'Honor',
                    ];
                    foreach ($tabs as $key => $label): 
                        $isActive = $key === $activeTab;
                    ?>
                    <a href="?id=<?= $id_pelatih ?>&tab=<?= $key ?>" class="<?= $isActive ? 'tab-active' : '' ?>">
                        <p style="<?= $isActive ? 'color:#2563eb !important;font-weight:600 !important' : '' ?>"><?= $label ?></p>
                    </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($activeTab == 'ringkasan'): ?>
                
                <!-- ===== RINGKASAN ===== -->

                <!-- 3 Kartu Statistik — UKURAN DIKECILKAN -->
                <div style="display:flex;gap:16px;margin-bottom:20px;">
                    <div class="stat-card-modern" style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid #bfdbfe;">
                        <div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);">📚</div>
                        <div class="stat-content">
                            <div class="stat-label" style="color:#1e40af;">KELAS AKTIF</div>
                            <div class="stat-value" style="color:#1e3a8a;"><?= $kelasAktif ?></div>
                        </div>
                    </div>
                    <div class="stat-card-modern" style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);border:1px solid #a7f3d0;">
                        <div class="stat-icon" style="background:linear-gradient(135deg,#059669,#047857);">👥</div>
                        <div class="stat-content">
                            <div class="stat-label" style="color:#065f46;">MEMBER DIAJAR</div>
                            <div class="stat-value" style="color:#064e3b;"><?= $memberDiajar ?></div>
                        </div>
                    </div>
                    <div class="stat-card-modern" style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid #fcd34d;">
                        <div class="stat-icon" style="background:linear-gradient(135deg,#d97706,#b45309);">📊</div>
                        <div class="stat-content">
                            <div class="stat-label" style="color:#92400e;">KEHADIRAN PELATIH</div>
                            <div class="stat-value" style="color:#78350f;"><?= $persenHadir ?>%</div>
                        </div>
                    </div>
                </div>

                <!-- Sertifikasi & Progres Siswa — SAMPINGAN -->
                <div class="ringkasan-grid">
                    <div class="content-card">
                        <h3>Sertifikasi</h3>
                        <?php if (empty($sertifikasiList)): ?>
                            <p style="color:#9ca3af;text-align:center;padding:20px">Belum ada data sertifikasi</p>
                        <?php else: ?>
                            <?php foreach ($sertifikasiList as $s): ?>
                            <div class="mini-card">
                                <div class="left">
                                    <div class="name"><?= htmlspecialchars($s['nama_sertifikasi'] ?? '-') ?></div>
                                    <div class="sub">Berlaku s/d <?= date('M Y', strtotime($s['berlaku_sampai'] ?? 'now')) ?></div>
                                </div>
                                <span class="badge-valid">Valid</span>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="content-card">
                        <h3>Progres Siswa per Level</h3>
                        <?php foreach ($progresList as $pr): 
                            $labelLevel = $pr['level_dari'] . ' → ' . $pr['level_ke'];
                            $jmlSiswa = (int)($pr['jumlah_siswa'] ?? 0);
                            $persenBar = min(100, $jmlSiswa * 5);
                        ?>
                        <div class="progres-item">
                            <div class="progres-header">
                                <span><?= htmlspecialchars($labelLevel) ?></span>
                                <span><?= $jmlSiswa ?> siswa</span>
                            </div>
                            <div class="progres-bar">
                                <div class="progres-fill" style="width:<?= $persenBar ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Ulasan -->
                <div class="content-card">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #f3f4f6;">
                        <h3 style="margin:0;border:none;padding:0;">Ulasan Member Terbaru</h3>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span style="color:#d97706;font-size:16px;">★ <?= number_format($pelatih['rating'] ?? 4.9, 1) ?></span>
                            <span style="font-size:12px;color:#6b7280;">(<?= $pelatih['total_ulasan'] ?? count($ulasanList) ?> ulasan)</span>
                        </div>
                    </div>
                    <?php foreach ($ulasanList as $u): 
                        $inisialUlasan = $u['inisial'] ?? getInisial($u['nama_orangtua'] ?? 'User');
                        $rating = $u['rating'] ?? 5;
                    ?>
                    <div style="padding:12px 0;border-bottom:1px solid #f3f4f6;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                            <div style="width:32px;height:32px;border-radius:50%;background:#2563eb;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:700;flex-shrink:0;"><?= htmlspecialchars($inisialUlasan) ?></div>
                            <div style="flex:1;"><p style="font-weight:600;font-size:13px;color:#1a1a2e;"><?= htmlspecialchars($u['nama_orangtua'] ?? 'Member') ?></p></div>
                            <span style="color:#d97706;font-size:14px;"><?= str_repeat('★', min(5, max(1, (int)$rating))) ?></span>
                        </div>
                        <p style="font-size:12px;color:#6b7280;margin:0;line-height:1.5;padding-left:42px;"><?= htmlspecialchars($u['ulasan'] ?? '') ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php elseif ($activeTab == 'kelas_diampu'): ?>

                    <?php
                    $kelasList = [];
                    $errorKelas = '';
                    try {
                        $stmt = $pdo->prepare("
                            SELECT j.*, kp.nama_program
                            FROM jadwal j 
                            LEFT JOIN kelas_program kp ON kp.id = j.program_id 
                            WHERE j.pelatih_id = :id 
                            ORDER BY DAYOFWEEK(j.tanggal) ASC, j.jam_mulai ASC
                        ");
                        $stmt->execute([':id' => $id_pelatih]);
                        $kelasList = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) { $errorKelas = $e->getMessage(); }

                    $hariNama = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
                    $kelasPerHari = [];
                    foreach ($kelasList as $k) {
                        $h = (int)date('w', strtotime($k['tanggal']));
                        if (!isset($kelasPerHari[$h])) $kelasPerHari[$h] = [];
                        $kelasPerHari[$h][] = $k;
                    }
                    ?>
                    <div class="kelas-wrapper">
                        <?php if (!empty($errorKelas)): ?>
                            <div class="kelas-empty"><div class="empty-icon">⚠️</div><div class="empty-title">Gagal Memuat Data</div><div class="empty-desc">Error: <?= htmlspecialchars($errorKelas) ?></div></div>
                        <?php elseif (empty($kelasList)): ?>
                            <div class="kelas-empty"><div class="empty-icon">📋</div><div class="empty-title">Belum Ada Jadwal</div><div class="empty-desc">Pelatih ini belum memiliki jadwal kelas.</div></div>
                        <?php else: ?>
                            <?php $totalKelas = count($kelasList); $totalHari = count($kelasPerHari); $programUnik = [];
                            foreach ($kelasList as $k) { if (!empty($k['nama_program'])) $programUnik[$k['nama_program']] = true; } ?>
                            <div class="kelas-stats">
                                <div class="kelas-stat-box"><div class="kelas-stat-icon" style="background:#eff6ff;color:#2563eb;font-weight:700;">K</div><div class="kelas-stat-info"><div class="stat-num"><?= $totalKelas ?></div><div class="stat-lbl">Total Jadwal</div></div></div>
                                <div class="kelas-stat-box"><div class="kelas-stat-icon" style="background:#ecfdf5;color:#059669;font-weight:700;">H</div><div class="kelas-stat-info"><div class="stat-num"><?= $totalHari ?></div><div class="stat-lbl">Hari Aktif</div></div></div>
                                <div class="kelas-stat-box"><div class="kelas-stat-icon" style="background:#fef3c7;color:#d97706;font-weight:700;">P</div><div class="kelas-stat-info"><div class="stat-num"><?= count($programUnik) ?></div><div class="stat-lbl">Program</div></div></div>
                                <div class="kelas-stat-box"><div class="kelas-stat-icon" style="background:#f3e8ff;color:#7c3aed;font-weight:700;">T</div><div class="kelas-stat-info"><div class="stat-num"><?= $totalKelas * 60 ?> menit</div><div class="stat-lbl">Total Jam/Minggu</div></div></div>
                            </div>
                            <?php $warnaHari = [0=>['bg'=>'#f1f5f9','dot'=>'#64748b'],1=>['bg'=>'#eff6ff','dot'=>'#2563eb'],2=>['bg'=>'#ecfdf5','dot'=>'#059669'],3=>['bg'=>'#fef3c7','dot'=>'#d97706'],4=>['bg'=>'#f3e8ff','dot'=>'#7c3aed'],5=>['bg'=>'#fff7ed','dot'=>'#ea580c'],6=>['bg'=>'#fce7f3','dot'=>'#db2777']];
                            foreach ($kelasPerHari as $angkaHari => $daftarKelas): $warna = $warnaHari[$angkaHari]??['bg'=>'#f9fafb','dot'=>'#6b7280']; $namaHariIndo = $hariNama[$angkaHari]; ?>
                            <div class="hari-card">
                                <div class="hari-card-header" style="background:<?= $warna['bg'] ?>"><span class="hari-badge" style="background:<?= $warna['dot'] ?>"></span><span class="hari-nama"><?= $namaHariIndo ?></span><span class="hari-jumlah"><?= count($daftarKelas) ?> jadwal</span></div>
                                <?php foreach ($daftarKelas as $k): $jamMulai = substr($k['jam_mulai']??'00:00',0,5); $jamSelesai = substr($k['jam_selesai']??'00:00',0,5); $statusKelas = $k['status']??'akan_datang'; $statusLabel = str_replace('_',' ',ucfirst($statusKelas)); $badgeClass = match($statusKelas){'berlangsung'=>'berlangsung','selesai'=>'selesai','dibatalkan'=>'dibatalkan',default=>'akan_datang'}; ?>
                                <div class="kelas-item">
                                    <div class="kelas-time"><span class="jam"><?= $jamMulai ?></span><span class="separator">─</span><span class="jam-end"><?= $jamSelesai ?></span></div>
                                    <div class="kelas-divider"></div>
                                    <div class="kelas-info"><div class="program-name"><?= htmlspecialchars($k['nama_program']??'Kelas') ?></div><div class="program-detail"><?= tglIndo($k['tanggal']) ?> &bull; <?= htmlspecialchars($k['kolam']??'-') ?> &bull; Kapasitas: <?= (int)$k['kapasitas'] ?></div></div>
                                    <span class="kelas-status-badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                <?php elseif ($activeTab == 'riwayat_kehadiran'): ?>

                    <?php
                    try {
                        $stmt = $pdo->prepare("SELECT pr.*, DATE(pr.waktu) as tgl, TIME(pr.waktu) as jam FROM presensi pr JOIN jadwal j ON j.id = pr.jadwal_id WHERE j.pelatih_id = :id AND pr.waktu >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY) ORDER BY pr.waktu DESC");
                        $stmt->execute([':id' => $id_pelatih]); $presensi = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) { $presensi = []; }
                    $hadir=0;$izin=0;$alpa=0;$sakit=0;
                    foreach ($presensi as $p) { $s=strtolower($p['status']??''); if($s==='hadir')$hadir++; elseif($s==='izin')$izin++; elseif($s==='alpa')$alpa++; elseif($s==='sakit')$sakit++; }
                    $total = count($presensi); $persenBulanIni = $total>0 ? round(($hadir/$total)*100) : 0;
                    ?>
                    <div class="stat-row">
                        <div class="stat-box" style="background:#ecfdf5"><div class="stat-label">Hadir</div><div class="stat-value" style="color:#059669"><?= $hadir ?></div></div>
                        <div class="stat-box" style="background:#fffbeb"><div class="stat-label">Izin</div><div class="stat-value" style="color:#d97706"><?= $izin ?></div></div>
                        <div class="stat-box" style="background:#fff7ed"><div class="stat-label">Sakit</div><div class="stat-value" style="color:#ea580c"><?= $sakit ?></div></div>
                        <div class="stat-box" style="background:#fef2f2"><div class="stat-label">Alpa</div><div class="stat-value" style="color:#dc2626"><?= $alpa ?></div></div>
                        <div class="stat-box" style="background:#eff6ff"><div class="stat-label">Kehadiran</div><div class="stat-value" style="color:#2563eb"><?= $persenBulanIni ?>%</div></div>
                    </div>
                    <div class="content-card">
                        <h3>Riwayat Presensi — 30 Hari Terakhir</h3>
                        <?php if (empty($presensi)): ?><p style="color:#9ca3af;text-align:center;padding:20px">Belum ada data presensi dalam 30 hari terakhir</p>
                        <?php else: ?>
                        <table class="tabel-presensi"><thead><tr><th>Tanggal</th><th>Jam</th><th>Status</th><th>Catatan</th></tr></thead><tbody>
                            <?php foreach ($presensi as $p): $status=strtolower($p['status']??''); $badgeClass='badge-'.($status==='alpa'?'alpa':$status); $label=ucfirst($status==='alpa'?'Alpa':$status); ?>
                            <tr><td><?= tglIndo($p['tgl']) ?></td><td><?= substr($p['jam']??'00:00',0,5) ?></td><td><span class="badge-status <?= $badgeClass ?>"><?= $label ?></span></td><td style="color:#6b7280;font-size:12px"><?= htmlspecialchars($p['catatan']??'-') ?></td></tr>
                            <?php endforeach; ?>
                        </tbody></table>
                        <?php endif; ?>
                    </div>

                <?php elseif ($activeTab == 'honor'): ?>

                    <?php
                    $tarif = 75000;
                    $labelHonor = match($pelatih['sistem_honor']??'per_sesi'){'per_sesi'=>'Per Sesi','bulanan'=>'Bulanan','persentase'=>'Persentase',default=>'Per Sesi'};
                    $totalSesiBulanIni=0;$sesiHadir=0;$sesiIzin=0;
                    try {
                        $stmt=$pdo->prepare("SELECT pr.status,COUNT(*) as jml FROM presensi pr JOIN jadwal j ON j.id=pr.jadwal_id WHERE j.pelatih_id=:id AND MONTH(pr.waktu)=MONTH(CURRENT_DATE) AND YEAR(pr.waktu)=YEAR(CURRENT_DATE) GROUP BY pr.status");
                        $stmt->execute([':id'=>$id_pelatih]); $sesiData=$stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach($sesiData as $sd){$totalSesiBulanIni+=(int)$sd['jml']; if(strtolower($sd['status'])==='hadir')$sesiHadir+=(int)$sd['jml']; elseif(strtolower($sd['status'])==='izin')$sesiIzin+=(int)$sd['jml'];}
                    } catch(Exception $e){}
                    $estimasiHonor=$tarif*$sesiHadir; $potonganIzin=$tarif*0.5*$sesiIzin; $totalDiterima=$estimasiHonor-$potonganIzin;
                    $namaBulanIndo=['January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April','May'=>'Mei','June'=>'Juni','July'=>'Juli','August'=>'Agustus','September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember']; $bulanIndo=$namaBulanIndo[date('F')]??date('F');
                    ?>
                    <div class="honor-grid">
                        <div class="honor-box" style="background:#eff6ff;border-color:#bfdbfe"><div class="h-label">Sistem Honor</div><div class="h-value"><?= $labelHonor ?></div></div>
                        <div class="honor-box" style="background:#ecfdf5;border-color:#a7f3d0"><div class="h-label">Total Sesi Bulan Ini</div><div class="h-value"><?= $totalSesiBulanIni ?> sesi</div></div>
                        <div class="honor-box" style="background:#fffbeb;border-color:#fde68a"><div class="h-label">Estimasi per Sesi</div><div class="h-value">Rp <?= number_format($tarif,0,',','.') ?></div></div>
                        <div class="honor-box" style="background:#fef2f2;border-color:#fecaca"><div class="h-label">Estimasi Diterima</div><div class="h-value" style="color:#2563eb">Rp <?= number_format($totalDiterima,0,',','.') ?></div></div>
                    </div>
                    <div class="content-card">
                        <h3>Detail Honor — <?= $bulanIndo ?> <?= date('Y') ?></h3>
                        <div class="honor-detail-row"><span class="label">Estimasi per Sesi</span><span class="value">Rp <?= number_format($tarif,0,',','.') ?></span></div>
                        <div class="honor-detail-row"><span class="label">Sesi Hadir</span><span class="value"><?= $sesiHadir ?> sesi x Rp <?= number_format($tarif,0,',','.') ?></span></div>
                        <div class="honor-detail-row"><span class="label">Subtotal Honor</span><span class="value" style="color:#059669">Rp <?= number_format($estimasiHonor,0,',','.') ?></span></div>
                        <div class="honor-detail-row"><span class="label">Sesi Izin (potongan 50%)</span><span class="value" style="color:#d97706"><?= $sesiIzin ?> sesi x Rp <?= number_format($tarif*0.5,0,',','.') ?></span></div>
                        <div class="honor-detail-row"><span class="label">Potongan Izin</span><span class="value" style="color:#dc2626">- Rp <?= number_format($potonganIzin,0,',','.') ?></span></div>
                        <div class="honor-total"><span class="label">Total Estimasi Diterima</span><span class="value">Rp <?= number_format($totalDiterima,0,',','.') ?></span></div>
                    </div>

                <?php endif; ?>

            </div><!-- .main-container3 -->
        </div><!-- .main-container2 -->
    </div><!-- .main-container1 -->
</div><!-- .main -->

<!-- ===== JAVASCRIPT ===== -->
<script>
function bukaModal() { document.getElementById('modalEdit').classList.add('active'); document.body.style.overflow = 'hidden'; }
function tutupModal() { document.getElementById('modalEdit').classList.remove('active'); document.body.style.overflow = ''; }
document.getElementById('modalEdit').addEventListener('click', function(e) { if (e.target === this) tutupModal(); });

let formPending = null;
function konfirmasiSebelumSimpan(event) { event.preventDefault(); formPending = event.target; document.getElementById('confirmOverlay').classList.add('active'); return false; }
function tutupConfirm() { document.getElementById('confirmOverlay').classList.remove('active'); formPending = null; }
function confirmSimpan() { document.getElementById('confirmOverlay').classList.remove('active'); if (formPending) formPending.submit(); }

document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('edit') === 'success') {
        document.getElementById('toastOverlay').classList.add('active');
        const newUrl = window.location.pathname + window.location.search.replace(/[?&]edit=success/, '').replace(/^&/, '?');
        window.history.replaceState({}, '', newUrl);
    }
    if (urlParams.get('edit') === 'error') {
        const msg = urlParams.get('msg') || 'Terjadi kesalahan';
        alert('Gagal menyimpan: ' + decodeURIComponent(msg));
        const newUrl = window.location.pathname + window.location.search.replace(/[?&]edit=error[^&]*/, '').replace(/^&/, '?');
        window.history.replaceState({}, '', newUrl);
    }
});

function tutupToast() { document.getElementById('toastOverlay').classList.remove('active'); }
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { tutupConfirm(); tutupToast(); tutupModal(); } });
document.getElementById('confirmOverlay').addEventListener('click', function(e) { if (e.target === this) tutupConfirm(); });
document.getElementById('toastOverlay').addEventListener('click', function(e) { if (e.target === this) tutupToast(); });
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