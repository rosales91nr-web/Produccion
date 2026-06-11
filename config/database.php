<?php
$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'produccion_quiebras';

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// ✅ Establecer zona horaria para MySQL (hora de Costa Rica / UTC-6)
$conn->query("SET time_zone = '-06:00'");
?>
