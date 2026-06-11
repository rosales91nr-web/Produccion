<?php
/**
 * api_monitor.php
 * JSON API para dashboard_monitor.php
 * Retorna sesiones activas, actividad reciente, estadísticas del servidor y de la BD
 */

declare(strict_types=1);
session_start();

if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

require_once '../config/database.php';
date_default_timezone_set('America/Guatemala');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');

$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d');
$fecha_fin    = $_GET['fecha_fin']    ?? date('Y-m-d');

// Sanitize dates
$fecha_inicio = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_inicio) ? $fecha_inicio : date('Y-m-d');
$fecha_fin    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin)    ? $fecha_fin    : date('Y-m-d');

$resp = [
    'success'          => true,
    'total_conectados' => 0,
    'admins_activos'   => 0,
    'empleados_activos'=> 0,
    'total_empleados'  => 0,
    'sesiones'         => [],
    'actividad'        => [],
    'alertas'          => [],
    'warnings'         => [],
    'db_size_mb'       => 0,
    'ultimo_insert_db' => '—',
    'server_info'      => ['load' => 0],
    'activos_hoy'      => 0,
];

try {
    // ── Total empleados ──────────────────────────────────────
    $r = $conn->query("SELECT COUNT(*) AS total FROM empleados");
    if ($r) $resp['total_empleados'] = (int)$r->fetch_assoc()['total'];

    // ── Sesiones activas (logins últimas 3 horas en actividad_monitor) ──
    $conn->query("
        CREATE TABLE IF NOT EXISTS actividad_monitor (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            tipo        VARCHAR(50)  NOT NULL DEFAULT '',
            usuario     VARCHAR(150) NOT NULL DEFAULT '',
            descripcion TEXT,
            ip          VARCHAR(45)  DEFAULT '',
            fecha       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fecha (fecha),
            INDEX idx_tipo  (tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $tres_horas = date('Y-m-d H:i:s', strtotime('-3 hours'));
    $stmt = $conn->prepare("
        SELECT am.usuario, am.ip, am.descripcion, am.fecha,
               e.rol, e.codigo_empleado
        FROM actividad_monitor am
        LEFT JOIN empleados e ON e.nombre_empleado = am.usuario
        WHERE am.tipo = 'login' AND am.fecha >= ?
        ORDER BY am.fecha DESC
    ");
    $stmt->bind_param('s', $tres_horas);
    $stmt->execute();
    $rs = $stmt->get_result();

    $sesiones_vistas = [];
    $sesiones = [];
    while ($row = $rs->fetch_assoc()) {
        if (isset($sesiones_vistas[$row['usuario']])) continue;
        $sesiones_vistas[$row['usuario']] = true;

        $mins_inactivo = (int)round((time() - strtotime($row['fecha'])) / 60);
        $rol = $row['rol'] ?? 'empleado';

        $sesiones[] = [
            'nombre'         => $row['usuario'],
            'codigo'         => $row['codigo_empleado'] ?? '—',
            'rol'            => $rol,
            'modulo_actual'  => extraerModulo($row['descripcion'] ?? ''),
            'ip'             => $row['ip'] ?? '',
            'tiempo_inactivo'=> $mins_inactivo,
        ];

        if ($rol === 'administrador') $resp['admins_activos']++;
        else $resp['empleados_activos']++;
    }
    $stmt->close();

    $resp['sesiones']         = $sesiones;
    $resp['total_conectados'] = count($sesiones);
    $resp['activos_hoy']      = count($sesiones);

    // ── Actividad reciente filtrada por fecha ────────────────
    $stmt2 = $conn->prepare("
        SELECT tipo, usuario, descripcion AS detalle, ip,
               DATE_FORMAT(fecha,'%d/%m/%Y %H:%i:%s') AS fecha_hora
        FROM actividad_monitor
        WHERE DATE(fecha) BETWEEN ? AND ?
        ORDER BY fecha DESC
        LIMIT 100
    ");
    $stmt2->bind_param('ss', $fecha_inicio, $fecha_fin);
    $stmt2->execute();
    $rs2 = $stmt2->get_result();
    while ($row = $rs2->fetch_assoc()) {
        $resp['actividad'][] = $row;
    }
    $stmt2->close();

    // ── Tamaño de la BD ──────────────────────────────────────
    $stmt3 = $conn->prepare("
        SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
        FROM information_schema.TABLES
        WHERE table_schema = DATABASE()
    ");
    $stmt3->execute();
    $rsize = $stmt3->get_result()->fetch_assoc();
    $resp['db_size_mb'] = $rsize['size_mb'] ?? 0;
    $stmt3->close();

    // ── Último insert en produccion / quiebras ───────────────
    $ultimo = null;
    foreach (['produccion', 'registro_quiebras', 'actividad_monitor'] as $tabla) {
        $chk = $conn->query("SHOW TABLES LIKE '$tabla'");
        if ($chk && $chk->num_rows > 0) {
            $q = $conn->query("SELECT MAX(fecha) AS ult FROM $tabla");
            if ($q) {
                $val = $q->fetch_assoc()['ult'] ?? null;
                if ($val && (!$ultimo || $val > $ultimo)) $ultimo = $val;
            }
        }
    }
    $resp['ultimo_insert_db'] = $ultimo
        ? date('d/m/Y H:i', strtotime($ultimo))
        : '—';

    // ── Carga del servidor ───────────────────────────────────
    $load = 0;
    if (function_exists('sys_getloadavg')) {
        $la = sys_getloadavg();
        $load = round($la[0] * 100 / max(1, (int)shell_exec('nproc 2>/dev/null') ?: 1));
    }
    $resp['server_info'] = ['load' => min(100, $load)];

    // ── Alertas ──────────────────────────────────────────────
    if ($resp['db_size_mb'] > 800) {
        $resp['alertas'][] = "BD supera " . $resp['db_size_mb'] . " MB — considera optimización o purga de registros antiguos.";
    }
    if ($resp['total_conectados'] === 0) {
        $resp['warnings'][] = "No hay sesiones activas en las últimas 3 horas.";
    }

} catch (Throwable $e) {
    $resp['success'] = false;
    $resp['error']   = $e->getMessage();
}

echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function extraerModulo(string $desc): string {
    if (preg_match('/[Mm]ódulo[^:]*:\s*(\S+)/', $desc, $m)) return $m[1];
    if (preg_match('/dashboard_\w+|ia_queries|login_\w+/', $desc, $m)) return $m[0];
    return 'default';
}
