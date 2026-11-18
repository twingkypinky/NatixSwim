<?php
// ============================================================
// gaji-fungsi.php — logika Gaji Coach (gaji per sesi): hitung, alur status, slip, WhatsApp
// Tidak ada SQL di sini — semua akses database lewat gaji-query.php.
// Dipakai oleh: gaji.php (halaman admin), cron-gaji.php (kirim slip otomatis),
//               manajemen-ai-chat.php (chat WhatsApp "Check Gaji Bulan Ini")
// ============================================================
require_once __DIR__ . '/gaji-query.php';

foreach ([
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

// ─── WHATSAPP ───
function gjKirimWa(PDO $pdo, int $id): array   // -> [ok, pesan]
{
    $g = gjAmbil($pdo, $id);
    if (!$g) return [false, 'Data gaji tidak ditemukan.'];
    if ($g['status'] !== 'dibayar') return [false, 'Slip resmi hanya dikirim setelah gaji berstatus Dibayar.'];
    $no = gjNomor($g['hp']);
    $gagal = function (string $pesan) use ($pdo, $id) {
        gqSetWa($pdo, $id, 'gagal', mb_substr($pesan, 0, 250), date('Y-m-d H:i:s'));
        return [false, $pesan];
    };
    if ($no === '') return $gagal('Nomor WhatsApp coach belum diisi / tidak valid (isi di Data Pelatih).');
    if (!function_exists('curl_init')) return $gagal('Ekstensi PHP cURL belum aktif di server.');
    $ch = curl_init(rtrim(GW_URL, '/') . '/kirim');
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['x-gateway-token: ' . GW_TOKEN, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['to' => $no, 'text' => gjSlipTeks($g), 'cek' => false], JSON_UNESCAPED_UNICODE)]);
    $raw = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return $gagal('Gateway WhatsApp tidak aktif (' . $err . ').');
    $j = json_decode((string)$raw, true);
    if ($http >= 400 || !is_array($j) || empty($j['ok'])) return $gagal(is_array($j) && !empty($j['pesan']) ? (string)$j['pesan'] : "Gateway menolak permintaan (HTTP $http).");
    gqSetWa($pdo, $id, 'terkirim', null, date('Y-m-d H:i:s'));
    return [true, 'Slip gaji terkirim ke WhatsApp ' . $g['nama'] . '.'];
}

// Dipanggil harian (cron-gaji.php). Tgl "hari_kirim" (default 1): hitung draft bulan lalu.
// Setiap hari: kirim slip yang sudah DIBAYAR tapi belum terkirim (3 bulan terakhir).
function gjCron(PDO $pdo, ?DateTime $sekarang = null): array
{
    gjMigrasi($pdo);
    $sekarang = $sekarang ?: new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $lap = ['draft_dibuat' => 0, 'terkirim' => 0, 'gagal' => 0, 'dilewati' => ''];
    $hariKirim = max(1, min(28, (int)gjPengaturan($pdo, 'hari_kirim', '1')));
    if ((int)$sekarang->format('j') === $hariKirim) {
        $lalu = (clone $sekarang)->modify('first day of last month');
        $lap['draft_dibuat'] = gjHitung($pdo, (int)$lalu->format('Y'), (int)$lalu->format('n'), $sekarang);
    }
    if (gjPengaturan($pdo, 'auto_wa', '1') !== '1') { $lap['dilewati'] = 'Pengiriman otomatis dimatikan di pengaturan.'; return $lap; }
    $batas = (int)$sekarang->format('Y') * 12 + (int)$sekarang->format('n') - 2;
    foreach (gqBelumTerkirim($pdo) as $r) {
        if ((int)$r['tahun'] * 12 + (int)$r['bulan'] < $batas) continue;
        [$ok] = gjKirimWa($pdo, (int)$r['id']);
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
