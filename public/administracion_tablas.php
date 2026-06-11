<?php
/**
 * administracion_tablas.php
 * Administración de tablas de la base de datos
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
date_default_timezone_set('America/Guatemala');

$mensaje = '';
$error   = '';

// ── Acción: TRUNCATE (solo tablas no críticas) ───────────────
$tablas_no_truncables = ['empleados', 'equipos'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'], $_POST['tabla'])) {
    $tabla  = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['tabla']);
    $accion = $_POST['accion'];

    if ($accion === 'optimizar') {
        $conn->query("OPTIMIZE TABLE `$tabla`");
        $mensaje = "Tabla `$tabla` optimizada correctamente.";
        registrar_actividad($conn, 'modificar', $_SESSION['empleado'], "OPTIMIZE TABLE `$tabla`", $_SERVER['REMOTE_ADDR'] ?? '');
    } elseif ($accion === 'analizar') {
        $conn->query("ANALYZE TABLE `$tabla`");
        $mensaje = "Tabla `$tabla` analizada correctamente.";
    } elseif ($accion === 'truncar' && !in_array($tabla, $tablas_no_truncables)) {
        if (isset($_POST['confirmar']) && $_POST['confirmar'] === 'SI') {
            $conn->query("TRUNCATE TABLE `$tabla`");
            $mensaje = "Tabla `$tabla` vaciada (TRUNCATE) correctamente.";
            registrar_actividad($conn, 'eliminar', $_SESSION['empleado'], "TRUNCATE TABLE `$tabla`", $_SERVER['REMOTE_ADDR'] ?? '');
        } else {
            $error = "Debes confirmar con 'SI' para vaciar la tabla.";
        }
    } else {
        $error = "Acción no permitida o tabla protegida.";
    }
}

// ── Obtener info de tablas ───────────────────────────────────
$tablas = [];
$r = $conn->query("
    SELECT
        TABLE_NAME        AS nombre,
        TABLE_ROWS        AS filas_est,
        ROUND((data_length)/1024/1024, 3)               AS datos_mb,
        ROUND((index_length)/1024/1024, 3)              AS indices_mb,
        ROUND((data_length+index_length)/1024/1024, 3)  AS total_mb,
        TABLE_COMMENT     AS comentario,
        CREATE_TIME       AS creada,
        UPDATE_TIME       AS actualizada,
        ENGINE            AS motor,
        TABLE_COLLATION   AS collation
    FROM information_schema.TABLES
    WHERE table_schema = DATABASE()
    ORDER BY (data_length+index_length) DESC
");
$total_size_mb = 0.0;
$total_filas   = 0;
while ($row = $r->fetch_assoc()) {
    // Filas exactas
    $rc = $conn->query("SELECT COUNT(*) AS c FROM `{$row['nombre']}`");
    $row['filas'] = $rc ? (int)$rc->fetch_assoc()['c'] : (int)$row['filas_est'];
    $tablas[] = $row;
    $total_size_mb += (float)$row['total_mb'];
    $total_filas   += $row['filas'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SIA-LAB · Administración de Tablas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root{--g:linear-gradient(135deg,#1a5276,#2e86c1);--ok:#1e8449;--warn:#d68910;--danger:#c0392b;--bg:#f0f4f8;--card:#fff;--border:#e2e8f0;--radius:12px;--shadow:0 4px 20px rgba(0,0,0,.08)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:var(--bg);min-height:100vh}
        header{background:var(--g);color:#fff;padding:1.25rem 2rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;box-shadow:0 2px 10px rgba(0,0,0,.15)}
        header h1{font-size:clamp(1.2rem,2.5vw,1.8rem);display:flex;align-items:center;gap:.5rem}
        .btn-hdr{padding:.45rem 1rem;border-radius:8px;border:none;cursor:pointer;font-size:.85rem;font-weight:600;display:flex;align-items:center;gap:.35rem;text-decoration:none;transition:.2s}
        .btn-logout{background:rgba(220,53,69,.8);color:#fff}.btn-logout:hover{background:#c0392b}
        .container{max-width:1400px;margin:0 auto;padding:1.5rem 2rem}
        .alert{padding:.85rem 1.1rem;border-radius:var(--radius);margin-bottom:1rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
        .alert.ok{background:#d4edda;border:1px solid #c3e6cb;color:#155724}
        .alert.err{background:#f8d7da;border:1px solid #f5c6cb;color:#721c24}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.85rem;margin-bottom:1.25rem}
        .stat{background:var(--card);border-radius:var(--radius);padding:1rem 1.25rem;box-shadow:var(--shadow);border-left:4px solid #2e86c1;display:flex;align-items:center;gap:.75rem}
        .stat-icon{font-size:1.7rem;color:#2e86c1;width:40px;text-align:center}
        .stat-info span{font-size:.72rem;color:#718096;text-transform:uppercase}
        .stat-info strong{display:block;font-size:1.3rem;font-weight:700}
        .tabla-card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:1.5rem}
        .tabla-card h3{padding:.9rem 1.25rem;font-size:.9rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.4rem;background:#f8fafc}
        table{width:100%;border-collapse:collapse}
        thead th{background:#f0f4f8;padding:.65rem .9rem;text-align:left;font-size:.75rem;text-transform:uppercase;letter-spacing:.4px;color:#718096;border-bottom:1px solid var(--border)}
        tbody td{padding:.65rem .9rem;font-size:.85rem;border-bottom:1px solid var(--border)}
        tbody tr:last-child td{border-bottom:none}
        tbody tr:hover td{background:#f8fafc}
        .badge{padding:.2rem .6rem;border-radius:20px;font-size:.72rem;font-weight:600}
        .b-ok{background:#d4edda;color:#155724}.b-warn{background:#fff3cd;color:#856404}.b-lg{background:#d1ecf1;color:#0c5460}
        .btn-sm{padding:.3rem .7rem;border-radius:6px;border:none;cursor:pointer;font-size:.78rem;font-weight:600;transition:.15s}
        .btn-opt{background:#d1ecf1;color:#0c5460}.btn-opt:hover{background:#bee5eb}
        .btn-trunc{background:#f8d7da;color:#721c24}.btn-trunc:hover{background:#f5c6cb}
        .modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center}
        .modal-bg.show{display:flex}
        .modal{background:#fff;border-radius:var(--radius);padding:1.5rem;max-width:440px;width:90%;box-shadow:0 8px 30px rgba(0,0,0,.2)}
        .modal h3{margin-bottom:.75rem;display:flex;align-items:center;gap:.4rem;color:#c0392b}
        .modal p{font-size:.88rem;margin-bottom:1rem;line-height:1.5}
        .modal form{display:flex;flex-direction:column;gap:.65rem}
        .modal input{padding:.5rem .75rem;border:1px solid var(--border);border-radius:8px;font-size:.9rem}
        .modal-btns{display:flex;gap:.5rem;justify-content:flex-end}
        .btn-cancel{background:#e2e8f0;color:#2d3748;padding:.45rem .9rem;border-radius:8px;border:none;cursor:pointer;font-weight:600}
        .btn-confirm{background:#c0392b;color:#fff;padding:.45rem .9rem;border-radius:8px;border:none;cursor:pointer;font-weight:600}
        @media(max-width:768px){.container{padding:1rem}header{padding:1rem}}
    </style>
</head>
<body>
<header>
    <h1><i class="fas fa-table"></i> Administración de Tablas</h1>
    <div style="display:flex;gap:.6rem;align-items:center">
        <span style="font-size:.85rem;opacity:.85"><i class="fas fa-database"></i> <?= htmlspecialchars($conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '') ?></span>
        <a href="login_admin.php" class="btn-hdr btn-logout"><i class="fas fa-sign-out-alt"></i> Salir</a>
    </div>
</header>

<div class="container">

    <?php if ($mensaje): ?>
    <div class="alert ok"><i class="fas fa-check-circle"></i><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert err"><i class="fas fa-exclamation-triangle"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat"><div class="stat-icon"><i class="fas fa-layer-group"></i></div>
            <div class="stat-info"><span>Tablas</span><strong><?= count($tablas) ?></strong></div></div>
        <div class="stat"><div class="stat-icon"><i class="fas fa-hdd"></i></div>
            <div class="stat-info"><span>Tamaño Total</span><strong><?= number_format($total_size_mb, 2) ?> MB</strong></div></div>
        <div class="stat"><div class="stat-icon"><i class="fas fa-list-ol"></i></div>
            <div class="stat-info"><span>Total Registros</span><strong><?= number_format($total_filas) ?></strong></div></div>
        <div class="stat"><div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-info"><span>Hora del Servidor</span><strong><?= date('H:i:s') ?></strong></div></div>
    </div>

    <div class="tabla-card">
        <h3><i class="fas fa-table" style="color:#2e86c1"></i> Tablas de la Base de Datos</h3>
        <table>
            <thead><tr>
                <th>#</th><th>Tabla</th><th>Motor</th><th>Registros</th>
                <th>Datos</th><th>Índices</th><th>Total</th>
                <th>Última Actualización</th><th>Acciones</th>
            </tr></thead>
            <tbody>
            <?php foreach ($tablas as $i => $t): ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td><strong><i class="fas fa-table" style="color:#2e86c1;margin-right:.3rem"></i><?= htmlspecialchars($t['nombre']) ?></strong></td>
                <td><span class="badge b-lg"><?= htmlspecialchars($t['motor']) ?></span></td>
                <td><?= number_format($t['filas']) ?></td>
                <td><?= $t['datos_mb'] ?> MB</td>
                <td><?= $t['indices_mb'] ?> MB</td>
                <td>
                    <strong style="color:<?= $t['total_mb'] > 100 ? '#c0392b' : ($t['total_mb'] > 10 ? '#d68910' : '#1e8449') ?>">
                        <?= $t['total_mb'] ?> MB
                    </strong>
                </td>
                <td style="font-size:.78rem;color:#718096"><?= $t['actualizada'] ? date('d/m/Y H:i', strtotime($t['actualizada'])) : '—' ?></td>
                <td style="white-space:nowrap">
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="tabla" value="<?= htmlspecialchars($t['nombre']) ?>">
                        <input type="hidden" name="accion" value="optimizar">
                        <button class="btn-sm btn-opt" type="submit" title="Optimizar tabla"><i class="fas fa-broom"></i> Opt</button>
                    </form>
                    <?php if (!in_array($t['nombre'], ['empleados','equipos'])): ?>
                    <button class="btn-sm btn-trunc" onclick="abrirModal('<?= htmlspecialchars($t['nombre']) ?>')" title="Vaciar tabla">
                        <i class="fas fa-trash-alt"></i> Vaciar
                    </button>
                    <?php else: ?>
                    <span class="badge b-ok" title="Tabla protegida"><i class="fas fa-lock"></i> Protegida</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

<!-- Modal de confirmación TRUNCATE -->
<div class="modal-bg" id="modal">
    <div class="modal">
        <h3><i class="fas fa-exclamation-triangle"></i> Confirmar TRUNCATE</h3>
        <p>Esta acción <strong>borrará TODOS los registros</strong> de la tabla <strong id="modal-tabla-nombre"></strong>.
        Esta operación <strong>no se puede deshacer</strong>.</p>
        <form method="POST">
            <input type="hidden" name="tabla" id="modal-tabla">
            <input type="hidden" name="accion" value="truncar">
            <input type="text" name="confirmar" placeholder="Escribe SI para confirmar" autocomplete="off" required>
            <div class="modal-btns">
                <button type="button" class="btn-cancel" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn-confirm"><i class="fas fa-trash-alt"></i> Vaciar Tabla</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModal(tabla) {
    document.getElementById('modal-tabla').value = tabla;
    document.getElementById('modal-tabla-nombre').textContent = tabla;
    document.getElementById('modal').classList.add('show');
}
function cerrarModal() {
    document.getElementById('modal').classList.remove('show');
}
document.getElementById('modal').addEventListener('click', e => {
    if (e.target === document.getElementById('modal')) cerrarModal();
});
</script>
</body>
</html>
