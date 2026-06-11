<?php
/**
 * asignar_tarea.php
 * Módulo de asignación y seguimiento de tareas para técnicos
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
    CREATE TABLE IF NOT EXISTS tareas (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        titulo          VARCHAR(200) NOT NULL,
        descripcion     TEXT,
        asignado_a      VARCHAR(150) NOT NULL DEFAULT '',
        asignado_por    VARCHAR(150) NOT NULL DEFAULT '',
        area            VARCHAR(100) DEFAULT '',
        equipo          VARCHAR(100) DEFAULT '',
        prioridad       ENUM('baja','media','alta','urgente') NOT NULL DEFAULT 'media',
        estado          ENUM('pendiente','en_progreso','completada','cancelada') NOT NULL DEFAULT 'pendiente',
        fecha_creacion  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fecha_limite    DATE DEFAULT NULL,
        fecha_cierre    DATETIME DEFAULT NULL,
        notas_cierre    TEXT,
        INDEX idx_asignado (asignado_a),
        INDEX idx_estado   (estado),
        INDEX idx_fecha    (fecha_creacion)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$msg   = '';
$error = '';

// ── CREAR TAREA ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_tarea'])) {
    $titulo  = trim($_POST['titulo'] ?? '');
    $desc    = trim($_POST['descripcion'] ?? '');
    $asig    = trim($_POST['asignado_a'] ?? '');
    $area    = trim($_POST['area'] ?? '');
    $equipo  = trim($_POST['equipo'] ?? '');
    $prio    = in_array($_POST['prioridad'] ?? '', ['baja','media','alta','urgente']) ? $_POST['prioridad'] : 'media';
    $limite  = !empty($_POST['fecha_limite']) ? $_POST['fecha_limite'] : null;
    $por     = $_SESSION['empleado'];

    if ($titulo && $asig) {
        $stmt = $conn->prepare("
            INSERT INTO tareas (titulo, descripcion, asignado_a, asignado_por, area, equipo, prioridad, fecha_limite)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param('ssssssss', $titulo, $desc, $asig, $por, $area, $equipo, $prio, $limite);
        $stmt->execute();
        $msg = "Tarea asignada correctamente a $asig.";
        registrar_actividad($conn, 'agregar', $por, "Tarea creada: '$titulo' → $asig", $_SERVER['REMOTE_ADDR'] ?? '');
    } else {
        $error = 'El título y el empleado asignado son obligatorios.';
    }
}

// ── CAMBIAR ESTADO ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estado'])) {
    $id_t   = (int)$_POST['tarea_id'];
    $estado = in_array($_POST['nuevo_estado'] ?? '', ['pendiente','en_progreso','completada','cancelada'])
        ? $_POST['nuevo_estado'] : 'pendiente';
    $notas = trim($_POST['notas_cierre'] ?? '');
    $cierre = in_array($estado, ['completada','cancelada']) ? date('Y-m-d H:i:s') : null;

    $stmt = $conn->prepare("
        UPDATE tareas SET estado=?, fecha_cierre=?, notas_cierre=? WHERE id=?
    ");
    $stmt->bind_param('sssi', $estado, $cierre, $notas, $id_t);
    $stmt->execute();
    $msg = 'Estado actualizado correctamente.';
}

// Listas para formulario
$empleados = [];
$r = $conn->query("SELECT DISTINCT nombre_empleado FROM empleados ORDER BY nombre_empleado");
if ($r) while ($row = $r->fetch_assoc()) $empleados[] = $row['nombre_empleado'];

$areas = [];
$r2 = $conn->query("SELECT DISTINCT area FROM produccion WHERE area IS NOT NULL AND area != '' ORDER BY area");
if ($r2) while ($row = $r2->fetch_assoc()) $areas[] = $row['area'];

// Filtro de estado
$filtro_estado = $_GET['estado'] ?? 'activas';
$where_estado = $filtro_estado === 'todas'      ? "1=1"
              : ($filtro_estado === 'activas'   ? "estado IN ('pendiente','en_progreso')"
              : ($filtro_estado === 'completadas'? "estado='completada'"
              : "estado='cancelada'"));

$tareas = [];
$rt = $conn->query("SELECT * FROM tareas WHERE $where_estado ORDER BY
    FIELD(prioridad,'urgente','alta','media','baja'), fecha_creacion DESC LIMIT 100");
if ($rt) $tareas = $rt->fetchAll(MYSQLI_ASSOC);

// KPIs
$kpis = $conn->query("
    SELECT
        SUM(estado='pendiente')   AS pendientes,
        SUM(estado='en_progreso') AS en_progreso,
        SUM(estado='completada')  AS completadas,
        SUM(estado='urgente' OR (estado IN('pendiente','en_progreso') AND prioridad='urgente')) AS urgentes,
        COUNT(*) AS total
    FROM tareas
")->fetch_assoc();

$colores_prio  = ['baja'=>'#718096','media'=>'#2e86c1','alta'=>'#d68910','urgente'=>'#c0392b'];
$iconos_estado = ['pendiente'=>'clock','en_progreso'=>'spinner','completada'=>'check-circle','cancelada'=>'ban'];
$colores_estado= ['pendiente'=>'#d68910','en_progreso'=>'#2e86c1','completada'=>'#27ae60','cancelada'=>'#718096'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SIA-LAB · Asignar Tareas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root{--g:linear-gradient(135deg,#1a3a5c,#2e6da4);--ok:#1e8449;--warn:#d68910;--danger:#c0392b;--bg:#f0f4f8;--card:#fff;--border:#e2e8f0;--radius:12px;--shadow:0 4px 20px rgba(0,0,0,.08)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:var(--bg);min-height:100vh;color:#2d3748}
        header{background:var(--g);color:#fff;padding:1.25rem 2rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;box-shadow:0 2px 10px rgba(0,0,0,.15)}
        header h1{font-size:clamp(1.2rem,2.5vw,1.8rem);display:flex;align-items:center;gap:.5rem}
        .btn-hdr{padding:.45rem 1rem;border-radius:8px;border:none;cursor:pointer;font-size:.85rem;font-weight:600;text-decoration:none;transition:.2s;display:flex;align-items:center;gap:.35rem}
        .btn-logout{background:rgba(220,53,69,.8);color:#fff}.btn-logout:hover{background:#c0392b}
        .container{max-width:1440px;margin:0 auto;padding:1.5rem 2rem}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:.85rem;margin-bottom:1.25rem}
        .stat{background:var(--card);border-radius:var(--radius);padding:1rem 1.25rem;box-shadow:var(--shadow);border-left:4px solid #2e6da4;display:flex;align-items:center;gap:.75rem}
        .stat-icon{font-size:1.7rem;width:40px;text-align:center}
        .stat-info span{font-size:.72rem;color:#718096;text-transform:uppercase}
        .stat-info strong{display:block;font-size:1.3rem;font-weight:700}
        .card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.25rem 1.5rem;margin-bottom:1.25rem}
        .card h3{font-size:.95rem;margin-bottom:1rem;display:flex;align-items:center;gap:.4rem;border-bottom:1px solid var(--border);padding-bottom:.6rem}
        .form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem}
        .form-group label{display:block;font-size:.82rem;font-weight:600;color:#4a5568;margin-bottom:.3rem}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:.55rem .8rem;border:1px solid var(--border);border-radius:8px;font-size:.9rem;font-family:inherit;transition:.2s}
        .form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:#2e6da4;box-shadow:0 0 0 3px rgba(46,109,164,.15)}
        .btn-crear{padding:.6rem 1.4rem;background:var(--g);color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:.9rem;transition:.2s;display:flex;align-items:center;gap:.4rem}
        .btn-crear:hover{opacity:.9}
        .filtros-tabs{display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap}
        .tab{padding:.45rem 1rem;border-radius:20px;border:1px solid var(--border);background:#fff;font-size:.82rem;font-weight:600;cursor:pointer;text-decoration:none;color:#4a5568;transition:.2s}
        .tab.active,.tab:hover{background:#2e6da4;color:#fff;border-color:#2e6da4}
        .tareas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem}
        .tarea-card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:1rem 1.25rem;border-left:4px solid #2e6da4;transition:.2s}
        .tarea-card:hover{transform:translateY(-2px)}
        .tarea-titulo{font-size:.95rem;font-weight:700;margin-bottom:.4rem;line-height:1.3}
        .tarea-meta{font-size:.75rem;color:#718096;display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.5rem}
        .tarea-meta span{display:flex;align-items:center;gap:.25rem}
        .badge{padding:.2rem .55rem;border-radius:20px;font-size:.72rem;font-weight:600}
        .tarea-desc{font-size:.82rem;color:#4a5568;margin-bottom:.75rem;line-height:1.4}
        .btn-estado{padding:.3rem .65rem;border-radius:6px;border:none;cursor:pointer;font-size:.75rem;font-weight:600;transition:.15s;background:#e2e8f0;color:#2d3748}
        .btn-estado:hover{background:#cbd5e0}
        .alert-ok{background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.88rem}
        .alert-err{background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.88rem}
        .sin-tareas{text-align:center;padding:3rem;color:#718096}
        .sin-tareas i{font-size:2.5rem;opacity:.35;display:block;margin-bottom:.75rem}
        @media(max-width:768px){.container{padding:1rem}header{padding:1rem}}
    </style>
</head>
<body>
<header>
    <h1><i class="fas fa-tasks"></i> Asignar Tareas</h1>
    <a href="login_admin.php" class="btn-hdr btn-logout"><i class="fas fa-sign-out-alt"></i> Salir</a>
</header>

<div class="container">

    <?php if ($msg): ?>
    <div class="alert-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert-err"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="stats">
        <div class="stat" style="border-left-color:#d68910">
            <div class="stat-icon" style="color:#d68910"><i class="fas fa-clock"></i></div>
            <div class="stat-info"><span>Pendientes</span><strong><?= $kpis['pendientes'] ?? 0 ?></strong></div>
        </div>
        <div class="stat" style="border-left-color:#2e86c1">
            <div class="stat-icon" style="color:#2e86c1"><i class="fas fa-spinner"></i></div>
            <div class="stat-info"><span>En Progreso</span><strong><?= $kpis['en_progreso'] ?? 0 ?></strong></div>
        </div>
        <div class="stat" style="border-left-color:#27ae60">
            <div class="stat-icon" style="color:#27ae60"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info"><span>Completadas</span><strong><?= $kpis['completadas'] ?? 0 ?></strong></div>
        </div>
        <div class="stat" style="border-left-color:#c0392b">
            <div class="stat-icon" style="color:#c0392b"><i class="fas fa-exclamation-circle"></i></div>
            <div class="stat-info"><span>Urgentes Activas</span><strong><?= $kpis['urgentes'] ?? 0 ?></strong></div>
        </div>
    </div>

    <!-- Formulario nueva tarea -->
    <div class="card">
        <h3><i class="fas fa-plus-circle" style="color:#2e6da4"></i> Asignar Nueva Tarea</h3>
        <form method="POST">
            <div class="form-row">
                <div class="form-group" style="grid-column:span 2">
                    <label>Título de la Tarea *</label>
                    <input type="text" name="titulo" required placeholder="Ej: Revisar máquina BISEL-03">
                </div>
                <div class="form-group">
                    <label>Asignar a *</label>
                    <select name="asignado_a" required>
                        <option value="">-- Seleccionar Empleado --</option>
                        <?php foreach ($empleados as $e): ?>
                        <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Prioridad</label>
                    <select name="prioridad">
                        <option value="baja">🟢 Baja</option>
                        <option value="media" selected>🔵 Media</option>
                        <option value="alta">🟡 Alta</option>
                        <option value="urgente">🔴 Urgente</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Área</label>
                    <select name="area">
                        <option value="">-- Opcional --</option>
                        <?php foreach ($areas as $a): ?>
                        <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Equipo / Máquina</label>
                    <input type="text" name="equipo" placeholder="Opcional">
                </div>
                <div class="form-group">
                    <label>Fecha Límite</label>
                    <input type="date" name="fecha_limite" min="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="form-group" style="margin-top:.75rem">
                <label>Descripción / Instrucciones</label>
                <textarea name="descripcion" rows="2" placeholder="Detalla lo que debe hacer el empleado..."></textarea>
            </div>
            <div style="margin-top:.85rem">
                <button type="submit" name="crear_tarea" class="btn-crear">
                    <i class="fas fa-paper-plane"></i> Asignar Tarea
                </button>
            </div>
        </form>
    </div>

    <!-- Lista de tareas -->
    <div class="filtros-tabs">
        <a href="?estado=activas"     class="tab <?= $filtro_estado==='activas'?    'active':'' ?>"><i class="fas fa-play-circle"></i> Activas</a>
        <a href="?estado=completadas" class="tab <?= $filtro_estado==='completadas'?'active':'' ?>"><i class="fas fa-check-circle"></i> Completadas</a>
        <a href="?estado=todas"       class="tab <?= $filtro_estado==='todas'?      'active':'' ?>"><i class="fas fa-list"></i> Todas</a>
    </div>

    <?php if (empty($tareas)): ?>
    <div class="sin-tareas"><i class="fas fa-clipboard-check"></i><p>No hay tareas en esta vista.</p></div>
    <?php else: ?>
    <div class="tareas-grid">
        <?php foreach ($tareas as $t):
            $color_prio  = $colores_prio[$t['prioridad']] ?? '#718096';
            $color_est   = $colores_estado[$t['estado']] ?? '#718096';
            $icono_est   = $iconos_estado[$t['estado']] ?? 'circle';
            $vencida     = $t['fecha_limite'] && $t['fecha_limite'] < date('Y-m-d') && !in_array($t['estado'], ['completada','cancelada']);
        ?>
        <div class="tarea-card" style="border-left-color:<?= $color_prio ?>">
            <div class="tarea-titulo">
                <?= htmlspecialchars($t['titulo']) ?>
                <?php if ($vencida): ?><span class="badge" style="background:#f8d7da;color:#721c24;margin-left:.3rem"><i class="fas fa-exclamation-triangle"></i> VENCIDA</span><?php endif; ?>
            </div>
            <div class="tarea-meta">
                <span><i class="fas fa-user"></i><?= htmlspecialchars($t['asignado_a']) ?></span>
                <span><i class="fas fa-calendar"></i><?= date('d/m/Y', strtotime($t['fecha_creacion'])) ?></span>
                <?php if ($t['fecha_limite']): ?>
                <span><i class="fas fa-flag"></i>Límite: <?= date('d/m/Y', strtotime($t['fecha_limite'])) ?></span>
                <?php endif; ?>
                <?php if ($t['area']): ?><span><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($t['area']) ?></span><?php endif; ?>
            </div>
            <div style="display:flex;gap:.4rem;margin-bottom:.6rem;flex-wrap:wrap">
                <span class="badge" style="background:<?= $color_prio ?>22;color:<?= $color_prio ?>;border:1px solid <?= $color_prio ?>55">
                    <?= strtoupper($t['prioridad']) ?>
                </span>
                <span class="badge" style="background:<?= $color_est ?>22;color:<?= $color_est ?>;border:1px solid <?= $color_est ?>55">
                    <i class="fas fa-<?= $icono_est ?>"></i> <?= str_replace('_',' ', $t['estado']) ?>
                </span>
                <?php if ($t['equipo']): ?><span class="badge" style="background:#edf2f7;color:#4a5568"><?= htmlspecialchars($t['equipo']) ?></span><?php endif; ?>
            </div>
            <?php if ($t['descripcion']): ?>
            <div class="tarea-desc"><?= htmlspecialchars(mb_substr($t['descripcion'], 0, 120)) ?><?= mb_strlen($t['descripcion'])>120?'…':'' ?></div>
            <?php endif; ?>
            <!-- Cambiar estado -->
            <?php if (!in_array($t['estado'], ['completada','cancelada'])): ?>
            <form method="POST" style="display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.5rem">
                <input type="hidden" name="tarea_id" value="<?= $t['id'] ?>">
                <?php if ($t['estado'] === 'pendiente'): ?>
                <button type="submit" name="cambiar_estado" class="btn-estado"
                    onclick="this.form.nuevo_estado.value='en_progreso'"
                    style="background:#d1ecf1;color:#0c5460">
                    <i class="fas fa-play"></i> Iniciar
                </button>
                <?php endif; ?>
                <button type="submit" name="cambiar_estado" class="btn-estado"
                    onclick="this.form.nuevo_estado.value='completada'"
                    style="background:#d4edda;color:#155724">
                    <i class="fas fa-check"></i> Completar
                </button>
                <button type="submit" name="cambiar_estado" class="btn-estado"
                    onclick="this.form.nuevo_estado.value='cancelada'"
                    style="background:#f8d7da;color:#721c24">
                    <i class="fas fa-ban"></i> Cancelar
                </button>
                <input type="hidden" name="nuevo_estado" value="">
            </form>
            <?php elseif ($t['notas_cierre']): ?>
            <div style="font-size:.78rem;color:#718096;margin-top:.4rem;font-style:italic">
                <i class="fas fa-comment-alt"></i> <?= htmlspecialchars(mb_substr($t['notas_cierre'],0,80)) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
