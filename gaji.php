<?php
// ============================================================
// gaji.php — Gaji Coach (gaji per sesi)
// Alur: Draft (dihitung otomatis dari jadwal) -> Disetujui -> Dibayar (masuk pengeluaran Laporan Keuangan) -> slip (cetak / WhatsApp)
// Logika di gaji-fungsi.php, query SQL di gaji-query.php. Tabel dibuat otomatis saat halaman dibuka.
// ============================================================
if (($_GET['aksi'] ?? '') !== '') { ob_start(); ini_set('display_errors', '0'); }
date_default_timezone_set('Asia/Jakarta');
require 'config/koneksi.php';
require_once __DIR__ . '/gaji-fungsi.php';

// true = pesan error server ditampilkan apa adanya (membantu debug). Ubah ke false saat production.
const GJ_DEBUG = true;

function out(array $data, int $code = 200): void
{
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function gjPeriode(): array
{
    $th = (int)($_GET['tahun'] ?? date('Y'));
    $bl = (int)($_GET['bulan'] ?? date('n'));
    if ($th < 2020 || $th > 2100) $th = (int)date('Y');
    if ($bl < 1 || $bl > 12) $bl = (int)date('n');
    return [$th, $bl];
}

// ============================================================
// ENDPOINT
// ============================================================
$aksi = $_GET['aksi'] ?? '';

if ($aksi !== '') {
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level()) ob_end_clean();
            if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['ok' => false, 'pesan' => GJ_DEBUG ? 'Error PHP: ' . $e['message'] : 'Terjadi kesalahan pada server.'], JSON_UNESCAPED_UNICODE);
        }
    });
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;

    // ── Slip gaji (halaman cetak, bukan JSON) ──
    if ($aksi === 'slip') {
        try {
            gjMigrasi($pdo);
            $g = gjAmbil($pdo, (int)($_GET['id'] ?? 0));
        } catch (Throwable $e) { $g = null; }
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/html; charset=utf-8');
        if (!$g) { http_response_code(404); echo '<!doctype html><meta charset="utf-8"><p style="font:16px Inter,Arial;padding:40px">Slip gaji tidak ditemukan.</p>'; exit; }
        gjCetakSlip($g);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    if (in_array($aksi, ['hitung', 'adj', 'status', 'status_semua', 'tarif', 'pengaturan', 'kirim_wa', 'wa_tes'], true) && !$isPost) {
        out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
    }

    try {
        gjMigrasi($pdo);
        switch ($aksi) {

            case 'data': {
                [$th, $bl] = gjPeriode();
                $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
                if ($th * 12 + $bl <= (int)$now->format('Y') * 12 + (int)$now->format('n')) gjHitung($pdo, $th, $bl, $now);   // bulan depan: belum ada yang dihitung
                $rows = gjDaftar($pdo, $th, $bl);
                out(['ok' => true, 'tahun' => $th, 'bulan' => $bl, 'label' => GJ_BULAN[$bl] . ' ' . $th, 'rows' => $rows, 'ringkas' => gjRingkas($rows),
                     'tarif' => ['standar' => gjTarifStandar($pdo), 'coach' => gjDaftarCoach($pdo), 'jenis' => GJ_JENIS],
                     'pengaturan' => ['auto_wa' => gjPengaturan($pdo, 'auto_wa', '1') === '1', 'hari_kirim' => (int)gjPengaturan($pdo, 'hari_kirim', '1')]]);
            }

            case 'hitung': {
                $th = (int)($in['tahun'] ?? 0); $bl = (int)($in['bulan'] ?? 0);
                if ($th < 2020 || $bl < 1 || $bl > 12) out(['ok' => false, 'pesan' => 'Periode tidak valid.'], 422);
                $n = gjHitung($pdo, $th, $bl);
                out(['ok' => true, 'pesan' => "Gaji dihitung ulang dari jadwal ($n draft diperbarui). Gaji yang sudah disetujui / dibayar tidak diubah."]);
            }

            case 'adj': {
                gjSimpanPenyesuaian($pdo, (int)($in['id'] ?? 0), (int)($in['bonus'] ?? 0), (int)($in['potongan'] ?? 0), (string)($in['catatan'] ?? ''));
                out(['ok' => true, 'pesan' => 'Bonus & potongan disimpan.']);
            }

            case 'status': {
                $a = (string)($in['aksi'] ?? '');
                $pesan = gjUbahStatus($pdo, (int)($in['id'] ?? 0), $a, ['metode' => $in['metode'] ?? 'transfer', 'tanggal' => $in['tanggal'] ?? date('Y-m-d')]);
                out(['ok' => true, 'pesan' => $pesan]);
            }

            case 'status_semua': {   // setujui semua draft pada periode
                $th = (int)($in['tahun'] ?? 0); $bl = (int)($in['bulan'] ?? 0);
                $n = 0;
                foreach (gjDaftar($pdo, $th, $bl) as $g) {
                    if ($g['status'] === 'draft' && $g['total'] > 0) { gjUbahStatus($pdo, $g['id'], 'setujui'); $n++; }
                }
                out(['ok' => true, 'pesan' => $n ? "$n gaji disetujui." : 'Tidak ada gaji Draft yang bisa disetujui.']);
            }

            case 'tarif': {
                $pid = (int)($in['pelatih_id'] ?? 0);
                $t = $in['tarif'] ?? null;
                if ($t !== null && $t !== '' && preg_match('/^\s*-/', (string)$t)) out(['ok' => false, 'pesan' => 'Tarif tidak boleh negatif.'], 422);
                $t = ($t === null || $t === '') ? null : (int)preg_replace('/\D+/', '', (string)$t);
                gjSimpanTarif($pdo, $pid, (string)($in['jenis'] ?? ''), $t);
                out(['ok' => true, 'pesan' => 'Tarif disimpan. Klik "Hitung Ulang" agar gaji Draft memakai tarif baru.']);
            }

            case 'pengaturan': {
                gjSimpanPengaturan($pdo, 'auto_wa', !empty($in['auto_wa']) ? '1' : '0');
                gjSimpanPengaturan($pdo, 'hari_kirim', (string)max(1, min(28, (int)($in['hari_kirim'] ?? 1))));
                out(['ok' => true, 'pesan' => 'Pengaturan pengiriman disimpan.']);
            }

            case 'kirim_wa': {
                [$ok, $pesan] = gjKirimWa($pdo, (int)($in['id'] ?? 0));
                out(['ok' => $ok, 'pesan' => $pesan], $ok ? 200 : 422);
            }

            case 'wa_status':
                out(['ok' => true] + gjStatusGateway());

            case 'wa_tes': {
                [$ok, $pesan] = gjTesWa((string)($in['nomor'] ?? ''));
                out(['ok' => $ok, 'pesan' => $pesan], $ok ? 200 : 422);
            }

            default:
                out(['ok' => false, 'pesan' => 'Aksi tidak dikenal.'], 400);
        }
    } catch (RuntimeException | InvalidArgumentException $e) {
        out(['ok' => false, 'pesan' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[gaji] ' . $e->getMessage());
        out(['ok' => false, 'pesan' => GJ_DEBUG ? 'Error server: ' . $e->getMessage() : 'Terjadi kesalahan pada server.'], 500);
    }
    exit;
}

// ─── Halaman slip gaji (siap cetak / simpan PDF dari browser) ───
function gjCetakSlip(array $g): void
{
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $periode = GJ_BULAN[$g['bulan']] . ' ' . $g['tahun'];
    $status = ['draft' => 'DRAFT', 'disetujui' => 'DISETUJUI', 'dibayar' => 'LUNAS'][$g['status']] ?? '';
    ?><!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Slip Gaji <?= $h($g['nama']) ?> — <?= $h($periode) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; } body { margin: 0; background: #f4f6fb; font-family: 'Inter', Arial, sans-serif; color: #111827; }
.bar { max-width: 760px; margin: 22px auto 0; display: flex; justify-content: flex-end; gap: 10px; padding: 0 16px; }
.bar button, .bar a { font: 600 14px 'Inter', Arial; padding: 10px 18px; border-radius: 12px; border: 1px solid #e5e7eb; background: #fff; color: #111827; cursor: pointer; text-decoration: none; }
.bar .p { background: #2563eb; border-color: #2563eb; color: #fff; }
.slip { max-width: 760px; margin: 16px auto 40px; background: #fff; border: 1px solid #eceef2; border-radius: 18px; padding: 36px 40px; position: relative; }
.top { display: flex; align-items: center; justify-content: space-between; gap: 16px; border-bottom: 2px solid #2563eb; padding-bottom: 18px; }
.brand { display: flex; align-items: center; gap: 12px; } .brand img { width: 44px; height: 44px; object-fit: contain; } .brand b { font-size: 20px; }
.top h1 { margin: 0; font-size: 18px; text-align: right; } .top small { display: block; color: #6b7280; font-weight: 500; margin-top: 2px; font-size: 13px; }
.meta { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; margin: 22px 0; font-size: 14px; }
.meta span { color: #9ca3af; display: inline-block; min-width: 92px; }
table { width: 100%; border-collapse: collapse; font-size: 14px; } th { text-align: left; font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; padding: 10px 8px; background: #f8fafc; }
td { padding: 11px 8px; border-bottom: 1px solid #f1f2f5; } .r { text-align: right; } th.r { text-align: right; }
.sum td { border: 0; padding: 6px 8px; } .sum .t td { border-top: 2px solid #111827; font-size: 17px; font-weight: 700; padding-top: 12px; }
.stamp { position: absolute; right: 40px; top: 120px; border: 3px solid #16a34a; color: #16a34a; font-weight: 800; letter-spacing: .12em; padding: 4px 14px; border-radius: 8px; transform: rotate(-8deg); opacity: .8; }
.stamp.d { border-color: #9ca3af; color: #9ca3af; } .stamp.s { border-color: #2563eb; color: #2563eb; }
.note { margin-top: 18px; font-size: 13px; color: #6b7280; } .foot { margin-top: 30px; display: flex; justify-content: space-between; font-size: 13px; color: #6b7280; }
.sign { text-align: center; min-width: 180px; } .sign i { display: block; height: 56px; border-bottom: 1px solid #9ca3af; margin-bottom: 6px; }
@media print { body { background: #fff; } .bar { display: none; } .slip { border: 0; margin: 0; padding: 0; max-width: none; } @page { margin: 16mm; } }
@media (max-width: 560px) { .slip { padding: 22px 18px; } .meta { grid-template-columns: 1fr; } .stamp { position: static; display: inline-block; margin-bottom: 10px; transform: none; } }
</style></head><body>
<div class="bar"><a href="gaji.php">← Kembali</a><button class="p" onclick="window.print()">Cetak / Simpan PDF</button></div>
<div class="slip">
  <div class="top"><div class="brand"><img src="assets/brand/navbar-logo.png" alt=""><b>Natix Swim</b></div><h1>SLIP GAJI COACH<small>Periode <?= $h($periode) ?></small></h1></div>
  <?php if ($status): ?><div class="stamp <?= $g['status'] === 'dibayar' ? '' : ($g['status'] === 'disetujui' ? 's' : 'd') ?>"><?= $h($status) ?></div><?php endif; ?>
  <div class="meta">
    <div><span>Nama</span><b><?= $h($g['nama']) ?></b></div>
    <div><span>Periode</span><?= $h($periode) ?></div>
    <div><span>No. WhatsApp</span><?= $h($g['hp'] ?: '-') ?></div>
    <div><span>Dibayar</span><?= $g['status'] === 'dibayar' ? $h(gjTglIndo($g['tanggal_bayar']) . ' · ' . (GJ_METODE[$g['metode']] ?? $g['metode'])) : '-' ?></div>
  </div>
  <table>
    <thead><tr><th>Jenis Kelas</th><th class="r">Sesi</th><th class="r">Tarif / Sesi</th><th class="r">Subtotal</th></tr></thead>
    <tbody>
    <?php foreach ($g['rincian'] as $r): ?>
      <tr><td><?= $h($r['label']) ?></td><td class="r"><?= (int)$r['sesi'] ?></td><td class="r"><?= $h(gjRp($r['tarif'])) ?></td><td class="r"><?= $h(gjRp($r['subtotal'])) ?></td></tr>
    <?php endforeach; if (!$g['rincian']): ?><tr><td colspan="4" style="color:#9ca3af">Belum ada sesi mengajar pada periode ini.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <table class="sum" style="margin-top:10px">
    <tr><td class="r">Gaji sesi (<?= (int)$g['jml_sesi'] ?> sesi)</td><td class="r" style="width:160px"><?= $h(gjRp($g['gaji_sesi'])) ?></td></tr>
    <tr><td class="r">Bonus</td><td class="r">+ <?= $h(gjRp($g['bonus'])) ?></td></tr>
    <tr><td class="r">Potongan</td><td class="r">− <?= $h(gjRp($g['potongan'])) ?></td></tr>
    <tr class="t"><td class="r">Total diterima</td><td class="r"><?= $h(gjRp($g['total'])) ?></td></tr>
  </table>
  <?php if ($g['catatan'] !== ''): ?><p class="note"><b>Catatan:</b> <?= $h($g['catatan']) ?></p><?php endif; ?>
  <div class="foot"><div>Dicetak <?= $h(date('j') . ' ' . GJ_BULAN[(int)date('n')] . ' ' . date('Y')) ?><br>Dokumen ini dibuat otomatis oleh sistem Natix Swim.</div><div class="sign"><i></i>Admin Natix Swim</div></div>
</div></body></html><?php
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
                ['Simulator Chat', 'simulator-wa.php', 'chat', [], false],
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
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Gaji Coach — Natix Swim</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/reset.css" />
    <link rel="stylesheet" href="css/global.css" />
    <link rel="stylesheet" href="css/cdm-segmentasi.css" />
    <style>
        /* Semua class halaman ini memakai prefix "gj-" supaya tidak bentrok dengan CSS export Figma */
        .main-content { position: relative !important; height: auto !important; width: auto !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; box-sizing: border-box; min-width: 0; }
        .gj-page, .gj-page *, .gj-overlay, .gj-overlay * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
        .gj-page p, .gj-page h1, .gj-page h2, .gj-page h3 { margin: 0; }
        .gj-page { padding: 28px 32px 48px; color: #111827; width: 100%; max-width: 100%; min-width: 0; }

        /* Header */
        .gj-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-top: 6px; }
        .gj-title { font-size: 30px; font-weight: 700; line-height: 1.2; }
        .gj-sub { font-size: 15px; color: #6b7280; margin-top: 4px !important; }
        .gj-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .gj-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; white-space: nowrap; font-size: 14px; font-weight: 600; border-radius: 12px; padding: 11px 18px; cursor: pointer; border: 1px solid #e5e7eb; background: #fff; color: #111827; transition: background .15s; text-decoration: none; }
        .gj-btn:hover { background: #f9fafb; }
        .gj-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        .gj-btn.primary:hover { background: #1d4ed8; }
        .gj-btn.green { background: #16a34a; border-color: #16a34a; color: #fff; }
        .gj-btn.green:hover { background: #15803d; }
        .gj-btn.danger { border-color: #fca5a5; color: #dc2626; }
        .gj-btn.danger:hover { background: #fef2f2; }
        .gj-btn.danger.confirm { background: #dc2626; color: #fff; border-color: #dc2626; }
        .gj-btn.sm { padding: 7px 14px; font-size: 13px; border-radius: 10px; }
        .gj-btn:disabled { opacity: .55; cursor: not-allowed; }
        .gj-month { display: inline-flex; align-items: center; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
        .gj-month button { border: 0; background: transparent; width: 40px; height: 42px; cursor: pointer; font-size: 18px; color: #6b7280; }
        .gj-month button:hover { background: #f3f4f6; }
        .gj-month strong { min-width: 150px; text-align: center; font-size: 14px; font-weight: 600; padding: 0 6px; }

        /* Kartu ringkasan */
        .gj-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin: 26px 0 22px; }
        .gj-stat { background: #fff; border: 1px solid #eceef2; border-radius: 16px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; min-width: 0; min-height: 92px; }
        .gj-ico { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .gj-ico svg { width: 22px; height: 22px; }
        .gj-ico.blue { background: #eef4ff; color: #2563eb; } .gj-ico.green { background: #eafaf1; color: #16a34a; }
        .gj-ico.orange { background: #fff3e6; color: #ea6a0a; } .gj-ico.violet { background: #f1eefe; color: #6d4aff; }
        .gj-stat div { min-width: 0; }
        .gj-stat p { font-size: 14px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .gj-stat h2 { font-size: 24px; font-weight: 700; line-height: 1.15; margin-top: 4px !important; white-space: nowrap; }
        .gj-stat small { display: block; font-size: 12px; color: #9ca3af; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Tab */
        .gj-tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .gj-tab { border: 1px solid #e5e7eb; background: #fff; color: #6b7280; font-size: 14px; font-weight: 600; padding: 9px 18px; border-radius: 999px; cursor: pointer; }
        .gj-tab:hover { background: #f9fafb; }
        .gj-tab.is-on { background: #111827; border-color: #111827; color: #fff; }
        .gj-panel { display: none; } .gj-panel.is-on { display: block; }

        /* Kartu / tabel */
        .gj-card { background: #fff; border: 1px solid #eceef2; border-radius: 18px; overflow-x: auto; overflow-y: hidden; }
        .gj-card-h { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 18px 22px; border-bottom: 1px solid #f1f2f5; }
        .gj-card-h h3 { font-size: 16px; font-weight: 700; } .gj-card-h small { color: #9ca3af; font-size: 13px; font-weight: 500; }
        .gj-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .gj-table th { text-align: left; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #6b7280; padding: 12px 16px; background: #fafafb; border-bottom: 1px solid #eceef2; white-space: nowrap; }
        .gj-table td { padding: 14px 16px; border-bottom: 1px solid #f1f2f5; vertical-align: middle; }
        .gj-table tr:last-child td { border-bottom: 0; }
        .gj-table tbody tr.gj-row { cursor: pointer; transition: background .12s; } .gj-table tbody tr.gj-row:hover { background: #f8fafd; }
        .gj-table .r { text-align: right; } .gj-table th.r { text-align: right; }
        .gj-coach { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .gj-av { width: 38px; height: 38px; border-radius: 50%; background: #eef2ff; color: #2563eb; font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .gj-coach b { display: block; font-weight: 600; } .gj-coach small { display: block; color: #9ca3af; font-size: 12.5px; margin-top: 1px; }
        .gj-chips { display: flex; gap: 6px; flex-wrap: wrap; }
        .gj-chip { font-size: 12px; font-weight: 600; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
        .gj-chip.kids { background: #eef2ff; color: #1e3a8a; } .gj-chip.dewasa { background: #e8f7ee; color: #14532d; } .gj-chip.private { background: #fff3e0; color: #7c4a03; }
        .gj-pos { color: #16a34a; } .gj-neg { color: #dc2626; } .gj-mut { color: #9ca3af; }
        .gj-total { font-weight: 700; }
        .gj-tag { display: inline-block; font-size: 12px; font-weight: 600; padding: 4px 11px; border-radius: 999px; white-space: nowrap; }
        .gj-tag.draft { background: #f3f4f6; color: #6b7280; } .gj-tag.disetujui { background: #eef2ff; color: #2563eb; } .gj-tag.dibayar { background: #dcfce7; color: #16a34a; }
        .gj-tag.belum { background: #f3f4f6; color: #9ca3af; } .gj-tag.terkirim { background: #dcfce7; color: #16a34a; } .gj-tag.gagal { background: #fee2e2; color: #dc2626; }
        .gj-empty { padding: 56px 16px; text-align: center; color: #9ca3af; font-size: 14px; }
        .gj-empty b { display: block; color: #6b7280; font-size: 15px; margin-bottom: 4px; }
        .gj-foot-note { padding: 14px 22px; border-top: 1px solid #f1f2f5; font-size: 13px; color: #6b7280; background: #fcfcfd; }

        /* Tarif */
        .gj-input { width: 100%; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; font-size: 14px; color: #111827; background: #fff; }
        .gj-input:focus { outline: 2px solid #bfdbfe; border-color: #93c5fd; }
        .gj-input:disabled { background: #f9fafb; color: #6b7280; }
        .gj-rp { position: relative; } .gj-rp span { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 13px; color: #9ca3af; pointer-events: none; }
        .gj-rp .gj-input { padding-left: 36px; text-align: right; }
        .gj-table .gj-rp { min-width: 130px; }
        tr.gj-std td { background: #f8fafc; font-weight: 600; }
        .gj-saved { box-shadow: 0 0 0 2px #86efac; transition: box-shadow .3s; }

        /* WhatsApp */
        .gj-wa { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; }
        .gj-box { padding: 22px; }
        .gj-box h3 { font-size: 16px; font-weight: 700; margin-bottom: 6px; } .gj-box p { font-size: 14px; color: #6b7280; line-height: 1.55; }
        .gj-switch { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 14px 0; border-bottom: 1px solid #f1f2f5; }
        .gj-switch b { display: block; font-size: 14px; font-weight: 600; } .gj-switch small { color: #9ca3af; font-size: 13px; }
        .gj-tg { position: relative; width: 46px; height: 26px; flex: 0 0 auto; }
        .gj-tg input { opacity: 0; width: 100%; height: 100%; position: absolute; inset: 0; margin: 0; cursor: pointer; z-index: 1; }
        .gj-tg i { position: absolute; inset: 0; background: #d1d5db; border-radius: 999px; transition: background .15s; }
        .gj-tg i::after { content: ''; position: absolute; left: 3px; top: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: transform .15s; }
        .gj-tg input:checked + i { background: #2563eb; } .gj-tg input:checked + i::after { transform: translateX(20px); }
        .gj-bubble { background: #e7f6ec; border-radius: 14px 14px 14px 4px; padding: 14px 16px; font-size: 13.5px; line-height: 1.55; color: #14321f; white-space: pre-wrap; margin-top: 14px; }
        .gj-cmd { display: inline-block; background: #f3f4f6; border-radius: 8px; padding: 2px 9px; font-weight: 600; color: #111827; font-size: 13px; }
        .gj-wa-full { grid-column: 1 / -1; }
        .gj-gw { padding: 18px 22px; }
        .gj-gw-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .gj-gw-dot { width: 12px; height: 12px; border-radius: 50%; background: #d1d5db; flex: 0 0 auto; box-shadow: 0 0 0 4px #f3f4f6; }
        .gj-gw-dot.ok { background: #16a34a; box-shadow: 0 0 0 4px #dcfce7; } .gj-gw-dot.wait { background: #e8890c; box-shadow: 0 0 0 4px #ffedd5; } .gj-gw-dot.off { background: #dc2626; box-shadow: 0 0 0 4px #fee2e2; }
        .gj-gw-txt { flex: 1 1 240px; min-width: 0; } .gj-gw-txt b { display: block; font-size: 15px; } .gj-gw-txt small { display: block; color: #6b7280; font-size: 13px; margin-top: 2px; line-height: 1.45; }
        .gj-gw-txt a { color: #2563eb; text-decoration: none; font-weight: 600; }
        .gj-gw-tes { display: flex; gap: 10px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #f1f2f5; flex-wrap: wrap; }
        .gj-gw-tes .gj-input { flex: 1 1 240px; min-width: 0; }

        /* Modal */
        .gj-overlay { position: fixed; inset: 0; background: rgba(17,24,39,.45); display: none; align-items: center; justify-content: center; padding: 20px; z-index: 1300; }
        .gj-overlay.is-open { display: flex; }
        .gj-modal { background: #fff; border-radius: 18px; width: 100%; max-width: 560px; max-height: calc(100vh - 40px); overflow-y: auto; padding: 26px; box-shadow: 0 24px 60px rgba(16,24,40,.25); }
        .gj-modal h2 { font-size: 20px; font-weight: 700; }
        .gj-modal-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
        .gj-modal-top p { font-size: 14px; color: #6b7280; margin-top: 3px; }
        .gj-x { border: 0; background: #f3f4f6; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 18px; line-height: 1; color: #6b7280; flex: 0 0 auto; }
        .gj-x:hover { background: #e5e7eb; }
        .gj-rin { border: 1px solid #f1f2f5; border-radius: 12px; overflow: hidden; margin-bottom: 18px; }
        .gj-rin .gj-table th, .gj-rin .gj-table td { padding: 10px 12px; }
        .gj-form { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .gj-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .gj-field.full { grid-column: 1 / -1; }
        .gj-field label { font-size: 13px; font-weight: 600; color: #374151; }
        .gj-sum { margin: 18px 0 0; border-top: 1px dashed #e5e7eb; padding-top: 14px; display: grid; gap: 6px; font-size: 14px; }
        .gj-sum div { display: flex; justify-content: space-between; gap: 10px; }
        .gj-sum .t { font-size: 18px; font-weight: 700; padding-top: 8px; border-top: 1px solid #111827; margin-top: 4px; }
        .gj-bayar { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; margin-top: 16px; }
        .gj-bayar h4 { margin: 0 0 10px; font-size: 14px; }
        .gj-mfoot { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; flex-wrap: wrap; }
        .gj-info { font-size: 13px; color: #6b7280; margin-top: 12px; line-height: 1.5; }
        .gj-err { color: #dc2626; font-size: 13px; margin-top: 10px; min-height: 0; }

        /* Toast */
        .gj-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(20px); background: #111827; color: #fff; padding: 12px 20px; border-radius: 12px; font-size: 14px; opacity: 0; pointer-events: none; transition: all .25s; z-index: 1500; max-width: calc(100vw - 32px); }
        .gj-toast.is-show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .gj-toast.err { background: #dc2626; }

        @media (max-width: 1200px) { .gj-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .gj-wa { grid-template-columns: 1fr; } }
        @media (max-width: 900px) {
            .gj-page { padding: 24px 20px 40px; }
            .gj-table thead { display: none; }
            .gj-table, .gj-table tbody, .gj-table tr, .gj-table td { display: block; width: 100%; }
            .gj-table tbody tr.gj-row { padding: 14px 16px; border-bottom: 1px solid #f1f2f5; }
            .gj-table tbody tr.gj-row td { border: 0; padding: 4px 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; text-align: right; }
            .gj-table tbody tr.gj-row td::before { content: attr(data-l); font-size: 12px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: .04em; text-align: left; flex: 0 0 auto; }
            .gj-table tbody tr.gj-row td.gj-c0 { display: block; padding-bottom: 8px; text-align: left; } .gj-table tbody tr.gj-row td.gj-c0::before { display: none; }
            .gj-table tbody tr.gj-row td.r { text-align: right; }
            .gj-tarif .gj-table tr { padding: 12px 16px; border-bottom: 1px solid #f1f2f5; } .gj-tarif .gj-table td { border: 0; padding: 4px 0; }
            .gj-tarif .gj-table td[data-l]::before { content: attr(data-l); display: block; font-size: 12px; font-weight: 600; color: #9ca3af; margin-bottom: 4px; }
        }
        @media (max-width: 640px) {
            .gj-page { padding: 20px 16px 40px; }
            .gj-title { font-size: 24px; }
            .gj-stats { grid-template-columns: 1fr; }
            .gj-actions { width: 100%; } .gj-actions .gj-btn { flex: 1 1 auto; } .gj-month { width: 100%; } .gj-month strong { flex: 1; }
            .gj-form { grid-template-columns: 1fr; } .gj-modal { padding: 20px 16px; }
            .gj-mfoot .gj-btn { flex: 1 1 auto; }
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

</style>
</head>
<body>

    <?php nvSidebar(); ?>

    <div class="main">
        <header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div><a class="nv-pf" href="manajemen-ai-chat.php">Buka Chat Log</a></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div></header>

        <div class="main-content">
            <div class="gj-page">
                <?= nvCrumb() ?>

                <div class="gj-head">
                    <div>
                        <h1 class="gj-title">Payroll Coach</h1>
                        <p class="gj-sub">Gaji dihitung otomatis dari jumlah sesi mengajar × tarif per sesi.</p>
                    </div>
                    <div class="gj-actions">
                        <div class="gj-month">
                            <button type="button" id="gj-prev" aria-label="Bulan sebelumnya">‹</button>
                            <strong id="gj-label">Memuat…</strong>
                            <button type="button" id="gj-next" aria-label="Bulan berikutnya">›</button>
                        </div>
                        <button class="gj-btn" id="gj-hitung" type="button">Hitung Ulang</button>
                        <button class="gj-btn primary" id="gj-setujui-semua" type="button">Setujui Semua</button>
                    </div>
                </div>

                <div class="gj-stats">
                    <div class="gj-stat"><span class="gj-ico blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 012-2h13v4"/><path d="M3 7v11a2 2 0 002 2h15a1 1 0 001-1v-3"/><path d="M21 9h-4a2 2 0 000 4h4z"/></svg></span><div><p>Total Gaji Bulan Ini</p><h2 id="gj-s-total">–</h2><small id="gj-s-total-n">&nbsp;</small></div></div>
                    <div class="gj-stat"><span class="gj-ico green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/></svg></span><div><p>Sudah Dibayar</p><h2 id="gj-s-bayar">–</h2><small id="gj-s-bayar-n">&nbsp;</small></div></div>
                    <div class="gj-stat"><span class="gj-ico orange"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><div><p>Menunggu Pembayaran</p><h2 id="gj-s-belum">–</h2><small id="gj-s-belum-n">&nbsp;</small></div></div>
                    <div class="gj-stat"><span class="gj-ico violet"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="16" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg></span><div><p>Total Sesi Mengajar</p><h2 id="gj-s-sesi">–</h2><small id="gj-s-coach">&nbsp;</small></div></div>
                </div>

                <div class="gj-tabs" role="tablist">
                    <button class="gj-tab is-on" data-tab="gaji" type="button">Gaji Bulanan</button>
                    <button class="gj-tab" data-tab="tarif" type="button">Tarif per Sesi</button>
                    <button class="gj-tab" data-tab="wa" type="button">Slip &amp; WhatsApp</button>
                </div>

                <!-- Gaji bulanan -->
                <div class="gj-panel is-on" id="gj-p-gaji">
                    <div class="gj-card">
                        <table class="gj-table">
                            <thead><tr><th>Coach</th><th>Sesi</th><th class="r">Gaji Sesi</th><th class="r">Bonus</th><th class="r">Potongan</th><th class="r">Total</th><th>Status</th><th>Slip WA</th></tr></thead>
                            <tbody id="gj-tbody"><tr><td colspan="8" class="gj-empty">Memuat…</td></tr></tbody>
                        </table>
                        <div class="gj-foot-note">Klik baris untuk melihat rincian, mengubah bonus / potongan, menyetujui, dan membayar.</div>
                    </div>
                </div>

                <!-- Tarif -->
                <div class="gj-panel gj-tarif" id="gj-p-tarif">
                    <div class="gj-card">
                        <div class="gj-card-h"><h3>Tarif per Sesi <small>· dalam Rupiah</small></h3><small>Kosongkan kolom coach untuk memakai tarif standar</small></div>
                        <table class="gj-table">
                            <thead><tr><th>Coach</th><th>Kids</th><th>Dewasa</th><th>Private</th></tr></thead>
                            <tbody id="gj-tarif-body"></tbody>
                        </table>
                        <div class="gj-foot-note">Perubahan tarif tersimpan otomatis dan hanya berlaku untuk perhitungan berikutnya. Gaji yang sudah disetujui / dibayar tidak ikut berubah.</div>
                    </div>
                </div>

                <!-- WhatsApp -->
                <div class="gj-panel" id="gj-p-wa">
                    <div class="gj-wa">
                        <div class="gj-card gj-wa-full">
                            <div class="gj-card-h"><h3>Status <small id="gj-wa-periode"></small></h3><button class="gj-btn sm primary" id="gj-kirim-semua" type="button">Kirim Semua yang Belum Terkirim</button></div>
                            <table class="gj-table">
                                <thead><tr><th>Coach</th><th>No. WhatsApp</th><th>Status Gaji</th><th>Status Kirim</th><th>Keterangan</th><th></th></tr></thead>
                                <tbody id="gj-wa-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="gj-overlay" id="gj-overlay"><div class="gj-modal" id="gj-modal"></div></div>
    <div class="gj-toast" id="gj-toast"></div>

<script>
// ============================================================
// PENGGAJIAN COACH
// ============================================================
const $ = id => document.getElementById(id);
const BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const JENIS = { kids: 'Kids', dewasa: 'Dewasa', private: 'Private' };
const STATUS = { draft: 'Draft', disetujui: 'Disetujui', dibayar: 'Dibayar' };
const WA = { belum: 'Belum dikirim', terkirim: 'Terkirim', gagal: 'Gagal' };
const NOW = new Date();
const S = { tahun: NOW.getFullYear(), bulan: NOW.getMonth() + 1, data: null, aktif: null, sibuk: false, gw: null };

function esc(t) { const d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
function rp(n) { return 'Rp ' + Math.round(+n || 0).toLocaleString('id-ID'); }
function angka(v) { return parseInt(String(v == null ? '' : v).replace(/\D+/g, ''), 10) || 0; }
function inisial(n) { return String(n).replace(/^coach\s+/i, '').trim().split(/\s+/).slice(0, 2).map(x => x[0]).join('').toUpperCase() || '?'; }
function tglIndo(s) { if (!s) return '-'; const p = String(s).slice(0, 10).split('-'); return +p[2] + ' ' + BULAN[+p[1]].slice(0, 3) + ' ' + p[0]; }

let toastT;
function toast(t, err) {
    const el = $('gj-toast'); el.textContent = t; el.className = 'gj-toast is-show' + (err ? ' err' : '');
    clearTimeout(toastT); toastT = setTimeout(() => el.classList.remove('is-show'), err ? 5000 : 2800);
}

async function api(aksi, body, qs) {
    const url = 'gaji.php?aksi=' + aksi + (qs ? '&' + qs : '');
    const opt = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {};
    let res, j;
    try { res = await fetch(url, opt); } catch (e) { throw new Error('Tidak bisa menghubungi server.'); }
    try { j = await res.json(); } catch (e) { throw new Error('Respons server tidak valid (HTTP ' + res.status + ').'); }
    if (!j.ok) throw new Error(j.pesan || 'Terjadi kesalahan.');
    return j;
}

// ─── Muat data ───
async function muat() {
    try {
        S.data = await api('data', null, 'tahun=' + S.tahun + '&bulan=' + S.bulan);
    } catch (e) { toast(e.message, true); $('gj-tbody').innerHTML = '<tr><td colspan="8" class="gj-empty"><b>Gagal memuat data</b>' + esc(e.message) + '</td></tr>'; return; }
    render();
}

function render() {
    const d = S.data, r = d.ringkas;
    $('gj-label').textContent = d.label;
    $('gj-s-total').textContent = rp(r.total);
    $('gj-s-total-n').textContent = r.coach + ' coach';
    $('gj-s-bayar').textContent = rp(r.dibayar);
    $('gj-s-bayar-n').textContent = r.lunas + ' dari ' + r.coach + ' coach';
    $('gj-s-belum').textContent = rp(r.belum);
    $('gj-s-belum-n').textContent = (r.draft + r.disetujui) + ' gaji · ' + r.draft + ' draft, ' + r.disetujui + ' disetujui';
    $('gj-s-sesi').textContent = r.sesi;
    $('gj-s-coach').textContent = 'sesi sudah berlalu';
    $('gj-setujui-semua').disabled = !r.draft;
    renderGaji(); renderTarif(); renderWa();
    if (S.aktif) { const g = d.rows.find(x => x.id === S.aktif); if (g && $('gj-overlay').classList.contains('is-open')) detail(g.id, true); }
}

function renderGaji() {
    const rows = S.data.rows;
    if (!rows.length) {
        $('gj-tbody').innerHTML = '<tr><td colspan="8" class="gj-empty"><b>Belum ada gaji pada ' + esc(S.data.label) + '</b>Belum ada sesi mengajar yang sudah berlalu. Pastikan jadwal & pelatih sudah terisi, lalu klik “Hitung Ulang”.</td></tr>';
        return;
    }
    $('gj-tbody').innerHTML = rows.map(g => {
        const chips = Object.keys(JENIS).filter(k => g.sesi[k] > 0).map(k => '<span class="gj-chip ' + k + '">' + JENIS[k] + ' ' + g.sesi[k] + '</span>').join('') || '<span class="gj-mut">–</span>';
        return '<tr class="gj-row" data-id="' + g.id + '">'
            + '<td class="gj-c0"><div class="gj-coach"><span class="gj-av">' + esc(inisial(g.nama)) + '</span><div><b>' + esc(g.nama) + '</b><small>' + g.jml_sesi + ' sesi</small></div></div></td>'
            + '<td data-l="Sesi"><div class="gj-chips">' + chips + '</div></td>'
            + '<td class="r" data-l="Gaji Sesi">' + rp(g.gaji_sesi) + '</td>'
            + '<td class="r ' + (g.bonus ? 'gj-pos' : 'gj-mut') + '" data-l="Bonus">' + (g.bonus ? '+ ' + rp(g.bonus) : '–') + '</td>'
            + '<td class="r ' + (g.potongan ? 'gj-neg' : 'gj-mut') + '" data-l="Potongan">' + (g.potongan ? '− ' + rp(g.potongan) : '–') + '</td>'
            + '<td class="r gj-total" data-l="Total">' + rp(g.total) + '</td>'
            + '<td data-l="Status"><span class="gj-tag ' + g.status + '">' + STATUS[g.status] + '</span></td>'
            + '<td data-l="Slip WA">' + (g.status === 'dibayar' ? '<span class="gj-tag ' + g.wa_status + '">' + WA[g.wa_status] + '</span>' : '<span class="gj-mut">–</span>') + '</td>'
            + '</tr>';
    }).join('');
}

// ─── Tarif ───
function renderTarif() {
    const t = S.data.tarif, ks = Object.keys(JENIS);
    const sel = (pid, k, val, ph) => '<div class="gj-rp"><span>Rp</span><input class="gj-input" inputmode="numeric" data-pid="' + pid + '" data-k="' + k + '" value="' + (val == null ? '' : (+val).toLocaleString('id-ID')) + '" placeholder="' + esc(ph || '') + '"></div>';
    let h = '<tr class="gj-std"><td data-l="Coach">Tarif standar (semua coach)</td>' + ks.map(k => '<td data-l="' + JENIS[k] + '">' + sel(0, k, t.standar[k]) + '</td>').join('') + '</tr>';
    h += t.coach.map(c => '<tr><td data-l="Coach"><div class="gj-coach"><span class="gj-av">' + esc(inisial(c.nama)) + '</span><b>' + esc(c.nama) + '</b></div></td>'
        + ks.map(k => '<td data-l="' + JENIS[k] + '">' + sel(c.pelatih_id, k, c.tarif[k], 'Standar ' + (+t.standar[k]).toLocaleString('id-ID')) + '</td>').join('') + '</tr>').join('');
    $('gj-tarif-body').innerHTML = h;
}

$('gj-tarif-body').addEventListener('input', e => {
    const el = e.target; if (!el.matches('input[data-pid]')) return;
    const v = angka(el.value); el.value = el.value.trim() === '' ? '' : v.toLocaleString('id-ID');
});
$('gj-tarif-body').addEventListener('change', async e => {
    const el = e.target; if (!el.matches('input[data-pid]')) return;
    const kosong = el.value.trim() === '';
    if (kosong && el.dataset.pid === '0') { toast('Tarif standar tidak boleh kosong.', true); renderTarif(); return; }
    try {
        const j = await api('tarif', { pelatih_id: +el.dataset.pid, jenis: el.dataset.k, tarif: kosong ? null : angka(el.value) });
        el.classList.add('gj-saved'); setTimeout(() => el.classList.remove('gj-saved'), 900); toast(j.pesan);
        const d = S.data.tarif;   // sinkronkan state lokal agar placeholder ikut berubah
        if (el.dataset.pid === '0') { d.standar[el.dataset.k] = angka(el.value); renderTarif(); }
        else { const c = d.coach.find(x => x.pelatih_id === +el.dataset.pid); if (c) c.tarif[el.dataset.k] = kosong ? null : angka(el.value); }
    } catch (err) { toast(err.message, true); muat(); }
});

// ─── WhatsApp ───
function contoh() {
    const g = S.data.rows.find(x => x.status === 'dibayar') || S.data.rows[0];
    const bln = BULAN[S.data.bulan] + ' ' + S.data.tahun;
    if (!g) return '*SLIP GAJI COACH — NATIX SWIM*\nPeriode: ' + bln + '\n\nBelum ada data gaji pada periode ini.';
    let o = '*SLIP GAJI COACH — NATIX SWIM*\nPeriode: ' + bln + '\nNama: ' + g.nama + '\n\n*Rincian Mengajar*\n';
    g.rincian.filter(r => r.sesi > 0).forEach(r => { o += '• ' + r.label + ': ' + r.sesi + ' sesi × ' + rp(r.tarif) + ' = ' + rp(r.subtotal) + '\n'; });
    o += '\nGaji sesi: ' + rp(g.gaji_sesi) + '\n';
    if (g.bonus) o += 'Bonus: + ' + rp(g.bonus) + '\n';
    if (g.potongan) o += 'Potongan: − ' + rp(g.potongan) + '\n';
    o += '*Total diterima: ' + rp(g.total) + '*\n\n';
    o += g.status === 'dibayar' ? 'Status: Dibayar ' + tglIndo(g.tanggal_bayar) + '\n' : g.status === 'disetujui' ? 'Status: Disetujui, menunggu pembayaran.\n' : 'Status: ESTIMASI SEMENTARA — belum disetujui admin, angka bisa berubah.\n';
    return o + '\nTerima kasih atas dedikasinya 🙏';
}

function renderWa() {
    $('gj-wa-periode').textContent = '· ' + S.data.label;
    const rows = S.data.rows;
    $('gj-wa-body').innerHTML = rows.length ? rows.map(g => {
        const bisa = g.status === 'dibayar';
        const gwOk = S.gw && S.gw.state === 'open';
        const ket = g.wa_status === 'terkirim' ? 'Terkirim ' + esc((g.wa_waktu || '').slice(0, 16).replace('T', ' ')) : g.wa_status === 'gagal' ? esc(g.wa_error) : (bisa ? 'Menunggu dikirim' : 'Menunggu pembayaran gaji');
        return '<tr class="gj-row" style="cursor:default"><td class="gj-c0"><div class="gj-coach"><span class="gj-av">' + esc(inisial(g.nama)) + '</span><b>' + esc(g.nama) + '</b></div></td>'
            + '<td data-l="No. WhatsApp">' + (g.hp ? esc(g.hp) : '<span class="gj-neg">Belum diisi</span>') + '</td>'
            + '<td data-l="Status Gaji"><span class="gj-tag ' + g.status + '">' + STATUS[g.status] + '</span></td>'
            + '<td data-l="Status Kirim">' + (bisa ? '<span class="gj-tag ' + g.wa_status + '">' + WA[g.wa_status] + '</span>' : '<span class="gj-mut">–</span>') + '</td>'
            + '<td data-l="Keterangan" style="color:#6b7280;font-size:13px">' + ket + '</td>'
            + '<td class="r"><button class="gj-btn sm" data-kirim="' + g.id + '" type="button"' + (bisa && gwOk ? '' : ' disabled') + (bisa && !gwOk ? ' title="WhatsApp belum terhubung"' : '') + '>' + (g.wa_status === 'terkirim' ? 'Kirim Ulang' : 'Kirim') + '</button></td></tr>';
    }).join('') : '<tr><td colspan="6" class="gj-empty">Belum ada data gaji pada periode ini.</td></tr>';
    $('gj-kirim-semua').disabled = !(S.gw && S.gw.state === 'open') || !rows.some(g => g.status === 'dibayar' && g.wa_status !== 'terkirim');
}

async function kirim(id, tombol) {
    if (tombol) { tombol.disabled = true; tombol.textContent = 'Mengirim…'; }
    try { const j = await api('kirim_wa', { id }); toast(j.pesan); }
    catch (e) { toast(e.message, true); }
    await muat();
}
$('gj-wa-body').addEventListener('click', e => { const b = e.target.closest('[data-kirim]'); if (b) kirim(+b.dataset.kirim, b); });
$('gj-kirim-semua').addEventListener('click', async e => {
    const b = e.currentTarget; b.disabled = true; b.textContent = 'Mengirim…';
    let ok = 0, gagal = 0;
    for (const g of S.data.rows.filter(x => x.status === 'dibayar' && x.wa_status !== 'terkirim')) {
        try { await api('kirim_wa', { id: g.id }); ok++; } catch (err) { gagal++; }
    }
    b.textContent = 'Kirim Semua yang Belum Terkirim';
    toast(ok + ' slip terkirim' + (gagal ? ', ' + gagal + ' gagal (lihat keterangan)' : '') + '.', gagal > 0);
    muat();
});
// ─── Status koneksi WhatsApp (tanpa tampilan; hanya menentukan tombol kirim aktif/tidak) ───
async function gwCek() {
    try { S.gw = await api('wa_status'); } catch (e) { S.gw = { state: 'mati', pesan: e.message }; }
    if (S.data) renderWa();
}

// ─── Detail / modal ───
function tutup() { $('gj-overlay').classList.remove('is-open'); S.aktif = null; }
$('gj-overlay').addEventListener('mousedown', e => { if (e.target === $('gj-overlay')) tutup(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') tutup(); });
$('gj-tbody').addEventListener('click', e => { const tr = e.target.closest('tr[data-id]'); if (tr) detail(+tr.dataset.id); });

function detail(id, tanpaReset) {
    const g = S.data.rows.find(x => x.id === id); if (!g) return;
    S.aktif = id;
    const draft = g.status === 'draft';
    const tabel = g.rincian.length ? g.rincian.map(r => '<tr><td>' + esc(r.label) + '</td><td class="r">' + r.sesi + '</td><td class="r">' + rp(r.tarif) + '</td><td class="r">' + rp(r.subtotal) + '</td></tr>').join('')
        : '<tr><td colspan="4" class="gj-mut">Belum ada sesi mengajar.</td></tr>';
    let foot = '';
    if (g.status === 'draft') foot = '<button class="gj-btn" data-a="simpan" type="button">Simpan Penyesuaian</button><button class="gj-btn primary" data-a="setujui" type="button">Setujui Gaji</button>';
    else if (g.status === 'disetujui') foot = '<button class="gj-btn danger" data-a="batal_setujui" type="button">Kembalikan ke Draft</button><button class="gj-btn green" data-a="bayar_form" type="button">Tandai Dibayar</button>';
    else foot = '<button class="gj-btn danger" data-a="batal_bayar" type="button">Batalkan Pembayaran</button><button class="gj-btn" data-a="wa" type="button">' + (g.wa_status === 'terkirim' ? 'Kirim Ulang WhatsApp' : 'Kirim ke WhatsApp') + '</button>';
    foot += '<a class="gj-btn" target="_blank" rel="noopener" href="gaji.php?aksi=slip&id=' + g.id + '">Cetak Slip</a>';
    $('gj-modal').innerHTML =
        '<div class="gj-modal-top"><div><h2>' + esc(g.nama) + '</h2><p>Gaji ' + esc(S.data.label) + ' · <span class="gj-tag ' + g.status + '">' + STATUS[g.status] + '</span>'
        + (g.status === 'dibayar' ? ' · dibayar ' + tglIndo(g.tanggal_bayar) + ' (' + esc(g.metode) + ')' : '') + '</p></div><button class="gj-x" type="button" data-a="tutup" aria-label="Tutup">×</button></div>'
        + '<div class="gj-rin"><table class="gj-table"><thead><tr><th>Jenis Kelas</th><th class="r">Sesi</th><th class="r">Tarif</th><th class="r">Subtotal</th></tr></thead><tbody>' + tabel + '</tbody></table></div>'
        + '<div class="gj-form">'
        + '<div class="gj-field"><label>Bonus</label><div class="gj-rp"><span>Rp</span><input class="gj-input" id="gj-bonus" inputmode="numeric" value="' + (g.bonus ? g.bonus.toLocaleString('id-ID') : '') + '" placeholder="0"' + (draft ? '' : ' disabled') + '></div></div>'
        + '<div class="gj-field"><label>Potongan</label><div class="gj-rp"><span>Rp</span><input class="gj-input" id="gj-pot" inputmode="numeric" value="' + (g.potongan ? g.potongan.toLocaleString('id-ID') : '') + '" placeholder="0"' + (draft ? '' : ' disabled') + '></div></div>'
        + '<div class="gj-field full"><label>Catatan <span class="gj-mut" style="font-weight:400">(muncul di slip)</span></label><input class="gj-input" id="gj-cat" maxlength="250" value="' + esc(g.catatan) + '" placeholder="Mis. bonus pengganti kelas / potongan terlambat"' + (draft ? '' : ' disabled') + '></div>'
        + '</div>'
        + '<div class="gj-sum"><div><span>Gaji sesi (' + g.jml_sesi + ' sesi)</span><span>' + rp(g.gaji_sesi) + '</span></div><div><span>Bonus</span><span id="gj-t-b" class="gj-pos">+ ' + rp(g.bonus) + '</span></div><div><span>Potongan</span><span id="gj-t-p" class="gj-neg">− ' + rp(g.potongan) + '</span></div><div class="t"><span>Total diterima</span><span id="gj-t-t">' + rp(g.total) + '</span></div></div>'
        + '<div id="gj-bayar-box"></div><div class="gj-err" id="gj-err"></div>'
        + (draft ? '<p class="gj-info">Gaji dihitung otomatis dari jadwal. Setelah disetujui, angka dikunci dan perubahan jadwal tidak mengubahnya lagi.</p>' : '')
        + (g.status === 'dibayar' ? '<p class="gj-info">Nominal ini sudah tercatat sebagai pengeluaran gaji di Laporan Keuangan bulan ' + esc(S.data.label) + '.' + (g.wa_status === 'gagal' ? '<br><span class="gj-neg">WhatsApp gagal: ' + esc(g.wa_error) + '</span>' : '') + '</p>' : '')
        + '<div class="gj-mfoot">' + foot + '</div>';
    $('gj-overlay').classList.add('is-open');
    if (draft) {
        const hit = () => {
            const b = angka($('gj-bonus').value), p = angka($('gj-pot').value);
            $('gj-t-b').textContent = '+ ' + rp(b); $('gj-t-p').textContent = '− ' + rp(p); $('gj-t-t').textContent = rp(Math.max(0, g.gaji_sesi + b - p));
        };
        ['gj-bonus', 'gj-pot'].forEach(i => $(i).addEventListener('input', e => { const el = e.target; el.value = el.value.trim() === '' ? '' : angka(el.value).toLocaleString('id-ID'); hit(); }));
    }
}

function pesanModal(t) { const el = $('gj-err'); if (el) el.textContent = t || ''; }

$('gj-modal').addEventListener('click', async e => {
    const b = e.target.closest('[data-a]'); if (!b || S.sibuk) return;
    const g = S.data.rows.find(x => x.id === S.aktif); if (!g) return;
    const a = b.dataset.a;
    if (a === 'tutup') return tutup();
    if (a === 'bayar_form') {
        const hari = new Date(); const iso = hari.getFullYear() + '-' + String(hari.getMonth() + 1).padStart(2, '0') + '-' + String(hari.getDate()).padStart(2, '0');
        $('gj-bayar-box').innerHTML = '<div class="gj-bayar"><h4>Konfirmasi pembayaran ' + rp(g.total) + '</h4><div class="gj-form">'
            + '<div class="gj-field"><label>Metode</label><select class="gj-input" id="gj-metode"><option value="transfer">Transfer</option><option value="tunai">Tunai</option></select></div>'
            + '<div class="gj-field"><label>Tanggal bayar</label><input class="gj-input" type="date" id="gj-tgl" value="' + iso + '"></div></div></div>';
        b.dataset.a = 'bayar'; b.textContent = 'Konfirmasi Bayar'; return;
    }
    if ((a === 'batal_bayar' || a === 'batal_setujui') && !b.classList.contains('confirm')) {   // klik kedua = konfirmasi
        b.classList.add('confirm'); const t = b.textContent; b.textContent = 'Yakin? Klik lagi';
        setTimeout(() => { b.classList.remove('confirm'); b.textContent = t; }, 3500); return;
    }
    S.sibuk = true; b.disabled = true; pesanModal('');
    try {
        let j;
        if (a === 'simpan') j = await api('adj', { id: g.id, bonus: angka($('gj-bonus').value), potongan: angka($('gj-pot').value), catatan: $('gj-cat').value });
        else if (a === 'setujui') {
            if (angka($('gj-bonus').value) !== g.bonus || angka($('gj-pot').value) !== g.potongan || $('gj-cat').value !== g.catatan)
                await api('adj', { id: g.id, bonus: angka($('gj-bonus').value), potongan: angka($('gj-pot').value), catatan: $('gj-cat').value });
            j = await api('status', { id: g.id, aksi: 'setujui' });
        }
        else if (a === 'bayar') j = await api('status', { id: g.id, aksi: 'bayar', metode: $('gj-metode').value, tanggal: $('gj-tgl').value });
        else if (a === 'wa') j = await api('kirim_wa', { id: g.id });
        else j = await api('status', { id: g.id, aksi: a });
        toast(j.pesan);
        await muat();
    } catch (err) { toast(err.message, true); await muat(); pesanModal(err.message); }
    S.sibuk = false;
});

// ─── Navigasi periode & aksi header ───
function geser(n) { let m = S.bulan + n, y = S.tahun; if (m < 1) { m = 12; y--; } if (m > 12) { m = 1; y++; } S.bulan = m; S.tahun = y; tutup(); muat(); }
$('gj-prev').addEventListener('click', () => geser(-1));
$('gj-next').addEventListener('click', () => geser(1));
$('gj-hitung').addEventListener('click', async e => {
    const b = e.currentTarget; b.disabled = true;
    try { const j = await api('hitung', { tahun: S.tahun, bulan: S.bulan }); toast(j.pesan); await muat(); } catch (err) { toast(err.message, true); }
    b.disabled = false;
});
$('gj-setujui-semua').addEventListener('click', async e => {
    const b = e.currentTarget; if (!b.dataset.ok) { b.dataset.ok = 1; b.textContent = 'Yakin? Klik lagi'; setTimeout(() => { delete b.dataset.ok; b.textContent = 'Setujui Semua'; }, 3500); return; }
    delete b.dataset.ok; b.textContent = 'Setujui Semua'; b.disabled = true;
    try { const j = await api('status_semua', { tahun: S.tahun, bulan: S.bulan }); toast(j.pesan); await muat(); } catch (err) { toast(err.message, true); }
});
document.querySelectorAll('.gj-tab').forEach(t => t.addEventListener('click', () => {
    document.querySelectorAll('.gj-tab').forEach(x => x.classList.toggle('is-on', x === t));
    document.querySelectorAll('.gj-panel').forEach(p => p.classList.toggle('is-on', p.id === 'gj-p-' + t.dataset.tab));
    if (t.dataset.tab === 'wa') gwCek(false);
}));
// Tautan dari notifikasi / halaman lain: gaji.php?tahun=2026&bulan=10
(function () { const q = new URLSearchParams(location.search); const y = +q.get('tahun'), m = +q.get('bulan'); if (y > 2019 && m >= 1 && m <= 12) { S.tahun = y; S.bulan = m; } })();
muat();
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
            return '<a class="nv-n' + (r.indexOf(x.id) < 0 ? ' baru' : '') + '" href="manajemen-ai-chat.php" data-id="' + esc(x.id) + '"><span class="nv-n-av">' + esc(x.inisial) + '</span>'
                + '<span class="nv-n-t"><b>' + esc(x.nama) + '</b><span>' + esc(x.isi || '—') + '</span><em>Butuh tindakan admin</em></span><time>' + esc(x.jam) + '</time></a>';
        }).join('');
    }
    function muat() {
        var cache = null; try { cache = JSON.parse(sessionStorage.getItem('nv-notif')); } catch (e) {}
        if (cache && Date.now() - cache.t < 60000) { DATA = cache.d; render(); return; }
        render();
        fetch('manajemen-ai-chat.php?aksi=data&tab=admin', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                DATA = { admin: (d && d.hitung && d.hitung.admin) || 0, rows: ((d && d.rows) || []).slice(0, 20) };
                try { sessionStorage.setItem('nv-notif', JSON.stringify({ t: Date.now(), d: DATA })); } catch (e) {}
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
        if (list) list.addEventListener('click', function (e) { var a = e.target.closest('a[data-id]'); if (a) { var r = dibaca(); r.push(+a.dataset.id); simpan(r); } });
        muat();
    }
})();
</script>
</body>
</html>
