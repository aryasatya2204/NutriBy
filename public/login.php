<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Jika sudah login, arahkan ke main menu
if (isset($_SESSION['user_id'])) {
    header('Location: main-menu.php');
    exit();
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error_message = 'Email dan password wajib diisi.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Login sukses
            session_regenerate_id(true); // Mencegah session fixation
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            
            // Ambil anak pertama sebagai anak aktif (default)
            $stmt_child = $pdo->prepare("SELECT id FROM children WHERE user_id = ? ORDER BY created_at ASC LIMIT 1");
            $stmt_child->execute([$user['id']]);
            $child = $stmt_child->fetch();
            if ($child) {
                $_SESSION['active_child_id'] = $child['id'];
            }

            header('Location: main-menu.php');
            exit();
        } else {
            $error_message = 'Email atau password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Log In - NutriBy</title>
    <link href="./assets/styles/output.css" rel="stylesheet">
     <style>
        /* Targetkan input yang di-autofill oleh browser WebKit (Chrome, Safari, Edge) */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        input:-webkit-autofill:active {         
            -webkit-box-shadow: 0 0 0 30px #7f1d1d inset !important;
            -webkit-text-fill-color: white !important;
        }
    </style>
</head>
<body class="bg-red-900 min-h-screen flex items-center justify-center p-4 text-white">
    <div class="w-full max-w-sm mx-auto text-center">
        <h1 class="text-4xl font-bold mb-8">Login ke NutriBy</h1>
        
        <?php if ($error_message): ?>
            <div class="bg-red-500 p-3 rounded-md mb-4"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST" class="space-y-6">
            <input type="email" name="email" placeholder="Email" class="bg-transparent border-b-2 border-white w-full text-white placeholder-white focus:outline-none py-2" required>
            <input type="password" name="password" placeholder="Password" class="bg-transparent border-b-2 border-white w-full text-white placeholder-white focus:outline-none py-2" required>
            <div class="text-right">
                <a href="forgot-password.php" class="text-sm text-white/80 hover:underline">Lupa Password?</a>
            </div>
            <button type="submit" class="bg-white text-red-900 font-semibold py-3 px-6 rounded-full w-full shadow-md transition hover:bg-gray-200">Log In</button>
            <a href="index.php" class="block text-white/80 hover:underline mt-4">Kembali ke Halaman Utama</a>
        </form>
    </div>
</body>
</html>