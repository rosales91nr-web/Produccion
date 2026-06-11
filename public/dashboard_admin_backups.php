<?php
/**
 * Dashboard Monitoreo de Respaldos de Base de Datos
 * Monitorea C:\backups con patrón: produccion_quiebras_YYYY-MM-DD_HH-00
 * @author Nestor Rosales | Rosalesdev91
 */

declare(strict_types=1);
session_start();

// Expiración de sesión 3 horas
$sessionTimeoutSeconds = 3 * 60 * 60;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $sessionTimeoutSeconds) {
    session_unset(); session_destroy(); session_start();
    header("Location: login_admin.php"); exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login_admin.php"); exit();
}

date_default_timezone_set('America/Guatemala');

// ─── CONFIGURACIÓN ────────────────────────────────────────────────────────────
// Cambia esta ruta según el servidor donde corran los respaldos
$BACKUP_DIR = defined('BACKUP_PATH') ? BACKUP_PATH : 'C:\\backups';

// Detectar si el sistema es Windows o Linux y adaptar separador
if (PHP_OS_FAMILY === 'Windows') {
    $BACKUP_DIR = 'C:\\backups';
} else {
    // En Linux/Replit, permite sobrescribir con variable de entorno
    $BACKUP_DIR = getenv('BACKUP_PATH') ?: $BACKUP_DIR;
}

// Prefijo del patrón de respaldos
$PREFIJO = 'produccion_quiebras_';

// ─── LÓGICA DE ESCANEO ────────────────────────────────────────────────────────
$respaldos = [];
$dir_existe = is_dir($BACKUP_DIR);

if ($dir_existe) {
    $items = scandir($BACKUP_DIR);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        // Coincide con: produccion_quiebras_YYYY-MM-DD_HH-00
        if (preg_match('/^' . preg_quote($PREFIJO, '/') . '(\d{4}-\d{2}-\d{2})_(\d{2})-00$/', $item, $m)) {
            $ruta = $BACKUP_DIR . DIRECTORY_SEPARATOR . $item;
            $fecha = $m[1];
            $hora  = (int)$m[2];
            $es_dir = is_dir($ruta);
            $tamanio = 0;
            if ($es_dir) {
                // Calcular tamaño del directorio
                $tamanio = calcularTamanioCarpeta($ruta);
            } else {
                $tamanio = filesize($ruta);
            }
            $respaldos[] = [
                'nombre'    => $item,
                'fecha'     => $fecha,
                'hora'      => $hora,
                'hora_fmt'  => str_pad((string)$hora, 2, '0', STR_PAD_LEFT) . ':00',
                'ruta'      => $ruta,
                'tipo'      => $es_dir ? 'carpeta' : 'archivo',
                'tamanio'   => $tamanio,
                'timestamp' => strtotime($fecha . ' ' . str_pad((string)$hora, 2, '0', STR_PAD_LEFT) . ':00:00'),
                'mtime'     => filemtime($ruta),
            ];
        }
    }
    // Ordenar de más reciente a más antiguo
    usort($respaldos, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
}

function calcularTamanioCarpeta(string $dir): int {
    $size = 0;
    if (!is_dir($dir)) return 0;
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if ($file->isFile()) $size += $file->getSize();
    }
    return $size;
}

function formatBytes(int $bytes): string {
    if ($bytes === 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB'];
    $i = (int)floor(log($bytes) / log($k));
    return round($bytes / ($k ** $i), 2) . ' ' . $sizes[$i];
}

// Agrupar por fecha
$por_fecha = [];
foreach ($respaldos as $r) {
    $por_fecha[$r['fecha']][] = $r;
}

// Estadísticas globales
$total_respaldos = count($respaldos);
$ultimo = $respaldos[0] ?? null;
$ahora  = time();
$minutos_desde_ultimo = $ultimo ? (int)(($ahora - $ultimo['timestamp']) / 60) : null;

// Detectar gaps: para cada fecha con respaldos, verificar horas faltantes entre primera y última
$total_gaps = 0;
foreach ($por_fecha as $fecha => $lista_horas) {
    $horas_presentes = array_column($lista_horas, 'hora');
    sort($horas_presentes);
    if (count($horas_presentes) >= 2) {
        $rango = range($horas_presentes[0], $horas_presentes[count($horas_presentes) - 1]);
        $total_gaps += count(array_diff($rango, $horas_presentes));
    }
}

// Tamaño total
$tamano_total = array_sum(array_column($respaldos, 'tamanio'));

// Calcular estado del último respaldo
$estado_ultimo = 'sin_datos';
if ($ultimo) {
    if ($minutos_desde_ultimo <= 70) $estado_ultimo = 'ok';
    elseif ($minutos_desde_ultimo <= 180) $estado_ultimo = 'advertencia';
    else $estado_ultimo = 'critico';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIA-LAB · Monitoreo de Respaldos</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a5276;
            --primary-light: #2e86c1;
            --primary-gradient: linear-gradient(135deg, #1a5276 0%, #2e86c1 100%);
            --success: #1e8449;
            --success-light: #27ae60;
            --warning: #d68910;
            --danger: #c0392b;
            --bg: #f0f4f8;
            --card: #ffffff;
            --text: #2d3748;
            --text-muted: #718096;
            --border: #e2e8f0;
            --radius: 12px;
            --shadow: 0 4px 20px rgba(0,0,0,0.08);
            --spacing: 1.5rem;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        /* ── Header ─────────────────────────────────────── */
        header {
            background: var(--primary-gradient);
            color: #fff;
            padding: 1.5rem 2rem;
            display: flex; flex-wrap: wrap;
            justify-content: space-between; align-items: center; gap: 1rem;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
        }
        header h1 { font-size: clamp(1.4rem, 3vw, 2rem); font-weight: 700; display: flex; align-items: center; gap: .6rem; }
        header p { font-size: .95rem; opacity: .85; margin-top: .25rem; }
        .header-actions { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; }
        .btn-header {
            padding: .55rem 1.1rem; border-radius: 8px; border: none; cursor: pointer;
            font-size: .9rem; font-weight: 600; display: flex; align-items: center; gap: .4rem;
            transition: .2s;
        }
        .btn-refresh { background: rgba(255,255,255,.2); color: #fff; }
        .btn-refresh:hover { background: rgba(255,255,255,.35); }
        .btn-logout  { background: rgba(220,53,69,.8);   color: #fff; }
        .btn-logout:hover { background: #c0392b; }
        #reloj { font-size: .95rem; opacity: .9; }

        /* ── Container ──────────────────────────────────── */
        .container { max-width: 1400px; margin: 0 auto; padding: 1.5rem 2rem; }

        /* ── Alerta directorio ──────────────────────────── */
        .alerta-dir {
            background: #fff3cd; border: 1px solid #ffc107; border-radius: var(--radius);
            padding: 1rem 1.25rem; margin-bottom: 1.5rem;
            display: flex; align-items: center; gap: .75rem; color: #856404;
        }
        .alerta-dir.error { background: #f8d7da; border-color: #f5c6cb; color: #721c24; }

        /* ── Tarjetas de resumen ────────────────────────── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem; margin-bottom: 1.5rem;
        }
        .stat-card {
            background: var(--card); border-radius: var(--radius);
            padding: 1.25rem 1.5rem; box-shadow: var(--shadow);
            display: flex; align-items: center; gap: 1rem;
            border-left: 4px solid var(--primary-light);
            transition: transform .2s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card.ok     { border-left-color: var(--success); }
        .stat-card.warn   { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger);  }
        .stat-icon { font-size: 2rem; width: 48px; text-align: center; }
        .stat-icon.ok     { color: var(--success); }
        .stat-icon.warn   { color: var(--warning); }
        .stat-icon.danger { color: var(--danger);  }
        .stat-icon.blue   { color: var(--primary-light); }
        .stat-info span   { font-size: .82rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; }
        .stat-info strong { display: block; font-size: 1.5rem; font-weight: 700; line-height: 1.2; }

        /* ── Filtro de fecha ────────────────────────────── */
        .filtros-card {
            background: var(--card); border-radius: var(--radius); padding: 1rem 1.5rem;
            box-shadow: var(--shadow); margin-bottom: 1.5rem;
            display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
        }
        .filtros-card label { font-weight: 600; font-size: .9rem; }
        .filtros-card select, .filtros-card input {
            padding: .5rem .85rem; border: 1px solid var(--border); border-radius: 8px;
            font-size: .9rem; font-family: inherit; background: #fff;
        }
        .btn-filtrar {
            padding: .5rem 1.1rem; background: var(--primary-gradient); color: #fff;
            border: none; border-radius: 8px; font-size: .9rem; font-weight: 600;
            cursor: pointer; display: flex; align-items: center; gap: .4rem;
        }
        .btn-filtrar:hover { opacity: .9; }

        /* ── Bloques por fecha ──────────────────────────── */
        .fecha-bloque { margin-bottom: 2rem; }
        .fecha-header {
            background: var(--card); border-radius: var(--radius) var(--radius) 0 0;
            padding: .9rem 1.25rem; border-bottom: 2px solid var(--primary-light);
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: var(--shadow);
        }
        .fecha-header h2 { font-size: 1.05rem; font-weight: 700; display: flex; align-items: center; gap: .5rem; }
        .badge {
            padding: .25rem .65rem; border-radius: 20px; font-size: .78rem; font-weight: 600;
        }
        .badge-ok     { background: #d4edda; color: #155724; }
        .badge-warn   { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-blue   { background: #d1ecf1; color: #0c5460; }

        /* ── Grilla de horas ────────────────────────────── */
        .horas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: .75rem; padding: 1rem;
            background: var(--card);
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--shadow);
        }
        .hora-card {
            border-radius: 8px; padding: .75rem 1rem;
            display: flex; flex-direction: column; gap: .3rem;
            border: 1px solid transparent; transition: .2s;
            cursor: default;
        }
        .hora-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        .hora-card.presente {
            background: #f0fff4; border-color: #9ae6b4;
        }
        .hora-card.gap {
            background: #fff5f5; border-color: #fed7d7; opacity: .7;
        }
        .hora-card .hora-titulo {
            font-size: 1.1rem; font-weight: 700;
            display: flex; align-items: center; gap: .4rem;
        }
        .hora-card.presente .hora-titulo { color: var(--success); }
        .hora-card.gap     .hora-titulo { color: var(--danger); }
        .hora-card .hora-meta { font-size: .75rem; color: var(--text-muted); }
        .hora-card .hora-size { font-size: .8rem; font-weight: 600; color: var(--text-muted); }
        .hora-card .hora-nombre { font-size: .72rem; color: #a0aec0; word-break: break-all; }

        /* ── Línea de tiempo compacta ───────────────────── */
        .timeline-bar {
            display: flex; gap: 3px; flex-wrap: wrap;
            padding: .5rem 0; margin-top: .5rem;
        }
        .tl-dot {
            width: 28px; height: 28px; border-radius: 4px;
            display: flex; align-items: center; justify-content: center;
            font-size: .65rem; font-weight: 700; cursor: default;
            title: '';
        }
        .tl-dot.ok  { background: #27ae60; color: #fff; }
        .tl-dot.gap { background: #e74c3c; color: #fff; opacity: .6; }
        .tl-dot.na  { background: #e2e8f0; color: #a0aec0; }

        /* ── Sin datos ──────────────────────────────────── */
        .sin-datos {
            text-align: center; padding: 3rem; color: var(--text-muted);
            background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow);
        }
        .sin-datos i { font-size: 3rem; margin-bottom: 1rem; display: block; opacity: .4; }

        /* ── Tabla de últimos ───────────────────────────── */
        .tabla-card { background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; margin-bottom: 2rem; }
        .tabla-card h3 { padding: 1rem 1.5rem; font-size: 1rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: .5rem; }
        table { width: 100%; border-collapse: collapse; }
        thead th { background: #f8fafc; padding: .75rem 1rem; text-align: left; font-size: .82rem; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); border-bottom: 1px solid var(--border); }
        tbody td { padding: .75rem 1rem; font-size: .88rem; border-bottom: 1px solid var(--border); }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: #f8fafc; }
        .ico-ok     { color: var(--success); }
        .ico-warn   { color: var(--warning); }
        .ico-danger { color: var(--danger); }

        /* ── Ruta config ────────────────────────────────── */
        .config-ruta {
            background: #edf2f7; border-radius: 8px; padding: .6rem 1rem;
            font-family: monospace; font-size: .85rem; color: #4a5568;
            display: flex; align-items: center; gap: .5rem;
            margin-bottom: 1.5rem; border: 1px solid #cbd5e0;
        }

        @media (max-width: 768px) {
            .container { padding: 1rem; }
            header { padding: 1rem; }
            .horas-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); }
        }
    </style>
</head>
<body>

<header>
    <div>
        <h1><i class="fas fa-database"></i> Monitoreo de Respaldos</h1>
        <p>BD: <strong>produccion_quiebras</strong> · Directorio: <code><?= htmlspecialchars($BACKUP_DIR) ?></code></p>
    </div>
    <div class="header-actions">
        <span id="reloj"><i class="fas fa-clock"></i> --:--:--</span>
        <button class="btn-header btn-refresh" onclick="location.reload()">
            <i class="fas fa-sync-alt"></i> Actualizar
        </button>
        <a href="login_admin.php" class="btn-header btn-logout">
            <i class="fas fa-sign-out-alt"></i> Salir
        </a>
    </div>
</header>

<div class="container">

    <?php if (!$dir_existe): ?>
    <div class="alerta-dir error">
        <i class="fas fa-exclamation-triangle fa-lg"></i>
        <div>
            <strong>Directorio no encontrado:</strong> <code><?= htmlspecialchars($BACKUP_DIR) ?></code><br>
            <small>Verifica la ruta o define la variable de entorno <code>BACKUP_PATH</code> con la ruta correcta.</small>
        </div>
    </div>
    <?php elseif ($total_respaldos === 0): ?>
    <div class="alerta-dir">
        <i class="fas fa-info-circle fa-lg"></i>
        <div>
            <strong>Directorio encontrado pero sin respaldos.</strong>
            Se buscan archivos con el patrón: <code>produccion_quiebras_YYYY-MM-DD_HH-00</code>
        </div>
    </div>
    <?php endif; ?>

    <!-- Ruta configurada -->
    <div class="config-ruta">
        <i class="fas fa-folder-open" style="color:#3182ce"></i>
        <span>Directorio supervisado: <strong><?= htmlspecialchars($BACKUP_DIR) ?></strong></span>
        <span style="margin-left:auto;color:#a0aec0;font-size:.78rem">
            PHP_OS: <?= PHP_OS_FAMILY ?> · PHP: <?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?>
        </span>
    </div>

    <!-- Tarjetas de resumen -->
    <div class="stats-grid">
        <!-- Total respaldos -->
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-archive"></i></div>
            <div class="stat-info">
                <span>Total Respaldos</span>
                <strong><?= $total_respaldos ?></strong>
            </div>
        </div>
        <!-- Último respaldo -->
        <div class="stat-card <?= $estado_ultimo === 'ok' ? 'ok' : ($estado_ultimo === 'advertencia' ? 'warn' : ($estado_ultimo === 'critico' ? 'danger' : '')) ?>">
            <div class="stat-icon <?= $estado_ultimo === 'ok' ? 'ok' : ($estado_ultimo === 'advertencia' ? 'warn' : 'danger') ?>">
                <i class="fas fa-<?= $estado_ultimo === 'ok' ? 'check-circle' : ($estado_ultimo === 'advertencia' ? 'exclamation-circle' : 'times-circle') ?>"></i>
            </div>
            <div class="stat-info">
                <span>Último Respaldo</span>
                <strong>
                    <?php if ($ultimo): ?>
                        <?= $ultimo['fecha'] ?> <?= $ultimo['hora_fmt'] ?>
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </strong>
            </div>
        </div>
        <!-- Antigüedad último -->
        <div class="stat-card <?= $estado_ultimo === 'ok' ? 'ok' : ($estado_ultimo === 'advertencia' ? 'warn' : 'danger') ?>">
            <div class="stat-icon <?= $estado_ultimo === 'ok' ? 'ok' : ($estado_ultimo === 'advertencia' ? 'warn' : 'danger') ?>">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <div class="stat-info">
                <span>Hace (minutos)</span>
                <strong>
                    <?php if ($minutos_desde_ultimo !== null): ?>
                        <?= $minutos_desde_ultimo > 1440
                            ? round($minutos_desde_ultimo/1440,1) . ' días'
                            : ($minutos_desde_ultimo > 60
                                ? round($minutos_desde_ultimo/60,1) . ' h'
                                : $minutos_desde_ultimo . ' min') ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </strong>
            </div>
        </div>
        <!-- Gaps detectados -->
        <div class="stat-card <?= $total_gaps > 0 ? 'danger' : 'ok' ?>">
            <div class="stat-icon <?= $total_gaps > 0 ? 'danger' : 'ok' ?>">
                <i class="fas fa-<?= $total_gaps > 0 ? 'exclamation-triangle' : 'shield-alt' ?>"></i>
            </div>
            <div class="stat-info">
                <span>Horas Faltantes</span>
                <strong><?= $total_gaps ?></strong>
            </div>
        </div>
        <!-- Fechas cubiertas -->
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-calendar-alt"></i></div>
            <div class="stat-info">
                <span>Fechas Cubiertas</span>
                <strong><?= count($por_fecha) ?></strong>
            </div>
        </div>
        <!-- Tamaño total -->
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-hdd"></i></div>
            <div class="stat-info">
                <span>Tamaño Total</span>
                <strong><?= formatBytes($tamano_total) ?></strong>
            </div>
        </div>
    </div>

    <?php if ($total_respaldos > 0): ?>

    <!-- ── Tabla últimos 10 respaldos ─────────────────── -->
    <div class="tabla-card">
        <h3><i class="fas fa-list-ul" style="color:var(--primary-light)"></i> Últimos <?= min(10, $total_respaldos) ?> Respaldos</h3>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nombre</th>
                    <th>Fecha</th>
                    <th>Hora</th>
                    <th>Tipo</th>
                    <th>Tamaño</th>
                    <th>Modificado</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($respaldos, 0, 10) as $i => $r):
                    $mins_atras = (int)(($ahora - $r['timestamp']) / 60);
                    $estado_r = $mins_atras <= 70 ? 'ok' : ($mins_atras <= 180 ? 'warn' : 'ok');
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><code style="font-size:.8rem"><?= htmlspecialchars($r['nombre']) ?></code></td>
                    <td><?= htmlspecialchars($r['fecha']) ?></td>
                    <td><strong><?= $r['hora_fmt'] ?></strong></td>
                    <td>
                        <?php if ($r['tipo'] === 'carpeta'): ?>
                            <i class="fas fa-folder ico-warn"></i> Carpeta
                        <?php else: ?>
                            <i class="fas fa-file-archive ico-ok"></i> Archivo
                        <?php endif; ?>
                    </td>
                    <td><?= formatBytes($r['tamanio']) ?></td>
                    <td><?= date('d/m/Y H:i', $r['mtime']) ?></td>
                    <td>
                        <?php if ($i === 0): ?>
                            <span class="badge badge-ok"><i class="fas fa-check"></i> Más reciente</span>
                        <?php else: ?>
                            <span class="badge badge-blue"><i class="fas fa-history"></i> Histórico</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Bloques por fecha ───────────────────────────── -->
    <h2 style="font-size:1rem;color:var(--text-muted);margin-bottom:1rem;text-transform:uppercase;letter-spacing:.5px">
        <i class="fas fa-calendar-week"></i> Detalle por Fecha
    </h2>

    <?php foreach ($por_fecha as $fecha => $lista):
        $horas_presentes = array_column($lista, 'hora');
        sort($horas_presentes);
        $horas_por_valor = [];
        foreach ($lista as $r) $horas_por_valor[$r['hora']] = $r;

        $gaps_fecha = 0;
        if (count($horas_presentes) >= 2) {
            $rango = range($horas_presentes[0], $horas_presentes[count($horas_presentes)-1]);
            $gaps_fecha = count(array_diff($rango, $horas_presentes));
        }
        // Formatear fecha para mostrar
        $dt = DateTime::createFromFormat('Y-m-d', $fecha);
        $fecha_display = $dt ? $dt->format('d/m/Y') . ' — ' . strftime_compat($dt) : $fecha;
    ?>
    <div class="fecha-bloque">
        <div class="fecha-header">
            <h2>
                <i class="fas fa-calendar-day" style="color:var(--primary-light)"></i>
                <?= htmlspecialchars($fecha) ?>
                &nbsp;<small style="font-weight:400;color:var(--text-muted)"><?= $dt ? $dt->format('d/m/Y') : '' ?></small>
            </h2>
            <div style="display:flex;gap:.5rem;align-items:center">
                <span class="badge badge-blue"><?= count($lista) ?> respaldo(s)</span>
                <?php if ($gaps_fecha > 0): ?>
                    <span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i> <?= $gaps_fecha ?> gap(s)</span>
                <?php else: ?>
                    <span class="badge badge-ok"><i class="fas fa-check-circle"></i> Sin gaps</span>
                <?php endif; ?>
            </div>
        </div>
        <!-- Mapa de horas 00–23 -->
        <div style="background:var(--card);padding:.75rem 1rem;border-bottom:1px solid var(--border)">
            <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:.4rem"><i class="fas fa-map"></i> Mapa de horas (00–23):</div>
            <div class="timeline-bar">
                <?php for ($h = 0; $h <= 23; $h++): ?>
                    <?php $label = str_pad((string)$h, 2, '0', STR_PAD_LEFT); ?>
                    <?php if (in_array($h, $horas_presentes)): ?>
                        <div class="tl-dot ok" title="<?= $fecha ?> <?= $label ?>:00 ✓"><?= $label ?></div>
                    <?php elseif (count($horas_presentes) >= 1 && $h >= min($horas_presentes) && $h <= max($horas_presentes)): ?>
                        <div class="tl-dot gap" title="<?= $fecha ?> <?= $label ?>:00 ✗ FALTANTE"><?= $label ?></div>
                    <?php else: ?>
                        <div class="tl-dot na" title="<?= $label ?>:00"><?= $label ?></div>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            <div style="font-size:.72rem;color:var(--text-muted);margin-top:.3rem;display:flex;gap:1rem">
                <span><span style="background:#27ae60;color:#fff;padding:1px 5px;border-radius:3px">HH</span> Presente</span>
                <span><span style="background:#e74c3c;color:#fff;padding:1px 5px;border-radius:3px;opacity:.7">HH</span> Faltante (gap)</span>
                <span><span style="background:#e2e8f0;color:#a0aec0;padding:1px 5px;border-radius:3px">HH</span> Fuera de rango</span>
            </div>
        </div>
        <!-- Tarjetas de cada hora -->
        <div class="horas-grid">
            <?php
            // Mostrar solo los que existen + los gaps dentro del rango
            $rango_mostrar = [];
            if (!empty($horas_presentes)) {
                $rango_mostrar = range(min($horas_presentes), max($horas_presentes));
            }
            sort($rango_mostrar);
            foreach ($rango_mostrar as $h):
                $label = str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00';
                $existe = in_array($h, $horas_presentes);
                $r = $horas_por_valor[$h] ?? null;
            ?>
            <div class="hora-card <?= $existe ? 'presente' : 'gap' ?>">
                <div class="hora-titulo">
                    <i class="fas fa-<?= $existe ? 'check-circle' : 'times-circle' ?>"></i>
                    <?= $label ?>
                </div>
                <?php if ($existe && $r): ?>
                    <div class="hora-size"><?= formatBytes($r['tamanio']) ?></div>
                    <div class="hora-meta"><i class="fas fa-<?= $r['tipo'] === 'carpeta' ? 'folder' : 'file' ?>"></i> <?= $r['tipo'] ?></div>
                    <div class="hora-nombre"><?= htmlspecialchars($r['nombre']) ?></div>
                <?php else: ?>
                    <div class="hora-meta" style="color:#fc8181"><i class="fas fa-exclamation-triangle"></i> Respaldo faltante</div>
                    <div class="hora-nombre"><?= $PREFIJO . $fecha . '_' . str_pad((string)$h, 2, '0', STR_PAD_LEFT) . '-00' ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php else: ?>
    <div class="sin-datos">
        <i class="fas fa-inbox"></i>
        <h3>No se encontraron respaldos</h3>
        <p style="margin-top:.5rem">
            Patrón buscado: <code><?= $PREFIJO ?>YYYY-MM-DD_HH-00</code><br>
            En el directorio: <code><?= htmlspecialchars($BACKUP_DIR) ?></code>
        </p>
    </div>
    <?php endif; ?>

</div><!-- /container -->

<footer style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.82rem;border-top:1px solid var(--border);margin-top:2rem">
    SIA-LAB · Monitoreo de Respaldos · Actualizado: <?= date('d/m/Y H:i:s') ?> (GT)
</footer>

<script>
// Reloj en tiempo real
function actualizarReloj() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2,'0');
    const m = String(now.getMinutes()).padStart(2,'0');
    const s = String(now.getSeconds()).padStart(2,'0');
    document.getElementById('reloj').innerHTML =
        `<i class="fas fa-clock"></i> ${h}:${m}:${s}`;
}
actualizarReloj();
setInterval(actualizarReloj, 1000);

// Auto-refresh cada 5 minutos
setTimeout(() => location.reload(), 5 * 60 * 1000);
</script>

</body>
</html>
<?php
// Helper: nombre del día en español
function strftime_compat(DateTime $dt): string {
    $dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    return $dias[(int)$dt->format('w')];
}
?>
