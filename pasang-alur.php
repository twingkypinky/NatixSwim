<?php
// ============================================================
// pasang-alur.php — pasang alur chatbot WhatsApp (jalankan sekali di folder NatixSwim, aman diulang)
//   Daftar (calon murid) → Data Member + Tagihan → Progres Murid (coach) → Gaji, semua lewat chat WhatsApp.
//   1. menulis progres.php, alur-fungsi.php (logika), alur-query.php (query SQL)
//   2. menambah menu "Progres Murid" (Operasional) di semua halaman (dan membuang menu Simulator Chat bila ada)
//   3. manajemen-ai-chat.php : chat "daftar" & "progres" dibalas otomatis oleh alurProses()
//   4. config/wa-config.php    : pengaturan WhatsApp bersama (Cloud API Meta). Tidak ditimpa kalau sudah ada.
//   5. gaji.php, gaji-fungsi.php, gaji-query.php diperbarui (pengiriman WhatsApp lewat Cloud API / Baileys)
// ============================================================
$dir = __DIR__;
$ok = []; $lewat = []; $gagal = [];

$berkas = [
'progres.php' => <<<'PHPEND'
<?php
// ============================================================
// progres.php — Progres Murid (diisi coach lewat WhatsApp, dipantau admin di sini)
// Alur chat ada di alur-fungsi.php, query SQL di alur-query.php. Tabel dibuat otomatis saat halaman dibuka.
// ============================================================
if (($_GET['aksi'] ?? '') !== '') { ob_start(); ini_set('display_errors', '0'); }
date_default_timezone_set('Asia/Jakarta');
require 'config/koneksi.php';
require_once __DIR__ . '/alur-fungsi.php';

const PR_DEBUG = true;   // true = pesan error server ditampilkan apa adanya. Ubah ke false saat production.

function out(array $data, int $code = 200): void
{
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$aksi = $_GET['aksi'] ?? '';
if ($aksi !== '') {
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level()) ob_end_clean();
            if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['ok' => false, 'pesan' => PR_DEBUG ? 'Error PHP: ' . $e['message'] : 'Terjadi kesalahan pada server.'], JSON_UNESCAPED_UNICODE);
        }
    });
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    try {
        aqMigrasi($pdo);
        if (in_array($aksi, ['kirim_ortu', 'hapus'], true) && !$isPost) out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
        switch ($aksi) {
            case 'data': {
                $th = (int)($_GET['tahun'] ?? date('Y')); $bl = (int)($_GET['bulan'] ?? date('n'));
                if ($th < 2020 || $th > 2100) $th = (int)date('Y');
                if ($bl < 1 || $bl > 12) $bl = (int)date('n');
                $awal = sprintf('%04d-%02d-01', $th, $bl);
                $akhir = date('Y-m-t', strtotime($awal));
                $rows = aqProgresBulan($pdo, $awal, $akhir, (int)($_GET['pelatih'] ?? 0), trim((string)($_GET['q'] ?? '')));
                foreach ($rows as &$r) {
                    $r['id'] = (int)$r['id']; $r['level_nama'] = AL_LEVEL[(int)$r['level_skill']] ?? '-';
                    $r['no_whatsapp'] = $r['no_whatsapp'] ? (string)$r['no_whatsapp'] : '';
                }
                unset($r);
                $pel = array_map(function ($c) { return ['id' => (int)$c['id'], 'nama' => gjNamaCoach((string)$c['nama'])]; }, aqDaftarPelatih($pdo));
                out(['ok' => true, 'label' => GJ_BULAN[$bl] . ' ' . $th, 'ringkas' => alRingkas($rows), 'rows' => $rows, 'pelatih' => $pel, 'level' => AL_LEVEL]);
            }
            case 'detail': {
                $p = aqAmbilProgres($pdo, (int)($_GET['id'] ?? 0));
                if (!$p) out(['ok' => false, 'pesan' => 'Data progres tidak ditemukan.'], 404);
                $p['level_nama'] = AL_LEVEL[(int)$p['level_skill']] ?? '-';
                out(['ok' => true, 'p' => $p, 'riwayat' => aqRiwayatMurid($pdo, (int)$p['member_id'], 6), 'level' => AL_LEVEL]);
            }
            case 'kirim_ortu': {
                [$ok, $pesan] = alKirimOrtu($pdo, (int)($in['id'] ?? 0));
                out(['ok' => $ok, 'pesan' => $pesan], $ok ? 200 : 422);
            }
            case 'hapus': {
                aqHapusProgres($pdo, (int)($in['id'] ?? 0));
                out(['ok' => true]);
            }
            default: out(['ok' => false, 'pesan' => 'Aksi tidak dikenal.'], 404);
        }
    } catch (Throwable $e) {
        error_log('[progres] ' . $e->getMessage());
        out(['ok' => false, 'pesan' => PR_DEBUG ? $e->getMessage() : 'Terjadi kesalahan pada server.'], 500);
    }
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
            'wallet'   => '<path d="M3 7a2 2 0 012-2h13v4"/><path d="M3 7v11a2 2 0 002 2h15a1 1 0 001-1v-3"/><path d="M21 9h-4a2 2 0 000 4h4z"/>',
            'award'    => '<circle cx="12" cy="9" r="6"/><path d="M8.5 14.2L7 22l5-3 5 3-1.5-7.8"/>',
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
    <title>Progres Murid — Natix Swim</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/reset.css" />
    <link rel="stylesheet" href="css/global.css" />
    <link rel="stylesheet" href="css/cdm-segmentasi.css" />
    <style>
        /* Semua class halaman ini memakai prefix "pr-" supaya tidak bentrok dengan CSS export Figma */
        .main-content { position: relative !important; height: auto !important; width: auto !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; box-sizing: border-box; min-width: 0; }
        .pr-page, .pr-page *, .pr-overlay, .pr-overlay * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
        .pr-page p, .pr-page h1, .pr-page h2, .pr-page h3 { margin: 0; }
        .pr-page { padding: 28px 32px 48px; color: #111827; width: 100%; max-width: 100%; min-width: 0; }

        /* Header */
        .pr-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-top: 6px; }
        .pr-title { font-size: 30px; font-weight: 700; line-height: 1.2; }
        .pr-sub { font-size: 15px; color: #6b7280; margin-top: 4px !important; }
        .pr-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .pr-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; white-space: nowrap; font-size: 14px; font-weight: 600; border-radius: 12px; padding: 11px 18px; cursor: pointer; border: 1px solid #e5e7eb; background: #fff; color: #111827; transition: background .15s; text-decoration: none; }
        .pr-btn:hover { background: #f9fafb; }
        .pr-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        .pr-btn.primary:hover { background: #1d4ed8; }
        .pr-btn.green { background: #16a34a; border-color: #16a34a; color: #fff; }
        .pr-btn.green:hover { background: #15803d; }
        .pr-btn.danger { border-color: #fca5a5; color: #dc2626; }
        .pr-btn.danger:hover { background: #fef2f2; }
        .pr-btn.danger.confirm { background: #dc2626; color: #fff; border-color: #dc2626; }
        .pr-btn.sm { padding: 7px 14px; font-size: 13px; border-radius: 10px; }
        .pr-btn:disabled { opacity: .55; cursor: not-allowed; }
        .pr-month { display: inline-flex; align-items: center; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
        .pr-month button { border: 0; background: transparent; width: 40px; height: 42px; cursor: pointer; font-size: 18px; color: #6b7280; }
        .pr-month button:hover { background: #f3f4f6; }
        .pr-month strong { min-width: 150px; text-align: center; font-size: 14px; font-weight: 600; padding: 0 6px; }

        /* Kartu ringkasan */
        .pr-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin: 26px 0 22px; }
        .pr-stat { background: #fff; border: 1px solid #eceef2; border-radius: 16px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; min-width: 0; min-height: 92px; }
        .pr-ico { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .pr-ico svg { width: 22px; height: 22px; }
        .pr-ico.blue { background: #eef4ff; color: #2563eb; } .pr-ico.green { background: #eafaf1; color: #16a34a; }
        .pr-ico.orange { background: #fff3e6; color: #ea6a0a; } .pr-ico.violet { background: #f1eefe; color: #6d4aff; }
        .pr-stat div { min-width: 0; }
        .pr-stat p { font-size: 14px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pr-stat h2 { font-size: 24px; font-weight: 700; line-height: 1.15; margin-top: 4px !important; white-space: nowrap; }
        .pr-stat small { display: block; font-size: 12px; color: #9ca3af; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Tab */
        .pr-tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .pr-tab { border: 1px solid #e5e7eb; background: #fff; color: #6b7280; font-size: 14px; font-weight: 600; padding: 9px 18px; border-radius: 999px; cursor: pointer; }
        .pr-tab:hover { background: #f9fafb; }
        .pr-tab.is-on { background: #111827; border-color: #111827; color: #fff; }
        .pr-panel { display: none; } .pr-panel.is-on { display: block; }

        /* Kartu / tabel */
        .pr-card { background: #fff; border: 1px solid #eceef2; border-radius: 18px; overflow-x: auto; overflow-y: hidden; }
        .pr-card-h { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 18px 22px; border-bottom: 1px solid #f1f2f5; }
        .pr-card-h h3 { font-size: 16px; font-weight: 700; } .pr-card-h small { color: #9ca3af; font-size: 13px; font-weight: 500; }
        .pr-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .pr-table th { text-align: left; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #6b7280; padding: 12px 16px; background: #fafafb; border-bottom: 1px solid #eceef2; white-space: nowrap; }
        .pr-table td { padding: 14px 16px; border-bottom: 1px solid #f1f2f5; vertical-align: middle; }
        .pr-table tr:last-child td { border-bottom: 0; }
        .pr-table tbody tr.pr-row { cursor: pointer; transition: background .12s; } .pr-table tbody tr.pr-row:hover { background: #f8fafd; }
        .pr-table .r { text-align: right; } .pr-table th.r { text-align: right; }
        .pr-coach { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .pr-av { width: 38px; height: 38px; border-radius: 50%; background: #eef2ff; color: #2563eb; font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .pr-coach b { display: block; font-weight: 600; } .pr-coach small { display: block; color: #9ca3af; font-size: 12.5px; margin-top: 1px; }
        .pr-chips { display: flex; gap: 6px; flex-wrap: wrap; }
        .pr-chip { font-size: 12px; font-weight: 600; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
        .pr-chip.kids { background: #eef2ff; color: #1e3a8a; } .pr-chip.dewasa { background: #e8f7ee; color: #14532d; } .pr-chip.private { background: #fff3e0; color: #7c4a03; }
        .pr-pos { color: #16a34a; } .pr-neg { color: #dc2626; } .pr-mut { color: #9ca3af; }
        .pr-total { font-weight: 700; }
        .pr-tag { display: inline-block; font-size: 12px; font-weight: 600; padding: 4px 11px; border-radius: 999px; white-space: nowrap; }
        .pr-tag.draft { background: #f3f4f6; color: #6b7280; } .pr-tag.disetujui { background: #eef2ff; color: #2563eb; } .pr-tag.dibayar { background: #dcfce7; color: #16a34a; }
        .pr-tag.belum { background: #f3f4f6; color: #9ca3af; } .pr-tag.terkirim { background: #dcfce7; color: #16a34a; } .pr-tag.gagal { background: #fee2e2; color: #dc2626; }
        .pr-empty { padding: 56px 16px; text-align: center; color: #9ca3af; font-size: 14px; }
        .pr-empty b { display: block; color: #6b7280; font-size: 15px; margin-bottom: 4px; }
        .pr-foot-note { padding: 14px 22px; border-top: 1px solid #f1f2f5; font-size: 13px; color: #6b7280; background: #fcfcfd; }

        /* Tarif */
        .pr-input { width: 100%; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; font-size: 14px; color: #111827; background: #fff; }
        .pr-input:focus { outline: 2px solid #bfdbfe; border-color: #93c5fd; }
        .pr-input:disabled { background: #f9fafb; color: #6b7280; }
        .pr-rp { position: relative; } .pr-rp span { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 13px; color: #9ca3af; pointer-events: none; }
        .pr-rp .pr-input { padding-left: 36px; text-align: right; }
        .pr-table .pr-rp { min-width: 130px; }
        tr.pr-std td { background: #f8fafc; font-weight: 600; }
        .pr-saved { box-shadow: 0 0 0 2px #86efac; transition: box-shadow .3s; }

        /* WhatsApp */
        .pr-wa { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; }
        .pr-box { padding: 22px; }
        .pr-box h3 { font-size: 16px; font-weight: 700; margin-bottom: 6px; } .pr-box p { font-size: 14px; color: #6b7280; line-height: 1.55; }
        .pr-switch { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 14px 0; border-bottom: 1px solid #f1f2f5; }
        .pr-switch b { display: block; font-size: 14px; font-weight: 600; } .pr-switch small { color: #9ca3af; font-size: 13px; }
        .pr-tg { position: relative; width: 46px; height: 26px; flex: 0 0 auto; }
        .pr-tg input { opacity: 0; width: 100%; height: 100%; position: absolute; inset: 0; margin: 0; cursor: pointer; z-index: 1; }
        .pr-tg i { position: absolute; inset: 0; background: #d1d5db; border-radius: 999px; transition: background .15s; }
        .pr-tg i::after { content: ''; position: absolute; left: 3px; top: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: transform .15s; }
        .pr-tg input:checked + i { background: #2563eb; } .pr-tg input:checked + i::after { transform: translateX(20px); }
        .pr-bubble { background: #e7f6ec; border-radius: 14px 14px 14px 4px; padding: 14px 16px; font-size: 13.5px; line-height: 1.55; color: #14321f; white-space: pre-wrap; margin-top: 14px; }
        .pr-cmd { display: inline-block; background: #f3f4f6; border-radius: 8px; padding: 2px 9px; font-weight: 600; color: #111827; font-size: 13px; }
        .pr-wa-full { grid-column: 1 / -1; }
        .pr-gw { padding: 18px 22px; }
        .pr-gw-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .pr-gw-dot { width: 12px; height: 12px; border-radius: 50%; background: #d1d5db; flex: 0 0 auto; box-shadow: 0 0 0 4px #f3f4f6; }
        .pr-gw-dot.ok { background: #16a34a; box-shadow: 0 0 0 4px #dcfce7; } .pr-gw-dot.wait { background: #e8890c; box-shadow: 0 0 0 4px #ffedd5; } .pr-gw-dot.off { background: #dc2626; box-shadow: 0 0 0 4px #fee2e2; }
        .pr-gw-txt { flex: 1 1 240px; min-width: 0; } .pr-gw-txt b { display: block; font-size: 15px; } .pr-gw-txt small { display: block; color: #6b7280; font-size: 13px; margin-top: 2px; line-height: 1.45; }
        .pr-gw-txt a { color: #2563eb; text-decoration: none; font-weight: 600; }
        .pr-gw-tes { display: flex; gap: 10px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #f1f2f5; flex-wrap: wrap; }
        .pr-gw-tes .pr-input { flex: 1 1 240px; min-width: 0; }

        /* Modal */
        .pr-overlay { position: fixed; inset: 0; background: rgba(17,24,39,.45); display: none; align-items: center; justify-content: center; padding: 20px; z-index: 1300; }
        .pr-overlay.is-open { display: flex; }
        .pr-modal { background: #fff; border-radius: 18px; width: 100%; max-width: 560px; max-height: calc(100vh - 40px); overflow-y: auto; padding: 26px; box-shadow: 0 24px 60px rgba(16,24,40,.25); }
        .pr-modal h2 { font-size: 20px; font-weight: 700; }
        .pr-modal-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
        .pr-modal-top p { font-size: 14px; color: #6b7280; margin-top: 3px; }
        .pr-x { border: 0; background: #f3f4f6; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 18px; line-height: 1; color: #6b7280; flex: 0 0 auto; }
        .pr-x:hover { background: #e5e7eb; }
        .pr-rin { border: 1px solid #f1f2f5; border-radius: 12px; overflow: hidden; margin-bottom: 18px; }
        .pr-rin .pr-table th, .pr-rin .pr-table td { padding: 10px 12px; }
        .pr-form { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .pr-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .pr-field.full { grid-column: 1 / -1; }
        .pr-field label { font-size: 13px; font-weight: 600; color: #374151; }
        .pr-sum { margin: 18px 0 0; border-top: 1px dashed #e5e7eb; padding-top: 14px; display: grid; gap: 6px; font-size: 14px; }
        .pr-sum div { display: flex; justify-content: space-between; gap: 10px; }
        .pr-sum .t { font-size: 18px; font-weight: 700; padding-top: 8px; border-top: 1px solid #111827; margin-top: 4px; }
        .pr-bayar { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; margin-top: 16px; }
        .pr-bayar h4 { margin: 0 0 10px; font-size: 14px; }
        .pr-mfoot { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; flex-wrap: wrap; }
        .pr-info { font-size: 13px; color: #6b7280; margin-top: 12px; line-height: 1.5; }
        .pr-err { color: #dc2626; font-size: 13px; margin-top: 10px; min-height: 0; }

        /* Toast */
        .pr-toast { font-family: 'Inter', sans-serif; position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(20px); background: #111827; color: #fff; padding: 12px 20px; border-radius: 12px; font-size: 14px; opacity: 0; pointer-events: none; transition: all .25s; z-index: 1500; max-width: calc(100vw - 32px); }
        .pr-toast.is-show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .pr-toast.err { background: #dc2626; }

        @media (max-width: 1200px) { .pr-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .pr-wa { grid-template-columns: 1fr; } }
        @media (max-width: 900px) {
            .pr-page { padding: 24px 20px 40px; }
            .pr-table thead { display: none; }
            .pr-table, .pr-table tbody, .pr-table tr, .pr-table td { display: block; width: 100%; }
            .pr-table tbody tr.pr-row { padding: 14px 16px; border-bottom: 1px solid #f1f2f5; }
            .pr-table tbody tr.pr-row td { border: 0; padding: 4px 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; text-align: right; }
            .pr-table tbody tr.pr-row td::before { content: attr(data-l); font-size: 12px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: .04em; text-align: left; flex: 0 0 auto; }
            .pr-table tbody tr.pr-row td.pr-c0 { display: block; padding-bottom: 8px; text-align: left; } .pr-table tbody tr.pr-row td.pr-c0::before { display: none; }
            .pr-table tbody tr.pr-row td.r { text-align: right; }
            .pr-tarif .pr-table tr { padding: 12px 16px; border-bottom: 1px solid #f1f2f5; } .pr-tarif .pr-table td { border: 0; padding: 4px 0; }
            .pr-tarif .pr-table td[data-l]::before { content: attr(data-l); display: block; font-size: 12px; font-weight: 600; color: #9ca3af; margin-bottom: 4px; }
        }
        @media (max-width: 640px) {
            .pr-page { padding: 20px 16px 40px; }
            .pr-title { font-size: 24px; }
            .pr-stats { grid-template-columns: 1fr; }
            .pr-actions { width: 100%; } .pr-actions .pr-btn { flex: 1 1 auto; } .pr-month { width: 100%; } .pr-month strong { flex: 1; }
            .pr-form { grid-template-columns: 1fr; } .pr-modal { padding: 20px 16px; }
            .pr-mfoot .pr-btn { flex: 1 1 auto; }
        }

        /* Progres Murid */
        .pr-filter { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; padding: 16px 20px; border-bottom: 1px solid #f1f2f5; }
        .pr-filter .pr-input { width: auto; height: 42px; }
        .pr-filter input.pr-input { flex: 1 1 260px; max-width: 360px; }
        .pr-filter select.pr-input { flex: 0 0 200px; }
        .pr-table td.pr-c0 { white-space: nowrap; }
        .pr-table td, .pr-table th { padding-left: 20px; padding-right: 12px; }
        .pr-nilai { display: flex; gap: 10px; align-items: center; font-size: 13px; white-space: nowrap; }
        .pr-nilai span { display: inline-flex; align-items: center; gap: 4px; color: #6b7280; }
        .pr-nilai b { color: #111827; font-weight: 700; }
        .pr-lv { display: inline-block; font-size: 12px; font-weight: 600; padding: 4px 11px; border-radius: 999px; background: #eef4ff; color: #1d4ed8; white-space: nowrap; }
        .pr-cat { max-width: 260px; color: #4b5563; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pr-bar { display: grid; grid-template-columns: 110px 1fr 24px; gap: 10px; align-items: center; font-size: 13.5px; margin: 8px 0; }
        .pr-bar i { display: block; height: 8px; border-radius: 999px; background: #eef0f4; position: relative; overflow: hidden; }
        .pr-bar i::after { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: var(--w); background: #2563eb; border-radius: 999px; }
        .pr-note { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 14px; font-size: 14px; color: #374151; line-height: 1.55; margin: 14px 0 4px; white-space: pre-wrap; }
        .pr-hist { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
        .pr-hist th { text-align: left; color: #9ca3af; font-weight: 600; font-size: 11.5px; text-transform: uppercase; padding: 6px 4px; }
        .pr-hist td { padding: 7px 4px; border-top: 1px solid #f1f2f5; }
        .pr-flow { display: flex; gap: 10px; flex-wrap: wrap; padding: 18px 22px; }
        .pr-step { flex: 1 1 150px; border: 1px solid #eceef2; border-radius: 14px; padding: 12px 14px; font-size: 13px; color: #6b7280; line-height: 1.45; }
        .pr-step b { display: block; color: #111827; font-size: 13.5px; margin-bottom: 2px; }
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
            <div class="pr-page">
                <?= nvCrumb() ?>

                <div class="pr-head">
                    <div>
                        <h1 class="pr-title">Progres Murid</h1>
                        <p class="pr-sub">Laporan perkembangan murid yang diisi coach lewat WhatsApp, otomatis diteruskan ke orang tua.</p>
                    </div>
                    <div class="pr-actions">
                        <div class="pr-month">
                            <button type="button" id="pr-prev" aria-label="Bulan sebelumnya">‹</button>
                            <strong id="pr-label">Memuat…</strong>
                            <button type="button" id="pr-next" aria-label="Bulan berikutnya">›</button>
                        </div>
                    </div>
                </div>

                <div class="pr-stats">
                    <div class="pr-stat"><span class="pr-ico blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 16l4-5 3 2 5-7"/></svg></span><div><p>Laporan Bulan Ini</p><h2 id="pr-s-total">–</h2><small>dari semua coach</small></div></div>
                    <div class="pr-stat"><span class="pr-ico violet"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/></svg></span><div><p>Murid Dilaporkan</p><h2 id="pr-s-murid">–</h2><small>murid berbeda</small></div></div>
                    <div class="pr-stat"><span class="pr-ico orange"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg></span><div><p>Rata-rata Nilai</p><h2 id="pr-s-rata">–</h2><small>skala 1–5</small></div></div>
                    <div class="pr-stat"><span class="pr-ico green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.5 8.5 0 01-12.6 7.4L3 20.5l1.6-5A8.5 8.5 0 1121 11.5z"/></svg></span><div><p>Terkirim ke Orang Tua</p><h2 id="pr-s-ortu">–</h2><small>lewat WhatsApp</small></div></div>
                </div>

                <div class="pr-card">
                    <div class="pr-filter">
                        <input class="pr-input" id="pr-f-q" type="search" placeholder="Cari nama / kode murid…" autocomplete="off">
                        <select class="pr-input" id="pr-f-coach"><option value="0">Semua coach</option></select>
                    </div>
                    <table class="pr-table">
                        <thead><tr><th>Tanggal</th><th>Murid</th><th>Coach</th><th>Level</th><th>Nilai (T · S · P)</th><th>Catatan</th><th>Orang Tua</th></tr></thead>
                        <tbody id="pr-tbody"><tr><td colspan="7" class="pr-empty">Memuat…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

    <div class="pr-overlay" id="pr-overlay"><div class="pr-modal" id="pr-modal"></div></div>
    <div class="pr-toast" id="pr-toast"></div>

<script>
// ============================================================
// PROGRES MURID
// ============================================================
const $ = id => document.getElementById(id);
const BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const NOW = new Date();
const S = { tahun: NOW.getFullYear(), bulan: NOW.getMonth() + 1, data: null, pelatih: 0, q: '', konfirmasiHapus: false };

function esc(t) { const d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
function tglIndo(s) { if (!s) return '-'; const p = String(s).slice(0, 10).split('-'); return +p[2] + ' ' + BULAN[+p[1]].slice(0, 3) + ' ' + p[0]; }
function inisial(n) { return String(n || '?').trim().split(/\s+/).slice(0, 2).map(x => x[0]).join('').toUpperCase() || '?'; }
let toastT;
function toast(t, err) { const el = $('pr-toast'); el.textContent = t; el.className = 'pr-toast is-show' + (err ? ' err' : ''); clearTimeout(toastT); toastT = setTimeout(() => el.classList.remove('is-show'), err ? 5000 : 2800); }

async function api(aksi, body, qs) {
    const url = 'progres.php?aksi=' + aksi + (qs ? '&' + qs : '');
    const opt = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {};
    let res, j;
    try { res = await fetch(url, opt); } catch (e) { throw new Error('Tidak bisa menghubungi server.'); }
    try { j = await res.json(); } catch (e) { throw new Error('Respons server tidak valid (HTTP ' + res.status + ').'); }
    if (!j.ok) throw new Error(j.pesan || 'Terjadi kesalahan.');
    return j;
}

async function muat() {
    try { S.data = await api('data', null, 'tahun=' + S.tahun + '&bulan=' + S.bulan + '&pelatih=' + S.pelatih + '&q=' + encodeURIComponent(S.q)); }
    catch (e) { toast(e.message, true); $('pr-tbody').innerHTML = '<tr><td colspan="7" class="pr-empty"><b>Gagal memuat data</b>' + esc(e.message) + '</td></tr>'; return; }
    render();
}

function render() {
    const d = S.data, r = d.ringkas;
    $('pr-label').textContent = d.label;
    $('pr-s-total').textContent = r.total;
    $('pr-s-murid').textContent = r.murid;
    $('pr-s-rata').textContent = r.total ? String(r.rata).replace('.', ',') : '–';
    $('pr-s-ortu').textContent = r.ortu + ' / ' + r.total;
    const sel = $('pr-f-coach');
    if (sel.options.length <= 1) d.pelatih.forEach(p => { const o = document.createElement('option'); o.value = p.id; o.textContent = p.nama; sel.appendChild(o); });
    sel.value = String(S.pelatih);
    if (!d.rows.length) {
        $('pr-tbody').innerHTML = '<tr><td colspan="7" class="pr-empty"><b>Belum ada progres di ' + esc(d.label) + '</b>Coach bisa mengisinya lewat WhatsApp dengan mengetik <span class="pr-cmd">progres</span>.</td></tr>';
        return;
    }
    const ORTU = { belum: 'Belum dikirim', terkirim: 'Terkirim', gagal: 'Gagal' };
    $('pr-tbody').innerHTML = d.rows.map(p => `
        <tr class="pr-row" data-id="${p.id}">
            <td data-l="Tanggal" class="pr-c0">${tglIndo(p.tanggal)}</td>
            <td data-l="Murid"><div class="pr-coach"><span class="pr-av">${esc(inisial(p.murid))}</span><div><b>${esc(p.murid)}</b><small>${esc(p.kode_member || '')}</small></div></div></td>
            <td data-l="Coach">${esc(p.pelatih || '-')}</td>
            <td data-l="Level"><span class="pr-lv">${esc(p.level_nama)}</span></td>
            <td data-l="Nilai"><div class="pr-nilai"><span>T <b>${p.nilai_teknik}</b></span><span>S <b>${p.nilai_stamina}</b></span><span>P <b>${p.nilai_percaya_diri}</b></span></div></td>
            <td data-l="Catatan"><div class="pr-cat" title="${esc(p.catatan || '')}">${esc(p.catatan || '-')}</div></td>
            <td data-l="Orang tua"><span class="pr-tag ${p.ortu_status}">${ORTU[p.ortu_status] || p.ortu_status}</span></td>
        </tr>`).join('');
}

function bar(label, n) { return `<div class="pr-bar"><span>${label}</span><i style="--w:${n * 20}%"></i><b>${n}/5</b></div>`; }

async function detail(id) {
    let j;
    try { j = await api('detail', null, 'id=' + id); } catch (e) { return toast(e.message, true); }
    const p = j.p; S.konfirmasiHapus = false;
    $('pr-modal').innerHTML = `
        <div class="pr-modal-top"><div><h2>${esc(p.murid)}</h2><p>${esc(p.kode_member || '')} · ${tglIndo(p.tanggal)} · ${esc(p.pelatih || '-')}</p></div><button class="pr-x" id="pr-x" type="button" aria-label="Tutup">×</button></div>
        <span class="pr-lv">Level ${p.level_skill} — ${esc(p.level_nama)}</span>
        ${bar('Teknik', +p.nilai_teknik)}${bar('Stamina', +p.nilai_stamina)}${bar('Percaya diri', +p.nilai_percaya_diri)}
        ${p.catatan ? `<div class="pr-note">${esc(p.catatan)}</div>` : ''}
        <p class="pr-info">Orang tua: ${esc(p.nama_wali || '-')} · ${esc(p.no_whatsapp || 'nomor belum diisi')} — <span class="pr-tag ${p.ortu_status}">${p.ortu_status === 'terkirim' ? 'Terkirim' : p.ortu_status === 'gagal' ? 'Gagal' : 'Belum dikirim'}</span></p>
        ${p.ortu_error ? `<p class="pr-err">${esc(p.ortu_error)}</p>` : ''}
        ${j.riwayat.length > 1 ? `<table class="pr-hist"><thead><tr><th>Riwayat</th><th>Level</th><th>T</th><th>S</th><th>P</th></tr></thead><tbody>${j.riwayat.map(h => `<tr><td>${tglIndo(h.tanggal)}</td><td>${h.level_skill}</td><td>${h.nilai_teknik}</td><td>${h.nilai_stamina}</td><td>${h.nilai_percaya_diri}</td></tr>`).join('')}</tbody></table>` : ''}
        <div class="pr-mfoot"><button class="pr-btn danger" id="pr-hapus" type="button">Hapus</button><button class="pr-btn green" id="pr-kirim" type="button">${p.ortu_status === 'terkirim' ? 'Kirim Ulang ke Orang Tua' : 'Kirim ke Orang Tua'}</button></div>`;
    $('pr-overlay').classList.add('is-open');
    $('pr-x').onclick = tutup;
    $('pr-kirim').onclick = async () => {
        const b = $('pr-kirim'); b.disabled = true; b.textContent = 'Mengirim…';
        try { await api('kirim_ortu', { id: p.id }); toast('Laporan terkirim ke orang tua.'); } catch (e) { toast(e.message, true); }
        await muat(); detail(p.id);
    };
    $('pr-hapus').onclick = async () => {
        const b = $('pr-hapus');
        if (!S.konfirmasiHapus) { S.konfirmasiHapus = true; b.textContent = 'Yakin hapus?'; b.classList.add('confirm'); return; }
        try { await api('hapus', { id: p.id }); tutup(); toast('Progres dihapus.'); muat(); } catch (e) { toast(e.message, true); }
    };
}
function tutup() { $('pr-overlay').classList.remove('is-open'); }

function geser(n) { S.bulan += n; if (S.bulan > 12) { S.bulan = 1; S.tahun++; } if (S.bulan < 1) { S.bulan = 12; S.tahun--; } muat(); }
$('pr-prev').onclick = () => geser(-1);
$('pr-next').onclick = () => geser(1);
$('pr-f-coach').onchange = e => { S.pelatih = +e.target.value; muat(); };
let qT; $('pr-f-q').oninput = e => { clearTimeout(qT); qT = setTimeout(() => { S.q = e.target.value.trim(); muat(); }, 300); };
$('pr-tbody').onclick = e => { const tr = e.target.closest('tr.pr-row'); if (tr) detail(+tr.dataset.id); };
$('pr-overlay').onclick = e => { if (e.target === $('pr-overlay')) tutup(); };
document.addEventListener('keydown', e => { if (e.key === 'Escape') tutup(); });
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

PHPEND
,
'alur-fungsi.php' => <<<'PHPEND'
<?php
// ============================================================
// alur-fungsi.php — LOGIKA alur chatbot WhatsApp (tanpa SQL; query ada di alur-query.php)
//
//   Orang tua / calon murid  : "daftar" → isi data → member + tagihan otomatis → kirim bukti bayar (diverifikasi di Transaksi)
//   Coach                    : "progres" → pilih murid → level & nilai → catatan → tersimpan + laporan ke orang tua
//   Coach                    : "Check Gaji Bulan Ini" (gaji-fungsi.php) → slip gaji dari sesi yang sudah diajar
//
// Dipanggil dari manajemen-ai-chat.php (waTerimaPesan) lewat alurProses(). Mengembalikan teks balasan,
// atau null kalau pesan bukan bagian dari alur (supaya AI / admin yang menjawab seperti biasa).
// ============================================================
require_once __DIR__ . '/alur-query.php';
require_once __DIR__ . '/gaji-fungsi.php';

const AL_SESI_MENIT = 30;            // percakapan alur dibatalkan otomatis kalau diam sekian menit
const AL_JATUH_TEMPO_HARI = 3;       // tagihan pendaftaran jatuh tempo
const AL_KIRIM_ORTU = true;          // laporan progres otomatis dikirim ke WhatsApp orang tua
const AL_LEVEL = [
    1 => 'Pengenalan Air',
    2 => 'Mengapung & Meluncur',
    3 => 'Gaya Bebas Dasar',
    4 => 'Gaya Punggung & Dada',
    5 => 'Mahir / Persiapan Lomba',
];

// ─── UTIL ───
function alSekarang(): DateTime { return new DateTime('now', new DateTimeZone('Asia/Jakarta')); }
function alAkhir9(?string $no): string { $d = preg_replace('/\D+/', '', gjNomor($no)); return strlen($d) >= 9 ? substr($d, -9) : ''; }
function alBintang(int $n): string { $n = max(0, min(5, $n)); return str_repeat('⭐', $n) . str_repeat('▫️', 5 - $n); }
function alRp(int $n): string { return 'Rp ' . number_format($n, 0, ',', '.'); }

function alParseTanggal(string $s): ?string   // 17-08-2016 / 17/08/2016 / 2016-08-17 -> Y-m-d (null kalau tidak valid)
{
    $s = trim($s);
    if (preg_match('/^(\d{1,2})[\-\/.](\d{1,2})[\-\/.](\d{4})$/', $s, $m)) [$d, $b, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) [$y, $b, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    else return null;
    if (!checkdate($b, $d, $y)) return null;
    $t = sprintf('%04d-%02d-%02d', $y, $b, $d);
    $hari = alSekarang()->format('Y-m-d');
    if ($t > $hari || $t < date('Y-m-d', strtotime($hari . ' -90 years'))) return null;
    return $t;
}

function alTglIndo(string $ymd): string { return gjTglIndo($ymd); }

function alCoachId(PDO $pdo, string $no): ?int
{
    $akhir = alAkhir9($no);
    if ($akhir === '') return null;
    foreach (aqDaftarPelatih($pdo) as $c) if (alAkhir9($c['hp'] ?? '') === $akhir) return (int)$c['id'];
    return null;
}

function alNamaPelatih(PDO $pdo, int $id): string
{
    foreach (aqDaftarPelatih($pdo) as $c) if ((int)$c['id'] === $id) return gjNamaCoach((string)$c['nama']);
    return 'Coach';
}

function alKodeMemberBaru(PDO $pdo): string   // meneruskan pola kode yang sudah dipakai (mis. NTX-0012 -> NTX-0013)
{
    $last = aqKodeMemberTerakhir($pdo);
    if ($last !== null && preg_match('/^(.*?)(\d+)$/', $last, $m)) return $m[1] . str_pad((string)((int)$m[2] + 1), strlen($m[2]), '0', STR_PAD_LEFT);
    return 'NTX-0001';
}

// ─── PINTU MASUK ───
function alurProses(PDO $pdo, string $no, string $teks): ?string
{
    $teks = trim($teks);
    if ($teks === '' || alAkhir9($no) === '') return null;
    aqMigrasi($pdo);
    $now = alSekarang();
    aqHapusSesiLama($pdo, (clone $now)->modify('-' . AL_SESI_MENIT . ' minutes')->format('Y-m-d H:i:s'));
    $t = mb_strtolower($teks);
    $sesi = aqSesi($pdo, $no);

    if ($sesi && preg_match('/^(batal|cancel|stop|keluar)\b/u', $t)) {
        aqHapusSesi($pdo, $no);
        return 'Baik, proses dibatalkan ✅ Kapan pun mau mulai lagi, tinggal ketik *daftar*' . (alCoachId($pdo, $no) ? ' atau *progres*' : '') . '.';
    }
    if ($sesi) {
        return $sesi['alur'] === 'daftar' ? alDaftarLangkah($pdo, $no, $sesi, $teks, $now) : alProgresLangkah($pdo, $no, $sesi, $teks, $now);
    }

    $coach = alCoachId($pdo, $no);
    if ($coach && preg_match('/^(menu|bantuan|help)\W*$/u', $t)) {
        return "Halo " . alNamaPelatih($pdo, $coach) . " 👋 Perintah yang tersedia:\n• *progres* — isi progres murid\n• *Check Gaji Bulan Ini* — lihat slip gaji bulan berjalan\n• *batal* — membatalkan isian yang sedang berjalan";
    }
    if ($coach && preg_match('/^(isi |input |catat |laporan )?(progres|progress|perkembangan)\b\s*(.*)$/u', $t, $m)) {
        return alProgresMulai($pdo, $no, $coach, trim($m[3] ?? ''), $now);
    }
    if (mb_strlen($t) <= 60 && preg_match('/^(mau |ingin |pengen |saya mau |kami mau )?(daftar|pendaftaran|registrasi|regist)\b/u', $t) && !preg_match('/daftar\s+(harga|paket|kelas|jadwal|program|tarif|biaya)/u', $t)) {
        return alDaftarMulai($pdo, $no, $now);
    }
    return null;
}

// ============================================================
// ALUR 1 — PENDAFTARAN
// ============================================================
function alDaftarMulai(PDO $pdo, string $no, DateTime $now): string
{
    aqSimpanSesi($pdo, $no, 'daftar', 'nama', [], $now->format('Y-m-d H:i:s'));
    return "Halo Kak! 👋 Terima kasih sudah tertarik berenang bersama *Natix Swim* 🏊\nSaya bantu daftar ya, ada 5 pertanyaan singkat. Ketik *batal* kapan saja untuk berhenti.\n\n1️⃣ Siapa *nama lengkap peserta* yang akan berenang?";
}

function alDaftarLangkah(PDO $pdo, string $no, array $sesi, string $teks, DateTime $now): string
{
    $d = $sesi['data']; $l = $sesi['langkah']; $t = mb_strtolower(trim($teks)); $ts = $now->format('Y-m-d H:i:s');
    $simpan = function (string $lang, array $data) use ($pdo, $no, $ts) { aqSimpanSesi($pdo, $no, 'daftar', $lang, $data, $ts); };

    if ($l === 'nama') {
        $nama = trim(preg_replace('/\s+/u', ' ', $teks));
        if (mb_strlen($nama) < 2 || mb_strlen($nama) > 60 || preg_match('/\d{3,}/', $nama)) return 'Mohon tulis nama lengkap peserta saja ya Kak (huruf, maks. 60 karakter).';
        $d['nama'] = mb_convert_case($nama, MB_CASE_TITLE, 'UTF-8');
        $simpan('lahir', $d);
        return "2️⃣ *Tanggal lahir* {$d['nama']}?\nFormat: tanggal-bulan-tahun, contoh *17-08-2016*";
    }
    if ($l === 'lahir') {
        $tgl = alParseTanggal($teks);
        if (!$tgl) return 'Format tanggalnya belum terbaca Kak 🙏 Mohon tulis seperti *17-08-2016* (tanggal-bulan-tahun).';
        $d['lahir'] = $tgl;
        $simpan('jk', $d);
        return "3️⃣ *Jenis kelamin* {$d['nama']}?\nBalas *L* (laki-laki) atau *P* (perempuan).";
    }
    if ($l === 'jk') {
        if (preg_match('/^(l|laki|laki-laki|pria|cowok)\b/u', $t)) $d['jk'] = 'L';
        elseif (preg_match('/^(p|perempuan|wanita|cewek)\b/u', $t)) $d['jk'] = 'P';
        else return 'Mohon balas *L* untuk laki-laki atau *P* untuk perempuan ya Kak.';
        $simpan('wali', $d);
        return "4️⃣ *Nama orang tua / wali* yang bisa dihubungi?\n(Kalau peserta mendaftar untuk diri sendiri, balas *sendiri*)";
    }
    if ($l === 'wali') {
        $wali = trim(preg_replace('/\s+/u', ' ', $teks));
        if (preg_match('/^(sendiri|saya|diri sendiri)$/u', $t)) $wali = $d['nama'];
        if (mb_strlen($wali) < 2 || mb_strlen($wali) > 60) return 'Mohon tulis nama orang tua / wali (maks. 60 karakter) ya Kak.';
        $d['wali'] = mb_convert_case($wali, MB_CASE_TITLE, 'UTF-8');
        $prog = aqProgramAktif($pdo);
        if (!$prog) {   // belum ada paket: data tetap disimpan, admin yang menawarkan paket
            $d['program_id'] = 0;
            $simpan('konfirm', $d);
            return alRingkasDaftar($d) . "\n\nBelum ada paket yang bisa dipilih lewat chat, jadi admin akan menghubungi untuk paketnya.\nBalas *ya* untuk menyimpan pendaftaran, atau *batal*.";
        }
        $d['pilihan'] = array_map(function ($p) { return (int)$p['id']; }, $prog);
        $simpan('program', $d);
        $baris = [];
        foreach ($prog as $i => $p) $baris[] = ($i + 1) . '. ' . $p['nama_program'] . ' — ' . $p['jumlah_sesi'] . ' sesi — ' . alRp((int)$p['harga']);
        return "5️⃣ Pilih *paket* dengan membalas nomornya:\n" . implode("\n", $baris);
    }
    if ($l === 'program') {
        $idx = (int)preg_replace('/\D+/', '', $teks) - 1;
        if (!isset($d['pilihan'][$idx])) return 'Mohon balas dengan nomor paket yang tersedia ya Kak (contoh: *1*).';
        $d['program_id'] = (int)$d['pilihan'][$idx];
        $simpan('konfirm', $d);
        return alRingkasDaftar($d, aqProgram($pdo, $d['program_id'])) . "\n\nSudah benar? Balas *ya* untuk mendaftar, *ulang* untuk mengisi dari awal, atau *batal*.";
    }
    if ($l === 'konfirm') {
        if (preg_match('/^(ulang|ulangi|salah)\b/u', $t)) { $simpan('nama', []); return "Oke, kita ulang ya 🙌\n1️⃣ Siapa *nama lengkap peserta*?"; }
        if (!preg_match('/^(ya|y|ok|oke|benar|betul|sudah|siap|iya)\b/u', $t)) return 'Balas *ya* untuk mendaftar, *ulang* untuk mengisi dari awal, atau *batal* untuk berhenti.';
        return alDaftarSelesai($pdo, $no, $d, $now);
    }
    aqHapusSesi($pdo, $no);
    return 'Maaf Kak, ada yang keliru. Ketik *daftar* untuk mengulang ya.';
}

function alRingkasDaftar(array $d, ?array $prog = null): string
{
    $s = "Berikut data pendaftarannya:\n• Nama: {$d['nama']}\n• Tanggal lahir: " . alTglIndo($d['lahir']) . "\n• Jenis kelamin: " . ($d['jk'] === 'L' ? 'Laki-laki' : 'Perempuan') . "\n• Orang tua / wali: {$d['wali']}";
    if ($prog) $s .= "\n• Paket: {$prog['nama_program']} ({$prog['jumlah_sesi']} sesi)\n• Biaya: " . alRp((int)$prog['harga']);
    return $s;
}

function alDaftarSelesai(PDO $pdo, string $no, array $d, DateTime $now): string
{
    $akhir = alAkhir9($no);
    $prog = !empty($d['program_id']) ? aqProgram($pdo, (int)$d['program_id']) : null;
    $mid = 0; $baru = true;
    foreach (aqMemberDenganNomor($pdo) as $m) {   // peserta yang sama (nomor & nama sama) tidak dibuat dua kali
        if (alAkhir9($m['no_whatsapp']) === $akhir && mb_strtolower(trim($m['nama'])) === mb_strtolower($d['nama'])) { $mid = (int)$m['id']; $baru = false; break; }
    }
    $nomorTagihan = null;
    $pdo->beginTransaction();
    try {
        if (!$mid) {
            for ($i = 0; ; $i++) {
                try { $mid = aqTambahMember($pdo, ['nama' => $d['nama'], 'lahir' => $d['lahir'], 'jk' => $d['jk'], 'wali' => $d['wali'], 'no' => '+' . gjNomor($no)], alKodeMemberBaru($pdo)); break; }
                catch (PDOException $e) { if ($i >= 4 || (string)$e->getCode() !== '23000') throw $e; }
            }
        }
        if ($prog) $nomorTagihan = aqTambahTagihan($pdo, $mid, (int)$prog['id'], (int)$prog['harga'], (clone $now)->modify('+' . AL_JATUH_TEMPO_HARI . ' days')->format('Y-m-d'), 'Pendaftaran via WhatsApp');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[alur] daftar gagal: ' . $e->getMessage());
        aqHapusSesi($pdo, $no);
        return 'Maaf Kak, pendaftaran belum berhasil tersimpan 🙏 Admin kami akan membantu ya.';
    }
    aqHapusSesi($pdo, $no);
    $s = "Pendaftaran *{$d['nama']}* berhasil dicatat ✅" . ($baru ? '' : ' (data peserta sudah ada, tidak dibuat ganda)');
    if ($nomorTagihan) {
        $s .= "\n\n🧾 Tagihan: *$nomorTagihan*\nPaket: {$prog['nama_program']} ({$prog['jumlah_sesi']} sesi)\nTotal: *" . alRp((int)$prog['harga']) . "*\nJatuh tempo: " . alTglIndo((clone $now)->modify('+' . AL_JATUH_TEMPO_HARI . ' days')->format('Y-m-d')) .
            "\n\nSilakan transfer, lalu *kirim foto bukti transfer di chat ini*. Admin akan memverifikasi, lalu jadwal latihan diatur 🏊";
    } else $s .= "\n\nAdmin kami akan segera menghubungi untuk memilih paket dan jadwal 🙏";
    return $s;
}

// ============================================================
// ALUR 2 — PROGRES MURID (khusus coach)
// ============================================================
function alProgresMulai(PDO $pdo, string $no, int $coach, string $nama, DateTime $now): string
{
    $murid = aqMuridPelatih($pdo, $coach, (clone $now)->modify('-45 days')->format('Y-m-d'));
    $d = ['coach' => $coach, 'murid' => array_map(function ($m) { return ['id' => (int)$m['id'], 'nama' => $m['nama']]; }, array_slice($murid, 0, 15))];
    if ($nama !== '') {   // "progres Budi" -> langsung pilih kalau cocok satu murid (murid coach dulu, lalu semua member)
        $cocok = array_values(array_filter($d['murid'], function ($m) use ($nama) { return mb_stripos($m['nama'], $nama) !== false; }));
        if (count($cocok) !== 1) $cocok = array_map(function ($m) { return ['id' => (int)$m['id'], 'nama' => $m['nama']]; }, aqCariMember($pdo, $nama));
        if (count($cocok) === 1) return alProgresPilih($pdo, $no, $d, $cocok[0], $now);
        if (count($cocok) > 1) {
            $d['murid'] = $cocok;
            aqSimpanSesi($pdo, $no, 'progres', 'murid', $d, $now->format('Y-m-d H:i:s'));
            $baris = []; foreach ($cocok as $i => $m) $baris[] = ($i + 1) . '. ' . $m['nama'];
            return "Ada beberapa nama yang cocok, balas nomornya:\n" . implode("\n", $baris);
        }
        return "Murid bernama \"$nama\" tidak ditemukan di Data Member 🙏";
    }
    if (!$murid) return "Belum ada murid di jadwal / paket Coach 🙏 Ketik *progres <nama murid>* untuk mencari murid, misalnya *progres Andi*.";
    aqSimpanSesi($pdo, $no, 'progres', 'murid', $d, $now->format('Y-m-d H:i:s'));
    $baris = [];
    foreach ($d['murid'] as $i => $m) $baris[] = ($i + 1) . '. ' . $m['nama'];
    return "Halo " . alNamaPelatih($pdo, $coach) . " 👋 Progres siapa yang mau diisi? Balas nomor atau nama murid:\n" . implode("\n", $baris);
}

function alProgresPilih(PDO $pdo, string $no, array $d, array $m, DateTime $now): string
{
    $d['member_id'] = $m['id']; $d['nama'] = $m['nama'];
    aqSimpanSesi($pdo, $no, 'progres', 'level', $d, $now->format('Y-m-d H:i:s'));
    $baris = [];
    foreach (AL_LEVEL as $k => $v) $baris[] = "$k. $v";
    return "Progres *{$m['nama']}* 🏊\n\n1️⃣ *Level kemampuan* saat ini? Balas nomor:\n" . implode("\n", $baris);
}

function alProgresLangkah(PDO $pdo, string $no, array $sesi, string $teks, DateTime $now): string
{
    $d = $sesi['data']; $l = $sesi['langkah']; $t = mb_strtolower(trim($teks)); $ts = $now->format('Y-m-d H:i:s');
    $simpan = function (string $lang, array $data) use ($pdo, $no, $ts) { aqSimpanSesi($pdo, $no, 'progres', $lang, $data, $ts); };

    if ($l === 'murid') {
        $pilih = null;
        if (preg_match('/^\d+$/', $t)) $pilih = $d['murid'][(int)$t - 1] ?? null;
        else {
            $cocok = array_values(array_filter($d['murid'], function ($m) use ($t) { return mb_stripos($m['nama'], $t) !== false; }));
            if (!$cocok) $cocok = array_map(function ($m) { return ['id' => (int)$m['id'], 'nama' => $m['nama']]; }, aqCariMember($pdo, $t));   // murid belum di jadwal coach
            if (count($cocok) === 1) $pilih = $cocok[0];
            elseif (count($cocok) > 1) return 'Ada beberapa nama yang cocok, mohon balas dengan *nomornya* ya Coach.';
        }
        return $pilih ? alProgresPilih($pdo, $no, $d, $pilih, $now) : 'Murid tidak ditemukan. Balas nomor dari daftar, atau tulis nama murid yang terdaftar ya Coach.';
    }
    if ($l === 'level') {
        $n = (int)$t;
        if (!isset(AL_LEVEL[$n])) return 'Balas dengan nomor level 1–5 ya Coach.';
        $d['level'] = $n;
        $simpan('nilai', $d);
        return "2️⃣ *Nilai (1–5)* untuk 3 aspek, tulis berurutan dipisah spasi:\n*teknik  stamina  percaya diri*\nContoh: *4 3 5*";
    }
    if ($l === 'nilai') {
        if (!preg_match('/^([1-5])\D+([1-5])\D+([1-5])$/', trim($teks), $m)) return 'Mohon tulis 3 angka 1–5 dipisah spasi, contoh: *4 3 5* (teknik, stamina, percaya diri).';
        [$d['teknik'], $d['stamina'], $d['percaya_diri']] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        $simpan('catatan', $d);
        return "3️⃣ *Catatan* perkembangan untuk orang tua? (misal: sudah berani meluncur 10 m)\nBalas *-* kalau tidak ada.";
    }
    if ($l === 'catatan') {
        $cat = trim($teks);
        $d['catatan'] = $cat === '-' ? '' : mb_substr($cat, 0, 500);
        $simpan('konfirm', $d);
        return alRingkasProgres($d) . "\n\nSimpan? Balas *ya*, *ulang*, atau *batal*." . (AL_KIRIM_ORTU ? "\n(Laporan juga otomatis dikirim ke WhatsApp orang tua.)" : '');
    }
    if ($l === 'konfirm') {
        if (preg_match('/^(ulang|ulangi|salah)\b/u', $t)) { unset($d['member_id']); $simpan('murid', $d); return 'Oke, pilih murid lagi: balas nomor atau sebagian nama.'; }
        if (!preg_match('/^(ya|y|ok|oke|simpan|iya|benar)\b/u', $t)) return 'Balas *ya* untuk menyimpan, *ulang*, atau *batal*.';
        return alProgresSelesai($pdo, $no, $d, $now);
    }
    aqHapusSesi($pdo, $no);
    return 'Maaf Coach, ada yang keliru. Ketik *progres* untuk mengulang.';
}

function alRingkasProgres(array $d): string
{
    return "Ringkasan progres *{$d['nama']}*:\n• Level: " . AL_LEVEL[$d['level']] . "\n• Teknik: " . alBintang($d['teknik']) . "\n• Stamina: " . alBintang($d['stamina']) . "\n• Percaya diri: " . alBintang($d['percaya_diri']) .
        "\n• Catatan: " . ($d['catatan'] !== '' ? $d['catatan'] : '-');
}

function alProgresSelesai(PDO $pdo, string $no, array $d, DateTime $now): string
{
    $coach = (int)$d['coach'];
    try {
        $id = aqTambahProgres($pdo, [
            'member_id' => (int)$d['member_id'], 'pelatih_id' => $coach,
            'jadwal_id' => aqJadwalTerakhirMurid($pdo, (int)$d['member_id'], $coach, $now->format('Y-m-d')),
            'tanggal' => $now->format('Y-m-d'), 'level' => $d['level'], 'teknik' => $d['teknik'], 'stamina' => $d['stamina'],
            'percaya_diri' => $d['percaya_diri'], 'catatan' => $d['catatan'] !== '' ? $d['catatan'] : null, 'sumber' => 'whatsapp',
        ], $now->format('Y-m-d H:i:s'));
    } catch (Throwable $e) {
        error_log('[alur] progres gagal: ' . $e->getMessage());
        aqHapusSesi($pdo, $no);
        return 'Maaf Coach, progres belum berhasil tersimpan 🙏 Coba lagi sebentar lagi.';
    }
    aqHapusSesi($pdo, $no);
    $s = "Progres *{$d['nama']}* tersimpan ✅ Bisa dilihat di halaman *Progres Murid*.";
    if (AL_KIRIM_ORTU) {
        [$ok, $pesan] = alKirimOrtu($pdo, $id);
        $s .= $ok ? "\nLaporan sudah dikirim ke orang tua 📨" : "\n⚠️ Laporan ke orang tua belum terkirim: $pesan\n(Admin bisa kirim ulang dari halaman Progres Murid.)";
    }
    return $s;
}

function alPesanOrtu(array $p): string
{
    $wali = trim((string)($p['nama_wali'] ?? ''));
    return "Halo " . ($wali !== '' ? $wali : 'Kak') . " 👋\nBerikut laporan perkembangan *{$p['murid']}* di Natix Swim:\n\n📅 " . alTglIndo($p['tanggal']) .
        "\n🏊 Level: " . (AL_LEVEL[(int)$p['level_skill']] ?? '-') .
        "\n• Teknik: " . alBintang((int)$p['nilai_teknik']) . "\n• Stamina: " . alBintang((int)$p['nilai_stamina']) . "\n• Percaya diri: " . alBintang((int)$p['nilai_percaya_diri']) .
        (trim((string)$p['catatan']) !== '' ? "\n\n📝 Catatan Coach: " . $p['catatan'] : '') .
        "\n\nTerima kasih sudah mempercayakan latihan berenang di Natix Swim 🙏";
}

// Kirim laporan ke WhatsApp orang tua (Cloud API Meta atau gateway Baileys, sesuai WA_PROVIDER) -> [ok, pesan]
function alKirimOrtu(PDO $pdo, int $id): array
{
    $p = aqAmbilProgres($pdo, $id);
    if (!$p) return [false, 'Data progres tidak ditemukan.'];
    $no = gjNomor($p['no_whatsapp'] ?? '');
    if ($no === '') { aqSetOrtu($pdo, $id, 'gagal', 'Nomor WhatsApp orang tua belum diisi.'); return [false, 'Nomor WhatsApp orang tua belum diisi di Data Member.']; }
    if (!empty($GLOBALS['AL_SIMULASI'])) {   // Simulator Chat: pesan ditampilkan di layar, tidak lewat gateway WhatsApp
        $GLOBALS['AL_OUTBOX'][] = ['to' => $no, 'text' => alPesanOrtu($p)];
        aqSetOrtu($pdo, $id, 'terkirim', null);
        return [true, 'Terkirim (simulasi).'];
    }
    try {
        gjKirimPesan($no, alPesanOrtu($p));
        aqSetOrtu($pdo, $id, 'terkirim', null);
        return [true, 'Terkirim.'];
    } catch (Throwable $e) {
        aqSetOrtu($pdo, $id, 'gagal', $e->getMessage());
        return [false, $e->getMessage()];
    }
}

// ─── Ringkasan untuk halaman Progres Murid ───
function alRingkas(array $baris): array
{
    $n = count($baris); $jum = 0; $murid = []; $ortu = 0;
    foreach ($baris as $b) {
        $jum += ($b['nilai_teknik'] + $b['nilai_stamina'] + $b['nilai_percaya_diri']) / 3;
        $murid[(int)$b['member_id']] = true;
        if ($b['ortu_status'] === 'terkirim') $ortu++;
    }
    return ['total' => $n, 'murid' => count($murid), 'rata' => $n ? round($jum / $n, 1) : 0, 'ortu' => $ortu];
}

PHPEND
,
'alur-query.php' => <<<'PHPEND'
<?php
// ============================================================
// alur-query.php — SEMUA query SQL alur chatbot WhatsApp (pendaftaran → tagihan → progres murid)
// Hanya baca / tulis tabel dan mengembalikan array mentah. Logika percakapan ada di alur-fungsi.php.
// Tabel baru (dibuat otomatis): chat_sesi, progres_murid
// Tabel yang dipakai (sudah ada): members, kelas_program, tagihan, paket_member, jadwal, jadwal_peserta, pelatih, users
// ============================================================

function aqAdaKolom(PDO $pdo, string $tabel, string $kolom): bool
{
    try { $pdo->query("SELECT $kolom FROM $tabel LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

function aqDdl(PDO $pdo, string $sql): string   // DDL ditulis untuk MySQL; driver lain (uji coba) disesuaikan
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') return $sql;
    $sql = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/(UNIQUE )?KEY \w+ \(([^)]*)\),?\n?/', '', $sql);
    $sql = preg_replace('/,\s*\) ENGINE=.*$/', ')', $sql);
    return preg_replace('/\) ENGINE=.*$/', ')', $sql);
}

function aqMigrasi(PDO $pdo): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $my = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    foreach ([
        "CREATE TABLE IF NOT EXISTS chat_sesi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nomor VARCHAR(20) NOT NULL,
            alur VARCHAR(20) NOT NULL,
            langkah VARCHAR(20) NOT NULL,
            data TEXT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_chat_sesi_nomor (nomor)
        )$my",
        "CREATE TABLE IF NOT EXISTS progres_murid (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            pelatih_id INT NULL,
            jadwal_id INT NULL,
            tanggal DATE NOT NULL,
            level_skill TINYINT NOT NULL DEFAULT 1,
            nilai_teknik TINYINT NOT NULL DEFAULT 0,
            nilai_stamina TINYINT NOT NULL DEFAULT 0,
            nilai_percaya_diri TINYINT NOT NULL DEFAULT 0,
            catatan TEXT NULL,
            sumber VARCHAR(12) NOT NULL DEFAULT 'whatsapp',
            ortu_status VARCHAR(10) NOT NULL DEFAULT 'belum',
            ortu_error VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_progres_member (member_id),
            KEY idx_progres_tanggal (tanggal)
        )$my",
    ] as $ddl) $pdo->exec(aqDdl($pdo, $ddl));
}

// ─── SESI PERCAKAPAN ───
function aqSesi(PDO $pdo, string $no): ?array
{
    $st = $pdo->prepare('SELECT * FROM chat_sesi WHERE nomor = :n');
    $st->execute([':n' => $no]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['data'] = json_decode((string)$r['data'], true) ?: [];
    return $r;
}

function aqSimpanSesi(PDO $pdo, string $no, string $alur, string $langkah, array $data, string $now): void
{
    $j = json_encode($data, JSON_UNESCAPED_UNICODE);
    $st = $pdo->prepare('UPDATE chat_sesi SET alur = :a, langkah = :l, data = :d, updated_at = :u WHERE nomor = :n');
    $st->execute([':a' => $alur, ':l' => $langkah, ':d' => $j, ':u' => $now, ':n' => $no]);
    if ($st->rowCount() === 0 && !aqSesi($pdo, $no)) {
        $pdo->prepare('INSERT INTO chat_sesi (nomor, alur, langkah, data, updated_at) VALUES (:n,:a,:l,:d,:u)')
            ->execute([':n' => $no, ':a' => $alur, ':l' => $langkah, ':d' => $j, ':u' => $now]);
    }
}

function aqHapusSesi(PDO $pdo, string $no): void
{
    $pdo->prepare('DELETE FROM chat_sesi WHERE nomor = :n')->execute([':n' => $no]);
}

function aqHapusSesiLama(PDO $pdo, string $batas): void
{
    $pdo->prepare('DELETE FROM chat_sesi WHERE updated_at < :b')->execute([':b' => $batas]);
}

// ─── PENDAFTARAN ───
function aqProgramAktif(PDO $pdo): array
{
    $f = aqAdaKolom($pdo, 'kelas_program', 'status') ? " WHERE status = 'aktif'" : '';
    return $pdo->query("SELECT id, nama_program, harga, jumlah_sesi FROM kelas_program$f ORDER BY nama_program")->fetchAll(PDO::FETCH_ASSOC);
}

function aqProgram(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, nama_program, harga, jumlah_sesi FROM kelas_program WHERE id = :i');
    $st->execute([':i' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function aqMemberDenganNomor(PDO $pdo): array   // semua member yang punya nomor WA (dicocokkan di PHP karena format nomor beragam)
{
    return $pdo->query("SELECT id, nama, no_whatsapp FROM members WHERE no_whatsapp IS NOT NULL AND no_whatsapp <> ''")->fetchAll(PDO::FETCH_ASSOC);
}

function aqKodeMemberTerakhir(PDO $pdo): ?string
{
    $r = $pdo->query("SELECT kode_member FROM members WHERE kode_member IS NOT NULL AND kode_member <> '' ORDER BY id DESC LIMIT 1")->fetchColumn();
    return $r !== false ? (string)$r : null;
}

function aqTambahMember(PDO $pdo, array $m, string $kode): int
{
    $kol = ['nama', 'tanggal_lahir', 'jenis_kelamin', 'nama_wali', 'no_whatsapp', 'status', 'kode_member'];
    $val = [':nama' => $m['nama'], ':tanggal_lahir' => $m['lahir'], ':jenis_kelamin' => $m['jk'], ':nama_wali' => $m['wali'], ':no_whatsapp' => $m['no'], ':status' => 'aktif', ':kode_member' => $kode];
    $pdo->prepare('INSERT INTO members (' . implode(',', $kol) . ') VALUES (' . implode(',', array_keys($val)) . ')')->execute($val);
    return (int)$pdo->lastInsertId();
}

function aqNomorTagihanBaru(PDO $pdo, int $geser = 0): string
{
    $pre = 'INV-' . date('ym') . '-';
    $st = $pdo->prepare('SELECT nomor_tagihan FROM tagihan WHERE nomor_tagihan LIKE :p');
    $st->execute([':p' => $pre . '%']);
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) $max = max($max, (int)substr($n, strlen($pre)));
    return $pre . str_pad((string)($max + 1 + $geser), 3, '0', STR_PAD_LEFT);
}

function aqTambahTagihan(PDO $pdo, int $memberId, int $programId, int $jumlah, string $tempo, string $catatan): string
{
    for ($i = 0; ; $i++) {
        $nomor = aqNomorTagihanBaru($pdo, $i);
        try {
            $pdo->prepare("INSERT INTO tagihan (nomor_tagihan, member_id, paket_id, program_id, jumlah, diskon, jatuh_tempo, status, catatan)
                           VALUES (:n, :m, NULL, :p, :j, 0, :t, 'belum_bayar', :c)")
                ->execute([':n' => $nomor, ':m' => $memberId, ':p' => $programId, ':j' => $jumlah, ':t' => $tempo, ':c' => $catatan]);
            return $nomor;
        } catch (PDOException $e) {
            if ($i >= 5 || (string)$e->getCode() !== '23000') throw $e;   // nomor bentrok -> coba nomor berikutnya
        }
    }
}

// ─── PROGRES MURID ───
function aqExprHp(PDO $pdo): string
{
    $dp = aqAdaKolom($pdo, 'pelatih', 'no_hp');
    $du = aqAdaKolom($pdo, 'users', 'no_hp');
    if ($dp && $du) return "COALESCE(NULLIF(pl.no_hp, ''), u.no_hp)";
    if ($dp) return 'pl.no_hp';
    if ($du) return 'u.no_hp';
    return 'NULL';
}

function aqDaftarPelatih(PDO $pdo): array   // -> [id, nama, hp]
{
    $hp = aqExprHp($pdo);
    return $pdo->query("SELECT pl.id, u.nama, $hp AS hp FROM pelatih pl JOIN users u ON u.id = pl.user_id ORDER BY u.nama")->fetchAll(PDO::FETCH_ASSOC);
}

// Murid milik coach: peserta jadwalnya 45 hari terakhir + paket aktif yang menunjuk coach ini
function aqMuridPelatih(PDO $pdo, int $pelatihId, string $sejak): array
{
    $ids = [];
    $st = $pdo->prepare('SELECT DISTINCT jp.member_id FROM jadwal_peserta jp JOIN jadwal j ON j.id = jp.jadwal_id WHERE j.pelatih_id = :p AND j.tanggal >= :s');
    $st->execute([':p' => $pelatihId, ':s' => $sejak]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i) $ids[(int)$i] = true;
    if (aqAdaKolom($pdo, 'paket_member', 'pelatih_id')) {
        $st = $pdo->prepare("SELECT DISTINCT member_id FROM paket_member WHERE pelatih_id = :p AND status = 'aktif'");
        $st->execute([':p' => $pelatihId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i) $ids[(int)$i] = true;
    }
    if (!$ids) return [];
    $in = implode(',', array_keys($ids));
    return $pdo->query("SELECT id, nama, no_whatsapp, nama_wali FROM members WHERE id IN ($in) ORDER BY nama")->fetchAll(PDO::FETCH_ASSOC);
}

function aqCariMember(PDO $pdo, string $nama): array   // cari di semua member (murid yang belum masuk jadwal coach)
{
    $st = $pdo->prepare('SELECT id, nama, no_whatsapp, nama_wali FROM members WHERE nama LIKE :q ORDER BY nama LIMIT 6');
    $st->execute([':q' => '%' . $nama . '%']);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function aqMember(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, nama, no_whatsapp, nama_wali FROM members WHERE id = :i');
    $st->execute([':i' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function aqJadwalTerakhirMurid(PDO $pdo, int $memberId, int $pelatihId, string $sampai): ?int   // sesi terakhir (sudah lewat) murid bersama coach ini
{
    $st = $pdo->prepare("SELECT j.id FROM jadwal j JOIN jadwal_peserta jp ON jp.jadwal_id = j.id
                          WHERE jp.member_id = :m AND j.pelatih_id = :p AND j.tanggal <= :t AND j.status <> 'dibatalkan'
                          ORDER BY j.tanggal DESC, j.id DESC LIMIT 1");
    $st->execute([':m' => $memberId, ':p' => $pelatihId, ':t' => $sampai]);
    $r = $st->fetchColumn();
    return $r === false ? null : (int)$r;
}

function aqTambahProgres(PDO $pdo, array $p, string $now): int
{
    $pdo->prepare('INSERT INTO progres_murid (member_id, pelatih_id, jadwal_id, tanggal, level_skill, nilai_teknik, nilai_stamina, nilai_percaya_diri, catatan, sumber, created_at)
                   VALUES (:m,:pl,:j,:t,:lv,:a,:b,:c,:cat,:s,:now)')
        ->execute([':m' => $p['member_id'], ':pl' => $p['pelatih_id'], ':j' => $p['jadwal_id'], ':t' => $p['tanggal'], ':lv' => $p['level'],
                   ':a' => $p['teknik'], ':b' => $p['stamina'], ':c' => $p['percaya_diri'], ':cat' => $p['catatan'], ':s' => $p['sumber'] ?? 'whatsapp', ':now' => $now]);
    return (int)$pdo->lastInsertId();
}

function aqSetOrtu(PDO $pdo, int $id, string $status, ?string $error): void
{
    $pdo->prepare('UPDATE progres_murid SET ortu_status = :s, ortu_error = :e WHERE id = :i')->execute([':s' => $status, ':e' => $error !== null ? mb_substr($error, 0, 250) : null, ':i' => $id]);
}

function aqProgresBulan(PDO $pdo, string $awal, string $akhir, int $pelatihId = 0, string $cari = ''): array
{
    $sql = "SELECT pg.*, m.nama AS murid, m.kode_member, m.nama_wali, m.no_whatsapp, u.nama AS pelatih
              FROM progres_murid pg
              JOIN members m ON m.id = pg.member_id
              LEFT JOIN pelatih pl ON pl.id = pg.pelatih_id
              LEFT JOIN users u ON u.id = pl.user_id
             WHERE pg.tanggal BETWEEN :s AND :e";
    $par = [':s' => $awal, ':e' => $akhir];
    if ($pelatihId > 0) { $sql .= ' AND pg.pelatih_id = :p'; $par[':p'] = $pelatihId; }
    if ($cari !== '') { $sql .= ' AND (m.nama LIKE :q1 OR m.kode_member LIKE :q2)'; $par[':q1'] = $par[':q2'] = '%' . $cari . '%'; }
    $st = $pdo->prepare($sql . ' ORDER BY pg.tanggal DESC, pg.id DESC');
    $st->execute($par);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function aqAmbilProgres(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT pg.*, m.nama AS murid, m.kode_member, m.nama_wali, m.no_whatsapp, u.nama AS pelatih
                           FROM progres_murid pg JOIN members m ON m.id = pg.member_id
                           LEFT JOIN pelatih pl ON pl.id = pg.pelatih_id LEFT JOIN users u ON u.id = pl.user_id WHERE pg.id = :i");
    $st->execute([':i' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function aqRiwayatMurid(PDO $pdo, int $memberId, int $batas = 6): array
{
    $st = $pdo->prepare('SELECT tanggal, level_skill, nilai_teknik, nilai_stamina, nilai_percaya_diri FROM progres_murid WHERE member_id = :m ORDER BY tanggal DESC, id DESC LIMIT ' . (int)$batas);
    $st->execute([':m' => $memberId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function aqHapusProgres(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM progres_murid WHERE id = :i')->execute([':i' => $id]);
}

PHPEND
,
'gaji.php' => <<<'PHPEND'
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

PHPEND
,
'gaji-fungsi.php' => <<<'PHPEND'
<?php
// ============================================================
// gaji-fungsi.php — logika Gaji Coach (gaji per sesi): hitung, alur status, slip, WhatsApp
// Tidak ada SQL di sini — semua akses database lewat gaji-query.php.
// Dipakai oleh: gaji.php (halaman admin), cron-gaji.php (kirim slip otomatis),
//               manajemen-ai-chat.php (chat WhatsApp "Check Gaji Bulan Ini")
// ============================================================
require_once __DIR__ . '/gaji-query.php';
if (is_file(__DIR__ . '/config/wa-config.php')) require_once __DIR__ . '/config/wa-config.php';   // pengaturan WhatsApp bersama (provider, token Meta)

foreach ([
    'WA_PROVIDER' => [getenv('NATIX_WA_PROVIDER'), 'baileys'],
    'WA_TOKEN'    => [getenv('NATIX_WA_TOKEN'),    'ISI_ACCESS_TOKEN'],
    'WA_PHONE_ID' => [getenv('NATIX_WA_PHONE_ID'), 'ISI_PHONE_NUMBER_ID'],
    'WA_BASE'     => [getenv('NATIX_WA_BASE'),     'https://graph.facebook.com'],
    'GW_URL'   => [getenv('NATIX_GW_URL'),   'http://127.0.0.1:3001'],
    'GW_TOKEN' => [getenv('NATIX_GW_TOKEN'), 'natix-gateway-secret'],
] as $k => [$env, $def]) if (!defined($k)) define($k, $env ?: $def);

const GJ_BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const GJ_JENIS = ['kids' => 'Kids', 'dewasa' => 'Dewasa', 'private' => 'Private'];
const GJ_TARIF_AWAL = ['kids' => 75000, 'dewasa' => 100000, 'private' => 150000];   // nilai awal saja — ubah di tab "Tarif per Sesi"
const GJ_METODE = ['transfer' => 'Transfer', 'tunai' => 'Tunai'];

// ─── FORMAT & BANTUAN ───
function gjRp($n): string
{
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}

function gjTglIndo(?string $ymd): string
{
    if (!$ymd) return '-';
    $t = strtotime($ymd);
    return date('j', $t) . ' ' . substr(GJ_BULAN[(int)date('n', $t)], 0, 3) . ' ' . date('Y', $t);
}

function gjKategori(string $tipe, string $nama): string
{
    if ($tipe === 'private' || stripos($nama, 'private') !== false) return 'private';
    if (preg_match('/kids|anak|baby|junior/i', $nama)) return 'kids';
    return 'dewasa';
}

function gjNamaCoach(string $n): string
{
    return preg_match('/^coach\b/i', $n) ? $n : 'Coach ' . $n;
}

function gjNomor(?string $s): string   // -> 62812xxxx ('' kalau tidak valid)
{
    $d = preg_replace('/\D+/', '', (string)$s);
    if ($d === '') return '';
    if ($d[0] === '0') $d = '62' . substr($d, 1);
    elseif ($d[0] === '8') $d = '62' . $d;
    return (strlen($d) >= 10 && strlen($d) <= 15) ? $d : '';
}

function gjTotal(int $gajiSesi, int $bonus, int $potongan): int
{
    return max(0, $gajiSesi + $bonus - $potongan);
}

function gjMigrasi(PDO $pdo): void
{
    static $selesai = false;
    if ($selesai) return;
    gqMigrasi($pdo, GJ_TARIF_AWAL);
    $selesai = true;
}

// ─── PENGATURAN ───
function gjPengaturan(PDO $pdo, string $kunci, string $def = ''): string
{
    $v = gqPengaturan($pdo, $kunci);
    return $v === null ? $def : $v;
}

function gjSimpanPengaturan(PDO $pdo, string $kunci, string $nilai): void
{
    gqSimpanPengaturan($pdo, $kunci, $nilai);
}

// ─── TARIF ───
// -> [pelatih_id][jenis] = tarif   (pelatih_id 0 = tarif standar semua coach)
function gjTarifMap(PDO $pdo): array
{
    $m = [];
    foreach (gqTarifSemua($pdo) as $r) $m[(int)$r['pelatih_id']][$r['jenis']] = (int)$r['tarif'];
    return $m;
}

function gjTarif(array $map, int $pid, string $jenis): int
{
    return $map[$pid][$jenis] ?? $map[0][$jenis] ?? 0;
}

function gjSimpanTarif(PDO $pdo, int $pid, string $jenis, ?int $tarif): void
{
    if (!isset(GJ_JENIS[$jenis])) throw new InvalidArgumentException('Jenis kelas tidak dikenal.');
    if ($pid > 0 && $tarif === null) { gqHapusTarif($pdo, $pid, $jenis); return; }   // kosong = ikut tarif standar
    if ($tarif === null || $tarif < 0 || $tarif > 100000000) throw new InvalidArgumentException('Tarif harus berupa angka 0 – 100.000.000.');
    gqSetTarif($pdo, $pid, $jenis, $tarif);
}

// Daftar pelatih + tarif khususnya (null = ikut standar) untuk tab Tarif
function gjDaftarCoach(PDO $pdo): array
{
    $map = gjTarifMap($pdo);
    $out = [];
    foreach (gqDaftarPelatih($pdo) as $c) {
        $t = [];
        foreach (GJ_JENIS as $k => $_) $t[$k] = $map[(int)$c['id']][$k] ?? null;
        $out[] = ['pelatih_id' => (int)$c['id'], 'nama' => gjNamaCoach((string)$c['nama']), 'hp' => (string)($c['hp'] ?? ''), 'tarif' => $t];
    }
    return $out;
}

function gjTarifStandar(PDO $pdo): array
{
    $map = gjTarifMap($pdo);
    $std = [];
    foreach (GJ_JENIS as $k => $_) $std[$k] = gjTarif($map, 0, $k);
    return $std;
}

// ─── SESI MENGAJAR ───
// Sesi yang dihitung: jadwal bulan tsb, tidak dibatalkan, dan sudah berlalu (atau berstatus selesai).
// -> [pelatih_id => ['nama','hp','sesi'=>[kids,dewasa,private]]]
function gjSesi(PDO $pdo, int $tahun, int $bulan, ?DateTime $sekarang = null): array
{
    $sekarang = $sekarang ?: new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $awal  = sprintf('%04d-%02d-01', $tahun, $bulan);
    $akhir = date('Y-m-t', strtotime($awal));
    $hari  = $sekarang->format('Y-m-d');
    $jamNow = $sekarang->format('H:i:s');
    $out = [];
    foreach (gqJadwalBulan($pdo, $awal, $akhir) as $r) {
        $lewat = $r['status'] === 'selesai' || $r['tanggal'] < $hari || ($r['tanggal'] === $hari && substr((string)$r['jam_selesai'], 0, 8) <= $jamNow);
        if (!$lewat) continue;
        $pid = (int)$r['pelatih_id'];
        if (!isset($out[$pid])) $out[$pid] = ['nama' => $r['pelatih'], 'hp' => $r['hp'], 'sesi' => ['kids' => 0, 'dewasa' => 0, 'private' => 0]];
        $out[$pid]['sesi'][gjKategori((string)$r['tipe'], (string)$r['nama_program'])]++;
    }
    return $out;
}

// ─── HITUNG GAJI ───
// Buat / segarkan DRAFT gaji satu periode. Gaji yang sudah disetujui / dibayar tidak disentuh.
function gjHitung(PDO $pdo, int $tahun, int $bulan, ?DateTime $sekarang = null): int
{
    gjMigrasi($pdo);
    $sekarang = $sekarang ?: new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $tarif = gjTarifMap($pdo);
    $sesi = gjSesi($pdo, $tahun, $bulan, $sekarang);
    $now = $sekarang->format('Y-m-d H:i:s');
    $n = 0;
    foreach ($sesi as $pid => $d) {
        $rin = []; $gaji = 0;
        foreach (GJ_JENIS as $kode => $label) {
            $jml = (int)$d['sesi'][$kode];
            $t = gjTarif($tarif, $pid, $kode);
            $rin[] = ['jenis' => $kode, 'label' => $label, 'sesi' => $jml, 'tarif' => $t, 'subtotal' => $jml * $t];
            $gaji += $jml * $t;
        }
        $json = json_encode($rin, JSON_UNESCAPED_UNICODE);
        $row = gqGajiPeriodePelatih($pdo, $tahun, $bulan, $pid);
        if (!$row) {
            gqTambahGaji($pdo, $tahun, $bulan, $pid, $d['sesi'], $gaji, gjTotal($gaji, 0, 0), $json, $now);
            $n++;
        } elseif ($row['status'] === 'draft') {
            gqUpdateDraft($pdo, (int)$row['id'], $d['sesi'], $gaji, gjTotal($gaji, (int)$row['bonus'], (int)$row['potongan']), $json, $now);
            $n++;
        }
    }
    // Draft yang sesinya sudah tidak ada lagi (mis. jadwal dibatalkan) -> nol
    $nol = ['kids' => 0, 'dewasa' => 0, 'private' => 0];
    foreach (gqDraftPeriode($pdo, $tahun, $bulan) as $r) {
        if (isset($sesi[(int)$r['pelatih_id']])) continue;
        gqUpdateDraft($pdo, (int)$r['id'], $nol, 0, gjTotal(0, (int)$r['bonus'], (int)$r['potongan']), null, $now);
    }
    return $n;
}

function gjBaris(array $r): array
{
    $rin = json_decode((string)($r['rincian'] ?? ''), true);
    if (!is_array($rin)) $rin = [];
    return [
        'id' => (int)$r['id'], 'pelatih_id' => (int)$r['pelatih_id'], 'nama' => gjNamaCoach((string)$r['nama']), 'hp' => (string)($r['hp'] ?? ''),
        'tahun' => (int)$r['tahun'], 'bulan' => (int)$r['bulan'],
        'sesi' => ['kids' => (int)$r['sesi_kids'], 'dewasa' => (int)$r['sesi_dewasa'], 'private' => (int)$r['sesi_private']],
        'jml_sesi' => (int)$r['sesi_kids'] + (int)$r['sesi_dewasa'] + (int)$r['sesi_private'],
        'rincian' => $rin, 'gaji_sesi' => (int)$r['gaji_sesi'], 'bonus' => (int)$r['bonus'], 'potongan' => (int)$r['potongan'],
        'catatan' => (string)($r['catatan'] ?? ''), 'total' => (int)$r['total'], 'status' => $r['status'],
        'metode' => (string)($r['metode'] ?? ''), 'tanggal_bayar' => $r['tanggal_bayar'] ?? null,
        'wa_status' => $r['wa_status'], 'wa_waktu' => $r['wa_waktu'] ?? null, 'wa_error' => (string)($r['wa_error'] ?? ''),
    ];
}

function gjDaftar(PDO $pdo, int $tahun, int $bulan): array
{
    return array_map('gjBaris', gqDaftarGaji($pdo, $tahun, $bulan));
}

function gjAmbil(PDO $pdo, int $id): ?array
{
    $r = gqAmbilGaji($pdo, $id);
    return $r ? gjBaris($r) : null;
}

// Ringkasan angka untuk kartu di atas halaman
function gjRingkas(array $rows): array
{
    $r = ['total' => 0, 'dibayar' => 0, 'belum' => 0, 'sesi' => 0, 'coach' => count($rows), 'draft' => 0, 'disetujui' => 0, 'lunas' => 0];
    foreach ($rows as $x) {
        $r['total'] += $x['total']; $r['sesi'] += $x['jml_sesi'];
        if ($x['status'] === 'dibayar') { $r['dibayar'] += $x['total']; $r['lunas']++; }
        else { $r['belum'] += $x['total']; $r[$x['status']]++; }
    }
    return $r;
}

// ─── LAPORAN KEUANGAN: pengeluaran gaji (dihitung ulang dari data gaji = aman dipanggil berulang) ───
function gjSinkronLaporan(PDO $pdo, int $tahun, int $bulan): void
{
    gqSetPengeluaranLaporan($pdo, $tahun, $bulan, gqTotalDibayar($pdo, $tahun, $bulan));
}

// ─── ALUR STATUS ───
// draft -> disetujui -> dibayar  (dan mundur satu langkah bila salah)
function gjUbahStatus(PDO $pdo, int $id, string $aksi, array $opsi = []): string
{
    $g = gjAmbil($pdo, $id);
    if (!$g) throw new RuntimeException('Data gaji tidak ditemukan.');
    $now = date('Y-m-d H:i:s');
    switch ($aksi) {
        case 'setujui':
            if ($g['status'] !== 'draft') throw new RuntimeException('Hanya gaji berstatus Draft yang bisa disetujui.');
            if ($g['total'] <= 0) throw new RuntimeException('Total gaji masih Rp 0 — belum ada yang perlu dibayar.');
            gqSetDisetujui($pdo, $id, $now);
            return 'Gaji ' . $g['nama'] . ' disetujui.';
        case 'batal_setujui':
            if ($g['status'] !== 'disetujui') throw new RuntimeException('Status gaji bukan Disetujui.');
            gqSetDraft($pdo, $id, $now);
            return 'Gaji dikembalikan ke Draft.';
        case 'bayar':
            if ($g['status'] !== 'disetujui') throw new RuntimeException('Gaji harus disetujui dulu sebelum dibayar.');
            $metode = (string)($opsi['metode'] ?? 'transfer');
            if (!isset(GJ_METODE[$metode])) throw new RuntimeException('Metode pembayaran tidak valid.');
            $tgl = (string)($opsi['tanggal'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) || !strtotime($tgl)) throw new RuntimeException('Tanggal bayar tidak valid.');
            $pdo->beginTransaction();
            try {
                gqSetDibayar($pdo, $id, $metode, $tgl, $now);
                gjSinkronLaporan($pdo, $g['tahun'], $g['bulan']);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            return 'Gaji ' . $g['nama'] . ' ditandai dibayar & dicatat sebagai pengeluaran.';
        case 'batal_bayar':
            if ($g['status'] !== 'dibayar') throw new RuntimeException('Status gaji bukan Dibayar.');
            $pdo->beginTransaction();
            try {
                gqBatalDibayar($pdo, $id, $now);
                gjSinkronLaporan($pdo, $g['tahun'], $g['bulan']);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            return 'Pembayaran dibatalkan, pengeluaran dikoreksi.';
    }
    throw new RuntimeException('Aksi tidak dikenal.');
}

function gjSimpanPenyesuaian(PDO $pdo, int $id, int $bonus, int $potongan, string $catatan): void
{
    $g = gjAmbil($pdo, $id);
    if (!$g) throw new RuntimeException('Data gaji tidak ditemukan.');
    if ($g['status'] !== 'draft') throw new RuntimeException('Bonus / potongan hanya bisa diubah saat status Draft.');
    if ($bonus < 0 || $potongan < 0 || $bonus > 1000000000 || $potongan > 1000000000) throw new RuntimeException('Bonus dan potongan harus berupa angka positif.');
    gqSetPenyesuaian($pdo, $id, $bonus, $potongan, mb_substr(trim($catatan), 0, 250) ?: null, gjTotal($g['gaji_sesi'], $bonus, $potongan), date('Y-m-d H:i:s'));
}

// ─── SLIP ───
function gjSlipTeks(array $g, bool $estimasi = false): string
{
    $o = "*SLIP GAJI COACH — NATIX SWIM*\n";
    $o .= 'Periode: ' . GJ_BULAN[$g['bulan']] . ' ' . $g['tahun'] . "\n";
    $o .= 'Nama: ' . $g['nama'] . "\n\n*Rincian Mengajar*\n";
    $ada = false;
    foreach ($g['rincian'] as $r) {
        if ((int)$r['sesi'] === 0) continue;
        $ada = true;
        $o .= '• ' . $r['label'] . ': ' . $r['sesi'] . ' sesi × ' . gjRp($r['tarif']) . ' = ' . gjRp($r['subtotal']) . "\n";
    }
    if (!$ada) $o .= "• Belum ada sesi mengajar\n";
    $o .= "\nGaji sesi: " . gjRp($g['gaji_sesi']) . "\n";
    if ($g['bonus'] > 0)    $o .= 'Bonus: + ' . gjRp($g['bonus']) . "\n";
    if ($g['potongan'] > 0) $o .= 'Potongan: − ' . gjRp($g['potongan']) . "\n";
    if ($g['catatan'] !== '') $o .= 'Catatan: ' . $g['catatan'] . "\n";
    $o .= "*Total diterima: " . gjRp($g['total']) . "*\n\n";
    if ($estimasi || $g['status'] !== 'dibayar') {
        $o .= $g['status'] === 'disetujui' ? "Status: Disetujui, menunggu pembayaran.\n" : "Status: ESTIMASI SEMENTARA — belum disetujui admin, angka bisa berubah.\n";
    } else {
        $o .= 'Status: Dibayar ' . gjTglIndo($g['tanggal_bayar']) . ' (' . (GJ_METODE[$g['metode']] ?? $g['metode']) . ")\n";
    }
    return $o . "\nTerima kasih atas dedikasinya 🙏";
}

// ─── WHATSAPP (gateway Baileys: folder wa-gateway, sama dengan yang dipakai Chat Log) ───
// Panggil API gateway. GW_URL & GW_TOKEN sama dengan manajemen-ai-chat.php (env NATIX_GW_URL / NATIX_GW_TOKEN).
function gjGw(string $metode, string $path, ?array $json = null): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('Ekstensi PHP cURL belum aktif di server.');
    $ch = curl_init(rtrim(GW_URL, '/') . $path);
    $opt = [CURLOPT_CUSTOMREQUEST => $metode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['x-gateway-token: ' . GW_TOKEN, 'Content-Type: application/json']];
    if ($json !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Gateway WhatsApp tidak aktif (' . $err . '). Jalankan "node wa-gateway.mjs" di folder wa-gateway.');
    $j = json_decode((string)$raw, true);
    if ($http === 401) throw new RuntimeException('Token gateway tidak cocok. Samakan NATIX_GW_TOKEN di PHP dengan GATEWAY_TOKEN di gateway.');
    if ($http >= 400 || !is_array($j) || empty($j['ok'])) throw new RuntimeException(is_array($j) && !empty($j['pesan']) ? (string)$j['pesan'] : "Gateway menolak permintaan (HTTP $http).");
    return $j;
}

// ── Cloud API resmi Meta ──
function gjCloudSiap(): bool { return strpos(WA_TOKEN, 'ISI_') !== 0 && WA_TOKEN !== '' && strpos(WA_PHONE_ID, 'ISI_') !== 0 && WA_PHONE_ID !== ''; }

function gjCloudPesanError(array $j, int $http): string
{
    $kode = (int)($j['error']['code'] ?? 0); $msg = (string)($j['error']['message'] ?? '');
    $peta = [
        190    => 'Access token WhatsApp tidak valid / kedaluwarsa. Buat token baru di Meta lalu isi NATIX_WA_TOKEN.',
        131047 => 'Jendela 24 jam sudah berakhir: penerima harus mengirim pesan ke nomor bisnis dulu (mis. ketik "halo"), atau pakai template pesan.',
        131030 => 'Nomor penerima belum ditambahkan sebagai penerima uji di Meta (WhatsApp → API Setup → To). Tambahkan dan verifikasi nomornya.',
        131026 => 'Pesan tidak bisa dikirim: nomor penerima tidak terdaftar di WhatsApp.',
        131031 => 'Akun WhatsApp Business dibatasi / dinonaktifkan oleh Meta.',
        100    => 'Permintaan ke Meta ditolak (periksa NATIX_WA_PHONE_ID dan izin token): ' . $msg,
    ];
    return $peta[$kode] ?? ('WhatsApp Cloud API menolak (HTTP ' . $http . ($kode ? ", kode $kode" : '') . '): ' . $msg);
}

function gjCloud(string $metode, string $path, ?array $json = null): array
{
    if (!gjCloudSiap()) throw new RuntimeException('WhatsApp Cloud API belum dikonfigurasi. Isi NATIX_WA_TOKEN dan NATIX_WA_PHONE_ID (lihat config/wa-config.php).');
    if (!function_exists('curl_init')) throw new RuntimeException('Ekstensi PHP cURL belum aktif di server.');
    $ch = curl_init(rtrim(WA_BASE, '/') . '/v21.0/' . ltrim($path, '/'));
    $opt = [CURLOPT_CUSTOMREQUEST => $metode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WA_TOKEN, 'Content-Type: application/json']];
    if ($json !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Tidak bisa menghubungi server Meta (' . $err . ').');
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) $j = [];
    if ($http >= 400 || !empty($j['error'])) throw new RuntimeException(gjCloudPesanError($j, $http));
    return $j;
}

// Kirim teks ke satu nomor lewat provider yang aktif (cloud = Meta resmi, baileys = gateway). Melempar RuntimeException bila gagal.
function gjKirimPesan(string $to, string $teks): void
{
    if (WA_PROVIDER === 'cloud') {
        gjCloud('POST', WA_PHONE_ID . '/messages', ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $teks]]);
        return;
    }
    gjGw('POST', '/kirim', ['to' => $to, 'text' => $teks, 'cek' => true]);   // cek = pastikan nomor terdaftar di WhatsApp
}

// Status koneksi: state = open (siap kirim) | qr (menunggu scan, Baileys) | connecting | closed | mati (belum siap / tidak berjalan)
function gjStatusGateway(): array
{
    if (WA_PROVIDER === 'cloud') {
        try {
            $j = gjCloud('GET', WA_PHONE_ID . '?fields=display_phone_number,verified_name');
            return ['state' => 'open', 'nomor' => preg_replace('/\D+/', '', (string)($j['display_phone_number'] ?? '')), 'nama' => (string)($j['verified_name'] ?? ''), 'pesan' => '', 'provider' => 'cloud'];
        } catch (RuntimeException $e) {
            return ['state' => 'mati', 'nomor' => '', 'nama' => '', 'pesan' => $e->getMessage(), 'provider' => 'cloud'];
        }
    }
    try {
        $j = gjGw('GET', '/status');
        return ['state' => (string)($j['state'] ?? 'closed'), 'nomor' => (string)($j['nomor'] ?? ''), 'nama' => (string)($j['nama'] ?? ''), 'pesan' => '', 'provider' => 'baileys'];
    } catch (RuntimeException $e) {
        return ['state' => 'mati', 'nomor' => '', 'nama' => '', 'pesan' => $e->getMessage(), 'provider' => 'baileys'];
    }
}

function gjTesWa(string $hp): array   // -> [ok, pesan]  pesan percobaan untuk memastikan koneksi WhatsApp
{
    $no = gjNomor($hp);
    if ($no === '') return [false, 'Nomor tujuan tidak valid. Contoh: 081234567890.'];
    try {
        gjKirimPesan($no, "Tes koneksi Natix Swim ✅\nJika pesan ini sampai, slip gaji dan laporan progres bisa dikirim otomatis ke WhatsApp.");
    } catch (RuntimeException $e) { return [false, $e->getMessage()]; }
    return [true, 'Pesan tes terkirim ke ' . $no . '.'];
}

function gjKirimWa(PDO $pdo, int $id, bool $semuaStatus = false): array   // -> [ok, pesan]; $semuaStatus = kiriman bulanan otomatis (slip dikirim apa pun statusnya, teks slip memuat statusnya)
{
    $g = gjAmbil($pdo, $id);
    if (!$g) return [false, 'Data gaji tidak ditemukan.'];
    if (!$semuaStatus && $g['status'] !== 'dibayar') return [false, 'Slip resmi hanya dikirim setelah gaji berstatus Dibayar.'];
    $no = gjNomor($g['hp']);
    $gagal = function (string $pesan) use ($pdo, $id) {
        gqSetWa($pdo, $id, 'gagal', mb_substr($pesan, 0, 250), date('Y-m-d H:i:s'));
        return [false, $pesan];
    };
    if ($no === '') return $gagal('Nomor WhatsApp coach belum diisi / tidak valid (isi di Data Pelatih).');
    try {
        gjKirimPesan($no, gjSlipTeks($g));
    } catch (RuntimeException $e) { return $gagal($e->getMessage()); }
    gqSetWa($pdo, $id, 'terkirim', null, date('Y-m-d H:i:s'));
    return [true, 'Slip gaji terkirim ke WhatsApp ' . $g['nama'] . '.'];
}

// Dipanggil harian oleh penjadwal:
//  - tanggal 25 s.d. akhir bulan : hitung gaji bulan berjalan (draft) agar bisa ditinjau & disetujui admin.
//  - tanggal 1                    : hitung ulang gaji bulan lalu (final), lalu kirim slip ke WhatsApp semua coach.
function gjCron(PDO $pdo, ?DateTime $sekarang = null): array
{
    gjMigrasi($pdo);
    $sekarang = $sekarang ?: new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $lap = ['draft_dibuat' => 0, 'terkirim' => 0, 'gagal' => 0, 'dilewati' => ''];
    $tgl = (int)$sekarang->format('j');
    if ($tgl >= 25) {
        $lap['draft_dibuat'] = gjHitung($pdo, (int)$sekarang->format('Y'), (int)$sekarang->format('n'), $sekarang);
        return $lap;
    }
    if ($tgl !== 1) { $lap['dilewati'] = 'Tidak ada tugas hari ini.'; return $lap; }
    $lalu = (clone $sekarang)->modify('first day of last month');
    $th = (int)$lalu->format('Y'); $bl = (int)$lalu->format('n');
    $lap['draft_dibuat'] = gjHitung($pdo, $th, $bl, $sekarang);
    if (gjPengaturan($pdo, 'auto_wa', '1') !== '1') { $lap['dilewati'] = 'Pengiriman otomatis dimatikan di pengaturan.'; return $lap; }
    foreach (gqDaftarGaji($pdo, $th, $bl) as $r) {
        if (($r['wa_status'] ?? '') === 'terkirim') continue;
        [$ok] = gjKirimWa($pdo, (int)$r['id'], true);
        $ok ? $lap['terkirim']++ : $lap['gagal']++;
    }
    return $lap;
}

// Balasan otomatis chat WhatsApp: "Check Gaji Bulan Ini" / "cek gaji". null = bukan permintaan gaji / bukan coach.
function gjBalasWa(PDO $pdo, string $no, string $teks): ?string
{
    if (!preg_match('/\b(cek|check|lihat)\s+(slip\s+)?gaji\b/i', $teks)) return null;
    gjMigrasi($pdo);
    $akhir = substr(preg_replace('/\D+/', '', gjNomor($no)), -9);
    if (strlen($akhir) < 9) return null;
    $coach = null;
    foreach (gqDaftarPelatih($pdo) as $c) {
        if (substr(gjNomor($c['hp']), -9) === $akhir) { $coach = (int)$c['id']; break; }
    }
    if (!$coach) return null;   // bukan nomor coach: serahkan ke alur chat biasa
    $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $th = (int)$now->format('Y'); $bl = (int)$now->format('n');
    gjHitung($pdo, $th, $bl, $now);   // segarkan draft supaya estimasi terbaru
    $id = gqIdGaji($pdo, $th, $bl, $coach);
    if (!$id) return 'Halo Coach 👋 Belum ada sesi mengajar yang tercatat untuk bulan ' . GJ_BULAN[$bl] . ' ' . $th . ' ya.';
    return gjSlipTeks(gjAmbil($pdo, $id));
}

PHPEND
,
'gaji-query.php' => <<<'PHPEND'
<?php
// ============================================================
// gaji-query.php — SEMUA query SQL fitur Gaji Coach (lapisan data)
// Tidak ada logika bisnis di sini: hanya baca / tulis tabel dan mengembalikan array mentah.
// Logika (hitung, alur status, slip, WhatsApp) ada di gaji-fungsi.php.
// Tabel (dibuat otomatis): gaji_tarif, gaji, gaji_pengaturan
// Kolom tambahan (otomatis): laporan_keuangan.total_pengeluaran
// ============================================================

// ─── SKEMA ───
function gqAdaKolom(PDO $pdo, string $tabel, string $kolom): bool
{
    try { $pdo->query("SELECT $kolom FROM $tabel LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

function gqDdl(PDO $pdo, string $sql): string   // DDL ditulis untuk MySQL; driver lain (uji coba) disesuaikan
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') return $sql;
    $sql = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/UNIQUE KEY \w+ \(/', 'UNIQUE (', $sql);
    return preg_replace('/\) ENGINE=.*$/', ')', $sql);
}

function gqMigrasi(PDO $pdo, array $tarifAwal): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $my = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    foreach ([
        "CREATE TABLE IF NOT EXISTS gaji_tarif (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pelatih_id INT NOT NULL DEFAULT 0,
            jenis VARCHAR(10) NOT NULL,
            tarif INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_gaji_tarif (pelatih_id, jenis)
        )$my",
        "CREATE TABLE IF NOT EXISTS gaji (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tahun SMALLINT NOT NULL,
            bulan TINYINT NOT NULL,
            pelatih_id INT NOT NULL,
            sesi_kids INT NOT NULL DEFAULT 0,
            sesi_dewasa INT NOT NULL DEFAULT 0,
            sesi_private INT NOT NULL DEFAULT 0,
            gaji_sesi BIGINT NOT NULL DEFAULT 0,
            bonus BIGINT NOT NULL DEFAULT 0,
            potongan BIGINT NOT NULL DEFAULT 0,
            catatan VARCHAR(255) NULL,
            total BIGINT NOT NULL DEFAULT 0,
            rincian TEXT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'draft',
            metode VARCHAR(20) NULL,
            tanggal_bayar DATE NULL,
            wa_status VARCHAR(10) NOT NULL DEFAULT 'belum',
            wa_waktu DATETIME NULL,
            wa_error VARCHAR(255) NULL,
            dibuat_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_gaji_periode (tahun, bulan, pelatih_id)
        )$my",
        "CREATE TABLE IF NOT EXISTS gaji_pengaturan (
            kunci VARCHAR(40) NOT NULL PRIMARY KEY,
            nilai VARCHAR(255) NOT NULL
        )$my",
    ] as $ddl) $pdo->exec(gqDdl($pdo, $ddl));

    if (!(int)$pdo->query('SELECT COUNT(*) FROM gaji_tarif WHERE pelatih_id = 0')->fetchColumn()) {
        $ins = $pdo->prepare('INSERT INTO gaji_tarif (pelatih_id, jenis, tarif) VALUES (0, :j, :t)');
        foreach ($tarifAwal as $j => $t) $ins->execute([':j' => $j, ':t' => $t]);
    }
    if (gqAdaKolom($pdo, 'laporan_keuangan', 'id') && !gqAdaKolom($pdo, 'laporan_keuangan', 'total_pengeluaran')) {
        try { $pdo->exec('ALTER TABLE laporan_keuangan ADD COLUMN total_pengeluaran BIGINT NOT NULL DEFAULT 0'); }
        catch (Throwable $e) { error_log('[gaji] gagal menambah total_pengeluaran: ' . $e->getMessage()); }
    }
}

// ─── PENGATURAN ───
function gqPengaturan(PDO $pdo, string $kunci): ?string
{
    $st = $pdo->prepare('SELECT nilai FROM gaji_pengaturan WHERE kunci = :k');
    $st->execute([':k' => $kunci]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function gqSimpanPengaturan(PDO $pdo, string $kunci, string $nilai): void
{
    $st = $pdo->prepare('UPDATE gaji_pengaturan SET nilai = :v WHERE kunci = :k');
    $st->execute([':v' => $nilai, ':k' => $kunci]);
    if ($st->rowCount()) return;
    $cek = $pdo->prepare('SELECT COUNT(*) FROM gaji_pengaturan WHERE kunci = :k');
    $cek->execute([':k' => $kunci]);
    if (!(int)$cek->fetchColumn()) $pdo->prepare('INSERT INTO gaji_pengaturan (kunci, nilai) VALUES (:k, :v)')->execute([':k' => $kunci, ':v' => $nilai]);
}

// ─── TARIF ───
function gqTarifSemua(PDO $pdo): array   // -> baris [pelatih_id, jenis, tarif]
{
    return $pdo->query('SELECT pelatih_id, jenis, tarif FROM gaji_tarif')->fetchAll(PDO::FETCH_ASSOC);
}

function gqHapusTarif(PDO $pdo, int $pelatihId, string $jenis): void
{
    $pdo->prepare('DELETE FROM gaji_tarif WHERE pelatih_id = :p AND jenis = :j')->execute([':p' => $pelatihId, ':j' => $jenis]);
}

function gqSetTarif(PDO $pdo, int $pelatihId, string $jenis, int $tarif): void
{
    $st = $pdo->prepare('UPDATE gaji_tarif SET tarif = :t WHERE pelatih_id = :p AND jenis = :j');
    $st->execute([':t' => $tarif, ':p' => $pelatihId, ':j' => $jenis]);
    $cek = $pdo->prepare('SELECT COUNT(*) FROM gaji_tarif WHERE pelatih_id = :p AND jenis = :j');
    $cek->execute([':p' => $pelatihId, ':j' => $jenis]);
    if (!(int)$cek->fetchColumn()) $pdo->prepare('INSERT INTO gaji_tarif (pelatih_id, jenis, tarif) VALUES (:p, :j, :t)')->execute([':p' => $pelatihId, ':j' => $jenis, ':t' => $tarif]);
}

// ─── PELATIH & JADWAL (tabel milik fitur lain, hanya dibaca) ───
// No. HP bisa ada di pelatih.no_hp dan/atau users.no_hp
function gqExprHp(PDO $pdo): string
{
    $dp = gqAdaKolom($pdo, 'pelatih', 'no_hp');
    $du = gqAdaKolom($pdo, 'users', 'no_hp');
    if ($dp && $du) return "COALESCE(NULLIF(pl.no_hp, ''), u.no_hp)";
    if ($dp) return 'pl.no_hp';
    if ($du) return 'u.no_hp';
    return 'NULL';
}

function gqDaftarPelatih(PDO $pdo): array   // -> [id, nama, hp]
{
    $hp = gqExprHp($pdo);
    return $pdo->query("SELECT pl.id, u.nama, $hp AS hp FROM pelatih pl JOIN users u ON u.id = pl.user_id ORDER BY u.nama")->fetchAll(PDO::FETCH_ASSOC);
}

function gqJadwalBulan(PDO $pdo, string $awal, string $akhir): array   // jadwal tidak dibatalkan pada rentang tanggal
{
    $hp = gqExprHp($pdo);
    $st = $pdo->prepare("
        SELECT j.pelatih_id, j.tanggal, j.jam_selesai, j.status, k.nama_program, k.tipe, u.nama AS pelatih, $hp AS hp
          FROM jadwal j
          JOIN kelas_program k ON k.id = j.program_id
          JOIN pelatih pl      ON pl.id = j.pelatih_id
          JOIN users u         ON u.id = pl.user_id
         WHERE j.tanggal BETWEEN :s AND :e AND j.status <> 'dibatalkan'");
    $st->execute([':s' => $awal, ':e' => $akhir]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ─── GAJI ───
function gqGajiPeriodePelatih(PDO $pdo, int $tahun, int $bulan, int $pelatihId): ?array
{
    $st = $pdo->prepare('SELECT id, status, bonus, potongan FROM gaji WHERE tahun = :t AND bulan = :b AND pelatih_id = :p');
    $st->execute([':t' => $tahun, ':b' => $bulan, ':p' => $pelatihId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function gqTambahGaji(PDO $pdo, int $tahun, int $bulan, int $pelatihId, array $sesi, int $gajiSesi, int $total, string $rincian, string $now): void
{
    $pdo->prepare('INSERT INTO gaji (tahun, bulan, pelatih_id, sesi_kids, sesi_dewasa, sesi_private, gaji_sesi, total, rincian, dibuat_at, updated_at)
                   VALUES (:t,:b,:p,:k,:d,:v,:g,:tot,:r,:n,:n2)')
        ->execute([':t' => $tahun, ':b' => $bulan, ':p' => $pelatihId, ':k' => $sesi['kids'], ':d' => $sesi['dewasa'], ':v' => $sesi['private'],
                   ':g' => $gajiSesi, ':tot' => $total, ':r' => $rincian, ':n' => $now, ':n2' => $now]);
}

function gqUpdateDraft(PDO $pdo, int $id, array $sesi, int $gajiSesi, int $total, ?string $rincian, string $now): void
{
    $pdo->prepare('UPDATE gaji SET sesi_kids=:k, sesi_dewasa=:d, sesi_private=:v, gaji_sesi=:g, total=:tot, rincian=:r, updated_at=:n WHERE id=:id')
        ->execute([':k' => $sesi['kids'], ':d' => $sesi['dewasa'], ':v' => $sesi['private'], ':g' => $gajiSesi, ':tot' => $total, ':r' => $rincian, ':n' => $now, ':id' => $id]);
}

function gqDraftPeriode(PDO $pdo, int $tahun, int $bulan): array
{
    $st = $pdo->prepare("SELECT id, pelatih_id, bonus, potongan FROM gaji WHERE tahun = :t AND bulan = :b AND status = 'draft'");
    $st->execute([':t' => $tahun, ':b' => $bulan]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function gqDaftarGaji(PDO $pdo, int $tahun, int $bulan): array
{
    $hp = gqExprHp($pdo);
    $st = $pdo->prepare("SELECT g.*, u.nama AS nama, $hp AS hp FROM gaji g JOIN pelatih pl ON pl.id = g.pelatih_id JOIN users u ON u.id = pl.user_id
                          WHERE g.tahun = :t AND g.bulan = :b ORDER BY u.nama");
    $st->execute([':t' => $tahun, ':b' => $bulan]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function gqAmbilGaji(PDO $pdo, int $id): ?array
{
    $hp = gqExprHp($pdo);
    $st = $pdo->prepare("SELECT g.*, u.nama AS nama, $hp AS hp FROM gaji g JOIN pelatih pl ON pl.id = g.pelatih_id JOIN users u ON u.id = pl.user_id WHERE g.id = :id");
    $st->execute([':id' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function gqIdGaji(PDO $pdo, int $tahun, int $bulan, int $pelatihId): ?int
{
    $st = $pdo->prepare('SELECT id FROM gaji WHERE tahun = :t AND bulan = :b AND pelatih_id = :p');
    $st->execute([':t' => $tahun, ':b' => $bulan, ':p' => $pelatihId]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

function gqSetPenyesuaian(PDO $pdo, int $id, int $bonus, int $potongan, ?string $catatan, int $total, string $now): void
{
    $pdo->prepare('UPDATE gaji SET bonus=:b, potongan=:p, catatan=:c, total=:t, updated_at=:n WHERE id=:id')
        ->execute([':b' => $bonus, ':p' => $potongan, ':c' => $catatan, ':t' => $total, ':n' => $now, ':id' => $id]);
}

function gqSetDisetujui(PDO $pdo, int $id, string $now): void
{
    $pdo->prepare("UPDATE gaji SET status='disetujui', updated_at=:n WHERE id=:id")->execute([':n' => $now, ':id' => $id]);
}

function gqSetDraft(PDO $pdo, int $id, string $now): void
{
    $pdo->prepare("UPDATE gaji SET status='draft', updated_at=:n WHERE id=:id")->execute([':n' => $now, ':id' => $id]);
}

function gqSetDibayar(PDO $pdo, int $id, string $metode, string $tanggal, string $now): void
{
    $pdo->prepare("UPDATE gaji SET status='dibayar', metode=:m, tanggal_bayar=:t, wa_status='belum', wa_error=NULL, updated_at=:n WHERE id=:id")
        ->execute([':m' => $metode, ':t' => $tanggal, ':n' => $now, ':id' => $id]);
}

function gqBatalDibayar(PDO $pdo, int $id, string $now): void
{
    $pdo->prepare("UPDATE gaji SET status='disetujui', metode=NULL, tanggal_bayar=NULL, updated_at=:n WHERE id=:id")->execute([':n' => $now, ':id' => $id]);
}

function gqSetWa(PDO $pdo, int $id, string $status, ?string $error, string $waktu): void
{
    $pdo->prepare('UPDATE gaji SET wa_status=:s, wa_error=:e, wa_waktu=:w WHERE id=:id')->execute([':s' => $status, ':e' => $error, ':w' => $waktu, ':id' => $id]);
}

function gqBelumTerkirim(PDO $pdo): array   // gaji dibayar yang slipnya belum terkirim
{
    return $pdo->query("SELECT id, tahun, bulan FROM gaji WHERE status = 'dibayar' AND wa_status <> 'terkirim' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

// ─── LAPORAN KEUANGAN ───
function gqTotalDibayar(PDO $pdo, int $tahun, int $bulan): int
{
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM gaji WHERE tahun = :t AND bulan = :b AND status = 'dibayar'");
        $st->execute([':t' => $tahun, ':b' => $bulan]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

// Isi laporan_keuangan.total_pengeluaran. Bulan yang belum punya catatan dibuat dulu (pendapatan diambil dari pembayaran
// supaya angka dashboard tidak jadi 0).
function gqSetPengeluaranLaporan(PDO $pdo, int $tahun, int $bulan, int $total): void
{
    if (!gqAdaKolom($pdo, 'laporan_keuangan', 'total_pengeluaran')) return;
    $ada = $pdo->prepare('SELECT id FROM laporan_keuangan WHERE tahun = :t AND bulan = :b');
    $ada->execute([':t' => $tahun, ':b' => $bulan]);
    $id = $ada->fetchColumn();
    if ($id) {
        $pdo->prepare('UPDATE laporan_keuangan SET total_pengeluaran = :x WHERE id = :id')->execute([':x' => $total, ':id' => $id]);
    } elseif ($total > 0) {
        $awal = sprintf('%04d-%02d-01', $tahun, $bulan);
        $p = $pdo->prepare('SELECT COALESCE(SUM(jumlah), 0) FROM pembayaran WHERE tanggal_bayar BETWEEN :s AND :e');
        $p->execute([':s' => $awal . ' 00:00:00', ':e' => date('Y-m-t', strtotime($awal)) . ' 23:59:59']);
        $pdo->prepare('INSERT INTO laporan_keuangan (bulan, tahun, total_pendapatan, total_pengeluaran, dibuat_oleh) VALUES (:b, :t, :p, :x, NULL)')
            ->execute([':b' => $bulan, ':t' => $tahun, ':p' => (int)$p->fetchColumn(), ':x' => $total]);
    }
}

PHPEND
];
foreach ($berkas as $nama => $isi) {
    if (@file_put_contents("$dir/$nama", $isi) === false) $gagal[] = "$nama: gagal ditulis (cek izin folder)";
    else $ok[] = "$nama ditulis";
}
// config/wa-config.php: hanya dibuat kalau belum ada (supaya token yang sudah Anda isi tidak hilang)
@mkdir("$dir/config", 0755, true);
if (is_file("$dir/config/wa-config.php")) $lewat[] = 'config/wa-config.php sudah ada (tidak ditimpa)';
elseif (@file_put_contents("$dir/config/wa-config.php", <<<'PHPEND'
<?php
// ============================================================
// config/wa-config.php — pengaturan WhatsApp bersama (Chat Log, Gaji, Progres Murid)
// Pilih provider lewat environment variable (Railway: Variables) atau isi nilai bawaan di bawah ini.
//
//   NATIX_WA_PROVIDER   cloud  = WhatsApp Cloud API resmi Meta (disarankan: tanpa QR, tanpa risiko blokir)
//                       baileys = gateway tidak resmi (butuh scan QR)
//   NATIX_WA_TOKEN      Access token dari Meta (WhatsApp → API Setup)
//   NATIX_WA_PHONE_ID   Phone number ID dari Meta
//   NATIX_WA_VERIFY     Verify token webhook (bebas, samakan dengan yang diisi di Meta)
//   NATIX_WA_SECRET     App Secret (Settings → Basic) agar webhook diverifikasi
// ============================================================
foreach ([
    'WA_PROVIDER'     => [getenv('NATIX_WA_PROVIDER'), 'cloud'],
    'WA_TOKEN'        => [getenv('NATIX_WA_TOKEN'),    'ISI_ACCESS_TOKEN'],
    'WA_PHONE_ID'     => [getenv('NATIX_WA_PHONE_ID'), 'ISI_PHONE_NUMBER_ID'],
    'WA_VERIFY_TOKEN' => [getenv('NATIX_WA_VERIFY'),   'natix-swim-verify'],
    'WA_APP_SECRET'   => [getenv('NATIX_WA_SECRET'),   ''],
] as $k => [$env, $def]) if (!defined($k)) define($k, $env ?: $def);

PHPEND) === false) $gagal[] = 'config/wa-config.php: gagal ditulis';
else $ok[] = 'config/wa-config.php dibuat';

// ── Simulator Chat dihapus (tidak dipakai) ──
if (is_file("$dir/simulator-wa.php")) { @unlink("$dir/simulator-wa.php"); $ok[] = 'simulator-wa.php dihapus'; }

// ── 2. menu di semua halaman ──
$ikon = "            'award'    => '<circle cx=\"12\" cy=\"9\" r=\"6\"/><path d=\"M8.5 14.2L7 22l5-3 5 3-1.5-7.8\"/>',\n";
$tambah = [   // [penanda, baris acuan (regex), baris baru]
    ['progres.php',     "/(\n[ ]*\['Kehadiran', 'kehadiran\.php', 'check', \[\], false\],)/",            "\n                ['Progres Murid', 'progres.php', 'award', [], false],"],
];
$menu = 0;
foreach (glob("$dir/*.php") as $f) {
    $s = file_get_contents($f);
    if ($f === __FILE__ || strpos($s, 'function nvMenu') === false) continue;
    $ubah = false;
    // Simulator Chat tidak dipakai lagi: buang dari menu
    $c3 = preg_replace("/\n[ ]*\['Simulator Chat', 'simulator-wa\.php', 'chat', \[\], (?:true|false)\],/", '', $s);
    if ($c3 !== null && $c3 !== $s) { $s = $c3; $ubah = true; }
    // Chat Log ditutup di menu semua halaman (kolom ke-5 = true; ganti ke false untuk membukanya) + teks pemberitahuan
    $c2 = str_replace("['Chat Log', 'manajemen-ai-chat.php', 'chat', [], false],", "['Chat Log', 'manajemen-ai-chat.php', 'chat', [], true],", $s);
    $c2 = str_replace("' belum tersedia.'", "' tidak tersedia.'", $c2);
    if ($c2 !== $s) { $s = $c2; $ubah = true; }
    if (strpos($s, "['Gaji', 'gaji.php'") !== false) { $s = str_replace("['Gaji', 'gaji.php'", "['Payroll', 'gaji.php'", $s); $ubah = true; }
    foreach ($tambah as [$tanda, $acuan, $baris]) {
        if (strpos($s, "'$tanda'") !== false) continue;
        $n = 0;
        $s2 = preg_replace($acuan, '$1' . str_replace('\\', '\\\\', $baris), $s, 1, $n);
        if ($n) { $s = $s2; $ubah = true; } else $lewat[] = basename($f) . ": baris acuan menu untuk $tanda tidak ditemukan";
    }
    if (strpos($s, "'award'    =>") === false && strpos($s, "'award'") !== false) {
        $s2 = preg_replace("/(\n[ ]*'card'\s*=>\s*'[^\n]*',)/", "$1\n" . str_replace('\\', '\\\\', rtrim($ikon, "\n")), $s, 1, $n2);
        if ($n2) { $s = $s2; $ubah = true; }
    }
    if ($ubah) { file_put_contents($f, $s); $menu++; }
}
$ok[] = "menu Payroll & Progres Murid diperbarui di $menu halaman";

// ── 3. Chat WhatsApp: "daftar" & "progres" ──
$f = "$dir/manajemen-ai-chat.php";
if (!is_file($f)) $lewat[] = 'manajemen-ai-chat.php tidak ditemukan (alur chat tidak dipasang)';
else {
    $s = file_get_contents($f);
    if (strpos($s, 'wa-config.php') === false) {
        $titik = "foreach ([\n    'WA_TOKEN'        => [getenv('NATIX_WA_TOKEN')";
        if (strpos($s, $titik) === false) $gagal[] = 'manajemen-ai-chat.php: titik sisip wa-config tidak ditemukan';
        else { $s = str_replace($titik, "if (is_file(__DIR__ . '/config/wa-config.php')) require_once __DIR__ . '/config/wa-config.php';   // pengaturan WhatsApp bersama\n" . $titik, $s); file_put_contents($f, $s); $ok[] = 'manajemen-ai-chat.php: memakai config/wa-config.php'; }
    }
    if (strpos($s, 'alurProses') !== false) $lewat[] = 'manajemen-ai-chat.php: alur chat sudah dipasang';
    else {
        $jangkar = "    if (\$c['status'] !== 'bot') return;   // sedang ditangani admin / menunggu admin: AI diam\n";
        if (strpos($s, $jangkar) === false) $gagal[] = 'manajemen-ai-chat.php: titik sisip tidak ditemukan (alur chat tidak dipasang)';
        else {
            $blok = <<<'BLK'
    // [alur] Alur chatbot: "daftar" (calon murid / orang tua) dan "progres" (coach). Percakapan yang sedang di tengah isian tetap dilanjutkan.
    if ($tipe === 'text' && is_file(__DIR__ . '/alur-fungsi.php')) {
        $alBalas = null;
        try { require_once __DIR__ . '/alur-fungsi.php'; $alBalas = alurProses($pdo, $no, $isi); }
        catch (Throwable $e) { error_log('[alur] chat: ' . $e->getMessage()); }
        if ($alBalas !== null) { waBotKirim($pdo, $cid, $no, $alBalas); return; }
    }

BLK;
            file_put_contents($f, str_replace($jangkar, $blok . $jangkar, $s));
            $ok[] = 'manajemen-ai-chat.php: alur "daftar" & "progres" dipasang';
        }
    }
}

foreach ($ok as $x) echo "OK     $x\n";
foreach ($lewat as $x) echo "LEWAT  $x\n";
foreach ($gagal as $x) echo "GAGAL  $x\n";
echo $gagal ? "\nAda yang gagal — kirim output ini ke saya.\n" : "\nSelesai. Buka localhost/NatixSwim/progres.php (isi config/wa-config.php untuk WhatsApp)\n";