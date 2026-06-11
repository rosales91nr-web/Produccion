<?php
/**
 * Dashboard Monitoreo de Respaldos — BD grande (+1 GB)
 * Patrón: produccion_quiebras_YYYY-MM-DD_HH-00
 * @author Nestor Rosales | Rosalesdev91
 */

declare(strict_types=1);
session_start();

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
if (PHP_OS_FAMILY === 'Windows') {
    $BACKUP_DIR = getenv('BACKUP_PATH') ?: 'C:\\backups';
} else {
    $BACKUP_DIR = getenv('BACKUP_PATH') ?: 'C:\\backups';
}

$PREFIJO = 'produccion_quiebras_';

// ─── HELPERS ──────────────────────────────────────────────────────────────────

/**
 * Calcula tamaño de forma rápida: usa 'du' en Linux o dir en Windows.
 * Para archivos individuales usa filesize(). Nunca hace recursión PHP en +1 GB.
 */
function tamanioCarpetaRapido(string $ruta): int {
    if (!file_exists($ruta)) return 0;
    if (!is_dir($ruta)) return (int)filesize($ruta);

    if (PHP_OS_FAMILY !== 'Windows') {
        // Linux/macOS: du -sb da bytes totales sin recorrer en PHP
        $out = shell_exec('du -sb ' . escapeshellarg($ruta) . ' 2>/dev/null');
        if ($out && preg_match('/^(\d+)/', trim($out), $m)) return (int)$m[1];
    } else {
        // Windows PowerShell fallback
        $ps = 'powershell -NoProfile -Command "(Get-ChildItem -Recurse -Force \'' 
            . addslashes($ruta) . '\' | Measure-Object -Property Length -Sum).Sum"';
        $out = shell_exec($ps . ' 2>NUL');
        if ($out && is_numeric(trim($out))) return (int)trim($out);
    }

    // Último recurso: solo el tamaño del puntero (no exacto, pero rápido)
    return 0;
}

function formatGB(int $bytes, int $dec = 2): string {
    if ($bytes === 0) return '0 B';
    $units = ['B','KB','MB','GB','TB'];
    $i = (int)floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    return number_format($bytes / (1024 ** $i), $dec) . ' ' . $units[$i];
}

function diasSemana(DateTime $dt): string {
    $dias = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
    return $dias[(int)$dt->format('w')];
}

// ─── ESCANEO ──────────────────────────────────────────────────────────────────
$respaldos   = [];
$dir_existe  = is_dir($BACKUP_DIR);

if ($dir_existe) {
    $items = @scandir($BACKUP_DIR);
    if ($items) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (!preg_match(
                '/^' . preg_quote($PREFIJO, '/') . '(\d{4}-\d{2}-\d{2})_(\d{2})-00$/',
                $item, $m
            )) continue;

            $ruta    = $BACKUP_DIR . DIRECTORY_SEPARATOR . $item;
            $fecha   = $m[1];
            $hora    = (int)$m[2];
            $tamanio = tamanioCarpetaRapido($ruta);
            $mtime   = (int)@filemtime($ruta);

            $respaldos[] = [
                'nombre'    => $item,
                'fecha'     => $fecha,
                'hora'      => $hora,
                'hora_fmt'  => str_pad((string)$hora, 2, '0', STR_PAD_LEFT) . ':00',
                'tipo'      => is_dir($ruta) ? 'carpeta' : 'archivo',
                'tamanio'   => $tamanio,
                'timestamp' => (int)strtotime($fecha . ' ' . str_pad((string)$hora, 2, '0', STR_PAD_LEFT) . ':00:00'),
                'mtime'     => $mtime,
            ];
        }
    }
    usort($respaldos, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
}

// ─── ESTADÍSTICAS BASE ────────────────────────────────────────────────────────
$total_respaldos = count($respaldos);
$ultimo          = $respaldos[0] ?? null;
$ahora           = time();
$mins_ultimo     = $ultimo ? (int)(($ahora - $ultimo['timestamp']) / 60) : null;

// Agrupar por fecha
$por_fecha = [];
foreach ($respaldos as $r) $por_fecha[$r['fecha']][] = $r;

// Gaps
$total_gaps = 0;
foreach ($por_fecha as $lista) {
    $horas = array_column($lista, 'hora');
    sort($horas);
    if (count($horas) >= 2) {
        $rango = range($horas[0], $horas[count($horas)-1]);
        $total_gaps += count(array_diff($rango, $horas));
    }
}

// Tamaños por fecha (para crecimiento)
$tamano_por_fecha = [];
foreach ($por_fecha as $fecha => $lista) {
    $tamano_por_fecha[$fecha] = array_sum(array_column($lista, 'tamanio'));
}

$tamano_total = array_sum(array_column($respaldos, 'tamanio'));

// Tamaño promedio por respaldo (solo los que tienen tamaño > 0)
$respaldos_con_size = array_filter($respaldos, fn($r) => $r['tamanio'] > 0);
$promedio_respaldo  = count($respaldos_con_size) > 0
    ? (int)(array_sum(array_column($respaldos_con_size, 'tamanio')) / count($respaldos_con_size))
    : 0;

// Consumo estimado por día (24 respaldos × promedio)
$consumo_dia   = $promedio_respaldo * 24;
$consumo_semana = $consumo_dia * 7;
$consumo_mes   = $consumo_dia * 30;

// Tasa de crecimiento: diferencia de tamaño entre última y penúltima fecha
$fechas_ordenadas = array_keys($tamano_por_fecha);
rsort($fechas_ordenadas);
$crecimiento_bytes = 0;
$crecimiento_pct   = 0.0;
if (count($fechas_ordenadas) >= 2) {
    $t_hoy   = $tamano_por_fecha[$fechas_ordenadas[0]] ?? 0;
    $t_ayer  = $tamano_por_fecha[$fechas_ordenadas[1]] ?? 0;
    if ($t_ayer > 0) {
        $crecimiento_bytes = $t_hoy - $t_ayer;
        $crecimiento_pct   = round(($crecimiento_bytes / $t_ayer) * 100, 1);
    }
}

// Espacio en disco
$disco_libre  = @disk_free_space($BACKUP_DIR) ?: 0;
$disco_total  = @disk_total_space($BACKUP_DIR) ?: 0;
$disco_usado  = $disco_total - $disco_libre;
$disco_pct    = $disco_total > 0 ? round(($disco_usado / $disco_total) * 100, 1) : 0;
$dias_restantes = ($consumo_dia > 0 && $disco_libre > 0)
    ? (int)floor($disco_libre / $consumo_dia)
    : null;

// Detección de anomalías de tamaño (respaldo < 30% del promedio = sospechoso)
$umbral_anomalia = (int)($promedio_respaldo * 0.30);
$respaldos_anomalos = $promedio_respaldo > 0
    ? array_filter($respaldos, fn($r) => $r['tamanio'] > 0 && $r['tamanio'] < $umbral_anomalia)
    : [];

// Estado del último respaldo
$estado_ultimo = 'sin_datos';
if ($ultimo) {
    if ($mins_ultimo <= 70)       $estado_ultimo = 'ok';
    elseif ($mins_ultimo <= 180)  $estado_ultimo = 'advertencia';
    else                          $estado_ultimo = 'critico';
}

// Estado del disco
$estado_disco = 'ok';
if ($disco_pct >= 90)      $estado_disco = 'critico';
elseif ($disco_pct >= 75)  $estado_disco = 'advertencia';

// Datos para chart de tamaño por fecha (últimos 7 días)
$chart_labels = [];
$chart_data   = [];
foreach (array_slice($fechas_ordenadas, 0, 7) as $f) {
    $chart_labels[] = $f;
    $chart_data[]   = round(($tamano_por_fecha[$f] ?? 0) / (1024**3), 3); // GB
}
$chart_labels = array_reverse($chart_labels);
$chart_data   = array_reverse($chart_data);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIA-LAB · Respaldos BD</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.3.0/chart.umd.min.js"></script>
    <style>
        :root {
            --primary: #1a5276; --primary-light: #2e86c1;
            --gradient: linear-gradient(135deg,#1a5276,#2e86c1);
            --ok: #1e8449; --ok-bg: #d4edda; --ok-txt: #155724;
            --warn: #d68910; --warn-bg: #fff3cd; --warn-txt: #856404;
            --danger: #c0392b; --danger-bg: #f8d7da; --danger-txt: #721c24;
            --bg: #f0f4f8; --card: #fff; --text: #2d3748; --muted: #718096;
            --border: #e2e8f0; --radius: 12px; --shadow: 0 4px 20px rgba(0,0,0,.08);
        }
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}

        /* Header */
        header{background:var(--gradient);color:#fff;padding:1.25rem 2rem;display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.75rem;box-shadow:0 2px 12px rgba(0,0,0,.15)}
        header h1{font-size:clamp(1.2rem,2.5vw,1.8rem);font-weight:700;display:flex;align-items:center;gap:.5rem}
        header p{font-size:.88rem;opacity:.85;margin-top:.2rem}
        .hdr-actions{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}
        .btn-hdr{padding:.45rem 1rem;border-radius:8px;border:none;cursor:pointer;font-size:.85rem;font-weight:600;display:flex;align-items:center;gap:.35rem;transition:.2s;text-decoration:none}
        .btn-refresh{background:rgba(255,255,255,.2);color:#fff}.btn-refresh:hover{background:rgba(255,255,255,.35)}
        .btn-logout{background:rgba(220,53,69,.8);color:#fff}.btn-logout:hover{background:#c0392b}
        #reloj{font-size:.88rem;opacity:.9}

        /* Layout */
        .container{max-width:1440px;margin:0 auto;padding:1.5rem 2rem}
        @media(max-width:768px){.container{padding:1rem}header{padding:1rem}}

        /* Alerta crítica global */
        .alerta{border-radius:var(--radius);padding:1rem 1.25rem;margin-bottom:1rem;display:flex;align-items:flex-start;gap:.75rem}
        .alerta.error{background:var(--danger-bg);border:1px solid #f5c6cb;color:var(--danger-txt)}
        .alerta.warn{background:var(--warn-bg);border:1px solid #ffc107;color:var(--warn-txt)}
        .alerta.info{background:#d1ecf1;border:1px solid #bee5eb;color:#0c5460}

        /* Barra de ruta */
        .ruta-bar{background:#edf2f7;border:1px solid #cbd5e0;border-radius:8px;padding:.5rem 1rem;font-family:monospace;font-size:.82rem;color:#4a5568;display:flex;align-items:center;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap}

        /* Stats grid */
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.85rem;margin-bottom:1.25rem}
        .stat-card{background:var(--card);border-radius:var(--radius);padding:1.1rem 1.3rem;box-shadow:var(--shadow);display:flex;align-items:center;gap:.85rem;border-left:4px solid var(--primary-light);transition:transform .2s}
        .stat-card:hover{transform:translateY(-2px)}
        .stat-card.ok{border-left-color:var(--ok)}.stat-card.warn{border-left-color:var(--warn)}.stat-card.danger{border-left-color:var(--danger)}
        .stat-icon{font-size:1.8rem;width:44px;text-align:center}
        .stat-icon.ok{color:var(--ok)}.stat-icon.warn{color:var(--warn)}.stat-icon.danger{color:var(--danger)}.stat-icon.blue{color:var(--primary-light)}
        .stat-info span{font-size:.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:.4px}
        .stat-info strong{display:block;font-size:1.35rem;font-weight:700;line-height:1.2}
        .stat-info small{font-size:.72rem;color:var(--muted)}

        /* Disco progress */
        .disco-card{background:var(--card);border-radius:var(--radius);padding:1.1rem 1.5rem;box-shadow:var(--shadow);margin-bottom:1.25rem}
        .disco-card h3{font-size:.9rem;font-weight:700;margin-bottom:.75rem;display:flex;align-items:center;gap:.4rem}
        .disco-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:.75rem}
        .disco-item{text-align:center}
        .disco-item .val{font-size:1.4rem;font-weight:700}
        .disco-item .lbl{font-size:.75rem;color:var(--muted);text-transform:uppercase}
        .progress-bar{height:16px;background:#e2e8f0;border-radius:8px;overflow:hidden;margin-top:.5rem}
        .progress-fill{height:100%;border-radius:8px;transition:width .6s}
        .progress-fill.ok{background:linear-gradient(90deg,#27ae60,#2ecc71)}
        .progress-fill.warn{background:linear-gradient(90deg,#f39c12,#e67e22)}
        .progress-fill.danger{background:linear-gradient(90deg,#e74c3c,#c0392b)}
        .progress-labels{display:flex;justify-content:space-between;font-size:.72rem;color:var(--muted);margin-top:.2rem}

        /* Proyección */
        .proyeccion-card{background:var(--card);border-radius:var(--radius);padding:1.1rem 1.5rem;box-shadow:var(--shadow);margin-bottom:1.25rem}
        .proyeccion-card h3{font-size:.9rem;font-weight:700;margin-bottom:.75rem;display:flex;align-items:center;gap:.4rem}
        .proy-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem}
        .proy-item{background:#f8fafc;border-radius:8px;padding:.75rem;border:1px solid var(--border);text-align:center}
        .proy-item .pval{font-size:1.3rem;font-weight:700;color:var(--primary-light)}
        .proy-item .plbl{font-size:.72rem;color:var(--muted);text-transform:uppercase;margin-top:.2rem}

        /* Chart */
        .chart-card{background:var(--card);border-radius:var(--radius);padding:1.1rem 1.5rem;box-shadow:var(--shadow);margin-bottom:1.25rem}
        .chart-card h3{font-size:.9rem;font-weight:700;margin-bottom:.75rem;display:flex;align-items:center;gap:.4rem}
        .chart-wrap{position:relative;height:200px}

        /* Tabla */
        .tabla-card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:1.5rem}
        .tabla-card h3{padding:.9rem 1.25rem;font-size:.9rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.4rem}
        table{width:100%;border-collapse:collapse}
        thead th{background:#f8fafc;padding:.65rem .9rem;text-align:left;font-size:.75rem;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);border-bottom:1px solid var(--border)}
        tbody td{padding:.65rem .9rem;font-size:.85rem;border-bottom:1px solid var(--border)}
        tbody tr:last-child td{border-bottom:none}
        tbody tr:hover td{background:#f8fafc}
        .anomalia-row td{background:#fff5f5 !important}

        /* Badge */
        .badge{padding:.2rem .55rem;border-radius:20px;font-size:.72rem;font-weight:600}
        .b-ok{background:var(--ok-bg);color:var(--ok-txt)}.b-warn{background:var(--warn-bg);color:var(--warn-txt)}.b-danger{background:var(--danger-bg);color:var(--danger-txt)}.b-blue{background:#d1ecf1;color:#0c5460}

        /* Fecha bloques */
        .fecha-bloque{margin-bottom:1.5rem}
        .fecha-header{background:var(--card);border-radius:var(--radius) var(--radius) 0 0;padding:.8rem 1.1rem;border-bottom:2px solid var(--primary-light);display:flex;align-items:center;justify-content:space-between;box-shadow:var(--shadow);flex-wrap:wrap;gap:.5rem}
        .fecha-header h2{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:.4rem}
        .tl-bar{display:flex;gap:2px;flex-wrap:wrap;padding:.5rem 1rem;background:var(--card);border-bottom:1px solid var(--border)}
        .tl-dot{width:26px;height:26px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:700;cursor:default}
        .tl-dot.ok{background:#27ae60;color:#fff}.tl-dot.gap{background:#e74c3c;color:#fff;opacity:.65}.tl-dot.na{background:#e2e8f0;color:#a0aec0}
        .horas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:.6rem;padding:.85rem;background:var(--card);border-radius:0 0 var(--radius) var(--radius);box-shadow:var(--shadow)}
        .hora-card{border-radius:8px;padding:.65rem .85rem;display:flex;flex-direction:column;gap:.25rem;border:1px solid transparent;transition:.2s}
        .hora-card:hover{transform:translateY(-2px);box-shadow:0 4px 10px rgba(0,0,0,.08)}
        .hora-card.presente{background:#f0fff4;border-color:#9ae6b4}.hora-card.gap{background:#fff5f5;border-color:#fed7d7;opacity:.75}
        .hora-card.anomalia{background:#fffbeb;border-color:#f6e05e}
        .hora-titulo{font-size:1rem;font-weight:700;display:flex;align-items:center;gap:.3rem}
        .hora-card.presente .hora-titulo{color:var(--ok)}.hora-card.gap .hora-titulo{color:var(--danger)}.hora-card.anomalia .hora-titulo{color:var(--warn)}
        .hora-size{font-size:.82rem;font-weight:700;color:#2d3748}
        .hora-meta{font-size:.72rem;color:var(--muted)}
        .hora-nombre{font-size:.68rem;color:#a0aec0;word-break:break-all}

        /* Sin datos */
        .sin-datos{text-align:center;padding:3rem;color:var(--muted);background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow)}
        .sin-datos i{font-size:2.5rem;margin-bottom:.75rem;display:block;opacity:.4}

        /* Leyenda */
        .leyenda{font-size:.7rem;color:var(--muted);padding:.4rem 1rem .6rem;background:var(--card);display:flex;gap:1rem;flex-wrap:wrap}
        .leyenda span{display:flex;align-items:center;gap:.3rem}
    </style>
</head>
<body>

<header>
    <div>
        <h1><i class="fas fa-database"></i> Monitor de Respaldos BD</h1>
        <p>
            <strong>produccion_quiebras</strong> ·
            <code><?= htmlspecialchars($BACKUP_DIR) ?></code> ·
            <?= PHP_OS_FAMILY ?> / PHP <?= PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION ?>
        </p>
    </div>
    <div class="hdr-actions">
        <span id="reloj"><i class="fas fa-clock"></i></span>
        <button class="btn-hdr btn-refresh" onclick="location.reload()">
            <i class="fas fa-sync-alt"></i> Actualizar
        </button>
        <a href="login_admin.php" class="btn-hdr btn-logout">
            <i class="fas fa-sign-out-alt"></i> Salir
        </a>
    </div>
</header>

<div class="container">

<?php /* ── Alertas globales ──────────────────────────────────────── */ ?>

<?php if (!$dir_existe): ?>
<div class="alerta error">
    <i class="fas fa-exclamation-triangle fa-lg"></i>
    <div>
        <strong>Directorio no encontrado:</strong> <code><?= htmlspecialchars($BACKUP_DIR) ?></code><br>
        <small>Define la variable de entorno <code>BACKUP_PATH</code> con la ruta real del servidor de respaldos.</small>
    </div>
</div>
<?php endif; ?>

<?php if ($estado_disco === 'critico' && $disco_total > 0): ?>
<div class="alerta error">
    <i class="fas fa-hdd fa-lg"></i>
    <div>
        <strong>⚠️ DISCO CRÍTICO — <?= $disco_pct ?>% usado.</strong>
        Espacio libre: <strong><?= formatGB($disco_libre) ?></strong>.
        <?php if ($dias_restantes !== null): ?>
            Al ritmo actual, el disco se llenará en aprox. <strong><?= $dias_restantes ?> día(s)</strong>.
        <?php endif; ?>
    </div>
</div>
<?php elseif ($estado_disco === 'advertencia' && $disco_total > 0): ?>
<div class="alerta warn">
    <i class="fas fa-exclamation-circle fa-lg"></i>
    <div>
        <strong>Disco al <?= $disco_pct ?>% —</strong> libre: <?= formatGB($disco_libre) ?>.
        <?php if ($dias_restantes !== null): ?>
            Quedan ~<strong><?= $dias_restantes ?> días</strong> de capacidad.
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($estado_ultimo === 'critico' && $ultimo): ?>
<div class="alerta error">
    <i class="fas fa-times-circle fa-lg"></i>
    <div>
        <strong>Último respaldo con <?= round($mins_ultimo/60, 1) ?> horas de antigüedad.</strong>
        Se esperaba uno hace menos de 70 min. Posible fallo en el proceso de respaldo.
    </div>
</div>
<?php endif; ?>

<?php if (count($respaldos_anomalos) > 0): ?>
<div class="alerta warn">
    <i class="fas fa-compress-alt fa-lg"></i>
    <div>
        <strong><?= count($respaldos_anomalos) ?> respaldo(s) con tamaño anormalmente pequeño</strong>
        (menos del 30% del promedio de <?= formatGB($promedio_respaldo) ?>).
        Podrían estar incompletos o corruptos.
    </div>
</div>
<?php endif; ?>

<?php if ($total_gaps > 0): ?>
<div class="alerta warn">
    <i class="fas fa-clock fa-lg"></i>
    <div>
        <strong><?= $total_gaps ?> hora(s) sin respaldo detectadas.</strong>
        Con una BD de +1 GB, cada hora perdida representa ~<?= formatGB($promedio_respaldo) ?> de datos sin respaldo.
    </div>
</div>
<?php endif; ?>

    <?php /* ── Ruta ─── */ ?>
    <div class="ruta-bar">
        <i class="fas fa-folder-open" style="color:#3182ce"></i>
        <strong><?= htmlspecialchars($BACKUP_DIR) ?></strong>
        <span style="margin-left:auto;opacity:.7">Actualizado: <?= date('d/m/Y H:i:s') ?> (GT)</span>
    </div>

    <?php /* ── Tarjetas KPI ─── */ ?>
    <div class="stats-grid">

        <div class="stat-card <?= $estado_ultimo === 'ok' ? 'ok' : ($estado_ultimo === 'advertencia' ? 'warn' : 'danger') ?>">
            <div class="stat-icon <?= $estado_ultimo === 'ok' ? 'ok' : ($estado_ultimo === 'advertencia' ? 'warn' : 'danger') ?>">
                <i class="fas fa-<?= $estado_ultimo === 'ok' ? 'check-circle' : ($estado_ultimo === 'advertencia' ? 'exclamation-circle' : 'times-circle') ?>"></i>
            </div>
            <div class="stat-info">
                <span>Último Respaldo</span>
                <strong><?= $ultimo ? $ultimo['fecha'].' '.$ultimo['hora_fmt'] : 'N/A' ?></strong>
                <small><?php if ($mins_ultimo !== null): echo $mins_ultimo < 60 ? "{$mins_ultimo} min atrás" : round($mins_ultimo/60,1).' h atrás'; endif; ?></small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-archive"></i></div>
            <div class="stat-info">
                <span>Total Respaldos</span>
                <strong><?= $total_respaldos ?></strong>
                <small><?= count($por_fecha) ?> fecha(s)</small>
            </div>
        </div>

        <div class="stat-card <?= $total_gaps > 0 ? 'danger' : 'ok' ?>">
            <div class="stat-icon <?= $total_gaps > 0 ? 'danger' : 'ok' ?>">
                <i class="fas fa-<?= $total_gaps > 0 ? 'exclamation-triangle' : 'shield-alt' ?>"></i>
            </div>
            <div class="stat-info">
                <span>Horas Faltantes</span>
                <strong><?= $total_gaps ?></strong>
                <small><?= $total_gaps > 0 ? '~'.formatGB($promedio_respaldo * $total_gaps).' sin respaldo' : 'Sin gaps' ?></small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-hdd"></i></div>
            <div class="stat-info">
                <span>Tamaño Total Respaldos</span>
                <strong><?= formatGB($tamano_total) ?></strong>
                <small>Promedio: <?= formatGB($promedio_respaldo) ?>/respaldo</small>
            </div>
        </div>

        <div class="stat-card <?= $crecimiento_bytes > 0 ? 'warn' : '' ?>">
            <div class="stat-icon <?= $crecimiento_bytes > 0 ? 'warn' : 'blue' ?>">
                <i class="fas fa-<?= $crecimiento_bytes >= 0 ? 'arrow-trend-up' : 'arrow-trend-down' ?>"></i>
            </div>
            <div class="stat-info">
                <span>Crecimiento Día</span>
                <strong><?= ($crecimiento_bytes >= 0 ? '+' : '') . formatGB(abs($crecimiento_bytes)) ?></strong>
                <small><?= ($crecimiento_pct >= 0 ? '+' : '') . $crecimiento_pct ?>% vs ayer</small>
            </div>
        </div>

        <div class="stat-card <?= count($respaldos_anomalos) > 0 ? 'warn' : 'ok' ?>">
            <div class="stat-icon <?= count($respaldos_anomalos) > 0 ? 'warn' : 'ok' ?>">
                <i class="fas fa-<?= count($respaldos_anomalos) > 0 ? 'compress-alt' : 'check' ?>"></i>
            </div>
            <div class="stat-info">
                <span>Anomalías Tamaño</span>
                <strong><?= count($respaldos_anomalos) ?></strong>
                <small><?= count($respaldos_anomalos) > 0 ? 'Requieren verificación' : 'Todos normales' ?></small>
            </div>
        </div>

    </div>

    <?php /* ── Disco ─── */ ?>
    <?php if ($disco_total > 0): ?>
    <div class="disco-card">
        <h3><i class="fas fa-hdd" style="color:var(--primary-light)"></i> Disco en <?= htmlspecialchars($BACKUP_DIR[0] ?? '/') ?></h3>
        <div class="disco-row">
            <div class="disco-item">
                <div class="val" style="color:var(--danger)"><?= formatGB($disco_usado) ?></div>
                <div class="lbl">Usado</div>
            </div>
            <div class="disco-item">
                <div class="val" style="color:var(--ok)"><?= formatGB($disco_libre) ?></div>
                <div class="lbl">Libre</div>
            </div>
            <div class="disco-item">
                <div class="val" style="color:var(--primary-light)"><?= formatGB($disco_total) ?></div>
                <div class="lbl">Total</div>
            </div>
            <div class="disco-item">
                <div class="val" style="color:<?= $estado_disco==='critico'?'#e74c3c':($estado_disco==='advertencia'?'#d68910':'#27ae60') ?>"><?= $disco_pct ?>%</div>
                <div class="lbl">Ocupado</div>
            </div>
        </div>
        <div class="progress-bar">
            <div class="progress-fill <?= $estado_disco ?>" style="width:<?= min($disco_pct,100) ?>%"></div>
        </div>
        <div class="progress-labels"><span>0%</span><span>50%</span><span>100%</span></div>
    </div>
    <?php endif; ?>

    <?php /* ── Proyección de almacenamiento ─── */ ?>
    <?php if ($promedio_respaldo > 0): ?>
    <div class="proyeccion-card">
        <h3><i class="fas fa-chart-line" style="color:var(--primary-light)"></i> Proyección de Consumo
            <small style="font-weight:400;color:var(--muted);margin-left:.5rem">(basado en promedio <?= formatGB($promedio_respaldo) ?>/respaldo · 24 respaldos/día)</small>
        </h3>
        <div class="proy-grid">
            <div class="proy-item">
                <div class="pval"><?= formatGB($consumo_dia) ?></div>
                <div class="plbl">Por día</div>
            </div>
            <div class="proy-item">
                <div class="pval"><?= formatGB($consumo_semana) ?></div>
                <div class="plbl">Por semana</div>
            </div>
            <div class="proy-item">
                <div class="pval"><?= formatGB($consumo_mes) ?></div>
                <div class="plbl">Por mes (30 días)</div>
            </div>
            <?php if ($dias_restantes !== null && $disco_libre > 0): ?>
            <div class="proy-item" style="border-color:<?= $dias_restantes < 7 ? '#fc8181' : ($dias_restantes < 30 ? '#fbd38d' : '#9ae6b4') ?>">
                <div class="pval" style="color:<?= $dias_restantes < 7 ? 'var(--danger)' : ($dias_restantes < 30 ? 'var(--warn)' : 'var(--ok)') ?>"><?= $dias_restantes ?> días</div>
                <div class="plbl">Hasta llenar disco</div>
            </div>
            <?php endif; ?>
            <div class="proy-item">
                <div class="pval" style="font-size:1rem"><?= formatGB($tamano_total) ?></div>
                <div class="plbl">Acumulado actual</div>
            </div>
            <?php if ($crecimiento_pct != 0): ?>
            <div class="proy-item">
                <div class="pval" style="color:<?= $crecimiento_pct > 5 ? 'var(--warn)' : 'var(--ok)' ?>;font-size:1.1rem">
                    <?= ($crecimiento_pct >= 0 ? '+' : '') . $crecimiento_pct ?>%
                </div>
                <div class="plbl">Crecimiento día</div>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($consumo_mes > 0): ?>
        <div style="margin-top:.85rem;padding:.75rem;background:#f8fafc;border-radius:8px;font-size:.82rem;color:var(--muted);border:1px solid var(--border)">
            <i class="fas fa-lightbulb" style="color:var(--warn)"></i>
            <strong>Recomendación de retención:</strong>
            Al consumir <strong><?= formatGB($consumo_dia) ?>/día</strong>, mantener 30 días de respaldo requiere
            <strong><?= formatGB($consumo_mes) ?></strong> de espacio dedicado.
            Considera una política de retención de 7–14 días (<?= formatGB($consumo_semana) ?>–<?= formatGB($consumo_dia*14) ?>) para optimizar almacenamiento.
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php /* ── Gráfico tamaño por fecha ─── */ ?>
    <?php if (count($chart_labels) > 1): ?>
    <div class="chart-card">
        <h3><i class="fas fa-chart-bar" style="color:var(--primary-light)"></i> Tamaño total de respaldos por día (GB)</h3>
        <div class="chart-wrap"><canvas id="chartSize"></canvas></div>
    </div>
    <?php endif; ?>

    <?php if ($total_respaldos > 0): ?>

    <?php /* ── Tabla últimos respaldos ─── */ ?>
    <div class="tabla-card">
        <h3><i class="fas fa-list-ul" style="color:var(--primary-light)"></i>
            Últimos <?= min(15, $total_respaldos) ?> respaldos
            <?php if (count($respaldos_anomalos) > 0): ?>
                &nbsp;<span class="badge b-warn"><i class="fas fa-exclamation-triangle"></i> Fila amarilla = tamaño anómalo</span>
            <?php endif; ?>
        </h3>
        <table>
            <thead><tr>
                <th>#</th><th>Nombre</th><th>Fecha</th><th>Hora</th>
                <th>Tipo</th><th>Tamaño</th><th>vs Promedio</th><th>Modificado</th><th>Estado</th>
            </tr></thead>
            <tbody>
            <?php
            $anomalos_ids = array_map(fn($r) => $r['nombre'], iterator_to_array(new ArrayIterator($respaldos_anomalos)));
            foreach (array_slice($respaldos, 0, 15) as $i => $r):
                $es_anomalo = in_array($r['nombre'], $anomalos_ids);
                $pct_vs_avg = $promedio_respaldo > 0 && $r['tamanio'] > 0
                    ? round(($r['tamanio'] / $promedio_respaldo) * 100) : null;
            ?>
            <tr <?= $es_anomalo ? 'class="anomalia-row"' : '' ?>>
                <td><?= $i+1 ?></td>
                <td><code style="font-size:.75rem"><?= htmlspecialchars($r['nombre']) ?></code></td>
                <td><?= $r['fecha'] ?></td>
                <td><strong><?= $r['hora_fmt'] ?></strong></td>
                <td>
                    <?= $r['tipo'] === 'carpeta'
                        ? '<i class="fas fa-folder" style="color:var(--warn)"></i> Carpeta'
                        : '<i class="fas fa-file-archive" style="color:var(--ok)"></i> Archivo' ?>
                </td>
                <td><strong><?= formatGB($r['tamanio']) ?></strong></td>
                <td>
                    <?php if ($pct_vs_avg !== null): ?>
                        <span style="color:<?= $pct_vs_avg < 50 ? 'var(--danger)' : ($pct_vs_avg < 80 ? 'var(--warn)' : 'var(--ok)') ?>;font-weight:600">
                            <?= $pct_vs_avg ?>%
                        </span>
                        <?= $es_anomalo ? '<i class="fas fa-exclamation-triangle" style="color:var(--warn)" title="Anómalo"></i>' : '' ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= date('d/m/Y H:i', $r['mtime']) ?></td>
                <td>
                    <?php if ($i === 0): ?>
                        <span class="badge b-ok"><i class="fas fa-check"></i> Reciente</span>
                    <?php elseif ($es_anomalo): ?>
                        <span class="badge b-warn"><i class="fas fa-compress-alt"></i> Anómalo</span>
                    <?php else: ?>
                        <span class="badge b-blue"><i class="fas fa-history"></i> OK</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php /* ── Detalle por fecha ─── */ ?>
    <h2 style="font-size:.88rem;color:var(--muted);margin-bottom:.85rem;text-transform:uppercase;letter-spacing:.5px">
        <i class="fas fa-calendar-week"></i> Detalle por Fecha
    </h2>

    <?php foreach ($por_fecha as $fecha => $lista):
        $horas_presentes = array_column($lista, 'hora');
        sort($horas_presentes);
        $horas_por_valor = [];
        foreach ($lista as $r) $horas_por_valor[$r['hora']] = $r;

        $gaps_f = 0;
        if (count($horas_presentes) >= 2) {
            $rango_f = range($horas_presentes[0], $horas_presentes[count($horas_presentes)-1]);
            $gaps_f  = count(array_diff($rango_f, $horas_presentes));
        }
        $tam_fecha = $tamano_por_fecha[$fecha] ?? 0;
        $dt = DateTime::createFromFormat('Y-m-d', $fecha);
        $dia_sem = $dt ? diasSemana($dt) : '';
    ?>
    <div class="fecha-bloque">
        <div class="fecha-header">
            <h2>
                <i class="fas fa-calendar-day" style="color:var(--primary-light)"></i>
                <?= htmlspecialchars($fecha) ?>
                <?php if ($dia_sem): ?><small style="font-weight:400;color:var(--muted)">(<?= $dia_sem ?>)</small><?php endif; ?>
            </h2>
            <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
                <span class="badge b-blue"><?= count($lista) ?> respaldo(s)</span>
                <span class="badge b-blue"><i class="fas fa-hdd"></i> <?= formatGB($tam_fecha) ?></span>
                <?php if ($gaps_f > 0): ?>
                    <span class="badge b-danger"><i class="fas fa-exclamation-triangle"></i> <?= $gaps_f ?> gap(s) · ~<?= formatGB($promedio_respaldo * $gaps_f) ?> riesgo</span>
                <?php else: ?>
                    <span class="badge b-ok"><i class="fas fa-shield-alt"></i> Completo</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Mapa 00–23 -->
        <div class="tl-bar">
            <?php for ($h = 0; $h <= 23; $h++):
                $lh = str_pad((string)$h, 2, '0', STR_PAD_LEFT);
                $presente = in_array($h, $horas_presentes);
                $en_rango = !empty($horas_presentes) && $h >= min($horas_presentes) && $h <= max($horas_presentes);
            ?>
            <div class="tl-dot <?= $presente ? 'ok' : ($en_rango ? 'gap' : 'na') ?>"
                 title="<?= $fecha ?> <?= $lh ?>:00 <?= $presente ? '✓' : ($en_rango ? '✗ FALTANTE' : '') ?>">
                <?= $lh ?>
            </div>
            <?php endfor; ?>
        </div>
        <div class="leyenda">
            <span><span style="background:#27ae60;color:#fff;padding:1px 5px;border-radius:3px;font-size:.65rem">HH</span> Presente</span>
            <span><span style="background:#e74c3c;color:#fff;padding:1px 5px;border-radius:3px;font-size:.65rem;opacity:.7">HH</span> Faltante</span>
            <span><span style="background:#e2e8f0;color:#a0aec0;padding:1px 5px;border-radius:3px;font-size:.65rem">HH</span> Sin dato</span>
        </div>

        <!-- Tarjetas de horas -->
        <div class="horas-grid">
            <?php
            $rango_m = !empty($horas_presentes)
                ? range(min($horas_presentes), max($horas_presentes)) : [];
            foreach ($rango_m as $h):
                $lbl   = str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00';
                $existe = in_array($h, $horas_presentes);
                $r      = $horas_por_valor[$h] ?? null;
                $anom   = $r && $promedio_respaldo > 0 && $r['tamanio'] > 0 && $r['tamanio'] < $umbral_anomalia;
                $clase  = !$existe ? 'gap' : ($anom ? 'anomalia' : 'presente');
            ?>
            <div class="hora-card <?= $clase ?>">
                <div class="hora-titulo">
                    <i class="fas fa-<?= !$existe ? 'times-circle' : ($anom ? 'exclamation-triangle' : 'check-circle') ?>"></i>
                    <?= $lbl ?>
                </div>
                <?php if ($existe && $r): ?>
                    <div class="hora-size"><?= formatGB($r['tamanio']) ?></div>
                    <?php if ($pct_r = ($promedio_respaldo > 0 && $r['tamanio'] > 0 ? round(($r['tamanio']/$promedio_respaldo)*100) : 0)): ?>
                        <div class="hora-meta" style="color:<?= $pct_r < 50 ? 'var(--danger)' : 'var(--muted)' ?>">
                            <?= $pct_r ?>% del promedio
                        </div>
                    <?php endif; ?>
                    <div class="hora-meta"><i class="fas fa-<?= $r['tipo']==='carpeta'?'folder':'file' ?>"></i> <?= $r['tipo'] ?></div>
                    <div class="hora-nombre"><?= htmlspecialchars($r['nombre']) ?></div>
                <?php else: ?>
                    <div class="hora-meta" style="color:#fc8181"><i class="fas fa-exclamation-triangle"></i> Faltante</div>
                    <div class="hora-meta" style="color:#fc8181;font-size:.7rem">~<?= formatGB($promedio_respaldo) ?> no respaldado</div>
                    <div class="hora-nombre"><?= $PREFIJO.$fecha.'_'.str_pad((string)$h,2,'0',STR_PAD_LEFT).'-00' ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php else: ?>
    <div class="sin-datos">
        <i class="fas fa-inbox"></i>
        <h3>Sin respaldos encontrados</h3>
        <p style="margin-top:.5rem">
            Patrón: <code><?= $PREFIJO ?>YYYY-MM-DD_HH-00</code><br>
            Directorio: <code><?= htmlspecialchars($BACKUP_DIR) ?></code>
        </p>
    </div>
    <?php endif; ?>

</div>

<footer style="text-align:center;padding:1.25rem;color:var(--muted);font-size:.78rem;border-top:1px solid var(--border);margin-top:1.5rem">
    SIA-LAB · Monitor Respaldos BD · <?= date('d/m/Y H:i:s') ?> (GT)
</footer>

<script>
// Reloj
(function tick(){
    const n=new Date(),p=v=>String(v).padStart(2,'0');
    document.getElementById('reloj').innerHTML=
        `<i class="fas fa-clock"></i> ${p(n.getHours())}:${p(n.getMinutes())}:${p(n.getSeconds())}`;
    setTimeout(tick,1000);
})();

// Auto-refresh cada 5 min
setTimeout(()=>location.reload(), 5*60*1000);

<?php if (count($chart_labels) > 1): ?>
// Gráfico de tamaño por fecha
(function(){
    const ctx = document.getElementById('chartSize');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [{
                label: 'Tamaño total (GB)',
                data: <?= json_encode($chart_data) ?>,
                backgroundColor: 'rgba(46,134,193,0.7)',
                borderColor: '#1a5276',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'GB' },
                    ticks: { callback: v => v.toFixed(1)+' GB' }
                }
            }
        }
    });
})();
<?php endif; ?>
</script>

</body>
</html>
<?php
function strftime_compat(DateTime $dt): string {
    $dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    return $dias[(int)$dt->format('w')];
}
?>
