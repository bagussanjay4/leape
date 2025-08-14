<?php
/* assign_book.php */
session_start();

/* ====== KONFIG DB ====== */
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'book_management_system';

/* ====== KONEK DB ====== */
try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

/* ====== CEK LOGIN ADMIN (opsional) ====== */
if (!isset($_SESSION['admin_logged_in'])) {
    // header('Location: index.php'); exit;
}

/* ====== PARAM KUNCI DARI QUERY ====== */
$categoryIdFromGet = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$levelIdFromGet    = isset($_GET['level_id']) ? (int)$_GET['level_id'] : null;
$lockCategory      = false;
$lockedCategory    = null;

if ($categoryIdFromGet) {
    $stmt = $db->prepare("SELECT id, name FROM categories WHERE id = ?");
    $stmt->execute([$categoryIdFromGet]);
    $lockedCategory = $stmt->fetch();
    if ($lockedCategory) {
        $lockCategory = true;
        $_POST['category_id'] = $lockedCategory['id']; // prefill
        if ($levelIdFromGet) $_POST['book_level_id'] = $levelIdFromGet;
    }
}

/* ====== AMBIL DATA KATEGORI & LEVEL ====== */
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$allBookLevels = [];
foreach ($categories as $c) {
    $stmt = $db->prepare("SELECT * FROM book_levels WHERE category_id = ? ORDER BY level");
    $stmt->execute([$c['id']]);
    $allBookLevels[$c['id']] = $stmt->fetchAll();
}

/* ====== ASSIGN (UPDATE-OR-INSERT) ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_book'])) {
    $bookLevelId = isset($_POST['book_level_id']) ? (int)$_POST['book_level_id'] : 0;
    $studentIds  = isset($_POST['student_ids']) && is_array($_POST['student_ids'])
                 ? array_values(array_unique(array_map('intval', $_POST['student_ids'])))
                 : [];

    try {
        if ($bookLevelId <= 0 || empty($studentIds)) throw new Exception("Pilih level buku dan minimal 1 siswa.");

        $stmt = $db->prepare("SELECT id, category_id FROM book_levels WHERE id = ?");
        $stmt->execute([$bookLevelId]);
        $selectedLevel = $stmt->fetch();
        if (!$selectedLevel) throw new Exception("Level buku tidak ditemukan.");
        $catId = (int)$selectedLevel['category_id'];

        $upd = $db->prepare("
            UPDATE user_books ub
            JOIN book_levels bl ON bl.id = ub.book_level_id
            SET ub.book_level_id = :newLevel
            WHERE ub.user_id = :uid AND bl.category_id = :catId
        ");
        $ins = $db->prepare("INSERT INTO user_books (user_id, book_level_id) VALUES (?, ?)");

        $updated = 0; $inserted = 0;
        $db->beginTransaction();
        foreach ($studentIds as $sid) {
            $upd->execute([':newLevel'=>$bookLevelId, ':uid'=>$sid, ':catId'=>$catId]);
            if ($upd->rowCount() === 0) { $ins->execute([$sid, $bookLevelId]); $inserted++; }
            else { $updated++; }
        }
        $db->commit();

        $_SESSION['success_message'] = "Assign selesai. Diupdate: {$updated}, ditambahkan: {$inserted}.";
        header("Location: assign_book.php?category_id={$catId}&level_id={$bookLevelId}");
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = "Gagal menugaskan buku: " . $e->getMessage();
    }
}

/* ====== HAPUS PENUGASAN ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_assignment'])) {
    $delUserId     = isset($_POST['del_user_id']) ? (int)$_POST['del_user_id'] : 0;
    $delCategoryId = isset($_POST['del_category_id']) ? (int)$_POST['del_category_id'] : 0;

    try {
        if ($delUserId <= 0 || $delCategoryId <= 0) throw new Exception("Data penghapusan tidak lengkap.");
        $stmt = $db->prepare("
            DELETE ub FROM user_books ub
            JOIN book_levels bl ON ub.book_level_id = bl.id
            WHERE ub.user_id = ? AND bl.category_id = ?
        ");
        $stmt->execute([$delUserId, $delCategoryId]);

        $_SESSION['success_message'] = "Penugasan berhasil dihapus.";
        $go = $lockCategory ? "assign_book.php?category_id={$delCategoryId}" : "assign_book.php";
        header("Location: $go"); exit;
    } catch (Exception $e) {
        $error = "Gagal menghapus penugasan: " . $e->getMessage();
    }
}

/* ====== KATEGORI AKTIF & LIST SISWA ====== */
$activeCategoryId   = $_POST['category_id'] ?? ($lockedCategory['id'] ?? null);
$students           = [];
$activeCategoryName = null;

if ($activeCategoryId) {
    $stmt = $db->prepare("SELECT id, name FROM categories WHERE id = ?");
    $stmt->execute([(int)$activeCategoryId]);
    $activeCategory = $stmt->fetch();
    if ($activeCategory) {
        $activeCategoryName = $activeCategory['name'];
        $stmt = $db->prepare("SELECT id, name FROM users WHERE kelas = ? ORDER BY name");
        $stmt->execute([$activeCategoryName]);
        $students = $stmt->fetchAll();
    }
}

/* ====== DATA TABEL (plus level_id untuk tombol Pilih) ====== */
$categoryAssignments = [];
if ($activeCategoryId) {
    $stmt = $db->prepare("
        SELECT 
            u.id   AS user_id,
            u.name,
            bl.level,
            bl.id  AS level_id,
            c.id   AS category_id
        FROM user_books ub
        JOIN users u        ON ub.user_id = u.id
        JOIN book_levels bl ON ub.book_level_id = bl.id
        JOIN categories c   ON bl.category_id = c.id
        WHERE c.id = ?
        ORDER BY u.name
    ");
    $stmt->execute([(int)$activeCategoryId]);
    $categoryAssignments = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Assign Buku</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>
:root{--main:#0b3a6f;--main-2:#0e58a8;--bg:#f4f7fb}
body{background:var(--bg)}
.sidebar{background:var(--main);min-height:100vh;color:#fff}
.sidebar .nav-link{color:#fff}
.sidebar .nav-link.active,.sidebar .nav-link:hover{background:var(--main-2);border-radius:8px}
.card-header{background:var(--main);color:#fff}
.btn-dark{background:var(--main);border-color:var(--main)}
.btn-dark:hover{background:var(--main-2);border-color:var(--main-2)}
.table thead th{background:var(--main);color:#fff}
.badge-wrap{display:flex;flex-wrap:wrap;gap:.4rem}
#studentPicker .list-group-item{border:1px solid #e9ecef;border-radius:.5rem;margin-bottom:.5rem}
.section-top{row-gap:20px}
</style>
</head>
<body>
<div class="container-fluid">
  <div class="row flex-nowrap">
    <!-- Sidebar -->
    <aside class="col-md-3 col-lg-2 sidebar p-3">
      <h4 class="text-center mb-4"><i class="fa-solid fa-graduation-cap me-2"></i>Leap English</h4>
      <ul class="nav flex-column gap-2">
        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fa-solid fa-gauge me-2"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link active" href="manage_books.php"><i class="fa-solid fa-book me-2"></i> Kelola Buku</a></li>
        <li class="nav-item"><a class="nav-link" href="m_users.php"><i class="fa-solid fa-users me-2"></i> Kelola User</a></li>
        <li class="nav-item mt-3">
          <form method="POST" action="dashboard.php"><button type="submit" name="logout" class="btn btn-outline-light w-100"><i class="fa-solid fa-right-from-bracket me-1"></i> Logout</button></form>
        </li>
      </ul>
    </aside>

    <!-- Main -->
    <main class="col-md-9 col-lg-10 p-4">
      <h2 class="mb-4"><i class="fa-solid fa-user-plus me-2"></i> Assign Buku</h2>

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

      <!-- TOP: kiri form, kanan info -->
      <div class="row section-top">
        <!-- FORM -->
        <div class="col-lg-7">
          <div class="card h-100">
            <div class="card-header"><i class="fa-solid fa-user-graduate me-2"></i> Pilih Siswa</div>
            <div class="card-body">
              <form method="POST" id="assignForm" autocomplete="off">
                <div class="mb-3">
                  <label class="form-label">Nama Siswa</label><br>
                  <button type="button" class="btn btn-outline-primary btn-sm" id="openPicker" <?= empty($students)?'disabled':''; ?>>
                    <i class="fa-solid fa-users"></i> Pilih Siswa
                  </button>
                  <div class="mt-2" id="chosenWrapper">
                    <span class="text-muted">Belum ada siswa dipilih.</span>
                  </div>
                  <div id="studentsHidden"></div>
                  <?php if ($activeCategoryId && isset($activeCategoryName)): ?>
                    <small class="text-muted d-block mt-1">Ditampilkan & difilter untuk kelas <strong><?= htmlspecialchars($activeCategoryName) ?></strong></small>
                  <?php endif; ?>
                </div>

                <div class="mb-3">
                  <label class="form-label">Kategori Buku</label>
                  <select class="form-select" id="category_id" name="category_id" <?= $lockCategory ? 'disabled' : '' ?> required>
                    <option value="">Pilih Kategori</option>
                    <?php foreach ($categories as $c): ?>
                      <option value="<?= (int)$c['id'] ?>" <?= ($activeCategoryId && (int)$activeCategoryId === (int)$c['id']) ? 'selected':''; ?>>
                        <?= htmlspecialchars($c['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?php if ($lockCategory && $lockedCategory): ?>
                    <input type="hidden" name="category_id" value="<?= (int)$lockedCategory['id'] ?>">
                    <small class="text-muted">Kategori dikunci dari halaman sebelumnya.</small>
                  <?php endif; ?>
                </div>

                <div class="mb-4">
                  <label class="form-label">Level Buku</label>
                  <select class="form-select" id="book_level_id" name="book_level_id" required <?= !$activeCategoryId ? 'disabled' : '' ?>>
                    <?php if ($activeCategoryId): ?>
                      <?php $levels = $allBookLevels[$activeCategoryId] ?? []; ?>
                      <?php if (empty($levels)): ?>
                        <option value="">Tidak ada level tersedia</option>
                      <?php else: ?>
                        <option value="">Pilih Level</option>
                        <?php foreach ($levels as $lv): ?>
                          <option value="<?= (int)$lv['id'] ?>"
                            <?= (isset($_POST['book_level_id']) && (int)$_POST['book_level_id']===(int)$lv['id']) ? 'selected' : ((isset($levelIdFromGet) && (int)$levelIdFromGet===(int)$lv['id'])?'selected':''); ?>>
                            Level <?= htmlspecialchars($lv['level']) ?>
                          </option>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    <?php else: ?>
                      <option value="">Pilih kategori terlebih dahulu</option>
                    <?php endif; ?>
                  </select>
                </div>

                <button type="submit" name="assign_book" class="btn btn-dark w-100">
                  <i class="fa-solid fa-plus me-1"></i> Assign Buku
                </button>
              </form>
            </div>
          </div>
        </div>

        <!-- INFO -->
        <div class="col-lg-5">
          <div class="card h-100">
            <div class="card-header"><i class="fa-solid fa-circle-info me-2"></i> Informasi</div>
            <div class="card-body">
              <div class="alert mb-0" style="background-color:#ffe5e5; border:1px solid #ff4d4d; color:#b30000; font-weight:bold;">
                <h5 class="mb-2"><i class="fa-solid fa-triangle-exclamation me-2"></i>CATATAN PENTING:</h5>
                <ul class="mb-0">
                  <li>Assign level baru <u>langsung mengganti</u> level lama pada kategori yang sama.</li>
                  <li>Satu siswa hanya <u>1 level</u> per kategori.</li>
                  <li>Gunakan tombol <em>Pilih Siswa</em> untuk memilih dan update level siswa dengan manual.</li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div><!-- /TOP -->

      <!-- BOTTOM: TABEL FULL WIDTH -->
      <div class="row mt-4">
        <div class="col-12">
          <div class="card">
            <div class="card-header d-flex align-items-center">
              <div class="me-auto">
                <i class="fa-solid fa-users me-2"></i> Penugasan di Kategori:
                <span class="badge bg-light text-dark ms-2"><?= $activeCategoryName ? htmlspecialchars($activeCategoryName) : '-' ?></span>
              </div>
              <!-- SEARCH NAMA (hanya menampilkan) -->
              <div class="input-group input-group-sm" style="max-width: 280px;">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="text" id="assignSearchName" class="form-control" placeholder="Cari nama siswa...">
              </div>
            </div>
            <div class="card-body">
              <?php if (empty($categoryAssignments)): ?>
                <div class="alert alert-info mb-0">Belum ada penugasan pada kategori ini.</div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-striped align-middle" id="assignTable">
                    <thead>
                      <tr>
                        <th style="width:60px;">No</th>
                        <th>Nama Siswa</th>
                        <th style="width:140px;">Level</th>
                        <th style="width:220px;" class="text-end">Aksi</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php $no=1; foreach ($categoryAssignments as $r): ?>
                        <tr>
                          <td><?= $no++ ?></td>
                          <td class="cell-name"><?= htmlspecialchars($r['name']) ?></td>
                          <td>Level <?= htmlspecialchars($r['level']) ?></td>
                          <td class="text-end">
                            <div class="btn-group">
                              <!-- TOMBOL PILIH: kirim id, nama, dan level_id -->
                              <button type="button"
                                      class="btn btn-sm btn-outline-secondary pick-one-btn"
                                      title="Pilih siswa ini"
                                      data-user-id="<?= (int)$r['user_id'] ?>"
                                      data-user-name="<?= htmlspecialchars($r['name']) ?>"
                                      data-level-id="<?= (int)$r['level_id'] ?>">
                                <i class="fa-solid fa-user-check"></i> Pilih
                              </button>

                              <form method="POST" class="ms-2"
                                    onsubmit="return confirm('Hapus penugasan siswa ini pada kategori ini?');">
                                <input type="hidden" name="del_user_id" value="<?= (int)$r['user_id'] ?>">
                                <input type="hidden" name="del_category_id" value="<?= (int)$activeCategoryId ?>">
                                <button type="submit" name="delete_assignment" class="btn btn-sm btn-danger">
                                  <i class="fa-solid fa-trash"></i> Hapus
                                </button>
                              </form>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  <div id="assignNoMatch" class="text-center text-muted d-none">Tidak ada data yang cocok.</div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div><!-- /BOTTOM -->
    </main>
  </div>
</div>

<!-- MODAL PILIH SISWA -->
<div class="modal fade" id="studentPicker" tabindex="-1" aria-labelledby="studentPickerLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title" id="studentPickerLabel"><i class="fa-solid fa-users me-2"></i>Pilih Siswa</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="p-3 border-bottom bg-light d-flex align-items-center gap-3 flex-wrap">
          <div class="input-group" style="max-width:420px;">
            <span class="input-group-text bg-white"><i class="fa-solid fa-search"></i></span>
            <input type="text" id="studentSearch" class="form-control" placeholder="Cari siswa (nama)">
          </div>
          <div class="form-check ms-auto">
            <input class="form-check-input" type="checkbox" id="checkAll">
            <label class="form-check-label" for="checkAll">Pilih semua (yang tampil)</label>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm" id="clearSelected">
            <i class="fa-solid fa-eraser me-1"></i> Bersihkan
          </button>
        </div>
        <div class="p-3" style="max-height:50vh; overflow:auto;">
          <div class="list-group" id="studentList">
            <?php foreach ($students as $s): ?>
              <label class="list-group-item d-flex align-items-center">
                <input class="form-check-input me-3 student-item" type="checkbox" value="<?= (int)$s['id'] ?>" style="transform: scale(1.1);">
                <span class="fw-medium"><?= htmlspecialchars($s['name']) ?></span>
              </label>
            <?php endforeach; ?>
            <?php if (empty($students)): ?>
              <div class="alert alert-info mb-0">Tidak ada siswa pada kelas ini.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="modal-footer d-flex align-items-center">
        <div class="me-auto small text-muted" id="selectedCount">0 siswa dipilih</div>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
        <button type="button" class="btn btn-primary" id="applyStudents"><i class="fa-solid fa-check me-1"></i> Terapkan</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  const $  = (s, c=document) => c.querySelector(s);
  const $$ = (s, c=document) => Array.from(c.querySelectorAll(s));

  // made global-safe so it survives refreshes of parts of the DOM
  const selectedMap = window.__assignSelectedMap ?? (window.__assignSelectedMap = {});

  function updateChosenDisplay(map){
    const wrap = $('#chosenWrapper');
    const hid  = $('#studentsHidden');
    wrap.innerHTML = ''; hid.innerHTML = '';
    const ids = Object.keys(map);
    if (!ids.length){ wrap.innerHTML = '<span class="text-muted">Belum ada siswa dipilih.</span>'; return; }
    wrap.classList.add('badge-wrap');
    ids.forEach(id=>{
      const b = document.createElement('span');
      b.className = 'badge text-bg-secondary'; b.textContent = map[id];
      wrap.appendChild(b);
      const hi = document.createElement('input');
      hi.type='hidden'; hi.name='student_ids[]'; hi.value=id;
      hid.appendChild(hi);
    });
  }
  function updateCount(){ const el=$('#selectedCount'); if (el) el.textContent = `${Object.keys(selectedMap).length} siswa dipilih`; }
  function syncCheckAll(){
    const visible = $$('.student-item').filter(cb => cb.closest('.list-group-item').style.display !== 'none');
    const ca = $('#checkAll'); if (!ca) return;
    ca.checked = (visible.length>0 && visible.every(cb=>cb.checked));
  }

  const pickerModal = new bootstrap.Modal(document.getElementById('studentPicker'));

  $('#openPicker')?.addEventListener('click', ()=>{
    $$('.student-item').forEach(cb=> cb.checked = !!selectedMap[cb.value]);
    updateCount(); syncCheckAll(); pickerModal.show();
  });

  $('#studentSearch')?.addEventListener('input', function(){
    const q = this.value.toLowerCase().trim();
    $$('#studentList .list-group-item').forEach(li=>{
      const t = li.textContent.toLowerCase();
      li.style.display = t.includes(q) ? '' : 'none';
    });
    syncCheckAll();
  });

  $('#checkAll')?.addEventListener('change', function(){
    const visible = $$('.student-item').filter(cb => cb.closest('.list-group-item').style.display !== 'none');
    visible.forEach(cb=>{
      cb.checked = this.checked;
      const id = cb.value, name = cb.parentElement.querySelector('span').textContent.trim();
      if (cb.checked) selectedMap[id]=name; else delete selectedMap[id];
    });
    updateCount();
  });

  $$('.student-item').forEach(cb=>{
    cb.addEventListener('change', function(){
      const id = this.value, name = this.parentElement.querySelector('span').textContent.trim();
      if (this.checked) selectedMap[id]=name; else delete selectedMap[id];
      updateCount(); syncCheckAll();
    });
  });

  $('#clearSelected')?.addEventListener('click', ()=>{
    for (const k in selectedMap) delete selectedMap[k];
    $$('.student-item').forEach(cb=> cb.checked=false);
    updateCount(); syncCheckAll();
  });

  $('#applyStudents')?.addEventListener('click', ()=>{
    updateChosenDisplay(selectedMap);
    pickerModal.hide();
  });

  // ====== Ganti kategori -> soft refresh via POST ======
  const formAssign = $('#assignForm');
  $('#category_id')?.addEventListener('change', function(){
    if (!formAssign) return;
    const ghost = document.createElement('input');
    ghost.type='hidden'; ghost.name='just_refresh'; ghost.value='1';
    formAssign.appendChild(ghost);
    formAssign.method='POST';
    formAssign.action='assign_book.php';
    formAssign.submit();
  });

  // ====== DELEGASI: klik tombol "Pilih" di tabel ======
  document.addEventListener('click', function(e){
    const btn = e.target.closest('.pick-one-btn');
    if (!btn) return;

    const uid     = btn.getAttribute('data-user-id');
    const uname   = btn.getAttribute('data-user-name') || 'Siswa';
    const levelId = btn.getAttribute('data-level-id');

    // reset & set 1 siswa
    Object.keys(selectedMap).forEach(k => delete selectedMap[k]);
    selectedMap[uid] = uname;
    updateChosenDisplay(selectedMap);

    // set dropdown level bila tersedia
    const levelSel = document.getElementById('book_level_id');
    if (levelSel && levelId) levelSel.value = levelId;

    // tampilkan jumlah (kalau modal terbuka)
    updateCount();

    // scroll ke form
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  // ====== Search Nama pada tabel penugasan (client-side) ======
  const searchBox = document.getElementById('assignSearchName');
  const tableBody = document.querySelector('#assignTable tbody');
  const noMatchEl = document.getElementById('assignNoMatch');

  function filterAssignTable(q){
    if (!tableBody) return;
    const rows = Array.from(tableBody.querySelectorAll('tr'));
    let shown = 0;
    rows.forEach(tr => {
      const nameCell = tr.querySelector('.cell-name');
      const nameText = (nameCell?.textContent || '').toLowerCase();
      const ok = nameText.includes(q);
      tr.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });
    if (noMatchEl){
      noMatchEl.classList.toggle('d-none', shown !== 0);
    }
  }

  searchBox?.addEventListener('input', function(){
    filterAssignTable(this.value.toLowerCase().trim());
  });

})();
</script>
</body>
</html>
