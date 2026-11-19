<?php
// ============================================================
// kehadiran.php — Kehadiran (rekap & input absensi per sesi kelas)
// Tabel: jadwal, jadwal_peserta, presensi, paket_member, members
// ============================================================
$khAjax = (($_GET['aksi'] ?? '') !== '');
if ($khAjax) { ob_start(); ini_set('display_errors', '0'); }
else { ini_set('display_errors', '1'); error_reporting(E_ALL); }   // hapus 2 baris ini kalau sudah production
date_default_timezone_set('Asia/Jakarta');

// true = pesan error server ditampilkan apa adanya (membantu debug). Ubah ke false saat production.
const KH_DEBUG = true;

const KH_HARI  = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
const KH_BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const KH_STATUS = ['hadir', 'izin', 'sakit', 'alpa'];

function out(array $data, int $code = 200): void
{
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($khAjax) {
    // Error fatal tetap dibalas sebagai pesan JSON yang jelas
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level()) ob_end_clean();
            if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['ok' => false, 'pesan' => KH_DEBUG ? 'Error PHP: ' . $e['message'] : 'Terjadi kesalahan pada server.'], JSON_UNESCAPED_UNICODE);
        }
    });
}

if (!is_file(__DIR__ . '/config/koneksi.php')) {
    $msg = 'File config/koneksi.php tidak ditemukan di folder ' . __DIR__;
    if ($khAjax) out(['ok' => false, 'pesan' => $msg], 500);
    die('<pre style="padding:24px">' . htmlspecialchars($msg) . '</pre>');
}
require_once __DIR__ . '/config/koneksi.php';   // menghasilkan $pdo

// ─── FUNGSI BANTU ───
function khTglPanjang(string $ymd): string
{
    $t = strtotime($ymd);
    return KH_HARI[(int)date('N', $t) - 1] . ', ' . date('j', $t) . ' ' . KH_BULAN[(int)date('n', $t)] . ' ' . date('Y', $t);
}

function khJam(string $t): string { return substr($t, 0, 5); }

function khCoach(string $n): string { return preg_match('/^coach\b/i', $n) ? $n : 'Coach ' . $n; }

// Status sesi untuk ditampilkan: dihitung dari tanggal & jam (kolom status di DB ikut dipakai kalau sudah 'selesai' / 'dibatalkan')
function khStatusSesi(array $j): string
{
    if ($j['status'] === 'dibatalkan') return 'dibatalkan';
    if ($j['status'] === 'selesai')    return 'selesai';
    $hari = date('Y-m-d'); $now = date('H:i:s');
    if ($j['tanggal'] < $hari) return 'selesai';
    if ($j['tanggal'] > $hari) return 'belum';
    if ($now >= $j['jam_selesai']) return 'selesai';
    if ($now >= $j['jam_mulai'] || $j['status'] === 'berlangsung') return 'berlangsung';
    return 'belum';
}

function khTanggal(?string $t): string
{
    $d = $t ? DateTime::createFromFormat('Y-m-d', $t) : false;
    return ($d && $d->format('Y-m-d') === $t) ? $t : date('Y-m-d');
}

// Pencatat absensi = user yang login (kalau halaman Anda memakai $_SESSION['user_id']); selain itu NULL
function khUserId(PDO $pdo): ?int
{
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    if ($id <= 0) return null;
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :i");
    $st->execute([':i' => $id]);
    return $st->fetchColumn() ? $id : null;
}

// Pilih paket yang dipotong untuk member di sesi ini:
// paket yang sudah dipakai di presensi (kalau ada) > paket program yang sama yang masih aktif > paket aktif lain > paket terbaru
function khPilihPaket(array $pakets, int $programId, ?int $paketPresensi): ?array
{
    if (!$pakets) return null;
    if ($paketPresensi) {
        foreach ($pakets as $p) if ((int)$p['id'] === $paketPresensi) return $p;
    }
    usort($pakets, function ($a, $b) use ($programId) {
        $sa = ((int)$a['program_id'] === $programId ? 4 : 0) + ($a['status'] === 'aktif' && (int)$a['sisa_sesi'] > 0 ? 2 : 0);
        $sb = ((int)$b['program_id'] === $programId ? 4 : 0) + ($b['status'] === 'aktif' && (int)$b['sisa_sesi'] > 0 ? 2 : 0);
        return $sb <=> $sa ?: ((int)$b['id'] <=> (int)$a['id']);
    });
    return $pakets[0];
}

// Member peserta sebuah sesi + presensi + paket yang dipakai
function khAnggota(PDO $pdo, int $jid, int $programId): array
{
    $st = $pdo->prepare("SELECT m.id, m.nama, m.kode_member FROM members m
                          WHERE m.id IN (SELECT member_id FROM jadwal_peserta WHERE jadwal_id = :a)
                             OR m.id IN (SELECT member_id FROM presensi WHERE jadwal_id = :b)
                          ORDER BY m.nama");
    $st->execute([':a' => $jid, ':b' => $jid]);
    $members = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$members) return [];

    $st = $pdo->prepare("SELECT member_id, paket_id, status, catatan FROM presensi WHERE jadwal_id = :j");
    $st->execute([':j' => $jid]);
    $presensi = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $presensi[(int)$r['member_id']] = $r;

    $in = implode(',', array_map('intval', array_column($members, 'id')));
    $pk = $pdo->query("SELECT pm.id, pm.member_id, pm.program_id, pm.total_sesi, pm.sisa_sesi, pm.status, kp.nama_program
                         FROM paket_member pm JOIN kelas_program kp ON kp.id = pm.program_id
                        WHERE pm.member_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
    $paketPer = [];
    foreach ($pk as $p) $paketPer[(int)$p['member_id']][] = $p;

    $hasil = [];
    foreach ($members as $m) {
        $mid = (int)$m['id'];
        $pr  = $presensi[$mid] ?? null;
        $p   = khPilihPaket($paketPer[$mid] ?? [], $programId, $pr ? (int)$pr['paket_id'] : null);
        $hasil[$mid] = [
            'member_id'    => $mid,
            'nama'         => $m['nama'],
            'kode'         => $m['kode_member'],
            'paket_id'     => $p ? (int)$p['id'] : null,
            'program'      => $p ? $p['nama_program'] : null,
            'sisa'         => $p ? (int)$p['sisa_sesi'] : null,
            'total'        => $p ? (int)$p['total_sesi'] : null,
            'paket_status' => $p ? $p['status'] : null,
            'bisa_hadir'   => $p && $p['status'] === 'aktif' && (int)$p['sisa_sesi'] > 0,
            'status'       => $pr ? $pr['status'] : null,
            'catatan'      => $pr ? (string)$pr['catatan'] : '',
            'sudah_hadir'  => $pr && $pr['status'] === 'hadir',
        ];
    }
    return $hasil;
}

function khAmbilSesi(PDO $pdo, int $id, bool $kunci = false): ?array
{
    $fu = ($kunci && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') ? ' FOR UPDATE' : '';
    $st = $pdo->prepare("SELECT j.id, j.program_id, j.tanggal, j.jam_mulai, j.jam_selesai, j.kolam, j.status
                           FROM jadwal j WHERE j.id = :id" . $fu);
    $st->execute([':id' => $id]);
    $j = $st->fetch(PDO::FETCH_ASSOC);
    return $j ?: null;
}

// ============================================================
// ENDPOINT AJAX (JSON / CSV)
// ============================================================
if ($khAjax) {
    $aksi = $_GET['aksi'];
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if ($aksi === 'simpan' && !$isPost) out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        switch ($aksi) {

            // ── Ringkasan 1 hari: statistik + daftar sesi ──
            case 'data': {
                $tgl = khTanggal($_GET['tanggal'] ?? '');

                $st = $pdo->prepare("
                    SELECT j.id, j.tanggal, j.jam_mulai, j.jam_selesai, j.kolam, j.status,
                           k.nama_program, u.nama AS pelatih,
                           (SELECT COUNT(*) FROM jadwal_peserta jp WHERE jp.jadwal_id = j.id) AS terdaftar,
                           (SELECT COUNT(*) FROM presensi pr WHERE pr.jadwal_id = j.id)       AS tercatat
                      FROM jadwal j
                      JOIN kelas_program k ON k.id = j.program_id
                      JOIN pelatih pl      ON pl.id = j.pelatih_id
                      JOIN users u         ON u.id = pl.user_id
                     WHERE j.tanggal = :t
                     ORDER BY j.jam_mulai, j.id");
                $st->execute([':t' => $tgl]);
                $sesi = [];
                $jml = 0;
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $stt = khStatusSesi($r);
                    if ($stt !== 'dibatalkan') $jml++;
                    $sesi[] = [
                        'id' => (int)$r['id'], 'mulai' => khJam($r['jam_mulai']), 'selesai' => khJam($r['jam_selesai']),
                        'program' => $r['nama_program'], 'pelatih' => khCoach($r['pelatih']), 'kolam' => $r['kolam'],
                        'status' => $stt, 'terdaftar' => (int)$r['terdaftar'], 'tercatat' => (int)$r['tercatat'],
                    ];
                }

                $st = $pdo->prepare("
                    SELECT COALESCE(SUM(CASE WHEN pr.status = 'hadir' THEN 1 ELSE 0 END), 0) AS hadir,
                           COALESCE(SUM(CASE WHEN pr.status IN ('izin','sakit') THEN 1 ELSE 0 END), 0) AS izin,
                           COALESCE(SUM(CASE WHEN pr.status = 'alpa' THEN 1 ELSE 0 END), 0) AS alpa
                      FROM presensi pr JOIN jadwal j ON j.id = pr.jadwal_id
                     WHERE j.tanggal = :t AND j.status <> 'dibatalkan'");
                $st->execute([':t' => $tgl]);
                $s = $st->fetch(PDO::FETCH_ASSOC);

                out(['ok' => true, 'tanggal' => $tgl, 'label' => khTglPanjang($tgl), 'sesi' => $sesi,
                     'stat' => ['sesi' => $jml, 'hadir' => (int)$s['hadir'], 'izin' => (int)$s['izin'], 'alpa' => (int)$s['alpa']]]);
            }

            // ── Detail 1 sesi + daftar member untuk absensi ──
            case 'sesi': {
                $id = (int)($_GET['id'] ?? 0);
                $st = $pdo->prepare("
                    SELECT j.id, j.program_id, j.tanggal, j.jam_mulai, j.jam_selesai, j.kolam, j.status,
                           k.nama_program, u.nama AS pelatih
                      FROM jadwal j
                      JOIN kelas_program k ON k.id = j.program_id
                      JOIN pelatih pl      ON pl.id = j.pelatih_id
                      JOIN users u         ON u.id = pl.user_id
                     WHERE j.id = :id");
                $st->execute([':id' => $id]);
                $j = $st->fetch(PDO::FETCH_ASSOC);
                if (!$j) out(['ok' => false, 'pesan' => 'Sesi tidak ditemukan.'], 404);

                $boleh = true; $alasan = '';
                if ($j['status'] === 'dibatalkan')          { $boleh = false; $alasan = 'Sesi ini dibatalkan, absensi tidak bisa diisi.'; }
                elseif ($j['tanggal'] > date('Y-m-d'))      { $boleh = false; $alasan = 'Sesi belum berlangsung, absensi baru bisa diisi pada hari sesinya.'; }

                $anggota = array_values(khAnggota($pdo, $id, (int)$j['program_id']));
                out(['ok' => true,
                     'sesi' => ['id' => (int)$j['id'], 'program' => $j['nama_program'], 'pelatih' => khCoach($j['pelatih']),
                                'kolam' => $j['kolam'], 'mulai' => khJam($j['jam_mulai']), 'selesai' => khJam($j['jam_selesai']),
                                'status' => khStatusSesi($j), 'boleh_isi' => $boleh, 'alasan' => $alasan],
                     'anggota' => $anggota]);
            }

            // ── Simpan absensi satu sesi (semua atau tidak sama sekali) ──
            case 'simpan': {
                $jid   = (int)($in['jadwal_id'] ?? 0);
                $items = $in['items'] ?? [];
                if (!is_array($items) || !$items) out(['ok' => false, 'pesan' => 'Tidak ada data absensi untuk disimpan.'], 422);

                $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
                $fu    = $mysql ? ' FOR UPDATE' : '';

                $gagal = function (string $pesan, int $code = 409) use ($pdo) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    out(['ok' => false, 'pesan' => $pesan], $code);
                };

                $pdo->beginTransaction();
                $j = khAmbilSesi($pdo, $jid, true);
                if (!$j) $gagal('Sesi tidak ditemukan.', 404);
                if ($j['status'] === 'dibatalkan') $gagal('Sesi ini dibatalkan, absensi tidak bisa diisi.');
                if ($j['tanggal'] > date('Y-m-d')) $gagal('Sesi belum berlangsung, absensi baru bisa diisi pada hari sesinya.');

                $anggota = khAnggota($pdo, $jid, (int)$j['program_id']);
                $uid     = khUserId($pdo);
                $now     = date('Y-m-d H:i:s');
                $ringkas = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0];
                $diubah  = 0;

                foreach ($items as $it) {
                    $mid = (int)($it['member_id'] ?? 0);
                    $stt = (string)($it['status'] ?? '');
                    $cat = trim((string)($it['catatan'] ?? ''));
                    if (!isset($anggota[$mid]))            $gagal('Ada member yang bukan peserta sesi ini.', 422);
                    if (!in_array($stt, KH_STATUS, true))  $gagal('Status kehadiran tidak valid.', 422);
                    if (strlen($cat) > 255)                $gagal('Catatan maksimal 255 karakter.', 422);
                    $a = $anggota[$mid];
                    if (!$a['paket_id']) $gagal($a['nama'] . ' belum punya paket, absensinya tidak bisa dicatat.');

                    $st = $pdo->prepare("SELECT id, status, catatan FROM presensi WHERE jadwal_id = :j AND member_id = :m" . $fu);
                    $st->execute([':j' => $jid, ':m' => $mid]);
                    $ex = $st->fetch(PDO::FETCH_ASSOC);

                    $st = $pdo->prepare("SELECT sisa_sesi, status FROM paket_member WHERE id = :id" . $fu);
                    $st->execute([':id' => $a['paket_id']]);
                    $pk = $st->fetch(PDO::FETCH_ASSOC);
                    $sisa = (int)$pk['sisa_sesi'];
                    $stPk = $pk['status'];

                    $setPaket = function (int $sisaBaru, string $statusBaru) use ($pdo, $a) {
                        $pdo->prepare("UPDATE paket_member SET sisa_sesi = :s, status = :st WHERE id = :id")
                            ->execute([':s' => $sisaBaru, ':st' => $statusBaru, ':id' => $a['paket_id']]);
                    };
                    $cekKuota = function () use ($a, $sisa, $stPk, $gagal) {
                        if ($sisa <= 0 || $stPk !== 'aktif') {
                            $gagal('Kuota ' . $a['nama'] . ' sudah habis / paket tidak aktif. Ubah statusnya ke Izin/Absen atau perpanjang paketnya dulu.');
                        }
                    };

                    if ($ex) {
                        $lama = $ex['status'];
                        if ($lama === $stt && (string)$ex['catatan'] === $cat) { $ringkas[$stt]++; continue; }
                        if ($lama !== 'hadir' && $stt === 'hadir') {          // jadi hadir: potong 1 kuota
                            $cekKuota();
                            $setPaket($sisa - 1, $sisa - 1 <= 0 ? 'habis' : $stPk);
                        } elseif ($lama === 'hadir' && $stt !== 'hadir') {    // batal hadir: kembalikan 1 kuota
                            $setPaket($sisa + 1, $stPk === 'habis' ? 'aktif' : $stPk);
                        }
                        $pdo->prepare("UPDATE presensi SET status = :s, catatan = :c, dicatat_oleh = :u, waktu = :w WHERE id = :id")
                            ->execute([':s' => $stt, ':c' => $cat !== '' ? $cat : null, ':u' => $uid, ':w' => $now, ':id' => $ex['id']]);
                    } else {
                        if ($stt === 'hadir') $cekKuota();
                        $pdo->prepare("INSERT INTO presensi (jadwal_id, member_id, paket_id, status, catatan, dicatat_oleh)
                                       VALUES (:j, :m, :p, :s, :c, :u)")
                            ->execute([':j' => $jid, ':m' => $mid, ':p' => $a['paket_id'], ':s' => $stt,
                                       ':c' => $cat !== '' ? $cat : null, ':u' => $uid]);
                        if ($stt === 'hadir') {
                            // Trigger trg_presensi_hadir di database memotong kuota saat INSERT.
                            // Kalau triggernya tidak ada (kuota tidak berubah), potong manual supaya tidak terlewat.
                            $st = $pdo->prepare("SELECT sisa_sesi FROM paket_member WHERE id = :id");
                            $st->execute([':id' => $a['paket_id']]);
                            if ((int)$st->fetchColumn() === $sisa) $setPaket($sisa - 1, $sisa - 1 <= 0 ? 'habis' : $stPk);
                        }
                    }
                    $ringkas[$stt]++;
                    $diubah++;
                }
                $pdo->commit();

                out(['ok' => true, 'pesan' => $diubah ? 'Absensi tersimpan.' : 'Tidak ada perubahan untuk disimpan.', 'ringkas' => $ringkas, 'diubah' => $diubah]);
            }

            // ── Export CSV absensi 1 hari ──
            case 'export': {
                $tgl = khTanggal($_GET['tanggal'] ?? '');
                $st = $pdo->prepare("
                    SELECT j.tanggal, j.jam_mulai, j.jam_selesai, k.nama_program, u.nama AS pelatih, j.kolam,
                           m.kode_member, m.nama AS member, pr.status, pr.catatan
                      FROM presensi pr
                      JOIN jadwal j        ON j.id = pr.jadwal_id
                      JOIN kelas_program k ON k.id = j.program_id
                      JOIN pelatih pl      ON pl.id = j.pelatih_id
                      JOIN users u         ON u.id = pl.user_id
                      JOIN members m       ON m.id = pr.member_id
                     WHERE j.tanggal = :t
                     ORDER BY j.jam_mulai, j.id, m.nama");
                $st->execute([':t' => $tgl]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);

                $aman = function ($v) {   // cegah CSV injection
                    $v = (string)$v;
                    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
                };
                $label = ['hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit', 'alpa' => 'Absen'];

                while (ob_get_level()) ob_end_clean();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="kehadiran-' . $tgl . '.csv"');
                $o = fopen('php://output', 'w');
                fwrite($o, "\xEF\xBB\xBF");
                fputcsv($o, ['Tanggal', 'Jam', 'Program', 'Pelatih', 'Kolam', 'Kode Member', 'Member', 'Status', 'Catatan']);
                foreach ($rows as $r) {
                    fputcsv($o, [$r['tanggal'], khJam($r['jam_mulai']) . '-' . khJam($r['jam_selesai']), $aman($r['nama_program']),
                                 $aman($r['pelatih']), $aman($r['kolam']), $aman($r['kode_member']), $aman($r['member']),
                                 $label[$r['status']] ?? $r['status'], $aman($r['catatan'])]);
                }
                fclose($o);
                exit;
            }

            default:
                out(['ok' => false, 'pesan' => 'Aksi tidak dikenal.'], 400);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[kehadiran] ' . $e->getMessage());
        if ($aksi === 'export') {
            while (ob_get_level()) ob_end_clean();
            http_response_code(500);
            echo KH_DEBUG ? 'Gagal membuat export: ' . $e->getMessage() : 'Gagal membuat export.';
            exit;
        }
        out(['ok' => false, 'pesan' => KH_DEBUG ? 'Error server: ' . $e->getMessage() : 'Terjadi kesalahan pada server.'], 500);
    }
    exit;
}

$hariIni = date('Y-m-d');
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
    <title>Kehadiran — Natix Swim</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/reset.css" />
    <link rel="stylesheet" href="css/global.css" />
    <link rel="stylesheet" href="css/kehadiran.css" />
    <style>
        /* Semua class halaman ini memakai prefix "kh-" supaya tidak bentrok dengan CSS export Figma */
        .main { height: auto !important; min-height: 100vh; }
        .kh-topbar, .kh-topbar *, .kh-page, .kh-page * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
        .kh-page p, .kh-page h1, .kh-page h2, .kh-page h3 { margin: 0; }

        /* Topbar */
        .kh-topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 32px; background: #fff; border-bottom: 1px solid #eef0f3; }
        .kh-search { display: flex; align-items: center; gap: 10px; background: #f6f7fb; border-radius: 12px; padding: 0 16px; height: 44px; width: min(420px, 100%); color: #9ca3af; margin: 0; }
        .kh-search input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font-size: 14px; color: #111827; }
        .kh-search input::placeholder { color: #9ca3af; }
        .kh-avatar { width: 40px; height: 40px; border-radius: 50%; background: #eef2ff; color: #2563eb; font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; }

        /* Header */
        .kh-page { padding: 28px 32px 48px; color: #111827; width: 100%; }
        .kh-crumb { font-size: 14px; color: #9ca3af; }
        .kh-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-top: 6px; }
        .kh-title { font-size: 30px; font-weight: 700; line-height: 1.2; }
        .kh-sub { font-size: 15px; color: #6b7280; margin-top: 4px !important; }
        .kh-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .kh-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; white-space: nowrap; font-size: 14px; font-weight: 600; border-radius: 12px; padding: 11px 18px; cursor: pointer; border: 1px solid #e5e7eb; background: #fff; color: #111827; text-decoration: none; transition: background .15s; }
        .kh-btn:hover { background: #f9fafb; }
        .kh-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        .kh-btn.primary:hover { background: #1d4ed8; }
        .kh-btn:disabled { opacity: .5; cursor: not-allowed; }
        .kh-date { display: inline-flex; align-items: center; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; overflow: hidden; }
        .kh-date button { border: 0; background: transparent; width: 38px; height: 42px; cursor: pointer; color: #6b7280; font-size: 18px; }
        .kh-date button:hover { background: #f3f4f6; }
        .kh-date input { border: 0; border-left: 1px solid #f1f2f5; border-right: 1px solid #f1f2f5; height: 42px; padding: 0 12px; font-size: 14px; font-weight: 600; color: #111827; background: #fff; outline: 0; }

        /* Statistik */
        .kh-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin: 24px 0; }
        .kh-stat { background: #fff; border: 1px solid #eceef2; border-radius: 16px; padding: 20px 22px; display: flex; align-items: center; gap: 16px; min-width: 0; }
        .kh-stat-ico { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .kh-stat-ico.blue { background: #eef2ff; color: #2563eb; }
        .kh-stat-ico.green { background: #dcfce7; color: #16a34a; }
        .kh-stat-ico.orange { background: #fff3e0; color: #e8890c; }
        .kh-stat-ico.red { background: #fee2e2; color: #dc2626; }
        .kh-stat-lbl { font-size: 13px; color: #6b7280; }
        .kh-stat-num { font-size: 28px; font-weight: 700; line-height: 1.15; margin-top: 2px !important; }

        /* Grid utama */
        .kh-grid { display: grid; grid-template-columns: 340px minmax(0, 1fr); gap: 16px; align-items: start; }
        .kh-card { background: #fff; border: 1px solid #eceef2; border-radius: 16px; min-width: 0; }
        .kh-card-pad { padding: 22px 24px; }
        .kh-card-title { font-size: 17px; font-weight: 700; }
        .kh-card-sub { font-size: 13px; color: #9ca3af; margin-top: 2px !important; }

        /* Daftar sesi */
        .kh-sesi-list { display: flex; flex-direction: column; gap: 10px; margin-top: 16px; }
        .kh-sesi { display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; text-align: left; background: #fff; border: 1px solid #eceef2; border-radius: 12px; padding: 13px 14px; cursor: pointer; transition: border-color .15s, background .15s; }
        .kh-sesi:hover { background: #fafbff; }
        .kh-sesi.on { border-color: #2563eb; background: #f5f8ff; box-shadow: 0 0 0 3px rgba(37,99,235,.08); }
        .kh-sesi.batal { opacity: .6; }
        .kh-sesi b { display: block; font-size: 14px; font-weight: 600; color: #111827; }
        .kh-sesi span.m { display: block; font-size: 12px; color: #9ca3af; margin-top: 3px; }
        .kh-badge { font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 999px; white-space: nowrap; flex: 0 0 auto; }
        .kh-badge.selesai { background: #dcfce7; color: #16a34a; }
        .kh-badge.berlangsung { background: #dbeafe; color: #2563eb; }
        .kh-badge.belum { background: #f3f4f6; color: #6b7280; }
        .kh-badge.dibatalkan { background: #fee2e2; color: #dc2626; }
        .kh-empty { padding: 36px 16px; text-align: center; color: #9ca3af; font-size: 14px; }

        /* Panel absensi */
        .kh-ab-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 22px 24px 0; }
        .kh-dirty { font-size: 12px; font-weight: 600; color: #b45309; background: #fef3c7; border-radius: 999px; padding: 4px 10px; display: none; margin-right: 10px; }
        .kh-dirty.show { display: inline-block; }
        .kh-info { margin: 16px 24px 0; background: #eff4ff; border-radius: 10px; padding: 11px 14px; font-size: 13px; color: #1e3a8a; line-height: 1.5; }
        .kh-info.warn { background: #fffbeb; color: #92400e; }
        .kh-scroll { overflow-x: auto; margin-top: 16px; border-top: 1px solid #f1f2f5; }
        .kh-table { width: 100%; min-width: 800px; border-collapse: collapse; }
        .kh-table th { text-align: left; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #9ca3af; padding: 12px 16px; background: #fafafb; white-space: nowrap; border-bottom: 1px solid #eceef2; }
        .kh-table td { padding: 12px 16px; border-bottom: 1px solid #f1f2f5; font-size: 14px; color: #1f2937; vertical-align: middle; }
        .kh-table tr:last-child td { border-bottom: 0; }
        .kh-person { display: flex; align-items: center; gap: 12px; min-width: 170px; }
        .kh-av { width: 36px; height: 36px; border-radius: 50%; background: #eff1f4; color: #1f2937; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .kh-name { font-weight: 600; color: #111827; white-space: nowrap; }
        .kh-kode { font-size: 12px; color: #9ca3af; margin-top: 2px; }
        .kh-nowrap { white-space: nowrap; }
        .kh-kuota b { color: #111827; }
        .kh-kuota b.low { color: #ea580c; }
        .kh-kuota small { display: block; font-size: 11px; color: #9ca3af; margin-top: 2px; }
        .kh-muted { color: #9ca3af; }

        .kh-seg { display: inline-flex; background: #f3f4f6; border-radius: 10px; padding: 3px; gap: 2px; }
        .kh-seg button { border: 0; background: transparent; font-size: 13px; font-weight: 600; color: #6b7280; padding: 7px 13px; border-radius: 8px; cursor: pointer; white-space: nowrap; }
        .kh-seg button:hover:not(:disabled) { background: #e5e7eb; }
        .kh-seg button:disabled { opacity: .45; cursor: not-allowed; }
        .kh-seg button.on.hadir { background: #16a34a; color: #fff; }
        .kh-seg button.on.izin  { background: #f59e0b; color: #fff; }
        .kh-seg button.on.sakit { background: #2563eb; color: #fff; }
        .kh-seg button.on.alpa  { background: #dc2626; color: #fff; }

        .kh-note { width: 100%; min-width: 150px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px; font-size: 13px; color: #111827; background: #fff; }
        .kh-note:focus { outline: 2px solid #bfdbfe; border-color: #93c5fd; }
        .kh-note:disabled { background: #f9fafb; color: #9ca3af; }

        .kh-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(20px); background: #111827; color: #fff; padding: 12px 20px; border-radius: 12px; font-size: 14px; opacity: 0; pointer-events: none; transition: .25s; z-index: 2000; max-width: 90vw; }
        .kh-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .kh-toast.err { background: #dc2626; }

        @media (max-width: 1100px) { .kh-grid { grid-template-columns: 1fr; } .kh-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 640px) { .kh-page { padding: 20px 16px 40px; } .kh-topbar { padding: 14px 16px; } .kh-stats { grid-template-columns: 1fr; } }
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
        <header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div><input type="hidden" id="kh-q" value=""></header>

        <div class="kh-page">
            <?= nvCrumb() ?>

            <div class="kh-head">
                <div>
                    <h1 class="kh-title">Kehadiran</h1>
                    <p class="kh-sub"><span id="kh-label">Memuat…</span> · Rekap absensi per sesi kelas</p>
                </div>
                <div class="kh-actions">
                    <div class="kh-date">
                        <button type="button" id="kh-prev" title="Hari sebelumnya">‹</button>
                        <input type="date" id="kh-tgl" value="<?= $hariIni ?>">
                        <button type="button" id="kh-next" title="Hari berikutnya">›</button>
                    </div>
                    <button type="button" class="kh-btn" id="kh-today">Hari Ini</button>
                    <a class="kh-btn" id="kh-export" href="?aksi=export&tanggal=<?= $hariIni ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
                        Export
                    </a>
                </div>
            </div>

            <div class="kh-stats">
                <div class="kh-stat"><div class="kh-stat-ico blue"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg></div>
                    <div><p class="kh-stat-lbl">Sesi Hari Ini</p><p class="kh-stat-num" id="st-sesi">–</p></div></div>
                <div class="kh-stat"><div class="kh-stat-ico green"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/></svg></div>
                    <div><p class="kh-stat-lbl">Hadir</p><p class="kh-stat-num" id="st-hadir">–</p></div></div>
                <div class="kh-stat"><div class="kh-stat-ico orange"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
                    <div><p class="kh-stat-lbl">Izin / Sakit</p><p class="kh-stat-num" id="st-izin">–</p></div></div>
                <div class="kh-stat"><div class="kh-stat-ico red"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg></div>
                    <div><p class="kh-stat-lbl">Tidak Hadir</p><p class="kh-stat-num" id="st-alpa">–</p></div></div>
            </div>

            <div class="kh-grid">
                <!-- Daftar sesi -->
                <div class="kh-card kh-card-pad">
                    <p class="kh-card-title" id="kh-sesi-judul">Sesi Hari Ini</p>
                    <p class="kh-card-sub">Pilih sesi untuk input absensi</p>
                    <div class="kh-sesi-list" id="kh-sesi-list"></div>
                </div>

                <!-- Panel absensi -->
                <div class="kh-card">
                    <div class="kh-ab-head">
                        <div>
                            <p class="kh-card-title" id="kh-ab-judul">Absensi</p>
                            <p class="kh-card-sub" id="kh-ab-sub">Pilih sesi di sebelah kiri</p>
                        </div>
                        <div>
                            <span class="kh-dirty" id="kh-dirty">Belum disimpan</span>
                            <button type="button" class="kh-btn primary" id="kh-simpan" disabled>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                                Simpan Absensi
                            </button>
                        </div>
                    </div>
                    <div class="kh-info" id="kh-info">Status <b>Hadir</b> otomatis mengurangi 1 kuota sesi. Status <b>Izin</b>, <b>Sakit</b>, dan <b>Absen</b> tidak memotong kuota.</div>
                    <div class="kh-scroll">
                        <table class="kh-table">
                            <thead><tr><th>Member</th><th>Program</th><th>Sisa Kuota</th><th>Status Kehadiran</th><th>Catatan</th></tr></thead>
                            <tbody id="kh-body"><tr><td colspan="5" class="kh-empty">Belum ada sesi dipilih.</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="kh-toast" id="kh-toast"></div>

<script>
var S = { tanggal: '<?= $hariIni ?>', hariIni: '<?= $hariIni ?>', sesi: [], aktif: null, detail: null, pilih: {}, dirty: false, q: '' };
var $ = function (id) { return document.getElementById(id); };
var OPSI = [['hadir', 'Hadir'], ['izin', 'Izin'], ['sakit', 'Sakit'], ['alpa', 'Absen']];
var LABEL_SESI = { belum: 'Belum Mulai', berlangsung: 'Berlangsung', selesai: 'Selesai', dibatalkan: 'Dibatalkan' };

function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
function inisial(n) { var k = String(n || '').trim().split(/\s+/); return ((k[0] || '')[0] || '') + ((k[1] || '')[0] || '') || '?'; }

function toast(msg, err) {
    var t = $('kh-toast');
    t.textContent = msg; t.classList.toggle('err', !!err); t.classList.add('show');
    clearTimeout(toast._t); toast._t = setTimeout(function () { t.classList.remove('show'); }, 3000);
}

// Memanggil halaman ini sendiri (tidak bergantung pada nama file)
function api(aksi, params, body) {
    var url = '?' + new URLSearchParams(Object.assign({ aksi: aksi }, params || {}));
    var opt = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {};
    var status = 0, txt = '';
    return fetch(url, opt)
        .then(function (r) { status = r.status; return r.text(); })
        .then(function (t) { txt = t; return JSON.parse(t); })
        .catch(function (e) {
            console.error('[kehadiran] aksi=' + aksi, 'HTTP', status, txt.slice(0, 800), e);
            return { ok: false, pesan: status
                ? 'Server membalas bukan JSON (HTTP ' + status + '). Buka Console browser (F12) untuk melihat isinya.'
                : 'Gagal terhubung ke server.' };
        });
}

function setDirty(v) {
    S.dirty = v;
    $('kh-dirty').classList.toggle('show', v);
}

function sudahAman() {
    return !S.dirty || window.confirm('Ada perubahan absensi yang belum disimpan. Lanjutkan tanpa menyimpan?');
}

// ===== MUAT DATA 1 HARI =====
function muatHari(pilihId) {
    return api('data', { tanggal: S.tanggal }).then(function (j) {
        if (!j.ok) { toast(j.pesan || 'Gagal memuat data.', true); return; }
        S.tanggal = j.tanggal; S.sesi = j.sesi;
        $('kh-tgl').value = j.tanggal;
        $('kh-label').textContent = j.label;
        $('kh-export').href = '?aksi=export&tanggal=' + j.tanggal;
        $('kh-sesi-judul').textContent = j.tanggal === S.hariIni ? 'Sesi Hari Ini' : 'Sesi Tanggal Ini';
        $('st-sesi').textContent = j.stat.sesi; $('st-hadir').textContent = j.stat.hadir;
        $('st-izin').textContent = j.stat.izin; $('st-alpa').textContent = j.stat.alpa;

        var ada = function (id) { return S.sesi.some(function (s) { return s.id === id; }); };
        var tujuan = pilihId && ada(pilihId) ? pilihId : (S.aktif && ada(S.aktif) ? S.aktif : null);
        if (!tujuan && S.sesi.length) {
            var jalan = S.sesi.filter(function (s) { return s.status === 'berlangsung'; })[0];
            tujuan = (jalan || S.sesi[0]).id;
        }
        renderSesi();
        return tujuan ? muatSesi(tujuan) : kosongkanAbsensi();
    });
}

function kosongkanAbsensi() {
    S.aktif = null; S.detail = null; S.pilih = {}; setDirty(false);
    $('kh-ab-judul').textContent = 'Absensi';
    $('kh-ab-sub').textContent = 'Tidak ada sesi pada tanggal ini';
    $('kh-body').innerHTML = '<tr><td colspan="5" class="kh-empty">Tidak ada sesi kelas pada tanggal ini.</td></tr>';
    $('kh-simpan').disabled = true;
}

function renderSesi() {
    var q = S.q.trim().toLowerCase();
    var list = S.sesi.filter(function (s) { return !q || (s.mulai + ' ' + s.program + ' ' + s.pelatih + ' ' + s.kolam).toLowerCase().indexOf(q) > -1; });
    if (!S.sesi.length) { $('kh-sesi-list').innerHTML = '<div class="kh-empty">Tidak ada sesi kelas pada tanggal ini.</div>'; return; }
    if (!list.length)   { $('kh-sesi-list').innerHTML = '<div class="kh-empty">Tidak ada sesi yang cocok dengan pencarian.</div>'; return; }
    $('kh-sesi-list').innerHTML = list.map(function (s) {
        var catat = s.tercatat > 0 ? ' · ' + s.tercatat + '/' + s.terdaftar + ' tercatat' : '';
        return '<button type="button" class="kh-sesi' + (s.id === S.aktif ? ' on' : '') + (s.status === 'dibatalkan' ? ' batal' : '') + '" data-id="' + s.id + '">'
            + '<div><b>' + esc(s.mulai + ' · ' + s.program) + '</b><span class="m">' + esc(s.pelatih + ' · ' + s.kolam + catat) + '</span></div>'
            + '<span class="kh-badge ' + s.status + '">' + LABEL_SESI[s.status] + '</span></button>';
    }).join('');
}

// ===== MUAT 1 SESI =====
function muatSesi(id) {
    return api('sesi', { id: id }).then(function (j) {
        if (!j.ok) { toast(j.pesan || 'Gagal memuat sesi.', true); return; }
        S.aktif = id; S.detail = j; S.pilih = {}; setDirty(false);
        j.anggota.forEach(function (a) {
            // Belum ada catatan: default Hadir (kalau kuota masih ada), supaya guru cukup mengubah yang tidak hadir
            S.pilih[a.member_id] = {
                status: a.status || (a.bisa_hadir ? 'hadir' : null),
                catatan: a.catatan || '',
                baru: !a.status
            };
        });
        renderSesi(); renderAbsensi();
    });
}

function kuotaHtml(a) {
    if (a.sisa == null) return '<span class="kh-muted">Belum ada paket</span>';
    var st = S.pilih[a.member_id].status, akhir = a.sisa, panah = false;
    if (st === 'hadir' && !a.sudah_hadir)      { akhir = a.sisa - 1; panah = true; }
    else if (st !== 'hadir' && a.sudah_hadir)  { akhir = a.sisa + 1; panah = true; }
    var low = akhir <= 2 ? ' low' : '';
    var sub = a.paket_status !== 'aktif' ? '<small>Paket ' + esc(a.paket_status) + '</small>' : '<small>dari ' + a.total + ' sesi</small>';
    return '<span class="kh-kuota">' + (panah ? a.sisa + ' → <b class="' + low + '">' + akhir + '</b>' : '<b class="' + low + '">' + a.sisa + '</b>') + sub + '</span>';
}

function renderAbsensi() {
    var d = S.detail, s = d.sesi, q = S.q.trim().toLowerCase();
    $('kh-ab-judul').textContent = 'Absensi · ' + s.program + ' ' + s.mulai;
    $('kh-ab-sub').textContent = s.pelatih + ' · ' + d.anggota.length + ' member terdaftar';

    var info = $('kh-info');
    if (!s.boleh_isi) { info.className = 'kh-info warn'; info.textContent = s.alasan; }
    else { info.className = 'kh-info'; info.innerHTML = 'Status <b>Hadir</b> otomatis mengurangi 1 kuota sesi. Status <b>Izin</b>, <b>Sakit</b>, dan <b>Absen</b> tidak memotong kuota.'; }
    $('kh-simpan').disabled = !s.boleh_isi || !d.anggota.length;

    var rows = d.anggota.filter(function (a) { return !q || (a.nama + ' ' + a.kode).toLowerCase().indexOf(q) > -1; });
    if (!d.anggota.length) { $('kh-body').innerHTML = '<tr><td colspan="5" class="kh-empty">Belum ada member terdaftar di sesi ini.</td></tr>'; return; }
    if (!rows.length)      { $('kh-body').innerHTML = '<tr><td colspan="5" class="kh-empty">Tidak ada member yang cocok dengan pencarian.</td></tr>'; return; }

    $('kh-body').innerHTML = rows.map(function (a) {
        var p = S.pilih[a.member_id], off = !s.boleh_isi || !a.paket_id;
        var seg = OPSI.map(function (o) {
            var dis = off || (o[0] === 'hadir' && !a.bisa_hadir && !a.sudah_hadir) ? ' disabled' : '';
            return '<button type="button" class="' + o[0] + (p.status === o[0] ? ' on' : '') + '" data-mid="' + a.member_id + '" data-st="' + o[0] + '"' + dis
                + (dis && o[0] === 'hadir' && a.paket_id ? ' title="Kuota habis / paket tidak aktif"' : '') + '>' + o[1] + '</button>';
        }).join('');
        return '<tr>'
            + '<td><div class="kh-person"><div class="kh-av">' + esc(inisial(a.nama)) + '</div><div><p class="kh-name">' + esc(a.nama) + '</p><p class="kh-kode">' + esc(a.kode) + '</p></div></div></td>'
            + '<td class="kh-nowrap">' + (a.program ? esc(a.program) : '<span class="kh-muted">-</span>') + '</td>'
            + '<td class="kh-nowrap" id="kq-' + a.member_id + '">' + kuotaHtml(a) + '</td>'
            + '<td><div class="kh-seg">' + seg + '</div></td>'
            + '<td><input class="kh-note" type="text" maxlength="255" placeholder="Catatan (opsional)" data-mid="' + a.member_id + '" value="' + esc(p.catatan) + '"' + (off ? ' disabled' : '') + '></td>'
            + '</tr>';
    }).join('');
}

// ===== AKSI =====
function pilihStatus(mid, st) {
    S.pilih[mid].status = st; setDirty(true);
    var a = S.detail.anggota.filter(function (x) { return x.member_id === mid; })[0];
    var row = document.querySelector('.kh-seg button[data-mid="' + mid + '"]').closest('tr');
    row.querySelectorAll('.kh-seg button').forEach(function (b) { b.classList.toggle('on', b.dataset.st === st); });
    $('kq-' + mid).innerHTML = kuotaHtml(a);
}

function simpan() {
    var items = [];
    Object.keys(S.pilih).forEach(function (mid) {
        var p = S.pilih[mid];
        if (p.status) items.push({ member_id: Number(mid), status: p.status, catatan: p.catatan });
    });
    if (!items.length) { toast('Belum ada status kehadiran yang dipilih.', true); return; }

    var btn = $('kh-simpan'); btn.disabled = true;
    api('simpan', {}, { jadwal_id: S.aktif, items: items }).then(function (j) {
        if (j.ok) {
            toast(j.pesan + ' (' + j.ringkas.hadir + ' hadir, ' + (j.ringkas.izin + j.ringkas.sakit) + ' izin/sakit, ' + j.ringkas.alpa + ' absen)');
            setDirty(false);
            return muatHari(S.aktif);
        }
        toast(j.pesan || 'Gagal menyimpan absensi.', true);
        btn.disabled = false;
    });
}

// ===== EVENT =====
$('kh-sesi-list').addEventListener('click', function (e) {
    var b = e.target.closest('.kh-sesi'); if (!b) return;
    var id = Number(b.dataset.id);
    if (id === S.aktif || !sudahAman()) return;
    muatSesi(id);
});
$('kh-body').addEventListener('click', function (e) {
    var b = e.target.closest('.kh-seg button'); if (!b || b.disabled) return;
    pilihStatus(Number(b.dataset.mid), b.dataset.st);
});
$('kh-body').addEventListener('input', function (e) {
    if (!e.target.classList.contains('kh-note')) return;
    S.pilih[e.target.dataset.mid].catatan = e.target.value; setDirty(true);
});
$('kh-simpan').addEventListener('click', simpan);

function gantiTanggal(t) {
    if (!t || !sudahAman()) return;
    S.tanggal = t; S.aktif = null; muatHari();
}
function geser(n) {
    var d = new Date(S.tanggal + 'T00:00:00'); d.setDate(d.getDate() + n);
    var p = function (x) { return String(x).padStart(2, '0'); };
    gantiTanggal(d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()));
}
$('kh-prev').addEventListener('click', function () { geser(-1); });
$('kh-next').addEventListener('click', function () { geser(1); });
$('kh-today').addEventListener('click', function () { gantiTanggal(S.hariIni); });
$('kh-tgl').addEventListener('change', function (e) { gantiTanggal(e.target.value); });
$('kh-q').addEventListener('input', function (e) { S.q = e.target.value; renderSesi(); if (S.detail) renderAbsensi(); });
window.addEventListener('beforeunload', function (e) { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });

muatHari();
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