<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Lupa Password - NutriBy</title>
    <link href="./assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-red-900 min-h-screen flex items-center justify-center p-4 text-white">
    <div class="w-full max-w-sm mx-auto text-center">
        <h1 class="text-3xl font-bold mb-2">Lupa Password</h1>
        <p class="text-white/80 mb-8">Masukkan email Anda untuk menerima link reset password.</p>
        
        <?php if(isset($_GET['status'])): ?>
            <div class="bg-green-500 p-3 rounded-md mb-4">Link reset password telah dikirim ke email Anda.</div>
        <?php endif; ?>
        
        <form action="send-reset-link.php" method="POST" class="space-y-6">
            <input type="email" name="email" placeholder="Email Terdaftar" class="bg-transparent border-b-2 border-white w-full text-white placeholder-white focus:outline-none py-2" required>
            <button type="submit" class="bg-white text-red-900 font-semibold py-3 px-6 rounded-full w-full shadow-md transition hover:bg-gray-200">Kirim Link</button>
            <a href="login.php" class="block text-white/80 hover:underline mt-4">Kembali ke Login</a>
        </form>
    </div>
</body>
</html>