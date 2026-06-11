<?php
/**
 * dashboard_admin_backups_gdrive.php
 * Monitor de Respaldos BD con Google Drive
 * Lee backup_status.json desde GitHub y muestra links directos
 * Reemplaza dashboard_admin_backups.php cuando los backups están en GDrive
 * @author Nestor Rosales | Rosalesdev91
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

date_default_timezone_set('America/Guatemala');

// ── CONFIGURACIÓN ─────────────────────────────────────────────────────────────
// Pegar aquí los datos de tu repo de GitHub (mismo que usas en el .ps1)
define('GH_USER',   getenv('GH_USER')   ?: 'TU_USUARIO_GITHUB');
define('GH_REPO',   getenv('GH_REPO')   ?: 'TU_REPOSITORIO');
define('GH_BRANCH', getenv('GH_BRANCH') ?: 'main');
define('GH_TOKEN',  getenv('GH_TOKEN')  ?: '');   // opcional: acceso a repos privados

// URL raw del JSON en GitHub (acceso público)
$json_url = sprintf(
    'https://raw.githubusercontent.com/%s/%s/%s/backup_status.json',
    GH_USER, GH_REPO, GH_BRANCH
);

// ── AJAX: retornar JSON fresco ─────────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $ctx_opts = ['http' => [
        'timeout' => 10,
        'ignore_errors' => true,
        'header' => GH_TOKEN
            ? "Authorization: token " . GH_TOKEN . "\r\n"
            : ''
    ]];
    $ctx  = stream_context_create($ctx_opts);
    $raw  = @file_get_contents($json_url, false, $ctx);

    if ($raw === false) {
        echo json_encode(['success' => false, 'error' => 'No se pudo leer backup_status.json desde GitHub', 'url' => $json_url]);
    } else {
        $data = json_decode($raw, true);
        if (!$data) {
            echo json_encode(['success' => false, 'error' => 'JSON inválido o vacío', 'url' => $json_url]);
        } else {
            $data['success'] = true;
            $data['leido_en'] = date('d/m/Y H:i:s');
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    exit();
}

$hoy = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SIA-LAB · Respaldos GDrive</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.3.0/chart.umd.min.js"></script>
    <style>
        :root {
            --primary:#1a5276; --primary-light:#2e86c1;
            --gradient:linear-gradient(135deg,#1a5276,#2e86c1);
            --ok:#1e8449; --ok-bg:#d4edda; --ok-txt:#155724;
            --warn:#d68910; --warn-bg:#fff3cd; --warn-txt:#856404;
            --danger:#c0392b; --danger-bg:#f8d7da; --danger-txt:#721c24;
            --bg:#f0f4f8; --card:#fff; --text:#2d3748; --muted:#718096;
            --border:#e2e8f0; --radius:12px; --shadow:0 4px 20px rgba(0,0,0,.08);
        }
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}

        header{background:var(--gradient);color:#fff;padding:1.25rem 2rem;display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.75rem;box-shadow:0 2px 12px rgba(0,0,0,.15)}
        header h1{font-size:clamp(1.1rem,2vw,1.6rem);font-weight:700;display:flex;align-items:center;gap:.5rem}
        header p{font-size:.82rem;opacity:.85;margin-top:.2rem}
        .hdr-actions{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}
        .btn-hdr{padding:.45rem 1rem;border-radius:8px;border:none;cursor:pointer;font-size:.82rem;font-weight:600;display:flex;align-items:center;gap:.35rem;transition:.2s;text-decoration:none}
        .btn-refresh{background:rgba(255,255,255,.2);color:#fff}.btn-refresh:hover{background:rgba(255,255,255,.35)}
        .btn-logout{background:rgba(220,53,69,.8);color:#fff}.btn-logout:hover{background:#c0392b}
        #reloj{font-size:.85rem;opacity:.9;font-family:monospace}

        .container{max-width:1440px;margin:0 auto;padding:1.5rem 2rem}
        @media(max-width:768px){.container{padding:1rem}}

        .alerta{border-radius:var(--radius);padding:1rem 1.25rem;margin-bottom:1rem;display:flex;align-items:flex-start;gap:.75rem}
        .alerta.error{background:var(--danger-bg);border:1px solid #f5c6cb;color:var(--danger-txt)}
        .alerta.warn{background:var(--warn-bg);border:1px solid #ffc107;color:var(--warn-txt)}
        .alerta.info{background:#d1ecf1;border:1px solid #bee5eb;color:#0c5460}

        /* Fuente del JSON */
        .json-source{background:#edf2f7;border:1px solid #cbd5e0;border-radius:8px;padding:.5rem 1rem;font-size:.78rem;color:#4a5568;display:flex;align-items:center;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap;font-family:monospace}

        /* KPIs */
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.85rem;margin-bottom:1.25rem}
        .stat-card{background:var(--card);border-radius:var(--radius);padding:1.1rem 1.3rem;box-shadow:var(--shadow);display:flex;align-items:center;gap:.85rem;border-left:4px solid var(--primary-light);transition:transform .2s}
        .stat-card:hover{transform:translateY(-2px)}
        .stat-card.ok{border-left-color:var(--ok)}.stat-card.warn{border-left-color:var(--warn)}.stat-card.danger{border-left-color:var(--danger)}
        .stat-icon{font-size:1.8rem;width:44px;text-align:center}
        .stat-icon.ok{color:var(--ok)}.stat-icon.warn{color:var(--warn)}.stat-icon.danger{color:var(--danger)}.stat-icon.blue{color:var(--primary-light)}
        .stat-info span{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.4px}
        .stat-info strong{display:block;font-size:1.3rem;font-weight:700;line-height:1.2}

        /* Chart */
        .chart-card{background:var(--card);border-radius:var(--radius);padding:1.1rem 1.5rem;box-shadow:var(--shadow);margin-bottom:1.25rem}
        .chart-card h3{font-size:.9rem;font-weight:700;margin-bottom:.75rem;display:flex;align-items:center;gap:.4rem}
        .chart-wrap{position:relative;height:200px}

        /* Tabla */
        .tabla-card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:1.25rem}
        .tabla-header{padding:.9rem 1.25rem;font-size:.9rem;font-weight:700;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap}
        .tabla-header h3{display:flex;align-items:center;gap:.4rem}
        .tabla-header input{padding:.35rem .75rem;border:1px solid var(--border);border-radius:8px;font-size:.82rem;width:200px;outline:none}
        .tabla-header input:focus{border-color:var(--primary-light)}
        table{width:100%;border-collapse:collapse}
        thead th{background:#f8fafc;padding:.65rem .9rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);border-bottom:1px solid var(--border)}
        tbody td{padding:.65rem .9rem;font-size:.83rem;border-bottom:1px solid var(--border);vertical-align:middle}
        tbody tr:last-child td{border-bottom:none}
        tbody tr:hover td{background:#f8fafc}

        .badge{padding:.2rem .55rem;border-radius:20px;font-size:.7rem;font-weight:600}
        .b-ok{background:var(--ok-bg);color:var(--ok-txt)}
        .b-warn{background:var(--warn-bg);color:var(--warn-txt)}
        .b-danger{background:var(--danger-bg);color:var(--danger-txt)}
        .b-blue{background:#d1ecf1;color:#0c5460}

        .btn-drive{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .7rem;border-radius:8px;font-size:.75rem;font-weight:600;background:#4285f4;color:#fff;text-decoration:none;transition:.2s;border:none;cursor:pointer}
        .btn-drive:hover{background:#1a73e8;transform:translateY(-1px)}
        .btn-drive.sin-link{background:#e2e8f0;color:#a0aec0;cursor:not-allowed}

        /* Fechas agrupadas */
        .fecha-bloque{margin-bottom:1.5rem}
        .fecha-header{background:var(--card);border-radius:var(--radius) var(--radius) 0 0;padding:.8rem 1.1rem;border-bottom:2px solid var(--primary-light);display:flex;align-items:center;justify-content:space-between;box-shadow:var(--shadow);flex-wrap:wrap;gap:.5rem}
        .fecha-header h2{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:.4rem}
        .tl-bar{display:flex;gap:2px;flex-wrap:wrap;padding:.5rem 1rem;background:var(--card);border-bottom:1px solid var(--border)}
        .tl-dot{width:26px;height:26px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:700;cursor:pointer;text-decoration:none;transition:.15s}
        .tl-dot.ok{background:#27ae60;color:#fff}.tl-dot.ok:hover{background:#1e8449}
        .tl-dot.gap{background:#e74c3c;color:#fff;opacity:.55;cursor:default}
        .tl-dot.na{background:#e2e8f0;color:#a0aec0;cursor:default}
        .leyenda{font-size:.7rem;color:var(--muted);padding:.4rem 1rem .6rem;background:var(--card);display:flex;gap:1rem;flex-wrap:wrap}
        .horas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.6rem;padding:.85rem;background:var(--card);border-radius:0 0 var(--radius) var(--radius);box-shadow:var(--shadow)}
        .hora-card{border-radius:8px;padding:.65rem .85rem;border:1px solid transparent;transition:.2s;display:flex;flex-direction:column;gap:.3rem}
        .hora-card:hover{transform:translateY(-2px);box-shadow:0 4px 10px rgba(0,0,0,.08)}
        .hora-card.presente{background:#f0fff4;border-color:#9ae6b4}
        .hora-card.gap{background:#fff5f5;border-color:#fed7d7;opacity:.75}
        .hora-card.error{background:#fff5f5;border-color:#fc8181}
        .hora-titulo{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:.3rem}
        .hora-card.presente .hora-titulo{color:var(--ok)}
        .hora-card.gap .hora-titulo,.hora-card.error .hora-titulo{color:var(--danger)}
        .hora-size{font-size:.8rem;font-weight:700;color:#2d3748}
        .hora-meta{font-size:.7rem;color:var(--muted)}

        /* Estado de carga */
        .loading{text-align:center;padding:3rem;color:var(--muted)}
        .loading i{font-size:2rem;margin-bottom:.75rem;display:block}
        .sin-datos{text-align:center;padding:3rem;color:var(--muted);background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow)}
        .sin-datos i{font-size:2.5rem;margin-bottom:.75rem;display:block;opacity:.4}

        #ultimo-update{font-size:.8rem;color:rgba(255,255,255,.75)}
    </style>
</head>
<body>

<header>
    <div>
        <h1><i class="fas fa-cloud"></i> Monitor Respaldos — Google Drive</h1>
        <p id="hdr-sub">Cargando estado desde GitHub...</p>
    </div>
    <div class="hdr-actions">
        <span id="reloj">--:--:--</span>
        <span id="ultimo-update"></span>
        <button class="btn-hdr btn-refresh" onclick="cargar(true)">
            <i class="fas fa-sync-alt"></i> Actualizar
        </button>
        <a href="login_admin.php" class="btn-hdr btn-logout">
            <i class="fas fa-sign-out-alt"></i> Salir
        </a>
    </div>
</header>

<div class="container">

    <div class="json-source" id="json-source">
        <i class="fas fa-code-branch"></i>
        Leyendo: <strong>github.com/<?= htmlspecialchars(GH_USER.'/'.GH_REPO) ?></strong>
        / <?= htmlspecialchars(GH_BRANCH) ?>/backup_status.json
        <span id="json-status" style="margin-left:auto"></span>
    </div>

    <!-- KPIs -->
    <div class="stats-grid" id="kpis">
        <?php foreach ([
            ['fa-database','blue','Total Backups','k-total'],
            ['fa-check-circle','ok','En Google Drive','k-ok'],
            ['fa-exclamation-triangle','warn','Con Error','k-error'],
            ['fa-clock','blue','Última Actualización','k-ultima'],
            ['fa-hdd','blue','Tamaño Total','k-size'],
        ] as $k): ?>
        <div class="stat-card">
            <div class="stat-icon <?= $k[1] ?>"><i class="fas <?= $k[0] ?>"></i></div>
            <div class="stat-info">
                <span><?= $k[2] ?></span>
                <strong id="<?= $k[3] ?>">—</strong>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Alertas dinámicas -->
    <div id="alertas"></div>

    <!-- Gráfico de tamaño por fecha -->
    <div class="chart-card" id="chart-section" style="display:none">
        <h3><i class="fas fa-chart-bar" style="color:var(--primary-light)"></i> Tamaño por Fecha (GB)</h3>
        <div class="chart-wrap"><canvas id="chartSize"></canvas></div>
    </div>

    <!-- Tabla resumen -->
    <div class="tabla-card">
        <div class="tabla-header">
            <h3><i class="fas fa-list"></i> Todos los Respaldos</h3>
            <input type="text" id="filtro" placeholder="Filtrar por nombre o fecha..." oninput="filtrarTabla()">
        </div>
        <div id="tabla-container">
            <div class="loading"><i class="fas fa-spinner fa-spin"></i> Cargando desde GitHub...</div>
        </div>
    </div>

    <!-- Detalle por fecha -->
    <div id="detalle-fechas"></div>

</div>

<footer style="text-align:center;padding:1.25rem;color:var(--muted);font-size:.78rem;border-top:1px solid var(--border);margin-top:1rem">
    SIA-LAB · Monitor Respaldos GDrive · <?= date('d/m/Y H:i:s') ?> (GT)
</footer>

<script>
let chartSize = null;
let backupsData = [];

// ── Reloj ──────────────────────────────────────────────────────────────────
(function tick(){
    const n=new Date(), p=v=>String(v).padStart(2,'0');
    document.getElementById('reloj').textContent =
        p(n.getHours())+':'+p(n.getMinutes())+':'+p(n.getSeconds());
    setTimeout(tick,1000);
})();

function setText(id,v){ const e=document.getElementById(id); if(e) e.textContent=v; }
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function formatSize(bytes) {
    if (!bytes || bytes===0) return '0 B';
    const b = parseInt(bytes);
    if (b >= 1073741824) return (b/1073741824).toFixed(2)+' GB';
    if (b >= 1048576)    return (b/1048576).toFixed(2)+' MB';
    if (b >= 1024)       return (b/1024).toFixed(1)+' KB';
    return b+' B';
}

// ── Cargar datos ──────────────────────────────────────────────────────────
async function cargar(manual = false) {
    if (manual) {
        document.getElementById('json-status').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    }
    try {
        const r = await fetch('?api=1&t=' + Date.now());
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();

        if (!d.success) {
            mostrarError(d.error || 'Error desconocido', d.url || '');
            return;
        }

        document.getElementById('json-status').innerHTML =
            '<span style="color:#1e8449"><i class="fas fa-check"></i> OK</span>';
        document.getElementById('hdr-sub').textContent =
            'GitHub: ' + (d.gdrive_folder || '—') + ' · Actualizado: ' + (d.generado_en || '—');
        document.getElementById('ultimo-update').textContent = '🔄 ' + (d.leido_en || '');

        backupsData = d.backups || [];
        renderKPIs(d);
        renderAlertas(d);
        renderTabla(backupsData);
        renderFechas(backupsData);
        renderChart(backupsData);

    } catch(e) {
        mostrarError(e.message, '');
        document.getElementById('json-status').innerHTML =
            '<span style="color:#c0392b"><i class="fas fa-times"></i> Error</span>';
    }
}

function mostrarError(msg, url) {
    document.getElementById('alertas').innerHTML = `
        <div class="alerta error">
            <i class="fas fa-exclamation-triangle fa-lg"></i>
            <div>
                <strong>No se pudo cargar backup_status.json</strong><br>
                ${esc(msg)}<br>
                <small style="opacity:.8">${url ? 'URL: '+esc(url) : ''}</small><br>
                <small>¿Ya corriste el script PowerShell y se subió el JSON a GitHub?</small>
            </div>
        </div>`;
    document.getElementById('tabla-container').innerHTML =
        '<div class="sin-datos"><i class="fas fa-inbox"></i><h3>Sin datos</h3><p>Ejecuta el script subir_backup_gdrive.ps1 en tu PC</p></div>';
}

// ── KPIs ──────────────────────────────────────────────────────────────────
function renderKPIs(d) {
    const total = d.total_backups || 0;
    const ok    = backupsData.filter(b => b.estado === 'ok').length;
    const err   = backupsData.filter(b => b.estado === 'error').length;
    const bytes = backupsData.reduce((s,b) => s + (parseInt(b.tamanio_bytes)||0), 0);

    setText('k-total', total);
    setText('k-ok',    ok);
    setText('k-error', err);
    setText('k-ultima', d.generado_en ? d.generado_en.substring(11,16) : '—');
    setText('k-size',  formatSize(bytes));
}

// ── Alertas ───────────────────────────────────────────────────────────────
function renderAlertas(d) {
    let html = '';
    const errores = backupsData.filter(b => b.estado === 'error');
    if (errores.length > 0) {
        html += `<div class="alerta warn"><i class="fas fa-exclamation-circle fa-lg"></i>
            <div><strong>${errores.length} backup(s) fallaron al subir a Google Drive.</strong>
            Revisa el log en tu PC: <code>%TEMP%\\sia_backup_YYYYMMDD.log</code></div></div>`;
    }
    const sinLink = backupsData.filter(b => b.estado === 'ok' && !b.gdrive_link);
    if (sinLink.length > 0) {
        html += `<div class="alerta info"><i class="fas fa-info-circle fa-lg"></i>
            <div><strong>${sinLink.length} backup(s)</strong> están subidos pero sin link generado. Vuelve a ejecutar el script.</div></div>`;
    }
    if (!d.generado_en) {
        html += `<div class="alerta info"><i class="fas fa-info-circle fa-lg"></i>
            <div>Aún no se ha ejecutado el script PowerShell. Configúralo y córrelo para generar el JSON.</div></div>`;
    }
    document.getElementById('alertas').innerHTML = html;
}

// ── Tabla ─────────────────────────────────────────────────────────────────
function renderTabla(data) {
    if (!data || data.length === 0) {
        document.getElementById('tabla-container').innerHTML =
            '<div class="sin-datos"><i class="fas fa-inbox"></i><h3>Sin backups registrados</h3></div>';
        return;
    }

    const rows = data.map((b,i) => {
        const estadoBadge = b.estado === 'ok'
            ? '<span class="badge b-ok"><i class="fas fa-check"></i> OK</span>'
            : b.estado === 'error'
                ? '<span class="badge b-danger"><i class="fas fa-times"></i> Error</span>'
                : '<span class="badge b-warn">Pendiente</span>';

        const linkBtn = b.gdrive_link
            ? `<a href="${esc(b.gdrive_link)}" target="_blank" class="btn-drive">
                <i class="fab fa-google-drive"></i> Abrir</a>`
            : `<span class="btn-drive sin-link"><i class="fas fa-link"></i> Sin link</span>`;

        const tipoIcon = b.tipo === 'carpeta'
            ? '<i class="fas fa-folder" style="color:#d68910"></i>'
            : '<i class="fas fa-file-archive" style="color:#1e8449"></i>';

        return `<tr data-nombre="${esc(b.nombre)}" data-fecha="${esc(b.fecha)}">
            <td>${i+1}</td>
            <td style="font-family:monospace;font-size:.78rem">${esc(b.nombre)}</td>
            <td>${esc(b.fecha)}</td>
            <td>${esc(b.hora||'—')}</td>
            <td>${tipoIcon} ${esc(b.tipo||'—')}</td>
            <td><strong>${esc(b.tamanio_str || formatSize(parseInt(b.tamanio_bytes||0)))}</strong></td>
            <td>${estadoBadge}</td>
            <td>${linkBtn}</td>
            <td style="font-size:.75rem;color:var(--muted)">${esc(b.modificado||'—')}</td>
        </tr>`;
    }).join('');

    document.getElementById('tabla-container').innerHTML = `
        <div style="overflow-x:auto">
        <table>
            <thead><tr>
                <th>#</th><th>Nombre</th><th>Fecha</th><th>Hora</th>
                <th>Tipo</th><th>Tamaño</th><th>Estado</th>
                <th>Google Drive</th><th>Modificado</th>
            </tr></thead>
            <tbody id="tabla-body">${rows}</tbody>
        </table></div>`;
}

function filtrarTabla() {
    const q = document.getElementById('filtro').value.toLowerCase();
    document.querySelectorAll('#tabla-body tr').forEach(tr => {
        const n = (tr.dataset.nombre||'').toLowerCase();
        const f = (tr.dataset.fecha||'').toLowerCase();
        tr.style.display = (!q || n.includes(q) || f.includes(q)) ? '' : 'none';
    });
}

// ── Detalle por fecha ─────────────────────────────────────────────────────
function renderFechas(data) {
    // Agrupar por fecha
    const porFecha = {};
    data.forEach(b => {
        if (!porFecha[b.fecha]) porFecha[b.fecha] = [];
        porFecha[b.fecha].push(b);
    });

    const fechas = Object.keys(porFecha).sort().reverse();
    const dias = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];

    let html = '';
    fechas.forEach(fecha => {
        const lista = porFecha[fecha];
        const horasPresentes = lista.map(b => parseInt((b.hora||'00').split(':')[0]));
        const horaMin = Math.min(...horasPresentes);
        const horaMax = Math.max(...horasPresentes);
        const rango = [];
        for (let h = horaMin; h <= horaMax; h++) rango.push(h);
        const gaps = rango.filter(h => !horasPresentes.includes(h)).length;
        const tamTotal = lista.reduce((s,b) => s + (parseInt(b.tamanio_bytes)||0), 0);
        const d = new Date(fecha + 'T00:00:00');
        const diaSem = dias[d.getDay()];

        // Timeline 0-23
        let tlHtml = '';
        for (let h = 0; h <= 23; h++) {
            const lh = String(h).padStart(2,'0');
            const presente = horasPresentes.includes(h);
            const enRango = h >= horaMin && h <= horaMax;
            const b = lista.find(x => parseInt((x.hora||'00').split(':')[0]) === h);
            const link = b && b.gdrive_link ? b.gdrive_link : null;

            if (presente && link) {
                tlHtml += `<a href="${esc(link)}" target="_blank" class="tl-dot ok" title="${fecha} ${lh}:00 ✓ — Click para abrir en Drive">${lh}</a>`;
            } else {
                tlHtml += `<div class="tl-dot ${presente?'ok':(enRango?'gap':'na')}" title="${fecha} ${lh}:00 ${presente?'✓':(enRango?'✗ FALTANTE':'')}">${lh}</div>`;
            }
        }

        // Tarjetas de horas (solo rango)
        let tarjetas = '';
        rango.forEach(h => {
            const lbl = String(h).padStart(2,'0') + ':00';
            const b = lista.find(x => parseInt((x.hora||'00').split(':')[0]) === h);
            const existe = !!b;
            const estado = !existe ? 'gap' : (b.estado === 'error' ? 'error' : 'presente');
            const icon = !existe ? 'times-circle' : (b.estado === 'error' ? 'exclamation-triangle' : 'check-circle');

            tarjetas += `<div class="hora-card ${estado}">
                <div class="hora-titulo"><i class="fas fa-${icon}"></i> ${lbl}</div>`;

            if (existe && b) {
                tarjetas += `<div class="hora-size">${esc(b.tamanio_str || formatSize(parseInt(b.tamanio_bytes||0)))}</div>
                    <div class="hora-meta"><i class="fas fa-${b.tipo==='carpeta'?'folder':'file'}"></i> ${esc(b.tipo||'—')}</div>`;
                if (b.gdrive_link) {
                    tarjetas += `<a href="${esc(b.gdrive_link)}" target="_blank" class="btn-drive" style="margin-top:.3rem;font-size:.7rem">
                        <i class="fab fa-google-drive"></i> Drive</a>`;
                } else {
                    tarjetas += `<div class="hora-meta" style="color:#c0392b">Sin link Drive</div>`;
                }
                tarjetas += `<div class="hora-meta" style="font-size:.65rem;color:#a0aec0;word-break:break-all">${esc(b.nombre)}</div>`;
            } else {
                tarjetas += `<div class="hora-meta" style="color:#c0392b"><i class="fas fa-exclamation-triangle"></i> Faltante</div>`;
            }
            tarjetas += '</div>';
        });

        html += `
        <div class="fecha-bloque">
            <div class="fecha-header">
                <h2><i class="fas fa-calendar-day" style="color:var(--primary-light)"></i>
                    ${esc(fecha)} <small style="font-weight:400;color:var(--muted)">(${diaSem})</small>
                </h2>
                <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
                    <span class="badge b-blue">${lista.length} respaldo(s)</span>
                    <span class="badge b-blue"><i class="fas fa-hdd"></i> ${formatSize(tamTotal)}</span>
                    ${gaps > 0
                        ? `<span class="badge b-danger"><i class="fas fa-exclamation-triangle"></i> ${gaps} gap(s)</span>`
                        : `<span class="badge b-ok"><i class="fas fa-shield-alt"></i> Completo</span>`
                    }
                </div>
            </div>
            <div class="tl-bar">${tlHtml}</div>
            <div class="leyenda">
                <span><span style="background:#27ae60;color:#fff;padding:1px 5px;border-radius:3px;font-size:.65rem">HH</span> Presente (click = Drive)</span>
                <span><span style="background:#e74c3c;color:#fff;padding:1px 5px;border-radius:3px;font-size:.65rem;opacity:.65">HH</span> Faltante</span>
                <span><span style="background:#e2e8f0;color:#a0aec0;padding:1px 5px;border-radius:3px;font-size:.65rem">HH</span> Sin dato</span>
            </div>
            <div class="horas-grid">${tarjetas}</div>
        </div>`;
    });

    document.getElementById('detalle-fechas').innerHTML = html || '<div class="sin-datos"><i class="fas fa-inbox"></i><h3>Sin backups</h3></div>';
}

// ── Chart ─────────────────────────────────────────────────────────────────
function renderChart(data) {
    const porFecha = {};
    data.forEach(b => {
        if (!porFecha[b.fecha]) porFecha[b.fecha] = 0;
        porFecha[b.fecha] += parseInt(b.tamanio_bytes||0);
    });
    const fechas = Object.keys(porFecha).sort().slice(-10);
    if (fechas.length < 2) { document.getElementById('chart-section').style.display='none'; return; }

    document.getElementById('chart-section').style.display='';
    const ctx = document.getElementById('chartSize');
    if (chartSize) chartSize.destroy();
    chartSize = new Chart(ctx, {
        type:'bar',
        data:{
            labels: fechas,
            datasets:[{
                label:'Tamaño total (GB)',
                data: fechas.map(f => parseFloat((porFecha[f]/1073741824).toFixed(3))),
                backgroundColor:'rgba(46,134,193,0.7)',
                borderColor:'#1a5276',
                borderWidth:1,
                borderRadius:4
            }]
        },
        options:{
            responsive:true,maintainAspectRatio:false,
            plugins:{legend:{display:false}},
            scales:{y:{beginAtZero:true,title:{display:true,text:'GB'},
                ticks:{callback:v=>v.toFixed(1)+' GB'}}}
        }
    });
}

// ── Inicio ────────────────────────────────────────────────────────────────
cargar();
setInterval(cargar, 5 * 60 * 1000); // refresh cada 5 min
</script>
</body>
</html>