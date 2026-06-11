<?php
/**
 * dashboard_admin_check.php
 * Dashboard de Pruebas de Calidad
 */
declare(strict_types=1);
session_start();

$sessionTimeout = 3 * 3600;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $sessionTimeout) {
    session_unset(); session_destroy(); session_start();
    header("Location: login_admin.php"); exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login_admin.php"); exit();
}

require_once '../config/database.php';
require_once 'registrar_actividad.php';
require_once 'auto_audit.php';
date_default_timezone_set('America/Guatemala');

// Crear tabla si no existe
$conn->query("
    CREATE TABLE IF NOT EXISTS pruebas_calidad (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        fecha           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        empleado        VARCHAR(150) NOT NULL DEFAULT '',
        area            VARCHAR(100) DEFAULT '',
        equipo          VARCHAR(100) DEFAULT '',
        turno           VARCHAR(50)  DEFAULT '',
        tipo_prueba     VARCHAR(150) DEFAULT '',
        resultado       ENUM('aprobado','rechazado','pendiente') NOT NULL DEFAULT 'pendiente',
        observaciones   TEXT,
        registrado_por  VARCHAR(150) DEFAULT '',
        INDEX idx_fecha    (fecha),
        INDEX idx_resultado(resultado),
        INDEX idx_area     (area)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Manejar nuevo registro
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_prueba'])) {
    $stmt = $conn->prepare("
        INSERT INTO pruebas_calidad (fecha, empleado, area, equipo, turno, tipo_prueba, resultado, observaciones, registrado_por)
        VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $em = trim($_POST['empleado'] ?? '');
    $ar = trim($_POST['area'] ?? '');
    $eq = trim($_POST['equipo'] ?? '');
    $tu = trim($_POST['turno'] ?? '');
    $tp = trim($_POST['tipo_prueba'] ?? '');
    $re = in_array($_POST['resultado'] ?? '', ['aprobado','rechazado','pendiente']) ? $_POST['resultado'] : 'pendiente';
    $ob = trim($_POST['observaciones'] ?? '');
    $rb = $_SESSION['empleado'];
    $stmt->bind_param('sssssss s', $em, $ar, $eq, $tu, $tp, $re, $ob, $rb);
    $stmt->execute();
    $msg = 'Prueba registrada correctamente.';
    registrar_actividad($conn, 'agregar', $rb, "Nueva prueba de calidad: $tp — $re", $_SERVER['REMOTE_ADDR'] ?? '');
}

// Parámetros de filtro
$f_inicio = $_GET['fi'] ?? date('Y-m-d');
$f_fin    = $_GET['ff'] ?? date('Y-m-d');

// Totales del período
$stmt_tot = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(resultado='aprobado')  AS aprobadas,
        SUM(resultado='rechazado') AS rechazadas,
        SUM(resultado='pendiente') AS pendientes
    FROM pruebas_calidad WHERE DATE(fecha) BETWEEN ? AND ?
");
$stmt_tot->bind_param('ss', $f_inicio, $f_fin);
$stmt_tot->execute();
$tot = $stmt_tot->get_result()->fetch_assoc();
$stmt_tot->close();

$tasa_aprobacion = ($tot['total'] > 0)
    ? round(($tot['aprobadas'] / $tot['total']) * 100, 1) : 0;

// Últimos 50 registros
$stmt_lst = $conn->prepare("
    SELECT * FROM pruebas_calidad
    WHERE DATE(fecha) BETWEEN ? AND ?
    ORDER BY fecha DESC LIMIT 50
");
$stmt_lst->bind_param('ss', $f_inicio, $f_fin);
$stmt_lst->execute();
$lista = $stmt_lst->get_result()->fetchAll(MYSQLI_ASSOC);
$stmt_lst->close();

// Obtener empleados y áreas para el formulario
$empleados = [];
$r = $conn->query("SELECT DISTINCT nombre_empleado FROM empleados ORDER BY nombre_empleado");
if ($r) while ($row = $r->fetch_assoc()) $empleados[] = $row['nombre_empleado'];

$areas = [];
$r2 = $conn->query("SELECT DISTINCT area FROM produccion WHERE area IS NOT NULL AND area != '' ORDER BY area");
if ($r2) while ($row = $r2->fetch_assoc()) $areas[] = $row['area'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SIA-LAB · Pruebas de Calidad</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root{--g:linear-gradient(135deg,#145a32,#27ae60);--ok:#1e8449;--warn:#d68910;--danger:#c0392b;--bg:#f0f4f8;--card:#fff;--border:#e2e8f0;--radius:12px;--shadow:0 4px 20px rgba(0,0,0,.08)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:var(--bg);min-height:100vh;color:#2d3748}
        header{background:var(--g);color:#fff;padding:1.25rem 2rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;box-shadow:0 2px 10px rgba(0,0,0,.15)}
        header h1{font-size:clamp(1.2rem,2.5vw,1.8rem);display:flex;align-items:center;gap:.5rem}
        .btn-hdr{padding:.45rem 1rem;border-radius:8px;border:none;cursor:pointer;font-size:.85rem;font-weight:600;display:flex;align-items:center;gap:.35rem;text-decoration:none;transition:.2s}
        .btn-logout{background:rgba(220,53,69,.8);color:#fff}.btn-logout:hover{background:#c0392b}
        .container{max-width:1400px;margin:0 auto;padding:1.5rem 2rem}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.85rem;margin-bottom:1.25rem}
        .stat{background:var(--card);border-radius:var(--radius);padding:1rem 1.25rem;box-shadow:var(--shadow);border-left:4px solid #27ae60;display:flex;align-items:center;gap:.75rem}
        .stat.ok{border-left-color:#27ae60}.stat.danger{border-left-color:#e74c3c}.stat.warn{border-left-color:#f39c12}
        .stat-icon{font-size:1.7rem;width:40px;text-align:center;color:#27ae60}
        .stat.danger .stat-icon{color:#e74c3c}.stat.warn .stat-icon{color:#f39c12}
        .stat-info span{font-size:.72rem;color:#718096;text-transform:uppercase}
        .stat-info strong{display:block;font-size:1.3rem;font-weight:700}
        .card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.25rem 1.5rem;margin-bottom:1.25rem}
        .card h3{font-size:.95rem;margin-bottom:1rem;display:flex;align-items:center;gap:.4rem;border-bottom:1px solid var(--border);padding-bottom:.6rem}
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
        .form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem}
        .form-group label{display:block;font-size:.82rem;font-weight:600;color:#4a5568;margin-bottom:.3rem}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:.55rem .8rem;border:1px solid var(--border);border-radius:8px;font-size:.9rem;font-family:inherit;transition:.2s}
        .form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:#27ae60;box-shadow:0 0 0 3px rgba(39,174,96,.15)}
        .btn-guardar{padding:.6rem 1.4rem;background:var(--g);color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:.9rem;transition:.2s;display:flex;align-items:center;gap:.4rem}
        .btn-guardar:hover{opacity:.9}
        .filtros{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;padding:.75rem 1rem;background:#f8fafc;border-radius:8px;border:1px solid var(--border)}
        .filtros label{font-size:.82rem;font-weight:600;color:#4a5568}
        .filtros input{padding:.45rem .75rem;border:1px solid var(--border);border-radius:6px;font-size:.85rem}
        .btn-filtrar{padding:.45rem .9rem;background:var(--g);color:#fff;border:none;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer}
        table{width:100%;border-collapse:collapse}
        thead th{background:#f8fafc;padding:.65rem .9rem;text-align:left;font-size:.75rem;text-transform:uppercase;color:#718096;border-bottom:1px solid var(--border)}
        tbody td{padding:.65rem .9rem;font-size:.85rem;border-bottom:1px solid var(--border)}
        tbody tr:last-child td{border-bottom:none}tbody tr:hover td{background:#f8fafc}
        .badge{padding:.2rem .6rem;border-radius:20px;font-size:.72rem;font-weight:600}
        .b-ok{background:#d4edda;color:#155724}.b-danger{background:#f8d7da;color:#721c24}.b-warn{background:#fff3cd;color:#856404}
        .progress-bar{height:12px;background:#e2e8f0;border-radius:6px;overflow:hidden;margin-top:.3rem}
        .progress-fill{height:100%;background:linear-gradient(90deg,#27ae60,#2ecc71);border-radius:6px}
        .alert-ok{background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.88rem}
        @media(max-width:768px){.container{padding:1rem}.grid-2{grid-template-columns:1fr}}
    </style>
</head>
<body>
<header>
    <h1><i class="fas fa-check-circle"></i> Pruebas de Calidad</h1>
    <div style="display:flex;gap:.6rem">
        <a href="login_admin.php" class="btn-hdr btn-logout"><i class="fas fa-sign-out-alt"></i> Salir</a>
    </div>
</header>

<div class="container">
    <?php if ($msg): ?>
    <div class="alert-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="stats">
        <div class="stat <?= $tasa_aprobacion >= 90 ? 'ok' : ($tasa_aprobacion >= 70 ? 'warn' : 'danger') ?>">
            <div class="stat-icon"><i class="fas fa-percentage"></i></div>
            <div class="stat-info"><span>Tasa Aprobación</span>
                <strong><?= $tasa_aprobacion ?>%</strong>
                <div class="progress-bar"><div class="progress-fill" style="width:<?= $tasa_aprobacion ?>%"></div></div>
            </div>
        </div>
        <div class="stat ok"><div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info"><span>Aprobadas</span><strong><?= $tot['aprobadas'] ?></strong></div></div>
        <div class="stat danger"><div class="stat-icon"><i class="fas fa-times-circle"></i></div>
            <div class="stat-info"><span>Rechazadas</span><strong><?= $tot['rechazadas'] ?></strong></div></div>
        <div class="stat warn"><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
            <div class="stat-info"><span>Pendientes</span><strong><?= $tot['pendientes'] ?></strong></div></div>
        <div class="stat"><div class="stat-icon" style="color:#2e86c1"><i class="fas fa-clipboard-list"></i></div>
            <div class="stat-info"><span>Total Período</span><strong><?= $tot['total'] ?></strong></div></div>
    </div>

    <!-- Formulario nueva prueba -->
    <div class="card">
        <h3><i class="fas fa-plus-circle" style="color:#27ae60"></i> Registrar Nueva Prueba</h3>
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Empleado</label>
                    <select name="empleado" required>
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($empleados as $e): ?>
                        <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Área</label>
                    <select name="area">
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($areas as $a): ?>
                        <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Equipo / Máquina</label>
                    <input type="text" name="equipo" placeholder="Ej: BISEL-01">
                </div>
                <div class="form-group">
                    <label>Turno</label>
                    <select name="turno">
                        <option value="">-- Seleccionar --</option>
                        <option value="Mañana">Mañana</option>
                        <option value="Tarde">Tarde</option>
                        <option value="Noche">Noche</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tipo de Prueba</label>
                    <input type="text" name="tipo_prueba" required placeholder="Ej: Inspección visual, Medición, etc.">
                </div>
                <div class="form-group">
                    <label>Resultado</label>
                    <select name="resultado" required>
                        <option value="aprobado">✅ Aprobado</option>
                        <option value="rechazado">❌ Rechazado</option>
                        <option value="pendiente">⏳ Pendiente</option>
                    </select>
                </div>
            </div>
            <div class="form-group" style="margin-top:.75rem">
                <label>Observaciones</label>
                <textarea name="observaciones" rows="2" placeholder="Detalles adicionales..."></textarea>
            </div>
            <div style="margin-top:.85rem">
                <button type="submit" name="guardar_prueba" class="btn-guardar">
                    <i class="fas fa-save"></i> Guardar Prueba
                </button>
            </div>
        </form>
    </div>

    <!-- Tabla de registros -->
    <div class="card" style="padding:0;overflow:hidden">
        <div style="padding:.9rem 1.25rem;background:#f8fafc;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
            <h3 style="margin:0;border:none;padding:0"><i class="fas fa-list-ul" style="color:#27ae60"></i> Historial de Pruebas</h3>
            <form method="GET" class="filtros" style="margin:0;background:none;border:none;padding:0">
                <label>Desde</label><input type="date" name="fi" value="<?= $f_inicio ?>">
                <label>Hasta</label><input type="date" name="ff" value="<?= $f_fin ?>">
                <button type="submit" class="btn-filtrar"><i class="fas fa-filter"></i> Filtrar</button>
            </form>
        </div>
        <table>
            <thead><tr>
                <th>#</th><th>Fecha</th><th>Empleado</th><th>Área</th>
                <th>Equipo</th><th>Turno</th><th>Tipo Prueba</th><th>Resultado</th><th>Observaciones</th>
            </tr></thead>
            <tbody>
            <?php if (empty($lista)): ?>
            <tr><td colspan="9" style="text-align:center;padding:2rem;color:#718096">Sin registros para el período seleccionado</td></tr>
            <?php else: ?>
            <?php foreach ($lista as $i => $p): ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td><?= date('d/m/Y H:i', strtotime($p['fecha'])) ?></td>
                <td><?= htmlspecialchars($p['empleado']) ?></td>
                <td><?= htmlspecialchars($p['area']) ?></td>
                <td><?= htmlspecialchars($p['equipo']) ?></td>
                <td><?= htmlspecialchars($p['turno']) ?></td>
                <td><?= htmlspecialchars($p['tipo_prueba']) ?></td>
                <td>
                    <?php if ($p['resultado'] === 'aprobado'): ?>
                        <span class="badge b-ok"><i class="fas fa-check"></i> Aprobado</span>
                    <?php elseif ($p['resultado'] === 'rechazado'): ?>
                        <span class="badge b-danger"><i class="fas fa-times"></i> Rechazado</span>
                    <?php else: ?>
                        <span class="badge b-warn"><i class="fas fa-clock"></i> Pendiente</span>
                    <?php endif; ?>
                </td>
                <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                    title="<?= htmlspecialchars($p['observaciones']) ?>">
                    <?= htmlspecialchars($p['observaciones'] ?: '—') ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>
