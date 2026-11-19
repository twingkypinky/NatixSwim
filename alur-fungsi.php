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
