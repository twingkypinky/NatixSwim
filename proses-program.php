<?php
require_once 'config/koneksi.php';
require_once 'config/helpers.php';

header('Content-Type: application/json');

$aksi = $_POST['aksi'] ?? '';

try {
    if ($aksi === 'tambah') {
        $pdo->prepare("INSERT INTO kelas_program 
            (nama_program, level, tipe, jumlah_sesi, kapasitas, harga, deskripsi, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $_POST['nama_program'],
            $_POST['level'],
            $_POST['tipe'],
            (int)$_POST['jumlah_sesi'],
            (int)$_POST['kapasitas'],
            (int)$_POST['harga'],
            $_POST['deskripsi'] ?? '',
            $_POST['status']
        ]);
        echo json_encode(['status' => 'ok', 'pesan' => 'Program berhasil ditambahkan']);
    }
    elseif ($aksi === 'edit') {
        $pdo->prepare("UPDATE kelas_program SET 
            nama_program = ?, level = ?, tipe = ?, jumlah_sesi = ?, 
            kapasitas = ?, harga = ?, deskripsi = ?, status = ?
            WHERE id = ?")
        ->execute([
            $_POST['nama_program'],
            $_POST['level'],
            $_POST['tipe'],
            (int)$_POST['jumlah_sesi'],
            (int)$_POST['kapasitas'],
            (int)$_POST['harga'],
            $_POST['deskripsi'] ?? '',
            $_POST['status'],
            (int)$_POST['id']
        ]);
        echo json_encode(['status' => 'ok', 'pesan' => 'Program berhasil diperbarui']);
    }
    elseif ($aksi === 'hapus') {
        $pdo->prepare("DELETE FROM kelas_program WHERE id = ?")
        ->execute([(int)$_POST['id']]);
        echo json_encode(['status' => 'ok', 'pesan' => 'Program berhasil dihapus']);
    }
    else {
        echo json_encode(['status' => 'error', 'pesan' => 'Aksi tidak dikenal']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'pesan' => $e->getMessage()]);
}
