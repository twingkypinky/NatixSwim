<?php
// ============================================================
// gaji-query.php — SEMUA query SQL fitur Gaji Coach (lapisan data)
// Tidak ada logika bisnis di sini: hanya baca / tulis tabel dan mengembalikan array mentah.
// Logika (hitung, alur status, slip, WhatsApp) ada di gaji-fungsi.php.
// Tabel (dibuat otomatis): gaji_tarif, gaji, gaji_pengaturan
// Kolom tambahan (otomatis): laporan_keuangan.total_pengeluaran
// ============================================================

// ─── SKEMA ───
function gqAdaKolom(PDO $pdo, string $tabel, string $kolom): bool
{
    try { $pdo->query("SELECT $kolom FROM $tabel LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

function gqDdl(PDO $pdo, string $sql): string   // DDL ditulis untuk MySQL; driver lain (uji coba) disesuaikan
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') return $sql;
    $sql = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/UNIQUE KEY \w+ \(/', 'UNIQUE (', $sql);
    return preg_replace('/\) ENGINE=.*$/', ')', $sql);
}

function gqMigrasi(PDO $pdo, array $tarifAwal): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $my = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    foreach ([
        "CREATE TABLE IF NOT EXISTS gaji_tarif (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pelatih_id INT NOT NULL DEFAULT 0,
            jenis VARCHAR(10) NOT NULL,
            tarif INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_gaji_tarif (pelatih_id, jenis)
        )$my",
        "CREATE TABLE IF NOT EXISTS gaji (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tahun SMALLINT NOT NULL,
            bulan TINYINT NOT NULL,
            pelatih_id INT NOT NULL,
            sesi_kids INT NOT NULL DEFAULT 0,
            sesi_dewasa INT NOT NULL DEFAULT 0,
            sesi_private INT NOT NULL DEFAULT 0,
            gaji_sesi BIGINT NOT NULL DEFAULT 0,
            bonus BIGINT NOT NULL DEFAULT 0,
            potongan BIGINT NOT NULL DEFAULT 0,
            catatan VARCHAR(255) NULL,
            total BIGINT NOT NULL DEFAULT 0,
            rincian TEXT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'draft',
            metode VARCHAR(20) NULL,
            tanggal_bayar DATE NULL,
            wa_status VARCHAR(10) NOT NULL DEFAULT 'belum',
            wa_waktu DATETIME NULL,
            wa_error VARCHAR(255) NULL,
            dibuat_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_gaji_periode (tahun, bulan, pelatih_id)
        )$my",
        "CREATE TABLE IF NOT EXISTS gaji_pengaturan (
            kunci VARCHAR(40) NOT NULL PRIMARY KEY,
            nilai VARCHAR(255) NOT NULL
        )$my",
    ] as $ddl) $pdo->exec(gqDdl($pdo, $ddl));

    if (!(int)$pdo->query('SELECT COUNT(*) FROM gaji_tarif WHERE pelatih_id = 0')->fetchColumn()) {
        $ins = $pdo->prepare('INSERT INTO gaji_tarif (pelatih_id, jenis, tarif) VALUES (0, :j, :t)');
        foreach ($tarifAwal as $j => $t) $ins->execute([':j' => $j, ':t' => $t]);
    }
    if (gqAdaKolom($pdo, 'laporan_keuangan', 'id') && !gqAdaKolom($pdo, 'laporan_keuangan', 'total_pengeluaran')) {
        try { $pdo->exec('ALTER TABLE laporan_keuangan ADD COLUMN total_pengeluaran BIGINT NOT NULL DEFAULT 0'); }
        catch (Throwable $e) { error_log('[gaji] gagal menambah total_pengeluaran: ' . $e->getMessage()); }
    }
}

// ─── PENGATURAN ───
function gqPengaturan(PDO $pdo, string $kunci): ?string
{
    $st = $pdo->prepare('SELECT nilai FROM gaji_pengaturan WHERE kunci = :k');
    $st->execute([':k' => $kunci]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function gqSimpanPengaturan(PDO $pdo, string $kunci, string $nilai): void
{
    $st = $pdo->prepare('UPDATE gaji_pengaturan SET nilai = :v WHERE kunci = :k');
    $st->execute([':v' => $nilai, ':k' => $kunci]);
    if ($st->rowCount()) return;
    $cek = $pdo->prepare('SELECT COUNT(*) FROM gaji_pengaturan WHERE kunci = :k');
    $cek->execute([':k' => $kunci]);
    if (!(int)$cek->fetchColumn()) $pdo->prepare('INSERT INTO gaji_pengaturan (kunci, nilai) VALUES (:k, :v)')->execute([':k' => $kunci, ':v' => $nilai]);
}

// ─── TARIF ───
function gqTarifSemua(PDO $pdo): array   // -> baris [pelatih_id, jenis, tarif]
{
    return $pdo->query('SELECT pelatih_id, jenis, tarif FROM gaji_tarif')->fetchAll(PDO::FETCH_ASSOC);
}

function gqHapusTarif(PDO $pdo, int $pelatihId, string $jenis): void
{
    $pdo->prepare('DELETE FROM gaji_tarif WHERE pelatih_id = :p AND jenis = :j')->execute([':p' => $pelatihId, ':j' => $jenis]);
}

function gqSetTarif(PDO $pdo, int $pelatihId, string $jenis, int $tarif): void
{
    $st = $pdo->prepare('UPDATE gaji_tarif SET tarif = :t WHERE pelatih_id = :p AND jenis = :j');
    $st->execute([':t' => $tarif, ':p' => $pelatihId, ':j' => $jenis]);
    $cek = $pdo->prepare('SELECT COUNT(*) FROM gaji_tarif WHERE pelatih_id = :p AND jenis = :j');
    $cek->execute([':p' => $pelatihId, ':j' => $jenis]);
    if (!(int)$cek->fetchColumn()) $pdo->prepare('INSERT INTO gaji_tarif (pelatih_id, jenis, tarif) VALUES (:p, :j, :t)')->execute([':p' => $pelatihId, ':j' => $jenis, ':t' => $tarif]);
}

// ─── PELATIH & JADWAL (tabel milik fitur lain, hanya dibaca) ───
// No. HP bisa ada di pelatih.no_hp dan/atau users.no_hp
function gqExprHp(PDO $pdo): string
{
    $dp = gqAdaKolom($pdo, 'pelatih', 'no_hp');
    $du = gqAdaKolom($pdo, 'users', 'no_hp');
    if ($dp && $du) return "COALESCE(NULLIF(pl.no_hp, ''), u.no_hp)";
    if ($dp) return 'pl.no_hp';
    if ($du) return 'u.no_hp';
    return 'NULL';
}

function gqDaftarPelatih(PDO $pdo): array   // -> [id, nama, hp]
{
    $hp = gqExprHp($pdo);
    return $pdo->query("SELECT pl.id, u.nama, $hp AS hp FROM pelatih pl JOIN users u ON u.id = pl.user_id ORDER BY u.nama")->fetchAll(PDO::FETCH_ASSOC);
}

function gqJadwalBulan(PDO $pdo, string $awal, string $akhir): array   // jadwal tidak dibatalkan pada rentang tanggal
{
    $hp = gqExprHp($pdo);
    $st = $pdo->prepare("
        SELECT j.pelatih_id, j.tanggal, j.jam_selesai, j.status, k.nama_program, k.tipe, u.nama AS pelatih, $hp AS hp
          FROM jadwal j
          JOIN kelas_program k ON k.id = j.program_id
          JOIN pelatih pl      ON pl.id = j.pelatih_id
          JOIN users u         ON u.id = pl.user_id
         WHERE j.tanggal BETWEEN :s AND :e AND j.status <> 'dibatalkan'");
    $st->execute([':s' => $awal, ':e' => $akhir]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ─── GAJI ───
function gqGajiPeriodePelatih(PDO $pdo, int $tahun, int $bulan, int $pelatihId): ?array
{
    $st = $pdo->prepare('SELECT id, status, bonus, potongan FROM gaji WHERE tahun = :t AND bulan = :b AND pelatih_id = :p');
    $st->execute([':t' => $tahun, ':b' => $bulan, ':p' => $pelatihId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function gqTambahGaji(PDO $pdo, int $tahun, int $bulan, int $pelatihId, array $sesi, int $gajiSesi, int $total, string $rincian, string $now): void
{
    $pdo->prepare('INSERT INTO gaji (tahun, bulan, pelatih_id, sesi_kids, sesi_dewasa, sesi_private, gaji_sesi, total, rincian, dibuat_at, updated_at)
                   VALUES (:t,:b,:p,:k,:d,:v,:g,:tot,:r,:n,:n2)')
        ->execute([':t' => $tahun, ':b' => $bulan, ':p' => $pelatihId, ':k' => $sesi['kids'], ':d' => $sesi['dewasa'], ':v' => $sesi['private'],
                   ':g' => $gajiSesi, ':tot' => $total, ':r' => $rincian, ':n' => $now, ':n2' => $now]);
}

function gqUpdateDraft(PDO $pdo, int $id, array $sesi, int $gajiSesi, int $total, ?string $rincian, string $now): void
{
    $pdo->prepare('UPDATE gaji SET sesi_kids=:k, sesi_dewasa=:d, sesi_private=:v, gaji_sesi=:g, total=:tot, rincian=:r, updated_at=:n WHERE id=:id')
        ->execute([':k' => $sesi['kids'], ':d' => $sesi['dewasa'], ':v' => $sesi['private'], ':g' => $gajiSesi, ':tot' => $total, ':r' => $rincian, ':n' => $now, ':id' => $id]);
}

function gqDraftPeriode(PDO $pdo, int $tahun, int $bulan): array
{
    $st = $pdo->prepare("SELECT id, pelatih_id, bonus, potongan FROM gaji WHERE tahun = :t AND bulan = :b AND status = 'draft'");
    $st->execute([':t' => $tahun, ':b' => $bulan]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function gqDaftarGaji(PDO $pdo, int $tahun, int $bulan): array
{
    $hp = gqExprHp($pdo);
    $st = $pdo->prepare("SELECT g.*, u.nama AS nama, $hp AS hp FROM gaji g JOIN pelatih pl ON pl.id = g.pelatih_id JOIN users u ON u.id = pl.user_id
                          WHERE g.tahun = :t AND g.bulan = :b ORDER BY u.nama");
    $st->execute([':t' => $tahun, ':b' => $bulan]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function gqAmbilGaji(PDO $pdo, int $id): ?array
{
    $hp = gqExprHp($pdo);
    $st = $pdo->prepare("SELECT g.*, u.nama AS nama, $hp AS hp FROM gaji g JOIN pelatih pl ON pl.id = g.pelatih_id JOIN users u ON u.id = pl.user_id WHERE g.id = :id");
    $st->execute([':id' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function gqIdGaji(PDO $pdo, int $tahun, int $bulan, int $pelatihId): ?int
{
    $st = $pdo->prepare('SELECT id FROM gaji WHERE tahun = :t AND bulan = :b AND pelatih_id = :p');
    $st->execute([':t' => $tahun, ':b' => $bulan, ':p' => $pelatihId]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

function gqSetPenyesuaian(PDO $pdo, int $id, int $bonus, int $potongan, ?string $catatan, int $total, string $now): void
{
    $pdo->prepare('UPDATE gaji SET bonus=:b, potongan=:p, catatan=:c, total=:t, updated_at=:n WHERE id=:id')
        ->execute([':b' => $bonus, ':p' => $potongan, ':c' => $catatan, ':t' => $total, ':n' => $now, ':id' => $id]);
}

function gqSetDisetujui(PDO $pdo, int $id, string $now): void
{
    $pdo->prepare("UPDATE gaji SET status='disetujui', updated_at=:n WHERE id=:id")->execute([':n' => $now, ':id' => $id]);
}

function gqSetDraft(PDO $pdo, int $id, string $now): void
{
    $pdo->prepare("UPDATE gaji SET status='draft', updated_at=:n WHERE id=:id")->execute([':n' => $now, ':id' => $id]);
}

function gqSetDibayar(PDO $pdo, int $id, string $metode, string $tanggal, string $now): void
{
    $pdo->prepare("UPDATE gaji SET status='dibayar', metode=:m, tanggal_bayar=:t, wa_status='belum', wa_error=NULL, updated_at=:n WHERE id=:id")
        ->execute([':m' => $metode, ':t' => $tanggal, ':n' => $now, ':id' => $id]);
}

function gqBatalDibayar(PDO $pdo, int $id, string $now): void
{
    $pdo->prepare("UPDATE gaji SET status='disetujui', metode=NULL, tanggal_bayar=NULL, updated_at=:n WHERE id=:id")->execute([':n' => $now, ':id' => $id]);
}

function gqSetWa(PDO $pdo, int $id, string $status, ?string $error, string $waktu): void
{
    $pdo->prepare('UPDATE gaji SET wa_status=:s, wa_error=:e, wa_waktu=:w WHERE id=:id')->execute([':s' => $status, ':e' => $error, ':w' => $waktu, ':id' => $id]);
}

function gqBelumTerkirim(PDO $pdo): array   // gaji dibayar yang slipnya belum terkirim
{
    return $pdo->query("SELECT id, tahun, bulan FROM gaji WHERE status = 'dibayar' AND wa_status <> 'terkirim' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

// ─── LAPORAN KEUANGAN ───
function gqTotalDibayar(PDO $pdo, int $tahun, int $bulan): int
{
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM gaji WHERE tahun = :t AND bulan = :b AND status = 'dibayar'");
        $st->execute([':t' => $tahun, ':b' => $bulan]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

// Isi laporan_keuangan.total_pengeluaran. Bulan yang belum punya catatan dibuat dulu (pendapatan diambil dari pembayaran
// supaya angka dashboard tidak jadi 0).
function gqSetPengeluaranLaporan(PDO $pdo, int $tahun, int $bulan, int $total): void
{
    if (!gqAdaKolom($pdo, 'laporan_keuangan', 'total_pengeluaran')) return;
    $ada = $pdo->prepare('SELECT id FROM laporan_keuangan WHERE tahun = :t AND bulan = :b');
    $ada->execute([':t' => $tahun, ':b' => $bulan]);
    $id = $ada->fetchColumn();
    if ($id) {
        $pdo->prepare('UPDATE laporan_keuangan SET total_pengeluaran = :x WHERE id = :id')->execute([':x' => $total, ':id' => $id]);
    } elseif ($total > 0) {
        $awal = sprintf('%04d-%02d-01', $tahun, $bulan);
        $p = $pdo->prepare('SELECT COALESCE(SUM(jumlah), 0) FROM pembayaran WHERE tanggal_bayar BETWEEN :s AND :e');
        $p->execute([':s' => $awal . ' 00:00:00', ':e' => date('Y-m-t', strtotime($awal)) . ' 23:59:59']);
        $pdo->prepare('INSERT INTO laporan_keuangan (bulan, tahun, total_pendapatan, total_pengeluaran, dibuat_oleh) VALUES (:b, :t, :p, :x, NULL)')
            ->execute([':b' => $bulan, ':t' => $tahun, ':p' => (int)$p->fetchColumn(), ':x' => $total]);
    }
}
