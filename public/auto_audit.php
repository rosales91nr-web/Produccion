<?php
/**
 * auto_audit.php
 * Auditoría automática de sesión y actividad de administradores.
 * Registra accesos y detecta sesiones inactivas.
 */

if (!defined('AUDIT_LOADED')) {
    define('AUDIT_LOADED', true);

    // Registrar timestamp de último acceso para auditoría
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['audit_last_page'] = $_SERVER['PHP_SELF'] ?? '';
        $_SESSION['audit_last_time'] = time();
    }

    if (!function_exists('audit_log')) {
        function audit_log($conn, string $accion, string $detalle = ''): void {
            if (!$conn || $conn->connect_error) return;

            $conn->query("
                CREATE TABLE IF NOT EXISTS audit_log (
                    id       INT AUTO_INCREMENT PRIMARY KEY,
                    usuario  VARCHAR(150) DEFAULT '',
                    accion   VARCHAR(100) NOT NULL,
                    detalle  TEXT,
                    ip       VARCHAR(45)  DEFAULT '',
                    fecha    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $usuario = $_SESSION['empleado'] ?? 'desconocido';
            $ip      = $_SERVER['REMOTE_ADDR'] ?? '';

            $stmt = $conn->prepare(
                "INSERT INTO audit_log (usuario, accion, detalle, ip, fecha) VALUES (?, ?, ?, ?, NOW())"
            );
            if (!$stmt) return;
            $stmt->bind_param('ssss', $usuario, $accion, $detalle, $ip);
            $stmt->execute();
            $stmt->close();
        }
    }
}
