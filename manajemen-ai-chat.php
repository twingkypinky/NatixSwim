<?php
// ============================================================
// manajemen-ai-chat.php — AI Assistant / Chat Log + koneksi WhatsApp Business (Cloud API)
// Tabel: percakapan, pesan, ai_whatsapp, knowledge_base, members, paket_member, tagihan, bukti_pembayaran
// Kolom tambahan (dibuat otomatis): percakapan.diambil_at/terakhir_at/nama_kontak, pesan.wa_id/status_kirim/media_tipe/media_path
//
// PILIH PROVIDER (WA_PROVIDER):
//  'baileys' = gratis, tidak resmi, tersambung lewat QR memakai gateway Node.js (folder wa-gateway). Nomor berisiko diblokir.
//  'cloud'   = WhatsApp Business Cloud API resmi dari Meta (berbayar per pesan, aman dari blokir).
//
// A) BAILEYS: di folder wa-gateway jalankan `npm install` lalu `node wa-gateway.mjs`. Samakan GW_TOKEN di sini dengan GATEWAY_TOKEN gateway,
//    lalu klik tombol status WhatsApp di halaman ini dan pindai QR dari HP (WhatsApp > Perangkat tertaut).
//
// B) CLOUD API — CARA MENYAMBUNGKAN WHATSAPP BUSINESS (Meta):
//  1. developers.facebook.com -> buat App (tipe Business) -> tambahkan produk "WhatsApp".
//  2. Salin Phone number ID dan buat Access Token permanen (System User) -> isi di bawah.
//  3. Webhooks -> Callback URL = alamat halaman ini + ?aksi=webhook (HTTPS publik, lihat tombol "WhatsApp Terhubung" di halaman),
//     Verify token = WA_VERIFY_TOKEN di bawah -> subscribe field "messages".
//  4. App Secret (Settings > Basic) -> WA_APP_SECRET agar webhook diverifikasi tanda tangannya.
// Nilai bisa juga diisi lewat environment variable NATIX_WA_TOKEN, NATIX_WA_PHONE_ID, NATIX_WA_VERIFY, NATIX_WA_SECRET.
// ============================================================
if (is_file(__DIR__ . '/config/wa-config.php')) require_once __DIR__ . '/config/wa-config.php';   // pengaturan WhatsApp bersama
foreach ([
    'WA_TOKEN'        => [getenv('NATIX_WA_TOKEN'),    'ISI_ACCESS_TOKEN'],
    'WA_PHONE_ID'     => [getenv('NATIX_WA_PHONE_ID'), 'ISI_PHONE_NUMBER_ID'],
    'WA_VERIFY_TOKEN' => [getenv('NATIX_WA_VERIFY'),   'natix-swim-verify'],
    'WA_APP_SECRET'   => [getenv('NATIX_WA_SECRET'),   ''],
    'WA_BASE'         => [getenv('NATIX_WA_BASE'),     'https://graph.facebook.com'],
    'WA_PROVIDER'     => [getenv('NATIX_WA_PROVIDER'), 'baileys'],
    'GW_URL'          => [getenv('NATIX_GW_URL'),      'http://127.0.0.1:3001'],
    'GW_TOKEN'        => [getenv('NATIX_GW_TOKEN'),    'natix-gateway-secret'],
] as $k => [$env, $def]) if (!defined($k)) define($k, $env ?: $def);
const WA_API_VERSION = 'v21.0';
const WA_TEMPLATE_BARU = 'hello_world';      // template (sudah disetujui Meta) untuk memulai chat baru
const WA_TEMPLATE_BAHASA = 'en_US';
const WA_URL_PROFIL = 'data-member.php?id={id}';   // tujuan tombol "Lihat Profil Lengkap" ({id} = id member)
const WA_AMBANG_AI = 0.5;                    // skor minimal kecocokan Knowledge Base agar AI menjawab sendiri
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

function waBaileys(): bool { return WA_PROVIDER === 'baileys'; }
function waSiap(): bool { if (waBaileys()) return GW_URL !== ''; return WA_TOKEN !== '' && strpos(WA_TOKEN, 'ISI_') !== 0 && WA_PHONE_ID !== '' && strpos(WA_PHONE_ID, 'ISI_') !== 0; }

// ─── UTIL ───
function waNomor(?string $s): string   // -> 62812xxxx (kosong bila tidak valid)
{
    if (preg_match('/^lid:\d{6,20}$/', (string)$s)) return (string)$s;   // ID tersembunyi WhatsApp (Baileys)
    $d = preg_replace('/\D+/', '', (string)$s);
    if ($d === '') return '';
    if ($d[0] === '0') $d = '62' . substr($d, 1);
    elseif ($d[0] === '8') $d = '62' . $d;
    return (strlen($d) >= 10 && strlen($d) <= 15) ? $d : '';
}
function waFormatNo(string $d): string   // 6281322445566 -> +62 813-2244-5566
{
    if (strpos($d, 'lid:') === 0) return 'ID WhatsApp ' . substr($d, 4, 6) . '…';
    if (strpos($d, '62') !== 0 || strlen($d) < 10) return '+' . $d;
    $r = substr($d, 2);
    return '+62 ' . substr($r, 0, 3) . '-' . substr($r, 3, 4) . '-' . substr($r, 7);
}
function waInisial(string $n): string
{
    $k = preg_split('/\s+/', trim($n));
    $s = mb_strtoupper(mb_substr($k[0] ?? '', 0, 1) . mb_substr($k[1] ?? '', 0, 1));
    return $s !== '' ? $s : '?';
}
function waUmur(?string $lahir): ?int
{
    if (!$lahir || !($t = strtotime($lahir))) return null;
    return (int)floor((time() - $t) / 31557600);
}
function waFolder(string $sub): string
{
    $d = __DIR__ . '/uploads/' . $sub;
    if (!is_dir($d)) @mkdir($d, 0755, true);
    $h = $d . '/.htaccess';
    if (!is_file($h)) @file_put_contents($h, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    if (!is_dir($d) || !is_writable($d)) throw new TrException('Folder uploads/' . $sub . ' tidak bisa ditulis. Beri izin tulis untuk user Apache.', 500);
    return $d;
}
function waTglLabel(string $tgl): string
{
    if ($tgl === date('Y-m-d')) return 'Hari ini';
    if ($tgl === date('Y-m-d', strtotime('-1 day'))) return 'Kemarin';
    return trTgl($tgl);
}
function waCsv(string $v): string { return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; }

// ─── DATABASE ───
function waMigrasi(PDO $pdo): array
{
    foreach (['percakapan', 'pesan'] as $t) if (!trAda($pdo, $t)) throw new TrException("Tabel $t belum ada. Jalankan skema database terlebih dulu.", 500);
    $dt = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'DATETIME' : 'TEXT';
    $salah = [];
    foreach ([
        ['percakapan', 'diambil_at', "ALTER TABLE percakapan ADD COLUMN diambil_at $dt NULL"],
        ['percakapan', 'terakhir_at', "ALTER TABLE percakapan ADD COLUMN terakhir_at $dt NULL"],
        ['percakapan', 'nama_kontak', 'ALTER TABLE percakapan ADD COLUMN nama_kontak VARCHAR(100) NULL'],
        ['pesan', 'wa_id', 'ALTER TABLE pesan ADD COLUMN wa_id VARCHAR(120) NULL'],
        ['pesan', 'status_kirim', 'ALTER TABLE pesan ADD COLUMN status_kirim VARCHAR(12) NULL'],
        ['pesan', 'media_tipe', 'ALTER TABLE pesan ADD COLUMN media_tipe VARCHAR(60) NULL'],
        ['pesan', 'media_path', 'ALTER TABLE pesan ADD COLUMN media_path VARCHAR(120) NULL'],
    ] as [$t, $c, $sql]) {
        if (trAda($pdo, $t, $c)) continue;
        try { $pdo->exec($sql); } catch (Throwable $e) { $salah[] = "$t.$c (" . $e->getMessage() . ')'; }
        trAda($pdo, $t, $c, true);
    }
    if (!$salah) { try { $pdo->exec('CREATE INDEX idx_pesan_wa_id ON pesan (wa_id)'); } catch (Throwable $e) { /* sudah ada */ } }
    return $salah;
}

// ─── WHATSAPP CLOUD API ───
function waPesanError($j, int $http): string
{
    $e = is_array($j) ? ($j['error'] ?? []) : [];
    $kode = (int)($e['code'] ?? 0);
    $peta = [
        190 => 'Access token WhatsApp tidak valid atau kedaluwarsa. Buat token baru di Meta dan isi WA_TOKEN.',
        131047 => 'Jendela 24 jam sudah berakhir. Member harus membalas dulu, atau mulai percakapan dengan template lewat "Chat Baru".',
        131030 => 'Nomor tujuan belum terdaftar sebagai penerima uji di akun WhatsApp Anda (mode development).',
        131026 => 'Pesan tidak dapat dikirim: nomor tujuan tidak memakai WhatsApp.',
        132001 => 'Template tidak ditemukan atau belum disetujui Meta untuk bahasa tersebut.',
        132000 => 'Jumlah variabel template tidak sesuai.',
        100 => 'Permintaan ditolak Meta (parameter tidak valid): ' . ($e['message'] ?? ''),
    ];
    if (isset($peta[$kode])) return $peta[$kode];
    $m = $e['error_data']['details'] ?? ($e['message'] ?? '');
    return 'WhatsApp API menolak permintaan' . ($m ? ': ' . $m : " (HTTP $http).");
}

function waApi(string $metode, string $path, ?array $json = null, ?array $form = null): array
{
    if (!waSiap()) throw new TrException('WhatsApp belum dikonfigurasi. Isi WA_TOKEN dan WA_PHONE_ID di bagian atas manajemen-ai-chat.php.', 503);
    if (!function_exists('curl_init')) throw new TrException('Ekstensi PHP cURL belum aktif di server.', 500);
    $ch = curl_init(rtrim(WA_BASE, '/') . '/' . WA_API_VERSION . '/' . ltrim($path, '/'));
    $hdr = ['Authorization: Bearer ' . WA_TOKEN];
    $opt = [CURLOPT_CUSTOMREQUEST => $metode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8];
    if ($json !== null) { $hdr[] = 'Content-Type: application/json'; $opt[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE); }
    if ($form !== null) $opt[CURLOPT_POSTFIELDS] = $form;
    $opt[CURLOPT_HTTPHEADER] = $hdr;
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new TrException('Tidak dapat menghubungi server WhatsApp: ' . $err, 502);
    $j = json_decode((string)$raw, true);
    if ($http >= 400 || (is_array($j) && isset($j['error']))) throw new TrException(waPesanError($j, $http), 502);
    return is_array($j) ? $j : [];
}

function waUnduh(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXFILESIZE => 10485760, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WA_TOKEN]]);
    $raw = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $http >= 400) throw new TrException('Gagal mengunduh media dari WhatsApp.', 502);
    return (string)$raw;
}

const WA_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

function waSimpanBinari(string $bin): array   // -> [nama file, mime]
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bin);
    if (!isset(WA_MIME[$mime])) throw new TrException('Jenis file tidak didukung (hanya JPG, PNG, WEBP, PDF).', 422);
    $nama = bin2hex(random_bytes(12)) . '.' . WA_MIME[$mime];
    if (file_put_contents(waFolder('chat') . '/' . $nama, $bin) === false) throw new TrException('Gagal menyimpan file.', 500);
    return [$nama, $mime];
}

// ─── GATEWAY BAILEYS ───
function waGw(string $metode, string $path, ?array $json = null): array
{
    if (!function_exists('curl_init')) throw new TrException('Ekstensi PHP cURL belum aktif di server.', 500);
    $ch = curl_init(rtrim(GW_URL, '/') . $path);
    $opt = [CURLOPT_CUSTOMREQUEST => $metode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['x-gateway-token: ' . GW_TOKEN, 'Content-Type: application/json']];
    if ($json !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new TrException('Gateway WhatsApp tidak aktif (' . $err . '). Jalankan "node wa-gateway.mjs" di folder wa-gateway.', 503);
    $j = json_decode((string)$raw, true);
    if ($http === 401) throw new TrException('Token gateway tidak cocok. Samakan GW_TOKEN di PHP dengan GATEWAY_TOKEN di gateway.', 502);
    if ($http >= 400 || !is_array($j) || empty($j['ok'])) throw new TrException(is_array($j) && !empty($j['pesan']) ? $j['pesan'] : "Gateway menolak permintaan (HTTP $http).", $http >= 400 && $http < 600 ? $http : 502);
    return $j;
}

// Kirim teks / media ke member dan catat sebagai pesan keluar
function waKirim(PDO $pdo, int $cid, string $no, string $pengirim, string $teks, ?array $berkas = null, bool $cek = false): int
{
    if (waBaileys()) {
        $body = ['to' => $no, 'text' => $teks, 'cek' => $cek];
        $mediaNama = null; $mediaMime = null;
        if ($berkas) {
            $bin = file_get_contents($berkas['tmp_name']);
            [$mediaNama, $mediaMime] = waSimpanBinari($bin);
            $body += ['file_base64' => base64_encode($bin), 'mimetype' => $mediaMime, 'filename' => $berkas['name']];
        }
        try { $r = waGw('POST', '/kirim', $body); }
        catch (TrException $e) { if ($mediaNama) @unlink(waFolder('chat') . '/' . $mediaNama); throw $e; }
        return waSimpanPesan($pdo, $cid, $pengirim, $teks !== '' ? $teks : ($mediaMime === 'application/pdf' ? '[Dokumen]' : '[Gambar]'),
            ['wa_id' => $r['id'] ?? null, 'status_kirim' => 'sent', 'media_tipe' => $mediaMime, 'media_path' => $mediaNama]);
    }
    $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $no];
    $mediaNama = null; $mediaMime = null;
    if ($berkas) {
        $bin = file_get_contents($berkas['tmp_name']);
        [$mediaNama, $mediaMime] = waSimpanBinari($bin);
        $up = waApi('POST', WA_PHONE_ID . '/media', null, ['messaging_product' => 'whatsapp', 'type' => $mediaMime,
            'file' => new CURLFile(waFolder('chat') . '/' . $mediaNama, $mediaMime, $berkas['name'])]);
        $tipe = $mediaMime === 'application/pdf' ? 'document' : 'image';
        $payload['type'] = $tipe;
        $payload[$tipe] = ['id' => $up['id']] + ($teks !== '' ? ['caption' => $teks] : []) + ($tipe === 'document' ? ['filename' => $berkas['name']] : []);
    } else {
        $payload['type'] = 'text';
        $payload['text'] = ['preview_url' => false, 'body' => $teks];
    }
    try { $r = waApi('POST', WA_PHONE_ID . '/messages', $payload); }
    catch (TrException $e) { if ($mediaNama) @unlink(waFolder('chat') . '/' . $mediaNama); throw $e; }
    return waSimpanPesan($pdo, $cid, $pengirim, $teks !== '' ? $teks : ($mediaMime === 'application/pdf' ? '[Dokumen]' : '[Gambar]'),
        ['wa_id' => $r['messages'][0]['id'] ?? null, 'status_kirim' => 'sent', 'media_tipe' => $mediaMime, 'media_path' => $mediaNama]);
}

function waSimpanPesan(PDO $pdo, int $cid, string $pengirim, string $isi, array $x = []): int
{
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO pesan (percakapan_id, pengirim, isi, waktu, wa_id, status_kirim, media_tipe, media_path) VALUES (:c,:p,:i,:w,:wa,:sk,:mt,:mp)')
        ->execute([':c' => $cid, ':p' => $pengirim, ':i' => $isi, ':w' => $x['waktu'] ?? $now, ':wa' => $x['wa_id'] ?? null, ':sk' => $x['status_kirim'] ?? null,
                   ':mt' => $x['media_tipe'] ?? null, ':mp' => $x['media_path'] ?? null]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE percakapan SET terakhir_at = :t WHERE id = :c')->execute([':t' => $x['waktu'] ?? $now, ':c' => $cid]);
    return $id;
}

// ─── MEMBER & PERCAKAPAN ───
function waMembers(PDO $pdo, string $no): array   // semua member dengan nomor WA ini
{
    if (strpos($no, 'lid:') === 0) return [];
    $lokal = '0' . substr($no, 2);
    $s = $pdo->prepare("SELECT * FROM members WHERE REPLACE(REPLACE(REPLACE(no_whatsapp,'-',''),' ',''),'+','') IN (:a,:b,:c) ORDER BY (status='aktif') DESC, id");
    $s->execute([':a' => $no, ':b' => $lokal, ':c' => substr($no, 2)]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function waPercakapan(PDO $pdo, string $no, ?string $kontak): array
{
    $s = $pdo->prepare('SELECT * FROM percakapan WHERE no_whatsapp = :n ORDER BY id DESC LIMIT 1');
    $s->execute([':n' => $no]);
    $c = $s->fetch(PDO::FETCH_ASSOC);
    $mem = waMembers($pdo, $no);
    $mid = $mem ? (int)$mem[0]['id'] : null;
    if ($c) {
        if ($kontak && empty($c['nama_kontak'])) $pdo->prepare('UPDATE percakapan SET nama_kontak = :k WHERE id = :i')->execute([':k' => mb_substr($kontak, 0, 100), ':i' => $c['id']]);
        if (empty($c['member_id']) && $mid) { $pdo->prepare('UPDATE percakapan SET member_id = :m WHERE id = :i')->execute([':m' => $mid, ':i' => $c['id']]); $c['member_id'] = $mid; }
        return $c;
    }
    $pdo->prepare("INSERT INTO percakapan (member_id, no_whatsapp, status, mulai, terakhir_at, nama_kontak) VALUES (:m,:n,'bot',:t,:t2,:k)")
        ->execute([':m' => $mid, ':n' => $no, ':t' => date('Y-m-d H:i:s'), ':t2' => date('Y-m-d H:i:s'), ':k' => $kontak ? mb_substr($kontak, 0, 100) : null]);
    $s = $pdo->prepare('SELECT * FROM percakapan WHERE id = :i'); $s->execute([':i' => (int)$pdo->lastInsertId()]);
    return $s->fetch(PDO::FETCH_ASSOC);
}

function waChip(array $c): array
{
    if ($c['status'] === 'selesai') return ['selesai', 'Selesai'];
    if ($c['status'] === 'cs') return empty($c['diambil_at']) ? ['admin', 'Butuh Admin'] : ['ditangani', 'Ditangani Admin'];
    return empty($c['member_id']) ? ['prospek', 'Prospek'] : ['ai', 'Dijawab AI'];
}

function waNamaTampil(array $c): string
{
    foreach ([$c['nama_wali'] ?? '', $c['member_nama'] ?? '', $c['nama_kontak'] ?? ''] as $n) if (trim((string)$n) !== '') return trim($n);
    return waFormatNo((string)$c['no_whatsapp']);
}

// Data untuk panel kanan
function waPanel(PDO $pdo, array $c): array
{
    $p = ['nama' => waNamaTampil($c), 'inisial' => waInisial(waNamaTampil($c)), 'no' => waFormatNo((string)$c['no_whatsapp']), 'member' => false, 'profil' => null, 'tag' => null, 'subjudul' => 'Prospek • belum terdaftar sebagai member', 'info' => [], 'saran' => ''];
    if (empty($c['member_id'])) { $p['saran'] = 'Prospek baru. Tawarkan kelas percobaan dan kirim info paket serta jadwal.'; return $p; }
    $s = $pdo->prepare('SELECT * FROM members WHERE id = :i'); $s->execute([':i' => $c['member_id']]);
    $m = $s->fetch(PDO::FETCH_ASSOC);
    if (!$m) return $p;
    $umur = waUmur($m['tanggal_lahir'] ?? null);
    $p['member'] = true; $p['profil'] = str_replace('{id}', (string)$m['id'], WA_URL_PROFIL);
    $p['subjudul'] = (!empty($m['nama_wali']) ? 'Orang tua dari ' . $m['nama'] : 'Member ' . $m['nama']) . ($umur !== null ? " ($umur th)" : '');
    $p['info'][] = ['ID Member', $m['kode_member']];
    $prog = '-'; $sisa = null; $total = null; $paketId = null;
    try {
        $q = $pdo->prepare("SELECT pm.id, pm.sisa_sesi, pm.total_sesi, kp.nama_program FROM paket_member pm JOIN kelas_program kp ON kp.id = pm.program_id
                             WHERE pm.member_id = :m ORDER BY (pm.status = 'aktif') DESC, pm.id DESC LIMIT 1");
        $q->execute([':m' => $m['id']]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) { $prog = $r['nama_program']; $sisa = (int)$r['sisa_sesi']; $total = (int)$r['total_sesi']; $paketId = $r['id']; }
    } catch (Throwable $e) { /* tabel belum ada */ }
    $p['info'][] = ['Program', $prog];
    $p['info'][] = ['Sisa Kuota', $sisa === null ? '-' : "$sisa / $total sesi"];
    $jadwal = '-'; $pelatih = '-';
    try {
        $q = $pdo->prepare("SELECT j.tanggal, j.jam_mulai, u.nama AS pelatih FROM jadwal_peserta jp JOIN jadwal j ON j.id = jp.jadwal_id
                             LEFT JOIN pelatih pl ON pl.id = j.pelatih_id LEFT JOIN users u ON u.id = pl.user_id
                            WHERE jp.member_id = :m AND j.tanggal >= :t AND j.status <> 'dibatalkan' ORDER BY j.tanggal, j.jam_mulai LIMIT 1");
        $q->execute([':m' => $m['id'], ':t' => date('Y-m-d')]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int)date('w', strtotime($r['tanggal']))];
            $jadwal = $hari . ' ' . str_replace(':', '.', substr($r['jam_mulai'], 0, 5)); $pelatih = $r['pelatih'] ? (preg_match('/^coach\b/i', $r['pelatih']) ? $r['pelatih'] : 'Coach ' . $r['pelatih']) : '-';
        }
    } catch (Throwable $e) { /* abaikan */ }
    $p['info'][] = ['Jadwal', $jadwal]; $p['info'][] = ['Pelatih', $pelatih];
    $saran = [];
    if ($sisa !== null && $sisa <= 2) { $p['tag'] = 'Kuota Hampir Habis'; $saran[] = "Sisa kuota tinggal $sisa sesi. Tawarkan perpanjangan paket $prog."; }
    try {
        $q = $pdo->prepare("SELECT nomor_tagihan FROM tagihan WHERE member_id = :m AND status <> 'lunas' ORDER BY id DESC LIMIT 1"); $q->execute([':m' => $m['id']]);
        if ($no = $q->fetchColumn()) $saran[] = "Ada tagihan $no yang belum lunas. Ingatkan pembayaran.";
    } catch (Throwable $e) { /* abaikan */ }
    $p['saran'] = $saran ? implode(' ', $saran) : 'Tidak ada tindakan khusus. Pastikan pertanyaan member terjawab.';
    return $p;
}

// ─── AI: jawab dari Knowledge Base ───
function waToken(string $t): array
{
    static $stop = ['yang', 'dan', 'atau', 'apa', 'saja', 'ada', 'kak', 'kakak', 'bisa', 'untuk', 'itu', 'ini', 'dengan', 'dari', 'saya', 'ya', 'nya', 'mau', 'dong', 'nih', 'ke', 'di', 'apakah', 'bagaimana', 'gimana', 'minta', 'tolong', 'aku', 'kami', 'anak', 'masih', 'sudah', 'belum', 'akan', 'pada', 'juga'];
    $w = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique(array_filter($w, function ($x) use ($stop) { return mb_strlen($x) >= 3 && !in_array($x, $stop, true); })));
}

function waJawab(PDO $pdo, string $teks, array $members, string $namaBot): ?string
{
    $t = mb_strtolower(trim($teks));
    $q = waToken($t);
    if (preg_match('/^(halo|hai|hi|hallo|hello|selamat (pagi|siang|sore|malam)|assalamualaikum|assalamu\'alaikum|permisi|p)(\s+(kak|kakak|min|admin|natix|bu|pak|mbak|mas))?\W*$/u', $t))
        return "Halo Kak! 👋 Saya $namaBot, asisten Natix Swim. Ada yang bisa dibantu? Silakan tanya seputar jadwal, paket, atau pembayaran ya.";

    if ($members && preg_match('/\b(sisa|kuota)\b/u', $t) && preg_match('/(kuota|sesi)/u', $t)) {
        $baris = [];
        foreach ($members as $m) {
            try {
                $s = $pdo->prepare("SELECT pm.sisa_sesi, pm.total_sesi, kp.nama_program FROM paket_member pm JOIN kelas_program kp ON kp.id = pm.program_id WHERE pm.member_id = :m AND pm.status = 'aktif' ORDER BY pm.id DESC LIMIT 1");
                $s->execute([':m' => $m['id']]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) $baris[] = '• ' . $m['nama'] . ': ' . $r['sisa_sesi'] . ' dari ' . $r['total_sesi'] . ' sesi (' . $r['nama_program'] . ')';
            } catch (Throwable $e) { /* abaikan */ }
        }
        if ($baris) return "Sisa kuota saat ini:\n" . implode("\n", $baris) . "\nMau sekalian perpanjang paket? 😊";
    }

    if (!$q || !trAda($pdo, 'knowledge_base')) return null;
    $sql = 'SELECT id, pertanyaan, jawaban FROM knowledge_base' . (trAda($pdo, 'knowledge_base', 'status') ? " WHERE status = 'aktif'" : '');
    $best = null; $skor = 0;
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $kb) {
        $tp = waToken($kb['pertanyaan']); $ta = waToken($kb['jawaban']);
        $a = count(array_intersect($q, $tp)); $b = count(array_intersect($q, array_diff($ta, $tp)));
        if ($a < 1) continue;
        $s = ($a + 0.5 * $b) / count($q);
        if ($s > $skor) { $skor = $s; $best = $kb; }
    }
    if (!$best || $skor < WA_AMBANG_AI) return null;
    if (trAda($pdo, 'knowledge_base', 'dipakai_ai')) $pdo->prepare('UPDATE knowledge_base SET dipakai_ai = dipakai_ai + 1 WHERE id = :i')->execute([':i' => $best['id']]);
    return $best['jawaban'];
}

function waBot(PDO $pdo): array
{
    $r = null;
    if (trAda($pdo, 'ai_whatsapp')) $r = $pdo->query('SELECT nama_bot, status FROM ai_whatsapp ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return ['nama' => $r['nama_bot'] ?? 'Natix Assistant', 'aktif' => !$r || $r['status'] === 'aktif'];
}

// Bot mengirim balasan; kegagalan kirim hanya dicatat (tidak menggagalkan webhook)
function waBotKirim(PDO $pdo, int $cid, string $no, string $teks): void
{
    try { waKirim($pdo, $cid, $no, 'bot', $teks); }
    catch (Throwable $e) { waSimpanPesan($pdo, $cid, 'bot', $teks, ['status_kirim' => 'failed']); error_log('[natix-wa] kirim bot gagal: ' . $e->getMessage()); }
}

function waAlihAdmin(PDO $pdo, int $cid): void
{
    $pdo->prepare("UPDATE percakapan SET status = 'cs', diambil_at = NULL WHERE id = :i")->execute([':i' => $cid]);
}

// ─── WEBHOOK ───
function waTerimaPesan(PDO $pdo, array $msg, ?string $kontak): void
{
    $no = waNomor($msg['from'] ?? '');
    $waId = (string)($msg['id'] ?? '');
    if ($no === '' || $waId === '') return;
    $d = $pdo->prepare('SELECT COUNT(*) FROM pesan WHERE wa_id = :w'); $d->execute([':w' => $waId]);
    if ((int)$d->fetchColumn() > 0) return;   // Meta mengirim ulang -> abaikan

    $tipe = (string)($msg['type'] ?? 'text');
    $waktu = !empty($msg['timestamp']) ? date('Y-m-d H:i:s', (int)$msg['timestamp']) : date('Y-m-d H:i:s');
    $isi = ''; $media = null; $mime = null; $adaMedia = false;
    if ($tipe === 'text') $isi = (string)($msg['text']['body'] ?? '');
    elseif ($tipe === 'button') $isi = (string)($msg['button']['text'] ?? '');
    elseif ($tipe === 'interactive') $isi = (string)($msg['interactive']['button_reply']['title'] ?? $msg['interactive']['list_reply']['title'] ?? '');
    elseif ($tipe === 'image' || $tipe === 'document') {
        $adaMedia = true;
        $isi = (string)($msg[$tipe]['caption'] ?? '');
        $mid = (string)($msg[$tipe]['id'] ?? '');
        try {
            if (isset($msg['_bin'])) [$media, $mime] = waSimpanBinari($msg['_bin']);
            else { $meta = waApi('GET', $mid); [$media, $mime] = waSimpanBinari(waUnduh((string)$meta['url'])); }
        } catch (Throwable $e) { error_log('[natix-wa] unduh media gagal: ' . $e->getMessage()); }
        if ($isi === '') $isi = $tipe === 'image' ? '[Gambar]' : '[Dokumen' . (!empty($msg['document']['filename']) ? ': ' . $msg['document']['filename'] : '') . ']';
    }
    elseif ($tipe === 'audio') $isi = '[Pesan suara]';
    elseif ($tipe === 'location') $isi = '[Lokasi]';
    else $isi = '[' . $tipe . ']';
    if (trim($isi) === '') $isi = '[' . $tipe . ']';

    $c = waPercakapan($pdo, $no, $kontak);
    $cid = (int)$c['id'];
    if ($c['status'] === 'selesai') {   // member menghubungi lagi -> buka kembali, AI menjawab
        $pdo->prepare("UPDATE percakapan SET status = 'bot', diambil_at = NULL WHERE id = :i")->execute([':i' => $cid]);
        $c['status'] = 'bot';
    }
    waSimpanPesan($pdo, $cid, 'member', $isi, ['waktu' => $waktu, 'wa_id' => $waId, 'media_tipe' => $mime, 'media_path' => $media]);
    // [gaji] Coach mengetik "Check Gaji Bulan Ini" -> balas slip gaji bulan berjalan (hanya untuk nomor yang terdaftar sebagai coach)
    if ($tipe === 'text' && is_file(__DIR__ . '/gaji-fungsi.php')) {
        $gjBalas = null;
        try { require_once __DIR__ . '/gaji-fungsi.php'; $gjBalas = gjBalasWa($pdo, $no, $isi); }
        catch (Throwable $e) { error_log('[gaji] chat: ' . $e->getMessage()); }
        if ($gjBalas !== null) { waBotKirim($pdo, $cid, $no, $gjBalas); return; }
    }
    // [alur] Alur chatbot: "daftar" (calon murid / orang tua) dan "progres" (coach). Percakapan yang sedang di tengah isian tetap dilanjutkan.
    if ($tipe === 'text' && is_file(__DIR__ . '/alur-fungsi.php')) {
        $alBalas = null;
        try { require_once __DIR__ . '/alur-fungsi.php'; $alBalas = alurProses($pdo, $no, $isi); }
        catch (Throwable $e) { error_log('[alur] chat: ' . $e->getMessage()); }
        if ($alBalas !== null) { waBotKirim($pdo, $cid, $no, $alBalas); return; }
    }
    if ($c['status'] !== 'bot') return;   // sedang ditangani admin / menunggu admin: AI diam

    $bot = waBot($pdo);
    if (!$bot['aktif']) { waAlihAdmin($pdo, $cid); return; }
    $members = waMembers($pdo, $no);

    // Bukti pembayaran: gambar/PDF dari member yang punya tagihan belum lunas -> masuk antrean verifikasi di Transaksi
    if ($adaMedia) {
        $nomorTagihan = null;
        if ($media && $members && trAda($pdo, 'bukti_pembayaran')) {
            try {
                $ids = implode(',', array_map(function ($m) { return (int)$m['id']; }, $members));
                $t = $pdo->query("SELECT id, nomor_tagihan FROM tagihan WHERE member_id IN ($ids) AND status <> 'lunas' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if ($t) {
                    $nb = $media;
                    if (@copy(waFolder('chat') . '/' . $media, waFolder('bukti') . '/' . $nb)) {
                        $pdo->prepare("INSERT INTO bukti_pembayaran (tagihan_id, file_path, nama_asli, metode, sumber, status) VALUES (:t,:f,:n,'transfer','ai_chat','menunggu')")
                            ->execute([':t' => $t['id'], ':f' => $nb, ':n' => 'bukti-whatsapp.' . pathinfo($nb, PATHINFO_EXTENSION)]);
                        $nomorTagihan = $t['nomor_tagihan'];
                    }
                }
            } catch (Throwable $e) { error_log('[natix-wa] simpan bukti gagal: ' . $e->getMessage()); }
        }
        waAlihAdmin($pdo, $cid);
        waBotKirim($pdo, $cid, $no, $nomorTagihan
            ? "Terima kasih Kak, bukti pembayaran untuk tagihan $nomorTagihan sudah kami terima. Admin akan segera memverifikasi ya 🙏"
            : 'Terima kasih Kak, file sudah kami terima. Admin akan segera membantu ya 🙏');
        return;
    }

    $jawab = $tipe === 'text' || $isi !== '' ? waJawab($pdo, $isi, $members, $bot['nama']) : null;
    if ($jawab !== null) { waBotKirim($pdo, $cid, $no, $jawab); return; }
    waAlihAdmin($pdo, $cid);
    waBotKirim($pdo, $cid, $no, 'Terima kasih Kak, pertanyaannya kami teruskan ke admin ya. Mohon ditunggu sebentar 🙏');
}

function waProsesWebhook(PDO $pdo, array $p): int
{
    $n = 0;
    foreach ($p['entry'] ?? [] as $en) foreach ($en['changes'] ?? [] as $ch) {
        $v = $ch['value'] ?? [];
        if (WA_PHONE_ID !== '' && strpos(WA_PHONE_ID, 'ISI_') !== 0 && !empty($v['metadata']['phone_number_id']) && (string)$v['metadata']['phone_number_id'] !== (string)WA_PHONE_ID) continue;
        $nama = [];
        foreach ($v['contacts'] ?? [] as $k) if (!empty($k['wa_id'])) $nama[waNomor($k['wa_id'])] = $k['profile']['name'] ?? null;
        foreach ($v['messages'] ?? [] as $msg) { waTerimaPesan($pdo, $msg, $nama[waNomor($msg['from'] ?? '')] ?? null); $n++; }
        foreach ($v['statuses'] ?? [] as $st) {
            if (empty($st['id']) || empty($st['status'])) continue;
            $urut = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
            if (!isset($urut[$st['status']])) continue;
            $pdo->prepare('UPDATE pesan SET status_kirim = :s WHERE wa_id = :w')->execute([':s' => $st['status'], ':w' => $st['id']]);
        }
    }
    return $n;
}

function waProsesBaileys(PDO $pdo, array $p): void
{
    if (($p['jenis'] ?? '') === 'status') {
        $ok = ['sent', 'delivered', 'read', 'failed'];
        if (!empty($p['id']) && in_array($p['status'] ?? '', $ok, true)) $pdo->prepare('UPDATE pesan SET status_kirim = :s WHERE wa_id = :w')->execute([':s' => $p['status'], ':w' => $p['id']]);
        return;
    }
    $tipe = (string)($p['tipe'] ?? 'text'); $teks = (string)($p['teks'] ?? '');
    $msg = ['from' => (string)($p['from'] ?? ''), 'id' => (string)($p['id'] ?? ''), 'timestamp' => $p['timestamp'] ?? time(), 'type' => $tipe === 'lainnya' ? 'other' : $tipe];
    if ($tipe === 'text') $msg['text'] = ['body' => $teks];
    elseif ($tipe === 'image' || $tipe === 'document') {
        $msg[$tipe] = ['caption' => $teks, 'id' => '', 'filename' => $p['media']['filename'] ?? ''];
        if (!empty($p['media']['base64'])) { $bin = base64_decode((string)$p['media']['base64'], true); if ($bin !== false && $bin !== '') $msg['_bin'] = $bin; }
    }
    waTerimaPesan($pdo, $msg, !empty($p['nama']) ? (string)$p['nama'] : null);
}

function waPlain(string $t, int $kode = 200): void
{
    while (ob_get_level()) ob_end_clean();
    http_response_code($kode); header('Content-Type: text/plain; charset=utf-8'); echo $t; exit;
}

// ─── AJAX ───
if ($trAjax) {
    try {
        $aksi = $_GET['aksi'];

        // Verifikasi webhook (Meta memanggil GET) & penerimaan event (POST) — tanpa header AJAX
        if ($aksi === 'webhook') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? ''; $tok = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? ''; $ch = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
                if ($mode === 'subscribe' && hash_equals((string)WA_VERIFY_TOKEN, (string)$tok)) waPlain((string)$ch);
                waPlain('Verify token tidak cocok', 403);
            }
            $raw = (string)file_get_contents('php://input');
            if (WA_APP_SECRET !== '') {
                $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
                if (!hash_equals('sha256=' . hash_hmac('sha256', $raw, WA_APP_SECRET), $sig)) waPlain('Tanda tangan tidak valid', 403);
            }
            $p = json_decode($raw, true);
            if (!is_array($p)) waPlain('Payload tidak valid', 400);
            waMigrasi($pdo);
            try { waProsesWebhook($pdo, $p); }
            catch (Throwable $e) { error_log('[natix-wa] webhook: ' . $e->getMessage()); waPlain('Error', 500); }   // 500 -> Meta mengirim ulang
            waPlain('OK');
        }

        if ($aksi === 'webhook_baileys') {
            if (!hash_equals((string)GW_TOKEN, (string)($_SERVER['HTTP_X_GATEWAY_TOKEN'] ?? ''))) waPlain('Token gateway salah', 403);
            $p = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($p)) waPlain('Payload tidak valid', 400);
            waMigrasi($pdo);
            try { waProsesBaileys($pdo, $p); }
            catch (Throwable $e) { error_log('[natix-wa] webhook_baileys: ' . $e->getMessage()); waPlain('Error', 500); }
            waPlain('OK');
        }

        $salahMigrasi = waMigrasi($pdo);

        if ($aksi === 'status' && waBaileys()) {
            $o = ['ok' => true, 'provider' => 'baileys', 'terhubung' => false, 'state' => 'closed', 'alasan' => '', 'qr' => null, 'nomor' => '', 'nama' => '',
                  'token_bawaan' => GW_TOKEN === 'natix-gateway-secret', 'peringatan' => $salahMigrasi ? 'Migrasi database gagal: ' . implode('; ', $salahMigrasi) : ''];
            try {
                $r = waGw('GET', '/status');
                $o['state'] = (string)($r['state'] ?? 'closed'); $o['terhubung'] = $o['state'] === 'open'; $o['qr'] = $r['qr'] ?? null;
                $o['nomor'] = (string)($r['nomor'] ?? ''); $o['nama'] = (string)($r['nama'] ?? '');
                if (!$o['terhubung']) $o['alasan'] = $o['state'] === 'qr' ? 'Pindai QR dengan WhatsApp di HP.' : 'Gateway sedang menyambung...';
            } catch (TrException $e) { $o['alasan'] = $e->getMessage(); }
            trOut($o);
        }

        if ($aksi === 'gw_aksi') {
            trWajibPost();
            if (!waBaileys()) throw new TrException('Hanya untuk provider Baileys.', 422);
            $ke = (string)(trBody()['ke'] ?? '');
            if ($ke === 'logout') { waGw('POST', '/logout'); trOut(['ok' => true, 'pesan' => 'Perangkat diputuskan. QR baru akan muncul sebentar lagi.']); }
            if ($ke === 'sambung') { waGw('POST', '/sambung'); trOut(['ok' => true, 'pesan' => 'Menyambungkan ulang...']); }
            throw new TrException('Aksi tidak dikenal.', 422);
        }

        if ($aksi === 'status') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            $url = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?aksi=webhook';
            $o = ['ok' => true, 'provider' => 'cloud', 'terhubung' => false, 'alasan' => '', 'webhook_url' => $url, 'verify_token' => WA_VERIFY_TOKEN, 'aman' => WA_APP_SECRET !== '', 'https' => $https,
                  'template' => WA_TEMPLATE_BARU, 'bahasa' => WA_TEMPLATE_BAHASA, 'nomor' => '', 'nama' => '', 'kualitas' => '', 'peringatan' => $salahMigrasi ? 'Migrasi database gagal: ' . implode('; ', $salahMigrasi) : ''];
            if (!waSiap()) { $o['alasan'] = 'WA_TOKEN / WA_PHONE_ID belum diisi.'; trOut($o); }
            try {
                $r = waApi('GET', WA_PHONE_ID . '?fields=display_phone_number,verified_name,quality_rating');
                $o['terhubung'] = true; $o['nomor'] = $r['display_phone_number'] ?? ''; $o['nama'] = $r['verified_name'] ?? ''; $o['kualitas'] = $r['quality_rating'] ?? '';
            } catch (TrException $e) { $o['alasan'] = $e->getMessage(); }
            trOut($o);
        }

        if ($aksi === 'data') {
            $tab = (string)($_GET['tab'] ?? 'semua'); $q = trim((string)($_GET['q'] ?? ''));
            $dasar = "FROM percakapan c LEFT JOIN members m ON m.id = c.member_id";
            $cnt = $pdo->query("SELECT COUNT(*) AS semua,
                    SUM(CASE WHEN c.status = 'cs' AND c.diambil_at IS NULL THEN 1 ELSE 0 END) AS admin,
                    SUM(CASE WHEN c.status = 'selesai' THEN 1 ELSE 0 END) AS selesai $dasar")->fetch(PDO::FETCH_ASSOC);
            $w = []; $par = [];
            if ($tab === 'admin') $w[] = "c.status = 'cs' AND c.diambil_at IS NULL";
            elseif ($tab === 'selesai') $w[] = "c.status = 'selesai'";
            if ($q !== '') {
                $w[] = '(m.nama LIKE :q1 OR m.nama_wali LIKE :q2 OR c.nama_kontak LIKE :q3 OR c.no_whatsapp LIKE :q4 OR EXISTS (SELECT 1 FROM pesan px WHERE px.percakapan_id = c.id AND px.isi LIKE :q5))';
                foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $k) $par[":$k"] = '%' . $q . '%';
            }
            $sql = "SELECT c.id, c.no_whatsapp, c.status, c.diambil_at, c.member_id, c.nama_kontak, COALESCE(c.terakhir_at, c.mulai) AS t, m.nama AS member_nama, m.nama_wali,
                           (SELECT p.isi FROM pesan p WHERE p.percakapan_id = c.id ORDER BY p.id DESC LIMIT 1) AS isi_akhir
                    $dasar " . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY t DESC, c.id DESC LIMIT 100';
            $s = $pdo->prepare($sql); $s->execute($par);
            $rows = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $nm = waNamaTampil($c); $tg = $c['t'] ? date('Y-m-d', strtotime($c['t'])) : '';
                $jam = !$c['t'] ? '' : ($tg === date('Y-m-d') ? date('H.i', strtotime($c['t'])) : ($tg === date('Y-m-d', strtotime('-1 day')) ? 'Kemarin' : trTgl($c['t'])));
                $chip = waChip($c);
                $rows[] = ['id' => (int)$c['id'], 'nama' => $nm, 'inisial' => waInisial($nm), 'isi' => (string)$c['isi_akhir'], 'jam' => $jam, 'chip' => $chip[0], 'chip_label' => $chip[1]];
            }
            trOut(['ok' => true, 'rows' => $rows, 'hitung' => ['semua' => (int)$cnt['semua'], 'admin' => (int)$cnt['admin'], 'selesai' => (int)$cnt['selesai']], 'peringatan' => $salahMigrasi ? 'Migrasi database gagal: ' . implode('; ', $salahMigrasi) : '']);
        }

        if ($aksi === 'pesan') {
            $id = (int)($_GET['id'] ?? 0); $after = (int)($_GET['after'] ?? 0);
            $s = $pdo->prepare("SELECT c.*, m.nama AS member_nama, m.nama_wali FROM percakapan c LEFT JOIN members m ON m.id = c.member_id WHERE c.id = :i");
            $s->execute([':i' => $id]);
            $c = $s->fetch(PDO::FETCH_ASSOC);
            if (!$c) throw new TrException('Percakapan tidak ditemukan (mungkin sudah dihapus).', 404);
            $q = $pdo->prepare('SELECT id, pengirim, isi, waktu, status_kirim, media_tipe, media_path FROM pesan WHERE percakapan_id = :c AND id > :a ORDER BY id LIMIT 300');
            $q->execute([':c' => $id, ':a' => $after]);
            $msgs = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $msgs[] = ['id' => (int)$p['id'], 'dari' => $p['pengirim'], 'isi' => $p['isi'], 'jam' => date('H.i', strtotime($p['waktu'])), 'tgl' => date('Y-m-d', strtotime($p['waktu'])),
                           'tgl_label' => waTglLabel(date('Y-m-d', strtotime($p['waktu']))), 'kirim' => $p['status_kirim'], 'media' => $p['media_path'] ? ($p['media_tipe'] === 'application/pdf' ? 'pdf' : 'img') : null];
            }
            $w = $pdo->prepare("SELECT MAX(waktu) FROM pesan WHERE percakapan_id = :c AND pengirim = 'member'"); $w->execute([':c' => $id]);
            $lastM = $w->fetchColumn();
            $chip = waChip($c);
            $out = ['ok' => true, 'id' => $id, 'status' => $c['status'], 'chip' => $chip[0], 'chip_label' => $chip[1], 'diambil' => !empty($c['diambil_at']),
                    'jendela_ok' => waBaileys() || ($lastM && strtotime($lastM) >= time() - 86400), 'pesan' => $msgs];
            if ($after === 0 || !empty($_GET['panel'])) $out['panel'] = waPanel($pdo, $c);
            trOut($out);
        }

        if ($aksi === 'media') {
            $s = $pdo->prepare('SELECT media_path, media_tipe FROM pesan WHERE id = :i'); $s->execute([':i' => (int)($_GET['id'] ?? 0)]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $f = $r && $r['media_path'] ? waFolder('chat') . '/' . basename($r['media_path']) : '';
            if (!$f || !is_file($f)) waPlain('File tidak ditemukan', 404);
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: ' . ($r['media_tipe'] ?: 'application/octet-stream')); header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: inline; filename="' . basename($f) . '"'); header('Content-Length: ' . filesize($f));
            readfile($f); exit;
        }

        if ($aksi === 'kirim') {
            trWajibPost();
            $b = trBody();
            $id = (int)($b['id'] ?? 0); $teks = trim((string)($b['teks'] ?? ''));
            $f = $_FILES['berkas'] ?? null; $ada = $f && ($f['error'] ?? 4) === UPLOAD_ERR_OK;
            if ($teks === '' && !$ada) throw new TrException('Tulis pesan atau pilih file terlebih dulu.', 422);
            if (mb_strlen($teks) > 4000) throw new TrException('Pesan maksimal 4.000 karakter.', 422);
            if ($ada && $f['size'] > 5242880) throw new TrException('Ukuran file maksimal 5 MB.', 422);
            $s = $pdo->prepare('SELECT * FROM percakapan WHERE id = :i'); $s->execute([':i' => $id]);
            $c = $s->fetch(PDO::FETCH_ASSOC);
            if (!$c) throw new TrException('Percakapan tidak ditemukan (mungkin sudah dihapus).', 404);
            $w = $pdo->prepare("SELECT MAX(waktu) FROM pesan WHERE percakapan_id = :c AND pengirim = 'member'"); $w->execute([':c' => $id]);
            $lastM = $w->fetchColumn();
            if (!waBaileys() && (!$lastM || strtotime($lastM) < time() - 86400))
                throw new TrException('Jendela 24 jam sudah berakhir, pesan bebas tidak bisa dikirim. Mulai percakapan dengan template lewat "Chat Baru".', 409);
            waKirim($pdo, $id, (string)$c['no_whatsapp'], 'cs', $teks, $ada ? ['tmp_name' => $f['tmp_name'], 'name' => basename($f['name'])] : null);
            $pdo->prepare("UPDATE percakapan SET status = 'cs', diambil_at = COALESCE(diambil_at, :t), ditangani_oleh = COALESCE(ditangani_oleh, :u) WHERE id = :i")
                ->execute([':t' => date('Y-m-d H:i:s'), ':u' => trUserId($pdo), ':i' => $id]);
            trOut(['ok' => true, 'pesan' => 'Pesan terkirim.']);
        }

        if ($aksi === 'status_chat') {
            trWajibPost();
            $b = trBody(); $id = (int)($b['id'] ?? 0); $ke = (string)($b['ke'] ?? '');
            $s = $pdo->prepare('SELECT id FROM percakapan WHERE id = :i'); $s->execute([':i' => $id]);
            if (!$s->fetchColumn()) throw new TrException('Percakapan tidak ditemukan (mungkin sudah dihapus).', 404);
            if ($ke === 'selesai') { $pdo->prepare("UPDATE percakapan SET status = 'selesai' WHERE id = :i")->execute([':i' => $id]); $m = 'Percakapan ditandai selesai.'; }
            elseif ($ke === 'ambil') {
                $pdo->prepare("UPDATE percakapan SET status = 'cs', diambil_at = :t, ditangani_oleh = :u WHERE id = :i")->execute([':t' => date('Y-m-d H:i:s'), ':u' => trUserId($pdo), ':i' => $id]);
                $m = 'Anda mengambil alih chat ini. AI tidak akan membalas lagi sampai chat dikembalikan.';
            } elseif ($ke === 'ai') { $pdo->prepare("UPDATE percakapan SET status = 'bot', diambil_at = NULL, ditangani_oleh = NULL WHERE id = :i")->execute([':i' => $id]); $m = 'Chat dikembalikan ke AI. Pesan berikutnya dijawab otomatis.'; }
            else throw new TrException('Aksi tidak dikenal.', 422);
            trOut(['ok' => true, 'pesan' => $m]);
        }

        if ($aksi === 'hapus') {
            trWajibPost();
            $id = (int)(trBody()['id'] ?? 0);
            $s = $pdo->prepare('SELECT media_path FROM pesan WHERE percakapan_id = :i AND media_path IS NOT NULL'); $s->execute([':i' => $id]);
            $file = $s->fetchAll(PDO::FETCH_COLUMN);
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM pesan WHERE percakapan_id = :i')->execute([':i' => $id]);
            $d = $pdo->prepare('DELETE FROM percakapan WHERE id = :i'); $d->execute([':i' => $id]);
            if ($d->rowCount() < 1) throw new TrException('Percakapan tidak ditemukan (mungkin sudah dihapus).', 404);
            $pdo->commit();
            foreach ($file as $f) @unlink(waFolder('chat') . '/' . basename($f));   // salinan di uploads/bukti (bukti pembayaran) sengaja dibiarkan
            trOut(['ok' => true, 'pesan' => 'Percakapan berhasil dihapus.']);
        }

        if ($aksi === 'cari') {
            $q = trim((string)($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) trOut(['ok' => true, 'rows' => []]);
            $s = $pdo->prepare("SELECT id, kode_member, nama, nama_wali, no_whatsapp FROM members WHERE status = 'aktif' AND (nama LIKE :a OR nama_wali LIKE :b OR kode_member LIKE :c OR no_whatsapp LIKE :d) ORDER BY nama LIMIT 8");
            $s->execute([':a' => "%$q%", ':b' => "%$q%", ':c' => "%$q%", ':d' => "%$q%"]);
            $rows = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $m) $rows[] = ['nama' => $m['nama'], 'wali' => $m['nama_wali'], 'kode' => $m['kode_member'], 'no' => waNomor($m['no_whatsapp'])];
            trOut(['ok' => true, 'rows' => $rows]);
        }

        if ($aksi === 'baru') {   // mulai chat dengan template (satu-satunya cara memulai di luar jendela 24 jam)
            trWajibPost();
            $b = trBody();
            $no = waNomor($b['no'] ?? '');
            if ($no === '') throw new TrException('Nomor WhatsApp tidak valid. Contoh: 0813-2244-5566.', 422);
            if (waBaileys()) {
                $isi = trim((string)($b['isi'] ?? ''));
                if (mb_strlen($isi) < 2) throw new TrException('Tulis isi pesan pembuka.', 422);
                if (mb_strlen($isi) > 1000) throw new TrException('Pesan pembuka maksimal 1.000 karakter.', 422);
                $c = waPercakapan($pdo, $no, null);
                try { waKirim($pdo, (int)$c['id'], $no, 'cs', $isi, null, true); }
                catch (TrException $e) {
                    $n = $pdo->prepare('SELECT COUNT(*) FROM pesan WHERE percakapan_id = :c'); $n->execute([':c' => $c['id']]);
                    if (!(int)$n->fetchColumn()) $pdo->prepare('DELETE FROM percakapan WHERE id = :c')->execute([':c' => $c['id']]);   // jangan tinggalkan chat kosong
                    throw $e;
                }
                $pdo->prepare("UPDATE percakapan SET status = 'cs', diambil_at = :t, ditangani_oleh = :u WHERE id = :i")->execute([':t' => date('Y-m-d H:i:s'), ':u' => trUserId($pdo), ':i' => $c['id']]);
                trOut(['ok' => true, 'id' => (int)$c['id'], 'pesan' => 'Pesan terkirim. Percakapan dibuka di daftar chat.']);
            }
            $tpl = trim((string)($b['template'] ?? '')); $bhs = trim((string)($b['bahasa'] ?? ''));
            if (!preg_match('/^[a-z0-9_]{1,512}$/', $tpl)) throw new TrException('Nama template hanya huruf kecil, angka, dan underscore.', 422);
            if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $bhs)) throw new TrException('Kode bahasa tidak valid (contoh: id atau en_US).', 422);
            $param = trim((string)($b['param'] ?? ''));
            $template = ['name' => $tpl, 'language' => ['code' => $bhs]];
            if ($param !== '') $template['components'] = [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($param, 0, 200)]]]];
            $r = waApi('POST', WA_PHONE_ID . '/messages', ['messaging_product' => 'whatsapp', 'to' => $no, 'type' => 'template', 'template' => $template]);
            $c = waPercakapan($pdo, $no, null);
            waSimpanPesan($pdo, (int)$c['id'], 'cs', '[Template: ' . $tpl . ']' . ($param !== '' ? ' ' . $param : ''), ['wa_id' => $r['messages'][0]['id'] ?? null, 'status_kirim' => 'sent']);
            $pdo->prepare("UPDATE percakapan SET status = 'cs', diambil_at = :t, ditangani_oleh = :u WHERE id = :i")->execute([':t' => date('Y-m-d H:i:s'), ':u' => trUserId($pdo), ':i' => $c['id']]);
            trOut(['ok' => true, 'id' => (int)$c['id'], 'pesan' => 'Pesan template terkirim. Percakapan dibuka di daftar chat.']);
        }

        if ($aksi === 'export') {
            $id = (int)($_GET['id'] ?? 0);
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . ($id ? 'chat-' . $id : 'chat-log') . '-' . date('Ymd') . '.csv"');
            $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
            if ($id) {
                fputcsv($o, ['Waktu', 'Pengirim', 'Isi', 'Status Kirim'], ',', '"', '');
                $s = $pdo->prepare('SELECT waktu, pengirim, isi, status_kirim FROM pesan WHERE percakapan_id = :i ORDER BY id'); $s->execute([':i' => $id]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) fputcsv($o, [$r['waktu'], $r['pengirim'], waCsv((string)$r['isi']), (string)$r['status_kirim']], ',', '"', '');
            } else {
                fputcsv($o, ['Nama', 'No. WhatsApp', 'Status', 'Jumlah Pesan', 'Pesan Terakhir', 'Waktu Terakhir'], ',', '"', '');
                $s = $pdo->query("SELECT c.id, c.no_whatsapp, c.status, c.diambil_at, c.member_id, c.nama_kontak, COALESCE(c.terakhir_at, c.mulai) AS t, m.nama AS member_nama, m.nama_wali,
                        (SELECT COUNT(*) FROM pesan p WHERE p.percakapan_id = c.id) AS n,
                        (SELECT p.isi FROM pesan p WHERE p.percakapan_id = c.id ORDER BY p.id DESC LIMIT 1) AS isi_akhir
                        FROM percakapan c LEFT JOIN members m ON m.id = c.member_id ORDER BY t DESC");
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) fputcsv($o, [waCsv(waNamaTampil($r)), waFormatNo((string)$r['no_whatsapp']), waChip($r)[1], $r['n'], waCsv((string)$r['isi_akhir']), $r['t']], ',', '"', '');
            }
            fclose($o); exit;
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
    <title>Chat Log — Natix Swim</title>
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

        .ch-wa { display: inline-flex; align-items: center; gap: 8px; border: 1px solid #bbf7d0; background: #f0fdf4; color: #15803d; font: inherit; font-size: 13px; font-weight: 600; padding: 8px 14px; border-radius: 10px; cursor: pointer; }
        .ch-wa i { width: 8px; height: 8px; border-radius: 50%; background: #16a34a; }
        .ch-wa.off { border-color: #fecaca; background: #fef2f2; color: #b91c1c; } .ch-wa.off i { background: #dc2626; }
        .ch-wa.cek { border-color: #e5e7eb; background: #f9fafb; color: #6b7280; } .ch-wa.cek i { background: #9ca3af; }
        .ch-bell { position: relative; width: 40px; height: 40px; border-radius: 12px; border: 1px solid #e5e7eb; background: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #4b5563; padding: 0; }
        .ch-bell b { position: absolute; top: -5px; right: -5px; background: #dc2626; color: #fff; font-size: 10px; min-width: 18px; height: 18px; border-radius: 99px; display: none; align-items: center; justify-content: center; padding: 0 4px; }
        .ch-right { display: flex; align-items: center; gap: 12px; }

        .ch-wrap { display: grid; grid-template-columns: 360px minmax(0, 1fr) 330px; height: calc(100vh - 290px); min-height: 560px; background: #fff; border: 1px solid #eceef2; border-radius: 16px; overflow: hidden; margin-top: 22px; }
        .ch-col { min-width: 0; min-height: 0; display: flex; flex-direction: column; }
        .ch-l { border-right: 1px solid #eef0f3; }
        .ch-tabs { display: flex; gap: 2px; background: #f3f4f8; border-radius: 12px; padding: 4px; margin: 16px; }
        .ch-tab { flex: 1; border: 0; background: transparent; font: inherit; font-size: 13px; font-weight: 500; color: #6b7280; padding: 9px 6px; border-radius: 9px; cursor: pointer; white-space: nowrap; }
        .ch-tab.on { background: #fff; color: #111827; font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
        .ch-items { flex: 1; overflow-y: auto; border-top: 1px solid #f1f2f5; }
        .ch-it { display: flex; gap: 12px; padding: 16px; border-bottom: 1px solid #f1f2f5; border-left: 3px solid transparent; cursor: pointer; }
        .ch-it:hover { background: #fafbfc; } .ch-it.on { background: #f3f6ff; border-left-color: #2563eb; }
        .ch-av { width: 40px; height: 40px; flex: 0 0 auto; border-radius: 50%; background: #eaefff; color: #2f4fd8; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
        .ch-it-b { min-width: 0; flex: 1; }
        .ch-it-t { display: flex; justify-content: space-between; gap: 8px; }
        .ch-it-n { font-size: 15px; font-weight: 700; color: #111827; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ch-it-j { font-size: 12px; color: #9ca3af; flex: 0 0 auto; }
        .ch-it-m { font-size: 13.5px; color: #4b5563; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ch-chip { display: inline-block; margin-top: 8px; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 7px; }
        .ch-chip.admin { background: #fff4e5; color: #d97706; } .ch-chip.ai { background: #e3f8ea; color: #16a34a; }
        .ch-chip.prospek { background: #e8efff; color: #2f55d4; } .ch-chip.selesai { background: #eef0f3; color: #6b7280; } .ch-chip.ditangani { background: #f3e8ff; color: #7e22ce; }
        .ch-none { padding: 40px 20px; text-align: center; color: #9ca3af; font-size: 14px; }

        .ch-c { background: #fff; }
        .ch-hd { display: flex; align-items: center; gap: 12px; padding: 14px 24px; border-bottom: 1px solid #eef0f3; }
        .ch-hd .ch-av { width: 38px; height: 38px; font-size: 12px; }
        .ch-hd-i { flex: 1; min-width: 0; }
        .ch-hd-n { font-size: 16px; font-weight: 700; margin: 0; } .ch-hd-s { font-size: 13px; color: #6b7280; margin: 2px 0 0; }
        .ch-hd .ad-btn { padding: 9px 15px; font-size: 13px; }
        .ch-msgs { flex: 1; overflow-y: auto; padding: 20px 28px; display: flex; flex-direction: column; gap: 14px; background: #fff; }
        .ch-day { align-self: center; font-size: 12px; color: #9ca3af; border: 1px solid #e5e7eb; border-radius: 99px; padding: 3px 14px; }
        .ch-b { max-width: 62%; padding: 14px 18px; border-radius: 12px; font-size: 14.5px; line-height: 1.55; white-space: pre-wrap; word-break: break-word; }
        .ch-b small { display: block; font-size: 12px; margin-top: 8px; opacity: .75; }
        .ch-b.m { align-self: flex-start; background: #fff; border: 1px solid #e5e7eb; color: #111827; }
        .ch-b.o { align-self: flex-end; background: #3b5bdb; color: #fff; }
        .ch-b.o.cs { background: #1e3a8a; }
        .ch-b .who { display: block; font-size: 11px; font-weight: 700; letter-spacing: .04em; margin-bottom: 6px; opacity: .9; }
        .ch-b img { max-width: 240px; max-height: 240px; border-radius: 8px; display: block; margin-bottom: 6px; cursor: zoom-in; }
        .ch-b a.doc { display: inline-block; background: rgba(0,0,0,.08); padding: 7px 12px; border-radius: 8px; color: inherit; text-decoration: none; font-weight: 600; margin-bottom: 6px; }
        .ch-warn { align-self: center; background: #fff7ed; color: #c2410c; border-radius: 10px; font-size: 13px; padding: 7px 18px; text-align: center; }
        .ch-bar { border-top: 1px solid #eef0f3; padding: 16px 24px; display: flex; gap: 10px; align-items: center; }
        .ch-bar textarea { flex: 1; resize: none; border: 1px solid #e5e7eb; border-radius: 12px; padding: 13px 16px; font: inherit; font-size: 14px; height: 48px; max-height: 120px; }
        .ch-bar textarea:focus { outline: 2px solid #bfdbfe; border-color: #93c5fd; }
        .ch-bar textarea:disabled { background: #f9fafb; }
        .ch-clip { width: 48px; height: 48px; border: 1px solid #e5e7eb; background: #fff; border-radius: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #4b5563; padding: 0; }
        .ch-clip:hover { background: #f3f4f6; }
        .ch-file { display: none; align-items: center; gap: 8px; padding: 8px 24px 0; font-size: 13px; color: #4b5563; }
        .ch-file button { border: 0; background: #fee2e2; color: #b91c1c; border-radius: 6px; cursor: pointer; padding: 2px 8px; font: inherit; }
        .ch-empty { flex: 1; display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 14px; text-align: center; padding: 30px; }

        .ch-p { border-left: 1px solid #eef0f3; padding: 26px 24px; overflow-y: auto; }
        .ch-p-top { text-align: center; padding-bottom: 18px; border-bottom: 1px solid #eef0f3; }
        .ch-p-av { width: 72px; height: 72px; border-radius: 50%; background: #eaefff; color: #2f4fd8; font-size: 20px; font-weight: 700; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; }
        .ch-p-n { font-size: 17px; font-weight: 700; margin: 0; } .ch-p-s { font-size: 13.5px; color: #6b7280; margin: 4px 0 0; }
        .ch-p-tag { display: inline-block; margin-top: 12px; font-size: 12px; font-weight: 600; background: #fff4e5; color: #d97706; padding: 5px 14px; border-radius: 8px; }
        .ch-p h4 { font-size: 12px; letter-spacing: .06em; color: #9ca3af; font-weight: 600; margin: 20px 0 10px; text-transform: uppercase; }
        .ch-p-r { display: flex; justify-content: space-between; gap: 12px; font-size: 14px; padding: 6px 0; } .ch-p-r span { color: #6b7280; } .ch-p-r b { text-align: right; }
        .ch-p-sar { background: #eaf1ff; color: #2748b8; border-radius: 12px; padding: 14px 16px; font-size: 13.5px; line-height: 1.55; }
        .ch-p .ad-btn { width: 100%; margin-top: 16px; font-size: 13px; }

        .ch-qr { text-align: center; margin-top: 14px; } .ch-qr img { width: 240px; height: 240px; border: 1px solid #eceef2; border-radius: 12px; padding: 6px; background: #fff; }
        .ch-gw { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; } .ch-risk { background: #fff7ed; color: #9a3412; border-radius: 10px; font-size: 12.5px; padding: 10px 12px; margin-top: 12px; line-height: 1.5; }
        .ch-kv { background: #f9fafb; border-radius: 12px; padding: 4px 14px; margin-top: 14px; }
        .ch-kv div { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 9px 0; border-bottom: 1px solid #f1f2f5; font-size: 13px; } .ch-kv div:last-child { border: 0; }
        .ch-kv span { color: #6b7280; flex: 0 0 auto; } .ch-kv b { text-align: right; word-break: break-all; }
        .ch-cp { border: 1px solid #e5e7eb; background: #fff; border-radius: 7px; font: inherit; font-size: 12px; cursor: pointer; padding: 3px 9px; margin-left: 8px; }
        .ch-sug { border: 1px solid #e5e7eb; border-radius: 10px; margin-top: 4px; overflow: hidden; display: none; }
        .ch-sug button { display: block; width: 100%; text-align: left; border: 0; background: #fff; padding: 9px 12px; font: inherit; font-size: 13px; cursor: pointer; border-bottom: 1px solid #f1f2f5; }
        .ch-sug button:hover { background: #f3f6ff; } .ch-sug small { color: #9ca3af; display: block; }
        .ch-lb { position: fixed; inset: 0; background: rgba(0,0,0,.85); display: none; align-items: center; justify-content: center; z-index: 2500; cursor: zoom-out; } .ch-lb.show { display: flex; } .ch-lb img { max-width: 92vw; max-height: 92vh; border-radius: 8px; }

        @media (max-width: 1200px) { .ch-wrap { grid-template-columns: 320px minmax(0, 1fr); } .ch-p { display: none; } }
        @media (max-width: 760px) { .ch-wrap { grid-template-columns: 1fr; height: auto; } .ch-c { min-height: 70vh; } .ch-l { max-height: 50vh; border-right: 0; border-bottom: 1px solid #eef0f3; } .ch-b { max-width: 85%; } }
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
        <header class="nv-topbar"><button type="button" class="nv-burger" aria-label="Buka menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="nv-top-right"><div class="nv-notif"><button type="button" class="nv-bell" id="nv-bell" aria-haspopup="true" aria-expanded="false" aria-controls="nv-panel" title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0"/></svg><b id="nv-bell-dot" hidden></b></button><div class="nv-panel" id="nv-panel" role="region" aria-label="Riwayat notifikasi" hidden><div class="nv-ph"><strong>Notifikasi</strong><button type="button" id="nv-readall">Tandai semua dibaca</button></div><div class="nv-list" id="nv-list"><div class="nv-empty">Memuat…</div></div></div></div><span class="nv-avatar" title="Admin Natix">AD</span></div><input type="hidden" id="ch-q" value=""></header>

        <div class="ad-page">
            <div class="nv-crumbbar"><?= nvCrumb() ?><div class="nv-tools"><button type="button" class="ch-wa cek" id="ch-wa"><i></i><span>Memeriksa koneksi...</span></button></div></div>
            <div class="ad-head">
                <div>
                    <h1 class="ad-title">Chat Log</h1>
                    <p class="ad-sub">Pantau percakapan AI dengan member dan ambil alih bila diperlukan</p>
                </div>
                <div class="ad-actions">
                    <a id="ch-export" hidden></a>
                    <button type="button" id="ch-baru" hidden></button>
                </div>
            </div>

            <div class="ch-wrap">
                <div class="ch-col ch-l">
                    <div class="ch-tabs" id="ch-tabs">
                        <button type="button" class="ch-tab on" data-t="semua">Semua</button>
                        <button type="button" class="ch-tab" data-t="admin">Butuh Admin</button>
                        <button type="button" class="ch-tab" data-t="selesai">Selesai</button>
                    </div>
                    <div class="ch-items" id="ch-items"><div class="ch-none">Memuat...</div></div>
                </div>

                <div class="ch-col ch-c" id="ch-c"><div class="ch-empty">Pilih percakapan di sebelah kiri untuk melihat pesan.</div></div>
                <div class="ch-col ch-p" id="ch-p"></div>
            </div>
        </div>
    </div>

    <!-- Koneksi WhatsApp -->
    <div class="ad-modal-bg" id="ch-m-wa">
        <div class="ad-modal">
            <h3>Koneksi WhatsApp Business</h3>
            <p class="lead" id="ch-wa-lead"></p>
            <div class="ch-qr" id="ch-wa-qr" style="display:none"><img alt="QR WhatsApp"><p class="ad-hint">Buka WhatsApp di HP &gt; Perangkat tertaut &gt; Tautkan perangkat, lalu pindai kode ini.</p></div>
            <div class="ch-kv" id="ch-wa-kv"></div>
            <div class="ch-gw" id="ch-wa-gw" style="display:none">
                <button type="button" class="ad-btn" id="ch-gw-sambung">Sambungkan Ulang</button>
                <button type="button" class="ad-btn" id="ch-gw-logout" style="color:#b91c1c">Putuskan Perangkat</button>
            </div>
            <div class="ch-risk" id="ch-wa-risk" style="display:none"><b>Penting:</b> koneksi QR memakai Baileys (tidak resmi). WhatsApp dapat memblokir nomor yang dipakai bot. Gunakan nomor khusus, jangan kirim pesan massal, dan jangan menghubungi orang yang belum pernah menulis lebih dulu.</div>
            <p class="ad-hint" id="ch-wa-cloud" style="margin-top:12px">Di Meta for Developers &gt; WhatsApp &gt; Configuration: tempel Callback URL dan Verify token di atas, lalu subscribe field <b>messages</b>. Token, Phone Number ID, dan App Secret diisi di bagian atas file <b>manajemen-ai-chat.php</b>.</p>
            <div class="ad-modal-foot">
                <button type="button" class="ad-btn" id="ch-wa-tutup">Tutup</button>
                <button type="button" class="ad-btn primary" id="ch-wa-uji">Uji Koneksi</button>
            </div>
        </div>
    </div>

    <!-- Chat baru -->
    <div class="ad-modal-bg" id="ch-m-baru">
        <div class="ad-modal">
            <h3>Chat Baru</h3>
            <p class="lead" id="ch-n-lead">Memulai percakapan di luar jendela 24 jam wajib memakai template pesan yang sudah disetujui Meta.</p>
            <div class="ad-field"><label for="ch-n-cari">Member atau Nomor WhatsApp</label>
                <input id="ch-n-cari" type="text" autocomplete="off" placeholder="Ketik nama member, kode, atau nomor (0813...)">
                <div class="ch-sug" id="ch-n-sug"></div></div>
            <div class="ad-field" id="ch-n-isi-w" style="display:none"><label for="ch-n-isi">Isi Pesan Pembuka</label><textarea id="ch-n-isi" rows="4" maxlength="1000" placeholder="Tulis pesan pembuka..."></textarea></div>
            <div class="ad-row2" id="ch-n-tpl-w">
                <div class="ad-field"><label for="ch-n-tpl">Nama Template</label><input id="ch-n-tpl" type="text" autocomplete="off"></div>
                <div class="ad-field"><label for="ch-n-bhs">Kode Bahasa</label><input id="ch-n-bhs" type="text" autocomplete="off"></div>
            </div>
            <div class="ad-field" id="ch-n-par-w"><label for="ch-n-par">Isi variabel {{1}} (opsional)</label><input id="ch-n-par" type="text" maxlength="200" placeholder="Hanya jika template punya variabel, mis. nama member"></div>
            <div class="ad-msg" id="ch-n-err"></div>
            <div class="ad-modal-foot">
                <button type="button" class="ad-btn" id="ch-n-batal">Batal</button>
                <button type="button" class="ad-btn primary" id="ch-n-kirim">Kirim</button>
            </div>
        </div>
    </div>

    <div class="ch-lb" id="ch-lb"><img alt=""></div>

<script>
var S = { tab: 'semua', q: '', id: 0, last: 0, tglAkhir: '', open: null };
var PROFIL = null, WA = null, SIBUK = false;
var $ = function (id) { return document.getElementById(id); };
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function pot(s, n) { s = String(s); return s.length > n ? s.slice(0, n - 1) + '…' : s; }
var IK = {
    klip: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5l-8.5 8.5a5.5 5.5 0 01-7.8-7.8l9-9a3.7 3.7 0 015.2 5.2l-9 9a1.8 1.8 0 01-2.6-2.6l8.3-8.3"/></svg>',
    kirim: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>',
    sampah: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>'
};

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

// ----- daftar percakapan -----
function muatDaftar() {
    return api('data', 'tab=' + S.tab + '&q=' + encodeURIComponent(S.q)).then(function (j) {
        var t = document.querySelectorAll('.ch-tab');
        t[0].textContent = 'Semua (' + j.hitung.semua + ')'; t[1].textContent = 'Butuh Admin (' + j.hitung.admin + ')'; t[2].textContent = 'Selesai';
        var n = document.getElementById('nv-badge'); if (n) { n.textContent = j.hitung.admin > 99 ? '99+' : j.hitung.admin; n.hidden = !j.hitung.admin; }
        try { sessionStorage.removeItem('nv-notif2'); } catch (e) {}
        $('ch-items').innerHTML = j.rows.length ? j.rows.map(function (r) {
            return '<div class="ch-it' + (r.id === S.id ? ' on' : '') + '" data-id="' + r.id + '"><div class="ch-av">' + esc(r.inisial) + '</div><div class="ch-it-b">'
                + '<div class="ch-it-t"><span class="ch-it-n">' + esc(r.nama) + '</span><span class="ch-it-j">' + esc(r.jam) + '</span></div>'
                + '<div class="ch-it-m">' + esc(r.isi) + '</div><span class="ch-chip ' + r.chip + '">' + esc(r.chip_label) + '</span></div></div>';
        }).join('') : '<div class="ch-none">' + (S.q || S.tab !== 'semua' ? 'Tidak ada percakapan yang cocok.' : 'Belum ada percakapan. Pesan WhatsApp yang masuk akan muncul di sini.') + '</div>';
    }).catch(function (e) { $('ch-items').innerHTML = '<div class="ch-none" style="color:#b91c1c">' + esc(e.message) + '</div>'; });
}
$('ch-tabs').addEventListener('click', function (ev) {
    var b = ev.target.closest('.ch-tab'); if (!b) return;
    S.tab = b.dataset.t; document.querySelectorAll('.ch-tab').forEach(function (x) { x.classList.toggle('on', x === b); }); muatDaftar();
});

var tmr; $('ch-q').addEventListener('input', function () { var v = this.value.trim(); clearTimeout(tmr); tmr = setTimeout(function () { S.q = v; muatDaftar(); }, 300); });
$('ch-items').addEventListener('click', function (ev) { var it = ev.target.closest('.ch-it'); if (it) buka(parseInt(it.dataset.id, 10)); });

// ----- percakapan -----
function kerangka() {
    $('ch-c').innerHTML = '<div class="ch-hd"><div class="ch-av" id="ch-h-av"></div><div class="ch-hd-i"><p class="ch-hd-n" id="ch-h-n"></p><p class="ch-hd-s" id="ch-h-s"></p></div><div id="ch-h-btn" style="display:flex;gap:8px"></div></div>'
        + '<div class="ch-msgs" id="ch-msgs"></div><div class="ch-file" id="ch-file"><span id="ch-file-n"></span><button type="button" id="ch-file-x">Hapus</button></div>'
        + '<div class="ch-bar"><textarea id="ch-teks" placeholder="Ketik balasan sebagai admin..." rows="1"></textarea>'
        + '<label class="ch-clip" title="Lampirkan gambar / PDF">' + IK.klip + '<input type="file" id="ch-berkas" accept="image/jpeg,image/png,image/webp,application/pdf" style="display:none"></label>'
        + '<button type="button" class="ad-btn primary" id="ch-kirim" style="height:48px">' + IK.kirim + 'Kirim</button></div>';
    $('ch-kirim').addEventListener('click', kirim);
    $('ch-teks').addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); kirim(); } });
    $('ch-teks').addEventListener('input', function () { this.style.height = '48px'; this.style.height = Math.min(120, this.scrollHeight) + 'px'; });
    $('ch-berkas').addEventListener('change', function () {
        var f = this.files[0]; if (!f) return;
        if (f.size > 5242880) { this.value = ''; return Dlg.gagal('File terlalu besar', 'Ukuran file maksimal 5 MB.'); }
        $('ch-file-n').textContent = '📎 ' + f.name; $('ch-file').style.display = 'flex';
    });
    $('ch-file-x').addEventListener('click', function () { $('ch-berkas').value = ''; $('ch-file').style.display = 'none'; });
    $('ch-msgs').addEventListener('click', function (ev) { var im = ev.target.closest('img[data-lb]'); if (im) { $('ch-lb').querySelector('img').src = im.src; $('ch-lb').classList.add('show'); } });
}
$('ch-lb').addEventListener('click', function () { this.classList.remove('show'); });

function buka(id) {
    S.id = id; S.last = 0; S.tglAkhir = ''; kerangka();
    document.querySelectorAll('.ch-it').forEach(function (x) { x.classList.toggle('on', parseInt(x.dataset.id, 10) === id); });
    $('ch-export').href = '?aksi=export&id=' + id;
    muatPesan(true);
}

function gelembung(m) {
    var h = '';
    if (m.tgl !== S.tglAkhir) { S.tglAkhir = m.tgl; h += '<div class="ch-day">' + esc(m.tgl_label) + '</div>'; }
    var kls = m.dari === 'member' ? 'm' : 'o' + (m.dari === 'cs' ? ' cs' : '');
    var isi = m.isi;
    var med = '';
    if (m.media === 'img') { med = '<img data-lb="1" src="?aksi=media&id=' + m.id + '" alt="Gambar">'; if (isi === '[Gambar]') isi = ''; }
    else if (m.media === 'pdf') { med = '<a class="doc" target="_blank" href="?aksi=media&id=' + m.id + '">📄 Buka dokumen</a><br>'; if (isi.indexOf('[Dokumen') === 0) isi = ''; }
    var tik = m.dari !== 'member' ? ({ sent: ' · Terkirim', delivered: ' · Diterima', read: ' · Dibaca ✓', failed: ' · Gagal terkirim' }[m.kirim] || '') : '';
    h += '<div class="ch-b ' + kls + '" data-m="' + m.id + '">' + (m.dari === 'bot' ? '<span class="who">🤖 AI ASSISTANT</span>' : (m.dari === 'cs' ? '<span class="who">ADMIN</span>' : '')) + med + esc(isi) + '<small>' + esc(m.jam) + esc(tik) + '</small></div>';
    return h;
}

function muatPesan(awal) {
    if (!S.id || SIBUK && !awal) return Promise.resolve();
    var id = S.id;
    return api('pesan', 'id=' + id + '&after=' + S.last + (awal ? '' : '')).then(function (j) {
        if (id !== S.id) return;
        S.open = j;
        var box = $('ch-msgs'); if (!box) return;
        var bawah = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
        if (j.pesan.length) {
            // hapus peringatan lama sebelum menambah pesan baru
            var w = box.querySelector('.ch-warn'); if (w) w.remove();
            box.insertAdjacentHTML('beforeend', j.pesan.map(gelembung).join(''));
            S.last = j.pesan[j.pesan.length - 1].id;
        }
        var lama = box.querySelector('.ch-warn'); if (lama) lama.remove();
        if (j.chip === 'admin') box.insertAdjacentHTML('beforeend', '<div class="ch-warn">⚠ AI belum yakin dengan jawabannya atau butuh tindakan. Chat dialihkan ke admin.</div>');
        if (j.pesan.length || awal) box.scrollTop = awal || bawah ? box.scrollHeight : box.scrollTop;
        if (j.panel) { PROFIL = j.panel; panel(j.panel); }
        header(j);
    }).catch(function (e) { if ($('ch-msgs') && awal) $('ch-msgs').innerHTML = '<div class="ch-empty" style="color:#b91c1c">' + esc(e.message) + '</div>'; });
}

function header(j) {
    var p = PROFIL; if (!p) return;
    $('ch-h-av').textContent = p.inisial; $('ch-h-n').textContent = p.nama;
    $('ch-h-s').textContent = p.no + (p.info.length ? ' • ' + p.info[1][1] : '');
    var b = '';
    if (j.status !== 'selesai') b += '<button type="button" class="ad-btn" data-a="selesai">Tandai Selesai</button>';
    if (j.status === 'selesai') b += '<button type="button" class="ad-btn primary" data-a="ai">Buka Kembali</button>';
    else if (j.status === 'cs' && j.diambil) b += '<button type="button" class="ad-btn" data-a="ai">Kembalikan ke AI</button>';
    else b += '<button type="button" class="ad-btn primary" data-a="ambil">Ambil Alih Chat</button>';
    b += '<button type="button" class="ad-btn danger" data-a="hapus" title="Hapus percakapan" style="padding:9px 11px">' + IK.sampah + '</button>';
    if ($('ch-h-btn').innerHTML !== b) $('ch-h-btn').innerHTML = b;
    var t = $('ch-teks'), ok = j.jendela_ok;
    t.disabled = !ok; t.placeholder = ok ? 'Ketik balasan sebagai admin...' : 'Jendela 24 jam berakhir. Gunakan "Chat Baru" untuk mengirim template.';
    $('ch-kirim').disabled = !ok;
}

function panel(p) {
    var h = '<div class="ch-p-top"><div class="ch-p-av">' + esc(p.inisial) + '</div><p class="ch-p-n">' + esc(p.nama) + '</p><p class="ch-p-s">' + esc(p.subjudul) + '</p>'
        + (p.tag ? '<span class="ch-p-tag">' + esc(p.tag) + '</span>' : '') + '</div>';
    h += '<h4>Info Member</h4>' + (p.member ? p.info.map(function (r) { return '<div class="ch-p-r"><span>' + esc(r[0]) + '</span><b>' + esc(r[1]) + '</b></div>'; }).join('')
        : '<div class="ch-p-r"><span>No. WhatsApp</span><b>' + esc(p.no) + '</b></div>');
    h += '<h4>Saran AI</h4><div class="ch-p-sar">💡 ' + esc(p.saran) + '</div>';
    if (p.profil) h += '<a class="ad-btn" href="' + esc(p.profil) + '">Lihat Profil Lengkap</a>';
    $('ch-p').innerHTML = h;
}

// ----- aksi chat -----
$('ch-c').addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-a]'); if (!b) return;
    var a = b.dataset.a, id = S.id, nm = PROFIL ? PROFIL.nama : '';
    var cfg = {
        selesai: ['Tandai percakapan selesai?', 'Percakapan dipindahkan ke tab Selesai. Jika member mengirim pesan lagi, chat terbuka kembali dan dijawab AI.', 'Ya, tandai selesai', 'tanya'],
        ambil: ['Ambil alih chat ini?', 'AI akan berhenti membalas dan Anda yang menjawab member ini.', 'Ya, ambil alih', 'tanya'],
        ai: ['Kembalikan ke AI?', 'Pesan berikutnya dari member akan dijawab otomatis oleh AI.', 'Ya, kembalikan', 'tanya'],
        hapus: ['Hapus percakapan ini?', 'Seluruh pesan dan file di percakapan ini dihapus permanen dan tidak bisa dikembalikan.', 'Ya, hapus', 'bahaya']
    }[a];
    Dlg.konfirmasi(cfg[0], cfg[1], { ya: cfg[2], tipe: cfg[3], rincian: [['Percakapan', nm]] }).then(function (y) {
        if (!y) return;
        var p = a === 'hapus' ? api('hapus', '', { id: id }) : api('status_chat', '', { id: id, ke: a });
        return p.then(function (j) {
            return Dlg.sukses(a === 'hapus' ? 'Berhasil dihapus' : 'Berhasil', j.pesan).then(function () {
                if (a === 'hapus') { S.id = 0; PROFIL = null; $('ch-c').innerHTML = '<div class="ch-empty">Pilih percakapan di sebelah kiri untuk melihat pesan.</div>'; $('ch-p').innerHTML = ''; $('ch-export').href = '?aksi=export'; muatDaftar(); }
                else { muatDaftar(); S.last = 0; S.tglAkhir = ''; $('ch-msgs').innerHTML = ''; muatPesan(true); }
            });
        }).catch(function (e) { return Dlg.gagal('Gagal', e.message); });
    });
});

function kirim() {
    var t = $('ch-teks'), f = $('ch-berkas').files[0], teks = t.value.trim();
    if (!teks && !f) return;
    if (t.disabled) return;
    var fd = new FormData(); fd.append('id', S.id); fd.append('teks', teks); if (f) fd.append('berkas', f);
    $('ch-kirim').disabled = true; SIBUK = true;
    api('kirim', '', fd, true).then(function () {
        t.value = ''; t.style.height = '48px'; $('ch-berkas').value = ''; $('ch-file').style.display = 'none';
        SIBUK = false; return muatPesan(false).then(muatDaftar);
    }).catch(function (e) { SIBUK = false; $('ch-kirim').disabled = false; return Dlg.gagal('Pesan gagal dikirim', e.message); });
}

// ----- koneksi WhatsApp -----
function cekWa(tampil) {
    return api('status').then(function (j) {
        WA = j;
        var b = $('ch-wa'); b.className = 'ch-wa ' + (j.terhubung ? '' : 'off');
        b.querySelector('span').textContent = j.terhubung ? 'WhatsApp Terhubung' : (j.state === 'qr' ? 'Pindai QR untuk Menghubungkan' : 'WhatsApp Belum Terhubung');
        if (j.peringatan) console.warn(j.peringatan);
        if (tampil) tampilWa(); else if ($('ch-m-wa').classList.contains('show')) tampilWa();
    }).catch(function (e) { $('ch-wa').className = 'ch-wa off'; $('ch-wa').querySelector('span').textContent = 'Koneksi bermasalah'; if (tampil) Dlg.gagal('Gagal memeriksa koneksi', e.message); });
}
function kv(a, b, salin) { return '<div><span>' + esc(a) + '</span><b>' + esc(b) + (salin ? '<button type="button" class="ch-cp" data-cp="' + esc(b) + '">Salin</button>' : '') + '</b></div>'; }
function tampilWa() {
    var j = WA; if (!j) return;
    var bl = j.provider === 'baileys';
    $('ch-wa-qr').style.display = bl && j.qr ? 'block' : 'none'; if (bl && j.qr) $('ch-wa-qr').querySelector('img').src = j.qr;
    $('ch-wa-gw').style.display = bl ? 'flex' : 'none'; $('ch-wa-risk').style.display = bl ? 'block' : 'none'; $('ch-wa-cloud').style.display = bl ? 'none' : 'block';
    $('ch-wa-lead').textContent = bl ? (j.terhubung ? 'Terhubung lewat gateway Baileys.' : (j.alasan || 'Belum terhubung.')) : (j.terhubung ? 'Terhubung ke WhatsApp Business Cloud API.' : 'Belum terhubung: ' + (j.alasan || 'periksa konfigurasi.'));
    if (bl) {
        $('ch-wa-kv').innerHTML = kv('Status', j.terhubung ? 'Terhubung' : (j.state === 'qr' ? 'Menunggu pindai QR' : 'Tidak terhubung'))
            + (j.terhubung ? kv('Nomor', j.nomor ? '+' + j.nomor : '-') + kv('Nama akun', j.nama || '-') : '')
            + (j.token_bawaan ? kv('Keamanan', 'GW_TOKEN masih bawaan — ganti!') : '');
        $('ch-m-wa').classList.add('show'); return;
    }
    $('ch-wa-kv').innerHTML = kv('Status', j.terhubung ? 'Terhubung' : 'Tidak terhubung')
        + (j.terhubung ? kv('Nomor', j.nomor) + kv('Nama terverifikasi', j.nama) + kv('Kualitas nomor', j.kualitas || '-') : '')
        + kv('Callback URL', j.webhook_url, true) + kv('Verify token', j.verify_token, true)
        + kv('Verifikasi tanda tangan', j.aman ? 'Aktif (App Secret terisi)' : 'Belum aktif — isi WA_APP_SECRET') + kv('HTTPS', j.https ? 'Ya' : 'Tidak — Meta mewajibkan HTTPS publik');
    $('ch-m-wa').classList.add('show');
}
$('ch-wa').addEventListener('click', function () { if (WA) tampilWa(); else cekWa(true); });
$('ch-wa-tutup').addEventListener('click', function () { $('ch-m-wa').classList.remove('show'); });
$('ch-m-wa').addEventListener('mousedown', function (ev) { if (ev.target === this) this.classList.remove('show'); });
$('ch-wa-uji').addEventListener('click', function () {
    var b = this; b.disabled = true;
    cekWa(false).then(function () { b.disabled = false; $('ch-m-wa').classList.remove('show'); return WA.terhubung ? Dlg.sukses('Koneksi berhasil', 'Tersambung ke ' + (WA.nama || 'WhatsApp Business') + ' ' + (WA.nomor || '') + '.') : Dlg.gagal('Koneksi gagal', WA.alasan || 'Periksa token dan Phone Number ID.'); });
});
function gwAksi(ke, judul, pesan, bahaya) {
    return Dlg.konfirmasi(judul, pesan, { ya: 'Ya, lanjutkan', tipe: bahaya ? 'bahaya' : 'tanya' }).then(function (y) {
        if (!y) return;
        return api('gw_aksi', '', { ke: ke }).then(function (j) { return new Promise(function (r) { setTimeout(r, 2500); }).then(function () { return cekWa(false); }).then(function () { return Dlg.sukses('Berhasil', j.pesan); }); })
            .catch(function (e) { return Dlg.gagal('Gagal', e.message); });
    });
}
$('ch-gw-sambung').addEventListener('click', function () { gwAksi('sambung', 'Sambungkan ulang?', 'Gateway akan mencoba menyambung ke WhatsApp lagi.', false); });
$('ch-gw-logout').addEventListener('click', function () { gwAksi('logout', 'Putuskan perangkat?', 'Sesi WhatsApp dihapus dan bot berhenti membalas sampai QR dipindai lagi.', true); });
setInterval(function () { if ($('ch-m-wa').classList.contains('show') && WA && WA.provider === 'baileys' && !WA.terhubung && !document.hidden) cekWa(false); }, 4000);
$('ch-wa-kv').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-cp]'); if (!b) return;
    var v = b.dataset.cp; (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).then(function () { b.textContent = 'Tersalin'; }, function () { window.prompt('Salin teks ini:', v); });
});

// ----- chat baru (template) -----
var NO = '';
function tutupBaru() { $('ch-m-baru').classList.remove('show'); }
function baruKotor() { return $('ch-n-cari').value.trim() !== '' || $('ch-n-par').value.trim() !== '' || $('ch-n-isi').value.trim() !== ''; }
function tolakBaru() { if (!baruKotor()) return tutupBaru(); Dlg.konfirmasi('Batalkan chat baru?', 'Data yang sudah diisi akan hilang.', { ya: 'Ya, batalkan', tidak: 'Lanjut mengisi', tipe: 'bahaya' }).then(function (y) { if (y) tutupBaru(); }); }
$('ch-baru').addEventListener('click', function () {
    NO = ''; $('ch-n-cari').value = ''; $('ch-n-par').value = ''; $('ch-n-sug').style.display = 'none'; $('ch-n-err').classList.remove('show'); $('ch-n-kirim').disabled = false;
    var bl = WA && WA.provider === 'baileys'; $('ch-n-isi').value = '';
    $('ch-n-isi-w').style.display = bl ? 'block' : 'none'; $('ch-n-tpl-w').style.display = bl ? 'none' : ''; $('ch-n-par-w').style.display = bl ? 'none' : '';
    $('ch-n-lead').textContent = bl ? 'Kirim pesan pembuka ke member. Gunakan hanya untuk orang yang memang berhubungan dengan Natix Swim agar nomor tidak diblokir.' : 'Memulai percakapan di luar jendela 24 jam wajib memakai template pesan yang sudah disetujui Meta.';
    if (!bl) { $('ch-n-tpl').value = WA ? WA.template : 'hello_world'; $('ch-n-bhs').value = WA ? WA.bahasa : 'en_US'; }
    $('ch-m-baru').classList.add('show'); $('ch-n-cari').focus();
});
$('ch-n-batal').addEventListener('click', tolakBaru);
$('ch-m-baru').addEventListener('mousedown', function (ev) { if (ev.target === this) tolakBaru(); });
var tcari; $('ch-n-cari').addEventListener('input', function () {
    NO = ''; var v = this.value.trim(); clearTimeout(tcari);
    if (v.length < 2) { $('ch-n-sug').style.display = 'none'; return; }
    tcari = setTimeout(function () {
        api('cari', 'q=' + encodeURIComponent(v)).then(function (j) {
            var s = $('ch-n-sug'); s.innerHTML = j.rows.map(function (r, i) { return '<button type="button" data-i="' + i + '">' + esc(r.nama) + ' <small>' + esc((r.wali ? 'Wali: ' + r.wali + ' • ' : '') + r.kode + ' • +' + r.no) + '</small></button>'; }).join('');
            s.style.display = j.rows.length ? 'block' : 'none'; s._r = j.rows;
        }).catch(function () {});
    }, 250);
});
$('ch-n-sug').addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-i]'); if (!b) return;
    var r = this._r[parseInt(b.dataset.i, 10)]; NO = r.no; $('ch-n-cari').value = r.nama + ' (+' + r.no + ')'; $('ch-n-par').value = r.wali || r.nama;
    if (WA && WA.provider === 'baileys' && !$('ch-n-isi').value) $('ch-n-isi').value = 'Halo ' + (r.wali || r.nama) + ', ini Natix Swim. '; this.style.display = 'none';
});
$('ch-n-kirim').addEventListener('click', function () {
    var btn = this, err = $('ch-n-err'); err.classList.remove('show');
    var no = NO || $('ch-n-cari').value.trim();
    if (!no) { err.textContent = 'Pilih member atau isi nomor WhatsApp.'; return err.classList.add('show'); }
    var bl = WA && WA.provider === 'baileys';
    var d = bl ? { no: no, isi: $('ch-n-isi').value.trim() } : { no: no, template: $('ch-n-tpl').value.trim(), bahasa: $('ch-n-bhs').value.trim(), param: $('ch-n-par').value.trim() };
    if (bl && d.isi.length < 2) { err.textContent = 'Tulis isi pesan pembuka.'; return err.classList.add('show'); }
    Dlg.konfirmasi(bl ? 'Kirim pesan?' : 'Kirim pesan template?', 'Pesan akan dikirim ke WhatsApp dan percakapan dibuat di daftar chat.', { ya: 'Ya, kirim', rincian: bl ? [['Tujuan', no], ['Pesan', d.isi.length > 60 ? d.isi.slice(0, 60) + '…' : d.isi]] : [['Tujuan', no], ['Template', d.template + ' (' + d.bahasa + ')']] }).then(function (y) {
        if (!y) return;
        btn.disabled = true;
        return api('baru', '', d).then(function (j) {
            tutupBaru(); return Dlg.sukses('Pesan terkirim', j.pesan).then(function () { S.tab = 'semua'; document.querySelectorAll('.ch-tab').forEach(function (x) { x.classList.toggle('on', x.dataset.t === 'semua'); }); return muatDaftar().then(function () { buka(j.id); }); });
        }).catch(function (e) { btn.disabled = false; return Dlg.gagal('Gagal mengirim', e.message); });
    });
});
document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !document.querySelector('.ad-dlg-bg.show')) { $('ch-m-wa').classList.remove('show'); $('ch-lb').classList.remove('show'); if ($('ch-m-baru').classList.contains('show')) tolakBaru(); } });

// ----- awal & polling -----
muatDaftar(); cekWa(false);
setInterval(function () { if (document.hidden) return; muatDaftar(); muatPesan(false); }, 6000);
setInterval(function () { if (!document.hidden) cekWa(false); }, 120000);
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