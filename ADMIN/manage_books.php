<?php
session_start();
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'book_management_system';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

/* ================== HANDLER: TAMBAH/EDIT/HAPUS KATEGORI ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['name']);
    try {
        $stmt = $db->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([$name]);
        $_SESSION['success_message'] = "Kategori berhasil ditambahkan!";
        header('Location: manage_books.php');
        exit;
    } catch (PDOException $e) {
        $error = "Gagal menambahkan kategori: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_category'])) {
    $catId = (int)$_POST['category_id'];
    $name  = trim($_POST['name']);
    try {
        $stmt = $db->prepare("UPDATE categories SET name = ? WHERE id = ?");
        $stmt->execute([$name, $catId]);
        $_SESSION['success_message'] = "Kategori berhasil diperbarui.";
        header("Location: manage_books.php?category_id=".$catId);
        exit;
    } catch (PDOException $e) {
        $error = "Gagal memperbarui kategori: " . $e->getMessage();
    }
}

if (isset($_GET['delete_category'])) {
    $catId = (int)$_GET['delete_category'];
    try {
        $db->beginTransaction();

        // ambil semua level & file PDF untuk kategori ini
        $stmt = $db->prepare("SELECT id, pdf_path FROM book_levels WHERE category_id = ?");
        $stmt->execute([$catId]);
        $levels = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($levels)) {
            $levelIds = array_column($levels, 'id');
            $in       = implode(',', array_fill(0, count($levelIds), '?'));

            // hapus penugasan user_books yang terkait level-level ini
            $delUB = $db->prepare("DELETE FROM user_books WHERE book_level_id IN ($in)");
            $delUB->execute($levelIds);

            // hapus file PDF lama (kalau ada)
            foreach ($levels as $lv) {
                if (!empty($lv['pdf_path']) && file_exists($lv['pdf_path'])) {
                    @unlink($lv['pdf_path']);
                }
            }

            // hapus book_levels
            $delBL = $db->prepare("DELETE FROM book_levels WHERE id IN ($in)");
            $delBL->execute($levelIds);
        }

        // hapus kategori
        $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$catId]);

        $db->commit();
        $_SESSION['success_message'] = "Kategori berhasil dihapus.";
        header("Location: manage_books.php");
        exit;
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = "Gagal menghapus kategori: " . $e->getMessage();
    }
}

/* ================== DATA DASAR UNTUK TAMPILAN ================== */
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$currentCategoryId = $_GET['category_id'] ?? ($categories[0]['id'] ?? null);

if ($currentCategoryId) {
    $stmt = $db->prepare("SELECT * FROM book_levels WHERE category_id = ? ORDER BY level");
    $stmt->execute([$currentCategoryId]);
    $bookLevels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $bookLevels = [];
}

/* ================== TAMBAH/EDIT/HAPUS LEVEL ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_book_level'])) {
    $categoryId = (int)$_POST['category_id'];
    $level = (int)$_POST['level'];
    try {
        $pdfPath = null;
        if (!empty($_FILES['pdf_file']['name']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
            $fileName = uniqid() . '_' . basename($_FILES['pdf_file']['name']);
            $targetPath = $uploadDir . $fileName;
            $fileType = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
            if ($fileType !== 'pdf') throw new Exception('Hanya file PDF yang diizinkan.');
            if ($_FILES['pdf_file']['size'] > 5 * 1024 * 1024) throw new Exception('Ukuran file maksimal 5MB.');
            if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $targetPath)) throw new Exception('Gagal mengunggah file.');
            $pdfPath = $targetPath;
        }

        $stmt = $db->prepare("INSERT INTO book_levels (category_id, level, pdf_path) VALUES (?, ?, ?)");
        $stmt->execute([$categoryId, $level, $pdfPath]);
        $_SESSION['success_message'] = "Level buku berhasil ditambahkan!";
        header("Location: manage_books.php?category_id=$categoryId");
        exit;
    } catch (Exception $e) {
        $error = "Gagal menambahkan level buku: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_book_level'])) {
    $levelId    = (int)$_POST['level_id'];
    $categoryId = (int)$_POST['category_id'];
    $newLevel   = (int)($_POST['level'] ?? 0);

    try {
        $stmt = $db->prepare("SELECT pdf_path FROM book_levels WHERE id = ?");
        $stmt->execute([$levelId]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new Exception('Level tidak ditemukan.');

        $newPdfPath = $old['pdf_path'];

        if (!empty($_FILES['edit_pdf']['name']) && $_FILES['edit_pdf']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
            $fileName = uniqid() . '_' . basename($_FILES['edit_pdf']['name']);
            $targetPath = $uploadDir . $fileName;
            $fileType = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
            if ($fileType !== 'pdf') throw new Exception('Hanya file PDF yang diizinkan.');
            if ($_FILES['edit_pdf']['size'] > 5 * 1024 * 1024) throw new Exception('Ukuran file maksimal 5MB.');
            if (!move_uploaded_file($_FILES['edit_pdf']['tmp_name'], $targetPath)) throw new Exception('Gagal mengunggah file.');
            if ($old['pdf_path'] && file_exists($old['pdf_path'])) @unlink($old['pdf_path']);
            $newPdfPath = $targetPath;
        }

        $stmt = $db->prepare("UPDATE book_levels SET level = ?, pdf_path = ? WHERE id = ?");
        $stmt->execute([$newLevel, $newPdfPath, $levelId]);

        $_SESSION['success_message'] = "Level berhasil diperbarui.";
        header("Location: manage_books.php?category_id=$categoryId");
        exit;
    } catch (Exception $e) {
        $error = "Gagal memperbarui level: " . $e->getMessage();
    }
}

if (isset($_GET['delete_level'])) {
    $levelId   = (int)$_GET['delete_level'];
    $categoryId = (int)$_GET['category_id'];
    try {
        $stmt = $db->prepare("DELETE FROM user_books WHERE book_level_id = ?");
        $stmt->execute([$levelId]);

        $stmt = $db->prepare("SELECT pdf_path FROM book_levels WHERE id = ?");
        $stmt->execute([$levelId]);
        $bookLevel = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($bookLevel && $bookLevel['pdf_path'] && file_exists($bookLevel['pdf_path'])) {
            @unlink($bookLevel['pdf_path']);
        }
        $stmt = $db->prepare("DELETE FROM book_levels WHERE id = ?");
        $stmt->execute([$levelId]);

        $_SESSION['success_message'] = "Level buku berhasil dihapus!";
        header("Location: manage_books.php?category_id=$categoryId");
        exit;
    } catch (PDOException $e) {
        $error = "Gagal menghapus level buku: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Kelola Buku</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
<style>
:root{--main-blue:#003366;--hover-blue:#00509e}
body{background:#f4f6f9;font-family:'Segoe UI',sans-serif}
.sidebar{background:var(--main-blue);min-height:100vh;color:#fff}
.sidebar .nav-link{color:#fff}
.sidebar .nav-link:hover,.sidebar .nav-link.active{background:var(--hover-blue);border-radius:6px}
.card-header{background:var(--main-blue);color:#fff}
.btn-dark{background:var(--main-blue)}.btn-dark:hover{background:var(--hover-blue)}
.table th{background:var(--main-blue);color:#fff}
.card{transition:.3s} .card:hover{box-shadow:0 .5rem 1rem rgba(0,0,0,.05);transform:translateY(-3px)}
.cat-actions .btn{padding:.15rem .45rem}
</style>
</head>
<body>
<div class="container-fluid">
  <div class="row flex-nowrap">
    <!-- Sidebar -->
    <nav class="col-md-3 col-lg-2 sidebar py-4 px-3 animate__animated animate__fadeInLeft">
      <h4 class="text-center mb-4"><i class="fas fa-graduation-cap"></i> Leap English</h4>
      <ul class="nav flex-column gap-2">
        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link active" href="manage_books.php"><i class="fas fa-book me-2"></i> Kelola Buku</a></li>
        <li class="nav-item"><a class="nav-link" href="m_users.php"><i class="fas fa-users me-2"></i> Kelola User</a></li>
        <li class="nav-item mt-3">
          <form method="POST" action="dashboard.php">
            <button type="submit" name="logout" class="btn btn-outline-light w-100"><i class="fas fa-sign-out-alt me-1"></i> Logout</button>
          </form>
        </li>
      </ul>
    </nav>

    <!-- Main -->
    <main class="col-md-9 col-lg-10 px-md-5 py-4 animate__animated animate__fadeInRight">
      <h2 class="mb-4"><i class="fas fa-book me-2"></i> Kelola Buku</h2>

      <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
      <?php endif; ?>
      <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="row">
        <!-- Kategori -->
        <div class="col-md-4">
          <div class="card mb-4 animate__animated animate__fadeInUp">
            <div class="card-header"><i class="fas fa-tags me-2"></i> Kategori Buku</div>
            <div class="card-body">
              <ul class="list-group mb-3">
                <?php foreach ($categories as $category): ?>
                  <li class="list-group-item d-flex align-items-center justify-content-between <?= $category['id'] == $currentCategoryId ? 'active' : '' ?>">
                    <a href="?category_id=<?= (int)$category['id'] ?>" class="<?= $category['id'] == $currentCategoryId ? 'text-white' : '' ?>" style="text-decoration:none;">
                      <?= htmlspecialchars($category['name']) ?>
                    </a>
                    <span class="cat-actions ms-2">
                      <!-- Edit kategori -->
                      <button type="button"
                              class="btn btn-sm btn-primary"
                              title="Edit"
                              data-bs-toggle="modal"
                              data-bs-target="#editCategoryModal"
                              data-id="<?= (int)$category['id'] ?>"
                              data-name="<?= htmlspecialchars($category['name']) ?>">
                        <i class="fas fa-pen"></i>
                      </button>
                      <!-- Hapus kategori -->
                      <a class="btn btn-sm btn-danger"
                         title="Hapus"
                         href="?delete_category=<?= (int)$category['id'] ?>"
                         onclick="return confirm('Hapus kategori ini beserta semua level & penugasannya?')">
                        <i class="fas fa-trash"></i>
                      </a>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>

              <form method="POST">
                <div class="mb-2">
                  <input type="text" name="name" class="form-control" placeholder="Kategori baru..." required>
                </div>
                <button type="submit" name="add_category" class="btn btn-dark w-100">
                  <i class="fas fa-plus me-1"></i> Tambah Kategori
                </button>
              </form>
            </div>
          </div>
        </div>

        <!-- Level Buku -->
        <div class="col-md-8">
          <div class="card animate__animated animate__fadeInUp animate__delay-1s">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span><i class="fas fa-layer-group me-2"></i> Level Buku
                <?= $currentCategoryId ? ' - ' . htmlspecialchars(array_column($categories, 'name', 'id')[$currentCategoryId]) : '' ?>
              </span>
              <?php if ($currentCategoryId): ?>
                <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#addLevelModal">
                  <i class="fas fa-plus"></i> Tambah Level
                </button>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <?php if ($currentCategoryId): ?>
                <?php if (empty($bookLevels)): ?>
                  <div class="alert alert-info">Belum ada level buku untuk kategori ini.</div>
                <?php else: ?>
                  <table class="table table-hover table-bordered">
                    <thead>
                      <tr><th>Level</th><th>PDF</th><th>Aksi</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bookLevels as $level): ?>
                      <tr>
                        <td><?= htmlspecialchars($level['level']) ?></td>
                        <td>
                          <?php if ($level['pdf_path']): ?>
                            <a href="<?= htmlspecialchars($level['pdf_path']) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                              <i class="fas fa-file-pdf"></i> Lihat
                            </a>
                          <?php else: ?>
                            <span class="text-muted">Tidak ada file</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <button type="button"
                                  class="btn btn-sm btn-primary me-1 edit-level-btn"
                                  title="Edit Level"
                                  data-bs-toggle="modal"
                                  data-bs-target="#editLevelModal"
                                  data-id="<?= (int)$level['id'] ?>"
                                  data-level="<?= (int)$level['level'] ?>"
                                  data-category="<?= (int)$currentCategoryId ?>"
                                  data-pdf="<?= htmlspecialchars($level['pdf_path'] ?? '') ?>">
                            <i class="fas fa-pen"></i>
                          </button>
                          <a href="assign_book.php?category_id=<?= (int)$currentCategoryId ?>&level_id=<?= (int)$level['id'] ?>"
                             class="btn btn-sm btn-success me-1" title="Assign ke Siswa">
                            <i class="fas fa-user-plus"></i>
                          </a>
                          <a href="?delete_level=<?= (int)$level['id'] ?>&category_id=<?= (int)$currentCategoryId ?>"
                             class="btn btn-sm btn-danger"
                             onclick="return confirm('Yakin ingin menghapus level ini?')">
                            <i class="fas fa-trash"></i>
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              <?php else: ?>
                <div class="alert alert-warning">Silakan pilih kategori terlebih dahulu.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- Modal Tambah Level -->
<div class="modal fade" id="addLevelModal" tabindex="-1" aria-labelledby="addLevelModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="category_id" value="<?= (int)$currentCategoryId ?>">
        <div class="modal-header bg-dark text-white">
          <h5 class="modal-title" id="addLevelModalLabel"><i class="fas fa-plus me-1"></i> Tambah Level Buku</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="level" class="form-label">Level</label>
            <input type="number" name="level" id="level" class="form-control" required>
          </div>
          <div class="mb-3">
            <label for="pdf_file" class="form-label">Upload PDF</label>
            <input type="file" name="pdf_file" id="pdf_file" class="form-control" accept="application/pdf" required>
            <small class="text-muted">Maksimal ukuran file 5MB.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" name="add_book_level" class="btn btn-dark">Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Edit Level -->
<div class="modal fade" id="editLevelModal" tabindex="-1" aria-labelledby="editLevelModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="update_book_level" value="1">
        <input type="hidden" name="level_id" id="edit_level_id">
        <input type="hidden" name="category_id" id="edit_category_id">
        <div class="modal-header bg-dark text-white">
          <h5 class="modal-title" id="editLevelModalLabel"><i class="fas fa-pen me-1"></i> Edit Level Buku</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Level</label>
            <input type="number" name="level" id="edit_level_value" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">PDF Saat Ini</label>
            <div id="current_pdf_wrap" class="small"></div>
          </div>
          <div class="mb-0">
            <label class="form-label">Ganti PDF (opsional)</label>
            <input type="file" name="edit_pdf" class="form-control" accept="application/pdf">
            <small class="text-muted">Biarkan kosong jika tidak ingin mengubah file.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-dark">Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Edit Kategori -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="update_category" value="1">
        <input type="hidden" name="category_id" id="edit_cat_id">
        <div class="modal-header bg-dark text-white">
          <h5 class="modal-title" id="editCategoryModalLabel"><i class="fas fa-pen me-1"></i> Edit Kategori</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
        <div class="modal-body">
          <label class="form-label">Nama Kategori</label>
          <input type="text" name="name" id="edit_cat_name" class="form-control" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-dark">Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// isi modal edit LEVEL
document.querySelectorAll('.edit-level-btn').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id  = btn.getAttribute('data-id');
    const lv  = btn.getAttribute('data-level');
    const cat = btn.getAttribute('data-category');
    const pdf = btn.getAttribute('data-pdf') || '';

    document.getElementById('edit_level_id').value   = id;
    document.getElementById('edit_category_id').value= cat;
    document.getElementById('edit_level_value').value= lv;

    const wrap = document.getElementById('current_pdf_wrap');
    wrap.innerHTML = pdf
      ? `<a class="btn btn-outline-primary btn-sm" target="_blank" href="${pdf}">
           <i class="fas fa-file-pdf"></i> Lihat PDF
         </a>`
      : 'Tidak ada file.';
  });
});

// isi modal edit KATEGORI
document.querySelectorAll('[data-bs-target="#editCategoryModal"]').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    document.getElementById('edit_cat_id').value   = btn.getAttribute('data-id');
    document.getElementById('edit_cat_name').value = btn.getAttribute('data-name') || '';
  });
});
</script>
</body>
</html>
