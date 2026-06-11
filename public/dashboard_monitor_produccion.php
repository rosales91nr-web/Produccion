<?php
/**
 * dashboard_monitor_produccion.php
 * Monitor en vivo de producción y quiebras — adaptado para pantalla grande / TV
 */
declare(strict_types=1);
session_start();

$sessionTimeout = 3 * 3600;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $sessionTimeout) {
    session_unset(); session_destroy(); session_start();
    header("Location: login_monitor.php"); exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login_monitor.php"); exit();
}

require_once '../config/database.php';
date_default_timezone_set('America/Guatemala');

$hoy = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=yes">
    <title>Monitor Producción — SIA-LAB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.3.0/chart.umd.min.js"></script>
    <style>
        :root{--bg:#0d1117;--card:rgba(30,40,55,.95);--border:rgba(99,102,241,.2);--accent:#6366f1;--ok:#22c55e;--warn:#f59e0b;--danger:#ef4444;--text:#e2e8f0;--muted:#94a3b8}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:16px}
        header{background:rgba(15,23,42,.98);padding:1rem 2rem;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:.5rem}
        header h1{font-size:clamp(1.1rem,2vw,1.5rem);display:flex;align-items:center;gap:.5rem;color:#fff}
        .hdr-right{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
        #reloj{font-size:.95rem;color:var(--muted);font-family:monospace}
        .btn-out{padding:.4rem .9rem;border-radius:8px;border:none;cursor:pointer;font-size:.82rem;font-weight:600;text-decoration:none;background:rgba(239,68,68,.8);color:#fff;transition:.2s}
        .btn-out:hover{background:#dc2626}
        .container{max-width:1600px;margin:0 auto;padding:1rem 1.5rem}
        .stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.85rem;margin-bottom:1rem}
        .kpi{background:var(--card);border-radius:12px;padding:.9rem 1.1rem;border:1px solid var(--border);display:flex;align-items:center;gap:.75rem}
        .kpi-icon{font-size:1.6rem;width:40px;text-align:center}
        .kpi-info span{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.4px}
        .kpi-info strong{display:block;font-size:1.4rem;font-weight:800;line-height:1.1}
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem}
        .panel{background:var(--card);border-radius:12px;padding:1rem 1.25rem;border:1px solid var(--border)}
        .panel h3{font-size:.88rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:.85rem;display:flex;align-items:center;gap:.4rem}
        .chart-wrap{position:relative;height:220px}
        table{width:100%;border-collapse:collapse}
        thead th{font-size:.72rem;text-transform:uppercase;color:var(--muted);padding:.5rem .75rem;border-bottom:1px solid var(--border);text-align:left}
        tbody td{padding:.55rem .75rem;font-size:.85rem;border-bottom:1px solid rgba(99,102,241,.08)}
        tbody tr:last-child td{border-bottom:none}
        tbody tr:hover td{background:rgba(99,102,241,.06)}
        .badge{padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:600}
        .b-ok{background:rgba(34,197,94,.2);color:#4ade80}
        .b-warn{background:rgba(245,158,11,.2);color:#fbbf24}
        .b-danger{background:rgba(239,68,68,.2);color:#f87171}
        .spinner{text-align:center;padding:2rem;color:var(--muted);font-size:.9rem}
        .pulse{animation:pulse 1.5s ease-in-out infinite}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
        @media(max-width:900px){.grid-2{grid-template-columns:1fr}}
        @media(max-width:600px){.container{padding:.75rem}}
    </style>
</head>
<body>

<header>
    <h1><i class="fas fa-industry" style="color:var(--accent)"></i> Monitor Producción en Vivo</h1>
    <div class="hdr-right">
        <span id="reloj"><i class="fas fa-circle pulse" style="color:var(--ok);font-size:.6rem"></i> --:--:--</span>
        <span id="last-update" style="font-size:.78rem;color:var(--muted)">Cargando...</span>
        <a href="login_monitor.php" class="btn-out"><i class="fas fa-sign-out-alt"></i> Salir</a>
    </div>
</header>

<div class="container">

    <!-- KPIs -->
    <div class="stats-row">
        <div class="kpi"><div class="kpi-icon" style="color:var(--ok)"><i class="fas fa-box"></i></div>
            <div class="kpi-info"><span>Órdenes Hoy</span><strong id="k-ordenes">—</strong></div></div>
        <div class="kpi"><div class="kpi-icon" style="color:var(--danger)"><i class="fas fa-times-circle"></i></div>
            <div class="kpi-info"><span>Quiebras Hoy</span><strong id="k-quiebras">—</strong></div></div>
        <div class="kpi"><div class="kpi-icon" style="color:var(--accent)"><i class="fas fa-users"></i></div>
            <div class="kpi-info"><span>Empleados Activos</span><strong id="k-empleados">—</strong></div></div>
        <div class="kpi"><div class="kpi-icon" style="color:var(--warn)"><i class="fas fa-percentage"></i></div>
            <div class="kpi-info"><span>% Quiebras</span><strong id="k-pct">—</strong></div></div>
        <div class="kpi"><div class="kpi-icon" style="color:var(--ok)"><i class="fas fa-tachometer-alt"></i></div>
            <div class="kpi-info"><span>Eficiencia</span><strong id="k-efic">—</strong></div></div>
    </div>

    <!-- Charts + Tablas -->
    <div class="grid-2">
        <div class="panel">
            <h3><i class="fas fa-chart-bar"></i> Producción por Área</h3>
            <div class="chart-wrap"><canvas id="chartAreas"></canvas></div>
        </div>
        <div class="panel">
            <h3><i class="fas fa-chart-line"></i> Producción por Hora</h3>
            <div class="chart-wrap"><canvas id="chartHoras"></canvas></div>
        </div>
    </div>

    <div class="grid-2">
        <div class="panel">
            <h3><i class="fas fa-exclamation-triangle" style="color:var(--danger)"></i> Últimas Quiebras</h3>
            <div id="tabla-quiebras"><div class="spinner"><i class="fas fa-spinner fa-spin"></i> Cargando...</div></div>
        </div>
        <div class="panel">
            <h3><i class="fas fa-trophy" style="color:var(--warn)"></i> Top Empleados Producción</h3>
            <div id="tabla-empleados"><div class="spinner"><i class="fas fa-spinner fa-spin"></i> Cargando...</div></div>
        </div>
    </div>

</div>

<script>
let chartAreas = null, chartHoras = null;
const hoy = '<?= $hoy ?>';

function reloj() {
    const n = new Date(), p = v => String(v).padStart(2,'0');
    document.getElementById('reloj').innerHTML =
        `<i class="fas fa-circle pulse" style="color:#22c55e;font-size:.6rem"></i> ${p(n.getHours())}:${p(n.getMinutes())}:${p(n.getSeconds())}`;
}
setInterval(reloj, 1000); reloj();

async function cargar() {
    try {
        const r = await fetch(`backend.php?ajax_tablas=1&fecha_inicio=${hoy}&fecha_fin=${hoy}&t=${Date.now()}`);
        if (!r.ok) throw new Error('HTTP '+r.status);
        const d = await r.json();
        if (!d.success) throw new Error(d.error || 'Error API');

        // KPIs
        const ordenes = d.top_ordenes ? d.top_ordenes.reduce((s,o)=>s+(parseInt(o.total_ordenes)||0),0) : 0;
        const quiebras_tot = d.quiebras_turno ? d.quiebras_turno.reduce((s,o)=>s+(parseInt(o.total)||0),0) : 0;
        const empleados_uniq = d.top_empleados_produccion ? d.top_empleados_produccion.length : 0;
        const pct = ordenes > 0 ? ((quiebras_tot/ordenes)*100).toFixed(1) : '0.0';
        const efic = ordenes > 0 ? (100 - parseFloat(pct)).toFixed(1) : '100.0';

        setText('k-ordenes', ordenes.toLocaleString());
        setText('k-quiebras', quiebras_tot.toLocaleString());
        setText('k-empleados', empleados_uniq);
        setText('k-pct', pct + '%');
        setText('k-efic', efic + '%');

        // Chart Áreas
        const areas = (d.areas_lista || []).slice(0,10);
        renderChartBars(chartAreas, 'chartAreas',
            areas.map(a=>a.area||a.nombre||'—'),
            areas.map(a=>parseInt(a.total_ordenes||a.total||0)),
            'Órdenes'
        );

        // Chart Horas — usar top_ordenes como proxy
        const horas_data = Array(24).fill(0);
        if (d.timeline_data) {
            d.timeline_data.forEach(t => {
                const h = parseInt((t.hora||'0').split(':')[0]);
                if (!isNaN(h)) horas_data[h] += parseInt(t.total||0);
            });
        }
        renderChartLine(chartHoras, 'chartHoras',
            Array.from({length:24},(_,i)=>String(i).padStart(2,'0')+':00'),
            horas_data, 'Quiebras/hora'
        );

        // Tabla quiebras
        const quiebras = (d.top_empleados_quiebras || []).slice(0,8);
        document.getElementById('tabla-quiebras').innerHTML = quiebras.length ? `
            <table><thead><tr><th>#</th><th>Empleado</th><th>Área</th><th>Total</th><th>Estado</th></tr></thead>
            <tbody>${quiebras.map((q,i)=>`
                <tr><td>${i+1}</td>
                    <td><strong>${esc(q.empleado||'—')}</strong></td>
                    <td>${esc(q.area||'—')}</td>
                    <td><strong style="color:#f87171">${q.total_quiebras||q.total||0}</strong></td>
                    <td><span class="badge ${parseInt(q.total_quiebras||q.total||0)>5?'b-danger':'b-warn'}">${parseInt(q.total_quiebras||q.total||0)>5?'Alto':'Normal'}</span></td>
                </tr>`).join('')}
            </tbody></table>` : '<div class="spinner">Sin quiebras registradas hoy ✅</div>';

        // Tabla empleados
        const emps = (d.top_empleados_produccion || []).slice(0,8);
        document.getElementById('tabla-empleados').innerHTML = emps.length ? `
            <table><thead><tr><th>#</th><th>Empleado</th><th>Área</th><th>Órdenes</th></tr></thead>
            <tbody>${emps.map((e,i)=>`
                <tr><td>${i+1}</td>
                    <td><strong>${esc(e.empleado||'—')}</strong></td>
                    <td>${esc(e.area||'—')}</td>
                    <td><span class="badge b-ok">${e.total_ordenes||e.total||0}</span></td>
                </tr>`).join('')}
            </tbody></table>` : '<div class="spinner">Sin datos de empleados para hoy</div>';

        document.getElementById('last-update').textContent = '🔄 ' + new Date().toLocaleTimeString('es-CR');

    } catch(e) {
        console.error(e);
        document.getElementById('last-update').textContent = '❌ ' + e.message;
    }
}

function setText(id, v) { const el=document.getElementById(id); if(el) el.textContent=v; }
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function renderChartBars(ref, id, labels, data, label) {
    const ctx = document.getElementById(id);
    if (!ctx) return;
    if (ref) ref.destroy();
    const c = new Chart(ctx, {
        type:'bar',
        data:{labels,datasets:[{label,data,backgroundColor:'rgba(99,102,241,.7)',borderColor:'#6366f1',borderWidth:1,borderRadius:4}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
            scales:{x:{ticks:{color:'#94a3b8',font:{size:10}},grid:{color:'rgba(99,102,241,.1)'}},
                    y:{ticks:{color:'#94a3b8',font:{size:10}},grid:{color:'rgba(99,102,241,.1)'}}}}
    });
    if (id==='chartAreas') chartAreas=c; else chartHoras=c;
}

function renderChartLine(ref, id, labels, data, label) {
    const ctx = document.getElementById(id);
    if (!ctx) return;
    if (ref) ref.destroy();
    const c = new Chart(ctx, {
        type:'line',
        data:{labels,datasets:[{label,data,borderColor:'#f59e0b',backgroundColor:'rgba(245,158,11,.15)',fill:true,tension:.3,pointRadius:3,borderWidth:2}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
            scales:{x:{ticks:{color:'#94a3b8',font:{size:9}},grid:{color:'rgba(99,102,241,.08)'}},
                    y:{ticks:{color:'#94a3b8',font:{size:9}},grid:{color:'rgba(99,102,241,.08)'}}}}
    });
    if (id==='chartAreas') chartAreas=c; else chartHoras=c;
}

cargar();
setInterval(cargar, 30000);
</script>
</body>
</html>
