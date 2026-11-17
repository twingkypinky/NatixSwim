<?php
// ============================================================
// PROSES JADWAL - NATIX SWIM
// File ini menangani semua operasi CRUD (Tambah, Edit, Hapus)
// untuk jadwal kelas. Dipanggil dari form atau JavaScript.
// ============================================================

// 1. KONEKSI KE DATABASE
require_once 'config/koneksi.php';

// 2. CEK AKSI YANG DIMINTA
$aksi = isset($_REQUEST['aksi']) ? $_REQUEST['aksi'] : '';

// ============================================================
// AKSI: AMBIL DATA SATU JADWAL (untuk edit)
// ============================================================
if ($aksi == 'ambil') {
    $id = (int) $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM jadwal WHERE id = ?");
    $stmt->execute([$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($data) {
        echo json_encode($data);
    } else {
        echo json_encode(['error' => 'Data tidak ditemukan']);
    }
    exit;
}

// ============================================================
// AKSI: TAMBAH JADWAL BARU
// ============================================================
if ($aksi == 'tambah') {
    // Ambil data dari form
    $program_id   = (int) $_POST['program_id'];
    $pelatih_id   = (int) $_POST['pelatih_id'];
    $hari         = $_POST['hari'];
    $jam_mulai    = $_POST['jam_mulai'];
    $jam_selesai  = $_POST['jam_selesai'];
    $kolam        = $_POST['kolam'];
    $kapasitas    = !empty($_POST['kapasitas']) ? (int) $_POST['kapasitas'] : 0;
    $ulangi       = $_POST['ulangi'];

    // Cari tahu tanggal untuk hari ini di minggu yang aktif
    // Karena kita pakai ?week=, kita ambil dari referensi
    $week_start = isset($_POST['week_start']) ? $_POST['week_start'] : date('Y-m-d', strtotime('monday this week'));
    
    // Konversi nama hari ke angka (0=Senin, 1=Selasa, ... 6=Minggu)
    $hari_map = ['Sen'=>0, 'Sel'=>1, 'Rab'=>2, 'Kam'=>3, 'Jum'=>4, 'Sab'=>5, 'Min'=>6];
    $hari_angka = $hari_map[$hari];
    
    // Hitung tanggal: week_start + hari_angka
    $tanggal = date('Y-m-d', strtotime($week_start . " +{$hari_angka} days"));

    // Simpan ke database
    $sql = "INSERT INTO jadwal (program_id, pelatih_id, tanggal, hari, jam_mulai, jam_selesai, kolam, kapasitas, ulangi) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$program_id, $pelatih_id, $tanggal, $hari, $jam_mulai, $jam_selesai, $kolam, $kapasitas, $ulangi]);

    // Redirect kembali ke halaman jadwal
    header('Location: jadwal-kelas.php?week=' . $week_start);
    exit;
}

// ============================================================
// AKSI: EDIT JADWAL
// ============================================================
if ($aksi == 'edit') {
    $id           = (int) $_POST['id'];
    $program_id   = (int) $_POST['program_id'];
    $pelatih_id   = (int) $_POST['pelatih_id'];
    $hari         = $_POST['hari'];
    $jam_mulai    = $_POST['jam_mulai'];
    $jam_selesai  = $_POST['jam_selesai'];
    $kolam        = $_POST['kolam'];
    $kapasitas    = !empty($_POST['kapasitas']) ? (int) $_POST['kapasitas'] : 0;

    // Update data di database
    $sql = "UPDATE jadwal SET 
              program_id = ?, 
              pelatih_id = ?, 
              hari = ?, 
              jam_mulai = ?, 
              jam_selesai = ?, 
              kolam = ?, 
              kapasitas = ? 
            WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$program_id, $pelatih_id, $hari, $jam_mulai, $jam_selesai, $kolam, $kapasitas, $id]);

    // Kirim response JSON (karena dipanggil lewat JavaScript)
    echo json_encode(['success' => true]);
    exit;
}

// ============================================================
// AKSI: HAPUS JADWAL
// ============================================================
if ($aksi == 'hapus') {
    $id = (int) $_POST['id'];
    
    // Hapus dari database
    $stmt = $pdo->prepare("DELETE FROM jadwal WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true]);
    exit;
}

// ============================================================
// JIKA TIDAK ADA AKSI YANG COCOK
// ============================================================
echo json_encode(['error' => 'Aksi tidak dikenal']);
exit;
