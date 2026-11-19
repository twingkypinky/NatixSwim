<?php
// ============================================================
// knowledge-base.php — AI Assistant / Knowledge Base
// Tabel: knowledge_base (kategori, pertanyaan, jawaban, diubah_oleh, updated_at)
// Kolom tambahan (dibuat otomatis): status (aktif/draft), dipakai_ai (jumlah dipakai bot)
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

const KB_PER_HAL = 8;
const KB_GRUP = [
    'harga'     => ['label' => 'Harga & Paket',      'isi' => ['harga', 'program', 'pembayaran']],
    'jadwal'    => ['label' => 'Jadwal & Kelas',     'isi' => ['jadwal']],
    'lokasi'    => ['label' => 'Lokasi & Fasilitas', 'isi' => ['lokasi']],
    'kebijakan' => ['label' => 'Kebijakan & FAQ',    'isi' => ['kebijakan', 'umum']],
];

function kbGrupDari(string $kat): string
{
    foreach (KB_GRUP as $k => $g) if (in_array($kat, $g['isi'], true)) return $k;
    return 'kebijakan';
}

// Migrasi otomatis: kolom status & dipakai_ai, serta ENUM kategori ditambah 'lokasi' & 'kebijakan'. Mengembalikan daftar masalah.
function kbMigrasi(PDO $pdo): array
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if (!trAda($pdo, 'knowledge_base')) throw new TrException('Tabel knowledge_base belum ada. Jalankan skema database terlebih dulu.', 500);
    $salah = [];
    $kolom = [
        ['status', $mysql ? "ALTER TABLE knowledge_base ADD COLUMN status ENUM('aktif','draft') NOT NULL DEFAULT 'aktif'" : "ALTER TABLE knowledge_base ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'aktif'"],
        ['dipakai_ai', 'ALTER TABLE knowledge_base ADD COLUMN dipakai_ai INT NOT NULL DEFAULT 0'],
    ];
    foreach ($kolom as [$c, $sql]) {
        if (trAda($pdo, 'knowledge_base', $c)) continue;
        try { $pdo->exec($sql); } catch (Throwable $e) { $salah[] = "kolom $c (" . $e->getMessage() . ')'; }
        trAda($pdo, 'knowledge_base', $c, true);
    }
    if ($mysql) {
        try {
            $r = $pdo->query("SHOW COLUMNS FROM knowledge_base LIKE 'kategori'")->fetch(PDO::FETCH_ASSOC);
            $tipe = (string)($r['Type'] ?? '');
            if (stripos($tipe, 'enum(') === 0 && (stripos($tipe, "'lokasi'") === false || stripos($tipe, "'kebijakan'") === false)) {
                $pdo->exec("ALTER TABLE knowledge_base MODIFY kategori ENUM('program','harga','jadwal','pembayaran','umum','lokasi','kebijakan') NOT NULL");
            }
        } catch (Throwable $e) { $salah[] = 'kategori (' . $e->getMessage() . ')'; }
    }
    return $salah;
}

// Nilai kategori yang aman disimpan (kalau ENUM belum bisa diperluas, 'lokasi'/'kebijakan' jatuh ke 'umum')
function kbKategoriDb(PDO $pdo, string $grup): string
{
    static $lengkap = null;
    if ($lengkap === null) {
        $lengkap = true;
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            try {
                $r = $pdo->query("SHOW COLUMNS FROM knowledge_base LIKE 'kategori'")->fetch(PDO::FETCH_ASSOC);
                $t = (string)($r['Type'] ?? '');
                $lengkap = stripos($t, 'enum(') !== 0 || (stripos($t, "'lokasi'") !== false && stripos($t, "'kebijakan'") !== false);
            } catch (Throwable $e) { $lengkap = false; }
        }
    }
    if ($grup === 'lokasi' || $grup === 'kebijakan') return $lengkap ? $grup : 'umum';
    return $grup;
}

function kbBaris(array $r, int $maks): array
{
    $g = kbGrupDari((string)$r['kategori']);
    $tgl = $r['updated_at'] ?? null;
    $hariIni = $tgl && date('Y-m-d', strtotime($tgl)) === date('Y-m-d');
    return [
        'id' => (int)$r['id'], 'grup' => $g, 'kategori' => KB_GRUP[$g]['label'],
        'pertanyaan' => $r['pertanyaan'], 'jawaban' => $r['jawaban'],
        'status' => ($r['status'] ?? 'aktif') === 'draft' ? 'draft' : 'aktif',
        'dipakai' => (int)($r['dipakai_ai'] ?? 0),
        'persen' => $maks > 0 ? max(4, (int)round(((int)($r['dipakai_ai'] ?? 0)) / $maks * 100)) : 0,
        'diperbarui' => $hariIni ? 'Hari ini' : trTgl($tgl),
    ];
}

function kbValidasi(array $b): array
{
    $grup = (string)($b['kategori'] ?? '');
    if (!isset(KB_GRUP[$grup])) throw new TrException('Pilih kategori.', 422);
    $p = trim(preg_replace('/\s+/u', ' ', (string)($b['pertanyaan'] ?? '')));
    $j = trim((string)($b['jawaban'] ?? ''));
    if (mb_strlen($p) < 5) throw new TrException('Pertanyaan / topik minimal 5 karakter.', 422);
    if (mb_strlen($p) > 255) throw new TrException('Pertanyaan / topik maksimal 255 karakter.', 422);
    if (mb_strlen($j) < 3) throw new TrException('Jawaban minimal 3 karakter.', 422);
    if (mb_strlen($j) > 5000) throw new TrException('Jawaban maksimal 5.000 karakter.', 422);
    $st = ($b['status'] ?? 'aktif') === 'draft' ? 'draft' : 'aktif';
    return [$grup, $p, $j, $st];
}

function kbDuplikat(PDO $pdo, string $p, int $selainId = 0): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM knowledge_base WHERE LOWER(pertanyaan) = LOWER(:p) AND id <> :i');
    $s->execute([':p' => $p, ':i' => $selainId]);
    return (int)$s->fetchColumn() > 0;
}

// Baca CSV impor -> [baris valid, jumlah duplikat, jumlah tidak valid, contoh galat, total terbaca]
function kbBacaCsv(PDO $pdo, string $path): array
{
    $isi = file_get_contents($path);
    if ($isi === false || trim($isi) === '') throw new TrException('File kosong.', 422);
    $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi);
    if (!mb_check_encoding($isi, 'UTF-8')) $isi = mb_convert_encoding($isi, 'UTF-8', 'Windows-1252');
    $baris1 = strtok($isi, "\n");
    $sep = substr_count($baris1, ';') > substr_count($baris1, ',') ? ';' : ',';
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $isi); rewind($fh);
    $head = fgetcsv($fh, 0, $sep, '"', '');
    if (!$head) throw new TrException('Header CSV tidak terbaca.', 422);
    $idx = [];
    foreach ($head as $i => $h) $idx[strtolower(trim((string)$h))] = $i;
    foreach (['kategori', 'pertanyaan', 'jawaban'] as $w) if (!isset($idx[$w])) throw new TrException("Kolom \"$w\" tidak ditemukan. Gunakan header: kategori, pertanyaan, jawaban, status.", 422);

    $peta = [];
    foreach (KB_GRUP as $k => $g) { $peta[$k] = $k; $peta[strtolower($g['label'])] = $k; foreach ($g['isi'] as $x) $peta[$x] = $k; }
    $valid = []; $dup = 0; $salah = 0; $galat = []; $total = 0; $lihat = [];
    while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
        if (count($r) === 1 && trim((string)$r[0]) === '') continue;
        $total++;
        if ($total > 500) throw new TrException('Maksimal 500 baris per impor.', 422);
        $kat = $peta[strtolower(trim((string)($r[$idx['kategori']] ?? '')))] ?? null;
        try {
            if (!$kat) throw new TrException('kategori tidak dikenal', 422);
            [$g, $p, $j, $st] = kbValidasi(['kategori' => $kat, 'pertanyaan' => $r[$idx['pertanyaan']] ?? '', 'jawaban' => $r[$idx['jawaban']] ?? '',
                'status' => strtolower(trim((string)(isset($idx['status']) ? ($r[$idx['status']] ?? '') : '')))]);
        } catch (TrException $e) {
            $salah++; if (count($galat) < 5) $galat[] = 'Baris ' . ($total + 1) . ': ' . $e->getMessage();
            continue;
        }
        $kunci = mb_strtolower($p);
        if (isset($lihat[$kunci]) || kbDuplikat($pdo, $p)) { $dup++; continue; }
        $lihat[$kunci] = true;
        $valid[] = [$g, $p, $j, $st];
    }
    fclose($fh);
    return [$valid, $dup, $salah, $galat, $total];
}

// ─── AJAX ───
if ($trAjax) {
    try {
        $aksi = $_GET['aksi'];
        $salahMigrasi = kbMigrasi($pdo);
        $uid = null;

        if ($aksi === 'data') {
            $w = []; $par = [];
            $g = (string)($_GET['kategori'] ?? '');
            if (isset(KB_GRUP[$g])) {
                $in = [];
                foreach (KB_GRUP[$g]['isi'] as $i => $x) { $in[] = ':k' . $i; $par[':k' . $i] = $x; }
                $w[] = 'kategori IN (' . implode(',', $in) . ')';
            }
            $st = (string)($_GET['status'] ?? '');
            if ($st === 'aktif' || $st === 'draft') { $w[] = 'status = :st'; $par[':st'] = $st; }
            $q = trim((string)($_GET['q'] ?? ''));
            if ($q !== '') { $w[] = '(pertanyaan LIKE :q1 OR jawaban LIKE :q2)'; $par[':q1'] = $par[':q2'] = '%' . $q . '%'; }
            $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

            $c = $pdo->prepare("SELECT COUNT(*) FROM knowledge_base $where"); $c->execute($par);
            $total = (int)$c->fetchColumn();
            $hal = max(1, (int)($_GET['page'] ?? 1));
            $maxHal = max(1, (int)ceil($total / KB_PER_HAL));
            if ($hal > $maxHal) $hal = $maxHal;
            $s = $pdo->prepare("SELECT * FROM knowledge_base $where ORDER BY updated_at DESC, id DESC LIMIT " . KB_PER_HAL . ' OFFSET ' . (($hal - 1) * KB_PER_HAL));
            $s->execute($par);
            $maks = (int)$pdo->query('SELECT COALESCE(MAX(dipakai_ai),0) FROM knowledge_base')->fetchColumn();
            $rows = array_map(function ($r) use ($maks) { return kbBaris($r, $maks); }, $s->fetchAll(PDO::FETCH_ASSOC));

            $kartu = [];
            foreach (KB_GRUP as $k => $gg) $kartu[$k] = ['label' => $gg['label'], 'jumlah' => 0];
            foreach ($pdo->query('SELECT kategori, COUNT(*) n FROM knowledge_base GROUP BY kategori')->fetchAll(PDO::FETCH_ASSOC) as $r) $kartu[kbGrupDari((string)$r['kategori'])]['jumlah'] += (int)$r['n'];
            $semua = (int)$pdo->query('SELECT COUNT(*) FROM knowledge_base')->fetchColumn();
            $akhir = $pdo->query('SELECT MAX(updated_at) FROM knowledge_base')->fetchColumn();
            $ket = '–';
            if ($akhir) $ket = date('Y-m-d', strtotime($akhir)) === date('Y-m-d') ? 'hari ini, ' . date('H.i', strtotime($akhir)) : trTglJam($akhir);
            trOut(['ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $hal, 'pages' => $maxHal, 'per' => KB_PER_HAL,
                   'kartu' => $kartu, 'semua' => $semua, 'terakhir' => $ket, 'peringatan' => $salahMigrasi ? 'Sebagian migrasi database gagal: ' . implode('; ', $salahMigrasi) : '']);
        }

        if ($aksi === 'simpan') {
            trWajibPost();
            $b = trBody();
            [$g, $p, $j, $st] = kbValidasi($b);
            $id = (int)($b['id'] ?? 0);
            if (kbDuplikat($pdo, $p, $id)) throw new TrException('Pertanyaan yang sama sudah ada di Knowledge Base.', 409);
            $uid = trUserId($pdo);
            $kat = kbKategoriDb($pdo, $g);
            if ($id > 0) {
                $e = $pdo->prepare('SELECT COUNT(*) FROM knowledge_base WHERE id = :i'); $e->execute([':i' => $id]);
                if (!$e->fetchColumn()) throw new TrException('Data tidak ditemukan (mungkin sudah dihapus).', 404);
                $pdo->prepare('UPDATE knowledge_base SET kategori=:k, pertanyaan=:p, jawaban=:j, status=:s, diubah_oleh=:u, updated_at=CURRENT_TIMESTAMP WHERE id=:i')
                    ->execute([':k' => $kat, ':p' => $p, ':j' => $j, ':s' => $st, ':u' => $uid, ':i' => $id]);
                trOut(['ok' => true, 'pesan' => 'Pengetahuan berhasil diperbarui.', 'id' => $id]);
            }
            $pdo->prepare('INSERT INTO knowledge_base (kategori, pertanyaan, jawaban, status, diubah_oleh) VALUES (:k,:p,:j,:s,:u)')
                ->execute([':k' => $kat, ':p' => $p, ':j' => $j, ':s' => $st, ':u' => $uid]);
            trOut(['ok' => true, 'pesan' => 'Pengetahuan baru berhasil ditambahkan.', 'id' => (int)$pdo->lastInsertId()]);
        }

        if ($aksi === 'status') {
            trWajibPost();
            $b = trBody();
            $id = (int)($b['id'] ?? 0); $st = ($b['status'] ?? '') === 'draft' ? 'draft' : (($b['status'] ?? '') === 'aktif' ? 'aktif' : '');
            if ($id <= 0 || $st === '') throw new TrException('Data tidak valid.', 422);
            $u = $pdo->prepare('UPDATE knowledge_base SET status=:s, diubah_oleh=:u, updated_at=CURRENT_TIMESTAMP WHERE id=:i');
            $u->execute([':s' => $st, ':u' => trUserId($pdo), ':i' => $id]);
            $e = $pdo->prepare('SELECT COUNT(*) FROM knowledge_base WHERE id = :i'); $e->execute([':i' => $id]);
            if (!$e->fetchColumn()) throw new TrException('Data tidak ditemukan (mungkin sudah dihapus).', 404);
            trOut(['ok' => true, 'pesan' => $st === 'aktif' ? 'Entri diaktifkan. AI akan memakainya untuk menjawab.' : 'Entri dijadikan draft. AI tidak akan memakainya.']);
        }

        if ($aksi === 'hapus') {
            trWajibPost();
            $id = (int)(trBody()['id'] ?? 0);
            if ($id <= 0) throw new TrException('Data tidak valid.', 422);
            $d = $pdo->prepare('DELETE FROM knowledge_base WHERE id = :i'); $d->execute([':i' => $id]);
            if ($d->rowCount() < 1) throw new TrException('Data tidak ditemukan (mungkin sudah dihapus).', 404);
            trOut(['ok' => true, 'pesan' => 'Pengetahuan berhasil dihapus.']);
        }

        if ($aksi === 'import') {
            trWajibPost();
            $f = $_FILES['berkas'] ?? null;
            if (!$f || ($f['error'] ?? 4) !== 0 || !is_uploaded_file($f['tmp_name'] ?? '')) throw new TrException('Pilih file CSV terlebih dulu.', 422);
            if (($f['size'] ?? 0) > 1048576) throw new TrException('Ukuran file maksimal 1 MB.', 422);
            if (!preg_match('/\.(csv|txt)$/i', (string)$f['name'])) throw new TrException('Format file harus .csv', 422);
            [$valid, $dup, $salah, $galat, $total] = kbBacaCsv($pdo, $f['tmp_name']);
            $simpan = ($_POST['mode'] ?? '') === 'simpan';
            if ($simpan) {
                if (!$valid) throw new TrException('Tidak ada baris yang bisa diimpor.', 422);
                $uid = trUserId($pdo);
                $pdo->beginTransaction();
                try {
                    $ins = $pdo->prepare('INSERT INTO knowledge_base (kategori, pertanyaan, jawaban, status, diubah_oleh) VALUES (:k,:p,:j,:s,:u)');
                    foreach ($valid as [$g, $p, $j, $st]) $ins->execute([':k' => kbKategoriDb($pdo, $g), ':p' => $p, ':j' => $j, ':s' => $st, ':u' => $uid]);
                    $pdo->commit();
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            }
            trOut(['ok' => true, 'disimpan' => $simpan, 'total' => $total, 'valid' => count($valid), 'duplikat' => $dup, 'tidak_valid' => $salah, 'galat' => $galat]);
        }

        if ($aksi === 'contoh') {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="contoh-knowledge-base.csv"');
            echo "\xEF\xBB\xBF" . "kategori,pertanyaan,jawaban,status\r\n"
               . "harga,Berapa harga paket Group Kids?,\"Rp 750.000 untuk 8 sesi, durasi 60 menit.\",aktif\r\n"
               . "jadwal,Bagaimana cara reschedule?,Kirim pesan RESCHEDULE paling lambat H-1 sebelum kelas.,aktif\r\n"
               . "lokasi,Di mana lokasi kolam?,Jl. Tirta Kencana No. 18 Bandung.,draft\r\n"
               . "kebijakan,Apakah kuota hangus jika izin?,Tidak hangus jika izin maksimal H-1.,aktif\r\n"
               . "";
            exit;
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
    <title>Knowledge Base — Natix Swim</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/reset.css" />
    <link rel="stylesheet" href="css/global.css" />
    <link rel="stylesheet" href="css/manajemen-ai1.css" />
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

        .kb-cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-top: 22px; }
        .kb-card { background: #fff; border: 1px solid #eceef2; border-radius: 14px; padding: 16px 18px; cursor: pointer; text-align: left; font: inherit; color: inherit; transition: border-color .15s, box-shadow .15s; }
        .kb-card:hover { border-color: #bfdbfe; }
        .kb-card.on { border-color: #2563eb; box-shadow: 0 0 0 3px #dbeafe; }
        .kb-card p { margin: 0; }
        .kb-card .a { font-size: 13px; color: #6b7280; }
        .kb-card .b { font-size: 24px; font-weight: 700; margin-top: 4px; color: #111827; }
        .kb-card .b small { font-size: 13px; font-weight: 500; color: #9ca3af; margin-left: 4px; }

        .kb-list { margin-top: 18px; }
        .kb-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 20px 24px 14px; }
        .kb-filters { display: flex; gap: 10px; flex-wrap: wrap; }
        .kb-scroll { overflow-x: auto; }
        .kb-table { width: 100%; border-collapse: collapse; min-width: 860px; }
        .kb-table th { text-align: left; font-size: 12px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: .03em; padding: 12px 24px; background: #fafbfc; border-top: 1px solid #f1f2f5; border-bottom: 1px solid #f1f2f5; }
        .kb-table td { padding: 16px 24px; border-bottom: 1px solid #f1f2f5; font-size: 14px; vertical-align: middle; }
        .kb-table tr:last-child td { border-bottom: 0; }
        .kb-q { font-weight: 600; color: #111827; max-width: 420px; }
        .kb-a { font-size: 13px; color: #6b7280; margin-top: 3px; max-width: 420px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; word-break: break-word; }
        .kb-cat { display: inline-block; font-size: 12px; font-weight: 600; padding: 4px 11px; border-radius: 999px; background: #eff4ff; color: #2563eb; white-space: nowrap; }
        .kb-cat.jadwal { background: #dcfce7; color: #15803d; } .kb-cat.lokasi { background: #f3e8ff; color: #7e22ce; } .kb-cat.kebijakan { background: #ffedd5; color: #c2410c; }
        .kb-use { display: flex; align-items: center; gap: 10px; min-width: 120px; }
        .kb-use b { font-size: 14px; min-width: 40px; }
        .kb-bar { flex: 1; height: 6px; background: #eef0f3; border-radius: 99px; overflow: hidden; min-width: 50px; }
        .kb-bar i { display: block; height: 100%; background: #2563eb; border-radius: 99px; }
        .kb-muted { color: #6b7280; white-space: nowrap; }
        .kb-st { border: 0; cursor: pointer; font: inherit; font-size: 12px; font-weight: 600; padding: 5px 13px; border-radius: 999px; }
        .kb-st.aktif { background: #dcfce7; color: #16a34a; } .kb-st.draft { background: #f3f4f6; color: #6b7280; }
        .kb-st:hover { filter: brightness(.96); }
        .kb-acts { display: flex; gap: 6px; justify-content: flex-end; }
        .kb-ib { width: 34px; height: 34px; border: 1px solid #e5e7eb; background: #fff; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #4b5563; padding: 0; }
        .kb-ib:hover { background: #f3f4f6; } .kb-ib.del { color: #dc2626; border-color: #fecaca; } .kb-ib.del:hover { background: #fef2f2; }
        .kb-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 16px 24px; border-top: 1px solid #f1f2f5; font-size: 13px; color: #6b7280; }
        .kb-pager { display: flex; gap: 6px; }
        .kb-pager button { min-width: 34px; height: 34px; border: 1px solid #e5e7eb; background: #fff; border-radius: 9px; cursor: pointer; font: inherit; font-size: 13px; color: #374151; padding: 0 8px; }
        .kb-pager button.on { background: #2563eb; border-color: #2563eb; color: #fff; } .kb-pager button:disabled { opacity: .4; cursor: default; }
        .kb-warn { margin-top: 16px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 12px; padding: 12px 16px; font-size: 13px; display: none; }
        .kb-count { text-align: right; font-size: 12px; color: #9ca3af; margin-top: 4px; }
        .kb-file { border: 1.5px dashed #cbd5e1; border-radius: 12px; padding: 18px; text-align: center; background: #f8fafc; font-size: 13px; color: #64748b; }
        .kb-file input { margin-top: 10px; max-width: 100%; }
        .kb-link { color: #2563eb; text-decoration: underline; cursor: pointer; font-weight: 600; }
        @media (max-width: 1100px) { .kb-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 640px) { .kb-cards { grid-template-columns: 1fr; } }
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
        <header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div><input type="hidden" id="kb-q" value=""></header>

        <div class="ad-page">
            <?= nvCrumb() ?>
            <div class="ad-head">
                <div>
                    <h1 class="ad-title">Knowledge Base</h1>
                    <p class="ad-sub">Sumber jawaban yang digunakan AI untuk membalas member</p>
                </div>
                <div class="ad-actions">
                    <button type="button" class="ad-btn" id="kb-impor">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
                        Import Dokumen
                    </button>
                    <button type="button" class="ad-btn primary" id="kb-baru">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        Tambah Pengetahuan
                    </button>
                </div>
            </div>

            <div class="kb-warn" id="kb-warn"></div>
            <div class="kb-cards" id="kb-cards"></div>

            <div class="ad-card kb-list">
                <div class="kb-head">
                    <div>
                        <p class="ad-card-title">Daftar Pengetahuan</p>
                        <p class="ad-card-sub" id="kb-ket">Memuat...</p>
                    </div>
                    <div class="kb-filters">
                        <select class="ad-select" id="kb-f-kat"><option value="">Semua Kategori</option>
                            <?php foreach (KB_GRUP as $k => $g): ?><option value="<?= $k ?>"><?= htmlspecialchars($g['label']) ?></option><?php endforeach; ?>
                        </select>
                        <select class="ad-select" id="kb-f-st"><option value="">Status: Semua</option><option value="aktif">Aktif</option><option value="draft">Draft</option></select>
                    </div>
                </div>
                <div class="kb-scroll">
                    <table class="kb-table">
                        <thead><tr><th>Pertanyaan / Topik</th><th>Kategori</th><th>Dipakai AI</th><th>Diperbarui</th><th>Status</th><th style="text-align:right">Aksi</th></tr></thead>
                        <tbody id="kb-body"><tr><td colspan="6" class="ad-empty">Memuat...</td></tr></tbody>
                    </table>
                </div>
                <div class="kb-foot"><span id="kb-info"></span><div class="kb-pager" id="kb-pager"></div></div>
            </div>
        </div>
    </div>

    <!-- Form tambah / ubah -->
    <div class="ad-modal-bg" id="kb-m-form">
        <div class="ad-modal">
            <h3 id="kb-m-judul">Tambah Pengetahuan</h3>
            <p class="lead">Jawaban ini akan dipakai AI saat membalas pertanyaan member di WhatsApp.</p>
            <div class="ad-row2">
                <div class="ad-field"><label for="kb-f-kategori">Kategori</label>
                    <select id="kb-f-kategori"><?php foreach (KB_GRUP as $k => $g): ?><option value="<?= $k ?>"><?= htmlspecialchars($g['label']) ?></option><?php endforeach; ?></select></div>
                <div class="ad-field"><label for="kb-f-status">Status</label>
                    <select id="kb-f-status"><option value="aktif">Aktif (dipakai AI)</option><option value="draft">Draft (disimpan saja)</option></select></div>
            </div>
            <div class="ad-field"><label for="kb-f-tanya">Pertanyaan / Topik</label>
                <input id="kb-f-tanya" type="text" maxlength="255" placeholder="Contoh: Berapa harga paket Group Kids?"></div>
            <div class="ad-field"><label for="kb-f-jawab">Jawaban</label>
                <textarea id="kb-f-jawab" rows="6" maxlength="5000" placeholder="Tulis jawaban yang akan dikirim AI..."></textarea>
                <p class="kb-count"><span id="kb-n">0</span> / 5000</p></div>
            <div class="ad-msg" id="kb-m-err"></div>
            <div class="ad-modal-foot">
                <button type="button" class="ad-btn" id="kb-batal">Batal</button>
                <button type="button" class="ad-btn primary" id="kb-simpan">Simpan</button>
            </div>
        </div>
    </div>

    <!-- Import -->
    <div class="ad-modal-bg" id="kb-m-impor">
        <div class="ad-modal">
            <h3>Import Dokumen</h3>
            <p class="lead">Unggah file CSV berisi banyak pengetahuan sekaligus.</p>
            <div class="ad-field">
                <div class="kb-file">
                    Kolom: <b>kategori, pertanyaan, jawaban, status</b> (status boleh dikosongkan). Maks. 500 baris, 1 MB.<br>
                    <a class="kb-link" href="?aksi=contoh">Unduh contoh CSV</a>
                    <br><input type="file" id="kb-file" accept=".csv,.txt,text/csv">
                </div>
            </div>
            <div class="ad-msg" id="kb-i-err"></div>
            <div class="ad-modal-foot">
                <button type="button" class="ad-btn" id="kb-i-batal">Batal</button>
                <button type="button" class="ad-btn primary" id="kb-i-cek">Periksa &amp; Impor</button>
            </div>
        </div>
    </div>

<script>
var S = { kategori: '', status: '', q: '', page: 1 };
var DATA = [], EDIT = 0, AWAL = '';
var $ = function (id) { return document.getElementById(id); };
var IKON = {
    pensil: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4"/></svg>',
    sampah: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>'
};
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function pot(s, n) { s = String(s); return s.length > n ? s.slice(0, n - 1) + '…' : s; }

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

function api(aksi, qs, body, form) {
    var opt = { method: body ? 'POST' : 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    if (body) { if (form) opt.body = body; else { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); } }
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
    var qs = 'kategori=' + encodeURIComponent(S.kategori) + '&status=' + S.status + '&q=' + encodeURIComponent(S.q) + '&page=' + S.page;
    return api('data', qs).then(function (j) {
        S.page = j.page; DATA = j.rows;
        $('kb-ket').textContent = j.semua + ' entri • terakhir diperbarui ' + j.terakhir;
        var w = $('kb-warn'); w.style.display = j.peringatan ? 'block' : 'none'; w.textContent = j.peringatan;
        var h = '';
        Object.keys(j.kartu).forEach(function (k) {
            h += '<button type="button" class="kb-card' + (S.kategori === k ? ' on' : '') + '" data-k="' + k + '"><p class="a">' + esc(j.kartu[k].label) + '</p><p class="b">' + j.kartu[k].jumlah + '<small>entri</small></p></button>';
        });
        $('kb-cards').innerHTML = h;
        var b = '';
        if (!j.rows.length) b = '<tr><td colspan="6" class="ad-empty">' + (j.semua ? 'Tidak ada entri yang cocok dengan filter.' : 'Belum ada pengetahuan. Klik "Tambah Pengetahuan" untuk memulai.') + '</td></tr>';
        j.rows.forEach(function (r) {
            b += '<tr><td><p class="kb-q">' + esc(r.pertanyaan) + '</p><p class="kb-a">' + esc(r.jawaban) + '</p></td>'
              + '<td><span class="kb-cat ' + r.grup + '">' + esc(r.kategori) + '</span></td>'
              + '<td><div class="kb-use"><b>' + r.dipakai + '×</b><div class="kb-bar"><i style="width:' + r.persen + '%"></i></div></div></td>'
              + '<td class="kb-muted">' + esc(r.diperbarui) + '</td>'
              + '<td><button type="button" class="kb-st ' + r.status + '" data-a="st" data-id="' + r.id + '" title="Klik untuk mengubah status">' + (r.status === 'aktif' ? 'Aktif' : 'Draft') + '</button></td>'
              + '<td><div class="kb-acts"><button type="button" class="kb-ib" data-a="ubah" data-id="' + r.id + '" title="Ubah">' + IKON.pensil + '</button>'
              + '<button type="button" class="kb-ib del" data-a="hapus" data-id="' + r.id + '" title="Hapus">' + IKON.sampah + '</button></div></td></tr>';
        });
        $('kb-body').innerHTML = b;
        var dari = j.total ? (j.page - 1) * j.per + 1 : 0, sampai = Math.min(j.total, j.page * j.per);
        $('kb-info').textContent = 'Menampilkan ' + dari + '–' + sampai + ' dari ' + j.total + ' entri';
        var p = '<button type="button" data-p="' + (j.page - 1) + '"' + (j.page <= 1 ? ' disabled' : '') + '>‹</button>';
        var a = Math.max(1, j.page - 2), z = Math.min(j.pages, a + 4); a = Math.max(1, z - 4);
        for (var i = a; i <= z; i++) p += '<button type="button" data-p="' + i + '"' + (i === j.page ? ' class="on"' : '') + '>' + i + '</button>';
        p += '<button type="button" data-p="' + (j.page + 1) + '"' + (j.page >= j.pages ? ' disabled' : '') + '>›</button>';
        $('kb-pager').innerHTML = j.pages > 1 ? p : '';
    }).catch(function (e) {
        $('kb-body').innerHTML = '<tr><td colspan="6" class="ad-empty" style="color:#b91c1c">' + esc(e.message) + '</td></tr>';
    });
}

// ----- filter -----
var tmr;
$('kb-q').addEventListener('input', function () { clearTimeout(tmr); var v = this.value; tmr = setTimeout(function () { S.q = v.trim(); S.page = 1; muat(); }, 300); });
$('kb-f-kat').addEventListener('change', function () { S.kategori = this.value; S.page = 1; muat(); });
$('kb-f-st').addEventListener('change', function () { S.status = this.value; S.page = 1; muat(); });
$('kb-cards').addEventListener('click', function (ev) {
    var c = ev.target.closest('.kb-card'); if (!c) return;
    S.kategori = S.kategori === c.dataset.k ? '' : c.dataset.k; $('kb-f-kat').value = S.kategori; S.page = 1; muat();
});
$('kb-pager').addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-p]'); if (!b || b.disabled) return;
    S.page = parseInt(b.dataset.p, 10); muat();
});

// ----- form -----
function ambil() { return { id: EDIT, kategori: $('kb-f-kategori').value, status: $('kb-f-status').value, pertanyaan: $('kb-f-tanya').value.trim(), jawaban: $('kb-f-jawab').value.trim() }; }
function kotor() { return JSON.stringify(ambil()) !== AWAL; }
function galat(el, t) { el.textContent = t || ''; el.classList.toggle('show', !!t); }
function bukaForm(r) {
    EDIT = r ? r.id : 0;
    $('kb-m-judul').textContent = r ? 'Ubah Pengetahuan' : 'Tambah Pengetahuan';
    $('kb-f-kategori').value = r ? r.grup : 'harga';
    $('kb-f-status').value = r ? r.status : 'aktif';
    $('kb-f-tanya').value = r ? r.pertanyaan : ''; $('kb-f-jawab').value = r ? r.jawaban : '';
    $('kb-n').textContent = $('kb-f-jawab').value.length;
    galat($('kb-m-err'), ''); $('kb-simpan').disabled = false;
    AWAL = JSON.stringify(ambil());
    $('kb-m-form').classList.add('show'); $('kb-f-tanya').focus();
}
function tutupForm(paksa) {
    if (!$('kb-m-form').classList.contains('show')) return Promise.resolve();
    if (paksa || !kotor()) { $('kb-m-form').classList.remove('show'); return Promise.resolve(); }
    return Dlg.konfirmasi('Batalkan perubahan?', 'Data yang sudah diisi belum disimpan dan akan hilang.', { ya: 'Ya, batalkan', tidak: 'Lanjut mengisi', tipe: 'bahaya' })
        .then(function (y) { if (y) $('kb-m-form').classList.remove('show'); });
}
$('kb-baru').addEventListener('click', function () { bukaForm(null); });
$('kb-batal').addEventListener('click', function () { tutupForm(false); });
$('kb-m-form').addEventListener('mousedown', function (ev) { if (ev.target === this) tutupForm(false); });
$('kb-f-jawab').addEventListener('input', function () { $('kb-n').textContent = this.value.length; });
document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !document.querySelector('.ad-dlg-bg.show')) { tutupForm(false); tutupImpor(); }
});

$('kb-simpan').addEventListener('click', function () {
    var d = ambil(), btn = this;
    galat($('kb-m-err'), '');
    if (d.pertanyaan.length < 5) return galat($('kb-m-err'), 'Pertanyaan / topik minimal 5 karakter.');
    if (d.jawaban.length < 3) return galat($('kb-m-err'), 'Jawaban minimal 3 karakter.');
    var kat = $('kb-f-kategori').options[$('kb-f-kategori').selectedIndex].text;
    Dlg.konfirmasi(EDIT ? 'Simpan perubahan?' : 'Tambah pengetahuan baru?', 'Pastikan jawaban sudah benar karena akan dikirim AI ke member.', {
        ya: 'Ya, simpan', rincian: [['Kategori', kat], ['Pertanyaan', pot(d.pertanyaan, 60)], ['Status', d.status === 'aktif' ? 'Aktif' : 'Draft']]
    }).then(function (y) {
        if (!y) return;
        btn.disabled = true;
        return api('simpan', '', d).then(function (j) {
            $('kb-m-form').classList.remove('show');
            return Dlg.sukses('Berhasil disimpan', j.pesan).then(function () { S.page = EDIT ? S.page : 1; return muat(); });
        }).catch(function (e) { btn.disabled = false; return Dlg.gagal('Gagal menyimpan', e.message); });
    });
});

// ----- aksi baris -----
$('kb-body').addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-a]'); if (!b) return;
    var id = parseInt(b.dataset.id, 10), r = DATA.filter(function (x) { return x.id === id; })[0]; if (!r) return;
    if (b.dataset.a === 'ubah') return bukaForm(r);
    if (b.dataset.a === 'st') {
        var baru = r.status === 'aktif' ? 'draft' : 'aktif';
        return Dlg.konfirmasi(baru === 'aktif' ? 'Aktifkan entri ini?' : 'Jadikan draft?',
            baru === 'aktif' ? 'AI akan mulai memakai jawaban ini untuk membalas member.' : 'AI tidak akan memakai jawaban ini lagi sampai diaktifkan kembali.',
            { ya: baru === 'aktif' ? 'Ya, aktifkan' : 'Ya, jadikan draft', rincian: [['Pertanyaan', pot(r.pertanyaan, 60)]] })
        .then(function (y) {
            if (!y) return;
            return api('status', '', { id: id, status: baru }).then(function (j) { return Dlg.sukses('Status diperbarui', j.pesan).then(muat); })
                .catch(function (e) { return Dlg.gagal('Gagal mengubah status', e.message).then(muat); });
        });
    }
    if (b.dataset.a === 'hapus') {
        return Dlg.konfirmasi('Hapus pengetahuan ini?', 'Data yang dihapus tidak bisa dikembalikan. Jika hanya ingin menonaktifkan sementara, gunakan status Draft.',
            { tipe: 'bahaya', ya: 'Ya, hapus', rincian: [['Pertanyaan', pot(r.pertanyaan, 60)], ['Kategori', r.kategori]] })
        .then(function (y) {
            if (!y) return;
            return api('hapus', '', { id: id }).then(function (j) { return Dlg.sukses('Berhasil dihapus', j.pesan).then(muat); })
                .catch(function (e) { return Dlg.gagal('Gagal menghapus', e.message).then(muat); });
        });
    }
});

// ----- import -----
function tutupImpor() { $('kb-m-impor').classList.remove('show'); }
$('kb-impor').addEventListener('click', function () { $('kb-file').value = ''; galat($('kb-i-err'), ''); $('kb-m-impor').classList.add('show'); });
$('kb-i-batal').addEventListener('click', tutupImpor);
$('kb-m-impor').addEventListener('mousedown', function (ev) { if (ev.target === this) tutupImpor(); });
$('kb-i-cek').addEventListener('click', function () {
    var f = $('kb-file').files[0], btn = this;
    galat($('kb-i-err'), '');
    if (!f) return galat($('kb-i-err'), 'Pilih file CSV terlebih dulu.');
    var fd = new FormData(); fd.append('berkas', f); fd.append('mode', 'cek');
    btn.disabled = true;
    api('import', '', fd, true).then(function (j) {
        btn.disabled = false;
        var rin = [['Baris terbaca', j.total], ['Siap diimpor', j.valid], ['Duplikat (dilewati)', j.duplikat], ['Tidak valid (dilewati)', j.tidak_valid]];
        if (!j.valid) return Dlg.gagal('Tidak ada yang bisa diimpor', (j.galat.length ? j.galat.join(' | ') : 'Semua baris duplikat atau kosong.'));
        return Dlg.konfirmasi('Impor ' + j.valid + ' pengetahuan?', j.galat.length ? j.galat.join(' | ') : 'Entri akan ditambahkan ke Knowledge Base.', { ya: 'Ya, impor', rincian: rin }).then(function (y) {
            if (!y) return;
            fd.set('mode', 'simpan'); btn.disabled = true;
            return api('import', '', fd, true).then(function (k) {
                btn.disabled = false; tutupImpor();
                return Dlg.sukses('Impor berhasil', k.valid + ' pengetahuan berhasil ditambahkan.').then(function () { S.page = 1; return muat(); });
            });
        });
    }).catch(function (e) { btn.disabled = false; return Dlg.gagal('Gagal mengimpor', e.message); });
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