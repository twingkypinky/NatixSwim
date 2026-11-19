<?php
// ============================================================
// simulator-wa.php — Simulator Chat WhatsApp (uji alur chatbot tanpa gateway WhatsApp)
// Memakai logika yang sama dengan chat asli: alurProses() (daftar, progres) dan gjBalasWa() (Check Gaji Bulan Ini).
// Semua data yang dibuat nyata masuk ke database (Data Member, Transaksi, Progres Murid, Gaji).
// ============================================================
if (($_GET['aksi'] ?? '') !== '') { ob_start(); ini_set('display_errors', '0'); }
date_default_timezone_set('Asia/Jakarta');
require 'config/koneksi.php';
require_once __DIR__ . '/alur-fungsi.php';

const SM_DEBUG = true;   // true = pesan error server ditampilkan apa adanya. Ubah ke false saat production.

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
            echo json_encode(['ok' => false, 'pesan' => SM_DEBUG ? 'Error PHP: ' . $e['message'] : 'Terjadi kesalahan pada server.'], JSON_UNESCAPED_UNICODE);
        }
    });
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    try {
        aqMigrasi($pdo);
        if (in_array($aksi, ['kirim', 'reset'], true) && !$isPost) out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
        switch ($aksi) {
            case 'info': {
                $pel = [];
                foreach (aqDaftarPelatih($pdo) as $c) $pel[] = ['id' => (int)$c['id'], 'nama' => gjNamaCoach((string)$c['nama']), 'hp' => gjNomor($c['hp'] ?? '')];
                out(['ok' => true, 'pelatih' => $pel]);
            }
            case 'kirim': {
                $no = gjNomor((string)($in['nomor'] ?? ''));
                $teks = trim((string)($in['teks'] ?? ''));
                if ($no === '') out(['ok' => false, 'pesan' => 'Nomor tidak valid. Contoh: 081234567890.'], 422);
                if ($teks === '' || mb_strlen($teks) > 1000) out(['ok' => false, 'pesan' => 'Pesan kosong atau terlalu panjang.'], 422);
                $GLOBALS['AL_SIMULASI'] = true; $GLOBALS['AL_OUTBOX'] = [];
                $balas = gjBalasWa($pdo, $no, $teks);          // sama dengan urutan di chat asli: gaji dulu,
                if ($balas === null) $balas = alurProses($pdo, $no, $teks);   // lalu alur daftar / progres
                $diluar = $balas === null;
                out(['ok' => true, 'balasan' => $diluar ? 'Pesan ini bukan bagian alur otomatis. Di sistem asli pesan seperti ini dijawab AI (Knowledge Base) atau diteruskan ke admin.' : $balas, 'alur' => !$diluar, 'notif' => $GLOBALS['AL_OUTBOX']]);
            }
            case 'reset': {
                aqHapusSesi($pdo, gjNomor((string)($in['nomor'] ?? '')));
                out(['ok' => true]);
            }
            default: out(['ok' => false, 'pesan' => 'Aksi tidak dikenal.'], 404);
        }
    } catch (Throwable $e) {
        error_log('[simulator] ' . $e->getMessage());
        out(['ok' => false, 'pesan' => SM_DEBUG ? $e->getMessage() : 'Terjadi kesalahan pada server.'], 500);
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
                ['Chat Log', 'manajemen-ai-chat.php', 'chat', [], false],
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
    <title>Simulator Chat — Natix Swim</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/reset.css" />
    <link rel="stylesheet" href="css/global.css" />
    <link rel="stylesheet" href="css/cdm-segmentasi.css" />
    <style>
        /* Semua class halaman ini memakai prefix "sm-" supaya tidak bentrok dengan CSS export Figma */
        .main-content { position: relative !important; height: auto !important; width: auto !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; box-sizing: border-box; min-width: 0; }
        .sm-page, .sm-page *, .sm-overlay, .sm-overlay * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
        .sm-page p, .sm-page h1, .sm-page h2, .sm-page h3 { margin: 0; }
        .sm-page { padding: 28px 32px 48px; color: #111827; width: 100%; max-width: 100%; min-width: 0; }

        /* Header */
        .sm-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-top: 6px; }
        .sm-title { font-size: 30px; font-weight: 700; line-height: 1.2; }
        .sm-sub { font-size: 15px; color: #6b7280; margin-top: 4px !important; }
        .sm-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .sm-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; white-space: nowrap; font-size: 14px; font-weight: 600; border-radius: 12px; padding: 11px 18px; cursor: pointer; border: 1px solid #e5e7eb; background: #fff; color: #111827; transition: background .15s; text-decoration: none; }
        .sm-btn:hover { background: #f9fafb; }
        .sm-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        .sm-btn.primary:hover { background: #1d4ed8; }
        .sm-btn.green { background: #16a34a; border-color: #16a34a; color: #fff; }
        .sm-btn.green:hover { background: #15803d; }
        .sm-btn.danger { border-color: #fca5a5; color: #dc2626; }
        .sm-btn.danger:hover { background: #fef2f2; }
        .sm-btn.danger.confirm { background: #dc2626; color: #fff; border-color: #dc2626; }
        .sm-btn.sm { padding: 7px 14px; font-size: 13px; border-radius: 10px; }
        .sm-btn:disabled { opacity: .55; cursor: not-allowed; }
        .sm-month { display: inline-flex; align-items: center; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
        .sm-month button { border: 0; background: transparent; width: 40px; height: 42px; cursor: pointer; font-size: 18px; color: #6b7280; }
        .sm-month button:hover { background: #f3f4f6; }
        .sm-month strong { min-width: 150px; text-align: center; font-size: 14px; font-weight: 600; padding: 0 6px; }

        /* Kartu ringkasan */
        .sm-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin: 26px 0 22px; }
        .sm-stat { background: #fff; border: 1px solid #eceef2; border-radius: 16px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; min-width: 0; min-height: 92px; }
        .sm-ico { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .sm-ico svg { width: 22px; height: 22px; }
        .sm-ico.blue { background: #eef4ff; color: #2563eb; } .sm-ico.green { background: #eafaf1; color: #16a34a; }
        .sm-ico.orange { background: #fff3e6; color: #ea6a0a; } .sm-ico.violet { background: #f1eefe; color: #6d4aff; }
        .sm-stat div { min-width: 0; }
        .sm-stat p { font-size: 14px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sm-stat h2 { font-size: 24px; font-weight: 700; line-height: 1.15; margin-top: 4px !important; white-space: nowrap; }
        .sm-stat small { display: block; font-size: 12px; color: #9ca3af; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Tab */
        .sm-tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .sm-tab { border: 1px solid #e5e7eb; background: #fff; color: #6b7280; font-size: 14px; font-weight: 600; padding: 9px 18px; border-radius: 999px; cursor: pointer; }
        .sm-tab:hover { background: #f9fafb; }
        .sm-tab.is-on { background: #111827; border-color: #111827; color: #fff; }
        .sm-panel { display: none; } .sm-panel.is-on { display: block; }

        /* Kartu / tabel */
        .sm-card { background: #fff; border: 1px solid #eceef2; border-radius: 18px; overflow-x: auto; overflow-y: hidden; }
        .sm-card-h { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 18px 22px; border-bottom: 1px solid #f1f2f5; }
        .sm-card-h h3 { font-size: 16px; font-weight: 700; } .sm-card-h small { color: #9ca3af; font-size: 13px; font-weight: 500; }
        .sm-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .sm-table th { text-align: left; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #6b7280; padding: 12px 16px; background: #fafafb; border-bottom: 1px solid #eceef2; white-space: nowrap; }
        .sm-table td { padding: 14px 16px; border-bottom: 1px solid #f1f2f5; vertical-align: middle; }
        .sm-table tr:last-child td { border-bottom: 0; }
        .sm-table tbody tr.sm-row { cursor: pointer; transition: background .12s; } .sm-table tbody tr.sm-row:hover { background: #f8fafd; }
        .sm-table .r { text-align: right; } .sm-table th.r { text-align: right; }
        .sm-coach { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .sm-av { width: 38px; height: 38px; border-radius: 50%; background: #eef2ff; color: #2563eb; font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .sm-coach b { display: block; font-weight: 600; } .sm-coach small { display: block; color: #9ca3af; font-size: 12.5px; margin-top: 1px; }
        .sm-chips { display: flex; gap: 6px; flex-wrap: wrap; }
        .sm-chip { font-size: 12px; font-weight: 600; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
        .sm-chip.kids { background: #eef2ff; color: #1e3a8a; } .sm-chip.dewasa { background: #e8f7ee; color: #14532d; } .sm-chip.private { background: #fff3e0; color: #7c4a03; }
        .sm-pos { color: #16a34a; } .sm-neg { color: #dc2626; } .sm-mut { color: #9ca3af; }
        .sm-total { font-weight: 700; }
        .sm-tag { display: inline-block; font-size: 12px; font-weight: 600; padding: 4px 11px; border-radius: 999px; white-space: nowrap; }
        .sm-tag.draft { background: #f3f4f6; color: #6b7280; } .sm-tag.disetujui { background: #eef2ff; color: #2563eb; } .sm-tag.dibayar { background: #dcfce7; color: #16a34a; }
        .sm-tag.belum { background: #f3f4f6; color: #9ca3af; } .sm-tag.terkirim { background: #dcfce7; color: #16a34a; } .sm-tag.gagal { background: #fee2e2; color: #dc2626; }
        .sm-empty { padding: 56px 16px; text-align: center; color: #9ca3af; font-size: 14px; }
        .sm-empty b { display: block; color: #6b7280; font-size: 15px; margin-bottom: 4px; }
        .sm-foot-note { padding: 14px 22px; border-top: 1px solid #f1f2f5; font-size: 13px; color: #6b7280; background: #fcfcfd; }

        /* Tarif */
        .sm-input { width: 100%; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; font-size: 14px; color: #111827; background: #fff; }
        .sm-input:focus { outline: 2px solid #bfdbfe; border-color: #93c5fd; }
        .sm-input:disabled { background: #f9fafb; color: #6b7280; }
        .sm-rp { position: relative; } .sm-rp span { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 13px; color: #9ca3af; pointer-events: none; }
        .sm-rp .sm-input { padding-left: 36px; text-align: right; }
        .sm-table .sm-rp { min-width: 130px; }
        tr.sm-std td { background: #f8fafc; font-weight: 600; }
        .sm-saved { box-shadow: 0 0 0 2px #86efac; transition: box-shadow .3s; }

        /* WhatsApp */
        .sm-wa { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; }
        .sm-box { padding: 22px; }
        .sm-box h3 { font-size: 16px; font-weight: 700; margin-bottom: 6px; } .sm-box p { font-size: 14px; color: #6b7280; line-height: 1.55; }
        .sm-switch { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 14px 0; border-bottom: 1px solid #f1f2f5; }
        .sm-switch b { display: block; font-size: 14px; font-weight: 600; } .sm-switch small { color: #9ca3af; font-size: 13px; }
        .sm-tg { position: relative; width: 46px; height: 26px; flex: 0 0 auto; }
        .sm-tg input { opacity: 0; width: 100%; height: 100%; position: absolute; inset: 0; margin: 0; cursor: pointer; z-index: 1; }
        .sm-tg i { position: absolute; inset: 0; background: #d1d5db; border-radius: 999px; transition: background .15s; }
        .sm-tg i::after { content: ''; position: absolute; left: 3px; top: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: transform .15s; }
        .sm-tg input:checked + i { background: #2563eb; } .sm-tg input:checked + i::after { transform: translateX(20px); }
        .sm-bubble { background: #e7f6ec; border-radius: 14px 14px 14px 4px; padding: 14px 16px; font-size: 13.5px; line-height: 1.55; color: #14321f; white-space: pre-wrap; margin-top: 14px; }
        .sm-cmd { display: inline-block; background: #f3f4f6; border-radius: 8px; padding: 2px 9px; font-weight: 600; color: #111827; font-size: 13px; }
        .sm-wa-full { grid-column: 1 / -1; }
        .sm-gw { padding: 18px 22px; }
        .sm-gw-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .sm-gw-dot { width: 12px; height: 12px; border-radius: 50%; background: #d1d5db; flex: 0 0 auto; box-shadow: 0 0 0 4px #f3f4f6; }
        .sm-gw-dot.ok { background: #16a34a; box-shadow: 0 0 0 4px #dcfce7; } .sm-gw-dot.wait { background: #e8890c; box-shadow: 0 0 0 4px #ffedd5; } .sm-gw-dot.off { background: #dc2626; box-shadow: 0 0 0 4px #fee2e2; }
        .sm-gw-txt { flex: 1 1 240px; min-width: 0; } .sm-gw-txt b { display: block; font-size: 15px; } .sm-gw-txt small { display: block; color: #6b7280; font-size: 13px; margin-top: 2px; line-height: 1.45; }
        .sm-gw-txt a { color: #2563eb; text-decoration: none; font-weight: 600; }
        .sm-gw-tes { display: flex; gap: 10px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #f1f2f5; flex-wrap: wrap; }
        .sm-gw-tes .sm-input { flex: 1 1 240px; min-width: 0; }

        /* Modal */
        .sm-overlay { position: fixed; inset: 0; background: rgba(17,24,39,.45); display: none; align-items: center; justify-content: center; padding: 20px; z-index: 1300; }
        .sm-overlay.is-open { display: flex; }
        .sm-modal { background: #fff; border-radius: 18px; width: 100%; max-width: 560px; max-height: calc(100vh - 40px); overflow-y: auto; padding: 26px; box-shadow: 0 24px 60px rgba(16,24,40,.25); }
        .sm-modal h2 { font-size: 20px; font-weight: 700; }
        .sm-modal-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
        .sm-modal-top p { font-size: 14px; color: #6b7280; margin-top: 3px; }
        .sm-x { border: 0; background: #f3f4f6; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 18px; line-height: 1; color: #6b7280; flex: 0 0 auto; }
        .sm-x:hover { background: #e5e7eb; }
        .sm-rin { border: 1px solid #f1f2f5; border-radius: 12px; overflow: hidden; margin-bottom: 18px; }
        .sm-rin .sm-table th, .sm-rin .sm-table td { padding: 10px 12px; }
        .sm-form { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .sm-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .sm-field.full { grid-column: 1 / -1; }
        .sm-field label { font-size: 13px; font-weight: 600; color: #374151; }
        .sm-sum { margin: 18px 0 0; border-top: 1px dashed #e5e7eb; padding-top: 14px; display: grid; gap: 6px; font-size: 14px; }
        .sm-sum div { display: flex; justify-content: space-between; gap: 10px; }
        .sm-sum .t { font-size: 18px; font-weight: 700; padding-top: 8px; border-top: 1px solid #111827; margin-top: 4px; }
        .sm-bayar { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; margin-top: 16px; }
        .sm-bayar h4 { margin: 0 0 10px; font-size: 14px; }
        .sm-mfoot { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; flex-wrap: wrap; }
        .sm-info { font-size: 13px; color: #6b7280; margin-top: 12px; line-height: 1.5; }
        .sm-err { color: #dc2626; font-size: 13px; margin-top: 10px; min-height: 0; }

        /* Toast */
        .sm-toast { font-family: 'Inter', sans-serif; position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(20px); background: #111827; color: #fff; padding: 12px 20px; border-radius: 12px; font-size: 14px; opacity: 0; pointer-events: none; transition: all .25s; z-index: 1500; max-width: calc(100vw - 32px); }
        .sm-toast.is-show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .sm-toast.err { background: #dc2626; }

        @media (max-width: 1200px) { .sm-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .sm-wa { grid-template-columns: 1fr; } }
        @media (max-width: 900px) {
            .sm-page { padding: 24px 20px 40px; }
            .sm-table thead { display: none; }
            .sm-table, .sm-table tbody, .sm-table tr, .sm-table td { display: block; width: 100%; }
            .sm-table tbody tr.sm-row { padding: 14px 16px; border-bottom: 1px solid #f1f2f5; }
            .sm-table tbody tr.sm-row td { border: 0; padding: 4px 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; text-align: right; }
            .sm-table tbody tr.sm-row td::before { content: attr(data-l); font-size: 12px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: .04em; text-align: left; flex: 0 0 auto; }
            .sm-table tbody tr.sm-row td.sm-c0 { display: block; padding-bottom: 8px; text-align: left; } .sm-table tbody tr.sm-row td.sm-c0::before { display: none; }
            .sm-table tbody tr.sm-row td.r { text-align: right; }
            .sm-tarif .sm-table tr { padding: 12px 16px; border-bottom: 1px solid #f1f2f5; } .sm-tarif .sm-table td { border: 0; padding: 4px 0; }
            .sm-tarif .sm-table td[data-l]::before { content: attr(data-l); display: block; font-size: 12px; font-weight: 600; color: #9ca3af; margin-bottom: 4px; }
        }
        @media (max-width: 640px) {
            .sm-page { padding: 20px 16px 40px; }
            .sm-title { font-size: 24px; }
            .sm-stats { grid-template-columns: 1fr; }
            .sm-actions { width: 100%; } .sm-actions .sm-btn { flex: 1 1 auto; } .sm-month { width: 100%; } .sm-month strong { flex: 1; }
            .sm-form { grid-template-columns: 1fr; } .sm-modal { padding: 20px 16px; }
            .sm-mfoot .sm-btn { flex: 1 1 auto; }
        }

        
        /* Simulator Chat */
        .sm-grid { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 16px; margin-top: 22px; align-items: start; }
        .sm-side { padding: 20px; display: grid; gap: 14px; }
        .sm-side h3 { font-size: 15px; font-weight: 700; }
        .sm-seg { display: flex; gap: 6px; background: #f3f4f6; border-radius: 12px; padding: 4px; }
        .sm-seg button { flex: 1; border: 0; background: transparent; padding: 9px 10px; border-radius: 9px; font-size: 13.5px; font-weight: 600; color: #6b7280; cursor: pointer; }
        .sm-seg button.is-on { background: #fff; color: #111827; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        .sm-lbl { font-size: 13px; font-weight: 600; color: #374151; display: block; margin-bottom: 6px; }
        .sm-hint { font-size: 12.5px; color: #9ca3af; line-height: 1.5; }
        .sm-chips { display: flex; gap: 8px; flex-wrap: wrap; }
        .sm-chip { border: 1px solid #e5e7eb; background: #fff; border-radius: 999px; padding: 7px 13px; font-size: 13px; font-weight: 600; cursor: pointer; color: #111827; }
        .sm-chip:hover { background: #f3f6ff; border-color: #bfdbfe; }
        .sm-links { display: grid; gap: 6px; font-size: 13px; }
        .sm-links a { color: #2563eb; text-decoration: none; font-weight: 600; }
        .sm-chat { display: flex; flex-direction: column; height: min(640px, calc(100vh - 220px)); min-height: 420px; overflow: hidden; }
        .sm-chat-h { display: flex; align-items: center; gap: 12px; padding: 14px 20px; border-bottom: 1px solid #f1f2f5; }
        .sm-chat-h b { display: block; font-size: 15px; } .sm-chat-h small { color: #9ca3af; font-size: 12.5px; }
        .sm-msgs { flex: 1; overflow-y: auto; padding: 18px 20px; background: #f7f5ef; display: flex; flex-direction: column; gap: 8px; }
        .sm-b { max-width: 78%; padding: 9px 13px; border-radius: 14px; font-size: 14px; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word; box-shadow: 0 1px 1px rgba(0,0,0,.06); }
        .sm-b.me { align-self: flex-end; background: #dcf8c6; border-bottom-right-radius: 4px; }
        .sm-b.bot { align-self: flex-start; background: #fff; border-bottom-left-radius: 4px; }
        .sm-b.sys { align-self: flex-start; background: #fff7e6; color: #92400e; font-size: 13px; }
        .sm-b.notif { align-self: flex-start; background: #e7f6ec; border: 1px dashed #86c9a0; }
        .sm-b small { display: block; font-size: 11.5px; font-weight: 600; opacity: .65; margin-bottom: 3px; }
        .sm-empty { margin: auto; text-align: center; color: #9ca3af; font-size: 14px; max-width: 320px; line-height: 1.6; }
        .sm-send { display: flex; gap: 10px; padding: 12px 16px; border-top: 1px solid #f1f2f5; background: #fff; }
        .sm-send .sm-input { flex: 1; min-width: 0; }
        @media (max-width: 1000px) { .sm-grid { grid-template-columns: 1fr; } .sm-chat { height: 560px; } }

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
            <div class="sm-page">
                <?= nvCrumb() ?>

                <div class="sm-head">
                    <div>
                        <h1 class="sm-title">Simulator Chat</h1>
                        <p class="sm-sub">Coba alur chatbot WhatsApp langsung di dashboard, tanpa perlu menautkan nomor. Datanya nyata masuk ke database.</p>
                    </div>
                    <div class="sm-actions">
                        <button class="sm-btn" id="sm-reset" type="button">Mulai Ulang Percakapan</button>
                    </div>
                </div>

                <div class="sm-grid">
                    <div class="sm-card sm-side">
                        <div>
                            <span class="sm-lbl">Chat sebagai</span>
                            <div class="sm-seg" id="sm-seg">
                                <button type="button" data-p="ortu" class="is-on">Orang Tua</button>
                                <button type="button" data-p="coach">Coach</button>
                            </div>
                        </div>
                        <div id="sm-f-ortu">
                            <label class="sm-lbl" for="sm-no-ortu">Nomor WhatsApp orang tua</label>
                            <input class="sm-input" id="sm-no-ortu" value="081355500001" inputmode="numeric">
                            <p class="sm-hint" style="margin-top:6px">Nomor ini jadi nomor member yang tersimpan saat mendaftar.</p>
                        </div>
                        <div id="sm-f-coach" hidden>
                            <label class="sm-lbl" for="sm-coach">Coach</label>
                            <select class="sm-input" id="sm-coach"></select>
                            <p class="sm-hint" id="sm-coach-info" style="margin-top:6px"></p>
                        </div>
                        <div>
                            <span class="sm-lbl">Pesan cepat</span>
                            <div class="sm-chips" id="sm-chips"></div>
                        </div>
                        <div class="sm-links">
                            <span class="sm-lbl" style="margin:0">Lihat hasilnya di</span>
                            <a href="datamember.php">Data Member →</a>
                            <a href="administrasi.php">Transaksi →</a>
                            <a href="progres.php">Progres Murid →</a>
                            <a href="gaji.php">Gaji →</a>
                        </div>
                    </div>

                    <div class="sm-card sm-chat">
                        <div class="sm-chat-h"><span class="sm-av" id="sm-av">OT</span><div><b id="sm-who">Orang Tua</b><small id="sm-no-lbl"></small></div></div>
                        <div class="sm-msgs" id="sm-msgs"><div class="sm-empty" id="sm-empty">Belum ada pesan.<br>Mulai dengan menekan pesan cepat di kiri, atau ketik sendiri.</div></div>
                        <form class="sm-send" id="sm-form" autocomplete="off">
                            <input class="sm-input" id="sm-teks" placeholder="Ketik pesan…" maxlength="1000">
                            <button class="sm-btn primary" id="sm-kirim" type="submit">Kirim</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    <div class="sm-toast" id="sm-toast"></div>

<script>
// ============================================================
// SIMULATOR CHAT
// ============================================================
const $ = id => document.getElementById(id);
const S = { p: 'ortu', coach: [], log: { ortu: [], coach: [] }, sibuk: false };
const CHIPS = { ortu: ['daftar', 'ya', 'batal'], coach: ['progres', 'Check Gaji Bulan Ini', 'menu', 'batal'] };

function esc(t) { const d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
let toastT;
function toast(t, err) { const el = $('sm-toast'); el.textContent = t; el.className = 'sm-toast is-show' + (err ? ' err' : ''); clearTimeout(toastT); toastT = setTimeout(() => el.classList.remove('is-show'), err ? 5000 : 2600); }

async function api(aksi, body) {
    const opt = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {};
    let res, j;
    try { res = await fetch('simulator-wa.php?aksi=' + aksi, opt); } catch (e) { throw new Error('Tidak bisa menghubungi server.'); }
    try { j = await res.json(); } catch (e) { throw new Error('Respons server tidak valid (HTTP ' + res.status + ').'); }
    if (!j.ok) throw new Error(j.pesan || 'Terjadi kesalahan.');
    return j;
}

function nomor() {
    if (S.p === 'ortu') return $('sm-no-ortu').value.trim();
    const c = S.coach.find(x => String(x.id) === $('sm-coach').value);
    return c ? c.hp : '';
}

function gambar() {
    const box = $('sm-msgs'), L = S.log[S.p];
    if (!L.length) { box.innerHTML = '<div class="sm-empty">Belum ada pesan.<br>Mulai dengan menekan pesan cepat di kiri, atau ketik sendiri.</div>'; return; }
    box.innerHTML = L.map(m => `<div class="sm-b ${m.t}">${m.t === 'notif' ? '<small>📨 Pesan WhatsApp ke ' + esc(m.to) + '</small>' : ''}${esc(m.x)}</div>`).join('');
    box.scrollTop = box.scrollHeight;
}

function ganti(p) {
    S.p = p;
    document.querySelectorAll('#sm-seg button').forEach(b => b.classList.toggle('is-on', b.dataset.p === p));
    $('sm-f-ortu').hidden = p !== 'ortu'; $('sm-f-coach').hidden = p !== 'coach';
    $('sm-who').textContent = p === 'ortu' ? 'Orang Tua' : ($('sm-coach').selectedOptions[0]?.textContent || 'Coach');
    $('sm-av').textContent = p === 'ortu' ? 'OT' : 'CH';
    $('sm-chips').innerHTML = CHIPS[p].map(c => `<button type="button" class="sm-chip" data-c="${esc(c)}">${esc(c)}</button>`).join('');
    info(); gambar();
}

function info() {
    const no = nomor();
    $('sm-no-lbl').textContent = no ? 'Nomor ' + no : '';
    if (S.p === 'coach') {
        const c = S.coach.find(x => String(x.id) === $('sm-coach').value);
        $('sm-coach-info').textContent = !S.coach.length ? 'Belum ada coach di Data Pelatih.' : (c && !c.hp ? '⚠️ Coach ini belum punya nomor WhatsApp. Isi dulu di Data Pelatih supaya dikenali.' : 'Nomor coach diambil dari Data Pelatih.');
    }
}

async function kirim(teks) {
    teks = (teks || '').trim();
    if (!teks || S.sibuk) return;
    const no = nomor();
    if (!no) return toast(S.p === 'coach' ? 'Coach ini belum punya nomor WhatsApp di Data Pelatih.' : 'Isi nomor WhatsApp dulu.', true);
    S.sibuk = true; $('sm-kirim').disabled = true;
    const L = S.log[S.p];
    L.push({ t: 'me', x: teks }); gambar(); $('sm-teks').value = '';
    try {
        const j = await api('kirim', { nomor: no, teks });
        L.push({ t: j.alur ? 'bot' : 'sys', x: j.balasan });
        (j.notif || []).forEach(n => { S.log.ortu.push({ t: 'notif', x: n.text, to: n.to }); });
        if ((j.notif || []).length && S.p !== 'ortu') toast('Laporan terkirim ke orang tua — lihat di tab Orang Tua.');
    } catch (e) { L.push({ t: 'sys', x: '⚠️ ' + e.message }); }
    S.sibuk = false; $('sm-kirim').disabled = false; gambar(); $('sm-teks').focus();
}

$('sm-seg').onclick = e => { const b = e.target.closest('button'); if (b) ganti(b.dataset.p); };
$('sm-chips').onclick = e => { const b = e.target.closest('.sm-chip'); if (b) kirim(b.dataset.c); };
$('sm-form').onsubmit = e => { e.preventDefault(); kirim($('sm-teks').value); };
$('sm-no-ortu').oninput = info;
$('sm-coach').onchange = () => { $('sm-who').textContent = $('sm-coach').selectedOptions[0]?.textContent || 'Coach'; info(); };
$('sm-reset').onclick = async () => {
    const no = nomor();
    try { if (no) await api('reset', { nomor: no }); } catch (e) { return toast(e.message, true); }
    S.log[S.p] = []; gambar(); toast('Percakapan dimulai ulang.');
};

(async function () {
    try {
        const j = await api('info');
        S.coach = j.pelatih;
        $('sm-coach').innerHTML = j.pelatih.length ? j.pelatih.map(c => `<option value="${c.id}">${esc(c.nama)}${c.hp ? '' : ' (tanpa nomor)'}</option>`).join('') : '<option value="">— belum ada coach —</option>';
    } catch (e) { toast(e.message, true); }
    ganti('ortu');
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
