USE natix_swim;
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