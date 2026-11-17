-- =========================================================
-- NATIX SWIM - DATABASE SCHEMA (MySQL 8+)
-- Bisa dijalankan sekaligus (Run All), tanpa DELIMITER
-- =========================================================

DROP DATABASE IF EXISTS natix_swim;
CREATE DATABASE natix_swim CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE natix_swim;

-- =========================================================
-- 1. USER & PELATIH
-- =========================================================
CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nama          VARCHAR(100) NOT NULL,
  email         VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  no_hp         VARCHAR(20),
  role          ENUM('owner','admin','cs','pelatih') NOT NULL,
  status        ENUM('aktif','non_aktif') NOT NULL DEFAULT 'aktif',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE pelatih (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL UNIQUE,
  spesialisasi VARCHAR(100),
  bergabung    DATE,
  status       ENUM('aktif','cuti','non_aktif') NOT NULL DEFAULT 'aktif',
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================================================
-- 2. CUSTOMER DATA
-- =========================================================
CREATE TABLE members (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  kode_member    VARCHAR(20) NOT NULL UNIQUE,
  nama           VARCHAR(100) NOT NULL,
  tanggal_lahir  DATE NULL,
  jenis_kelamin  ENUM('L','P') NULL,
  no_whatsapp    VARCHAR(20) NOT NULL,
  nama_wali      VARCHAR(100) NULL,
  alamat         TEXT NULL,
  segmen         ENUM('anak','remaja','dewasa','private') NOT NULL DEFAULT 'anak',
  status         ENUM('aktif','non_aktif') NOT NULL DEFAULT 'aktif',
  tanggal_daftar DATE NOT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_member_status (status),
  INDEX idx_member_segmen (segmen)
) ENGINE=InnoDB;

-- =========================================================
-- 3. OPERASIONAL
-- =========================================================
CREATE TABLE kelas_program (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nama_program VARCHAR(100) NOT NULL,
  level        ENUM('beginner','intermediate','advanced') NOT NULL,
  tipe         ENUM('grup','private') NOT NULL DEFAULT 'grup',
  jumlah_sesi  INT NOT NULL,
  kapasitas    INT NOT NULL,
  harga        DECIMAL(12,2) NOT NULL,
  deskripsi    TEXT NULL,
  status       ENUM('aktif','non_aktif') NOT NULL DEFAULT 'aktif'
) ENGINE=InnoDB;

CREATE TABLE paket_member (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  member_id        INT NOT NULL,
  program_id       INT NOT NULL,
  total_sesi       INT NOT NULL,
  sisa_sesi        INT NOT NULL,
  tanggal_mulai    DATE NOT NULL,
  tanggal_berakhir DATE NULL,
  status           ENUM('aktif','habis','kadaluarsa') NOT NULL DEFAULT 'aktif',
  FOREIGN KEY (member_id)  REFERENCES members(id) ON DELETE CASCADE,
  FOREIGN KEY (program_id) REFERENCES kelas_program(id),
  INDEX idx_paket_sisa (sisa_sesi, status)
) ENGINE=InnoDB;

CREATE TABLE jadwal (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  program_id  INT NOT NULL,
  pelatih_id  INT NOT NULL,
  tanggal     DATE NOT NULL,
  jam_mulai   TIME NOT NULL,
  jam_selesai TIME NOT NULL,
  kolam       VARCHAR(30) NOT NULL,
  kapasitas   INT NOT NULL,
  status      ENUM('akan_datang','berlangsung','selesai','dibatalkan') NOT NULL DEFAULT 'akan_datang',
  FOREIGN KEY (program_id) REFERENCES kelas_program(id),
  FOREIGN KEY (pelatih_id) REFERENCES pelatih(id),
  INDEX idx_jadwal_tanggal (tanggal)
) ENGINE=InnoDB;

CREATE TABLE jadwal_peserta (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  jadwal_id INT NOT NULL,
  member_id INT NOT NULL,
  UNIQUE KEY uq_jadwal_member (jadwal_id, member_id),
  FOREIGN KEY (jadwal_id) REFERENCES jadwal(id) ON DELETE CASCADE,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE presensi (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  jadwal_id    INT NOT NULL,
  member_id    INT NOT NULL,
  paket_id     INT NOT NULL,
  status       ENUM('hadir','izin','sakit','alpa') NOT NULL,
  catatan      VARCHAR(255) NULL,
  dicatat_oleh INT NULL,
  waktu        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_presensi (jadwal_id, member_id),
  FOREIGN KEY (jadwal_id)    REFERENCES jadwal(id) ON DELETE CASCADE,
  FOREIGN KEY (member_id)    REFERENCES members(id) ON DELETE CASCADE,
  FOREIGN KEY (paket_id)     REFERENCES paket_member(id),
  FOREIGN KEY (dicatat_oleh) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE evaluasi (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  jadwal_id  INT NOT NULL,
  member_id  INT NOT NULL,
  pelatih_id INT NOT NULL,
  nilai      TINYINT NULL,
  catatan    TEXT NULL,
  status     ENUM('belum','selesai') NOT NULL DEFAULT 'belum',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (jadwal_id)  REFERENCES jadwal(id) ON DELETE CASCADE,
  FOREIGN KEY (member_id)  REFERENCES members(id) ON DELETE CASCADE,
  FOREIGN KEY (pelatih_id) REFERENCES pelatih(id)
) ENGINE=InnoDB;

CREATE TABLE reschedule (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  member_id      INT NOT NULL,
  jadwal_lama_id INT NOT NULL,
  jadwal_baru_id INT NULL,
  alasan         VARCHAR(255),
  status         ENUM('diajukan','disetujui','ditolak') NOT NULL DEFAULT 'diajukan',
  diproses_oleh  INT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id)      REFERENCES members(id) ON DELETE CASCADE,
  FOREIGN KEY (jadwal_lama_id) REFERENCES jadwal(id),
  FOREIGN KEY (jadwal_baru_id) REFERENCES jadwal(id),
  FOREIGN KEY (diproses_oleh)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =========================================================
-- 4. ADMINISTRASI
-- =========================================================
CREATE TABLE tagihan (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nomor_tagihan VARCHAR(30) NOT NULL UNIQUE,
  member_id     INT NOT NULL,
  paket_id      INT NULL,
  jumlah        DECIMAL(12,2) NOT NULL,
  jatuh_tempo   DATE NOT NULL,
  status        ENUM('belum_bayar','sebagian','lunas','jatuh_tempo') NOT NULL DEFAULT 'belum_bayar',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  FOREIGN KEY (paket_id)  REFERENCES paket_member(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE pembayaran (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tagihan_id    INT NOT NULL,
  jumlah        DECIMAL(12,2) NOT NULL,
  metode        ENUM('tunai','transfer','qris','ewallet') NOT NULL,
  tanggal_bayar DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dicatat_oleh  INT NULL,
  FOREIGN KEY (tagihan_id)   REFERENCES tagihan(id) ON DELETE CASCADE,
  FOREIGN KEY (dicatat_oleh) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE kuitansi (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  pembayaran_id  INT NOT NULL UNIQUE,
  nomor_kuitansi VARCHAR(30) NOT NULL UNIQUE,
  diterbitkan    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pembayaran_id) REFERENCES pembayaran(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE laporan_keuangan (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  bulan             TINYINT NOT NULL,
  tahun             SMALLINT NOT NULL,
  total_pendapatan  DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_pengeluaran DECIMAL(14,2) NOT NULL DEFAULT 0,
  dibuat_oleh       INT NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_periode (bulan, tahun),
  FOREIGN KEY (dibuat_oleh) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =========================================================
-- 5. AI ASSISTANT
-- =========================================================
CREATE TABLE ai_whatsapp (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nama_bot      VARCHAR(100) NOT NULL,
  nomor_wa      VARCHAR(20) NOT NULL,
  prompt_sistem TEXT,
  status        ENUM('aktif','non_aktif') NOT NULL DEFAULT 'aktif'
) ENGINE=InnoDB;

CREATE TABLE knowledge_base (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  kategori    ENUM('program','harga','jadwal','pembayaran','umum') NOT NULL,
  pertanyaan  VARCHAR(255) NOT NULL,
  jawaban     TEXT NOT NULL,
  diubah_oleh INT NULL,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (diubah_oleh) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE percakapan (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  member_id      INT NULL,
  no_whatsapp    VARCHAR(20) NOT NULL,
  status         ENUM('bot','cs','selesai') NOT NULL DEFAULT 'bot',
  ditangani_oleh INT NULL,
  mulai          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id)      REFERENCES members(id) ON DELETE SET NULL,
  FOREIGN KEY (ditangani_oleh) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE pesan (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  percakapan_id INT NOT NULL,
  pengirim      ENUM('member','bot','cs') NOT NULL,
  isi           TEXT NOT NULL,
  waktu         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (percakapan_id) REFERENCES percakapan(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE otomasi_pesan (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  nama     VARCHAR(100) NOT NULL,
  pemicu   ENUM('kuota_rendah','pengingat_h1','tagihan_jatuh_tempo','ulang_tahun') NOT NULL,
  template TEXT NOT NULL,
  aktif    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE log_otomasi (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  otomasi_id INT NOT NULL,
  member_id  INT NOT NULL,
  isi_pesan  TEXT NOT NULL,
  status     ENUM('antri','terkirim','gagal') NOT NULL DEFAULT 'antri',
  waktu      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (otomasi_id) REFERENCES otomasi_pesan(id) ON DELETE CASCADE,
  FOREIGN KEY (member_id)  REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================================================
-- 6. TRIGGER (satu statement, tanpa BEGIN...END / DELIMITER)
-- =========================================================

-- Presensi "hadir" → sisa sesi berkurang 1, status 'habis' jika jadi 0
CREATE TRIGGER trg_presensi_hadir
AFTER INSERT ON presensi
FOR EACH ROW
UPDATE paket_member
   SET sisa_sesi = sisa_sesi - 1,
       status    = IF(sisa_sesi = 0, 'habis', status)
 WHERE id = NEW.paket_id
   AND sisa_sesi > 0
   AND NEW.status = 'hadir';

-- Sisa sesi turun ke ≤ 2 → masuk antrean pesan "Kuota Hampir Habis"
CREATE TRIGGER trg_kuota_rendah
AFTER UPDATE ON paket_member
FOR EACH ROW
INSERT INTO log_otomasi (otomasi_id, member_id, isi_pesan)
SELECT o.id, NEW.member_id,
       CONCAT('Halo ', m.nama, ', sisa sesi kamu tinggal ', NEW.sisa_sesi,
              '. Yuk perpanjang paketnya!')
  FROM otomasi_pesan o
  JOIN members m ON m.id = NEW.member_id
 WHERE o.pemicu = 'kuota_rendah'
   AND o.aktif = 1
   AND NEW.sisa_sesi <= 2
   AND NEW.sisa_sesi < OLD.sisa_sesi;

-- Pembayaran masuk → status tagihan jadi 'sebagian' / 'lunas'
CREATE TRIGGER trg_pembayaran_status
AFTER INSERT ON pembayaran
FOR EACH ROW
UPDATE tagihan t
   SET t.status = IF(
         (SELECT SUM(p.jumlah) FROM pembayaran p WHERE p.tagihan_id = NEW.tagihan_id) >= t.jumlah,
         'lunas', 'sebagian')
 WHERE t.id = NEW.tagihan_id;

-- Pembayaran masuk → kuitansi otomatis
CREATE TRIGGER trg_pembayaran_kuitansi
AFTER INSERT ON pembayaran
FOR EACH ROW
INSERT INTO kuitansi (pembayaran_id, nomor_kuitansi)
VALUES (NEW.id, CONCAT('KW-', DATE_FORMAT(NOW(), '%Y%m'), '-', LPAD(NEW.id, 5, '0')));

-- =========================================================
-- 7. VIEW UNTUK DASHBOARD
-- =========================================================

-- Kartu "Kuota Hampir Habis"
CREATE VIEW v_kuota_hampir_habis AS
SELECT pm.id AS paket_id, m.id AS member_id, m.nama, m.no_whatsapp,
       k.nama_program, pm.sisa_sesi, pm.total_sesi
FROM paket_member pm
JOIN members m       ON m.id = pm.member_id
JOIN kelas_program k ON k.id = pm.program_id
WHERE pm.status = 'aktif' AND pm.sisa_sesi <= 2
ORDER BY pm.sisa_sesi, m.nama;

-- Tabel "Kelas Hari Ini"
CREATE VIEW v_kelas_hari_ini AS
SELECT j.id, k.nama_program, j.jam_mulai, j.jam_selesai, u.nama AS pelatih,
       j.kolam, COUNT(jp.member_id) AS jumlah_siswa, j.kapasitas, j.status
FROM jadwal j
JOIN kelas_program k ON k.id = j.program_id
JOIN pelatih pl      ON pl.id = j.pelatih_id
JOIN users u         ON u.id = pl.user_id
LEFT JOIN jadwal_peserta jp ON jp.jadwal_id = j.id
WHERE j.tanggal = CURDATE()
GROUP BY j.id, k.nama_program, j.jam_mulai, j.jam_selesai, u.nama,
         j.kolam, j.kapasitas, j.status
ORDER BY j.jam_mulai;

-- Kartu statistik member
CREATE VIEW v_statistik_member AS
SELECT COUNT(*)                                      AS total_member,
       SUM(status = 'aktif')                         AS member_aktif,
       SUM(status = 'non_aktif')                     AS member_non_aktif,
       ROUND(SUM(status = 'aktif') / COUNT(*) * 100) AS persen_aktif
FROM members;

-- Grafik "Pendapatan Bulanan" (juta Rupiah)
CREATE VIEW v_pendapatan_bulanan AS
SELECT tahun, bulan, ROUND(total_pendapatan / 1000000, 1) AS pendapatan_juta
FROM laporan_keuangan
ORDER BY tahun, bulan;

-- Notifikasi "Pengingat H-1"
CREATE VIEW v_pengingat_besok AS
SELECT j.id, k.nama_program, j.tanggal, j.jam_mulai, j.kolam, u.nama AS pelatih,
       COUNT(jp.member_id) AS jumlah_siswa
FROM jadwal j
JOIN kelas_program k ON k.id = j.program_id
JOIN pelatih pl      ON pl.id = j.pelatih_id
JOIN users u         ON u.id = pl.user_id
LEFT JOIN jadwal_peserta jp ON jp.jadwal_id = j.id
WHERE j.tanggal = CURDATE() + INTERVAL 1 DAY
GROUP BY j.id, k.nama_program, j.tanggal, j.jam_mulai, j.kolam, u.nama
ORDER BY j.jam_mulai;

-- =========================================================
-- 8. DATA CONTOH
-- =========================================================
INSERT INTO users (nama, email, password_hash, no_hp, role) VALUES
('Admin Natix',      'admin@natixswim.id', '$2y$10$dummyhashadmin', '081200000001', 'owner'),
('Customer Service', 'cs@natixswim.id',    '$2y$10$dummyhashcs',    '081200000002', 'cs'),
('Coach Rina',       'rina@natixswim.id',  '$2y$10$dummyhashrina',  '081200000003', 'pelatih'),
('Coach Budi',       'budi@natixswim.id',  '$2y$10$dummyhashbudi',  '081200000004', 'pelatih'),
('Coach Dimas',      'dimas@natixswim.id', '$2y$10$dummyhashdimas', '081200000005', 'pelatih');

INSERT INTO pelatih (user_id, spesialisasi, bergabung) VALUES
(3, 'Anak & Pemula', '2023-01-10'),
(4, 'Intermediate',  '2023-06-01'),
(5, 'Private Class', '2024-02-15');

INSERT INTO kelas_program (nama_program, level, tipe, jumlah_sesi, kapasitas, harga) VALUES
('Beginner Kids', 'beginner',     'grup',    8,  15, 600000),
('Intermediate',  'intermediate', 'grup',    12, 12, 850000),
('Private Class', 'advanced',     'private', 8,  2,  1500000);

INSERT INTO members (kode_member, nama, no_whatsapp, segmen, tanggal_daftar) VALUES
('NX-0001', 'Alya Putri',     '081300000001', 'anak',    '2026-01-05'),
('NX-0002', 'Rafi Pratama',   '081300000002', 'remaja',  '2026-02-11'),
('NX-0003', 'Nadia Kirana',   '081300000003', 'private', '2026-03-02'),
('NX-0004', 'Kevin Wijaya',   '081300000004', 'anak',    '2026-04-18'),
('NX-0005', 'Salsa Maharani', '081300000005', 'remaja',  '2026-05-09'),
('NX-0006', 'Farel Hakim',    '081300000006', 'anak',    '2026-06-21'),
('NX-0007', 'Gita Safitri',   '081300000007', 'remaja',  '2026-07-14'),
('NX-0008', 'Iqbal Ramadhan', '081300000008', 'anak',    '2026-08-03');

-- Farel 1/8, Gita 2/12, Iqbal 2/8 → muncul di "Kuota Hampir Habis"
INSERT INTO paket_member (member_id, program_id, total_sesi, sisa_sesi, tanggal_mulai) VALUES
(1, 1, 8,  5,  '2026-10-01'),
(2, 2, 12, 8,  '2026-10-01'),
(3, 3, 8,  6,  '2026-10-01'),
(4, 1, 8,  7,  '2026-10-01'),
(5, 2, 12, 10, '2026-10-01'),
(6, 1, 8,  1,  '2026-09-01'),
(7, 2, 12, 2,  '2026-09-01'),
(8, 1, 8,  2,  '2026-09-01');

INSERT INTO jadwal (program_id, pelatih_id, tanggal, jam_mulai, jam_selesai, kolam, kapasitas, status) VALUES
(1, 1, '2026-10-11', '07:00', '08:00', 'Kolam A', 15, 'selesai'),
(2, 2, '2026-10-11', '09:00', '10:00', 'Kolam B', 12, 'selesai'),
(3, 3, '2026-10-11', '15:30', '16:30', 'Kolam A', 2,  'berlangsung'),
(1, 1, '2026-10-11', '17:00', '18:00', 'Kolam A', 15, 'akan_datang'),
(2, 2, '2026-10-11', '18:30', '19:30', 'Kolam B', 10, 'akan_datang'),
(1, 1, '2026-10-12', '07:00', '08:00', 'Kolam A', 15, 'akan_datang'),
(3, 3, '2026-10-12', '16:00', '17:00', 'Kolam B', 2,  'akan_datang');

INSERT INTO jadwal_peserta (jadwal_id, member_id) VALUES
(1, 1), (1, 6), (1, 8),
(2, 2), (2, 7),
(3, 3),
(4, 4),
(5, 5),
(6, 1), (6, 4), (6, 6),
(7, 3);

INSERT INTO laporan_keuangan (bulan, tahun, total_pendapatan, dibuat_oleh) VALUES
(1,  2026, 58000000, 1), (2,  2026, 62000000, 1),
(3,  2026, 55000000, 1), (4,  2026, 68000000, 1),
(5,  2026, 72000000, 1), (6,  2026, 70000000, 1),
(7,  2026, 76000000, 1), (8,  2026, 79000000, 1),
(9,  2026, 77100000, 1), (10, 2026, 86400000, 1);

INSERT INTO tagihan (nomor_tagihan, member_id, paket_id, jumlah, jatuh_tempo) VALUES
('INV-202610-0001', 6, 6, 600000, '2026-10-15'),
('INV-202610-0002', 7, 7, 850000, '2026-10-20');

INSERT INTO ai_whatsapp (nama_bot, nomor_wa, prompt_sistem) VALUES
('Natix Assistant', '081299990000',
 'Kamu adalah asisten Natix Swim. Jawab ramah, singkat, dan berdasarkan knowledge base.');

INSERT INTO knowledge_base (kategori, pertanyaan, jawaban, diubah_oleh) VALUES
('program',    'Program apa saja yang tersedia?', 'Beginner Kids, Intermediate, dan Private Class.', 1),
('harga',      'Berapa harga Beginner Kids?',     'Rp 600.000 untuk 8 sesi.', 1),
('jadwal',     'Bagaimana cara reschedule?',      'Kirim pesan "RESCHEDULE" beserta tanggal yang diinginkan.', 1),
('pembayaran', 'Metode pembayaran apa saja?',     'Tunai, transfer bank, QRIS, dan e-wallet.', 1);

INSERT INTO otomasi_pesan (nama, pemicu, template) VALUES
('Kuota Hampir Habis',  'kuota_rendah',
 'Halo {nama}, sisa sesi kamu tinggal {sisa_sesi}. Yuk perpanjang paketnya!'),
('Pengingat H-1',       'pengingat_h1',
 'Halo {nama}, besok ada kelas {program} jam {jam} di {kolam}. Sampai jumpa!'),
('Tagihan Jatuh Tempo', 'tagihan_jatuh_tempo',
 'Halo {nama}, tagihan {nomor_tagihan} jatuh tempo pada {tanggal}.');

-- ============================================================
-- TAMBAH KOLOM BARU di tabel pelatih (sudah punya bergabung)
-- ============================================================
ALTER TABLE `pelatih`
  ADD COLUMN `jenis_kelamin` ENUM('Laki-laki', 'Perempuan') NULL DEFAULT NULL AFTER `bergabung`,
  ADD COLUMN `pengalaman_tahun` INT(2) NULL DEFAULT NULL AFTER `jenis_kelamin`,
  ADD COLUMN `sistem_honor` VARCHAR(50) NULL DEFAULT NULL AFTER `pengalaman_tahun`,
  ADD COLUMN `rating` DECIMAL(2,1) NULL DEFAULT NULL AFTER `sistem_honor`,
  ADD COLUMN `total_ulasan` INT(5) NULL DEFAULT 0 AFTER `rating`;

-- ============================================================
-- TABEL BARU
-- ============================================================

-- Detail spesialisasi (many-to-many)
CREATE TABLE IF NOT EXISTS `pelatih_spesialisasi` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pelatih_id` INT(11) NOT NULL,
  `nama_spesialisasi` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pelatih_id` (`pelatih_id`),
  CONSTRAINT `fk_spes_pelatih` FOREIGN KEY (`pelatih_id`) REFERENCES `pelatih` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hari mengajar
CREATE TABLE IF NOT EXISTS `pelatih_hari_mengajar` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pelatih_id` INT(11) NOT NULL,
  `hari` VARCHAR(20) NOT NULL COMMENT 'Senin, Selasa, Rabu, ...',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pelatih_id` (`pelatih_id`),
  CONSTRAINT `fk_hari_pelatih` FOREIGN KEY (`pelatih_id`) REFERENCES `pelatih` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sertifikasi
CREATE TABLE IF NOT EXISTS `pelatih_sertifikasi` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pelatih_id` INT(11) NOT NULL,
  `nama_sertifikasi` VARCHAR(150) NOT NULL,
  `berlaku_sampai` DATE NOT NULL,
  `icon_svg` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pelatih_id` (`pelatih_id`),
  CONSTRAINT `fk_sertif_pelatih` FOREIGN KEY (`pelatih_id`) REFERENCES `pelatih` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Progres siswa per level
CREATE TABLE IF NOT EXISTS `progres_siswa_level` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pelatih_id` INT(11) NOT NULL,
  `level_dari` VARCHAR(50) NOT NULL,
  `level_ke` VARCHAR(50) NOT NULL,
  `jumlah_siswa` INT(5) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pelatih_id` (`pelatih_id`),
  CONSTRAINT `fk_progres_pelatih` FOREIGN KEY (`pelatih_id`) REFERENCES `pelatih` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ulasan member
CREATE TABLE IF NOT EXISTS `ulasan_pelatih` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pelatih_id` INT(11) NOT NULL,
  `nama_orangtua` VARCHAR(100) NOT NULL,
  `inisial` VARCHAR(2) NOT NULL,
  `nama_anak` VARCHAR(100) DEFAULT NULL,
  `rating` TINYINT(1) NOT NULL DEFAULT 5 COMMENT '1-5',
  `ulasan` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pelatih_id` (`pelatih_id`),
  CONSTRAINT `fk_ulasan_pelatih` FOREIGN KEY (`pelatih_id`) REFERENCES `pelatih` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SAMPLE DATA (sesuai ID dari schema existing)
-- ============================================================

-- Update pelatih yang sudah ada (id 1 = Coach Rina, 2 = Coach Budi, 3 = Coach Dimas)
UPDATE `pelatih` SET
  `jenis_kelamin` = 'Perempuan',
  `pengalaman_tahun` = 6,
  `sistem_honor` = 'Per Sesi',
  `rating` = 4.9,
  `total_ulasan` = 36
WHERE `id` = 1;

UPDATE `pelatih` SET
  `jenis_kelamin` = 'Laki-laki',
  `pengalaman_tahun` = 4,
  `sistem_honor` = 'Per Sesi',
  `rating` = 4.7,
  `total_ulasan` = 24
WHERE `id` = 2;

UPDATE `pelatih` SET
  `jenis_kelamin` = 'Laki-laki',
  `pengalaman_tahun` = 3,
  `sistem_honor` = 'Per Sesi',
  `rating` = 4.8,
  `total_ulasan` = 18
WHERE `id` = 3;

-- Spesialisasi detail
INSERT INTO `pelatih_spesialisasi` (`pelatih_id`, `nama_spesialisasi`) VALUES
(1, 'Anak & Pemula'), (1, 'Gaya Bebas'), (1, 'Gaya Dada'), (1, 'Pemula'),
(2, 'Intermediate'), (2, 'Gaya Kupu-kupu'), (2, 'Gaya Punggung'),
(3, 'Private Class'), (3, 'Teknik Lanjutan'), (3, 'Kondisi Fisik');

-- Hari mengajar
INSERT INTO `pelatih_hari_mengajar` (`pelatih_id`, `hari`) VALUES
(1, 'Senin'), (1, 'Rabu'), (1, 'Jumat'), (1, 'Sabtu'), (1, 'Minggu'),
(2, 'Selasa'), (2, 'Kamis'), (2, 'Sabtu'),
(3, 'Senin'), (3, 'Rabu'), (3, 'Jumat');

-- Sertifikasi
INSERT INTO `pelatih_sertifikasi` (`pelatih_id`, `nama_sertifikasi`, `berlaku_sampai`) VALUES
(1, 'Lisensi Pelatih PRSI', '2027-12-31'),
(1, 'First Aid & CPR', '2026-11-30'),
(1, 'Lifeguard Certification', '2027-08-15'),
(2, 'Lisensi Pelatih PRSI', '2028-03-20'),
(2, 'Water Safety Instructor', '2027-06-10'),
(3, 'Advanced Swim Coach', '2027-09-05'),
(3, 'First Aid & CPR', '2027-01-15');

-- Progres siswa
INSERT INTO `progres_siswa_level` (`pelatih_id`, `level_dari`, `level_ke`, `jumlah_siswa`) VALUES
(1, 'Pemula', 'Dasar', 18),
(1, 'Dasar', 'Menengah', 14),
(1, 'Menengah', 'Mahir', 9),
(1, 'Siap Kompetisi', 'Siap Kompetisi', 7),
(2, 'Pemula', 'Dasar', 12),
(2, 'Dasar', 'Menengah', 10),
(2, 'Menengah', 'Mahir', 6),
(3, 'Pemula', 'Dasar', 8),
(3, 'Dasar', 'Menengah', 5);

-- Ulasan
INSERT INTO `ulasan_pelatih` (`pelatih_id`, `nama_orangtua`, `inisial`, `nama_anak`, `rating`, `ulasan`) VALUES
(1, 'Ibu Sari', 'IS', 'Kenzo', 5, 'Coach Rina sabar banget, anak saya yang tadinya takut air sekarang sudah berani gaya bebas 10 meter.'),
(1, 'Pak Hendra', 'PH', 'Alya', 5, 'Laporan evaluasinya rutin dan jelas, jadi kami tahu perkembangan anak tiap bulan.'),
(1, 'Ibu Dewi', 'DW', 'Bima', 4, 'Anak saya jadi lebih percaya diri setelah belajar dengan Coach Rina.'),
(2, 'Pak Andi', 'AN', 'Rafi', 5, 'Coach Budi sangat profesional, teknik renang anak saya meningkat pesat.'),
(2, 'Ibu Maya', 'MY', 'Salsa', 4, 'Anak saya suka metode latihan Coach Budi yang variatif.'),
(3, 'Ibu Rina', 'RN', 'Nadia', 5, 'Private class dengan Coach Dimas sangat worth it, perkembangan cepat!');

ALTER TABLE paket_member
  ADD COLUMN pelatih_id INT NULL AFTER program_id,
  ADD CONSTRAINT fk_paket_pelatih FOREIGN KEY (pelatih_id)
      REFERENCES pelatih(id) ON DELETE SET NULL;

-- Isi data lama dari jadwal yang diikuti member
UPDATE paket_member pm
JOIN (SELECT jp.member_id, MIN(j.pelatih_id) AS pelatih_id
        FROM jadwal_peserta jp
        JOIN jadwal j ON j.id = jp.jadwal_id
       GROUP BY jp.member_id) x ON x.member_id = pm.member_id
   SET pm.pelatih_id = x.pelatih_id
 WHERE pm.pelatih_id IS NULL;

 SELECT pm.id, pm.pelatih_id, pl.id AS pelatih_ada
FROM paket_member pm
LEFT JOIN pelatih pl ON pl.id = pm.pelatih_id;

UPDATE paket_member SET pelatih_id = NULL
WHERE pelatih_id NOT IN (SELECT id FROM pelatih);

ALTER TABLE paket_member
  ADD CONSTRAINT fk_paket_pelatih FOREIGN KEY (pelatih_id)
      REFERENCES pelatih(id) ON DELETE SET NULL;

      SET SQL_SAFE_UPDATES = 0;

UPDATE paket_member pm
JOIN (SELECT jp.member_id, MIN(j.pelatih_id) AS pelatih_id
        FROM jadwal_peserta jp
        JOIN jadwal j ON j.id = jp.jadwal_id
       GROUP BY jp.member_id) x ON x.member_id = pm.member_id
   SET pm.pelatih_id = x.pelatih_id
 WHERE pm.pelatih_id IS NULL;

 SELECT pm.id, m.nama AS member, pm.pelatih_id, u.nama AS nama_pelatih
FROM paket_member pm
JOIN members m ON m.id = pm.member_id
LEFT JOIN pelatih pl ON pl.id = pm.pelatih_id
LEFT JOIN users u ON u.id = pl.user_id;

-- Isi "Berlaku s.d." (paket_member.tanggal_berakhir) untuk data lama yang masih NULL.
-- Masa berlaku di sini 3 bulan sejak tanggal_mulai -> ubah angka INTERVAL sesuai kebijakan Natix.
SET SQL_SAFE_UPDATES = 0;

UPDATE paket_member
   SET tanggal_berakhir = DATE_ADD(tanggal_mulai, INTERVAL 3 MONTH)
 WHERE id > 0
   AND tanggal_berakhir IS NULL;

-- Cek hasil
SELECT pm.id, m.nama, pm.tanggal_mulai, pm.tanggal_berakhir
FROM paket_member pm
JOIN members m ON m.id = pm.member_id;

-- Data contoh jadwal untuk MINGGU INI (otomatis mengikuti tanggal hari ini).
-- Jalankan SEKALI saja (kalau diulang, jadwalnya terduplikasi).
-- Berisi: kelas Kids, Dewasa, Private, 1 kelas penuh (Sabtu), dan 1 bentrok pelatih (Rabu 16:00).
USE natix_swim;
SET @senin  = DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY);
SET @minggu = DATE_ADD(@senin, INTERVAL 6 DAY);

INSERT INTO jadwal (program_id, pelatih_id, tanggal, jam_mulai, jam_selesai, kolam, kapasitas, status) VALUES
(1, 1, DATE_ADD(@senin, INTERVAL 0 DAY), '07:00', '08:00', 'Kolam A', 15, 'akan_datang'),
(1, 1, DATE_ADD(@senin, INTERVAL 2 DAY), '07:00', '08:00', 'Kolam A', 15, 'akan_datang'),
(1, 1, DATE_ADD(@senin, INTERVAL 4 DAY), '07:00', '08:00', 'Kolam A', 15, 'akan_datang'),
(2, 2, DATE_ADD(@senin, INTERVAL 1 DAY), '09:00', '10:00', 'Kolam B', 12, 'akan_datang'),
(2, 2, DATE_ADD(@senin, INTERVAL 3 DAY), '09:00', '10:00', 'Kolam B', 12, 'akan_datang'),
(3, 3, DATE_ADD(@senin, INTERVAL 0 DAY), '16:00', '17:00', 'Kolam B', 2,  'akan_datang'),
(3, 3, DATE_ADD(@senin, INTERVAL 4 DAY), '15:00', '16:00', 'Kolam B', 2,  'akan_datang'),
(1, 1, DATE_ADD(@senin, INTERVAL 5 DAY), '09:00', '10:00', 'Kolam A', 3,  'akan_datang'),  -- penuh (3/3)
(1, 1, DATE_ADD(@senin, INTERVAL 2 DAY), '16:00', '17:00', 'Kolam A', 15, 'akan_datang'),  -- bentrok
(2, 1, DATE_ADD(@senin, INTERVAL 2 DAY), '16:30', '17:30', 'Kolam B', 12, 'akan_datang'),  -- bentrok
(2, 2, DATE_ADD(@senin, INTERVAL 4 DAY), '17:00', '18:00', 'Kolam B', 12, 'akan_datang'),
(1, 1, DATE_ADD(@senin, INTERVAL 6 DAY), '08:00', '09:00', 'Kolam A', 15, 'akan_datang');

-- Peserta contoh
INSERT IGNORE INTO jadwal_peserta (jadwal_id, member_id)
SELECT j.id, m.id FROM jadwal j JOIN members m ON m.id IN (1,4,6)
 WHERE j.tanggal BETWEEN @senin AND @minggu AND j.program_id = 1 AND j.kapasitas >= 3;

INSERT IGNORE INTO jadwal_peserta (jadwal_id, member_id)
SELECT j.id, m.id FROM jadwal j JOIN members m ON m.id IN (2,5,7)
 WHERE j.tanggal BETWEEN @senin AND @minggu AND j.program_id = 2;

INSERT IGNORE INTO jadwal_peserta (jadwal_id, member_id)
SELECT j.id, 3 FROM jadwal j
 WHERE j.tanggal BETWEEN @senin AND @minggu AND j.program_id = 3;

 -- ============================================================
-- MIGRASI ADMINISTRASI (Transaksi, Detail Invoice, Laporan Keuangan)
-- Jalankan SEKALI di database natix_swim.
-- Kalau muncul "Duplicate column name", berarti kolom itu sudah ada -> lewati baris tersebut.
-- ============================================================
USE natix_swim;

-- 1) Tagihan: program yang dibeli (paket dibuat otomatis saat lunas), diskon, catatan
ALTER TABLE tagihan ADD COLUMN program_id INT NULL AFTER paket_id;
ALTER TABLE tagihan ADD COLUMN diskon DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER jumlah;
ALTER TABLE tagihan ADD COLUMN catatan VARCHAR(255) NULL;

-- 2) Pembayaran: nama bank untuk metode transfer (BCA / Mandiri / ...)
ALTER TABLE pembayaran ADD COLUMN bank VARCHAR(30) NULL AFTER metode;

-- 3) Bukti pembayaran (upload bukti transfer) + status verifikasinya
CREATE TABLE IF NOT EXISTS bukti_pembayaran (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  tagihan_id        INT NOT NULL,
  file_path         VARCHAR(255) NOT NULL,
  nama_asli         VARCHAR(150) NULL,
  metode            ENUM('tunai','transfer','qris','ewallet') NOT NULL DEFAULT 'transfer',
  bank              VARCHAR(30) NULL,
  sumber            ENUM('admin','member','ai_chat') NOT NULL DEFAULT 'admin',
  status            ENUM('menunggu','diterima','ditolak') NOT NULL DEFAULT 'menunggu',
  catatan           VARCHAR(255) NULL,
  diunggah_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diverifikasi_at   DATETIME NULL,
  diverifikasi_oleh INT NULL,
  FOREIGN KEY (tagihan_id)        REFERENCES tagihan(id) ON DELETE CASCADE,
  FOREIGN KEY (diverifikasi_oleh) REFERENCES users(id)   ON DELETE SET NULL,
  INDEX idx_bukti_status (status, tagihan_id)
) ENGINE=InnoDB;