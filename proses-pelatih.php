<?php
// ============================================================
// proses-pelatih.php
//  - Bagian 1: fungsi-fungsi Data Pelatih (dimuat juga oleh data-pelatih-daftar.php)
//  - Bagian 2: tambah / edit / hapus / export — hanya jalan kalau file ini dipanggil langsung
//    Balasan JSON: { sukses: bool, pesan: string, ... }
// ============================================================

// ───────────── BAGIAN 1: FUNGSI BERSAMA ─────────────
date_default_timezone_set('Asia/Jakarta');

// Spesialisasi bawaan (selain yang sudah ada di database) untuk saran di form
const PL_SPES_DEFAULT = ['Group Kids', 'Group Dewasa', 'Baby Swim', 'Private'];

// Senin & Minggu pekan ini (Y-m-d)
function plRentangPekan(): array
{
    $d = new DateTime('today');
    $d->modify('-' . ((int)$d->format('N') - 1) . ' days');
    return [$d->format('Y-m-d'), (clone $d)->modify('+6 days')->format('Y-m-d')];
}

// 'non_aktif' / 'Nonaktif' / 'NON-AKTIF' -> 'nonaktif'
function plNorm(string $s): string
{
    return preg_replace('/[^a-z]/', '', strtolower($s));
}

// Pemetaan status logis (aktif | cuti | nonaktif) -> nilai ENUM asli di tabel pelatih.
// Dibaca dari database supaya tetap jalan walau enum-nya 'non_aktif' atau 'Nonaktif'.
function plStatusMap(PDO $pdo): array
{
    static $map = null;
    if ($map !== null) return $map;
    $map = ['aktif' => 'aktif', 'cuti' => 'cuti', 'nonaktif' => 'non_aktif'];
    try {
        $row = $pdo->query("SHOW COLUMNS FROM pelatih LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($row && preg_match_all("/'((?:[^']|'')*)'/", $row['Type'], $m)) {
            $baru = [];
            foreach ($m[1] as $v) {
                $v = str_replace("''", "'", $v);
                $baru[plNorm($v)] = $v;
            }
            foreach (['aktif', 'cuti', 'nonaktif'] as $k) {
                if (isset($baru[$k])) $map[$k] = $baru[$k];
            }
        }
    } catch (Throwable $e) { /* pakai default */ }
    return $map;
}

// Nilai status di DB -> kunci logis
function plKeyStatus(?string $raw): string
{
    $n = plNorm((string)$raw);
    return in_array($n, ['aktif', 'cuti', 'nonaktif'], true) ? $n : 'aktif';
}

function plLabelStatus(string $key): string
{
    return ['aktif' => 'Aktif', 'penuh' => 'Penuh', 'cuti' => 'Cuti', 'nonaktif' => 'Nonaktif'][$key] ?? 'Aktif';
}

// Apakah kolom ada di tabel? (dipakai untuk kolom opsional seperti maks_jam)
function plAdaKolom(PDO $pdo, string $tabel, string $kolom): bool
{
    static $cache = [];
    $k = $tabel . '.' . $kolom;
    if (!isset($cache[$k])) {
        try {
            $pdo->query("SELECT `$kolom` FROM `$tabel` LIMIT 0");
            $cache[$k] = true;
        } catch (Throwable $e) {
            $cache[$k] = false;
        }
    }
    return $cache[$k];
}

// No. HP bisa tersimpan di pelatih.no_hp dan/atau users.no_hp, tergantung struktur database
function plExprHp(PDO $pdo): string
{
    $diPelatih = plAdaKolom($pdo, 'pelatih', 'no_hp');
    $diUsers   = plAdaKolom($pdo, 'users', 'no_hp');
    if ($diPelatih && $diUsers) return "COALESCE(NULLIF(p.no_hp, ''), u.no_hp)";
    if ($diPelatih) return 'p.no_hp';
    if ($diUsers)   return 'u.no_hp';
    return 'NULL';
}

// Daftar pelatih + beban mengajar pekan ini.
// $q = kata kunci (nama / spesialisasi / email), $spes = spesialisasi persis.
function plDaftar(PDO $pdo, string $q = '', string $spes = ''): array
{
    [$senin, $minggu] = plRentangPekan();
    $maks = plAdaKolom($pdo, 'pelatih', 'maks_jam') ? 'p.maks_jam' : 'NULL';
    $bgbg = plAdaKolom($pdo, 'pelatih', 'bergabung') ? 'p.bergabung' : 'NULL';
    $hp   = plExprHp($pdo);

    $sql = "SELECT p.id, p.user_id, p.spesialisasi, p.status, $bgbg AS bergabung, $maks AS maks_jam,
                   u.nama, u.email, $hp AS no_hp,
                   COALESCE(a.jml_kelas, 0)  AS jml_kelas,
                   COALESCE(a.jam, 0)        AS jam,
                   COALESCE(b.jml_member, 0) AS jml_member
              FROM pelatih p
              JOIN users u ON u.id = p.user_id
              LEFT JOIN (
                    SELECT j.pelatih_id, COUNT(*) AS jml_kelas,
                           SUM(TIME_TO_SEC(TIMEDIFF(j.jam_selesai, j.jam_mulai))) / 3600 AS jam
                      FROM jadwal j
                     WHERE j.tanggal BETWEEN :s1 AND :e1 AND j.status <> 'dibatalkan'
                     GROUP BY j.pelatih_id) a ON a.pelatih_id = p.id
              LEFT JOIN (
                    SELECT j.pelatih_id, COUNT(DISTINCT jp.member_id) AS jml_member
                      FROM jadwal j
                      JOIN jadwal_peserta jp ON jp.jadwal_id = j.id
                     WHERE j.tanggal BETWEEN :s2 AND :e2 AND j.status <> 'dibatalkan'
                     GROUP BY j.pelatih_id) b ON b.pelatih_id = p.id
             WHERE 1 = 1";
    $par = [':s1' => $senin, ':e1' => $minggu, ':s2' => $senin, ':e2' => $minggu];

    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $sql .= " AND (u.nama LIKE :q1 OR p.spesialisasi LIKE :q2 OR u.email LIKE :q3)";
        $par[':q1'] = $par[':q2'] = $par[':q3'] = $like;
    }
    if ($spes !== '') {
        $sql .= " AND p.spesialisasi = :sp";
        $par[':sp'] = $spes;
    }
    $sql .= " ORDER BY p.id ASC";

    $st = $pdo->prepare($sql);
    $st->execute($par);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['jam']        = round((float)$r['jam'], 1);
        $r['maks_jam']   = (int)($r['maks_jam'] ?: 20);
        $r['jml_kelas']  = (int)$r['jml_kelas'];
        $r['jml_member'] = (int)$r['jml_member'];
        $r['status_key'] = plKeyStatus($r['status']);
        // "Penuh" = status aktif tetapi jam mengajar pekan ini sudah mencapai batas
        $r['tampil']     = ($r['status_key'] === 'aktif' && $r['jam'] >= $r['maks_jam']) ? 'penuh' : $r['status_key'];
        $r['satuan']     = stripos((string)$r['spesialisasi'], 'private') !== false ? 'sesi' : 'kelas';
    }
    unset($r);
    return $rows;
}

// Kartu statistik di atas tabel (tidak terpengaruh filter)
function plStatistik(PDO $pdo): array
{
    [$senin, $minggu] = plRentangPekan();
    $aktifVal = plStatusMap($pdo)['aktif'];

    $total = (int)$pdo->query("SELECT COUNT(*) FROM pelatih")->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM pelatih WHERE status = :s");
    $st->execute([':s' => $aktifVal]);
    $aktif = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(jam_selesai, jam_mulai))), 0) / 3600
                           FROM jadwal
                          WHERE tanggal BETWEEN :s AND :e AND status <> 'dibatalkan'");
    $st->execute([':s' => $senin, ':e' => $minggu]);
    $jam = round((float)$st->fetchColumn(), 1);

    return ['total' => $total, 'aktif' => $aktif, 'jam' => $jam];
}

// Spesialisasi yang sudah dipakai di database (untuk dropdown filter)
function plSpesDiDb(PDO $pdo): array
{
    return $pdo->query("SELECT DISTINCT spesialisasi FROM pelatih
                         WHERE spesialisasi IS NOT NULL AND spesialisasi <> ''
                         ORDER BY spesialisasi")->fetchAll(PDO::FETCH_COLUMN);
}

// ───────────── BAGIAN 2: PROSES (hanya jika dipanggil langsung) ─────────────
// Kalau file ini di-require oleh halaman lain, berhenti di sini (cukup memuat fungsi di atas).
if (get_included_files()[0] !== __FILE__) {
    return;
}

ob_start();
ini_set('display_errors', '0');

// true = pesan error server ditampilkan apa adanya (membantu debug). Ubah ke false saat production.
const PL_DEBUG = true;

// Dipasang paling awal supaya error fatal (mis. file tidak ketemu) tetap dibalas sebagai pesan jelas
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) ob_end_clean();
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['sukses' => false, 'pesan' => PL_DEBUG ? 'Error PHP: ' . $e['message'] : 'Terjadi kesalahan pada server.'], JSON_UNESCAPED_UNICODE);
    }
});

foreach (['config/koneksi.php'] as $f) {
    if (!is_file(__DIR__ . '/' . $f)) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sukses' => false, 'pesan' => "File $f tidak ditemukan di folder " . __DIR__], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
require_once __DIR__ . '/config/koneksi.php';   // menghasilkan $pdo

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function balas(bool $sukses, string $pesan, array $extra = [], int $code = 200): void
{
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['sukses' => $sukses, 'pesan' => $pesan], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$aksi   = $_GET['aksi'] ?? $_POST['aksi'] ?? '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

// ------------------------------------------------------------
// EXPORT CSV (GET) — mengikuti filter yang sedang aktif
// ------------------------------------------------------------
if ($aksi === 'export') {
    try {
        $q    = trim($_GET['q'] ?? '');
        $spes = trim($_GET['spesialisasi'] ?? '');
        $fSt  = trim($_GET['status'] ?? '');
        $rows = plDaftar($pdo, $q, $spes);
        if (in_array($fSt, ['aktif', 'penuh', 'cuti', 'nonaktif'], true)) {
            $rows = array_values(array_filter($rows, fn($r) => $r['tampil'] === $fSt));
        }

        // cegah CSV injection (sel diawali = + - @)
        $aman = function ($v) {
            $v = (string)$v;
            return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
        };

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="data-pelatih-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Kode', 'Nama', 'Email', 'No. HP', 'Spesialisasi', 'Kelas Pekan Ini', 'Member', 'Jam Pekan Ini', 'Maks. Jam', 'Status', 'Bergabung']);
        foreach ($rows as $r) {
            fputcsv($out, [
                sprintf('PLT-%03d', $r['id']), $aman($r['nama']), $aman($r['email']), $aman($r['no_hp']),
                $aman($r['spesialisasi']), $r['jml_kelas'], $r['jml_member'], $r['jam'], $r['maks_jam'],
                plLabelStatus($r['tampil']), $r['bergabung'],
            ]);
        }
        fclose($out);
    } catch (Throwable $e) {
        error_log('[proses-pelatih] ' . $e->getMessage());
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        echo PL_DEBUG ? 'Gagal membuat export: ' . $e->getMessage() : 'Gagal membuat export.';
    }
    exit;
}

// ------------------------------------------------------------
// Aksi lain: wajib POST
// ------------------------------------------------------------
if (!in_array($aksi, ['tambah', 'edit', 'hapus'], true)) {
    balas(false, 'Aksi tidak dikenal.', [], 400);
}
if (!$isPost) {
    balas(false, 'Metode tidak diizinkan.', [], 405);
}

// Ambil & validasi isian form (dipakai tambah + edit)
function ambilInput(PDO $pdo): array
{
    $nama   = trim($_POST['nama'] ?? '');
    $email  = strtolower(trim($_POST['email'] ?? ''));
    $hp     = trim($_POST['no_hp'] ?? '');
    $spes   = trim($_POST['spesialisasi'] ?? '');
    $maks   = (int)($_POST['maks_jam'] ?? 20);
    $status = plNorm($_POST['status'] ?? 'aktif');

    if ($nama === '' || strlen($nama) > 100)               return ['err' => 'Nama wajib diisi (maksimal 100 karakter).'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) return ['err' => 'Format email tidak valid.'];
    if ($hp !== '' && !preg_match('/^[0-9+\-\s()]{5,20}$/', $hp))           return ['err' => 'No. HP hanya boleh angka, spasi, +, -, dan maksimal 20 karakter.'];
    if ($spes === '' || strlen($spes) > 100)               return ['err' => 'Spesialisasi wajib diisi (maksimal 100 karakter).'];
    if ($maks < 1 || $maks > 60)                           return ['err' => 'Maks. jam per minggu harus antara 1 dan 60.'];
    if (!in_array($status, ['aktif', 'cuti', 'nonaktif'], true)) return ['err' => 'Status tidak valid.'];

    return ['nama' => $nama, 'email' => $email, 'no_hp' => $hp !== '' ? $hp : null,
            'spes' => $spes, 'maks' => $maks, 'status' => $status];
}

function emailDipakai(PDO $pdo, string $email, int $kecualiUserId = 0): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :e AND id <> :id");
    $st->execute([':e' => $email, ':id' => $kecualiUserId]);
    return (bool)$st->fetchColumn();
}

try {
    $map      = plStatusMap($pdo);
    $adaMaks  = plAdaKolom($pdo, 'pelatih', 'maks_jam');
    $hpPel    = plAdaKolom($pdo, 'pelatih', 'no_hp');       // no_hp di tabel pelatih?
    $hpUser   = plAdaKolom($pdo, 'users', 'no_hp');         // no_hp di tabel users?
    $stUser   = plAdaKolom($pdo, 'users', 'status');
    $colPass  = plAdaKolom($pdo, 'users', 'password_hash') ? 'password_hash' : 'password';

    // ── TAMBAH ──
    if ($aksi === 'tambah') {
        $d = ambilInput($pdo);
        if (isset($d['err'])) balas(false, $d['err'], [], 422);
        if (emailDipakai($pdo, $d['email'])) balas(false, 'Email sudah dipakai akun lain.', [], 409);

        $passwordAwal = substr(bin2hex(random_bytes(6)), 0, 10);

        $pdo->beginTransaction();
        $cols = "nama, email, $colPass, role"; $vals = ':n, :e, :p, :r';
        $par  = [':n' => $d['nama'], ':e' => $d['email'], ':p' => password_hash($passwordAwal, PASSWORD_DEFAULT), ':r' => 'pelatih'];
        if ($hpUser) { $cols .= ', no_hp';  $vals .= ', :h'; $par[':h'] = $d['no_hp']; }
        if ($stUser) { $cols .= ', status'; $vals .= ', :s'; $par[':s'] = $d['status'] === 'nonaktif' ? 'non_aktif' : 'aktif'; }
        $pdo->prepare("INSERT INTO users ($cols) VALUES ($vals)")->execute($par);
        $uid = (int)$pdo->lastInsertId();

        $cols = 'user_id, spesialisasi, status';
        $vals = ':u, :sp, :st';
        $par  = [':u' => $uid, ':sp' => $d['spes'], ':st' => $map[$d['status']]];
        if (plAdaKolom($pdo, 'pelatih', 'bergabung')) { $cols .= ', bergabung'; $vals .= ', :bg'; $par[':bg'] = date('Y-m-d'); }
        if ($hpPel)  { $cols .= ', no_hp';    $vals .= ', :hp'; $par[':hp'] = $d['no_hp']; }
        if ($adaMaks) { $cols .= ', maks_jam'; $vals .= ', :mk'; $par[':mk'] = $d['maks']; }
        $pdo->prepare("INSERT INTO pelatih ($cols) VALUES ($vals)")->execute($par);
        $pdo->commit();

        balas(true, 'Pelatih berhasil ditambahkan.', ['password_awal' => $passwordAwal, 'email' => $d['email']]);
    }

    // ── EDIT ──
    if ($aksi === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT user_id FROM pelatih WHERE id = :id");
        $st->execute([':id' => $id]);
        $uid = (int)$st->fetchColumn();
        if (!$uid) balas(false, 'Data pelatih tidak ditemukan.', [], 404);

        $d = ambilInput($pdo);
        if (isset($d['err'])) balas(false, $d['err'], [], 422);
        if (emailDipakai($pdo, $d['email'], $uid)) balas(false, 'Email sudah dipakai akun lain.', [], 409);

        $pdo->beginTransaction();
        $set = 'nama = :n, email = :e';
        $par = [':n' => $d['nama'], ':e' => $d['email'], ':id' => $uid];
        if ($hpUser) { $set .= ', no_hp = :h';  $par[':h'] = $d['no_hp']; }
        if ($stUser) { $set .= ', status = :s'; $par[':s'] = $d['status'] === 'nonaktif' ? 'non_aktif' : 'aktif'; }
        $pdo->prepare("UPDATE users SET $set WHERE id = :id")->execute($par);

        $set = 'spesialisasi = :sp, status = :st';
        $par = [':sp' => $d['spes'], ':st' => $map[$d['status']], ':id' => $id];
        if ($hpPel)   { $set .= ', no_hp = :hp';   $par[':hp'] = $d['no_hp']; }
        if ($adaMaks) { $set .= ', maks_jam = :mk'; $par[':mk'] = $d['maks']; }
        $pdo->prepare("UPDATE pelatih SET $set WHERE id = :id")->execute($par);
        $pdo->commit();

        balas(true, 'Data pelatih berhasil diperbarui.');
    }

    // ── HAPUS ──
    if ($aksi === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT p.user_id, u.role FROM pelatih p JOIN users u ON u.id = p.user_id WHERE p.id = :id");
        $st->execute([':id' => $id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) balas(false, 'Data pelatih tidak ditemukan.', [], 404);

        // Pelatih yang sudah punya jadwal / evaluasi tidak boleh dihapus (data riwayat akan rusak)
        foreach (['jadwal' => 'jadwal', 'evaluasi' => 'evaluasi'] as $tabel => $label) {
            try {
                $c = $pdo->prepare("SELECT COUNT(*) FROM `$tabel` WHERE pelatih_id = :id");
                $c->execute([':id' => $id]);
                $n = (int)$c->fetchColumn();
            } catch (Throwable $e) { $n = 0; }
            if ($n > 0) {
                balas(false, "Pelatih ini masih punya $n data $label sehingga tidak bisa dihapus. Ubah statusnya menjadi Nonaktif, atau pindahkan/hapus datanya terlebih dahulu.", [], 409);
            }
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM pelatih WHERE id = :id")->execute([':id' => $id]);
        // akun login ikut dihapus, kecuali milik owner/admin
        if ($p['role'] === 'pelatih') {
            $pdo->prepare("DELETE FROM users WHERE id = :u AND role = 'pelatih'")->execute([':u' => $p['user_id']]);
        }
        $pdo->commit();
        balas(true, 'Pelatih berhasil dihapus.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[proses-pelatih] ' . $e->getMessage());
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        if (stripos($e->getMessage(), 'Duplicate') !== false) balas(false, 'Email sudah dipakai akun lain.', [], 409);
        balas(false, 'Data ini masih dipakai di tabel lain sehingga tidak bisa diubah/dihapus.', [], 409);
    }
    balas(false, PL_DEBUG ? 'Error server: ' . $e->getMessage() : 'Terjadi kesalahan pada server.', [], 500);
}