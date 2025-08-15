<?php
/* m_users.php — Kelola Pengguna + Import CSV/XLSX + Bulk Edit Kelas & Level */
session_start();

/* ====== KONFIG DB ====== */
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'book_management_system';

/* ====== KONEK DB ====== */
try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Koneksi gagal: " . htmlspecialchars($e->getMessage()));
}

/* ====== (Opsional) Cek Login ====== */
// if (!isset($_SESSION['admin_logged_in'])) { header("Location: index.php"); exit; }

/* ====== Helper Kategori & Level ====== */
function get_category_by_id(PDO $db, int $id) {
    $s = $db->prepare("SELECT id, name FROM categories WHERE id=?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}
function get_category_by_name(PDO $db, string $name) {
    $s = $db->prepare("SELECT id, name FROM categories WHERE name=?");
    $s->execute([$name]);
    return $s->fetch() ?: null;
}

$error = ''; // init biar aman

/* ====== Data referensi untuk UI ====== */
$categories = $db->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$levelsRows = $db->query("SELECT id, category_id, level FROM book_levels ORDER BY category_id, level")->fetchAll();

$levelsByCat = [];
foreach ($levelsRows as $r) {
    $levelsByCat[(int)$r['category_id']][] = [
        'id'    => (int)$r['id'],
        'label' => 'Level ' . (int)$r['level'],
        'n'     => (int)$r['level'],
    ];
}
$kategoriBuku = array_values(array_unique(array_map(fn($c) => $c['name'], $categories)));

/* =========================================================
   HANDLERS
   ========================================================= */

/* Tambah user (opsional assign level awal) */
if (isset($_POST['add_user'])) {
    $name      = trim($_POST['name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = 'Leap1234';
    $kelas_id  = (int)($_POST['kelas_id'] ?? 0);
    $level_id  = (int)($_POST['level_id'] ?? 0);

    try {
        if ($name === '' || $email === '' || $kelas_id <= 0) {
            throw new Exception("Nama, Email, dan Kategori wajib diisi.");
        }

        // Pastikan email unik
        $cek = $db->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?)");
        $cek->execute([$email]);
        if ($cek->fetch()) throw new Exception("Email sudah terdaftar: ".$email);

        $cat = get_category_by_id($db, $kelas_id);
        if (!$cat) throw new Exception("Kategori tidak ditemukan.");

        $db->prepare("INSERT INTO users (name, email, password, kelas) VALUES (?,?,?,?)")
           ->execute([$name, $email, $password, $cat['name']]);
        $uid = (int)$db->lastInsertId();

        if ($level_id > 0) {
            // validasi level utk kategori yg dipilih
            $v = $db->prepare("SELECT COUNT(*) FROM book_levels WHERE id=? AND category_id=?");
            $v->execute([$level_id, $kelas_id]);
            if (!$v->fetchColumn()) throw new Exception("Level tidak valid untuk kategori terpilih.");

            $db->prepare("INSERT INTO user_books (user_id, book_level_id) VALUES (?,?)")
               ->execute([$uid, $level_id]);
            $_SESSION['success_message'] = "Pengguna ditambahkan & ditugaskan ke level awal.";
        } else {
            $_SESSION['success_message'] = "Pengguna berhasil ditambahkan!";
        }

        header("Location: m_users.php");
        exit;
    } catch (Exception $e) {
        $error = "Gagal menambahkan pengguna: " . $e->getMessage();
    }
}

/* Hapus user */
if (isset($_GET['delete_user'])) {
    $uid = (int)$_GET['delete_user'];
    $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
    $_SESSION['success_message'] = "Pengguna berhasil dihapus!";
    header("Location: m_users.php");
    exit;
}

/* Bulk ubah kelas & level (via modal + checklist) */
if (isset($_POST['bulk_update_class_level'])) {
    $ids          = isset($_POST['user_ids']) && is_array($_POST['user_ids']) ? array_map('intval', $_POST['user_ids']) : [];
    $new_kelas_id = (int)($_POST['new_kelas_id'] ?? 0);
    $new_level_id = (int)($_POST['new_level_id'] ?? 0);

    try {
        if (empty($ids))      throw new Exception("Tidak ada pengguna dipilih.");
        if ($new_kelas_id<=0) throw new Exception("Kategori (kelas) belum dipilih.");

        $newCat = get_category_by_id($db, $new_kelas_id);
        if (!$newCat) throw new Exception("Kategori baru tidak ditemukan.");

        if ($new_level_id > 0) {
            $chk = $db->prepare("SELECT COUNT(*) FROM book_levels WHERE id=? AND category_id=?");
            $chk->execute([$new_level_id, $new_kelas_id]);
            if (!$chk->fetchColumn()) throw new Exception("Level tidak valid untuk kategori terpilih.");
        }

        $db->beginTransaction();

        // Ambil kelas lama sekali query
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $rows = $db->prepare("SELECT id, kelas FROM users WHERE id IN ($in)");
        $rows->execute($ids);
        $byId = [];
        foreach ($rows as $r) $byId[(int)$r['id']] = $r['kelas'];

        foreach ($ids as $uid) {
            $oldName = $byId[$uid] ?? '';
            $oldCat  = $oldName !== '' ? get_category_by_name($db, $oldName) : null;
            $oldCatId= $oldCat ? (int)$oldCat['id'] : 0;

            // Update users.kelas
            $db->prepare("UPDATE users SET kelas=? WHERE id=?")->execute([$newCat['name'], $uid]);

            // Jika ganti kategori, bersihkan assignment lama
            if ($oldCatId && $oldCatId !== (int)$newCat['id']) {
                $db->prepare("
                    DELETE ub FROM user_books ub
                    JOIN book_levels bl ON bl.id=ub.book_level_id
                    WHERE ub.user_id=? AND bl.category_id=?
                ")->execute([$uid, $oldCatId]);
            }

            // Upsert assignment utk kategori baru bila level dipilih
            if ($new_level_id > 0) {
                $upd = $db->prepare("
                    UPDATE user_books ub
                    JOIN book_levels bl ON bl.id = ub.book_level_id
                    SET ub.book_level_id = :lvl
                    WHERE ub.user_id = :uid AND bl.category_id = :cid
                ");
                $upd->execute([':lvl'=>$new_level_id, ':uid'=>$uid, ':cid'=>$newCat['id']]);
                if ($upd->rowCount() === 0) {
                    $db->prepare("INSERT INTO user_books (user_id, book_level_id) VALUES (?,?)")
                       ->execute([$uid, $new_level_id]);
                }
            }
        }

        $db->commit();
        $_SESSION['success_message'] = "Kelas & level untuk ".count($ids)." pengguna berhasil diperbarui.";
        header("Location: m_users.php");
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = "Gagal bulk update: " . $e->getMessage();
    }
}

/* ================ IMPORT CSV/XLSX (robust header) ================ */
if (isset($_POST['import_csv'])) {
    try {
        // 1) Check if vendor directory exists
        $autoload = __DIR__.'/vendor/autoload.php';
        if (!file_exists($autoload)) {
            throw new Exception("Sistem import membutuhkan library PhpSpreadsheet. Silakan hubungi administrator untuk menginstal dependency.");
        }
        require $autoload;

        // 2) Validate uploaded file
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Silakan pilih file yang valid untuk diimport.');
        }

        $tmpPath = $_FILES['csv_file']['tmp_name'];
        $origName = $_FILES['csv_file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // 3) Validate file extension
        if (!in_array($ext, ['csv', 'xlsx'])) {
            throw new Exception('Format file tidak didukung. Hanya file CSV atau XLSX yang diterima.');
        }

        // 4) Create appropriate reader
        if ($ext === 'csv') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
            $reader->setInputEncoding('UTF-8');
            
            // Auto-detect delimiter
            $sample = file_get_contents($tmpPath, false, null, 0, 4096) ?: '';
            $delimiters = [',', ';', "\t"];
            $bestDelimiter = ',';
            $bestCount = 0;
            
            foreach ($delimiters as $delimiter) {
                $count = substr_count(strtok($sample, "\n"), $delimiter);
                if ($count > $bestCount) {
                    $bestCount = $count;
                    $bestDelimiter = $delimiter;
                }
            }
            
            $reader->setDelimiter($bestDelimiter);
            $reader->setEnclosure('"');
        } else {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        }
        
        $reader->setReadDataOnly(true);

        // 5) Load spreadsheet
        $spreadsheet = $reader->load($tmpPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            throw new Exception('File tidak berisi data atau format tidak sesuai.');
        }

        // 6) Normalize and find header row
        $headerRow = null;
        $headerMap = ['name' => null, 'email' => null, 'kelas' => null, 'level' => null];
        
        // Scan first 5 rows for headers
        for ($i = 1; $i <= 5; $i++) {
            if (!isset($rows[$i])) continue;
            
            $normalized = array_map(function($val) {
                $val = trim(strtolower(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $val)));
                return $val;
            }, $rows[$i]);
            
            // Check if this row contains our required headers
            foreach ($normalized as $col => $value) {
                if (in_array($value, ['name', 'nama'])) $headerMap['name'] = $col;
                if (in_array($value, ['email', 'e-mail'])) $headerMap['email'] = $col;
                if (in_array($value, ['kelas', 'class', 'kategori', 'category'])) $headerMap['kelas'] = $col;
                if (in_array($value, ['level', 'tingkat'])) $headerMap['level'] = $col;
            }
            
            // We need at least email to proceed
            if ($headerMap['email'] !== null) {
                $headerRow = $i;
                break;
            }
        }

        if ($headerRow === null) {
            throw new Exception('Format header tidak valid. Pastikan file memiliki kolom "email".');
        }

        // 7) Prepare database operations
        $db->beginTransaction();
        
        // Prepare statements
        $selUser = $db->prepare("SELECT id, name, kelas FROM users WHERE LOWER(email) = LOWER(?)");
        $insUser = $db->prepare("INSERT INTO users (name, email, password, kelas) VALUES (?, ?, ?, ?)");
        $updUser = $db->prepare("UPDATE users SET name = ?, kelas = ? WHERE id = ?");
        
        $selCat = $db->prepare("SELECT id FROM categories WHERE name = ?");
        $selLevel = $db->prepare("SELECT id FROM book_levels WHERE category_id = ? AND level = ?");
        $updAssign = $db->prepare("
            UPDATE user_books ub
            JOIN book_levels bl ON bl.id = ub.book_level_id
            SET ub.book_level_id = ? 
            WHERE ub.user_id = ? AND bl.category_id = ?
        ");
        $insAssign = $db->prepare("INSERT INTO user_books (user_id, book_level_id) VALUES (?, ?)");

        // 8) Process rows
        $counts = ['add' => 0, 'update' => 0, 'level' => 0, 'skip' => 0];
        $startRow = $headerRow + 1;
        
        for ($i = $startRow; $i <= count($rows); $i++) {
            if (!isset($rows[$i])) continue;
            
            $row = $rows[$i];
            
            // Get values from mapped columns
            $email = isset($headerMap['email'], $row[$headerMap['email']]) 
                   ? trim(strtolower($row[$headerMap['email']])) 
                   : '';
            $name = isset($headerMap['name'], $row[$headerMap['name']]) 
                  ? trim($row[$headerMap['name']]) 
                  : '';
            $kelas = isset($headerMap['kelas'], $row[$headerMap['kelas']]) 
                   ? trim($row[$headerMap['kelas']]) 
                   : '';
            $level = isset($headerMap['level'], $row[$headerMap['level']]) 
                   ? trim($row[$headerMap['level']]) 
                   : '';
            
            // Skip if no email or invalid email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $counts['skip']++;
                continue;
            }
            
            // Check if user exists
            $selUser->execute([$email]);
            $user = $selUser->fetch();
            
            // Determine kelas to use (new or existing)
            $kelasToUse = $kelas !== '' ? $kelas : ($user['kelas'] ?? '');
            
            if ($user) {
                // Update existing user if name or kelas changed
                $newName = $name !== '' ? $name : $user['name'];
                $newKelas = $kelasToUse !== '' ? $kelasToUse : $user['kelas'];
                
                if ($newName !== $user['name'] || $newKelas !== $user['kelas']) {
                    $updUser->execute([$newName, $newKelas, $user['id']]);
                    $counts['update']++;
                }
                $uid = (int)$user['id'];
            } else {
                // Insert new user
                if ($name === '') {
                    $counts['skip']++;
                    continue;
                }
                
                $insUser->execute([$name, $email, 'Leap1234', $kelasToUse]);
                $uid = (int)$db->lastInsertId();
                $counts['add']++;
            }
            
            // Process level assignment if kelas and level provided
            if ($kelasToUse !== '' && $level !== '' && is_numeric($level)) {
                $selCat->execute([$kelasToUse]);
                $cat = $selCat->fetch();
                
                if ($cat) {
                    $selLevel->execute([(int)$cat['id'], (int)$level]);
                    $levelData = $selLevel->fetch();
                    
                    if ($levelData) {
                        $updAssign->execute([(int)$levelData['id'], $uid, (int)$cat['id']]);
                        if ($updAssign->rowCount() === 0) {
                            $insAssign->execute([$uid, (int)$levelData['id']]);
                        }
                        $counts['level']++;
                    }
                }
            }
        }
        
        $db->commit();
        
        $_SESSION['success_message'] = sprintf(
            "Import berhasil! Ditambahkan: %d, Diupdate: %d, Level diatur: %d, Dilewati: %d",
            $counts['add'], $counts['update'], $counts['level'], $counts['skip']
        );
        
        header("Location: m_users.php");
        exit;
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $error = "Gagal mengimport data: " . $e->getMessage();
    }
}

/* =========================================================
   FILTER & PAGINATION
   ========================================================= */
$limitOptions = [5,10,15,25];
$limit  = (isset($_GET['limit']) && in_array((int)$_GET['limit'], $limitOptions)) ? (int)$_GET['limit'] : 10;
$search = trim($_GET['search'] ?? '');
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$c = $db->prepare("SELECT COUNT(*) FROM users WHERE kelas LIKE :s");
$c->bindValue(':s', '%'.$search.'%');
$c->execute();
$totalRows  = (int)$c->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $limit; }

$sql = "
SELECT 
  u.*,
  c2.id    AS kelas_category_id,
  bl.id    AS current_level_id,
  bl.level AS current_level
FROM users u
LEFT JOIN categories c2 ON c2.name = u.kelas
LEFT JOIN user_books ub ON ub.user_id = u.id
LEFT JOIN book_levels bl ON bl.id = ub.book_level_id AND bl.category_id = c2.id
WHERE u.kelas LIKE :s
ORDER BY u.email
LIMIT $limit OFFSET $offset
";
$q = $db->prepare($sql);
$q->bindValue(':s', '%'.$search.'%');
$q->execute();
$users = $q->fetchAll();

/* Helper URL */
function build_url(array $ov = []): string {
    $cur = $_GET;
    foreach ($ov as $k=>$v) $cur[$k] = $v;
    return '?'.http_build_query($cur);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Kelola Pengguna</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>
:root{--main:#0b3a6f;--main2:#0e58a8;--bg:#f4f6fb}
body{background:var(--bg);font-family:Segoe UI,system-ui,-apple-system,Roboto,Arial}
.sidebar{background:var(--main);min-height:100vh;color:#fff}
.sidebar .nav-link{color:#fff}
.sidebar .nav-link.active,.sidebar .nav-link:hover{background:var(--main2);border-radius:8px}
.card-header{background:var(--main);color:#fff}
.table thead th{background:var(--main);color:#fff}
.pagination .page-link{border-radius:10px!important;padding:.5rem .9rem}
.pagination .active .page-link{background:#e9ecef;color:#000;border-color:#ced4da}
.badge-lightish{background:#f8f9fa;border:1px solid #e9ecef}
.checkbox-col{display:none}
.header-actions .btn{min-width:140px}
</style>
</head>
<body>
<div class="container-fluid">
  <div class="row flex-nowrap">
    <aside class="col-md-3 col-lg-2 sidebar p-3">
      <h4 class="text-center mb-4"><i class="fa-solid fa-graduation-cap me-2"></i>Leap English</h4>
      <ul class="nav flex-column gap-2">
        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fa-solid fa-gauge me-2"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="manage_books.php"><i class="fa-solid fa-book me-2"></i> Kelola Buku</a></li>
        <li class="nav-item"><a class="nav-link active" href="m_users.php"><i class="fa-solid fa-users me-2"></i> Kelola User</a></li>
      </ul>
    </aside>

    <main class="col-md-9 col-lg-10 px-md-5 py-4">
      <h2 class="mb-4"><i class="fa-solid fa-users me-2"></i> Kelola Pengguna</h2>

      <?php if (!empty($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
          <?= htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
          <?= htmlspecialchars($error) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Tambah Pengguna -->
      <div class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-user-plus me-2"></i> Tambah Pengguna Baru</div>
        <div class="card-body">
          <form method="POST" autocomplete="off">
            <input type="hidden" name="add_user" value="1">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Nama</label>
                <input class="form-control" name="name" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Email</label>
                <input class="form-control" name="email" type="email" required>
              </div>
              <div class="col-md-4">
                <label class="form-label d-flex align-items-center gap-2">
                  Kategori (Kelas) <span class="badge text-bg-light">otomatis isi level</span>
                </label>
                <select class="form-select" name="kelas_id" id="kelas_id" required>
                  <option value="">-- Pilih Kategori --</option>
                  <?php foreach($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Level (sesuai kategori)</label>
                <select class="form-select" name="level_id" id="level_id" disabled required>
                  <option value="">Pilih kategori dulu</option>
                </select>
                <small class="text-muted">Level diambil dari Manage Books.</small>
              </div>
              <div class="col-md-8">
                <label class="form-label">Password (default)</label>
                <input class="form-control" value="Leap1234" disabled>
              </div>
            </div>
            <button class="btn btn-dark mt-3"><i class="fa-solid fa-save me-1"></i> Simpan</button>
          </form>
        </div>
      </div>

      <!-- Daftar Pengguna -->
      <div class="card">
        <div class="card-header">
          <div class="row g-2 align-items-center">
            <div class="col-md-4 text-white">
              <i class="fa-solid fa-list me-2"></i> Daftar Pengguna
              <span class="badge text-bg-light ms-2"><?= number_format($totalRows) ?> data</span>
            </div>
            <div class="col-md-8 header-actions">
              <form class="d-flex flex-wrap gap-2 justify-content-md-end" method="GET">
                <input name="search" class="form-control form-control-sm w-auto" placeholder="Cari kelas..." value="<?= htmlspecialchars($search) ?>">
                <select name="limit" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                  <?php foreach($limitOptions as $opt): ?>
                    <option value="<?= $opt ?>" <?= $limit==$opt?'selected':'' ?>>Tampilkan <?= $opt ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-light w-auto" type="submit">
                  <i class="fa-solid fa-magnifying-glass me-1"></i> Terapkan
                </button>

                <a class="btn btn-sm btn-success w-auto" href="download_template_xlsx.php">
                  <i class="fa-solid fa-file-excel me-1"></i> Download Template XLSX
                </a>

                <button type="button" class="btn btn-sm btn-info text-white w-auto" data-bs-toggle="modal" data-bs-target="#importCsvModal">
                  <i class="fa-solid fa-file-import me-1"></i> Import CSV/XLSX
                </button>

                <button class="btn btn-sm btn-primary w-auto" id="btnStartSelect" type="button">
                  <i class="fa-solid fa-pen me-1"></i> Ubah Kelas & Level
                </button>
                <button class="btn btn-sm btn-success w-auto d-none" id="btnConfirm" type="button" disabled>
                  <i class="fa-solid fa-check me-1"></i> Konfirmasi
                </button>
                <button class="btn btn-sm btn-secondary w-auto d-none" id="btnCancel" type="button">
                  <i class="fa-solid fa-xmark me-1"></i> Batal
                </button>
              </form>
            </div>
          </div>
        </div>

        <div class="card-body table-responsive">
          <table class="table table-bordered table-hover align-middle" id="userTable">
            <thead>
              <tr>
                <th class="checkbox-col" style="width:40px;"><input type="checkbox" id="checkAll"></th>
                <th style="width:70px;">No</th>
                <th>Nama</th><th>Email</th>
                <th>Kelas</th>
                <th style="width:120px;">Level</th>
                <th style="width:120px;">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php $no=$offset+1; foreach ($users as $u):
                $uid=(int)$u['id'];
                $kelas=(string)$u['kelas'];
                $curLevel = $u['current_level']!==null ? ('Level '.(int)$u['current_level']) : '-';
              ?>
              <tr>
                <td class="checkbox-col"><input type="checkbox" class="user-checkbox" value="<?= $uid ?>"></td>
                <td><?= $no++ ?></td>
                <td><?= htmlspecialchars($u['name']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><span class="badge badge-lightish"><?= htmlspecialchars($kelas) ?></span></td>
                <td><?= htmlspecialchars($curLevel) ?></td>
                <td>
                  <a class="btn btn-sm btn-danger" href="?delete_user=<?= $uid ?>" onclick="return confirm('Yakin hapus?')">
                    <i class="fa-solid fa-trash"></i>
                  </a>
                </td>
              </tr>
              <?php endforeach; if (empty($users)): ?>
              <tr><td colspan="7" class="text-center text-muted">Tidak ada data.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>

          <?php if ($totalPages > 1): ?>
          <nav class="d-flex justify-content-center justify-content-md-end">
            <ul class="pagination gap-2 mb-0">
              <li class="page-item <?= $page<=1?'disabled':'' ?>">
                <a class="page-link" href="<?= $page<=1?'#':build_url(['page'=>$page-1]) ?>">Previous</a>
              </li>
              <?php $win=4;$s=max(1,$page-$win);$e=min($totalPages,$page+$win);
              for($i=$s;$i<=$e;$i++): ?>
                <li class="page-item <?= $i==$page?'active':'' ?>">
                  <a class="page-link" href="<?= build_url(['page'=>$i]) ?>"><?= $i ?></a>
                </li>
              <?php endfor; ?>
              <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
                <a class="page-link" href="<?= $page>>= $totalPages?'#':build_url(['page'=>$page+1]) ?>">Next</a>
              </li>
            </ul>
          </nav>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- MODAL: Import CSV/XLSX -->
<div class="modal fade" id="importCsvModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="import_csv" value="1">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="fa-solid fa-file-import me-2"></i>Import CSV/XLSX Pengguna</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Format header: <code>Name, Email, Kelas, Level</code> (case bebas).</p>
        <ul class="small mb-3">
          <li><b>Email</b> jadi kunci unik. Kalau sudah ada → data di-<em>update</em>.</li>
          <li><b>Kelas</b> harus sama dengan nama kategori di <em>Manage Books</em>.</li>
          <li><b>Level</b> opsional (angka). Jika diisi dan valid → assignment level di-<em>upsert</em>.</li>
        </ul>
        <div class="mb-3">
          <label class="form-label">Pilih file (.csv/.xlsx)</label>
          <input class="form-control" type="file" name="csv_file" accept=".csv,.xlsx" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-dark"><i class="fa-solid fa-upload me-1"></i> Import</button>
      </div>
    </form>
  </div></div>
</div>

<!-- MODAL: Bulk Ubah Kelas & Level -->
<div class="modal fade" id="bulkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST" id="bulkForm" autocomplete="off">
      <input type="hidden" name="bulk_update_class_level" value="1">
      <div id="bulkUserIds"></div>
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="fa-solid fa-sliders me-2"></i>Ubah Kelas & Level</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Kategori (Kelas)</label>
          <select name="new_kelas_id" id="bk_kelas" class="form-select" required>
            <option value="">-- Pilih Kategori --</option>
            <?php foreach($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-1">
          <label class="form-label">Level</label>
          <select name="new_level_id" id="bk_level" class="form-select" disabled>
            <option value="">Pilih kategori dulu</option>
          </select>
          <small class="text-muted">Biarkan “Pilih Level” jika tidak ingin mengubah level.</small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-dark">Simpan</button>
      </div>
    </form>
  </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ====== Data level per kategori (untuk JS) ====== */
const LEVELS_BY_CAT = <?= json_encode($levelsByCat, JSON_UNESCAPED_UNICODE) ?>;

/* Tambah user: isi level sesuai kategori */
const kelasSel = document.getElementById('kelas_id');
const levelSel = document.getElementById('level_id');
function fillAddLevels(cid){
  levelSel.innerHTML = '';
  cid = parseInt(cid||'0',10);
  if(!cid || !LEVELS_BY_CAT[cid] || LEVELS_BY_CAT[cid].length===0){
    levelSel.disabled = true;
    const o=document.createElement('option'); o.value=''; o.textContent='Tidak ada level'; levelSel.appendChild(o);
    return;
  }
  levelSel.disabled=false;
  const first=document.createElement('option'); first.value=''; first.textContent='Pilih Level'; levelSel.appendChild(first);
  LEVELS_BY_CAT[cid].forEach(({id,label})=>{
    const op=document.createElement('option'); op.value=id; op.textContent=label;
    levelSel.appendChild(op);
  });
}
kelasSel?.addEventListener('change', ()=> fillAddLevels(kelasSel.value));

/* Selection mode (checklist) */
const btnStart   = document.getElementById('btnStartSelect');
const btnConfirm = document.getElementById('btnConfirm');
const btnCancel  = document.getElementById('btnCancel');
const checkCols  = document.querySelectorAll('.checkbox-col');
const checkAll   = document.getElementById('checkAll');
const rowChecks  = [...document.querySelectorAll('.user-checkbox')];

function setSelectionMode(on){
  checkCols.forEach(el=> el.style.display = on ? 'table-cell' : 'none');
  btnStart.classList.toggle('d-none', on);
  btnConfirm.classList.toggle('d-none', !on);
  btnCancel.classList.toggle('d-none', !on);
  btnConfirm.disabled = true;
  if(!on){
    checkAll.checked = false;
    rowChecks.forEach(cb => cb.checked = false);
  }
}
btnStart?.addEventListener('click', ()=> setSelectionMode(true));
btnCancel?.addEventListener('click', ()=> setSelectionMode(false));

function updateConfirmState(){
  const any = rowChecks.some(cb => cb.checked);
  btnConfirm.disabled = !any;
}
checkAll?.addEventListener('change', function(){
  rowChecks.forEach(cb => cb.checked = this.checked);
  updateConfirmState();
});
rowChecks.forEach(cb => cb.addEventListener('change', updateConfirmState));

/* Konfirmasi → isi hidden inputs & buka modal */
const bulkModal = new bootstrap.Modal(document.getElementById('bulkModal'));
const bulkUserIdsDiv = document.getElementById('bulkUserIds');
btnConfirm?.addEventListener('click', ()=>{
  const ids = rowChecks.filter(cb=>cb.checked).map(cb=>cb.value);
  if(!ids.length) return;
  bulkUserIdsDiv.innerHTML = '';
  ids.forEach(id=>{
    const i=document.createElement('input');
    i.type='hidden'; i.name='user_ids[]'; i.value=id;
    bulkUserIdsDiv.appendChild(i);
  });
  bulkModal.show();
});

/* Modal bulk: isi level saat pilih kategori */
const bkKelas = document.getElementById('bk_kelas');
const bkLevel = document.getElementById('bk_level');
function fillBulkLevels(cid){
  bkLevel.innerHTML='';
  cid = parseInt(cid||'0',10);
  if(!cid || !LEVELS_BY_CAT[cid] || LEVELS_BY_CAT[cid].length===0){
    bkLevel.disabled=true;
    const o=document.createElement('option'); o.value=''; o.textContent='Tidak ada level'; bkLevel.appendChild(o);
    return;
  }
  bkLevel.disabled=false;
  const first=document.createElement('option'); first.value=''; first.textContent='Pilih Level'; bkLevel.appendChild(first);
  LEVELS_BY_CAT[cid].forEach(({id,label})=>{
    const op=document.createElement('option'); op.value=id; op.textContent=label;
    bkLevel.appendChild(op);
  });
}
bkKelas?.addEventListener('change', ()=> fillBulkLevels(bkKelas.value));
</script>
</body>
</html>
