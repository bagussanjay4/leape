<?php
session_start();

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'book_management_system';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$users = $db->query("SELECT * FROM users ORDER BY email")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Admin - Leap English</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Bootstrap + FontAwesome + Animate.css -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <style>
        :root {
            --main-blue: #003366;
            --hover-blue: #00509e;
        }

        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', sans-serif;
        }

        .sidebar {
            background-color: var(--main-blue);
            min-height: 100vh;
            color: white;
            transition: all 0.3s ease-in-out;
        }

        .sidebar .nav-link {
            color: white;
            transition: background 0.3s ease;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background-color: var(--hover-blue);
            border-radius: 8px;
        }

        .card-header {
            background-color: var(--main-blue);
            color: white;
        }

        .btn-dark {
            background-color: var(--main-blue);
            transition: background-color 0.3s ease;
        }

        .btn-dark:hover {
            background-color: var(--hover-blue);
        }

        .table th {
            background-color: var(--main-blue);
            color: white;
        }

        .card {
            transition: transform 0.2s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1);
        }

        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                padding-bottom: 20px;
            }
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row flex-nowrap">
        <!-- Sidebar -->
        <nav class="col-md-3 col-lg-2 sidebar py-4 px-3 animate__animated animate__fadeInLeft">
            <h4 class="text-center mb-4"><i class="fas fa-graduation-cap"></i> Leap English</h4>
            <ul class="nav flex-column gap-2">
                <li class="nav-item">
                    <a class="nav-link active" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="manage_books.php"><i class="fas fa-book me-2"></i> Kelola Buku</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="m_users.php"><i class="fas fa-users me-2"></i> Kelola User</a>
                </li>
                <li class="nav-item mt-3">
                    <form method="POST">
                        <button type="submit" name="logout" class="btn btn-outline-light w-100">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </form>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="col-md-9 col-lg-10 px-md-5 py-4 animate__animated animate__fadeInRight">
            <h2 class="mb-4"><i class="fas fa-tachometer-alt me-2"></i> Dashboard Admin</h2>
            <p class="text-muted">Selamat datang, <?= htmlspecialchars($_SESSION['admin_email']) ?></p>

            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card border-start border-4 border-primary shadow-sm animate__animated animate__fadeInUp">
                        <div class="card-body">
                            <h5 class="card-title text-muted">Total Kategori <i class="fas fa-tags float-end"></i></h5>
                            <h3><?= $db->query("SELECT COUNT(*) FROM categories")->fetchColumn(); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-start border-4 border-info shadow-sm animate__animated animate__fadeInUp animate__delay-1s">
                        <div class="card-body">
                            <h5 class="card-title text-muted">Total Level Buku <i class="fas fa-book-open float-end"></i></h5>
                            <h3><?= $db->query("SELECT COUNT(*) FROM book_levels")->fetchColumn(); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-start border-4 border-success shadow-sm animate__animated animate__fadeInUp animate__delay-2s">
                        <div class="card-body">
                            <h5 class="card-title text-muted">Total Pengguna <i class="fas fa-users float-end"></i></h5>
                            <h3><?= $db->query("SELECT COUNT(*) FROM users")->fetchColumn(); ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Kategori Buku -->
            <div class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header"><i class="fas fa-book me-2"></i> Kategori Buku Terbaru</div>
                <div class="card-body table-responsive">
                    <table class="table table-hover table-bordered">
                        <thead>
                            <tr><th>Nama Kategori</th><th>Jumlah Level</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($categories, 0, 5) as $category): ?>
                            <?php
                            $stmt = $db->prepare("SELECT COUNT(*) FROM book_levels WHERE category_id = ?");
                            $stmt->execute([$category['id']]);
                            $levelCount = $stmt->fetchColumn();
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($category['name']) ?></td>
                                <td><?= $levelCount ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <a href="manage_books.php" class="btn btn-dark mt-2">Lihat Semua</a>
                </div>
            </div>

            <!-- Pengguna Baru -->
            <div class="card animate__animated animate__fadeIn">
                <div class="card-header"><i class="fas fa-users me-2"></i> Pengguna Terbaru</div>
                <div class="card-body table-responsive">
                    <table class="table table-hover table-bordered">
                        <thead>
                            <tr><th>Email</th><th>Buku Ditugaskan</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($users, 0, 5) as $user): ?>
                            <?php
                            $stmt = $db->prepare("SELECT COUNT(*) FROM user_books WHERE user_id = ?");
                            $stmt->execute([$user['id']]);
                            $bookCount = $stmt->fetchColumn();
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td><?= $bookCount ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <a href="m_users.php" class="btn btn-dark mt-2">Lihat Semua</a>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
