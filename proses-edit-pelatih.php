<?php
// ==========================================
//  FILE: proses-edit-pelatih.php
//  DESKRIPSI: CRUD Edit Profil Pelatih
//  Method: POST (from modal form)
// ==========================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/koneksi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: data-pelatih-daftar.php');
    exit;
}

$id_pelatih = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($id_pelatih <= 0) {
    header('Location: data-pelatih-daftar.php');
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. AMBIL user_id dari pelatih
    $stmt = $pdo->prepare("SELECT user_id FROM pelatih WHERE id = :id");
    $stmt->execute([':id' => $id_pelatih]);
    $pelatih = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pelatih) {
        throw new Exception('Data pelatih tidak ditemukan');
    }
    $user_id = $pelatih['user_id'];

    // 2. UPDATE tabel users
    $nama   = trim($_POST['nama'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $no_hp  = trim($_POST['no_hp'] ?? '');

    if (empty($nama)) {
        throw new Exception('Nama tidak boleh kosong');
    }

    $stmt = $pdo->prepare("UPDATE users SET nama = :nama, email = :email, no_hp = :no_hp WHERE id = :id");
    $stmt->execute([
        ':nama'  => $nama,
        ':email' => $email,
        ':no_hp' => $no_hp,
        ':id'    => $user_id
    ]);

    // 3. UPDATE tabel pelatih
    $jenis_kelamin    = $_POST['jenis_kelamin'] ?? 'Laki-laki';
    $pengalaman_tahun = intval($_POST['pengalaman_tahun'] ?? 0);
    $sistem_honor     = $_POST['sistem_honor'] ?? 'per_sesi';
    $status           = $_POST['status'] ?? 'aktif';
    $spesialisasi_str = trim($_POST['spesialisasi'] ?? '');

    $stmt = $pdo->prepare("
        UPDATE pelatih SET
            jenis_kelamin    = :jenis_kelamin,
            pengalaman_tahun = :pengalaman_tahun,
            sistem_honor     = :sistem_honor,
            status           = :status,
            spesialisasi     = :spesialisasi
        WHERE id = :id
    ");
    $stmt->execute([
        ':jenis_kelamin'    => $jenis_kelamin,
        ':pengalaman_tahun' => $pengalaman_tahun,
        ':sistem_honor'     => $sistem_honor,
        ':status'           => $status,
        ':spesialisasi'     => $spesialisasi_str,
        ':id'               => $id_pelatih
    ]);

    // 4. UPDATE spesialisasi (table pelatih_spesialisasi)
    // Hapus dulu yang lama
    $stmt = $pdo->prepare("DELETE FROM pelatih_spesialisasi WHERE pelatih_id = :id");
    $stmt->execute([':id' => $id_pelatih]);

    // Insert yang baru
    $spesialisasi_items = array_filter(array_map('trim', explode(',', $spesialisasi_str)));
    if (!empty($spesialisasi_items)) {
        $stmt = $pdo->prepare("INSERT INTO pelatih_spesialisasi (pelatih_id, nama_spesialisasi) VALUES (:pid, :sp)");
        foreach ($spesialisasi_items as $sp) {
            if (!empty($sp)) {
                $stmt->execute([':pid' => $id_pelatih, ':sp' => $sp]);
            }
        }
    }

    // 5. UPDATE sertifikasi (table pelatih_sertifikasi)
    // Hapus dulu yang lama
    $stmt = $pdo->prepare("DELETE FROM pelatih_sertifikasi WHERE pelatih_id = :id");
    $stmt->execute([':id' => $id_pelatih]);

    // Insert yang baru
    $sertifikasi_text = trim($_POST['sertifikasi'] ?? '');
    if (!empty($sertifikasi_text)) {
        $baris_sertif = array_filter(array_map('trim', explode("\n", $sertifikasi_text)));
        $stmt = $pdo->prepare("INSERT INTO pelatih_sertifikasi (pelatih_id, nama_sertifikasi, berlaku_sampai) VALUES (:pid, :nama, :berlaku)");
        foreach ($baris_sertif as $baris) {
            $baris = trim($baris);
            if (empty($baris)) continue;

            // Coba ekstrak tahun dari format: "Nama Sertifikasi (2026)" atau "Nama Sertifikasi 2026"
            $nama_sertif = $baris;
            $tahun = null;
            if (preg_match('/\((\d{4})\)/', $baris, $m)) {
                $nama_sertif = trim(str_replace($m[0], '', $baris));
                $tahun = $m[1] . '-12-31';
            } elseif (preg_match('/\b(19|20)\d{2}\b/', $baris, $m)) {
                $tahun = $m[0] . '-12-31';
            }

            $stmt->execute([
                ':pid'    => $id_pelatih,
                ':nama'   => $nama_sertif,
                ':berlaku' => $tahun
            ]);
        }
    }

    $pdo->commit();

    // Redirect back with success
    $referer = $_SERVER['HTTP_REFERER'] ?? 'data-pelatih-detail.php?id=' . $id_pelatih;
    $separator = strpos($referer, '?') !== false ? '&' : '?';
    header('Location: ' . $referer . $separator . 'edit=success');
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    $referer = $_SERVER['HTTP_REFERER'] ?? 'data-pelatih-detail.php?id=' . $id_pelatih;
    $separator = strpos($referer, '?') !== false ? '&' : '?';
    header('Location: ' . $referer . $separator . 'edit=error&msg=' . urlencode($e->getMessage()));
    exit;
}
