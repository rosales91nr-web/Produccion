<?php
/**
 * registrar_actividad.php
 * Registra actividad de usuarios en la tabla actividad_monitor
 * Si la tabla no existe, falla silenciosamente para no interrumpir el flujo.
 */

if (!function_exists('registrar_actividad')) {
    function registrar_actividad($conn, string $tipo, string $usuario, string $descripcion, string $ip = ''): bool {
        if (!$conn || $conn->connect_error) return false;

        // Intentar crear la tabla si no existe
        $conn->query("
            CREATE TABLE IF NOT EXISTS actividad_monitor (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                tipo        VARCHAR(50)   NOT NULL DEFAULT '',
                usuario     VARCHAR(150)  NOT NULL DEFAULT '',
                descripcion TEXT,
                ip          VARCHAR(45)   DEFAULT '',
                fecha       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $stmt = $conn->prepare(
            "INSERT INTO actividad_monitor (tipo, usuario, descripcion, ip, fecha) VALUES (?, ?, ?, ?, NOW())"
        );

        if (!$stmt) return false;

        $stmt->bind_param('ssss', $tipo, $usuario, $descripcion, $ip);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}
