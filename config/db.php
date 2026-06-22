<?php
function koneksiDB() {
    // Railway/Render akan membaca variabel yang diatur di sistem mereka
    // Jika tidak ditemukan (saat di laptop sendiri), maka akan menggunakan nilai default
    $host     = getenv('DB_HOST') ?: 'localhost';
    $port     = getenv('DB_PORT') ?: '5432';
    $dbname   = getenv('DB_NAME') ?: 'project_dwh';
    $user     = getenv('DB_USER') ?: 'postgres';
    $password = getenv('DB_PASS') ?: 'root';

    try {
        // Menggunakan variabel di atas
        $conn = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch (PDOException $e) {
        die("Koneksi Database Gagal: " . $e->getMessage());
    }
}
?>