USE natix_swim;

SHOW TRIGGERS;                                 
SELECT * FROM v_kuota_hampir_habis;            
SELECT SUM(pendapatan_juta) AS total_ytd
FROM v_pendapatan_bulanan WHERE tahun = 2026;  

-- Tes presensi: Farel hadir → sisa 0, status 'habis', log otomasi bertambah
INSERT INTO presensi (jadwal_id, member_id, paket_id, status, dicatat_oleh)
VALUES (1, 6, 6, 'hadir', 3);
SELECT sisa_sesi, status FROM paket_member WHERE id = 6;
SELECT * FROM log_otomasi;

-- Tes pembayaran: tagihan jadi 'lunas' dan kuitansi terbuat otomatis
INSERT INTO pembayaran (tagihan_id, jumlah, metode, dicatat_oleh)
VALUES (1, 600000, 'transfer', 1);
SELECT status FROM tagihan WHERE id = 1;
SELECT * FROM kuitansi;
