<?php
// ============================================================
// jadwal-kelas.php — Jadwal Kelas (kalender mingguan)
// ============================================================
if (($_GET['aksi'] ?? '') !== '') { ob_start(); ini_set('display_errors', '0'); }
date_default_timezone_set('Asia/Jakarta');
require 'config/koneksi.php';

// true = pesan error server ditampilkan apa adanya (membantu debug). Ubah ke false saat production.
const JW_DEBUG = true;

const HARI_PENDEK = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
const HARI_PANJANG = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
const BULAN_PENDEK = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const BULAN_PANJANG = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// ─── FUNGSI BANTU ───
function out(array $data, int $code = 200): void
{
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Senin s.d. Minggu dari tanggal mana pun di dalam pekan tersebut
function pekan(string $tgl): array
{
    $d = DateTime::createFromFormat('Y-m-d', $tgl) ?: new DateTime('today');
    $d->setTime(0, 0);
    $d->modify('-' . ((int)$d->format('N') - 1) . ' days');
    return [$d, (clone $d)->modify('+6 days')];
}

function labelPekan(DateTime $a, DateTime $b): string
{
    $da = (int)$a->format('j'); $db = (int)$b->format('j');
    $ma = (int)$a->format('n'); $mb = (int)$b->format('n');
    $ya = $a->format('Y');      $yb = $b->format('Y');
    if ($ya !== $yb) return "$da " . BULAN_PENDEK[$ma] . " $ya – $db " . BULAN_PENDEK[$mb] . " $yb";
    if ($ma !== $mb) return "$da " . BULAN_PENDEK[$ma] . " – $db " . BULAN_PENDEK[$mb] . " $yb";
    return "$da – $db " . BULAN_PANJANG[$mb] . " $yb";
}

function tglPanjang(string $ymd): string
{
    $t = strtotime($ymd);
    return HARI_PANJANG[(int)date('N', $t) - 1] . ', ' . date('j', $t) . ' ' . BULAN_PANJANG[(int)date('n', $t)] . ' ' . date('Y', $t);
}

function namaPelatih(string $n): string
{
    return preg_match('/^coach\b/i', $n) ? $n : 'Coach ' . $n;
}

// Warna kartu: private = oranye, kids = biru, selain itu (dewasa) = hijau
function kategoriProgram(string $tipe, string $nama): string
{
    if ($tipe === 'private' || stripos($nama, 'private') !== false) return 'private';
    if (preg_match('/kids|anak|baby|junior/i', $nama)) return 'kids';
    return 'dewasa';
}

function jam(string $t): string
{
    return substr($t, 0, 5);
}

// ============================================================
// ENDPOINT AJAX (JSON)
// ============================================================
$aksi = $_GET['aksi'] ?? '';

if ($aksi !== '') {
    header('Content-Type: application/json; charset=utf-8');
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level()) ob_end_clean();
            if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['ok' => false, 'pesan' => JW_DEBUG ? 'Error PHP: ' . $e['message'] : 'Terjadi kesalahan pada server.'], JSON_UNESCAPED_UNICODE);
        }
    });
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;

    if (in_array($aksi, ['tambah', 'batal', 'peserta_tambah', 'peserta_hapus'], true) && !$isPost) {
        out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
    }

    try {
        switch ($aksi) {

            // ── Data kalender 1 pekan ──
            case 'data': {
                [$mon, $sun] = pekan($_GET['start'] ?? date('Y-m-d'));
                $s = $mon->format('Y-m-d');
                $e = $sun->format('Y-m-d');
                $fKolam = trim($_GET['kolam'] ?? '');
                $fPel   = (int)($_GET['pelatih'] ?? 0);

                $st = $pdo->prepare("
                    SELECT j.id, j.tanggal, j.jam_mulai, j.jam_selesai, j.kolam, j.kapasitas, j.status,
                           j.pelatih_id, k.nama_program, k.tipe, u.nama AS pelatih,
                           (SELECT COUNT(*) FROM jadwal_peserta jp WHERE jp.jadwal_id = j.id) AS terisi,
                           (SELECT m.nama FROM jadwal_peserta jp JOIN members m ON m.id = jp.member_id
                             WHERE jp.jadwal_id = j.id ORDER BY jp.id LIMIT 1) AS peserta_pertama
                      FROM jadwal j
                      JOIN kelas_program k ON k.id = j.program_id
                      JOIN pelatih pl      ON pl.id = j.pelatih_id
                      JOIN users u         ON u.id = pl.user_id
                     WHERE j.tanggal BETWEEN :s AND :e AND j.status <> 'dibatalkan'
                     ORDER BY j.tanggal, j.jam_mulai, j.id");
                $st->execute([':s' => $s, ':e' => $e]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);

                // Bentrok = pelatih yang sama, tanggal sama, jam saling tumpang tindih
                // (dihitung sebelum filter supaya bentrok tetap terdeteksi walau difilter)
                $bentrok = [];
                $n = count($rows);
                for ($a = 0; $a < $n; $a++) {
                    for ($b = $a + 1; $b < $n; $b++) {
                        if ($rows[$a]['pelatih_id'] == $rows[$b]['pelatih_id']
                            && $rows[$a]['tanggal'] === $rows[$b]['tanggal']
                            && $rows[$a]['jam_mulai'] < $rows[$b]['jam_selesai']
                            && $rows[$b]['jam_mulai'] < $rows[$a]['jam_selesai']) {
                            $bentrok[$rows[$a]['id']] = true;
                            $bentrok[$rows[$b]['id']] = true;
                        }
                    }
                }

                $items = [];
                foreach ($rows as $r) {
                    if ($fKolam !== '' && $r['kolam'] !== $fKolam) continue;
                    if ($fPel > 0 && (int)$r['pelatih_id'] !== $fPel) continue;
                    $items[] = [
                        'id'          => (int)$r['id'],
                        'hari'        => (int)date('N', strtotime($r['tanggal'])) - 1,
                        'tanggal'     => $r['tanggal'],
                        'mulai'       => jam($r['jam_mulai']),
                        'selesai'     => jam($r['jam_selesai']),
                        'kolam'       => $r['kolam'],
                        'program'     => $r['nama_program'],
                        'kategori'    => kategoriProgram($r['tipe'], $r['nama_program']),
                        'pelatih_id'  => (int)$r['pelatih_id'],
                        'pelatih'     => namaPelatih($r['pelatih']),
                        'kapasitas'   => (int)$r['kapasitas'],
                        'terisi'      => (int)$r['terisi'],
                        'peserta'     => $r['peserta_pertama'],
                        'status'      => $r['status'],
                        'penuh'       => (int)$r['kapasitas'] > 0 && (int)$r['terisi'] >= (int)$r['kapasitas'],
                        'bentrok'     => isset($bentrok[$r['id']]),
                    ];
                }

                $days = [];
                $today = date('Y-m-d');
                for ($i = 0; $i < 7; $i++) {
                    $d = (clone $mon)->modify("+$i days");
                    $days[] = ['tgl' => $d->format('Y-m-d'), 'hari' => HARI_PENDEK[$i], 'no' => (int)$d->format('j'), 'today' => $d->format('Y-m-d') === $today];
                }

                $kolamList = $pdo->query("SELECT DISTINCT kolam FROM jadwal ORDER BY kolam")->fetchAll(PDO::FETCH_COLUMN);
                $pelList = $pdo->query("SELECT pl.id, u.nama FROM pelatih pl JOIN users u ON u.id = pl.user_id
                                         WHERE pl.status = 'aktif' ORDER BY u.nama")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($pelList as &$p) { $p['id'] = (int)$p['id']; $p['nama'] = namaPelatih($p['nama']); }
                unset($p);

                out([
                    'ok' => true, 'start' => $s, 'end' => $e,
                    'label' => 'Minggu, ' . labelPekan($mon, $sun),
                    'kolam_text' => implode(' & ', $kolamList),
                    'days' => $days, 'items' => $items,
                    'kolam_list' => $kolamList, 'pelatih_list' => $pelList,
                ]);
            }

            // ── Pilihan untuk form Tambah Jadwal ──
            case 'opsi': {
                $prog = $pdo->query("SELECT id, nama_program, tipe, kapasitas FROM kelas_program WHERE status = 'aktif' ORDER BY nama_program")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($prog as &$p) { $p['id'] = (int)$p['id']; $p['kapasitas'] = (int)$p['kapasitas']; }
                unset($p);
                $pel = $pdo->query("SELECT pl.id, u.nama FROM pelatih pl JOIN users u ON u.id = pl.user_id WHERE pl.status = 'aktif' ORDER BY u.nama")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($pel as &$p) { $p['id'] = (int)$p['id']; $p['nama'] = namaPelatih($p['nama']); }
                unset($p);
                $kolam = $pdo->query("SELECT DISTINCT kolam FROM jadwal ORDER BY kolam")->fetchAll(PDO::FETCH_COLUMN);
                out(['ok' => true, 'program' => $prog, 'pelatih' => $pel, 'kolam' => $kolam]);
            }

            // ── Detail 1 kelas + daftar peserta ──
            case 'detail': {
                $id = (int)($_GET['id'] ?? 0);
                $st = $pdo->prepare("
                    SELECT j.id, j.tanggal, j.jam_mulai, j.jam_selesai, j.kolam, j.kapasitas, j.status,
                           k.nama_program, k.tipe, u.nama AS pelatih
                      FROM jadwal j
                      JOIN kelas_program k ON k.id = j.program_id
                      JOIN pelatih pl      ON pl.id = j.pelatih_id
                      JOIN users u         ON u.id = pl.user_id
                     WHERE j.id = :id");
                $st->execute([':id' => $id]);
                $j = $st->fetch(PDO::FETCH_ASSOC);
                if (!$j) out(['ok' => false, 'pesan' => 'Jadwal tidak ditemukan.'], 404);

                $sp = $pdo->prepare("SELECT m.id, m.nama, m.kode_member FROM jadwal_peserta jp
                                       JOIN members m ON m.id = jp.member_id
                                      WHERE jp.jadwal_id = :id ORDER BY m.nama");
                $sp->execute([':id' => $id]);
                $peserta = $sp->fetchAll(PDO::FETCH_ASSOC);

                out(['ok' => true, 'jadwal' => [
                    'id' => (int)$j['id'], 'program' => $j['nama_program'],
                    'kategori' => kategoriProgram($j['tipe'], $j['nama_program']),
                    'tanggal' => tglPanjang($j['tanggal']),
                    'mulai' => jam($j['jam_mulai']), 'selesai' => jam($j['jam_selesai']),
                    'kolam' => $j['kolam'], 'pelatih' => namaPelatih($j['pelatih']),
                    'kapasitas' => (int)$j['kapasitas'], 'terisi' => count($peserta),
                    'status' => $j['status'],
                    'bisa_ubah' => in_array($j['status'], ['akan_datang', 'berlangsung'], true),
                ], 'peserta' => $peserta]);
            }

            // ── Member yang belum ikut kelas ini (untuk dropdown tambah peserta) ──
            case 'member_cari': {
                $id = (int)($_GET['id'] ?? 0);
                $st = $pdo->prepare("SELECT m.id, m.nama, m.kode_member FROM members m
                                      WHERE m.status = 'aktif'
                                        AND m.id NOT IN (SELECT member_id FROM jadwal_peserta WHERE jadwal_id = :id)
                                      ORDER BY m.nama LIMIT 300");
                $st->execute([':id' => $id]);
                out(['ok' => true, 'member' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            }

            // ── Tambah jadwal ──
            case 'tambah': {
                $program = (int)($in['program_id'] ?? 0);
                $pelatih = (int)($in['pelatih_id'] ?? 0);
                $tanggal = trim($in['tanggal'] ?? '');
                $mulai   = trim($in['jam_mulai'] ?? '');
                $selesai = trim($in['jam_selesai'] ?? '');
                $kolam   = trim($in['kolam'] ?? '');
                $kap     = (int)($in['kapasitas'] ?? 0);
                $paksa   = !empty($in['paksa']);

                if (!$program || !$pelatih || $tanggal === '' || $mulai === '' || $selesai === '' || $kolam === '' || $kap < 1) {
                    out(['ok' => false, 'pesan' => 'Semua kolom wajib diisi dan kapasitas minimal 1.'], 422);
                }
                if (!DateTime::createFromFormat('Y-m-d', $tanggal)) {
                    out(['ok' => false, 'pesan' => 'Format tanggal tidak valid.'], 422);
                }
                if (!preg_match('/^\d{2}:\d{2}$/', $mulai) || !preg_match('/^\d{2}:\d{2}$/', $selesai)) {
                    out(['ok' => false, 'pesan' => 'Format jam tidak valid.'], 422);
                }
                if ($selesai <= $mulai) {
                    out(['ok' => false, 'pesan' => 'Jam selesai harus lebih besar dari jam mulai.'], 422);
                }
                if (strlen($kolam) > 30) {
                    out(['ok' => false, 'pesan' => 'Nama kolam maksimal 30 karakter.'], 422);
                }

                $cek = $pdo->prepare("SELECT COUNT(*) FROM kelas_program WHERE id = :id AND status = 'aktif'");
                $cek->execute([':id' => $program]);
                if (!$cek->fetchColumn()) out(['ok' => false, 'pesan' => 'Program tidak ditemukan / non-aktif.'], 422);

                $cek = $pdo->prepare("SELECT COUNT(*) FROM pelatih WHERE id = :id AND status = 'aktif'");
                $cek->execute([':id' => $pelatih]);
                if (!$cek->fetchColumn()) out(['ok' => false, 'pesan' => 'Pelatih tidak ditemukan / tidak aktif.'], 422);

                // Cek bentrok pelatih
                $bt = $pdo->prepare("
                    SELECT k.nama_program, j.jam_mulai, j.jam_selesai
                      FROM jadwal j JOIN kelas_program k ON k.id = j.program_id
                     WHERE j.pelatih_id = :p AND j.tanggal = :t AND j.status <> 'dibatalkan'
                       AND j.jam_mulai < :selesai AND :mulai < j.jam_selesai
                     LIMIT 1");
                $bt->execute([':p' => $pelatih, ':t' => $tanggal, ':selesai' => $selesai . ':00', ':mulai' => $mulai . ':00']);
                $b = $bt->fetch(PDO::FETCH_ASSOC);
                if ($b && !$paksa) {
                    out(['ok' => false, 'bentrok' => true,
                         'pesan' => 'Pelatih sudah mengajar ' . $b['nama_program'] . ' pukul ' . jam($b['jam_mulai']) . '–' . jam($b['jam_selesai']) . ' pada tanggal tersebut.'], 409);
                }

                $ins = $pdo->prepare("INSERT INTO jadwal (program_id, pelatih_id, tanggal, jam_mulai, jam_selesai, kolam, kapasitas, status)
                                      VALUES (:pr, :pl, :t, :m, :s, :k, :kap, 'akan_datang')");
                $ins->execute([':pr' => $program, ':pl' => $pelatih, ':t' => $tanggal, ':m' => $mulai . ':00', ':s' => $selesai . ':00', ':k' => $kolam, ':kap' => $kap]);
                out(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'pesan' => 'Jadwal berhasil ditambahkan.']);
            }

            // ── Batalkan jadwal ──
            case 'batal': {
                $id = (int)($in['id'] ?? 0);
                $st = $pdo->prepare("UPDATE jadwal SET status = 'dibatalkan' WHERE id = :id AND status IN ('akan_datang','berlangsung')");
                $st->execute([':id' => $id]);
                if (!$st->rowCount()) out(['ok' => false, 'pesan' => 'Jadwal tidak bisa dibatalkan (sudah selesai / dibatalkan).'], 409);
                out(['ok' => true, 'pesan' => 'Jadwal dibatalkan.']);
            }

            // ── Tambah peserta ──
            case 'peserta_tambah': {
                $jid = (int)($in['jadwal_id'] ?? 0);
                $mid = (int)($in['member_id'] ?? 0);

                $st = $pdo->prepare("SELECT kapasitas, status, (SELECT COUNT(*) FROM jadwal_peserta WHERE jadwal_id = jadwal.id) AS terisi FROM jadwal WHERE id = :id");
                $st->execute([':id' => $jid]);
                $j = $st->fetch(PDO::FETCH_ASSOC);
                if (!$j) out(['ok' => false, 'pesan' => 'Jadwal tidak ditemukan.'], 404);
                if (!in_array($j['status'], ['akan_datang', 'berlangsung'], true)) out(['ok' => false, 'pesan' => 'Jadwal ini sudah tidak bisa diubah.'], 409);
                if ((int)$j['terisi'] >= (int)$j['kapasitas']) out(['ok' => false, 'pesan' => 'Kelas sudah penuh.'], 409);

                $cek = $pdo->prepare("SELECT COUNT(*) FROM members WHERE id = :id AND status = 'aktif'");
                $cek->execute([':id' => $mid]);
                if (!$cek->fetchColumn()) out(['ok' => false, 'pesan' => 'Member tidak ditemukan / non-aktif.'], 422);

                $ins = $pdo->prepare("INSERT IGNORE INTO jadwal_peserta (jadwal_id, member_id) VALUES (:j, :m)");
                $ins->execute([':j' => $jid, ':m' => $mid]);
                out(['ok' => true, 'pesan' => 'Peserta ditambahkan.']);
            }

            // ── Hapus peserta ──
            case 'peserta_hapus': {
                $jid = (int)($in['jadwal_id'] ?? 0);
                $mid = (int)($in['member_id'] ?? 0);
                $st = $pdo->prepare("DELETE jp FROM jadwal_peserta jp JOIN jadwal j ON j.id = jp.jadwal_id
                                      WHERE jp.jadwal_id = :j AND jp.member_id = :m AND j.status IN ('akan_datang','berlangsung')");
                $st->execute([':j' => $jid, ':m' => $mid]);
                out(['ok' => true, 'pesan' => 'Peserta dihapus dari kelas.']);
            }

            default:
                out(['ok' => false, 'pesan' => 'Aksi tidak dikenal.'], 400);
        }
    } catch (Throwable $e) {
        error_log('[cdm-jadwal] ' . $e->getMessage());
        out(['ok' => false, 'pesan' => JW_DEBUG ? 'Error server: ' . $e->getMessage() : 'Terjadi kesalahan pada server.'], 500);
    }
    exit;
}

$startAwal = date('Y-m-d');
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
    <title>Jadwal Kelas — Natix Swim</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/reset.css" />
    <link rel="stylesheet" href="css/global.css" />
    <link rel="stylesheet" href="css/cdm-segmentasi.css" />
    <style>
        /* Semua class halaman ini memakai prefix "jw-" supaya tidak bentrok dengan CSS export Figma */
        .main-content { position: relative !important; height: auto !important; width: 100% !important; box-sizing: border-box; }
        .jw-page, .jw-page *, .jw-topbar, .jw-topbar *, .jw-overlay, .jw-overlay * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
        .jw-page p, .jw-page h1, .jw-page h2 { margin: 0; }

        /* Topbar */
        .jw-topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 32px; background: #fff; border-bottom: 1px solid #eef0f3; }
        .jw-search { display: flex; align-items: center; gap: 10px; background: #f6f7fb; border-radius: 12px; padding: 0 16px; height: 44px; width: min(420px, 100%); color: #9ca3af; }
        .jw-search input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font-size: 14px; color: #111827; }
        .jw-search input::placeholder { color: #9ca3af; }
        .jw-top-right { display: flex; align-items: center; gap: 12px; }
        .jw-iconbtn { width: 40px; height: 40px; border-radius: 50%; border: 1px solid #eceef2; background: #fff; display: flex; align-items: center; justify-content: center; color: #6b7280; cursor: pointer; }
        .jw-avatar { width: 40px; height: 40px; border-radius: 50%; background: #eef2ff; color: #2563eb; font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; }

        /* Header halaman */
        .jw-page { padding: 28px 32px 48px; color: #111827; width: 100%; }
        .jw-crumb { font-size: 14px; color: #9ca3af; }
        .jw-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-top: 6px; }
        .jw-title { font-size: 30px; font-weight: 700; line-height: 1.2; }
        .jw-sub { font-size: 15px; color: #6b7280; margin-top: 4px !important; }
        .jw-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .jw-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; white-space: nowrap; font-size: 14px; font-weight: 600; border-radius: 12px; padding: 11px 18px; cursor: pointer; border: 1px solid #e5e7eb; background: #fff; color: #111827; transition: background .15s; }
        .jw-btn:hover { background: #f9fafb; }
        .jw-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        .jw-btn.primary:hover { background: #1d4ed8; }
        .jw-btn.danger { background: #fff; border-color: #fca5a5; color: #dc2626; }
        .jw-btn.danger:hover { background: #fef2f2; }
        .jw-btn.danger.confirm { background: #dc2626; color: #fff; border-color: #dc2626; }
        .jw-btn:disabled { opacity: .55; cursor: not-allowed; }

        /* Legenda + filter */
        .jw-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 28px 0 14px; }
        .jw-legend { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; font-size: 14px; color: #374151; }
        .jw-legend span { display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; }
        .jw-dot { width: 11px; height: 11px; border-radius: 50%; }
        .jw-filters { display: flex; gap: 10px; flex-wrap: wrap; }
        .jw-select { appearance: none; -webkit-appearance: none; background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%236b7280' stroke-width='1.6' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 14px center; border: 1px solid #e5e7eb; border-radius: 12px; padding: 11px 36px 11px 16px; font-size: 14px; color: #374151; cursor: pointer; max-width: 220px; }
        .jw-select:focus { outline: 2px solid #bfdbfe; }

        /* Kalender */
        .jw-cal { background: #fff; border: 1px solid #eceef2; border-radius: 18px; overflow: hidden; }
        .jw-scroll { overflow-x: auto; }
        .jw-grid { display: grid; grid-template-columns: 72px repeat(7, minmax(130px, 1fr)); min-width: 980px; transition: opacity .15s; }
        .jw-grid.is-loading { opacity: .45; pointer-events: none; }
        .jw-corner, .jw-dayh { background: #fafafb; border-bottom: 1px solid #eceef2; padding: 14px 8px; }
        .jw-dayh { text-align: center; display: flex; flex-direction: column; gap: 2px; border-left: 1px solid #f1f2f5; }
        .jw-dayh span { font-size: 15px; font-weight: 600; color: #111827; }
        .jw-dayh small { font-size: 13px; color: #9ca3af; }
        .jw-dayh.is-today span, .jw-dayh.is-today small { color: #2563eb; }
        .jw-time { padding: 12px 0 0 14px; font-size: 13px; color: #9ca3af; border-bottom: 1px solid #f1f2f5; min-height: 84px; }
        .jw-cell { border-left: 1px solid #f1f2f5; border-bottom: 1px solid #f1f2f5; padding: 6px; min-height: 84px; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .jw-cell.is-today { background: #fbfcff; }
        .jw-empty { grid-column: 1 / -1; padding: 56px 16px; text-align: center; color: #9ca3af; font-size: 14px; }

        /* Kartu kelas */
        .jw-ev { display: block; width: 100%; text-align: left; cursor: pointer; border: 0; border-left: 3px solid; border-radius: 10px; padding: 9px 12px; font-size: 13px; line-height: 1.35; transition: transform .1s, box-shadow .1s; }
        .jw-ev:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(16,24,40,.08); }
        .jw-ev b { display: block; font-size: 14px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .jw-ev span { display: block; font-weight: 400; overflow-wrap: anywhere; }
        .jw-ev.kids    { background: #eef2ff; border-color: #2563eb; color: #1e3a8a; }
        .jw-ev.dewasa  { background: #e8f7ee; border-color: #16a34a; color: #14532d; }
        .jw-ev.private { background: #fff3e0; border-color: #e8890c; color: #7c4a03; }
        .jw-ev.red     { background: #fdeaea; border-color: #ef4444; color: #8f1d1d; }

        /* Modal */
        .jw-overlay { position: fixed; inset: 0; background: rgba(17,24,39,.45); display: none; align-items: center; justify-content: center; padding: 20px; z-index: 1000; }
        .jw-overlay.is-open { display: flex; }
        .jw-modal { background: #fff; border-radius: 18px; width: 100%; max-width: 520px; max-height: calc(100vh - 40px); overflow-y: auto; padding: 26px; box-shadow: 0 24px 60px rgba(16,24,40,.25); }
        .jw-modal h2 { font-size: 20px; font-weight: 700; }
        .jw-modal-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
        .jw-x { border: 0; background: #f3f4f6; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 18px; line-height: 1; color: #6b7280; flex: 0 0 auto; }
        .jw-x:hover { background: #e5e7eb; }
        .jw-form { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .jw-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .jw-field.full { grid-column: 1 / -1; }
        .jw-field label { font-size: 13px; font-weight: 600; color: #374151; }
        .jw-input { width: 100%; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; font-size: 14px; color: #111827; background: #fff; }
        .jw-input:focus { outline: 2px solid #bfdbfe; border-color: #93c5fd; }
        .jw-warn { grid-column: 1 / -1; background: #fef3c7; border: 1px solid #fde68a; color: #92400e; border-radius: 10px; padding: 10px 12px; font-size: 13px; line-height: 1.4; display: none; }
        .jw-warn.is-show { display: block; }
        .jw-foot { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; flex-wrap: wrap; }
        .jw-info { display: grid; grid-template-columns: 110px 1fr; gap: 10px 12px; font-size: 14px; }
        .jw-info dt { color: #9ca3af; margin: 0; }
        .jw-info dd { margin: 0; color: #111827; font-weight: 500; }
        .jw-tag { display: inline-block; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 999px; }
        .jw-tag.akan_datang { background: #eef2ff; color: #2563eb; }
        .jw-tag.berlangsung { background: #dcfce7; color: #16a34a; }
        .jw-tag.selesai { background: #f3f4f6; color: #6b7280; }
        .jw-sec { margin-top: 22px; }
        .jw-sec h3 { font-size: 14px; font-weight: 700; margin: 0 0 10px; display: flex; justify-content: space-between; }
        .jw-sec h3 small { font-weight: 500; color: #9ca3af; font-size: 13px; }
        .jw-plist { list-style: none; margin: 0; padding: 0; border: 1px solid #f1f2f5; border-radius: 12px; }
        .jw-plist li { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-bottom: 1px solid #f1f2f5; font-size: 14px; }
        .jw-plist li:last-child { border-bottom: 0; }
        .jw-plist .kode { color: #9ca3af; font-size: 12px; margin-left: auto; }
        .jw-plist .rm { border: 0; background: transparent; color: #dc2626; cursor: pointer; font-size: 13px; font-weight: 600; padding: 2px 6px; }
        .jw-plist .rm:hover { text-decoration: underline; }
        .jw-pempty { padding: 14px; text-align: center; color: #9ca3af; font-size: 13px; }
        .jw-addrow { display: flex; gap: 8px; margin-top: 10px; }
        .jw-addrow .jw-input { flex: 1; }
        .jw-glist { display: flex; flex-direction: column; gap: 8px; }

        /* Toast */
        .jw-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(20px); background: #111827; color: #fff; padding: 12px 20px; border-radius: 12px; font-size: 14px; opacity: 0; pointer-events: none; transition: .25s; z-index: 2000; max-width: 90vw; }
        .jw-toast.is-show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .jw-toast.err { background: #dc2626; }

        @media (max-width: 640px) {
            .jw-page { padding: 20px 16px 40px; }
            .jw-topbar { padding: 14px 16px; }
            .jw-form { grid-template-columns: 1fr; }
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

/* ===== Jadwal Kelas: kalender menyesuaikan lebar layar (tanpa geser horizontal) ===== */
.jw-cal { overflow: hidden; }
.jw-scroll { overflow-x: visible; }
.jw-grid { grid-template-columns: 56px repeat(7, minmax(0, 1fr)); min-width: 0; }
.jw-corner, .jw-dayh { padding: 12px 4px; }
.jw-dayh span { font-size: 14px; } .jw-dayh small { font-size: 12px; }
.jw-time { padding: 10px 0 0 10px; font-size: 12px; min-height: 72px; }
.jw-cell { padding: 5px; gap: 5px; min-height: 72px; }
.jw-ev { padding: 7px 8px; font-size: 12px; border-radius: 9px; border-left-width: 3px; }
.jw-ev b { font-size: 12.5px; white-space: normal; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.jw-ev span { font-size: 11.5px; }
.jw-head > div:first-child { min-width: 0; }
@media (max-width: 1200px) {
  .jw-page { padding: 24px 20px 40px; }
  .jw-time { padding-left: 6px; font-size: 11px; }
  .jw-grid { grid-template-columns: 46px repeat(7, minmax(0, 1fr)); }
  .jw-cell { padding: 3px; gap: 3px; }
  .jw-ev { padding: 5px 5px 5px 6px; font-size: 11px; border-radius: 7px; }
  .jw-ev b { font-size: 11.5px; -webkit-line-clamp: 3; }
  .jw-ev span { font-size: 10.5px; }
  .jw-dayh span { font-size: 12.5px; }
}
@media (max-width: 1000px) { .jw-toolbar { margin-top: 20px; } }
@media (max-width: 640px) {
  .jw-title { font-size: 24px; }
  .jw-sub { font-size: 14px; }
  .jw-head { align-items: stretch; }
  .jw-actions { width: 100%; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
  .jw-actions .jw-btn { padding: 10px 6px; font-size: 13px; }
  .jw-actions .jw-btn.primary { grid-column: 1 / -1; }
  .jw-toolbar { margin: 18px 0 12px; }
  .jw-legend { gap: 8px 16px; font-size: 13px; }
  .jw-filters { width: 100%; } .jw-filters .jw-select { flex: 1 1 0; min-width: 0; max-width: none; }
  .jw-grid.is-agenda { display: block; }
  .jw-ag-day { padding: 12px 14px 14px; border-bottom: 1px solid #f1f2f5; }
  .jw-ag-day:last-child { border-bottom: 0; }
  .jw-ag-day h4 { margin: 0 0 8px; font-size: 14px; font-weight: 700; color: #111827; display: flex; align-items: baseline; gap: 8px; }
  .jw-ag-day h4 small { font-size: 12px; font-weight: 500; color: #9ca3af; }
  .jw-ag-day.is-today h4, .jw-ag-day.is-today h4 small { color: #2563eb; }
  .jw-ag-item { display: flex; gap: 10px; margin-top: 6px; align-items: stretch; }
  .jw-ag-item time { flex: 0 0 44px; font-size: 12px; line-height: 1.35; color: #6b7280; padding-top: 8px; }
  .jw-ag-item time i { display: block; font-style: normal; color: #9ca3af; }
  .jw-ag-item .jw-ev { flex: 1; min-width: 0; font-size: 13px; padding: 9px 12px; }
  .jw-ag-item .jw-ev b { font-size: 14px; -webkit-line-clamp: 2; } .jw-ag-item .jw-ev span { font-size: 12.5px; }
  .jw-ag-none { font-size: 13px; color: #9ca3af; }
  .jw-modal { padding: 20px 16px; } .jw-info { grid-template-columns: 90px 1fr; }
  .jw-addrow { flex-direction: column; }
}
/* jw-fit: kalender pas satu layar, tanpa geser-geser (desktop) */
@media (min-width: 641px) {
  .jw-cal { display: flex !important; flex-direction: column; height: calc(100vh - 330px); min-height: 460px; }
  .jw-scroll { flex: 1 1 auto; min-height: 0; overflow-x: hidden !important; overflow-y: auto !important; scrollbar-width: thin; }
  .jw-grid { min-height: 100%; grid-template-rows: auto; grid-auto-rows: minmax(72px, 1fr); }
  .jw-grid .jw-corner, .jw-grid .jw-dayh { position: sticky; top: 0; z-index: 2; }
  .jw-grid .jw-empty { align-self: center; }
}
</style>
</head>
<body>

    <!-- ─── SIDEBAR ─── -->
    <?php nvSidebar(); ?>


    <!-- ─── MAIN ─── -->
    <div class="main">
        <header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div><input type="hidden" id="jw-q" value=""></header>

        <div class="main-content">
            <div class="jw-page">
                <?= nvCrumb() ?>

                <div class="jw-head">
                    <div>
                        <h1 class="jw-title">Jadwal Kelas</h1>
                        <p class="jw-sub" id="jw-label">Memuat…</p>
                    </div>
                    <div class="jw-actions">
                        <button class="jw-btn" id="jw-today" type="button">Hari Ini</button>
                        <button class="jw-btn" id="jw-prev" type="button">‹ Minggu Lalu</button>
                        <button class="jw-btn" id="jw-next" type="button">Minggu Depan ›</button>
                        <button class="jw-btn primary" id="jw-add" type="button">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                            Tambah Jadwal
                        </button>
                    </div>
                </div>

                <div class="jw-toolbar">
                    <div class="jw-legend">
                        <span><i class="jw-dot" style="background:#2563eb"></i>Kids</span>
                        <span><i class="jw-dot" style="background:#16a34a"></i>Dewasa</span>
                        <span><i class="jw-dot" style="background:#e8890c"></i>Private</span>
                        <span><i class="jw-dot" style="background:#ef4444"></i>Penuh / Bentrok</span>
                    </div>
                    <div class="jw-filters">
                        <select class="jw-select" id="jw-f-kolam"><option value="">Semua Kolam</option></select>
                        <select class="jw-select" id="jw-f-pelatih"><option value="">Semua Pelatih</option></select>
                    </div>
                </div>

                <div class="jw-cal">
                    <div class="jw-scroll">
                        <div class="jw-grid" id="jw-grid"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="jw-overlay" id="jw-overlay"><div class="jw-modal" id="jw-modal"></div></div>
    <div class="jw-toast" id="jw-toast"></div>

<script>
// ============================================================
// STATE
// ============================================================
const S = { start: '<?= $startAwal ?>', kolam: '', pelatih: '', q: '', data: null, opsi: null, force: false };
const $ = id => document.getElementById(id);
const STATUS_LABEL = { akan_datang: 'Akan Datang', berlangsung: 'Berlangsung', selesai: 'Selesai' };

function esc(t) {
    const d = document.createElement('div');
    d.textContent = t == null ? '' : t;
    return d.innerHTML;
}

function toast(msg, err) {
    const t = $('jw-toast');
    t.textContent = msg;
    t.classList.toggle('err', !!err);
    t.classList.add('is-show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => t.classList.remove('is-show'), 2800);
}

async function api(aksi, params, body) {
    const url = '?' + new URLSearchParams(Object.assign({ aksi: aksi }, params || {}));
    const opt = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {};
    let j, status = 0, txt = '';
    try {
        const r = await fetch(url, opt);
        status = r.status;
        txt = await r.text();
        j = JSON.parse(txt);
        j._status = status;
    } catch (e) {
        console.error('[jadwal] aksi=' + aksi, 'HTTP', status, '\n', txt.slice(0, 800), e);
        j = { ok: false, pesan: status
            ? 'Server membalas bukan JSON (HTTP ' + status + '). Buka Console browser (F12) untuk melihat isinya.'
            : 'Gagal terhubung ke server (cek URL / koneksi).' };
    }
    return j;
}

// ============================================================
// KALENDER
// ============================================================
function shiftWeek(days) {
    const d = new Date(S.start + 'T00:00:00');
    d.setDate(d.getDate() + days);
    const p = n => String(n).padStart(2, '0');
    S.start = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    loadWeek();
}

async function loadWeek() {
    $('jw-grid').classList.add('is-loading');
    const j = await api('data', { start: S.start, kolam: S.kolam, pelatih: S.pelatih });
    $('jw-grid').classList.remove('is-loading');
    if (!j.ok) { toast(j.pesan || 'Gagal memuat jadwal.', true); return; }
    S.data = j;
    S.start = j.start;
    $('jw-label').textContent = j.label + (j.kolam_text ? ' · ' + j.kolam_text : '');
    fillFilters(j);
    renderGrid();
}

function fillFilters(j) {
    const k = $('jw-f-kolam'), p = $('jw-f-pelatih');
    k.innerHTML = '<option value="">Semua Kolam</option>' + j.kolam_list.map(x => '<option value="' + esc(x) + '">' + esc(x) + '</option>').join('');
    p.innerHTML = '<option value="">Semua Pelatih</option>' + j.pelatih_list.map(x => '<option value="' + x.id + '">' + esc(x.nama) + '</option>').join('');
    k.value = S.kolam;
    p.value = S.pelatih;
}

function visibleItems() {
    const q = S.q.trim().toLowerCase();
    if (!q) return S.data.items;
    return S.data.items.filter(i => (i.program + ' ' + i.pelatih + ' ' + (i.peserta || '') + ' ' + i.kolam).toLowerCase().includes(q));
}

function cardHtml(i) {
    let cls = i.kategori, title = i.program, sub;
    if (i.kategori === 'private') sub = i.pelatih + (i.peserta ? ' · ' + i.peserta : '');
    else sub = i.pelatih + ' · ' + i.terisi + '/' + i.kapasitas;
    if (i.bentrok) { cls = 'red'; sub += ' · Bentrok'; }
    else if (i.penuh) { cls = 'red'; title += ' · Penuh'; }
    const tip = i.mulai + '–' + i.selesai + ' · ' + i.kolam;
    return '<button type="button" class="jw-ev ' + cls + '" data-id="' + i.id + '" title="' + esc(tip) + '">'
        + '<b>' + esc(title) + '</b><span>' + esc(sub) + '</span></button>';
}

function cellHtml(list) {
    const grup = {};
    list.forEach(i => {
        if (i.bentrok) { (grup[i.pelatih_id] = grup[i.pelatih_id] || []).push(i); }
    });
    const sudah = {};
    let html = '';
    list.forEach(i => {
        if (i.bentrok && grup[i.pelatih_id].length > 1) {
            if (sudah[i.pelatih_id]) return;
            sudah[i.pelatih_id] = true;
            const g = grup[i.pelatih_id];
            html += '<button type="button" class="jw-ev red" data-grup="' + g.map(x => x.id).join(',') + '">'
                + '<b>Bentrok Jadwal</b><span>' + esc(i.pelatih) + ' · ' + g.length + ' kelas</span></button>';
        } else {
            html += cardHtml(i);
        }
    });
    return html;
}

function renderGrid() {
    const items = visibleItems();
    const ag = window.matchMedia('(max-width: 640px)').matches;
    $('jw-grid').classList.toggle('is-agenda', ag);
    if (ag) return renderAgenda(items);
    const days = S.data.days;
    const jamList = Array.from(new Set(items.map(i => i.mulai.slice(0, 2)))).sort();

    let html = '<div class="jw-corner"></div>';
    days.forEach(d => {
        html += '<div class="jw-dayh' + (d.today ? ' is-today' : '') + '"><span>' + d.hari + '</span><small>' + d.no + '</small></div>';
    });

    if (!jamList.length) {
        html += '<div class="jw-empty">' + (S.q || S.kolam || S.pelatih ? 'Tidak ada jadwal yang cocok dengan pencarian / filter.' : 'Belum ada jadwal pada minggu ini.') + '</div>';
    }

    jamList.forEach(h => {
        html += '<div class="jw-time">' + h + ':00</div>';
        days.forEach((d, di) => {
            const cell = items.filter(i => i.hari === di && i.mulai.slice(0, 2) === h);
            html += '<div class="jw-cell' + (d.today ? ' is-today' : '') + '">' + cellHtml(cell) + '</div>';
        });
    });

    $('jw-grid').innerHTML = html;
}

// ============================================================
// MODAL
// ============================================================
function openModal(html) {
    $('jw-modal').innerHTML = html;
    $('jw-overlay').classList.add('is-open');
}
function closeModal() {
    $('jw-overlay').classList.remove('is-open');
    S.force = false;
}

// ── Daftar kelas yang bentrok (kartu gabungan) ──
function openGrup(ids) {
    const list = S.data.items.filter(i => ids.includes(String(i.id)));
    openModal(
        '<div class="jw-modal-top"><div><h2>Bentrok Jadwal</h2><p style="font-size:13px;color:#6b7280;margin-top:4px">' + esc(list[0].pelatih) + ' dijadwalkan di ' + list.length + ' kelas pada waktu yang saling tumpang tindih.</p></div>'
        + '<button class="jw-x" type="button" data-close>×</button></div>'
        + '<div class="jw-glist">' + list.map(i =>
            '<button type="button" class="jw-ev ' + i.kategori + '" data-id="' + i.id + '"><b>' + esc(i.program) + '</b><span>'
            + esc(i.mulai + '–' + i.selesai + ' · ' + i.kolam) + '</span></button>').join('') + '</div>'
    );
}

// ── Detail kelas ──
async function openDetail(id) {
    const [d, m] = await Promise.all([api('detail', { id: id }), api('member_cari', { id: id })]);
    if (!d.ok) { toast(d.pesan || 'Gagal memuat detail.', true); return; }
    const j = d.jadwal;
    const penuh = j.terisi >= j.kapasitas;

    let peserta = d.peserta.length
        ? '<ul class="jw-plist">' + d.peserta.map(p =>
            '<li><span>' + esc(p.nama) + '</span><span class="kode">' + esc(p.kode_member) + '</span>'
            + (j.bisa_ubah ? '<button class="rm" type="button" data-rm="' + p.id + '">Hapus</button>' : '') + '</li>').join('') + '</ul>'
        : '<div class="jw-plist"><div class="jw-pempty">Belum ada peserta.</div></div>';

    let tambah = '';
    if (j.bisa_ubah) {
        if (penuh) tambah = '<p style="font-size:13px;color:#dc2626;margin-top:10px">Kelas sudah penuh.</p>';
        else tambah = '<div class="jw-addrow"><select class="jw-input" id="jw-addm"><option value="">Pilih member…</option>'
            + (m.member || []).map(x => '<option value="' + x.id + '">' + esc(x.nama) + ' (' + esc(x.kode_member) + ')</option>').join('')
            + '</select><button class="jw-btn primary" type="button" id="jw-addm-btn">Tambah</button></div>';
    }

    openModal(
        '<div class="jw-modal-top"><div><h2>' + esc(j.program) + '</h2>'
        + '<span class="jw-tag ' + esc(j.status) + '" style="margin-top:8px">' + esc(STATUS_LABEL[j.status] || j.status) + '</span></div>'
        + '<button class="jw-x" type="button" data-close>×</button></div>'
        + '<dl class="jw-info">'
        + '<dt>Tanggal</dt><dd>' + esc(j.tanggal) + '</dd>'
        + '<dt>Waktu</dt><dd>' + esc(j.mulai + ' – ' + j.selesai) + '</dd>'
        + '<dt>Kolam</dt><dd>' + esc(j.kolam) + '</dd>'
        + '<dt>Pelatih</dt><dd>' + esc(j.pelatih) + '</dd>'
        + '<dt>Terisi</dt><dd>' + j.terisi + ' / ' + j.kapasitas + ' peserta</dd></dl>'
        + '<div class="jw-sec"><h3>Peserta <small>' + j.terisi + '/' + j.kapasitas + '</small></h3>' + peserta + tambah + '</div>'
        + '<div class="jw-foot">'
        + (j.bisa_ubah ? '<button class="jw-btn danger" type="button" id="jw-batal">Batalkan Jadwal</button>' : '')
        + '<button class="jw-btn" type="button" data-close>Tutup</button></div>'
    );

    const modal = $('jw-modal');
    modal.dataset.jid = j.id;

    modal.querySelectorAll('[data-rm]').forEach(b => b.addEventListener('click', async () => {
        const r = await api('peserta_hapus', {}, { jadwal_id: j.id, member_id: Number(b.dataset.rm) });
        toast(r.pesan, !r.ok);
        if (r.ok) { openDetail(j.id); loadWeek(); }
    }));

    const addBtn = $('jw-addm-btn');
    if (addBtn) addBtn.addEventListener('click', async () => {
        const v = $('jw-addm').value;
        if (!v) { toast('Pilih member terlebih dahulu.', true); return; }
        addBtn.disabled = true;
        const r = await api('peserta_tambah', {}, { jadwal_id: j.id, member_id: Number(v) });
        toast(r.pesan, !r.ok);
        if (r.ok) { openDetail(j.id); loadWeek(); } else addBtn.disabled = false;
    });

    const bt = $('jw-batal');
    if (bt) bt.addEventListener('click', async () => {
        if (!bt.classList.contains('confirm')) {          // klik pertama = minta konfirmasi
            bt.classList.add('confirm');
            bt.textContent = 'Yakin batalkan?';
            return;
        }
        bt.disabled = true;
        const r = await api('batal', {}, { id: j.id });
        toast(r.pesan, !r.ok);
        if (r.ok) { closeModal(); loadWeek(); } else bt.disabled = false;
    });
}

// ── Form tambah jadwal ──
async function openTambah() {
    if (!S.opsi) {
        const o = await api('opsi');
        if (!o.ok) { toast(o.pesan || 'Gagal memuat pilihan.', true); return; }
        S.opsi = o;
    }
    const o = S.opsi;
    const today = new Date();
    const p = n => String(n).padStart(2, '0');
    const tglDefault = (S.data && S.data.days.some(d => d.today)) ? today.getFullYear() + '-' + p(today.getMonth() + 1) + '-' + p(today.getDate()) : (S.data ? S.data.start : S.start);
    S.force = false;

    openModal(
        '<div class="jw-modal-top"><h2>Tambah Jadwal</h2><button class="jw-x" type="button" data-close>×</button></div>'
        + '<form id="jw-form" class="jw-form" novalidate>'
        + '<div class="jw-field full"><label>Program</label><select class="jw-input" name="program_id" id="f-program"><option value="">Pilih program…</option>'
            + o.program.map(x => '<option value="' + x.id + '" data-kap="' + x.kapasitas + '">' + esc(x.nama_program) + '</option>').join('') + '</select></div>'
        + '<div class="jw-field full"><label>Pelatih</label><select class="jw-input" name="pelatih_id"><option value="">Pilih pelatih…</option>'
            + o.pelatih.map(x => '<option value="' + x.id + '">' + esc(x.nama) + '</option>').join('') + '</select></div>'
        + '<div class="jw-field full"><label>Tanggal</label><input class="jw-input" type="date" name="tanggal" value="' + tglDefault + '"></div>'
        + '<div class="jw-field"><label>Jam Mulai</label><input class="jw-input" type="time" name="jam_mulai" id="f-mulai"></div>'
        + '<div class="jw-field"><label>Jam Selesai</label><input class="jw-input" type="time" name="jam_selesai" id="f-selesai"></div>'
        + '<div class="jw-field"><label>Kolam</label><input class="jw-input" type="text" name="kolam" maxlength="30" list="f-kolam-list" placeholder="mis. Kolam A">'
            + '<datalist id="f-kolam-list">' + o.kolam.map(x => '<option value="' + esc(x) + '">').join('') + '</datalist></div>'
        + '<div class="jw-field"><label>Kapasitas</label><input class="jw-input" type="number" min="1" name="kapasitas" id="f-kap"></div>'
        + '<div class="jw-warn" id="f-warn"></div>'
        + '</form>'
        + '<div class="jw-foot"><button class="jw-btn" type="button" data-close>Batal</button>'
        + '<button class="jw-btn primary" type="button" id="f-submit">Simpan</button></div>'
    );

    const form = $('jw-form');
    const resetForce = () => { S.force = false; $('f-warn').classList.remove('is-show'); $('f-submit').textContent = 'Simpan'; };
    form.addEventListener('input', resetForce);

    $('f-program').addEventListener('change', e => {
        const opt = e.target.selectedOptions[0];
        if (opt && opt.dataset.kap) $('f-kap').value = opt.dataset.kap;
    });
    $('f-mulai').addEventListener('change', e => {
        const v = e.target.value;
        if (!v) return;
        const sel = $('f-selesai');
        if (!sel.value || sel.value <= v) {
            const [h, m] = v.split(':').map(Number);
            sel.value = p((h + 1) % 24) + ':' + p(m);
        }
    });

    $('f-submit').addEventListener('click', async () => {
        const fd = Object.fromEntries(new FormData(form).entries());
        const btn = $('f-submit');
        const warn = $('f-warn');
        const body = {
            program_id: Number(fd.program_id), pelatih_id: Number(fd.pelatih_id), tanggal: fd.tanggal,
            jam_mulai: fd.jam_mulai, jam_selesai: fd.jam_selesai, kolam: fd.kolam, kapasitas: Number(fd.kapasitas),
            paksa: S.force
        };
        btn.disabled = true;
        const r = await api('tambah', {}, body);
        btn.disabled = false;

        if (r.ok) {
            toast(r.pesan);
            S.start = body.tanggal;
            closeModal();
            loadWeek();
        } else if (r.bentrok) {
            warn.textContent = r.pesan + ' Tetap simpan sebagai jadwal bentrok?';
            warn.classList.add('is-show');
            btn.textContent = 'Tetap Simpan';
            S.force = true;
        } else {
            warn.textContent = r.pesan || 'Gagal menyimpan jadwal.';
            warn.classList.add('is-show');
            S.force = false;
        }
    });
}

// ============================================================
// EVENT
// ============================================================
$('jw-prev').addEventListener('click', () => shiftWeek(-7));
$('jw-next').addEventListener('click', () => shiftWeek(7));
$('jw-today').addEventListener('click', () => {
    const d = new Date(), p = n => String(n).padStart(2, '0');
    S.start = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    loadWeek();
});
$('jw-add').addEventListener('click', openTambah);

$('jw-f-kolam').addEventListener('change', e => { S.kolam = e.target.value; loadWeek(); });
$('jw-f-pelatih').addEventListener('change', e => { S.pelatih = e.target.value; loadWeek(); });
$('jw-q').addEventListener('input', e => { S.q = e.target.value; if (S.data) renderGrid(); });

// Klik kartu di kalender / di dalam modal
document.addEventListener('click', e => {
    const close = e.target.closest('[data-close]');
    if (close) { closeModal(); return; }
    if (e.target === $('jw-overlay')) { closeModal(); return; }

    const ev = e.target.closest('.jw-ev');
    if (!ev) return;
    if (ev.dataset.grup) openGrup(ev.dataset.grup.split(','));
    else if (ev.dataset.id) openDetail(Number(ev.dataset.id));
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

function renderAgenda(items) {
    let html = '';
    S.data.days.forEach((d, di) => {
        const list = items.filter(i => i.hari === di);
        html += '<div class="jw-ag-day' + (d.today ? ' is-today' : '') + '"><h4>' + d.hari + ' <small>' + d.no + (d.today ? ' · Hari ini' : '') + '</small></h4>';
        if (!list.length) html += '<div class="jw-ag-none">Tidak ada kelas</div>';
        list.forEach(i => { html += '<div class="jw-ag-item"><time>' + i.mulai + '<i>' + i.selesai + '</i></time>' + cardHtml(i) + '</div>'; });
        html += '</div>';
    });
    if (!items.length) html = '<div class="jw-empty">' + (S.q || S.kolam || S.pelatih ? 'Tidak ada jadwal yang cocok dengan filter.' : 'Belum ada jadwal pada minggu ini.') + '</div>';
    $('jw-grid').innerHTML = html;
}
(function () {
    const mq = window.matchMedia('(max-width: 640px)'); let was = mq.matches;
    window.addEventListener('resize', () => { if (mq.matches !== was) { was = mq.matches; if (S.data) renderGrid(); } });
})();

loadWeek();
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