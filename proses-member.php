<?php
// Jangan tampilkan error PHP ke browser (merusak output JSON). Cek log server.
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'config/koneksi.php';
require_once 'config/helpers.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------------------------------------------------------
// EXPORT CSV (GET ?export=csv, filter sama dengan datamember.php)
// ---------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    exportCsv($pdo);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_REQUEST['action'] ?? '';

// Aksi yang mengubah data wajib lewat POST
if (in_array($action, ['tambah', 'edit', 'hapus'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['success' => false, 'message' => 'Metode request tidak valid']);
}

try {
    switch ($action) {

        // -------------------------------------------------
        // GET: data 1 member untuk form edit
        // -------------------------------------------------
        case 'get':
            $id = (int)($_REQUEST['id'] ?? 0);
            if ($id <= 0) {
                jsonOut(['success' => false, 'message' => 'ID tidak valid']);
            }

            $sql = "SELECT m.*, pm.program_id, pm.total_sesi, pm.pelatih_id
                      FROM members m
                      LEFT JOIN paket_member pm
                             ON pm.member_id = m.id AND pm.status = 'aktif'
                     WHERE m.id = ?
                     ORDER BY pm.id DESC
                     LIMIT 1";
            $data = ambil($pdo, $sql, [$id]);

            if (empty($data)) {
                jsonOut(['success' => false, 'message' => 'Member tidak ditemukan']);
            }
            jsonOut(['success' => true, 'data' => $data[0]]);
            break;

        // -------------------------------------------------
        // TAMBAH: member baru + paket aktif + pelatih
        // -------------------------------------------------
        case 'tambah':
            $nama        = trim($_POST['nama'] ?? '');
            $no_whatsapp = trim($_POST['no_whatsapp'] ?? '');
            $program_id  = (int)($_POST['program_id'] ?? 0);
            $pelatih_id  = (int)($_POST['pelatih_id'] ?? 0);
            $total_sesi  = (int)($_POST['total_sesi'] ?? 0);

            if ($nama === '' || $no_whatsapp === '' || $program_id <= 0 || $pelatih_id <= 0) {
                jsonOut(['success' => false, 'message' => 'Nama, No. WhatsApp, Program, dan Pelatih wajib diisi']);
            }
            if (!programAda($pdo, $program_id)) {
                jsonOut(['success' => false, 'message' => 'Program tidak ditemukan']);
            }
            if (!pelatihAda($pdo, $pelatih_id)) {
                jsonOut(['success' => false, 'message' => 'Pelatih tidak ditemukan atau tidak aktif']);
            }
            // Kalau total sesi kosong, pakai jumlah sesi bawaan program
            if ($total_sesi <= 0) {
                $total_sesi = jumlahSesiProgram($pdo, $program_id);
            }

            $pdo->beginTransaction();

            // Kode sementara (unik), lalu diganti NX-xxxx berdasarkan ID hasil insert
            $stmt = $pdo->prepare(
                "INSERT INTO members (kode_member, nama, no_whatsapp, segmen, tanggal_daftar, status)
                 VALUES (?, ?, ?, 'anak', CURDATE(), 'aktif')"
            );
            $stmt->execute(['TMP' . uniqid(), $nama, $no_whatsapp]);
            $member_id = (int)$pdo->lastInsertId();

            $kode_member = 'NX-' . str_pad((string)$member_id, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE members SET kode_member = ? WHERE id = ?")
                ->execute([$kode_member, $member_id]);

            $pdo->prepare(
                "INSERT INTO paket_member
                    (member_id, program_id, pelatih_id, total_sesi, sisa_sesi, tanggal_mulai, status)
                 VALUES (?, ?, ?, ?, ?, CURDATE(), 'aktif')"
            )->execute([$member_id, $program_id, $pelatih_id, $total_sesi, $total_sesi]);

            $pdo->commit();
            jsonOut(['success' => true, 'message' => 'Member berhasil ditambahkan']);
            break;

        // -------------------------------------------------
        // EDIT: data member + paket aktif + pelatih
        // -------------------------------------------------
        case 'edit':
            $id          = (int)($_POST['id'] ?? 0);
            $nama        = trim($_POST['nama'] ?? '');
            $no_whatsapp = trim($_POST['no_whatsapp'] ?? '');
            $program_id  = (int)($_POST['program_id'] ?? 0);
            $pelatih_id  = (int)($_POST['pelatih_id'] ?? 0);
            $total_sesi  = (int)($_POST['total_sesi'] ?? 0);

            if ($id <= 0 || $nama === '' || $no_whatsapp === '' || $program_id <= 0 || $pelatih_id <= 0) {
                jsonOut(['success' => false, 'message' => 'Data tidak lengkap']);
            }
            if (!programAda($pdo, $program_id)) {
                jsonOut(['success' => false, 'message' => 'Program tidak ditemukan']);
            }
            if (!pelatihAda($pdo, $pelatih_id)) {
                jsonOut(['success' => false, 'message' => 'Pelatih tidak ditemukan atau tidak aktif']);
            }
            if ($total_sesi <= 0) {
                $total_sesi = jumlahSesiProgram($pdo, $program_id);
            }

            $pdo->beginTransaction();

            $pdo->prepare("UPDATE members SET nama = ?, no_whatsapp = ? WHERE id = ?")
                ->execute([$nama, $no_whatsapp, $id]);

            $paket = ambil(
                $pdo,
                "SELECT id FROM paket_member
                  WHERE member_id = ? AND status = 'aktif'
                  ORDER BY id DESC LIMIT 1",
                [$id]
            );

            if (!empty($paket)) {
                // sisa_sesi disesuaikan dengan selisih total sesi (sesi terpakai tidak hilang).
                // Urutan SET penting: sisa_sesi dihitung dulu memakai total_sesi lama.
                $pdo->prepare(
                    "UPDATE paket_member
                        SET program_id = ?,
                            pelatih_id = ?,
                            sisa_sesi  = GREATEST(0, sisa_sesi + (? - total_sesi)),
                            total_sesi = ?
                      WHERE id = ?"
                )->execute([$program_id, $pelatih_id, $total_sesi, $total_sesi, $paket[0]['id']]);
            } else {
                $pdo->prepare(
                    "INSERT INTO paket_member
                        (member_id, program_id, pelatih_id, total_sesi, sisa_sesi, tanggal_mulai, status)
                     VALUES (?, ?, ?, ?, ?, CURDATE(), 'aktif')"
                )->execute([$id, $program_id, $pelatih_id, $total_sesi, $total_sesi]);
            }

            $pdo->commit();
            jsonOut(['success' => true, 'message' => 'Member berhasil diperbarui']);
            break;

        // -------------------------------------------------
        // HAPUS: member + paketnya (dalam satu transaksi)
        // -------------------------------------------------
        case 'hapus':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonOut(['success' => false, 'message' => 'ID tidak valid']);
            }

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM paket_member WHERE member_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$id]);
            $pdo->commit();

            jsonOut(['success' => true, 'message' => 'Member berhasil dihapus']);
            break;

        default:
            jsonOut(['success' => false, 'message' => 'Aksi tidak dikenal']);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[proses-member] ' . $e->getMessage());
    jsonOut(['success' => false, 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.']);
}

// ---------------------------------------------------------
// Helper
// ---------------------------------------------------------
function programAda(PDO $pdo, int $id): bool
{
    $r = ambil($pdo, "SELECT id FROM kelas_program WHERE id = ? LIMIT 1", [$id]);
    return !empty($r);
}

function pelatihAda(PDO $pdo, int $id): bool
{
    // $id = pelatih.id (bukan users.id)
    $r = ambil($pdo, "SELECT id FROM pelatih WHERE id = ? AND status = 'aktif' LIMIT 1", [$id]);
    return !empty($r);
}

function jumlahSesiProgram(PDO $pdo, int $program_id): int
{
    $r = ambil($pdo, "SELECT jumlah_sesi FROM kelas_program WHERE id = ? LIMIT 1", [$program_id]);
    return (int)($r[0]['jumlah_sesi'] ?? 8);
}

function csvAman($v): string
{
    $v = (string)($v ?? '');
    // Cegah CSV/formula injection saat dibuka di Excel
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
        $v = "'" . $v;
    }
    return $v;
}

function exportCsv(PDO $pdo): void
{
    $search         = trim($_GET['search'] ?? '');
    $program_filter = trim($_GET['program'] ?? '');
    $pelatih_filter = trim($_GET['pelatih'] ?? '');
    $status_filter  = trim($_GET['status'] ?? 'semua');

    $where  = "WHERE 1=1";
    $params = [];

    if ($search !== '') {
        $where .= " AND (m.nama LIKE :s1 OR m.no_whatsapp LIKE :s2 OR m.kode_member LIKE :s3)";
        $params[':s1'] = $params[':s2'] = $params[':s3'] = "%$search%";
    }
    if ($program_filter !== '') {
        $where .= " AND pm.program_id = :program_id";
        $params[':program_id'] = $program_filter;
    }
    if ($pelatih_filter !== '') {
        $where .= " AND pm.pelatih_id = :pelatih_id";
        $params[':pelatih_id'] = $pelatih_filter;
    }
    if ($status_filter === 'aktif') {
        $where .= " AND pm.status = 'aktif'";
    } elseif ($status_filter === 'nonaktif') {
        $where .= " AND (pm.status IS NULL OR pm.status <> 'aktif')";
    }

    $sql = "SELECT m.kode_member, m.nama, m.no_whatsapp, m.tanggal_daftar,
                   kp.nama_program, pm.total_sesi, pm.sisa_sesi,
                   pm.status AS status_paket, u.nama AS nama_pelatih
              FROM members m
              LEFT JOIN paket_member pm ON pm.member_id = m.id
              LEFT JOIN kelas_program kp ON kp.id = pm.program_id
              LEFT JOIN pelatih pl ON pl.id = pm.pelatih_id
              LEFT JOIN users u ON u.id = pl.user_id
              $where
              ORDER BY m.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $filename = 'data-member-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
    fputcsv($out, ['Kode Member', 'Nama', 'WhatsApp', 'Tanggal Daftar', 'Program',
                   'Total Sesi', 'Sisa Sesi', 'Status Paket', 'Pelatih'], ',', '"', '\\');

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            csvAman($r['kode_member']),
            csvAman($r['nama']),
            csvAman($r['no_whatsapp']),
            $r['tanggal_daftar'],
            csvAman($r['nama_program']),
            $r['total_sesi'],
            $r['sisa_sesi'],
            csvAman($r['status_paket']),
            csvAman($r['nama_pelatih']),
        ], ',', '"', '\\');
    }
    fclose($out);
}