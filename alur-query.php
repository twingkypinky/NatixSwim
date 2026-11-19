<?php
// ============================================================
// alur-query.php — SEMUA query SQL alur chatbot WhatsApp (pendaftaran → tagihan → progres murid)
// Hanya baca / tulis tabel dan mengembalikan array mentah. Logika percakapan ada di alur-fungsi.php.
// Tabel baru (dibuat otomatis): chat_sesi, progres_murid
// Tabel yang dipakai (sudah ada): members, kelas_program, tagihan, paket_member, jadwal, jadwal_peserta, pelatih, users
// ============================================================

function aqAdaKolom(PDO $pdo, string $tabel, string $kolom): bool
{
    try { $pdo->query("SELECT $kolom FROM $tabel LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

function aqDdl(PDO $pdo, string $sql): string   // DDL ditulis untuk MySQL; driver lain (uji coba) disesuaikan
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') return $sql;
    $sql = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/(UNIQUE )?KEY \w+ \(([^)]*)\),?\n?/', '', $sql);
    $sql = preg_replace('/,\s*\) ENGINE=.*$/', ')', $sql);
    return preg_replace('/\) ENGINE=.*$/', ')', $sql);
}

function aqMigrasi(PDO $pdo): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $my = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    foreach ([
        "CREATE TABLE IF NOT EXISTS chat_sesi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nomor VARCHAR(20) NOT NULL,
            alur VARCHAR(20) NOT NULL,
            langkah VARCHAR(20) NOT NULL,
            data TEXT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_chat_sesi_nomor (nomor)
        )$my",
        "CREATE TABLE IF NOT EXISTS progres_murid (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            pelatih_id INT NULL,
            jadwal_id INT NULL,
            tanggal DATE NOT NULL,
            level_skill TINYINT NOT NULL DEFAULT 1,
            nilai_teknik TINYINT NOT NULL DEFAULT 0,
            nilai_stamina TINYINT NOT NULL DEFAULT 0,
            nilai_percaya_diri TINYINT NOT NULL DEFAULT 0,
            catatan TEXT NULL,
            sumber VARCHAR(12) NOT NULL DEFAULT 'whatsapp',
            ortu_status VARCHAR(10) NOT NULL DEFAULT 'belum',
            ortu_error VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_progres_member (member_id),
            KEY idx_progres_tanggal (tanggal)
        )$my",
    ] as $ddl) $pdo->exec(aqDdl($pdo, $ddl));
}

// ─── SESI PERCAKAPAN ───
function aqSesi(PDO $pdo, string $no): ?array
{
    $st = $pdo->prepare('SELECT * FROM chat_sesi WHERE nomor = :n');
    $st->execute([':n' => $no]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['data'] = json_decode((string)$r['data'], true) ?: [];
    return $r;
}

function aqSimpanSesi(PDO $pdo, string $no, string $alur, string $langkah, array $data, string $now): void
{
    $j = json_encode($data, JSON_UNESCAPED_UNICODE);
    $st = $pdo->prepare('UPDATE chat_sesi SET alur = :a, langkah = :l, data = :d, updated_at = :u WHERE nomor = :n');
    $st->execute([':a' => $alur, ':l' => $langkah, ':d' => $j, ':u' => $now, ':n' => $no]);
    if ($st->rowCount() === 0 && !aqSesi($pdo, $no)) {
        $pdo->prepare('INSERT INTO chat_sesi (nomor, alur, langkah, data, updated_at) VALUES (:n,:a,:l,:d,:u)')
            ->execute([':n' => $no, ':a' => $alur, ':l' => $langkah, ':d' => $j, ':u' => $now]);
    }
}

function aqHapusSesi(PDO $pdo, string $no): void
{
    $pdo->prepare('DELETE FROM chat_sesi WHERE nomor = :n')->execute([':n' => $no]);
}

function aqHapusSesiLama(PDO $pdo, string $batas): void
{
    $pdo->prepare('DELETE FROM chat_sesi WHERE updated_at < :b')->execute([':b' => $batas]);
}

// ─── PENDAFTARAN ───
function aqProgramAktif(PDO $pdo): array
{
    $f = aqAdaKolom($pdo, 'kelas_program', 'status') ? " WHERE status = 'aktif'" : '';
    return $pdo->query("SELECT id, nama_program, harga, jumlah_sesi FROM kelas_program$f ORDER BY nama_program")->fetchAll(PDO::FETCH_ASSOC);
}

function aqProgram(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, nama_program, harga, jumlah_sesi FROM kelas_program WHERE id = :i');
    $st->execute([':i' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function aqMemberDenganNomor(PDO $pdo): array   // semua member yang punya nomor WA (dicocokkan di PHP karena format nomor beragam)
{
    return $pdo->query("SELECT id, nama, no_whatsapp FROM members WHERE no_whatsapp IS NOT NULL AND no_whatsapp <> ''")->fetchAll(PDO::FETCH_ASSOC);
}

function aqKodeMemberTerakhir(PDO $pdo): ?string
{
    $r = $pdo->query("SELECT kode_member FROM members WHERE kode_member IS NOT NULL AND kode_member <> '' ORDER BY id DESC LIMIT 1")->fetchColumn();
    return $r !== false ? (string)$r : null;
}

function aqTambahMember(PDO $pdo, array $m, string $kode): int
{
    $kol = ['nama', 'tanggal_lahir', 'jenis_kelamin', 'nama_wali', 'no_whatsapp', 'status', 'kode_member'];
    $val = [':nama' => $m['nama'], ':tanggal_lahir' => $m['lahir'], ':jenis_kelamin' => $m['jk'], ':nama_wali' => $m['wali'], ':no_whatsapp' => $m['no'], ':status' => 'aktif', ':kode_member' => $kode];
    $pdo->prepare('INSERT INTO members (' . implode(',', $kol) . ') VALUES (' . implode(',', array_keys($val)) . ')')->execute($val);
    return (int)$pdo->lastInsertId();
}

function aqNomorTagihanBaru(PDO $pdo, int $geser = 0): string
{
    $pre = 'INV-' . date('ym') . '-';
    $st = $pdo->prepare('SELECT nomor_tagihan FROM tagihan WHERE nomor_tagihan LIKE :p');
    $st->execute([':p' => $pre . '%']);
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) $max = max($max, (int)substr($n, strlen($pre)));
    return $pre . str_pad((string)($max + 1 + $geser), 3, '0', STR_PAD_LEFT);
}

function aqTambahTagihan(PDO $pdo, int $memberId, int $programId, int $jumlah, string $tempo, string $catatan): string
{
    for ($i = 0; ; $i++) {
        $nomor = aqNomorTagihanBaru($pdo, $i);
        try {
            $pdo->prepare("INSERT INTO tagihan (nomor_tagihan, member_id, paket_id, program_id, jumlah, diskon, jatuh_tempo, status, catatan)
                           VALUES (:n, :m, NULL, :p, :j, 0, :t, 'belum_bayar', :c)")
                ->execute([':n' => $nomor, ':m' => $memberId, ':p' => $programId, ':j' => $jumlah, ':t' => $tempo, ':c' => $catatan]);
            return $nomor;
        } catch (PDOException $e) {
            if ($i >= 5 || (string)$e->getCode() !== '23000') throw $e;   // nomor bentrok -> coba nomor berikutnya
        }
    }
}

// ─── PROGRES MURID ───
function aqExprHp(PDO $pdo): string
{
    $dp = aqAdaKolom($pdo, 'pelatih', 'no_hp');
    $du = aqAdaKolom($pdo, 'users', 'no_hp');
    if ($dp && $du) return "COALESCE(NULLIF(pl.no_hp, ''), u.no_hp)";
    if ($dp) return 'pl.no_hp';
    if ($du) return 'u.no_hp';
    return 'NULL';
}

function aqDaftarPelatih(PDO $pdo): array   // -> [id, nama, hp]
{
    $hp = aqExprHp($pdo);
    return $pdo->query("SELECT pl.id, u.nama, $hp AS hp FROM pelatih pl JOIN users u ON u.id = pl.user_id ORDER BY u.nama")->fetchAll(PDO::FETCH_ASSOC);
}

// Murid milik coach: peserta jadwalnya 45 hari terakhir + paket aktif yang menunjuk coach ini
function aqMuridPelatih(PDO $pdo, int $pelatihId, string $sejak): array
{
    $ids = [];
    $st = $pdo->prepare('SELECT DISTINCT jp.member_id FROM jadwal_peserta jp JOIN jadwal j ON j.id = jp.jadwal_id WHERE j.pelatih_id = :p AND j.tanggal >= :s');
    $st->execute([':p' => $pelatihId, ':s' => $sejak]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i) $ids[(int)$i] = true;
    if (aqAdaKolom($pdo, 'paket_member', 'pelatih_id')) {
        $st = $pdo->prepare("SELECT DISTINCT member_id FROM paket_member WHERE pelatih_id = :p AND status = 'aktif'");
        $st->execute([':p' => $pelatihId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i) $ids[(int)$i] = true;
    }
    if (!$ids) return [];
    $in = implode(',', array_keys($ids));
    return $pdo->query("SELECT id, nama, no_whatsapp, nama_wali FROM members WHERE id IN ($in) ORDER BY nama")->fetchAll(PDO::FETCH_ASSOC);
}

function aqCariMember(PDO $pdo, string $nama): array   // cari di semua member (murid yang belum masuk jadwal coach)
{
    $st = $pdo->prepare('SELECT id, nama, no_whatsapp, nama_wali FROM members WHERE nama LIKE :q ORDER BY nama LIMIT 6');
    $st->execute([':q' => '%' . $nama . '%']);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function aqMember(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, nama, no_whatsapp, nama_wali FROM members WHERE id = :i');
    $st->execute([':i' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function aqJadwalTerakhirMurid(PDO $pdo, int $memberId, int $pelatihId, string $sampai): ?int   // sesi terakhir (sudah lewat) murid bersama coach ini
{
    $st = $pdo->prepare("SELECT j.id FROM jadwal j JOIN jadwal_peserta jp ON jp.jadwal_id = j.id
                          WHERE jp.member_id = :m AND j.pelatih_id = :p AND j.tanggal <= :t AND j.status <> 'dibatalkan'
                          ORDER BY j.tanggal DESC, j.id DESC LIMIT 1");
    $st->execute([':m' => $memberId, ':p' => $pelatihId, ':t' => $sampai]);
    $r = $st->fetchColumn();
    return $r === false ? null : (int)$r;
}

function aqTambahProgres(PDO $pdo, array $p, string $now): int
{
    $pdo->prepare('INSERT INTO progres_murid (member_id, pelatih_id, jadwal_id, tanggal, level_skill, nilai_teknik, nilai_stamina, nilai_percaya_diri, catatan, sumber, created_at)
                   VALUES (:m,:pl,:j,:t,:lv,:a,:b,:c,:cat,:s,:now)')
        ->execute([':m' => $p['member_id'], ':pl' => $p['pelatih_id'], ':j' => $p['jadwal_id'], ':t' => $p['tanggal'], ':lv' => $p['level'],
                   ':a' => $p['teknik'], ':b' => $p['stamina'], ':c' => $p['percaya_diri'], ':cat' => $p['catatan'], ':s' => $p['sumber'] ?? 'whatsapp', ':now' => $now]);
    return (int)$pdo->lastInsertId();
}

function aqSetOrtu(PDO $pdo, int $id, string $status, ?string $error): void
{
    $pdo->prepare('UPDATE progres_murid SET ortu_status = :s, ortu_error = :e WHERE id = :i')->execute([':s' => $status, ':e' => $error !== null ? mb_substr($error, 0, 250) : null, ':i' => $id]);
}

function aqProgresBulan(PDO $pdo, string $awal, string $akhir, int $pelatihId = 0, string $cari = ''): array
{
    $sql = "SELECT pg.*, m.nama AS murid, m.kode_member, m.nama_wali, m.no_whatsapp, u.nama AS pelatih
              FROM progres_murid pg
              JOIN members m ON m.id = pg.member_id
              LEFT JOIN pelatih pl ON pl.id = pg.pelatih_id
              LEFT JOIN users u ON u.id = pl.user_id
             WHERE pg.tanggal BETWEEN :s AND :e";
    $par = [':s' => $awal, ':e' => $akhir];
    if ($pelatihId > 0) { $sql .= ' AND pg.pelatih_id = :p'; $par[':p'] = $pelatihId; }
    if ($cari !== '') { $sql .= ' AND (m.nama LIKE :q1 OR m.kode_member LIKE :q2)'; $par[':q1'] = $par[':q2'] = '%' . $cari . '%'; }
    $st = $pdo->prepare($sql . ' ORDER BY pg.tanggal DESC, pg.id DESC');
    $st->execute($par);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function aqAmbilProgres(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT pg.*, m.nama AS murid, m.kode_member, m.nama_wali, m.no_whatsapp, u.nama AS pelatih
                           FROM progres_murid pg JOIN members m ON m.id = pg.member_id
                           LEFT JOIN pelatih pl ON pl.id = pg.pelatih_id LEFT JOIN users u ON u.id = pl.user_id WHERE pg.id = :i");
    $st->execute([':i' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function aqRiwayatMurid(PDO $pdo, int $memberId, int $batas = 6): array
{
    $st = $pdo->prepare('SELECT tanggal, level_skill, nilai_teknik, nilai_stamina, nilai_percaya_diri FROM progres_murid WHERE member_id = :m ORDER BY tanggal DESC, id DESC LIMIT ' . (int)$batas);
    $st->execute([':m' => $memberId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function aqHapusProgres(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM progres_murid WHERE id = :i')->execute([':i' => $id]);
}
