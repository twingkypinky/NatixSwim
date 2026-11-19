<?php
require 'config/koneksi.php';
date_default_timezone_set('Asia/Jakarta');

// Query kalau error (misal nama kolom beda), halaman tetap tampil
function ambil($pdo, $sql, $params = [], $semua = true)
{
	try {
		$st = $pdo->prepare($sql);
		$st->execute($params);
		return $semua ? $st->fetchAll() : $st->fetch();
	} catch (PDOException $e) {
		echo "<!-- SQL error: " . htmlspecialchars($e->getMessage()) . " -->";
		return $semua ? [] : null;
	}
}
function e($s)
{
	return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
function inisial($nama)
{
	$nama = preg_replace('/^Coach\s+/i', '', trim($nama ?? ''));
	$p = preg_split('/\s+/', $nama);
	return strtoupper(substr($p[0] ?? '', 0, 1) . substr($p[1] ?? ($p[0] ?? ''), $p[1] ?? false ? 0 : 1, 1));
}
function persen($baru, $lama)
{
	if (!$lama) return '+0%';
	$v = ($baru - $lama) / $lama * 100;
	return ($v >= 0 ? '+' : '') . number_format($v, 1, ',', '.') . '%';
}
function juta($rp)
{
	return number_format($rp / 1000000, 1, ',', '.');
}

$hariID  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$bulanID = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$blnPdk  = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

$tgl      = $_GET['tanggal'] ?? date('Y-m-d');
$ts       = strtotime($tgl);
$besok    = date('Y-m-d', strtotime('+1 day', $ts));
$tsBesok  = strtotime($besok);
$bulanIni = (int)date('n', $ts);
$tahun    = (int)($_GET['tahun'] ?? date('Y', $ts));
$teksTanggal = $hariID[date('w', $ts)] . ', ' . date('j', $ts) . ' ' . $bulanID[$bulanIni] . ' ' . date('Y', $ts);

/* ---------- Stat: member ---------- */
$m = ambil($pdo, "
    SELECT COUNT(*) AS total,
           SUM(status = 'aktif') AS aktif,
           SUM(created_at < DATE_FORMAT(:t, '%Y-%m-01')) AS total_lalu
    FROM members", [':t' => $tgl], false);

$totalMember = (int)($m['total'] ?? 0);
$memberAktif = (int)($m['aktif'] ?? 0);
$totalLalu   = (int)($m['total_lalu'] ?? 0);
$nonAktif    = $totalMember - $memberAktif;
$pAktif      = $totalMember ? round($memberAktif / $totalMember * 100, 1) : 0;

/* ---------- Kelas hari ini ---------- */
$kelas = ambil($pdo, "SELECT * FROM v_kelas_hari_ini ORDER BY jam_mulai");
$jamSkrg = date('H:i:s');
$berlangsung = 0;
$pelatihSet = [];
foreach ($kelas as &$k) {
	if ($jamSkrg < $k['jam_mulai']) {
		$k['_s'] = ['status-next', 'Akan Datang'];
	} elseif ($jamSkrg <= $k['jam_selesai']) {
		$k['_s'] = ['status-live', 'Berlangsung'];
		$berlangsung++;
	} else {
		$k['_s'] = ['status-done', 'Selesai'];
	}
	$pelatihSet[$k['pelatih']] = true;
}
unset($k);
$jmlKelas   = count($kelas);
$jmlPelatih = count($pelatihSet);

/* ---------- Pendapatan bulanan (2 tahun) ---------- */
$tahunIni  = (int) date('Y');
$tahunLalu = $tahunIni - 1;

// Tahun yang dipilih dari ?tahun=..., hanya boleh tahun ini / tahun lalu
$tahunPdp = (int) ($_GET['tahun'] ?? $tahunIni);
if (!in_array($tahunPdp, [$tahunIni, $tahunLalu], true)) {
	$tahunPdp = $tahunIni;
}

// Ambil data 2 tahun sekaligus
// Sumber sama dengan halaman Laporan Keuangan: laporan_keuangan (rupiah) -> juta
$rows = ambil($pdo, "SELECT tahun, bulan, total_pendapatan AS total FROM laporan_keuangan WHERE tahun = ? ORDER BY bulan", [$tahunPdp]);
// Bulan yang belum punya catatan laporan_keuangan: dijumlahkan dari tabel pembayaran
$sudahAda = [];
foreach ($rows as $r) { $sudahAda[(int) $r['bulan']] = true; }
$dariByr = ambil($pdo, "SELECT MONTH(tanggal_bayar) AS bulan, SUM(jumlah) AS total FROM pembayaran WHERE YEAR(tanggal_bayar) = ? GROUP BY MONTH(tanggal_bayar)", [$tahunPdp]);
foreach ($dariByr as $r) {
  if (empty($sudahAda[(int) $r['bulan']])) {
    $rows[] = ['tahun' => $tahunPdp, 'bulan' => (int) $r['bulan'], 'total' => $r['total']];
  }
}

$dataPdp = [$tahunLalu => [], $tahunIni => []];
foreach ($rows as $r) {
	$dataPdp[(int) $r['tahun']][(int) $r['bulan']] = (float) $r['total'];
}

// 12 bulan lengkap (bulan kosong = 0)
$pendapatan = array_replace(array_fill(1, 12, 0), $dataPdp[$tahunPdp]);

// Tahun ini pakai bulan sekarang; tahun lalu pakai Desember
$bulanAcuan = ($tahunPdp === $tahunIni) ? (int) date('n') : 12;

$pdpIni  = $pendapatan[$bulanAcuan];
$pdpLalu = $bulanAcuan > 1
	? $pendapatan[$bulanAcuan - 1]
	: ($dataPdp[$tahunPdp - 1][12] ?? 0);   // Januari: bandingkan dengan Desember tahun lalu

$ytd    = array_sum($pendapatan);
$maxBar = max(max($pendapatan), 1);

/* ---------- Pengingat H-1 ---------- */
$pengingat = ambil($pdo, "
    SELECT kp.nama_program, j.jam_mulai, j.kolam, u.nama AS pelatih,
           COUNT(jp.member_id) AS jml_siswa
    FROM jadwal j
    JOIN kelas_program kp ON kp.id = j.program_id
    JOIN pelatih pl       ON pl.id = j.pelatih_id
    JOIN users u          ON u.id  = pl.user_id
    LEFT JOIN jadwal_peserta jp ON jp.jadwal_id = j.id
    WHERE j.tanggal = ? AND j.status <> 'dibatalkan'
    GROUP BY j.id, kp.nama_program, j.jam_mulai, j.kolam, u.nama
    ORDER BY j.jam_mulai", [$besok]);

/* ---------- Tugas evaluasi ---------- */
$evaluasi = ambil($pdo, "
    SELECT kp.nama_program, u.nama AS pelatih, COUNT(*) AS jml
    FROM presensi a
    JOIN jadwal j         ON j.id  = a.jadwal_id
    JOIN kelas_program kp ON kp.id = j.program_id
    JOIN pelatih pl       ON pl.id = j.pelatih_id
    JOIN users u          ON u.id  = pl.user_id
    LEFT JOIN evaluasi ev ON ev.jadwal_id = a.jadwal_id AND ev.member_id = a.member_id AND ev.status = 'selesai'
    WHERE a.status = 'hadir' AND ev.id IS NULL
    GROUP BY kp.id, kp.nama_program, pl.id, u.nama
    ORDER BY jml DESC LIMIT 5");
/* ---------- Kuota hampir habis ---------- */
$kuota = ambil($pdo, "SELECT * FROM v_kuota_hampir_habis ORDER BY sisa_sesi ASC");
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
<html lang="id">

<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Dashboard — Natix Swim</title>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="css/reset.css" />
	<link rel="stylesheet" href="css/dashboard.css" />
	<style>
		/* Statistik: 4 kolom */
		.stats {
			display: grid !important;
			grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
			gap: 24px !important;
		}

		/* Layout utama: kiri lebar, kanan sempit */
		.layout {
			display: grid !important;
			grid-template-columns: minmax(0, 2fr) minmax(320px, 1fr) !important;
			gap: 24px !important;
			align-items: start !important;
		}

		.col-left,
		.col-right {
			display: flex !important;
			flex-direction: column !important;
			gap: 24px !important;
			min-width: 0 !important;
			width: auto !important;
		}

		/* Status Member + Pendapatan berdampingan */
		.row-charts {
			display: grid !important;
			grid-template-columns: minmax(240px, 1fr) minmax(0, 2fr) !important;
			gap: 24px !important;
		}

		/* Layar sedang: kanan turun ke bawah */
		@media (max-width: 1200px) {
			.layout {
				grid-template-columns: 1fr !important;
			}
		}

		@media (max-width: 1024px) {
			.stats {
				grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
			}

			.row-charts {
				grid-template-columns: 1fr !important;
			}
		}

		@media (max-width: 560px) {
			.stats {
				grid-template-columns: 1fr !important;
			}
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

@media (max-width: 1000px) {
  .content { padding-left: 16px !important; padding-right: 16px !important; box-sizing: border-box; }
  .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .table { min-width: 640px; }
  .bars { overflow-x: auto; }
}
</style>
</head>

<body>
	<!-- ===== SIDEBAR ===== -->
	<?php nvSidebar(); ?>

	<!-- ===== MAIN ===== -->
	<main class="main">
		<header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div></header>

		<div class="content">
			<div>
				<?= nvCrumb() ?>
<h1 class="page-title">Dashboard</h1>
				<p class="muted"><?= e($teksTanggal) ?> · Selamat datang kembali, Admin</p>
			</div>

			<!-- STAT CARDS -->
			<section class="stats">
				<article class="card stat">
					<div class="stat-top">
						<p class="stat-label">Total Member</p>
						<span class="stat-icon"><img src="assets/dashboard-admin/card-c-f-ico-r/card-c-f-group1.svg" alt=""></span>
					</div>
					<div class="stat-value">
						<h2><?= $totalMember ?></h2>
						<span class="pill pill-green"><?= persen($totalMember, $totalLalu) ?></span>
					</div>
					<p class="stat-note">vs. bulan lalu (<?= $totalLalu ?>)</p>
				</article>

				<article class="card stat">
					<div class="stat-top">
						<p class="stat-label">Member Aktif</p>
						<span class="stat-icon"><img src="assets/dashboard-admin/card-c-f-ico-r/card-c-f-group2.svg" alt=""></span>
					</div>
					<div class="stat-value">
						<h2><?= $memberAktif ?></h2>
						<span class="pill pill-green"><?= number_format($pAktif, 1, ',', '.') ?>%</span>
					</div>
					<p class="stat-note"><?= round($pAktif) ?>% dari total member</p>
				</article>

				<article class="card stat">
					<div class="stat-top">
						<p class="stat-label">Total Kelas Hari Ini</p>
						<span class="stat-icon"><img src="assets/dashboard-admin/card-c-f-group.svg" alt=""></span>
					</div>
					<div class="stat-value">
						<h2><?= $jmlKelas ?></h2>
						<span class="pill pill-green pill-wide"><?= $berlangsung ?> berlangsung</span>
					</div>
					<p class="stat-note"><?= $jmlPelatih ?> pelatih bertugas</p>
				</article>

				<article class="card stat">
					<div class="stat-top">
						<p class="stat-label">Pendapatan <?= $bulanID[$bulanIni] ?></p>
						<span class="stat-icon"><img src="assets/dashboard-admin/card-c-f-ico-r/card-c-f-group3.svg" alt=""></span>
					</div>
					<div class="stat-value">
						<h2>Rp <?= juta($pdpIni) ?>jt</h2>
						<span class="pill pill-green"><?= persen($pdpIni, $pdpLalu) ?></span>
					</div>
					<p class="stat-note">vs. <?= $bulanID[max(1, $bulanIni - 1)] ?> (Rp <?= juta($pdpLalu) ?>jt)</p>
				</article>
			</section>

			<section class="layout">
				<!-- KOLOM KIRI -->
				<div class="col-left">
					<div class="row-charts">
						<!-- Donut -->
						<article class="card">
							<div class="card-head">
								<div>
									<h3 class="card-title">Status Member</h3>
									<p class="muted">Aktif vs. Non-Aktif</p>
								</div>
							</div>

							<div class="donut" style="--p: <?= $pAktif ?>">
								<div class="donut-center">
									<strong><?= $totalMember ?></strong>
									<span>Total Member</span>
								</div>
							</div>

							<div class="legend">
								<div class="legend-box">
									<p><i class="dot dot-blue"></i>Aktif</p>
									<strong><?= $memberAktif ?></strong>
								</div>
								<div class="legend-box">
									<p><i class="dot dot-gray"></i>Non-Aktif</p>
									<strong><?= $nonAktif ?></strong>
								</div>
							</div>
						</article>

						<!-- Bar chart -->
						<!-- Bar chart -->
						<article class="card" id="pendapatan">
							<div class="card-head">
								<div>
									<h3 class="card-title">Pendapatan Bulanan</h3>
									<p class="muted">Jan – <?= $blnPdk[$bulanAcuan] ?> <?= $tahunPdp ?> (juta Rupiah)</p>
								</div>
								<div class="chips">
									<?php foreach ([$tahunIni, $tahunLalu] as $th): ?>
										<a href="?tahun=<?= $th ?>#pendapatan"
											class="chip<?= $th === $tahunPdp ? ' active' : '' ?>"><?= $th ?></a>
									<?php endforeach; ?>
								</div>
							</div>

							<div class="revenue">
								<h2>Rp <?= juta($ytd) ?>jt</h2>
								<span class="pill pill-green"><?= $tahunPdp === $tahunIni ? 'Total YTD' : 'Total 1 Tahun' ?></span>
							</div>

							<div class="bars">
								<?php for ($b = 1; $b <= $bulanAcuan; $b++):
									$total = $pendapatan[$b] ?? 0;
									$nilai = $total / 1000000;
									$v     = round($total / $maxBar * 100); ?>
									<div class="bar<?= $b === $bulanAcuan ? ' active' : '' ?>" style="--v: <?= $v ?>">
										<span><?= str_replace('.', ',', round($nilai, 1)) ?></span><i></i><b><?= $blnPdk[$b] ?></b>
									</div>
								<?php endfor; ?>
							</div>
						</article>
					</div>

					<!-- Tabel kelas -->
					<article class="card">
						<div class="card-head">
							<div>
								<h3 class="card-title">Kelas Hari Ini</h3>
								<p class="muted"><?= $jmlKelas ?> kelas · <?= $jmlPelatih ?> pelatih bertugas</p>
							</div>
							<a href="jadwal-kelas.php" class="link">Lihat semua jadwal →</a>
						</div>

						<div class="table-wrap">
							<table class="table">
								<thead>
									<tr>
										<th>PROGRAM</th>
										<th>WAKTU</th>
										<th>PELATIH</th>
										<th>KOLAM</th>
										<th>SISWA</th>
										<th>STATUS</th>
									</tr>
								</thead>
								<tbody>
									<?php if (!$kelas): ?>
										<tr>
											<td colspan="6" class="muted">Tidak ada kelas hari ini.</td>
										</tr>
									<?php endif; ?>
									<?php foreach ($kelas as $k): ?>
										<tr>
											<td>
												<div class="person"><span class="avatar"><?= inisial($k['nama_program']) ?></span><strong><?= e($k['nama_program']) ?></strong></div>
											</td>
											<td><?= substr($k['jam_mulai'], 0, 5) ?> – <?= substr($k['jam_selesai'], 0, 5) ?></td>
											<td>
												<div class="person"><span class="avatar"><?= inisial($k['pelatih']) ?></span><?= e($k['pelatih']) ?></div>
											</td>
											<td><?= e($k['kolam']) ?></td>
											<td><b><?= (int)$k['jumlah_siswa'] ?></b>/<?= (int)$k['kapasitas'] ?></td>
											<td><span class="status <?= $k['_s'][0] ?>"><?= $k['_s'][1] ?></span></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</article>
				</div>

				<!-- KOLOM KANAN -->
				<div class="col-right">
					<!-- Notifikasi -->
					<article class="card">
						<div class="card-head">
							<h3 class="card-title">Notifikasi &amp; Tugas</h3>
							<a href="#" class="link">Tandai dibaca</a>
						</div>

						<div class="chips">
							<button class="chip active" data-filter="semua">Semua</button>
							<button class="chip" data-filter="jadwal">Jadwal</button>
							<button class="chip" data-filter="evaluasi">Evaluasi</button>
						</div>

						<!-- Pengingat H-1 -->
						<div class="notif-group" data-group="jadwal">
							<p class="section-label">PENGINGAT H-1 · <?= $hariID[date('w', $tsBesok)] ?>, <?= date('j', $tsBesok) ?> <?= $blnPdk[(int)date('n', $tsBesok)] ?></p>

							<?php if (!$pengingat): ?>
								<p class="muted">Tidak ada kelas besok.</p>
							<?php endif; ?>

							<?php foreach ($pengingat as $p): ?>
								<div class="notif">
									<span class="notif-icon"><img src="assets/dashboard-admin/nt-ni-r/nt-group.svg" alt=""></span>
									<div>
										<p class="notif-title">Besok: <?= e($p['nama_program']) ?></p>
										<p class="notif-text"><?= substr($p['jam_mulai'], 0, 5) ?> · <?= e($p['kolam']) ?> · <?= e($p['pelatih']) ?> · <?= (int)$p['jml_siswa'] ?> siswa</p>
									</div>
								</div>
							<?php endforeach; ?>
						</div>

						<!-- Tugas Evaluasi -->
						<div class="notif-group" data-group="evaluasi">
							<p class="section-label">TUGAS EVALUASI</p>

							<?php if (!$evaluasi): ?>
								<p class="muted">Semua evaluasi sudah diisi. 🎉</p>
							<?php endif; ?>

							<?php foreach ($evaluasi as $ev): ?>
								<div class="notif">
									<span class="notif-icon"><img src="assets/dashboard-admin/nt-ni-r/nt-group1.svg" alt=""></span>
									<div>
										<p class="notif-title">Evaluasi belum diisi: <?= e($ev['nama_program']) ?></p>
										<p class="notif-text"><?= e($ev['pelatih']) ?> · <?= (int)$ev['jml'] ?> siswa menunggu</p>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</article>

					<!-- Kuota hampir habis -->
					<article class="card">
						<div class="card-head">
							<div>
								<h3 class="card-title">Kuota Hampir Habis</h3>
								<p class="muted"><?= count($kuota) ?> member perlu perpanjang paket</p>
							</div>
							<a href="datamember.php" class="link">Lihat semua →</a>
						</div>

						<?php if (!$kuota): ?>
							<p class="muted">Tidak ada member dengan kuota rendah.</p>
						<?php endif; ?>

						<?php foreach ($kuota as $q):
							$total = max(1, (int)($q['total_sesi'] ?? 1));
							$sisa  = (int)($q['sisa_sesi'] ?? 0);
							$pct   = round($sisa / $total * 100);
						?>
							<div class="quota">
								<div class="quota-top">
									<div class="person">
										<span class="avatar"><?= inisial($q['nama']) ?></span>
										<div>
											<strong><?= e($q['nama']) ?></strong>
											<p class="muted"><?= e($q['nama_paket'] ?? $q['nama_program'] ?? '-') ?></p>
										</div>
									</div>
									<span class="pill <?= $sisa <= 1 ? 'pill-red' : 'pill-orange' ?>"><?= $sisa ?>/<?= $total ?> sesi</span>
								</div>
								<div class="progress"><i style="width: <?= $pct ?>%"></i></div>
							</div>
						<?php endforeach; ?>
					</article>
				</div>
			</section>
		</div>
	</main>

	<!-- Filter chip notifikasi -->
	<script>
		document.querySelectorAll('[data-filter]').forEach(btn => {
			btn.addEventListener('click', () => {
				document.querySelectorAll('[data-filter]').forEach(b => b.classList.remove('active'));
				btn.classList.add('active');
				const f = btn.dataset.filter;
				document.querySelectorAll('.notif-group').forEach(g => {
					g.style.display = (f === 'semua' || g.dataset.group === f) ? '' : 'none';
				});
			});
		});
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