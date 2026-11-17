<?php
// ============================================================
// otomasi-pesan.php — AI Assistant / Otomasi Pesan
// Tabel: otomasi_pesan, log_otomasi (+ members, jadwal, jadwal_peserta, paket_member, tagihan, presensi, kelas_program)
// Kolom tambahan (dibuat otomatis): otomasi_pesan.ambang, otomasi_pesan.program_ids, log_otomasi.dibalas
// Catatan: halaman ini MENGANTRIKAN pesan ke log_otomasi (status 'antri'). Pengiriman WhatsApp sebenarnya
// dilakukan oleh gateway/worker Anda, yang mengubah status menjadi 'terkirim' / 'gagal'.
// ============================================================
$trAjax = (($_GET['aksi'] ?? '') !== '');
if ($trAjax) { ob_start(); ini_set('display_errors', '0'); }
else { ini_set('display_errors', '1'); error_reporting(E_ALL); }   // hapus 2 baris ini kalau sudah production
date_default_timezone_set('Asia/Jakarta');

// true = pesan error server ditampilkan apa adanya (membantu debug). Ubah ke false saat production.
const TR_DEBUG = true;
const TR_MASA_BERLAKU = 60;   // hari masa berlaku paket yang dibuat otomatis saat transaksi lunas
const TR_BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const TR_BLN   = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const TR_METODE = ['tunai' => 'Tunai', 'transfer' => 'Transfer', 'qris' => 'QRIS', 'ewallet' => 'E-Wallet'];   // label (data lama tetap terbaca)
const TR_METODE_BARU = ['tunai', 'transfer'];   // metode yang bisa dipilih saat input: pembayaran manual

class TrException extends RuntimeException {}

function trOut(array $data, int $code = 200): void
{
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function trPesanError(Throwable $e): string
{
    return TR_DEBUG ? 'Error server: ' . $e->getMessage() : 'Terjadi kesalahan pada server.';
}

if ($trAjax) {
    // Error fatal tetap dibalas sebagai pesan JSON yang jelas
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level()) ob_end_clean();
            if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['ok' => false, 'pesan' => TR_DEBUG ? 'Error PHP: ' . $e['message'] : 'Terjadi kesalahan pada server.'], JSON_UNESCAPED_UNICODE);
        }
    });
}

if (!is_file(__DIR__ . '/config/koneksi.php')) {
    $msg = 'File config/koneksi.php tidak ditemukan di folder ' . __DIR__;
    if ($trAjax) trOut(['ok' => false, 'pesan' => $msg], 500);
    die('<pre style="padding:24px">' . htmlspecialchars($msg) . '</pre>');
}
require_once __DIR__ . '/config/koneksi.php';   // menghasilkan $pdo
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function trTgl(?string $d): string
{
    if (!$d) return '-';
    $t = strtotime($d);
    return $t ? date('j', $t) . ' ' . TR_BLN[(int)date('n', $t)] . ' ' . date('Y', $t) : '-';
}

function trTglJam(?string $d): string
{
    $t = $d ? strtotime($d) : false;
    return $t ? trTgl($d) . ', ' . date('H:i', $t) : '-';
}

function trAda(PDO $pdo, string $tbl, ?string $col = null, bool $paksa = false): bool
{
    static $cache = [];
    $k = $tbl . '.' . $col;
    if ($paksa) unset($cache[$k]);
    if (!isset($cache[$k])) {
        try { $pdo->query('SELECT ' . ($col ?: '1') . ' FROM ' . $tbl . ' LIMIT 0'); $cache[$k] = true; }
        catch (Throwable $e) { $cache[$k] = false; }
    }
    return $cache[$k];
}


// Pencatat = user yang login (kalau aplikasi Anda memakai $_SESSION['user_id']); selain itu NULL
function trUserId(PDO $pdo): ?int
{
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    if ($id <= 0) return null;
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :i");
    $st->execute([':i' => $id]);
    return $st->fetchColumn() ? $id : null;
}


function trKunci(PDO $pdo): string { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }

// Body request: JSON (fetch) atau form biasa. Aksi yang mengubah data wajib POST + header AJAX.
function trBody(): array
{
    $b = json_decode((string)file_get_contents('php://input'), true);
    return is_array($b) ? $b : $_POST;
}
function trWajibPost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest')
        throw new TrException('Permintaan tidak valid.', 405);
}

// Isi {link} pada pesan Evaluasi Bulanan. Boleh diisi, mis. 'https://domain-anda.com/progres.php?member={id}'
const OT_LINK_PROGRES = '';

function otDef(): array
{
    return [
        'pengingat_h1' => ['judul' => 'Reminder Kelas', 'pemicu' => 'H-{n} Jadwal', 'ambang' => ['label' => 'Dikirim berapa hari sebelum kelas', 'def' => 1, 'min' => 1, 'max' => 14],
            'program' => true, 'manual' => true, 'jendela' => 1,
            'vars' => ['nama', 'program', 'kelas', 'jam', 'kolam', 'tanggal'],
            'tpl' => 'Halo Kak {nama}! Reminder ya, ada kelas {program} besok pukul {jam} di {kolam}. Sampai jumpa di kolam! 🏊‍♂️'],
        'kuota_rendah' => ['judul' => 'Kuota Hampir Habis', 'pemicu' => 'Sisa ≤ {n} Sesi', 'ambang' => ['label' => 'Kirim saat sisa sesi maksimal', 'def' => 2, 'min' => 1, 'max' => 5],
            'program' => true, 'manual' => true, 'jendela' => 14,
            'vars' => ['nama', 'paket', 'program', 'kelas', 'sisa_sesi'],
            'tpl' => 'Kak {nama}, sisa kuota di paket {paket} tinggal {sisa_sesi} sesi nih. Mau sekalian perpanjang agar jadwal tidak terputus? 😊'],
        'tagihan_jatuh_tempo' => ['judul' => 'Tagihan Jatuh Tempo', 'pemicu' => 'H+{n} Jatuh Tempo', 'ambang' => ['label' => 'Dikirim berapa hari setelah jatuh tempo', 'def' => 0, 'min' => 0, 'max' => 30],
            'program' => true, 'manual' => true, 'jendela' => 7,
            'vars' => ['nama', 'nomor_tagihan', 'tanggal', 'program', 'kelas'],
            'tpl' => 'Kak {nama}, tagihan {nomor_tagihan} sudah jatuh tempo pada {tanggal}. Mohon lakukan pembayaran ya. Terima kasih!'],
        'member_nonaktif' => ['judul' => 'Member Tidak Aktif', 'pemicu' => '> {n} Hari Absen', 'ambang' => ['label' => 'Kirim jika tidak hadir lebih dari (hari)', 'def' => 14, 'min' => 7, 'max' => 90],
            'program' => true, 'manual' => true, 'jendela' => 14,
            'vars' => ['nama', 'program', 'kelas', 'hari'],
            'tpl' => 'Halo {nama}, sudah {hari} hari tidak latihan nih. Ada kendala atau mau konsultasi jadwal baru?'],
        'evaluasi_bulanan' => ['judul' => 'Evaluasi Bulanan', 'pemicu' => 'Tiap Tgl {n}', 'ambang' => ['label' => 'Tanggal kirim setiap bulan', 'def' => 30, 'min' => 1, 'max' => 31],
            'program' => true, 'manual' => true, 'jendela' => 25,
            'vars' => ['nama', 'program', 'kelas', 'bulan', 'link'],
            'tpl' => 'Laporan perkembangan {nama} bulan {bulan} sudah keluar. Klik link berikut untuk melihat detail progres: {link}'],
        'konfirmasi_pembayaran' => ['judul' => 'Konfirmasi Pembayaran', 'pemicu' => 'Setelah Input Transaksi', 'ambang' => null,
            'program' => true, 'manual' => false, 'jendela' => 0,
            'vars' => ['nama', 'paket', 'program', 'kelas', 'nomor_tagihan'],
            'tpl' => 'Terima kasih {nama}, pembayaran {paket} telah kami terima. Kuota kamu sudah aktif ya! 🙌'],
        'ulang_tahun' => ['judul' => 'Ucapan Ulang Tahun', 'pemicu' => 'Pada Hari Ulang Tahun', 'ambang' => null,
            'program' => false, 'manual' => true, 'jendela' => 300,
            'vars' => ['nama'],
            'tpl' => 'Selamat ulang tahun, {nama}! 🎉 Semoga sehat selalu dan makin semangat berenang bersama Natix Swim.'],
    ];
}

function otTeksPemicu(string $p, ?int $n): string
{
    $d = otDef()[$p] ?? null;
    return $d ? str_replace('{n}', (string)($n ?? ($d['ambang']['def'] ?? '')), $d['pemicu']) : $p;
}

function otMigrasi(PDO $pdo): array
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    foreach (['otomasi_pesan', 'log_otomasi'] as $t) if (!trAda($pdo, $t)) throw new TrException("Tabel $t belum ada. Jalankan skema database terlebih dulu.", 500);
    $salah = [];
    $kolom = [
        ['otomasi_pesan', 'ambang', 'ALTER TABLE otomasi_pesan ADD COLUMN ambang INT NULL'],
        ['otomasi_pesan', 'program_ids', 'ALTER TABLE otomasi_pesan ADD COLUMN program_ids VARCHAR(255) NULL'],
        ['log_otomasi', 'dibalas', 'ALTER TABLE log_otomasi ADD COLUMN dibalas TINYINT(1) NOT NULL DEFAULT 0'],
    ];
    foreach ($kolom as [$t, $c, $sql]) {
        if (trAda($pdo, $t, $c)) continue;
        try { $pdo->exec($sql); } catch (Throwable $e) { $salah[] = "$t.$c (" . $e->getMessage() . ')'; }
        trAda($pdo, $t, $c, true);
    }
    if ($mysql) {
        try {
            $r = $pdo->query("SHOW COLUMNS FROM otomasi_pesan LIKE 'pemicu'")->fetch(PDO::FETCH_ASSOC);
            $tipe = (string)($r['Type'] ?? '');
            $semua = array_keys(otDef());
            $kurang = false;
            foreach ($semua as $p) if (stripos($tipe, "'$p'") === false) $kurang = true;
            if (stripos($tipe, 'enum(') === 0 && $kurang)
                $pdo->exec("ALTER TABLE otomasi_pesan MODIFY pemicu ENUM('" . implode("','", $semua) . "') NOT NULL");
        } catch (Throwable $e) { $salah[] = 'pemicu (' . $e->getMessage() . ')'; }
    }
    return $salah;
}

// Pemicu yang boleh dipilih (sesuai ENUM kolom pemicu di database)
function otPemicuBoleh(PDO $pdo): array
{
    $semua = array_keys(otDef());
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return $semua;
    try {
        $r = $pdo->query("SHOW COLUMNS FROM otomasi_pesan LIKE 'pemicu'")->fetch(PDO::FETCH_ASSOC);
        $t = (string)($r['Type'] ?? '');
        if (stripos($t, 'enum(') !== 0) return $semua;
        return array_values(array_filter($semua, function ($p) use ($t) { return stripos($t, "'$p'") !== false; }));
    } catch (Throwable $e) { return $semua; }
}

function otIsi(string $tpl, array $v): string
{
    return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($v) { return array_key_exists($m[1], $v) ? (string)$v[$m[1]] : $m[0]; }, $tpl);
}

function otIds(?string $s): array { return array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$s))))); }

function otFilterProg(array $ids, string $kolom): string { return $ids ? " AND $kolom IN (" . implode(',', $ids) . ')' : ''; }

// Penerima untuk sebuah aturan -> [[member_id, nama, no_wa, vars], ...]
function otTarget(PDO $pdo, array $a, int $batas = 1000): array
{
    $def = otDef()[$a['pemicu']] ?? null;
    if (!$def || !$def['manual']) return [];
    $n = $a['ambang'] !== null && $a['ambang'] !== '' ? (int)$a['ambang'] : (int)($def['ambang']['def'] ?? 0);
    $ids = $def['program'] ? otIds($a['program_ids'] ?? '') : [];
    $hariIni = date('Y-m-d');
    $out = [];

    switch ($a['pemicu']) {
        case 'pengingat_h1':
            $sql = "SELECT m.id AS mid, m.nama, m.no_whatsapp, kp.nama_program, j.jam_mulai, j.kolam, j.tanggal
                      FROM jadwal j
                      JOIN jadwal_peserta jp ON jp.jadwal_id = j.id
                      JOIN members m ON m.id = jp.member_id
                      JOIN kelas_program kp ON kp.id = j.program_id
                     WHERE j.tanggal = :t AND j.status = 'akan_datang' AND m.status = 'aktif'" . otFilterProg($ids, 'j.program_id') . ' ORDER BY j.jam_mulai, m.nama';
            $s = $pdo->prepare($sql); $s->execute([':t' => date('Y-m-d', strtotime("+$n day"))]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r)
                $out[] = [(int)$r['mid'], $r['nama'], $r['no_whatsapp'], ['nama' => $r['nama'], 'program' => $r['nama_program'], 'kelas' => $r['nama_program'], 'jam' => str_replace(':', '.', substr($r['jam_mulai'], 0, 5)), 'kolam' => $r['kolam'], 'tanggal' => trTgl($r['tanggal'])]];
            break;

        case 'kuota_rendah':
            $sql = "SELECT m.id AS mid, m.nama, m.no_whatsapp, kp.nama_program, pm.sisa_sesi
                      FROM paket_member pm JOIN members m ON m.id = pm.member_id JOIN kelas_program kp ON kp.id = pm.program_id
                     WHERE pm.status = 'aktif' AND pm.sisa_sesi BETWEEN 1 AND :n AND m.status = 'aktif'" . otFilterProg($ids, 'pm.program_id') . ' ORDER BY pm.sisa_sesi, m.nama';
            $s = $pdo->prepare($sql); $s->execute([':n' => $n]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r)
                $out[] = [(int)$r['mid'], $r['nama'], $r['no_whatsapp'], ['nama' => $r['nama'], 'paket' => $r['nama_program'], 'program' => $r['nama_program'], 'kelas' => $r['nama_program'], 'sisa_sesi' => $r['sisa_sesi']]];
            break;

        case 'tagihan_jatuh_tempo':
            $prog = trAda($pdo, 'tagihan', 'program_id') ? 'COALESCE(t.program_id, pm.program_id)' : 'pm.program_id';
            $sql = "SELECT m.id AS mid, m.nama, m.no_whatsapp, t.nomor_tagihan, t.jatuh_tempo, kp.nama_program
                      FROM tagihan t JOIN members m ON m.id = t.member_id
                      LEFT JOIN paket_member pm ON pm.id = t.paket_id
                      LEFT JOIN kelas_program kp ON kp.id = $prog
                     WHERE t.status <> 'lunas' AND t.jatuh_tempo <= :c AND m.status = 'aktif'" . otFilterProg($ids, $prog) . ' ORDER BY t.jatuh_tempo, m.nama';
            $s = $pdo->prepare($sql); $s->execute([':c' => date('Y-m-d', strtotime("-$n day"))]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r)
                $out[] = [(int)$r['mid'], $r['nama'], $r['no_whatsapp'], ['nama' => $r['nama'], 'nomor_tagihan' => $r['nomor_tagihan'], 'tanggal' => trTgl($r['jatuh_tempo']), 'program' => (string)$r['nama_program'], 'kelas' => (string)$r['nama_program']]];
            break;

        case 'member_nonaktif':
            $sql = "SELECT m.id AS mid, m.nama, m.no_whatsapp, m.tanggal_daftar, kp.nama_program,
                           (SELECT MAX(j.tanggal) FROM presensi pr JOIN jadwal j ON j.id = pr.jadwal_id WHERE pr.member_id = m.id AND pr.status = 'hadir') AS terakhir
                      FROM members m JOIN paket_member pm ON pm.member_id = m.id AND pm.status = 'aktif'
                      JOIN kelas_program kp ON kp.id = pm.program_id
                     WHERE m.status = 'aktif'" . otFilterProg($ids, 'pm.program_id') . ' ORDER BY m.nama';
            $lihat = [];
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset($lihat[$r['mid']])) continue;
                $ref = $r['terakhir'] ?: $r['tanggal_daftar'];
                if (!$ref) continue;
                $hari = (int)floor((strtotime($hariIni) - strtotime($ref)) / 86400);
                if ($hari <= $n) continue;
                $lihat[$r['mid']] = true;
                $out[] = [(int)$r['mid'], $r['nama'], $r['no_whatsapp'], ['nama' => $r['nama'], 'program' => $r['nama_program'], 'kelas' => $r['nama_program'], 'hari' => $hari]];
            }
            break;

        case 'evaluasi_bulanan':
            $a1 = date('Y-m-01'); $b1 = date('Y-m-d', strtotime($a1 . ' +1 month'));
            $sql = "SELECT m.id AS mid, m.nama, m.no_whatsapp, kp.nama_program
                      FROM presensi pr JOIN jadwal j ON j.id = pr.jadwal_id JOIN members m ON m.id = pr.member_id
                      JOIN paket_member pm ON pm.id = pr.paket_id JOIN kelas_program kp ON kp.id = pm.program_id
                     WHERE pr.status = 'hadir' AND j.tanggal >= :a AND j.tanggal < :b AND m.status = 'aktif'" . otFilterProg($ids, 'pm.program_id') . ' ORDER BY m.nama';
            $s = $pdo->prepare($sql); $s->execute([':a' => $a1, ':b' => $b1]);
            $lihat = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset($lihat[$r['mid']])) continue;
                $lihat[$r['mid']] = true;
                $out[] = [(int)$r['mid'], $r['nama'], $r['no_whatsapp'], ['nama' => $r['nama'], 'program' => $r['nama_program'], 'kelas' => $r['nama_program'], 'bulan' => TR_BULAN[(int)date('n')],
                    'link' => str_replace('{id}', (string)$r['mid'], OT_LINK_PROGRES)]];
            }
            break;

        case 'ulang_tahun':
            $s = $pdo->prepare("SELECT id AS mid, nama, no_whatsapp FROM members WHERE status = 'aktif' AND tanggal_lahir IS NOT NULL AND SUBSTR(tanggal_lahir, 6, 5) = :md ORDER BY nama");
            $s->execute([':md' => date('m-d')]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = [(int)$r['mid'], $r['nama'], $r['no_whatsapp'], ['nama' => $r['nama']]];
            break;
    }
    return array_slice($out, 0, $batas);
}

function otAturan(PDO $pdo, int $id): array
{
    $s = $pdo->prepare('SELECT * FROM otomasi_pesan WHERE id = :i'); $s->execute([':i' => $id]);
    $a = $s->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new TrException('Aturan tidak ditemukan (mungkin sudah dihapus).', 404);
    return $a;
}

function otValidasi(PDO $pdo, array $b): array
{
    $def = otDef();
    $pem = (string)($b['pemicu'] ?? '');
    if (!isset($def[$pem])) throw new TrException('Pilih jenis pemicu.', 422);
    if (!in_array($pem, otPemicuBoleh($pdo), true)) throw new TrException('Pemicu ini belum didukung oleh kolom database. Jalankan ALTER TABLE otomasi_pesan MODIFY pemicu ... (lihat peringatan di halaman).', 422);
    $d = $def[$pem];
    $nama = trim(preg_replace('/\s+/u', ' ', (string)($b['nama'] ?? '')));
    if (mb_strlen($nama) < 3) throw new TrException('Nama aturan minimal 3 karakter.', 422);
    if (mb_strlen($nama) > 100) throw new TrException('Nama aturan maksimal 100 karakter.', 422);
    $tpl = trim((string)($b['template'] ?? ''));
    if (mb_strlen($tpl) < 5) throw new TrException('Isi pesan minimal 5 karakter.', 422);
    if (mb_strlen($tpl) > 1000) throw new TrException('Isi pesan maksimal 1.000 karakter.', 422);
    preg_match_all('/\{(\w+)\}/', $tpl, $mm);
    $asing = array_values(array_unique(array_diff($mm[1], $d['vars'])));
    if ($asing) throw new TrException('Variabel tidak dikenal untuk pemicu ini: {' . implode('}, {', $asing) . '}. Tersedia: {' . implode('}, {', $d['vars']) . '}.', 422);
    $ambang = null;
    if ($d['ambang']) {
        $ambang = (int)($b['ambang'] ?? $d['ambang']['def']);
        if ($ambang < $d['ambang']['min'] || $ambang > $d['ambang']['max']) throw new TrException($d['ambang']['label'] . ' harus antara ' . $d['ambang']['min'] . ' dan ' . $d['ambang']['max'] . '.', 422);
    }
    $prog = null;
    if ($d['program']) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['program_ids'] ?? [])))));
        if ($ids) {
            $s = $pdo->query('SELECT id FROM kelas_program WHERE id IN (' . implode(',', $ids) . ')');
            $ada = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
            if (count($ada) !== count($ids)) throw new TrException('Ada program yang tidak ditemukan.', 422);
            $prog = implode(',', $ids);
        }
    }
    $aktif = !empty($b['aktif']) && $b['aktif'] !== 'false' && $b['aktif'] !== '0' ? 1 : 0;
    return [$nama, $pem, $tpl, $ambang, $prog, $aktif];
}

function otRingkas(PDO $pdo, array $a, array $namaProg): array
{
    $def = otDef()[$a['pemicu']] ?? ['judul' => $a['pemicu'], 'pemicu' => $a['pemicu'], 'ambang' => null, 'program' => false, 'manual' => false, 'vars' => []];
    $n = $a['ambang'] !== null && $a['ambang'] !== '' ? (int)$a['ambang'] : null;
    $ids = otIds($a['program_ids'] ?? '');
    return [
        'id' => (int)$a['id'], 'nama' => $a['nama'], 'pemicu' => $a['pemicu'], 'jenis' => $def['judul'], 'teks_pemicu' => otTeksPemicu($a['pemicu'], $n),
        'ambang' => $n ?? ($def['ambang']['def'] ?? null), 'program_ids' => $ids,
        'program' => $def['program'] ? ($ids ? array_values(array_filter(array_map(function ($i) use ($namaProg) { return $namaProg[$i] ?? null; }, $ids))) : ['Semua Program']) : [],
        'template' => $a['template'], 'aktif' => (int)$a['aktif'] === 1, 'manual' => $def['manual'],
    ];
}

// ─── AJAX ───
if ($trAjax) {
    try {
        $aksi = $_GET['aksi'];
        $salahMigrasi = otMigrasi($pdo);
        $def = otDef();

        if ($aksi === 'data') {
            $namaProg = [];
            if (trAda($pdo, 'kelas_program')) foreach ($pdo->query('SELECT id, nama_program FROM kelas_program ORDER BY nama_program')->fetchAll(PDO::FETCH_ASSOC) as $p) $namaProg[(int)$p['id']] = $p['nama_program'];
            $stat = [];
            foreach ($pdo->query("SELECT otomasi_id, status, COUNT(*) n, SUM(dibalas) b FROM log_otomasi GROUP BY otomasi_id, status")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $stat[(int)$r['otomasi_id']][$r['status']] = ['n' => (int)$r['n'], 'b' => (int)$r['b']];
            $rows = [];
            foreach ($pdo->query('SELECT * FROM otomasi_pesan ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $x = otRingkas($pdo, $a, $namaProg);
                $st = $stat[$x['id']] ?? [];
                $x['terkirim'] = $st['terkirim']['n'] ?? 0;
                $x['antri'] = $st['antri']['n'] ?? 0;
                $x['gagal'] = $st['gagal']['n'] ?? 0;
                $x['engagement'] = $x['terkirim'] > 0 ? (int)round(($st['terkirim']['b'] ?? 0) / $x['terkirim'] * 100) : null;
                $rows[] = $x;
            }
            $progList = [];
            foreach ($namaProg as $i => $n) $progList[] = ['id' => $i, 'nama' => $n];
            $defOut = [];
            foreach ($def as $k => $d) $defOut[$k] = ['judul' => $d['judul'], 'pemicu' => $d['pemicu'], 'ambang' => $d['ambang'], 'program' => $d['program'], 'manual' => $d['manual'], 'vars' => $d['vars'], 'tpl' => $d['tpl']];
            $warn = $salahMigrasi ? 'Sebagian migrasi database gagal: ' . implode('; ', $salahMigrasi) : '';
            if (OT_LINK_PROGRES === '') foreach ($rows as $x) if ($x['aktif'] && strpos($x['template'], '{link}') !== false) {
                $warn .= ($warn ? ' ' : '') . 'Aturan "' . $x['nama'] . '" memakai {link}, tetapi OT_LINK_PROGRES di bagian atas otomasi-pesan.php belum diisi, sehingga link akan kosong.'; break;
            }
            trOut(['ok' => true, 'rows' => $rows, 'program' => $progList, 'def' => $defOut, 'boleh' => otPemicuBoleh($pdo), 'peringatan' => $warn]);
        }

        if ($aksi === 'simpan') {
            trWajibPost();
            $b = trBody();
            [$nama, $pem, $tpl, $ambang, $prog, $aktif] = otValidasi($pdo, $b);
            $id = (int)($b['id'] ?? 0);
            if ($id > 0) {
                otAturan($pdo, $id);
                $pdo->prepare('UPDATE otomasi_pesan SET nama=:n, pemicu=:p, template=:t, ambang=:a, program_ids=:g, aktif=:k WHERE id=:i')
                    ->execute([':n' => $nama, ':p' => $pem, ':t' => $tpl, ':a' => $ambang, ':g' => $prog, ':k' => $aktif, ':i' => $id]);
                trOut(['ok' => true, 'pesan' => 'Aturan pesan berhasil diperbarui.', 'id' => $id]);
            }
            $pdo->prepare('INSERT INTO otomasi_pesan (nama, pemicu, template, ambang, program_ids, aktif) VALUES (:n,:p,:t,:a,:g,:k)')
                ->execute([':n' => $nama, ':p' => $pem, ':t' => $tpl, ':a' => $ambang, ':g' => $prog, ':k' => $aktif]);
            trOut(['ok' => true, 'pesan' => 'Aturan pesan baru berhasil dibuat.', 'id' => (int)$pdo->lastInsertId()]);
        }

        if ($aksi === 'aktif') {
            trWajibPost();
            $b = trBody();
            $id = (int)($b['id'] ?? 0); $on = !empty($b['aktif']) && $b['aktif'] !== 'false' ? 1 : 0;
            otAturan($pdo, $id);
            $pdo->prepare('UPDATE otomasi_pesan SET aktif = :k WHERE id = :i')->execute([':k' => $on, ':i' => $id]);
            trOut(['ok' => true, 'pesan' => $on ? 'Aturan diaktifkan. Pesan otomatis akan diantrikan sesuai pemicu.' : 'Aturan dinonaktifkan. Tidak ada pesan baru yang diantrikan.']);
        }

        if ($aksi === 'hapus') {
            trWajibPost();
            $id = (int)(trBody()['id'] ?? 0);
            otAturan($pdo, $id);
            $pdo->beginTransaction();
            $c = $pdo->prepare('SELECT COUNT(*) FROM log_otomasi WHERE otomasi_id = :i'); $c->execute([':i' => $id]);
            $nLog = (int)$c->fetchColumn();
            $pdo->prepare('DELETE FROM log_otomasi WHERE otomasi_id = :i')->execute([':i' => $id]);
            $pdo->prepare('DELETE FROM otomasi_pesan WHERE id = :i')->execute([':i' => $id]);
            $pdo->commit();
            trOut(['ok' => true, 'pesan' => 'Aturan pesan berhasil dihapus' . ($nLog ? " beserta $nLog riwayat pesannya." : '.')]);
        }

        if ($aksi === 'log_jumlah') {   // dipakai konfirmasi hapus
            $c = $pdo->prepare('SELECT COUNT(*) FROM log_otomasi WHERE otomasi_id = :i'); $c->execute([':i' => (int)($_GET['id'] ?? 0)]);
            trOut(['ok' => true, 'jumlah' => (int)$c->fetchColumn()]);
        }

        if ($aksi === 'target' || $aksi === 'jalankan') {
            $post = $aksi === 'jalankan';
            if ($post) trWajibPost();
            $id = (int)($post ? (trBody()['id'] ?? 0) : ($_GET['id'] ?? 0));
            $a = otAturan($pdo, $id);
            $d = $def[$a['pemicu']] ?? null;
            if (!$d || !$d['manual']) throw new TrException('Aturan ini berjalan otomatis saat transaksi dicatat, tidak bisa dijalankan manual.', 422);
            if ($post && (int)$a['aktif'] !== 1) throw new TrException('Aktifkan aturan terlebih dulu sebelum menjalankannya.', 409);
            $target = otTarget($pdo, $a);
            $since = date('Y-m-d H:i:s', strtotime('-' . max(1, $d['jendela']) . ' day'));
            $cek = $pdo->prepare('SELECT COUNT(*) FROM log_otomasi WHERE otomasi_id = :o AND member_id = :m AND isi_pesan = :i AND waktu >= :w');
            $baru = []; $lewat = 0;
            foreach ($target as [$mid, $nama, $wa, $vars]) {
                $isi = otIsi($a['template'], $vars);
                $cek->execute([':o' => $id, ':m' => $mid, ':i' => $isi, ':w' => $since]);
                if ((int)$cek->fetchColumn() > 0) { $lewat++; continue; }
                $baru[] = [$mid, $nama, $wa, $isi];
            }
            if ($post) {
                if (!$baru) throw new TrException('Tidak ada penerima baru untuk diantrikan.', 409);
                $pdo->beginTransaction();
                $ins = $pdo->prepare("INSERT INTO log_otomasi (otomasi_id, member_id, isi_pesan, status) VALUES (:o,:m,:i,'antri')");
                foreach ($baru as [$mid, $nama, $wa, $isi]) $ins->execute([':o' => $id, ':m' => $mid, ':i' => $isi]);
                $pdo->commit();
                trOut(['ok' => true, 'diantrikan' => count($baru), 'dilewati' => $lewat, 'pesan' => count($baru) . ' pesan masuk antrean pengiriman.']);
            }
            trOut(['ok' => true, 'baru' => count($baru), 'dilewati' => $lewat,
                   'contoh' => array_map(function ($r) { return ['nama' => $r[1], 'wa' => $r[2], 'isi' => $r[3]]; }, array_slice($baru, 0, 3))]);
        }

        trOut(['ok' => false, 'pesan' => 'Aksi tidak dikenal.'], 400);
    } catch (TrException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        trOut(['ok' => false, 'pesan' => $e->getMessage()], $e->getCode() >= 400 ? $e->getCode() : 400);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        trOut(['ok' => false, 'pesan' => trPesanError($e)], 500);
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
            ]],
            ['Administrasi', [
                ['Transaksi', 'administrasi.php', 'card', ['administrasi-detail.php'], false],
                ['Laporan Keuangan', 'administrasi-laporan.php', 'chart', [], false],
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
    <title>Otomasi Pesan — Natix Swim</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/reset.css" />
    <link rel="stylesheet" href="css/global.css" />
    <link rel="stylesheet" href="css/manajemen-ai2.css" />
    <style>
        /* Semua class halaman ini memakai prefix (ad-/tr-/dv-/lp-) supaya tidak bentrok dengan CSS export Figma */
        .main, .col { height: auto !important; min-height: 100vh; }
        .ad-topbar, .ad-topbar *, .ad-page, .ad-page *, .ad-modal-bg *, .ad-toast { box-sizing: border-box; font-family: 'Inter', sans-serif; }
        .ad-page p, .ad-page h1, .ad-page h2, .ad-page h3 { margin: 0; }

        .ad-topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 32px; background: #fff; border-bottom: 1px solid #eef0f3; }
        .ad-search { display: flex; align-items: center; gap: 10px; background: #f6f7fb; border-radius: 12px; padding: 0 16px; height: 44px; width: min(420px, 100%); color: #9ca3af; margin: 0; }
        .ad-search input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font-size: 14px; color: #111827; }
        .ad-search input::placeholder { color: #9ca3af; }
        .ad-avatar { width: 40px; height: 40px; border-radius: 50%; background: #eef2ff; color: #2563eb; font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; }

        .ad-page { padding: 28px 32px 48px; color: #111827; width: 100%; }
        .ad-crumb { font-size: 14px; color: #9ca3af; }
        .ad-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-top: 6px; }
        .ad-title { font-size: 30px; font-weight: 700; line-height: 1.2; }
        .ad-sub { font-size: 15px; color: #6b7280; margin-top: 4px !important; }
        .ad-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

        .ad-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; white-space: nowrap; font-size: 14px; font-weight: 600; border-radius: 12px; padding: 11px 18px; cursor: pointer; border: 1px solid #e5e7eb; background: #fff; color: #111827; text-decoration: none; transition: background .15s; }
        .ad-btn:hover { background: #f9fafb; }
        .ad-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        .ad-btn.primary:hover { background: #1d4ed8; }
        .ad-btn.danger { color: #dc2626; border-color: #fecaca; }
        .ad-btn.danger:hover { background: #fef2f2; }
        .ad-btn.green { background: #16a34a; border-color: #16a34a; color: #fff; }
        .ad-btn.green:hover { background: #15803d; }
        .ad-btn:disabled { opacity: .5; cursor: not-allowed; }

        .ad-card { background: #fff; border: 1px solid #eceef2; border-radius: 16px; min-width: 0; }
        .ad-card-pad { padding: 22px 24px; }
        .ad-card-title { font-size: 17px; font-weight: 700; }
        .ad-card-sub { font-size: 13px; color: #9ca3af; margin-top: 2px !important; }
        .ad-select { appearance: none; -webkit-appearance: none; background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%236b7280' stroke-width='1.6' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 14px center; border: 1px solid #e5e7eb; border-radius: 12px; padding: 10px 36px 10px 16px; font-size: 14px; color: #374151; cursor: pointer; max-width: 240px; }
        .ad-empty { padding: 40px 16px; text-align: center; color: #9ca3af; font-size: 14px; }
        .ad-av { width: 36px; height: 36px; border-radius: 50%; background: #eff1f4; color: #1f2937; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .ad-chip { display: inline-block; font-size: 12px; font-weight: 600; padding: 4px 11px; border-radius: 999px; white-space: nowrap; }
        .ad-chip.lunas { background: #dcfce7; color: #16a34a; }
        .ad-chip.menunggu { background: #fef3c7; color: #b45309; }
        .ad-chip.belum { background: #f3f4f6; color: #6b7280; }
        .ad-chip.tempo { background: #fee2e2; color: #dc2626; }
        .ad-chip.sebagian { background: #dbeafe; color: #2563eb; }

        .ad-error { margin: 24px 32px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 12px; padding: 16px 20px; font-size: 14px; line-height: 1.5; }

        /* Modal */
        .ad-modal-bg { position: fixed; inset: 0; background: rgba(17,24,39,.5); display: none; align-items: center; justify-content: center; z-index: 1500; padding: 16px; }
        .ad-modal-bg.show { display: flex; }
        .ad-modal { background: #fff; border-radius: 18px; width: 100%; max-width: 560px; max-height: 92vh; overflow: auto; padding: 26px 28px; }
        .ad-modal h3 { font-size: 20px; font-weight: 700; }
        .ad-modal .lead { font-size: 13px; color: #6b7280; margin-top: 4px !important; }
        .ad-field { margin-top: 16px; }
        .ad-field label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .ad-field input, .ad-field select, .ad-field textarea { width: 100%; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; font-size: 14px; color: #111827; background: #fff; }
        .ad-field input:focus, .ad-field select:focus, .ad-field textarea:focus { outline: 2px solid #bfdbfe; border-color: #93c5fd; }
        .ad-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .ad-modal-foot { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; }
        .ad-hint { font-size: 12px; color: #9ca3af; margin-top: 5px !important; }
        .ad-msg { display: none; margin-top: 14px; padding: 10px 12px; border-radius: 10px; background: #fef2f2; color: #b91c1c; font-size: 13px; }
        .ad-msg.show { display: block; }

        .ad-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(20px); background: #111827; color: #fff; padding: 12px 20px; border-radius: 12px; font-size: 14px; opacity: 0; pointer-events: none; transition: .25s; z-index: 2000; max-width: 90vw; }
        .ad-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .ad-toast.err { background: #dc2626; }

        @media (max-width: 640px) { .ad-page { padding: 20px 16px 40px; } .ad-topbar { padding: 14px 16px; } .ad-row2 { grid-template-columns: 1fr; } }
        @media print { .navbar-a, .ad-topbar, .ad-actions, .ad-noprint { display: none !important; } .ad-page { padding: 0; } }

        /* Popup konfirmasi / berhasil / gagal */
        .ad-btn.red { background: #dc2626; border-color: #dc2626; color: #fff; }
        .ad-btn.red:hover { background: #b91c1c; }
        .ad-dlg-bg { position: fixed; inset: 0; background: rgba(17,24,39,.55); display: none; align-items: center; justify-content: center; z-index: 3000; padding: 16px; }
        .ad-dlg-bg.show { display: flex; }
        .ad-dlg-bg, .ad-dlg-bg * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
        .ad-dlg { background: #fff; border-radius: 18px; width: 100%; max-width: 400px; padding: 28px 26px 22px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,.25); animation: ad-pop .18s ease-out; }
        @keyframes ad-pop { from { transform: scale(.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .ad-dlg-ico { width: 60px; height: 60px; border-radius: 50%; margin: 0 auto 14px; display: flex; align-items: center; justify-content: center; }
        .ad-dlg-ico.tanya { background: #eff4ff; color: #2563eb; } .ad-dlg-ico.sukses { background: #dcfce7; color: #16a34a; }
        .ad-dlg-ico.bahaya, .ad-dlg-ico.gagal { background: #fee2e2; color: #dc2626; }
        .ad-dlg-t { font-size: 18px; font-weight: 700; color: #111827; margin: 0; }
        .ad-dlg-m { font-size: 14px; color: #6b7280; line-height: 1.55; margin: 8px 0 0; }
        .ad-dlg-r { margin-top: 16px; background: #f9fafb; border-radius: 12px; padding: 8px 14px; text-align: left; }
        .ad-dlg-r div { display: flex; justify-content: space-between; gap: 14px; font-size: 13px; padding: 6px 0; border-bottom: 1px solid #f1f2f5; }
        .ad-dlg-r div:last-child { border-bottom: 0; }
        .ad-dlg-r span { color: #9ca3af; flex: 0 0 auto; } .ad-dlg-r b { color: #111827; text-align: right; word-break: break-word; }
        .ad-dlg-f { display: flex; gap: 10px; margin-top: 22px; }
        .ad-dlg-f .ad-btn { flex: 1; }

        .ot-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; margin-top: 24px; }
        .ot-card { background: #fff; border: 1px solid #eceef2; border-radius: 16px; padding: 22px; display: flex; flex-direction: column; gap: 14px; min-width: 0; }
        .ot-card.off { background: #fcfcfd; }
        .ot-card.off .ot-tpl, .ot-card.off .ot-nm { opacity: .7; }
        .ot-top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .ot-nm { font-size: 17px; font-weight: 700; color: #111827; margin: 0; }
        .ot-pm { font-size: 13px; color: #9ca3af; margin: 3px 0 0; }
        .ot-sw { flex: 0 0 auto; width: 44px; height: 24px; border-radius: 99px; border: 0; background: #d1d5db; position: relative; cursor: pointer; padding: 0; transition: background .15s; }
        .ot-sw i { position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: left .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
        .ot-sw.on { background: #2563eb; } .ot-sw.on i { left: 23px; }
        .ot-chips { display: flex; gap: 8px; flex-wrap: wrap; }
        .ot-chip { font-size: 12px; font-weight: 600; padding: 5px 12px; border-radius: 999px; background: #eff4ff; color: #2563eb; }
        .ot-chip.all { background: #f3f4f6; color: #6b7280; }
        .ot-tpl { background: #f6f7fb; border-radius: 12px; padding: 14px 16px; font-size: 14px; line-height: 1.75; color: #374151; flex: 1; white-space: pre-wrap; word-break: break-word; }
        .ot-v { display: inline-block; background: #dbeafe; color: #1d4ed8; font-size: 12px; font-weight: 600; padding: 1px 9px; border-radius: 7px; line-height: 1.5; }
        .ot-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; border-top: 1px solid #f1f2f5; padding-top: 12px; font-size: 13px; color: #6b7280; }
        .ot-foot b { color: #111827; }
        .ot-acts { display: flex; gap: 6px; }
        .ot-ib { width: 32px; height: 32px; border: 1px solid #e5e7eb; background: #fff; border-radius: 9px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #4b5563; padding: 0; }
        .ot-ib:hover { background: #f3f4f6; } .ot-ib.del { color: #dc2626; border-color: #fecaca; } .ot-ib.del:hover { background: #fef2f2; }
        .ot-ib:disabled { opacity: .35; cursor: not-allowed; }
        .ot-empty { grid-column: 1 / -1; text-align: center; color: #9ca3af; padding: 56px 16px; background: #fff; border: 1px dashed #d1d5db; border-radius: 16px; font-size: 14px; }
        .ot-warn { margin-top: 16px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 12px; padding: 12px 16px; font-size: 13px; display: none; }
        .ot-vars { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .ot-vars button { border: 0; cursor: pointer; font: inherit; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 7px; background: #dbeafe; color: #1d4ed8; }
        .ot-vars button:hover { background: #bfdbfe; }
        .ot-prog { display: flex; gap: 8px; flex-wrap: wrap; }
        .ot-prog label { cursor: pointer; }
        .ot-prog input { display: none; }
        .ot-prog span { display: inline-block; font-size: 13px; font-weight: 600; padding: 6px 13px; border-radius: 999px; border: 1px solid #e5e7eb; color: #4b5563; background: #fff; }
        .ot-prog input:checked + span { background: #2563eb; border-color: #2563eb; color: #fff; }
        .ot-prev { margin-top: 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 12px 14px; font-size: 13px; line-height: 1.6; color: #14532d; white-space: pre-wrap; word-break: break-word; }
        .ot-prev small { display: block; color: #4d7c5f; font-weight: 600; margin-bottom: 2px; }
        .ot-aktifrow { display: flex; align-items: center; gap: 12px; margin-top: 16px; font-size: 14px; color: #374151; }
        @media (max-width: 1280px) { .ot-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 760px) { .ot-grid { grid-template-columns: 1fr; } }
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
        <header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div><a class="nv-pf" href="manajemen-ai-chat.php">Buka Chat Log</a></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div><input type="hidden" id="ot-q" value=""></header>

        <div class="ad-page">
            <?= nvCrumb() ?>
            <div class="ad-head">
                <div>
                    <h1 class="ad-title">Otomasi Pesan</h1>
                    <p class="ad-sub">Atur pengiriman pesan otomatis (reminder) kepada member melalui WhatsApp</p>
                </div>
                <div class="ad-actions">
                    <button type="button" class="ad-btn primary" id="ot-baru">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        Buat Aturan Baru
                    </button>
                </div>
            </div>
            <div class="ot-warn" id="ot-warn"></div>
            <div class="ot-grid" id="ot-grid"><div class="ot-empty">Memuat...</div></div>
        </div>
    </div>

    <!-- Form aturan -->
    <div class="ad-modal-bg" id="ot-m">
        <div class="ad-modal" style="max-width:620px">
            <h3 id="ot-m-judul">Buat Aturan Baru</h3>
            <p class="lead">Pesan akan diantrikan otomatis ke member sesuai pemicu yang dipilih.</p>
            <div class="ad-row2">
                <div class="ad-field"><label for="ot-f-pemicu">Jenis Pemicu</label><select id="ot-f-pemicu"></select></div>
                <div class="ad-field" id="ot-w-ambang"><label for="ot-f-ambang" id="ot-l-ambang">Parameter</label><input id="ot-f-ambang" type="number" min="0" max="99"></div>
            </div>
            <div class="ad-field"><label for="ot-f-nama">Nama Aturan</label><input id="ot-f-nama" type="text" maxlength="100" placeholder="Contoh: Reminder Kelas"></div>
            <div class="ad-field" id="ot-w-prog"><label>Berlaku untuk Program</label>
                <div class="ot-prog" id="ot-f-prog"></div><p class="ad-hint">Kosongkan semua untuk berlaku di semua program.</p></div>
            <div class="ad-field"><label for="ot-f-tpl">Isi Pesan</label>
                <textarea id="ot-f-tpl" rows="5" maxlength="1000"></textarea>
                <div class="ot-vars" id="ot-vars"></div>
                <div class="ot-prev"><small>Pratinjau (contoh data)</small><span id="ot-prev"></span></div></div>
            <div class="ot-aktifrow"><button type="button" class="ot-sw" id="ot-f-aktif"><i></i></button><span id="ot-t-aktif">Aturan aktif</span></div>
            <div class="ad-msg" id="ot-err"></div>
            <div class="ad-modal-foot">
                <button type="button" class="ad-btn" id="ot-batal">Batal</button>
                <button type="button" class="ad-btn primary" id="ot-simpan">Simpan</button>
            </div>
        </div>
    </div>

<script>
var DATA = [], DEF = {}, PROG = [], BOLEH = [], EDIT = 0, AWAL = '', Q = '', SUDAH_EDIT = { nama: false, tpl: false };
var $ = function (id) { return document.getElementById(id); };
var IKON = {
    kirim: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>',
    pensil: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4"/></svg>',
    sampah: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>'
};
var CONTOH = { nama: 'Dinda', program: 'Group Kids', kelas: 'Group Kids', paket: 'Group Kids', jam: '16.00', kolam: 'Kolam A', tanggal: '18 Nov 2026', sisa_sesi: '2', nomor_tagihan: 'INV-2611-001', hari: '14', bulan: 'November', link: 'https://natix.id/progres/123' };
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function pot(s, n) { s = String(s); return s.length > n ? s.slice(0, n - 1) + '…' : s; }
function kartuTpl(t) { return esc(t).replace(/\{(\w+)\}/g, '<span class="ot-v">$1</span>'); }
function isiContoh(t) { return String(t).replace(/\{(\w+)\}/g, function (m, k) { return CONTOH.hasOwnProperty(k) ? CONTOH[k] : m; }); }

// ===== POPUP (konfirmasi / berhasil / gagal) =====
var Dlg = (function () {
    var bg, e = {}, sel = null;
    var IKON = {
        tanya: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 114 2c-.9.6-1.5 1.1-1.5 2M12 17.5v.01"/></svg>',
        sukses: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
        bahaya: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3L2 20h20L12 3zM12 10v4M12 17.5v.01"/></svg>',
        gagal: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg>'
    };
    function bangun() {
        if (bg) return;
        bg = document.createElement('div'); bg.className = 'ad-dlg-bg';
        bg.innerHTML = '<div class="ad-dlg" role="dialog" aria-modal="true"><div class="ad-dlg-ico"></div><h3 class="ad-dlg-t"></h3><p class="ad-dlg-m"></p><div class="ad-dlg-r"></div><div class="ad-dlg-f"></div></div>';
        document.body.appendChild(bg);
        e = { ico: bg.querySelector('.ad-dlg-ico'), t: bg.querySelector('.ad-dlg-t'), m: bg.querySelector('.ad-dlg-m'), r: bg.querySelector('.ad-dlg-r'), f: bg.querySelector('.ad-dlg-f') };
        bg.addEventListener('mousedown', function (ev) { if (ev.target === bg && e.f.children.length > 1) tutup(false); });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && bg.classList.contains('show')) tutup(false); });
    }
    function tutup(v) {
        bg.classList.remove('show');
        var f = sel; sel = null; if (f) f(v);
    }
    function tampil(o) {
        bangun();
        if (sel) tutup(false);
        return new Promise(function (res) {
            sel = res;
            e.ico.className = 'ad-dlg-ico ' + o.tipe; e.ico.innerHTML = IKON[o.tipe];
            e.t.textContent = o.judul; e.m.textContent = o.pesan || ''; e.m.style.display = o.pesan ? '' : 'none';
            e.r.innerHTML = '';
            (o.rincian || []).forEach(function (r) {
                var d = document.createElement('div'), a = document.createElement('span'), b = document.createElement('b');
                a.textContent = r[0]; b.textContent = r[1]; d.appendChild(a); d.appendChild(b); e.r.appendChild(d);
            });
            e.r.style.display = (o.rincian && o.rincian.length) ? '' : 'none';
            e.f.innerHTML = '';
            var ya = document.createElement('button'); ya.type = 'button';
            ya.className = 'ad-btn ' + (o.tipe === 'bahaya' ? 'red' : (o.tipe === 'gagal' ? '' : (o.tipe === 'sukses' ? 'green' : 'primary')));
            ya.textContent = o.ya; ya.addEventListener('click', function () { tutup(true); });
            if (o.tidak) {
                var tdk = document.createElement('button'); tdk.type = 'button'; tdk.className = 'ad-btn'; tdk.textContent = o.tidak;
                tdk.addEventListener('click', function () { tutup(false); }); e.f.appendChild(tdk);
            }
            e.f.appendChild(ya);
            bg.classList.add('show'); ya.focus();
        });
    }
    return {
        konfirmasi: function (judul, pesan, opt) { opt = opt || {}; return tampil({ tipe: opt.tipe || 'tanya', judul: judul, pesan: pesan, rincian: opt.rincian, ya: opt.ya || 'Ya, lanjutkan', tidak: opt.tidak || 'Batal' }); },
        sukses: function (judul, pesan, opt) { opt = opt || {}; return tampil({ tipe: 'sukses', judul: judul, pesan: pesan, rincian: opt.rincian, ya: opt.ya || 'OK' }); },
        info: function (judul, pesan) { return tampil({ tipe: 'tanya', judul: judul, pesan: pesan, ya: 'OK' }); },
        gagal: function (judul, pesan) { return tampil({ tipe: 'gagal', judul: judul, pesan: pesan, ya: 'Tutup' }); }
    };
})();

function api(aksi, qs, body) {
    var opt = { method: body ? 'POST' : 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    return fetch('?aksi=' + aksi + (qs ? '&' + qs : ''), opt).then(function (r) {
        return r.text().then(function (t) {
            var j;
            try { j = JSON.parse(t); } catch (e) { console.error('Respons server bukan JSON:', t); throw new Error('Respons server tidak valid. Buka Console (F12) untuk detailnya.'); }
            if (!j.ok) throw new Error(j.pesan || 'Terjadi kesalahan.');
            return j;
        });
    });
}

function muat() {
    return api('data').then(function (j) {
        DATA = j.rows; DEF = j.def; PROG = j.program; BOLEH = j.boleh;
        var w = $('ot-warn'); w.style.display = j.peringatan ? 'block' : 'none'; w.textContent = j.peringatan;
        gambar();
    }).catch(function (e) { $('ot-grid').innerHTML = '<div class="ot-empty" style="color:#b91c1c">' + esc(e.message) + '</div>'; });
}

function gambar() {
    var q = Q.toLowerCase();
    var list = DATA.filter(function (r) { return !q || (r.nama + ' ' + r.jenis + ' ' + r.template).toLowerCase().indexOf(q) >= 0; });
    if (!list.length) {
        $('ot-grid').innerHTML = '<div class="ot-empty">' + (DATA.length ? 'Tidak ada aturan yang cocok dengan pencarian.' : 'Belum ada aturan pesan. Klik "Buat Aturan Baru" untuk memulai.') + '</div>';
        return;
    }
    $('ot-grid').innerHTML = list.map(function (r) {
        var chips = r.program.map(function (p) { return '<span class="ot-chip' + (r.program_ids.length ? '' : ' all') + '">' + esc(p) + '</span>'; }).join('');
        var eng = r.aktif ? (r.terkirim > 0 ? 'Engagement: ' + r.engagement + '%' : 'Engagement: –') : 'Status: Nonaktif';
        return '<div class="ot-card' + (r.aktif ? '' : ' off') + '" data-id="' + r.id + '">'
            + '<div class="ot-top"><div><p class="ot-nm">' + esc(r.nama) + '</p><p class="ot-pm">Pemicu: ' + esc(r.teks_pemicu) + '</p></div>'
            + '<button type="button" class="ot-sw' + (r.aktif ? ' on' : '') + '" data-a="aktif" title="' + (r.aktif ? 'Nonaktifkan' : 'Aktifkan') + '"><i></i></button></div>'
            + (chips ? '<div class="ot-chips">' + chips + '</div>' : '')
            + '<div class="ot-tpl">' + kartuTpl(r.template) + '</div>'
            + '<div class="ot-foot"><div>Total terkirim: <b>' + r.terkirim + '</b>' + (r.antri ? ' · <span title="Menunggu dikirim oleh gateway WhatsApp">' + r.antri + ' antri</span>' : '') + '<br>' + eng + '</div>'
            + '<div class="ot-acts"><button type="button" class="ot-ib" data-a="jalan" title="' + (r.manual ? 'Jalankan sekarang' : 'Berjalan otomatis saat transaksi dicatat') + '"' + (r.manual ? '' : ' disabled') + '>' + IKON.kirim + '</button>'
            + '<button type="button" class="ot-ib" data-a="ubah" title="Ubah">' + IKON.pensil + '</button>'
            + '<button type="button" class="ot-ib del" data-a="hapus" title="Hapus">' + IKON.sampah + '</button></div></div></div>';
    }).join('');
}
function ambil(id) { return DATA.filter(function (x) { return x.id === id; })[0]; }

var tmr; $('ot-q').addEventListener('input', function () { var v = this.value.trim(); clearTimeout(tmr); tmr = setTimeout(function () { Q = v; gambar(); }, 200); });

// ----- form -----
function nilai() {
    var ids = []; document.querySelectorAll('#ot-f-prog input:checked').forEach(function (c) { ids.push(parseInt(c.value, 10)); });
    return { id: EDIT, pemicu: $('ot-f-pemicu').value, nama: $('ot-f-nama').value.trim(), ambang: $('ot-f-ambang').value === '' ? null : parseInt($('ot-f-ambang').value, 10),
             program_ids: ids, template: $('ot-f-tpl').value.trim(), aktif: $('ot-f-aktif').classList.contains('on') };
}
function kotor() { return JSON.stringify(nilai()) !== AWAL; }
function galat(t) { $('ot-err').textContent = t || ''; $('ot-err').classList.toggle('show', !!t); }
function setAktif(on) { $('ot-f-aktif').classList.toggle('on', on); $('ot-t-aktif').textContent = on ? 'Aturan aktif' : 'Aturan nonaktif'; }
function pratinjau() { $('ot-prev').textContent = isiContoh($('ot-f-tpl').value) || '–'; }
function sesuaikan(isiBaru) {   // ubah tampilan sesuai jenis pemicu
    var d = DEF[$('ot-f-pemicu').value]; if (!d) return;
    $('ot-w-ambang').style.display = d.ambang ? '' : 'none';
    if (d.ambang) { $('ot-l-ambang').textContent = d.ambang.label; $('ot-f-ambang').min = d.ambang.min; $('ot-f-ambang').max = d.ambang.max; if (isiBaru) $('ot-f-ambang').value = d.ambang.def; }
    $('ot-w-prog').style.display = d.program ? '' : 'none';
    $('ot-vars').innerHTML = d.vars.map(function (v) { return '<button type="button" data-v="' + v + '">{' + v + '}</button>'; }).join('');
    if (isiBaru) {
        if (!SUDAH_EDIT.nama) $('ot-f-nama').value = d.judul;
        if (!SUDAH_EDIT.tpl) $('ot-f-tpl').value = d.tpl;
    }
    pratinjau();
}
function bukaForm(r) {
    EDIT = r ? r.id : 0; SUDAH_EDIT = { nama: !!r, tpl: !!r };
    $('ot-m-judul').textContent = r ? 'Ubah Aturan Pesan' : 'Buat Aturan Baru';
    $('ot-f-pemicu').innerHTML = Object.keys(DEF).filter(function (k) { return BOLEH.indexOf(k) >= 0 || (r && r.pemicu === k); })
        .map(function (k) { return '<option value="' + k + '">' + esc(DEF[k].judul) + '</option>'; }).join('');
    $('ot-f-pemicu').value = r ? r.pemicu : $('ot-f-pemicu').options[0].value;
    $('ot-f-pemicu').disabled = !!r;
    $('ot-f-prog').innerHTML = PROG.map(function (p) { return '<label><input type="checkbox" value="' + p.id + '"' + (r && r.program_ids.indexOf(p.id) >= 0 ? ' checked' : '') + '><span>' + esc(p.nama) + '</span></label>'; }).join('')
        || '<span class="ad-hint">Belum ada program.</span>';
    sesuaikan(!r);
    if (r) { $('ot-f-nama').value = r.nama; $('ot-f-tpl').value = r.template; if (DEF[r.pemicu].ambang) $('ot-f-ambang').value = r.ambang; pratinjau(); }
    setAktif(r ? r.aktif : true); galat(''); $('ot-simpan').disabled = false;
    AWAL = JSON.stringify(nilai());
    $('ot-m').classList.add('show'); $('ot-f-nama').focus();
}
function tutupForm(paksa) {
    if (!$('ot-m').classList.contains('show')) return Promise.resolve();
    if (paksa || !kotor()) { $('ot-m').classList.remove('show'); return Promise.resolve(); }
    return Dlg.konfirmasi('Batalkan perubahan?', 'Data yang sudah diisi belum disimpan dan akan hilang.', { ya: 'Ya, batalkan', tidak: 'Lanjut mengisi', tipe: 'bahaya' })
        .then(function (y) { if (y) $('ot-m').classList.remove('show'); });
}
$('ot-baru').addEventListener('click', function () { if (!Object.keys(DEF).length) return Dlg.gagal('Belum siap', 'Data belum termuat. Muat ulang halaman.'); bukaForm(null); });
$('ot-batal').addEventListener('click', function () { tutupForm(false); });
$('ot-m').addEventListener('mousedown', function (ev) { if (ev.target === this) tutupForm(false); });
document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !document.querySelector('.ad-dlg-bg.show')) tutupForm(false); });
$('ot-f-pemicu').addEventListener('change', function () { sesuaikan(true); });
$('ot-f-nama').addEventListener('input', function () { SUDAH_EDIT.nama = true; });
$('ot-f-tpl').addEventListener('input', function () { SUDAH_EDIT.tpl = true; pratinjau(); });
$('ot-f-aktif').addEventListener('click', function () { setAktif(!this.classList.contains('on')); });
$('ot-vars').addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-v]'); if (!b) return;
    var t = $('ot-f-tpl'), a = t.selectionStart == null ? t.value.length : t.selectionStart, z = t.selectionEnd == null ? a : t.selectionEnd, v = '{' + b.dataset.v + '}';
    t.value = t.value.slice(0, a) + v + t.value.slice(z); t.focus(); t.selectionStart = t.selectionEnd = a + v.length;
    SUDAH_EDIT.tpl = true; pratinjau();
});

$('ot-simpan').addEventListener('click', function () {
    var d = nilai(), btn = this, def = DEF[d.pemicu];
    galat('');
    if (d.nama.length < 3) return galat('Nama aturan minimal 3 karakter.');
    if (d.template.length < 5) return galat('Isi pesan minimal 5 karakter.');
    if (def.ambang && (d.ambang == null || isNaN(d.ambang) || d.ambang < def.ambang.min || d.ambang > def.ambang.max)) return galat(def.ambang.label + ' harus antara ' + def.ambang.min + ' dan ' + def.ambang.max + '.');
    var prog = !def.program ? '–' : (d.program_ids.length ? d.program_ids.map(function (i) { return PROG.filter(function (p) { return p.id === i; })[0].nama; }).join(', ') : 'Semua Program');
    Dlg.konfirmasi(EDIT ? 'Simpan perubahan aturan?' : 'Buat aturan pesan baru?', d.aktif ? 'Aturan aktif: pesan akan diantrikan sesuai pemicu.' : 'Aturan disimpan dalam keadaan nonaktif.', {
        ya: 'Ya, simpan', rincian: [['Nama', pot(d.nama, 40)], ['Pemicu', def.pemicu.replace('{n}', d.ambang)], ['Program', pot(prog, 40)], ['Status', d.aktif ? 'Aktif' : 'Nonaktif']]
    }).then(function (y) {
        if (!y) return;
        btn.disabled = true;
        return api('simpan', '', d).then(function (j) {
            $('ot-m').classList.remove('show');
            return Dlg.sukses('Berhasil disimpan', j.pesan).then(muat);
        }).catch(function (e) { btn.disabled = false; return Dlg.gagal('Gagal menyimpan', e.message); });
    });
});

// ----- aksi kartu -----
$('ot-grid').addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-a]'); if (!b || b.disabled) return;
    var c = b.closest('.ot-card'), id = parseInt(c.dataset.id, 10), r = ambil(id); if (!r) return;
    var a = b.dataset.a;
    if (a === 'ubah') return bukaForm(r);

    if (a === 'aktif') {
        var on = !r.aktif;
        return Dlg.konfirmasi(on ? 'Aktifkan aturan ini?' : 'Nonaktifkan aturan ini?',
            on ? 'Pesan otomatis akan diantrikan ke member sesuai pemicu.' : 'Tidak ada pesan baru yang diantrikan sampai diaktifkan kembali.',
            { ya: on ? 'Ya, aktifkan' : 'Ya, nonaktifkan', tipe: on ? 'tanya' : 'bahaya', rincian: [['Aturan', r.nama], ['Pemicu', r.teks_pemicu]] })
        .then(function (y) {
            if (!y) return;
            return api('aktif', '', { id: id, aktif: on }).then(function (j) { return Dlg.sukses(on ? 'Aturan diaktifkan' : 'Aturan dinonaktifkan', j.pesan).then(muat); })
                .catch(function (e) { return Dlg.gagal('Gagal mengubah status', e.message).then(muat); });
        });
    }

    if (a === 'hapus') {
        return api('log_jumlah', 'id=' + id).then(function (k) {
            return Dlg.konfirmasi('Hapus aturan pesan ini?', 'Aturan beserta riwayat pesannya akan dihapus permanen' + (k.jumlah ? ' (' + k.jumlah + ' riwayat pesan)' : '') + '. Jika hanya ingin berhenti sementara, gunakan tombol nonaktif.',
                { tipe: 'bahaya', ya: 'Ya, hapus', rincian: [['Aturan', r.nama], ['Pemicu', r.teks_pemicu]] });
        }).then(function (y) {
            if (!y) return;
            return api('hapus', '', { id: id }).then(function (j) { return Dlg.sukses('Berhasil dihapus', j.pesan).then(muat); })
                .catch(function (e) { return Dlg.gagal('Gagal menghapus', e.message).then(muat); });
        }).catch(function (e) { return Dlg.gagal('Gagal', e.message).then(muat); });
    }

    if (a === 'jalan') {
        if (!r.aktif) return Dlg.info('Aturan belum aktif', 'Aktifkan aturan ini terlebih dulu sebelum menjalankannya.');
        return api('target', 'id=' + id).then(function (t) {
            if (!t.baru) return Dlg.info('Tidak ada penerima baru', t.dilewati ? t.dilewati + ' member sudah pernah diantrikan pesan yang sama baru-baru ini.' : 'Saat ini belum ada member yang memenuhi pemicu "' + r.teks_pemicu + '".');
            var rin = [['Aturan', r.nama], ['Penerima baru', t.baru + ' member']];
            if (t.dilewati) rin.push(['Dilewati (sudah diantrikan)', t.dilewati]);
            t.contoh.forEach(function (x, i) { rin.push(['Contoh ' + (i + 1) + ' · ' + x.nama, pot(x.isi, 70)]); });
            return Dlg.konfirmasi('Antrikan ' + t.baru + ' pesan?', 'Pesan masuk antrean dan dikirim oleh gateway WhatsApp.', { ya: 'Ya, antrikan', rincian: rin }).then(function (y) {
                if (!y) return;
                return api('jalankan', '', { id: id }).then(function (j) { return Dlg.sukses('Pesan diantrikan', j.pesan).then(muat); });
            });
        }).catch(function (e) { return Dlg.gagal('Gagal menjalankan aturan', e.message); });
    }
});

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