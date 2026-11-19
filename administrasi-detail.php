<?php
// ============================================================
// administrasi-detail.php — Detail Invoice: periksa bukti, verifikasi lunas / tolak, kirim WhatsApp
// Dibuka dari halaman Transaksi: administrasi-detail.php?id=<id tagihan>
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

// ─── FORMAT ───
function trRp($n): string { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }

function trJt($n): string   // 48750000 -> "Rp 48,75 jt"
{
    $n = (float)$n;
    if (abs($n) < 1000000) return trRp($n);
    return 'Rp ' . rtrim(rtrim(number_format($n / 1000000, 2, ',', '.'), '0'), ',') . ' jt';
}

function trJtPendek($n): string   // "Rp 38jt"
{
    return 'Rp ' . rtrim(rtrim(number_format((float)$n / 1000000, 1, ',', '.'), '0'), ',') . 'jt';
}

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

function trInisial(string $n): string
{
    $k = preg_split('/\s+/', trim($n));
    $s = mb_strtoupper(mb_substr($k[0] ?? '', 0, 1) . mb_substr($k[1] ?? '', 0, 1));
    return $s !== '' ? $s : '?';
}

function trCoach(string $n): string { return preg_match('/^coach\b/i', $n) ? $n : 'Coach ' . $n; }

function trNoWa(?string $no): string
{
    $d = preg_replace('/\D+/', '', (string)$no);
    if ($d === '') return '';
    if (strpos($d, '0') === 0) return '62' . substr($d, 1);
    if (strpos($d, '62') === 0) return $d;
    if (strpos($d, '8') === 0) return '62' . $d;
    return $d;
}

function trMetodeLabel(?string $m, ?string $bank = null): string
{
    if (!$m) return '-';
    if ($m === 'transfer' && $bank) return 'Transfer ' . $bank;
    return TR_METODE[$m] ?? $m;
}

// Status yang ditampilkan: dihitung dari status tagihan + ada tidaknya bukti yang menunggu verifikasi
// -> [kunci filter, label, kelas css]
function trStatus(string $st, ?string $jatuhTempo, int $menunggu): array
{
    if ($st === 'lunas') return ['lunas', 'Lunas', 'lunas'];
    if ($menunggu > 0)   return ['menunggu', 'Menunggu Verifikasi', 'menunggu'];
    if ($st === 'sebagian') return ['belum', 'Sebagian', 'sebagian'];
    if ($st === 'jatuh_tempo' || ($jatuhTempo && $jatuhTempo < date('Y-m-d'))) return ['belum', 'Jatuh Tempo', 'tempo'];
    return ['belum', 'Belum Bayar', 'belum'];
}

// ─── DATABASE ───
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

function trCekMetodeBaru(string $m): void
{
    if (!in_array($m, TR_METODE_BARU, true)) throw new TrException('Metode pembayaran harus Tunai atau Transfer.', 422);
}

// Migrasi otomatis: menambah kolom/tabel yang dibutuhkan halaman Administrasi kalau belum ada.
// (Sama dengan isi administrasi_migrasi.sql. Harus dipanggil SEBELUM beginTransaction karena DDL MySQL meng-commit.)
function trMigrasi(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $kolom = [
        ['tagihan', 'program_id', 'ALTER TABLE tagihan ADD COLUMN program_id INT NULL' . ($mysql ? ' AFTER paket_id' : '')],
        ['tagihan', 'diskon',     'ALTER TABLE tagihan ADD COLUMN diskon DECIMAL(12,2) NOT NULL DEFAULT 0'],
        ['tagihan', 'catatan',    'ALTER TABLE tagihan ADD COLUMN catatan VARCHAR(255) NULL'],
        ['pembayaran', 'bank',    'ALTER TABLE pembayaran ADD COLUMN bank VARCHAR(30) NULL'],
    ];
    $salah = [];
    foreach ($kolom as [$t, $c, $sql]) {
        if (trAda($pdo, $t, $c)) continue;
        try { $pdo->exec($sql); } catch (Throwable $e) { $salah[] = "$t.$c (" . $e->getMessage() . ')'; }
        trAda($pdo, $t, $c, true);
    }
    if (!trAda($pdo, 'bukti_pembayaran')) {
        $en = function (array $v) use ($mysql) { return $mysql ? "ENUM('" . implode("','", $v) . "')" : 'VARCHAR(20)'; };
        $isi = ($mysql ? 'id INT AUTO_INCREMENT PRIMARY KEY' : 'id INTEGER PRIMARY KEY AUTOINCREMENT') . ",
            tagihan_id INT NOT NULL, file_path VARCHAR(255) NOT NULL, nama_asli VARCHAR(150) NULL,
            metode " . $en(['tunai', 'transfer', 'qris', 'ewallet']) . " NOT NULL DEFAULT 'transfer', bank VARCHAR(30) NULL,
            sumber " . $en(['admin', 'member', 'ai_chat']) . " NOT NULL DEFAULT 'admin',
            status " . $en(['menunggu', 'diterima', 'ditolak']) . " NOT NULL DEFAULT 'menunggu',
            catatan VARCHAR(255) NULL, diunggah_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            diverifikasi_at DATETIME NULL, diverifikasi_oleh INT NULL";
        $fk = ", FOREIGN KEY (tagihan_id) REFERENCES tagihan(id) ON DELETE CASCADE, FOREIGN KEY (diverifikasi_oleh) REFERENCES users(id) ON DELETE SET NULL";
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS bukti_pembayaran ($isi" . ($mysql ? $fk : '') . ')' . ($mysql ? ' ENGINE=InnoDB' : '')); }
        catch (Throwable $e) {
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS bukti_pembayaran ($isi)" . ($mysql ? ' ENGINE=InnoDB' : '')); }   // tanpa foreign key
            catch (Throwable $e2) { $salah[] = 'tabel bukti_pembayaran (' . $e2->getMessage() . ')'; }
        }
        trAda($pdo, 'bukti_pembayaran', null, true);
    }
    if (!trAda($pdo, 'tagihan', 'program_id') || !trAda($pdo, 'tagihan', 'diskon') || !trAda($pdo, 'tagihan', 'catatan') || !trAda($pdo, 'bukti_pembayaran')) {
        throw new TrException('Migrasi otomatis gagal' . ($salah ? ': ' . implode('; ', $salah) : '') . '. Jalankan administrasi_migrasi.sql secara manual dengan user database yang punya hak ALTER/CREATE.', 500);
    }
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

// Ekspresi SQL yang dipakai di beberapa query (alias: t=tagihan, pm=paket_member)
function trSqlMenunggu(PDO $pdo): string
{
    return trAda($pdo, 'bukti_pembayaran')
        ? "(SELECT COUNT(*) FROM bukti_pembayaran bx WHERE bx.tagihan_id = t.id AND bx.status = 'menunggu')" : '0';
}
function trSqlProgram(PDO $pdo): string
{
    return trAda($pdo, 'tagihan', 'program_id') ? 'COALESCE(t.program_id, pm.program_id)' : 'pm.program_id';
}
function trSqlMetode(PDO $pdo): string
{
    $e = "(SELECT px.metode FROM pembayaran px WHERE px.tagihan_id = t.id ORDER BY px.id DESC LIMIT 1)";
    if (trAda($pdo, 'bukti_pembayaran')) $e = "COALESCE($e, (SELECT bm.metode FROM bukti_pembayaran bm WHERE bm.tagihan_id = t.id ORDER BY bm.id DESC LIMIT 1))";
    return $e;
}
function trSqlBank(PDO $pdo): string
{
    $e = trAda($pdo, 'pembayaran', 'bank') ? "(SELECT pb.bank FROM pembayaran pb WHERE pb.tagihan_id = t.id ORDER BY pb.id DESC LIMIT 1)" : 'NULL';
    if (trAda($pdo, 'bukti_pembayaran')) $e = "COALESCE($e, (SELECT bb.bank FROM bukti_pembayaran bb WHERE bb.tagihan_id = t.id ORDER BY bb.id DESC LIMIT 1))";
    return $e;
}

function trNomorBaru(PDO $pdo, int $geser = 0): string
{
    $pre = 'INV-' . date('ym') . '-';
    $st = $pdo->prepare("SELECT nomor_tagihan FROM tagihan WHERE nomor_tagihan LIKE :p");
    $st->execute([':p' => $pre . '%']);
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) $max = max($max, (int)substr($n, strlen($pre)));
    return $pre . str_pad((string)($max + 1 + $geser), 3, '0', STR_PAD_LEFT);
}

// Total pendapatan 1 bulan: catatan laporan_keuangan kalau ada, kalau tidak dijumlahkan dari pembayaran
function trTotalBulan(PDO $pdo, int $th, int $bl): float
{
    if (trAda($pdo, 'laporan_keuangan')) {
        $st = $pdo->prepare("SELECT total_pendapatan FROM laporan_keuangan WHERE tahun = :t AND bulan = :b");
        $st->execute([':t' => $th, ':b' => $bl]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (float)$v;
    }
    return trSumPembayaran($pdo, $th, $bl);
}

function trSumPembayaran(PDO $pdo, int $th, int $bl): float
{
    $a = sprintf('%04d-%02d-01 00:00:00', $th, $bl);
    $b = date('Y-m-d 00:00:00', strtotime($a . ' +1 month'));
    $st = $pdo->prepare("SELECT COALESCE(SUM(jumlah), 0) FROM pembayaran WHERE tanggal_bayar >= :a AND tanggal_bayar < :b");
    $st->execute([':a' => $a, ':b' => $b]);
    return (float)$st->fetchColumn();
}

// Tambahkan pembayaran baru ke catatan bulanan laporan_keuangan (supaya grafik dashboard ikut naik)
function trTambahLaporan(PDO $pdo, float $jumlah, string $tgl, ?int $uid): void
{
    if (!trAda($pdo, 'laporan_keuangan')) return;
    $bl = (int)date('n', strtotime($tgl)); $th = (int)date('Y', strtotime($tgl));
    $st = $pdo->prepare("SELECT id FROM laporan_keuangan WHERE bulan = :b AND tahun = :t" . trKunci($pdo));
    $st->execute([':b' => $bl, ':t' => $th]);
    $id = $st->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE laporan_keuangan SET total_pendapatan = total_pendapatan + :j WHERE id = :id")
            ->execute([':j' => $jumlah, ':id' => $id]);
    } else {   // bulan baru: pembayaran barusan sudah tercatat, jadi cukup jumlahkan dari tabel pembayaran
        $pdo->prepare("INSERT INTO laporan_keuangan (bulan, tahun, total_pendapatan, dibuat_oleh) VALUES (:b, :t, :j, :u)")
            ->execute([':b' => $bl, ':t' => $th, ':j' => trSumPembayaran($pdo, $th, $bl), ':u' => $uid]);
    }
}

// ─── BUKTI TRANSFER ───
function trSimpanBukti(array $f, int $tid): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new TrException('Upload bukti gagal (kode ' . ($f['error'] ?? '?') . ').', 422);
    if ((int)$f['size'] > 5 * 1024 * 1024) throw new TrException('Ukuran bukti maksimal 5 MB.', 422);
    $boleh = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext = $boleh[$mime] ?? null;
    } else {
        $e = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $ext = $e === 'jpeg' ? 'jpg' : (in_array($e, $boleh, true) ? $e : null);
    }
    if (!$ext) throw new TrException('Format bukti harus JPG, PNG, WEBP, atau PDF.', 422);

    $dir = __DIR__ . '/uploads/bukti';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new TrException('Folder uploads/bukti tidak bisa dibuat. Periksa izin folder.', 500);
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");

    $nama = 'bukti-' . $tid . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $ok = is_uploaded_file($f['tmp_name']) ? move_uploaded_file($f['tmp_name'], $dir . '/' . $nama)
        : (PHP_SAPI === 'cli' ? copy($f['tmp_name'], $dir . '/' . $nama) : false);
    if (!$ok) throw new TrException('Bukti gagal disimpan. Periksa izin folder uploads/bukti.', 500);
    return [$nama, mb_substr(basename((string)$f['name']), 0, 150)];
}

// Hapus invoice yang belum dibayar sama sekali (jalankan di dalam transaksi). Mengembalikan file bukti yang harus dihapus setelah commit.
function trHapusTagihan(PDO $pdo, int $tid): array
{
    $st = $pdo->prepare("SELECT id, nomor_tagihan, status FROM tagihan WHERE id = :id" . trKunci($pdo));
    $st->execute([':id' => $tid]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new TrException('Invoice tidak ditemukan.', 404);
    if ($t['status'] === 'lunas') throw new TrException('Invoice yang sudah lunas tidak bisa dihapus.', 409);
    $st = $pdo->prepare("SELECT COUNT(*) FROM pembayaran WHERE tagihan_id = :id");
    $st->execute([':id' => $tid]);
    if ((int)$st->fetchColumn() > 0) throw new TrException('Invoice ini sudah punya pembayaran tercatat, jadi tidak bisa dihapus.', 409);

    $files = [];
    if (trAda($pdo, 'bukti_pembayaran')) {
        $st = $pdo->prepare("SELECT file_path FROM bukti_pembayaran WHERE tagihan_id = :id");
        $st->execute([':id' => $tid]);
        $files = $st->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare("DELETE FROM bukti_pembayaran WHERE tagihan_id = :id")->execute([':id' => $tid]);
    }
    $pdo->prepare("DELETE FROM tagihan WHERE id = :id")->execute([':id' => $tid]);
    return ['nomor' => $t['nomor_tagihan'], 'files' => $files];
}

function trHapusBerkas(array $files): void
{
    foreach ($files as $f) { $p = __DIR__ . '/uploads/bukti/' . basename((string)$f); if (is_file($p)) @unlink($p); }
}

// ─── INTI: TERIMA PEMBAYARAN (jalankan di dalam transaksi) ───
// Mencatat pembayaran untuk sisa tagihan -> trigger MySQL menandai lunas + menerbitkan kuitansi;
// kalau triggernya tidak ada, dikerjakan di sini. Paket member dibuat/aktif otomatis.
function trTerimaBayar(PDO $pdo, int $tid, string $metode, ?string $bank, ?int $uid, ?string $tglBayar = null): array
{
    $bank = $bank !== null && trim($bank) !== '' ? mb_substr(trim($bank), 0, 30) : null;
    $tgl  = $tglBayar ?: date('Y-m-d H:i:s');

    $st = $pdo->prepare("SELECT id, member_id, paket_id, jumlah, status FROM tagihan WHERE id = :id" . trKunci($pdo));
    $st->execute([':id' => $tid]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new TrException('Tagihan tidak ditemukan.', 404);
    if ($t['status'] === 'lunas') throw new TrException('Tagihan ini sudah lunas.', 409);
    if (!isset(TR_METODE[$metode])) throw new TrException($metode === '' ? 'Pilih metode pembayaran.' : 'Metode pembayaran tidak valid.', 422);

    $st = $pdo->prepare("SELECT COALESCE(SUM(jumlah), 0) FROM pembayaran WHERE tagihan_id = :id");
    $st->execute([':id' => $tid]);
    $sisa = round((float)$t['jumlah'] - (float)$st->fetchColumn(), 2);

    $pid = null; $kuitansi = null;
    if ($sisa > 0) {
        $kol = ['tagihan_id', 'jumlah', 'metode', 'tanggal_bayar', 'dicatat_oleh'];
        $val = [':t' => $tid, ':j' => $sisa, ':m' => $metode, ':d' => $tgl, ':u' => $uid];
        $ph  = [':t', ':j', ':m', ':d', ':u'];
        if (trAda($pdo, 'pembayaran', 'bank')) { $kol[] = 'bank'; $ph[] = ':b'; $val[':b'] = $bank; }
        $pdo->prepare('INSERT INTO pembayaran (' . implode(',', $kol) . ') VALUES (' . implode(',', $ph) . ')')->execute($val);
        $pid = (int)$pdo->lastInsertId();
    }

    // Pastikan status lunas (kalau trigger trg_pembayaran_status tidak ada)
    $st = $pdo->prepare("SELECT status FROM tagihan WHERE id = :id");
    $st->execute([':id' => $tid]);
    if ($st->fetchColumn() !== 'lunas') $pdo->prepare("UPDATE tagihan SET status = 'lunas' WHERE id = :id")->execute([':id' => $tid]);

    // Pastikan kuitansi terbit (kalau trigger trg_pembayaran_kuitansi tidak ada)
    if ($pid && trAda($pdo, 'kuitansi')) {
        $st = $pdo->prepare("SELECT nomor_kuitansi FROM kuitansi WHERE pembayaran_id = :p");
        $st->execute([':p' => $pid]);
        $kuitansi = $st->fetchColumn() ?: null;
        if (!$kuitansi) {
            $kuitansi = 'KW-' . date('Ym') . '-' . str_pad((string)$pid, 5, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO kuitansi (pembayaran_id, nomor_kuitansi) VALUES (:p, :n)")->execute([':p' => $pid, ':n' => $kuitansi]);
        }
    }

    // Paket: kalau tagihan dibuat dari halaman Transaksi (punya program_id, belum punya paket) -> buat paket baru
    $paketBaru = null;
    if (empty($t['paket_id']) && trAda($pdo, 'tagihan', 'program_id')) {
        $st = $pdo->prepare("SELECT t.program_id, kp.jumlah_sesi FROM tagihan t JOIN kelas_program kp ON kp.id = t.program_id WHERE t.id = :id");
        $st->execute([':id' => $tid]);
        if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
            $mulai = date('Y-m-d', strtotime($tgl));
            $pdo->prepare("INSERT INTO paket_member (member_id, program_id, total_sesi, sisa_sesi, tanggal_mulai, tanggal_berakhir, status)
                           VALUES (:m, :p, :s, :s2, :a, :b, 'aktif')")
                ->execute([':m' => $t['member_id'], ':p' => $p['program_id'], ':s' => $p['jumlah_sesi'], ':s2' => $p['jumlah_sesi'],
                           ':a' => $mulai, ':b' => date('Y-m-d', strtotime($mulai . ' +' . TR_MASA_BERLAKU . ' days'))]);
            $paketBaru = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE tagihan SET paket_id = :p WHERE id = :id")->execute([':p' => $paketBaru, ':id' => $tid]);
        }
    }

    elseif (!empty($t['paket_id'])) {   // paket sudah dibuat sebelum bayar (sisa 0, belum pernah dipakai) -> aktifkan
        $st = $pdo->prepare("SELECT total_sesi, sisa_sesi, status FROM paket_member WHERE id = :id");
        $st->execute([':id' => $t['paket_id']]);
        $pk = $st->fetch(PDO::FETCH_ASSOC);
        if ($pk && (int)$pk['sisa_sesi'] === 0 && $pk['status'] === 'habis' && (int)$pk['total_sesi'] > 0) {
            $pakai = 0;
            if (trAda($pdo, 'presensi')) { $q = $pdo->prepare("SELECT COUNT(*) FROM presensi WHERE paket_id = :p"); $q->execute([':p' => $t['paket_id']]); $pakai = (int)$q->fetchColumn(); }
            if (!$pakai) {
                $mulai = date('Y-m-d', strtotime($tgl));
                $pdo->prepare("UPDATE paket_member SET sisa_sesi = total_sesi, status = 'aktif', tanggal_mulai = :a, tanggal_berakhir = :b WHERE id = :id")
                    ->execute([':a' => $mulai, ':b' => date('Y-m-d', strtotime($mulai . ' +' . TR_MASA_BERLAKU . ' days')), ':id' => $t['paket_id']]);
                $paketBaru = (int)$t['paket_id'];
            }
        }
    }

    if (trAda($pdo, 'bukti_pembayaran')) {
        $pdo->prepare("UPDATE bukti_pembayaran SET status = 'diterima', diverifikasi_at = :w, diverifikasi_oleh = :u
                        WHERE tagihan_id = :t AND status = 'menunggu'")
            ->execute([':w' => date('Y-m-d H:i:s'), ':u' => $uid, ':t' => $tid]);
    }
    if ($sisa > 0) trTambahLaporan($pdo, $sisa, $tgl, $uid);

    return ['pembayaran_id' => $pid, 'kuitansi' => $kuitansi, 'paket_baru' => $paketBaru, 'jumlah' => $sisa];
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ============================================================
// ENDPOINT AJAX
// ============================================================
if ($trAjax) {
    $aksi = $_GET['aksi'];
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (in_array($aksi, ['verifikasi', 'tolak', 'unggah', 'hapus'], true) && !$isPost) trOut(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);

    try {
        switch ($aksi) {

            // ── Tampilkan file bukti ──
            case 'bukti': {
                $st = $pdo->prepare("SELECT file_path FROM bukti_pembayaran WHERE id = :id");
                $st->execute([':id' => (int)($_GET['id'] ?? 0)]);
                $f = basename((string)$st->fetchColumn());
                $path = __DIR__ . '/uploads/bukti/' . $f;
                if ($f === '' || !is_file($path)) { while (ob_get_level()) ob_end_clean(); http_response_code(404); echo 'Bukti tidak ditemukan.'; exit; }
                $tipe = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'][strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
                while (ob_get_level()) ob_end_clean();
                header('Content-Type: ' . $tipe);
                header('Content-Length: ' . filesize($path));
                header('X-Content-Type-Options: nosniff');
                header('Content-Disposition: inline; filename="' . $f . '"');
                readfile($path);
                exit;
            }

            // ── Verifikasi bukti / catat lunas ──
            case 'verifikasi': {
                $tid = (int)($in['id'] ?? 0);
                $uid = trUserId($pdo);
                trMigrasi($pdo);
                $pdo->beginTransaction();
                $metode = (string)($in['metode'] ?? ''); $bank = (string)($in['bank'] ?? '');
                if (trAda($pdo, 'bukti_pembayaran')) {
                    $st = $pdo->prepare("SELECT metode, bank FROM bukti_pembayaran WHERE tagihan_id = :t AND status = 'menunggu' ORDER BY id DESC LIMIT 1");
                    $st->execute([':t' => $tid]);
                    if ($b = $st->fetch(PDO::FETCH_ASSOC)) { $metode = $b['metode']; $bank = (string)$b['bank']; $dariBukti = true; }
                }
                if (empty($dariBukti) && $metode !== '') trCekMetodeBaru($metode);
                $info = trTerimaBayar($pdo, $tid, $metode, $bank, $uid);
                $pdo->commit();
                $pesan = 'Pembayaran ' . trRp($info['jumlah']) . ' dicatat, tagihan lunas.'
                       . ($info['kuitansi'] ? ' Kuitansi ' . $info['kuitansi'] . '.' : '') . ($info['paket_baru'] ? ' Paket member aktif.' : '');
                trOut(['ok' => true, 'pesan' => $pesan]);
            }

            // ── Tolak bukti ──
            case 'tolak': {
                $tid = (int)($in['id'] ?? 0);
                $alasan = trim((string)($in['alasan'] ?? ''));
                if (mb_strlen($alasan) < 3) throw new TrException('Tulis alasan penolakan (minimal 3 karakter).', 422);
                if (mb_strlen($alasan) > 255) throw new TrException('Alasan maksimal 255 karakter.', 422);
                trMigrasi($pdo);
                $uid = trUserId($pdo);
                $st = $pdo->prepare("UPDATE bukti_pembayaran SET status = 'ditolak', catatan = :c, diverifikasi_at = :w, diverifikasi_oleh = :u
                                      WHERE tagihan_id = :t AND status = 'menunggu'");
                $st->execute([':c' => $alasan, ':w' => date('Y-m-d H:i:s'), ':u' => $uid, ':t' => $tid]);
                if (!$st->rowCount()) throw new TrException('Tidak ada bukti yang menunggu verifikasi.', 409);
                trOut(['ok' => true, 'pesan' => 'Bukti ditolak. Tagihan tetap belum lunas.']);
            }

            // ── Unggah bukti oleh admin ──
            case 'unggah': {
                trMigrasi($pdo);
                $tid = (int)($in['id'] ?? 0);
                $metode = (string)($in['metode'] ?? 'transfer');
                $bank = $metode === 'transfer' ? trim((string)($in['bank'] ?? '')) : '';
                trCekMetodeBaru($metode);
                $f = $_FILES['bukti'] ?? null;
                if (!$f || ($f['error'] ?? 4) === UPLOAD_ERR_NO_FILE) throw new TrException('Pilih file bukti terlebih dahulu.', 422);

                $st = $pdo->prepare("SELECT status FROM tagihan WHERE id = :id"); $st->execute([':id' => $tid]);
                $sts = $st->fetchColumn();
                if ($sts === false) throw new TrException('Tagihan tidak ditemukan.', 404);
                if ($sts === 'lunas') throw new TrException('Tagihan ini sudah lunas.', 409);
                $st = $pdo->prepare("SELECT COUNT(*) FROM bukti_pembayaran WHERE tagihan_id = :t AND status = 'menunggu'"); $st->execute([':t' => $tid]);
                if ((int)$st->fetchColumn() > 0) throw new TrException('Sudah ada bukti yang menunggu verifikasi.', 409);

                [$file, $asli] = trSimpanBukti($f, $tid);
                try {
                    $pdo->prepare("INSERT INTO bukti_pembayaran (tagihan_id, file_path, nama_asli, metode, bank, sumber, status) VALUES (:t, :f, :a, :m, :b, 'admin', 'menunggu')")
                        ->execute([':t' => $tid, ':f' => $file, ':a' => $asli, ':m' => $metode, ':b' => $bank !== '' ? $bank : null]);
                } catch (Throwable $e) { @unlink(__DIR__ . '/uploads/bukti/' . $file); throw $e; }
                trOut(['ok' => true, 'pesan' => 'Bukti diunggah, menunggu verifikasi.']);
            }

            case 'hapus': {
                $tid = (int)($in['id'] ?? 0);
                $pdo->beginTransaction();
                $h = trHapusTagihan($pdo, $tid);
                $pdo->commit();
                trHapusBerkas($h['files']);
                trOut(['ok' => true, 'pesan' => 'Invoice ' . $h['nomor'] . ' berhasil dihapus.']);
            }

            default:
                trOut(['ok' => false, 'pesan' => 'Aksi tidak dikenal.'], 400);
        }
    } catch (TrException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        trOut(['ok' => false, 'pesan' => $e->getMessage()], $e->getCode() >= 400 ? $e->getCode() : 400);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[administrasi-detail] ' . $e->getMessage());
        trOut(['ok' => false, 'pesan' => trPesanError($e)], 500);
    }
    exit;
}

// ============================================================
// DATA HALAMAN
// ============================================================
$err = ''; $t = null; $bukti = []; $rincian = [];
try {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT t.*, m.nama AS member, m.kode_member, m.no_whatsapp, kp.nama_program, kp.jumlah_sesi,
                                pm.total_sesi, pm.sisa_sesi AS paket_sisa, pm.status AS paket_status, pm.tanggal_mulai, pm.tanggal_berakhir
                           FROM tagihan t
                           JOIN members m ON m.id = t.member_id
                           LEFT JOIN paket_member pm ON pm.id = t.paket_id
                           LEFT JOIN kelas_program kp ON kp.id = " . trSqlProgram($pdo) . "
                          WHERE t.id = :id");
    $st->execute([':id' => $id]);
    $t = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($t) {
        $bukti = [];
        if (trAda($pdo, 'bukti_pembayaran')) {
            $st = $pdo->prepare("SELECT b.*, u.nama AS verifikator FROM bukti_pembayaran b LEFT JOIN users u ON u.id = b.diverifikasi_oleh WHERE b.tagihan_id = :t ORDER BY b.id DESC");
            $st->execute([':t' => $id]);
            $bukti = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $bayarBank = trAda($pdo, 'pembayaran', 'bank') ? 'p.bank' : 'NULL';
        $st = $pdo->prepare("SELECT p.id, p.jumlah, p.metode, $bayarBank AS bank, p.tanggal_bayar, u.nama AS oleh, k.nomor_kuitansi
                               FROM pembayaran p
                               LEFT JOIN users u ON u.id = p.dicatat_oleh
                               LEFT JOIN kuitansi k ON k.pembayaran_id = p.id
                              WHERE p.tagihan_id = :t ORDER BY p.id");
        $st->execute([':t' => $id]);
        $bayar = $st->fetchAll(PDO::FETCH_ASSOC);

        $menunggu = null;
        foreach ($bukti as $b) if ($b['status'] === 'menunggu') { $menunggu = $b; break; }
        $dibayar = array_sum(array_map(function ($p) { return (float)$p['jumlah']; }, $bayar));
        [$kunci, $label, $css] = trStatus($t['status'], $t['jatuh_tempo'], $menunggu ? 1 : 0);

        // Metode: pembayaran terakhir > bukti terakhir
        $metode = $bayar ? trMetodeLabel(end($bayar)['metode'], end($bayar)['bank']) : ($bukti ? trMetodeLabel($bukti[0]['metode'], $bukti[0]['bank']) : '-');

        // Pelatih: dari kelas member sendiri, kalau tidak ada dari kelas program yang sama
        $pelatih = '-';
        if (!empty($t['program_id']) || !empty($t['paket_id'])) {
            $pid = (int)($t['program_id'] ?? 0);
            if (!$pid && !empty($t['paket_id'])) { $q = $pdo->prepare("SELECT program_id FROM paket_member WHERE id = :i"); $q->execute([':i' => $t['paket_id']]); $pid = (int)$q->fetchColumn(); }
            if ($pid) {
                $q = $pdo->prepare("SELECT u.nama FROM jadwal j JOIN jadwal_peserta jp ON jp.jadwal_id = j.id JOIN pelatih pl ON pl.id = j.pelatih_id JOIN users u ON u.id = pl.user_id
                                     WHERE jp.member_id = :m AND j.program_id = :p ORDER BY j.tanggal DESC LIMIT 1");
                $q->execute([':m' => $t['member_id'], ':p' => $pid]);
                $n = $q->fetchColumn();
                if (!$n) {
                    $q = $pdo->prepare("SELECT u.nama FROM jadwal j JOIN pelatih pl ON pl.id = j.pelatih_id JOIN users u ON u.id = pl.user_id WHERE j.program_id = :p ORDER BY j.tanggal DESC LIMIT 1");
                    $q->execute([':p' => $pid]);
                    $n = $q->fetchColumn();
                }
                if ($n) $pelatih = trCoach($n);
            }
        }

        // Paket
        $sesi = (int)($t['total_sesi'] ?: $t['jumlah_sesi']);
        $hari = TR_MASA_BERLAKU;
        if ($t['tanggal_mulai'] && $t['tanggal_berakhir']) $hari = max(1, (int)round((strtotime($t['tanggal_berakhir']) - strtotime($t['tanggal_mulai'])) / 86400));
        $paketTxt = $sesi ? $sesi . ' sesi · berlaku ' . $hari . ' hari' : '-';
        $program = $t['nama_program'] ?: '-';
        $kuotaBaru = false;   // apakah kuota sesi akan ditambahkan saat lunas
        if (empty($t['paket_id'])) $kuotaBaru = true;
        elseif ((int)$t['paket_sisa'] === 0 && $t['paket_status'] === 'habis') {
            $kuotaBaru = !trAda($pdo, 'presensi') || !(int)$pdo->query("SELECT COUNT(*) FROM presensi WHERE paket_id = " . (int)$t['paket_id'])->fetchColumn();
        }

        $diskon = (float)($t['diskon'] ?? 0);
        $total = (float)$t['jumlah'];
        $subtotal = $total + $diskon;

        // Riwayat status (disusun dari data yang ada)
        $sumber = ['admin' => 'Admin', 'member' => 'Member', 'ai_chat' => 'AI Assistant'];
        $riwayat = [['t' => $t['created_at'], 'judul' => 'Invoice dibuat', 'ket' => trTglJam($t['created_at']), 'tipe' => 'muted']];
        foreach ($bukti as $b) {
            $riwayat[] = ['t' => $b['diunggah_at'], 'judul' => 'Bukti pembayaran diterima', 'ket' => trTglJam($b['diunggah_at']) . ' · via ' . ($sumber[$b['sumber']] ?? $b['sumber']), 'tipe' => 'info'];
            if ($b['status'] === 'ditolak') $riwayat[] = ['t' => $b['diverifikasi_at'], 'judul' => 'Bukti ditolak', 'ket' => trTglJam($b['diverifikasi_at']) . ($b['catatan'] ? ' · ' . $b['catatan'] : ''), 'tipe' => 'bad'];
        }
        foreach ($bayar as $p) {
            $riwayat[] = ['t' => $p['tanggal_bayar'], 'judul' => 'Pembayaran ' . trRp($p['jumlah']) . ' diterima (' . trMetodeLabel($p['metode'], $p['bank']) . ')',
                          'ket' => trTglJam($p['tanggal_bayar']) . ($p['oleh'] ? ' · ' . $p['oleh'] : '') . ($p['nomor_kuitansi'] ? ' · ' . $p['nomor_kuitansi'] : ''), 'tipe' => 'ok'];
        }
        usort($riwayat, function ($a, $b) { return strcmp((string)$b['t'], (string)$a['t']); });

        // Pesan WhatsApp
        $nm = $t['member'];
        if ($kunci === 'lunas')         $pesanWa = "Halo $nm, pembayaran invoice {$t['nomor_tagihan']} sebesar " . trRp($total) . " sudah kami terima (LUNAS). Terima kasih! - Natix Swim";
        elseif ($kunci === 'menunggu')  $pesanWa = "Halo $nm, bukti pembayaran invoice {$t['nomor_tagihan']} sebesar " . trRp($total) . " sudah kami terima dan sedang diverifikasi. Terima kasih! - Natix Swim";
        else                            $pesanWa = "Halo $nm, berikut tagihan {$t['nomor_tagihan']} untuk $program sebesar " . trRp($total - $dibayar) . ", jatuh tempo " . trTgl($t['jatuh_tempo']) . ". Mohon segera melakukan pembayaran. Terima kasih - Natix Swim";
        $bisaHapus = $kunci !== 'lunas' && $dibayar <= 0;
        $rincian = [['Invoice', $t['nomor_tagihan']], ['Member', $t['member']], ['Total', trRp($total)], ['Metode', $metode]];
        $wa = trNoWa($t['no_whatsapp']);
        $linkWa = $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($pesanWa) : '';
    }
} catch (Throwable $e) {
    $err = TR_DEBUG ? $e->getMessage() : 'Terjadi kesalahan pada server.';
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
    <title>Detail Invoice — Natix Swim</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/reset.css" />
    <link rel="stylesheet" href="css/global.css" />
    <link rel="stylesheet" href="css/administrasi-detail.css" />
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
        .dv-grid { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 16px; margin-top: 24px; align-items: start; }
        .dv-inv-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
        .dv-lbl { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #9ca3af; }
        .dv-no { font-size: 24px; font-weight: 700; margin-top: 4px !important; }
        .dv-meta { font-size: 13px; color: #9ca3af; margin-top: 4px !important; }
        .dv-info { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px 20px; margin-top: 22px; padding-top: 22px; border-top: 1px solid #f1f2f5; }
        .dv-info .k { font-size: 12px; color: #9ca3af; }
        .dv-info .v { font-size: 14px; font-weight: 600; color: #111827; margin-top: 3px !important; word-break: break-word; }
        .dv-info .s { font-size: 12px; color: #9ca3af; margin-top: 2px !important; }
        .dv-table { width: 100%; border-collapse: collapse; margin-top: 22px; }
        .dv-table th { text-align: left; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #9ca3af; padding: 10px 12px; background: #fafafb; border-bottom: 1px solid #eceef2; }
        .dv-table td { padding: 14px 12px; font-size: 14px; border-bottom: 1px solid #f1f2f5; }
        .dv-table .r { text-align: right; }
        .dv-sum { margin-top: 16px; margin-left: auto; max-width: 320px; }
        .dv-sum div { display: flex; justify-content: space-between; gap: 12px; font-size: 14px; color: #4b5563; padding: 5px 0; }
        .dv-sum .tot { border-top: 1px solid #e5e7eb; margin-top: 8px; padding-top: 12px; font-size: 17px; font-weight: 700; color: #111827; }
        .dv-bukti { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; margin-top: 16px; border: 1px dashed #d1d5db; border-radius: 12px; background: #f9fafb; padding: 14px; min-height: 140px; text-align: center; color: #6b7280; font-size: 13px; cursor: zoom-in; }
        .dv-bukti img { max-width: 100%; max-height: 260px; border-radius: 8px; }
        .dv-bk { margin-top: 16px; padding-top: 14px; border-top: 1px solid #f1f2f5; }
        .dv-bk:first-of-type { border-top: 0; padding-top: 0; }
        .dv-bk-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
        .dv-bk-meta { font-size: 12px; color: #9ca3af; }
        .dv-bk .dv-bukti { margin-top: 10px; }
        .dv-bk-info { font-size: 12px; color: #6b7280; margin-top: 8px !important; line-height: 1.5; }
        .dv-bk-open { color: #2563eb; }
        .dv-note { margin-top: 14px; background: #eff4ff; color: #1e3a8a; border-radius: 10px; padding: 11px 14px; font-size: 13px; line-height: 1.5; }
        .dv-note.warn { background: #fffbeb; color: #92400e; }
        .dv-note.ok { background: #f0fdf4; color: #166534; }
        .dv-btns { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
        .dv-btns .ad-btn { flex: 1; }
        .dv-sub { font-size: 13px; font-weight: 600; color: #374151; margin-top: 20px !important; padding-top: 16px; border-top: 1px solid #f1f2f5; }
        .dv-tl { margin-top: 16px; display: flex; flex-direction: column; }
        .dv-tl-i { position: relative; padding: 0 0 18px 26px; }
        .dv-tl-i:before { content: ''; position: absolute; left: 5px; top: 14px; bottom: 0; width: 2px; background: #e5e7eb; }
        .dv-tl-i:last-child { padding-bottom: 0; } .dv-tl-i:last-child:before { display: none; }
        .dv-dot { position: absolute; left: 0; top: 3px; width: 12px; height: 12px; border-radius: 50%; background: #d1d5db; }
        .dv-dot.ok { background: #16a34a; } .dv-dot.info { background: #2563eb; } .dv-dot.bad { background: #dc2626; }
        .dv-tl-i b { display: block; font-size: 14px; color: #111827; }
        .dv-tl-i span { display: block; font-size: 12px; color: #9ca3af; margin-top: 2px; }
        .dv-lightbox { position: fixed; inset: 0; background: rgba(0,0,0,.8); display: none; align-items: center; justify-content: center; z-index: 1800; padding: 20px; cursor: zoom-out; }
        .dv-lightbox.show { display: flex; } .dv-lightbox img { max-width: 100%; max-height: 100%; border-radius: 8px; }
        @media (max-width: 1100px) { .dv-grid { grid-template-columns: 1fr; } }
        @media (max-width: 640px) { .dv-info { grid-template-columns: 1fr 1fr; } }
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

@media (max-width: 760px) { .dv-table { display: block; overflow-x: auto; white-space: nowrap; } }
</style>
</head>

<body>
	<?php nvSidebar(); ?>

    <div class="main">
        <header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div><input type="hidden" id="dv-q" value=""></header>

<?php if ($err): ?>
        <div class="ad-error"><b>Gagal memuat invoice:</b> <?= h($err) ?></div>
<?php elseif (!$t): ?>
        <div class="ad-page">
            <?= nvCrumb([['Invoice tidak ditemukan']]) ?>
            <h1 class="ad-title" style="margin-top:6px">Invoice tidak ditemukan</h1>
            <p class="ad-sub">Tagihan dengan ID tersebut tidak ada.</p>
            <p style="margin-top:20px"><a class="ad-btn" href="administrasi.php">← Kembali ke Transaksi</a></p>
        </div>
<?php else: ?>
        <div class="ad-page">
            <?= nvCrumb([[$t['nomor_tagihan']]]) ?>
            <div class="ad-head">
                <div>
                    <h1 class="ad-title">Detail Invoice</h1>
                    <p class="ad-sub"><?= $kunci === 'lunas' ? 'Pembayaran sudah lunas' : 'Periksa bukti transfer sebelum verifikasi' ?></p>
                </div>
                <div class="ad-actions">
<?php if ($bisaHapus): ?>
                    <button type="button" class="ad-btn danger ad-noprint" id="dv-hapus">Hapus Invoice</button>
<?php endif; ?>
                    <button type="button" class="ad-btn" onclick="window.print()">Export</button>
<?php if ($linkWa): ?>
                    <a class="ad-btn primary" href="<?= h($linkWa) ?>" target="_blank" rel="noopener">Kirim via WhatsApp</a>
<?php else: ?>
                    <button type="button" class="ad-btn primary" disabled title="Member belum punya nomor WhatsApp">Kirim via WhatsApp</button>
<?php endif; ?>
                </div>
            </div>

            <div class="dv-grid">
                <!-- Kolom kiri: invoice -->
                <div class="ad-card ad-card-pad">
                    <div class="dv-inv-head">
                        <div>
                            <p class="dv-lbl">No. Invoice</p>
                            <p class="dv-no"><?= h($t['nomor_tagihan']) ?></p>
                            <p class="dv-meta">Diterbitkan <?= h(trTgl($t['created_at'])) ?> · Jatuh tempo <?= h(trTgl($t['jatuh_tempo'])) ?></p>
                        </div>
                        <span class="ad-chip <?= h($css) ?>"><?= h($label) ?></span>
                    </div>

                    <div class="dv-info">
                        <div><p class="k">Member</p><p class="v"><?= h($t['member']) ?></p><p class="s"><?= h($t['kode_member']) ?></p></div>
                        <div><p class="k">No. WhatsApp</p><p class="v"><?= h($t['no_whatsapp'] ?: '-') ?></p></div>
                        <div><p class="k">Program</p><p class="v"><?= h($program) ?></p></div>
                        <div><p class="k">Pelatih</p><p class="v"><?= h($pelatih) ?></p></div>
                        <div><p class="k">Paket</p><p class="v"><?= h($paketTxt) ?></p></div>
                        <div><p class="k">Metode Pembayaran</p><p class="v"><?= h($metode) ?></p></div>
                    </div>

                    <table class="dv-table">
                        <thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Harga</th><th class="r">Subtotal</th></tr></thead>
                        <tbody><tr>
                            <td>Paket <?= h($program) ?><?= $sesi ? ' (' . $sesi . ' sesi)' : '' ?></td>
                            <td class="r">1</td><td class="r"><?= h(trRp($subtotal)) ?></td><td class="r"><?= h(trRp($subtotal)) ?></td>
                        </tr></tbody>
                    </table>
                    <div class="dv-sum">
                        <div><span>Subtotal</span><span><?= h(trRp($subtotal)) ?></span></div>
                        <div><span>Diskon</span><span><?= $diskon > 0 ? '− ' . h(trRp($diskon)) : h(trRp(0)) ?></span></div>
                        <div class="tot"><span>Total</span><span><?= h(trRp($total)) ?></span></div>
<?php if ($dibayar > 0 && $kunci !== 'lunas'): ?>
                        <div><span>Sudah dibayar</span><span><?= h(trRp($dibayar)) ?></span></div>
                        <div><span>Sisa tagihan</span><span><?= h(trRp($total - $dibayar)) ?></span></div>
<?php endif; ?>
                    </div>
                </div>

                <!-- Kolom kanan: bukti + riwayat -->
                <div style="display:flex;flex-direction:column;gap:16px;min-width:0">
                    <div class="ad-card ad-card-pad" id="verifikasi">
                        <span id="bukti"></span>
                        <p class="ad-card-title">Bukti Pembayaran<?= $bukti ? ' (' . count($bukti) . ')' : '' ?></p>
<?php if (!$bukti): ?>
                        <p class="ad-card-sub">Belum ada bukti pembayaran yang diunggah</p>
                        <div class="dv-bukti" style="cursor:default"><b>Belum ada bukti</b><span>Bukti yang diunggah akan tampil di sini.</span></div>
<?php else: ?>
                        <p class="ad-card-sub">Klik gambar untuk memperbesar</p>
<?php foreach ($bukti as $bk):
    $ext = strtolower(pathinfo($bk['file_path'], PATHINFO_EXTENSION));
    $urlB = '?aksi=bukti&id=' . (int)$bk['id'];
    $sb = ['menunggu' => ['menunggu', 'Menunggu verifikasi'], 'diterima' => ['lunas', 'Diterima'], 'ditolak' => ['tempo', 'Ditolak']][$bk['status']] ?? ['belum', $bk['status']];
?>
                        <div class="dv-bk">
                            <div class="dv-bk-head"><span class="ad-chip <?= h($sb[0]) ?>"><?= h($sb[1]) ?></span><span class="dv-bk-meta"><?= h(trTglJam($bk['diunggah_at'])) ?> · <?= h($sumber[$bk['sumber']] ?? $bk['sumber']) ?></span></div>
<?php if ($ext === 'pdf'): ?>
                            <a class="dv-bukti" href="<?= h($urlB) ?>" target="_blank" rel="noopener" style="cursor:pointer"><b><?= h($bk['nama_asli'] ?: 'bukti.pdf') ?></b><span>Klik untuk membuka PDF</span></a>
<?php else: ?>
                            <div class="dv-bukti dv-zoom" data-src="<?= h($urlB) ?>"><img src="<?= h($urlB) ?>" alt="Bukti pembayaran" loading="lazy"><span><?= h($bk['nama_asli'] ?: '') ?></span></div>
<?php endif; ?>
                            <p class="dv-bk-info"><?= h(trMetodeLabel($bk['metode'], $bk['bank'])) ?><?= $bk['catatan'] ? ' · Alasan ditolak: ' . h($bk['catatan']) : '' ?> · <a class="dv-bk-open" href="<?= h($urlB) ?>" target="_blank" rel="noopener">Buka di tab baru</a></p>
                        </div>
<?php endforeach; endif; ?>

<?php if ($kunci === 'lunas'): ?>
                        <div class="dv-note ok">Pembayaran sudah lunas<?php $kw = ''; foreach ($bayar as $p) if ($p['nomor_kuitansi']) $kw = $p['nomor_kuitansi']; echo $kw ? ' · kuitansi ' . h($kw) : ''; ?>.</div>
<?php elseif ($menunggu): ?>
                        <div class="dv-note">Setelah diverifikasi, <?= $kuotaBaru && $sesi ? 'kuota ' . (int)$sesi . ' sesi otomatis ditambahkan ke profil member dan ' : '' ?>tagihan ditandai lunas dan kuitansi diterbitkan.</div>
                        <div class="dv-btns ad-noprint">
                            <button type="button" class="ad-btn danger" id="dv-tolak">Tolak</button>
                            <button type="button" class="ad-btn green" id="dv-verif">✓ Verifikasi Lunas</button>
                        </div>
<?php else: ?>
                        <div class="dv-note warn">Tagihan belum lunas. Catat pembayaran langsung (tunai/QRIS/transfer yang sudah dicek), atau unggah bukti untuk diverifikasi.</div>
                        <div class="ad-noprint">
                            <p class="dv-sub">Catat pembayaran</p>
                            <div class="ad-row2">
                                <div class="ad-field"><label>Metode</label><select id="dv-metode"><option value="tunai">Tunai</option><option value="transfer">Transfer bank</option></select></div>
                                <div class="ad-field" id="dv-bank-wrap" style="display:none"><label>Bank</label><select id="dv-bank"><option>BCA</option><option>Mandiri</option><option>BNI</option><option>BRI</option><option>Lainnya</option></select></div>
                            </div>
                            <div class="dv-btns"><button type="button" class="ad-btn green" id="dv-lunas">✓ Catat Lunas (<?= h(trRp($total - $dibayar)) ?>)</button></div>
                            <p class="dv-sub">Atau unggah bukti</p>
                            <div class="ad-field"><input type="file" id="dv-file" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
                            <div class="dv-btns"><button type="button" class="ad-btn" id="dv-unggah">Unggah Bukti</button></div>
                        </div>
<?php endif; ?>
                    </div>

                    <div class="ad-card ad-card-pad">
                        <p class="ad-card-title">Riwayat Status</p>
                        <div class="dv-tl">
<?php foreach ($riwayat as $r): ?>
                            <div class="dv-tl-i"><i class="dv-dot <?= h($r['tipe']) ?>"></i><b><?= h($r['judul']) ?></b><span><?= h($r['ket']) ?></span></div>
<?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal tolak -->
        <div class="ad-modal-bg" id="dv-tbg">
            <div class="ad-modal" style="max-width:440px">
                <h3>Tolak Bukti</h3>
                <p class="lead">Tulis alasan penolakan agar bisa dijelaskan ke member.</p>
                <div class="ad-field"><label>Alasan</label><textarea id="dv-alasan" rows="3" maxlength="255" placeholder="mis. Nominal tidak sesuai / bukti buram"></textarea></div>
                <div class="ad-msg" id="dv-tmsg"></div>
                <div class="ad-modal-foot"><button type="button" class="ad-btn" id="dv-tbatal">Batal</button><button type="button" class="ad-btn danger" id="dv-tkirim">Tolak Bukti</button></div>
            </div>
        </div>
        <div class="dv-lightbox" id="dv-lb"><img alt=""></div>
<?php endif; ?>
    </div>

    <div class="ad-toast" id="ad-toast"></div>

<script>
var TID = <?= (int)($t['id'] ?? 0) ?>;
var NO = <?= json_encode($t['nomor_tagihan'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
var RINCI = <?= json_encode($rincian, JSON_UNESCAPED_UNICODE) ?>;
var $ = function (id) { return document.getElementById(id); };
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
        gagal: function (judul, pesan) { return tampil({ tipe: 'gagal', judul: judul, pesan: pesan, ya: 'Tutup' }); }
    };
})();

function toast(msg, err) {
    var t = $('ad-toast'); t.textContent = msg; t.classList.toggle('err', !!err); t.classList.add('show');
    clearTimeout(toast._t); toast._t = setTimeout(function () { t.classList.remove('show'); }, 3200);
}
function api(aksi, body) {
    var url = '?' + new URLSearchParams({ aksi: aksi });
    var opt = body instanceof FormData ? { method: 'POST', body: body }
        : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
    var status = 0, txt = '';
    return fetch(url, opt)
        .then(function (r) { status = r.status; return r.text(); })
        .then(function (t) { txt = t; return JSON.parse(t); })
        .catch(function (e) {
            console.error('[administrasi-detail] aksi=' + aksi, 'HTTP', status, txt.slice(0, 800), e);
            return { ok: false, pesan: status ? 'Server membalas bukan JSON (HTTP ' + status + '). Buka Console browser (F12) untuk melihat isinya.' : 'Gagal terhubung ke server.' };
        });
}
// Setelah berhasil: tampilkan popup, lalu muat ulang halaman
function berhasil(j, judul, tujuan) {
    return Dlg.sukses(judul, j.pesan).then(function () { location.href = tujuan || (location.pathname + '?id=' + TID); });
}
function gagal(j, judul, btn) { if (btn) btn.disabled = false; Dlg.gagal(judul, j.pesan || 'Terjadi kesalahan pada server.'); }
function rinciDengan(extra) { return RINCI.filter(function (r) { return !extra || r[0] !== 'Metode'; }).concat(extra ? [extra] : []); }

$('dv-q').addEventListener('keydown', function (e) { if (e.key === 'Enter') location.href = 'administrasi.php?q=' + encodeURIComponent(this.value); });

document.addEventListener('click', function (e) {
    var z = e.target.closest('.dv-zoom');
    if (z) { var lb = $('dv-lb'); lb.querySelector('img').src = z.dataset.src; lb.classList.add('show'); }
});
if ($('dv-lb')) $('dv-lb').addEventListener('click', function () { this.classList.remove('show'); });

if ($('dv-verif')) $('dv-verif').addEventListener('click', function () {
    var b = this;
    Dlg.konfirmasi('Verifikasi sebagai lunas?', 'Pembayaran dicatat lunas, kuitansi diterbitkan, dan kuota paket member diaktifkan.', { ya: 'Ya, verifikasi', rincian: RINCI }).then(function (ok) {
        if (!ok) return;
        b.disabled = true;
        api('verifikasi', { id: TID }).then(function (j) { j.ok ? berhasil(j, 'Pembayaran terverifikasi') : gagal(j, 'Gagal memverifikasi', b); });
    });
});

if ($('dv-tolak')) {
    $('dv-tolak').addEventListener('click', function () { $('dv-tmsg').classList.remove('show'); $('dv-alasan').value = ''; $('dv-tbg').classList.add('show'); });
    $('dv-tbatal').addEventListener('click', function () { $('dv-tbg').classList.remove('show'); });
    $('dv-tkirim').addEventListener('click', function () {
        var b = this, alasan = $('dv-alasan').value.trim(), m = $('dv-tmsg');
        if (alasan.length < 3) { m.textContent = 'Tulis alasan penolakan (minimal 3 karakter).'; m.classList.add('show'); return; }
        m.classList.remove('show');
        Dlg.konfirmasi('Tolak bukti ini?', 'Tagihan tetap belum lunas dan bukti baru perlu diunggah.', { tipe: 'bahaya', ya: 'Ya, tolak', rincian: [['Alasan', alasan]] }).then(function (ok) {
            if (!ok) return;
            b.disabled = true;
            api('tolak', { id: TID, alasan: alasan }).then(function (j) { b.disabled = false; if (j.ok) { $('dv-tbg').classList.remove('show'); berhasil(j, 'Bukti ditolak'); } else gagal(j, 'Gagal menolak bukti'); });
        });
    });
}

if ($('dv-metode')) {
    $('dv-metode').addEventListener('change', function () { $('dv-bank-wrap').style.display = this.value === 'transfer' ? '' : 'none'; });
    var metodeTeks = function () { return $('dv-metode').value === 'transfer' ? 'Transfer ' + $('dv-bank').value : 'Tunai'; };
    $('dv-lunas').addEventListener('click', function () {
        var b = this;
        Dlg.konfirmasi('Catat pembayaran lunas?', 'Pembayaran akan dicatat sebagai lunas dan kuitansi diterbitkan.', { ya: 'Ya, catat lunas', rincian: rinciDengan(['Metode', metodeTeks()]) }).then(function (ok) {
            if (!ok) return;
            b.disabled = true;
            api('verifikasi', { id: TID, metode: $('dv-metode').value, bank: $('dv-bank').value }).then(function (j) { j.ok ? berhasil(j, 'Pembayaran tercatat') : gagal(j, 'Gagal mencatat pembayaran', b); });
        });
    });
    $('dv-unggah').addEventListener('click', function () {
        var f = $('dv-file').files[0], b = this;
        if (!f) { Dlg.gagal('Belum ada file', 'Pilih file bukti pembayaran terlebih dahulu.'); return; }
        if (f.size > 5 * 1024 * 1024) { Dlg.gagal('File terlalu besar', 'Ukuran bukti maksimal 5 MB.'); return; }
        if (!/\.(jpe?g|png|webp|pdf)$/i.test(f.name)) { Dlg.gagal('Format tidak didukung', 'Gunakan file JPG, PNG, WEBP, atau PDF.'); return; }
        Dlg.konfirmasi('Unggah bukti ini?', 'Bukti akan masuk antrean verifikasi.', { ya: 'Ya, unggah', rincian: [['File', f.name], ['Metode', metodeTeks()]] }).then(function (ok) {
            if (!ok) return;
            var fd = new FormData(); fd.append('id', TID); fd.append('bukti', f); fd.append('metode', $('dv-metode').value); fd.append('bank', $('dv-bank').value);
            b.disabled = true; api('unggah', fd).then(function (j) { j.ok ? berhasil(j, 'Bukti terunggah', location.pathname + '?id=' + TID + '#bukti') : gagal(j, 'Gagal mengunggah', b); });
        });
    });
}

if ($('dv-hapus')) $('dv-hapus').addEventListener('click', function () {
    var b = this;
    Dlg.konfirmasi('Hapus invoice ini?', 'Invoice ' + NO + ' akan dihapus permanen beserta bukti pembayarannya. Tindakan ini tidak bisa dibatalkan.', { tipe: 'bahaya', ya: 'Ya, hapus', rincian: RINCI }).then(function (ok) {
        if (!ok) return;
        b.disabled = true;
        api('hapus', { id: TID }).then(function (j) { j.ok ? berhasil(j, 'Invoice dihapus', 'administrasi.php') : gagal(j, 'Gagal menghapus', b); });
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