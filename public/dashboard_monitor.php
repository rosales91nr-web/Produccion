<?php
/**
 * dashboard_monitor.php
 * Panel de monitoreo en vivo MEJORADO - VERSIÓN SMART TV
 * Optimizado para pantallas grandes y control remoto
 * 
 * MODIFICADO: Adaptado para Smart TVs y Android TV Box
 * - Textos más grandes
 * - Navegación por control remoto
 * - Scroll automático
 * - Controles optimizados para TV
 *
 * Requiere: api_monitor.php + registrar_actividad.php
 * Desarrollado por: Nestor Rosales | Rosalesdev91
 */

session_start();
require_once 'registrar_actividad.php';

// Verificar autenticación
if (!isset($_SESSION['empleado']) || $_SESSION['rol'] != 'administrador') {
    header("Location: login_monitor.php");
    exit();
}

// Zona horaria
date_default_timezone_set('America/Guatemala');

// Obtener fechas seleccionadas (por defecto hoy)
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d');
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <meta name="theme-color" content="#0a3d2a">
    <title>Monitor en Vivo</title>
    <style>
        * { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0; 
        }

        /* Modo TV: tamaños más grandes y mejor contraste */
        body {
            background: radial-gradient(circle at top left, rgba(59,130,246,0.12), transparent 24%),
                        radial-gradient(circle at bottom right, rgba(99,102,241,0.12), transparent 20%),
                        linear-gradient(180deg, #f8fbff 0%, #eef5fb 100%);
            color: #0f172a;
            font-family: 'Segoe UI', 'Roboto', Arial, sans-serif;
            min-height: 100vh;
            padding-bottom: 100px;
            font-size: 18px;
        }

        /* Enfoque para navegación por control remoto */
        .focusable:focus, button:focus, input:focus, a:focus, .filter-btn:focus {
            outline: 3px solid #1d4ed8 !important;
            outline-offset: 4px;
            transform: scale(1.02);
            transition: all 0.1s ease;
        }

        /* ── TOPBAR ── */
        .topbar {
            background: rgba(26, 35, 64, 0.95);
            backdrop-filter: blur(10px);
            padding: 20px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            border-radius: 24px;
            border: 1px solid rgba(99, 102, 241, 0.18);
            box-shadow: 0 24px 62px rgba(15, 23, 42, 0.14);
        }
        .topbar h1 { 
            font-size: 28px; 
            font-weight: 800;
            color: #ffffff;
            text-shadow: 0 1px 10px rgba(0,0,0,0.18);
        }
        .topbar a {  
            color: #dbeafe; 
            font-size: 18px; 
            text-decoration: none; 
            padding: 10px 16px;
            border-radius: 30px;
            transition: all 0.3s;
            display: inline-block;
        }
        .topbar a:hover, .topbar a:focus { 
            background: rgba(59, 130, 246, 0.18);
            color: #bfdbfe; 
            text-decoration: none;
            outline: 2px solid #93c5fd;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .page-header {
            text-align: center;
            margin-bottom: 25px;
        }
        .spinner-note {
            font-size: 18px;
            margin-top: 15px;
            color: #475569;
        }

        .badge-live {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(59, 130, 246, 0.16);
            border: 2px solid #3b82f6;
            padding: 8px 20px;
            border-radius: 40px;
            font-size: 18px;
            color: #ffffff;
        }
        .badge-historic {
            background: rgba(251, 191, 36, 0.15);
            border-color: #f59e0b;
        }
        .dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #3b82f6;
            animation: pulse 1.4s infinite;
        }
        .dot-historic {
            background: #f59e0b;
            animation: none;
        }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.25} }

        /* ── FILTRO FECHA ── */
        .fecha-filter {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(255,255,255,0.16);
            padding: 16px 22px;
            border-radius: 999px;
            flex-wrap: wrap;
            border: 1px solid rgba(59, 130, 246, 0.18);
            box-shadow: inset 0 1px 15px rgba(255,255,255,0.4);
        }
        .fecha-filter label {
            font-size: 16px;
            color: #bfdbfe;
            font-weight: 700;
        }
        .fecha-filter input {
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.35);
            color: #ffffff;
            padding: 10px 16px;
            border-radius: 30px;
            font-size: 16px;
            cursor: pointer;
        }
        .fecha-filter input:focus {
            outline: 3px solid rgba(59, 130, 246, 0.35);
            border-color: #93c5fd;
        }
        .fecha-filter button {
            background: #3b82f6;
            border: none;
            color: #ffffff;
            padding: 10px 24px;
            border-radius: 30px;
            font-weight: 700;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s;
            box-shadow: 0 10px 24px rgba(59,130,246,0.18);
        }
        .fecha-filter button:hover, .fecha-filter button:focus {
            background: #1d4ed8;
            transform: scale(1.03);
            outline: 2px solid rgba(255,255,255,0.8);
        }
        .btn-csv {
            background: #e63946 !important;
            color: white !important;
            box-shadow: 0 10px 24px rgba(230,57,70,0.18);
        }

        .status-text {
            font-size: 18px;
            color: #c7d2fe;
            font-family: monospace;
        }
        .status-note {
            font-size: 14px;
            color: #c7d2fe;
        }

        /* ── WRAPPER ── */
        .content-wrapper { 
            max-width: 1600px; 
            margin: auto; 
            padding: 30px 25px; 
        }

        /* ── MÉTRICAS ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            position: relative;
            background: linear-gradient(180deg, #ffffff 0%, #eff6ff 100%);
            border-radius: 24px;
            padding: 26px 24px 24px;
            border: 1px solid rgba(59, 130, 246, 0.15);
            box-shadow: 0 20px 45px rgba(15,23,42,0.08);
            transition: transform 0.25s, box-shadow 0.25s;
            overflow: hidden;
        }
        .stat-card:focus, .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 25px 55px rgba(15,23,42,0.14);
            outline: none;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 6px;
            height: 100%;
            background: linear-gradient(180deg, #3b82f6, #2563eb);
            border-radius: 0 0 10px 0;
        }
        .stat-label { 
            font-size: 14px; 
            color: #475569; 
            margin-bottom: 14px; 
            text-transform: uppercase; 
            letter-spacing: 1.8px; 
            opacity: 0.85;
        }
        .stat-val   { 
            font-size: 44px; 
            font-weight: 900; 
            color: #0f172a;
            line-height: 1;
        }
        .val-green  { color: #16a34a; }
        .val-amber  { color: #f59e0b; }
        .val-red    { color: #dc2626; }
        .stat-unit  { font-size: 16px; color: #475569; margin-left: 6px; }

        /* ── GRID DOS COLUMNAS ── */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        @media (max-width: 1024px) { 
            .two-col { 
                grid-template-columns: 1fr; 
            } 
        }

        /* ── PANELES ── */
        .panel {
            background: #ffffff;
            border-radius: 28px;
            padding: 28px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 24px 64px rgba(15,23,42,0.08);
            transition: transform 0.3s, border-color 0.3s;
        }
        .panel:focus-within, .panel:hover {
            border-color: #bfdbfe;
            transform: translateY(-1px);
        }
        .panel-title {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #1d4ed8;
            margin-bottom: 24px;
            padding-bottom: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e2e8f0;
        }
        .panel-title span:last-child {
            font-size: 14px;
            font-weight: 700;
            color: #475569;
            background: rgba(59,130,246,0.08);
            padding: 8px 14px;
            border-radius: 999px;
        }

        /* ── TABLA DE USUARIOS ── */
        .user-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 16px;
        }
        .user-table th {
            text-align: left;
            padding: 15px 12px;
            color: #1d4ed8;
            border-bottom: 2px solid #bfdbfe;
            font-weight: 600;
            font-size: 16px;
        }
        .user-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
            font-size: 15px;
        }
        .user-table tr:hover { background: #eff6ff; }
        
        .avatar-small {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: bold;
        }
        .av-admin { background: linear-gradient(135deg, #2d2589, #1a1555); color: #cecbf6; }
        .av-emp   { background: linear-gradient(135deg, #0c5240, #063829); color: #9fe1cb; }
        .av-tecnico { background: linear-gradient(135deg, #1a5f2a, #0f3d1a); color: #b8f0c0; }
        
        .modulo-badge {
            background: rgba(59, 130, 246, 0.15);
            color: #1d4ed8;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 14px;
            display: inline-block;
            font-weight: 500;
        }
        .tag {
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
        }
        .tag-admin { background: #2d2589; color: #cecbf6; }
        .tag-emp { background: #085041; color: #9fe1cb; }
        .tag-tecnico { background: #1a5f2a; color: #b8f0c0; }

        .ip-text {
            font-family: monospace;
            font-size: 14px;
            background: rgba(226, 232, 240, 0.8);
            color: #1a2340;
            padding: 6px 12px;
            border-radius: 20px;
            display: inline-block;
        }

        /* ── BD STATUS ── */
        .db-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 16px;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .db-key { color: #475569; font-size: 16px; }
        .db-val { font-weight: bold; color: #1d6e43; font-size: 20px; }

        .prog-wrap { margin-top: 20px; }
        .prog-label {
            display: flex;
            justify-content: space-between;
            font-size: 15px;
            color: #475569;
            margin-bottom: 10px;
        }
        .prog-bar {
            height: 12px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }
        .prog-fill { height: 100%; border-radius: 10px; transition: width 0.5s ease; }

        /* ── ACTIVIDAD ── */
        .filters { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px; }
        .filter-btn {
            font-size: 16px;
            padding: 12px 24px;
            border-radius: 999px;
            cursor: pointer;
            border: 1px solid rgba(59,130,246,0.35);
            background: rgba(59,130,246,0.08);
            color: #1d4ed8;
            transition: all 0.2s;
        }
        .filter-btn.active { 
            background: #1d4ed8; 
            border-color: #1d4ed8; 
            color: white; 
            box-shadow: 0 10px 24px rgba(59,130,246,0.2);
        }
        .filter-btn:hover, .filter-btn:focus {
            background: rgba(59,130,246,0.16);
            color: #1d4ed8;
            outline: 2px solid rgba(59,130,246,0.35);
            transform: translateY(-1px);
        }

        .period-btn {
            background: #3b82f6;
            border: none;
            color: #ffffff;
            padding: 10px 24px;
            border-radius: 30px;
            font-weight: 700;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s;
            box-shadow: 0 10px 24px rgba(59,130,246,0.18);
        }
        .period-btn:hover, .period-btn:focus {
            background: #1d4ed8;
            transform: scale(1.02);
        }

        .act-feed { 
            max-height: 450px; 
            overflow-y: auto; 
            padding-right: 4px;
        }
        .act-row {
            display: flex;
            gap: 18px;
            align-items: flex-start;
            padding: 18px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            margin-bottom: 14px;
            transition: background 0.2s, transform 0.2s, border-color 0.2s;
        }
        .act-row:hover {
            background: #eff6ff;
            transform: translateY(-1px);
            border-color: #bfdbfe;
        }
        .act-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: bold;
            flex-shrink: 0;
            color: white;
            box-shadow: 0 10px 24px rgba(59,130,246,0.08);
        }
        .ico-login { background: #2563eb; }
        .ico-agregar { background: #16a34a; }
        .ico-modificar { background: #f59e0b; }
        .ico-eliminar { background: #ef4444; }
        .ico-otro { background: #6366f1; }
        .act-text { 
            font-size: 16px; 
            line-height: 1.6; 
            color: #1e293b;
        }
        .act-time { 
            font-size: 14px; 
            color: #475569; 
            margin-top: 8px; 
        }
        .act-empleado {
            font-weight: 700;
            color: #1d4ed8;
            background: rgba(59, 130, 246, 0.12);
            padding: 5px 14px;
            border-radius: 999px;
            display: inline-block;
            font-size: 14px;
            margin-right: 8px;
        }

        /* Scrollbar más grande para TV */
        ::-webkit-scrollbar { width: 12px; height: 12px; }
        ::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #3b82f6; border-radius: 10px; }
        
        .table-scroll {
            max-height: 500px;
            overflow-y: auto;
        }

        .rango-fechas {
            font-size: 16px;
            background: #ffffff;
            padding: 12px 24px;
            border-radius: 999px;
            color: #1d4ed8;
            display: inline-block;
            border: 1px solid #bfdbfe;
            box-shadow: 0 12px 24px rgba(59,130,246,0.08);
        }

        /* ── FOOTER ── */
        .firma {
            text-align: center;
            font-size: 16px;
            color: #93c5fd;
            padding: 20px 25px;
            background: #1a2340;
            border-top: 3px solid #3b82f6;
            position: fixed;
            left: 0;
            bottom: 0;
            width: 100%;
            z-index: 100;
        }

        .logo {
            position: absolute;
            top: 20px;
            right: 30px;
            width: 180px;
            height: auto;
            opacity: 0.8;
        }

        #spinner {
            text-align: center;
            padding: 80px;
            font-size: 24px;
            color: #1d4ed8;
        }
        .hidden { display: none !important; }

        /* Modo kiosko - ocultar scrollbar si es necesario */
        @media (display-mode: fullscreen) {
            body { overflow-y: auto; }
        }
        
        /* Botones grandes para control remoto */
        button, .filter-btn, .fecha-filter button {
            min-height: 48px;
            min-width: 100px;
        }
        
        /* Alertas */
        .alertas-container {
            margin-bottom: 25px;
            display: none;
        }
        .alertas-container.show { display: block; }
        .alerta {
            background: rgba(255, 107, 107, 0.25);
            border-left: 6px solid #ff6b6b;
            padding: 16px 20px;
            margin-bottom: 12px;
            border-radius: 12px;
            font-size: 16px;
        }
        
        /* Estados de inactividad */
        .inactivo-bajo { color: #16a34a; }
        .inactivo-medio { color: #f59e0b; }
        .inactivo-alto { color: #ef4444; }
    </style>
</head>
<body>

<img src="/logo.svg" alt="Logo" class="logo" onerror="this.style.display='none'">

<div class="topbar">
    <h1>📡 Monitor en Vivo</h1>
    <div class="topbar-actions">
        <div class="fecha-filter">
            <label>📅 DESDE:</label>
            <input type="date" id="fecha-inicio" class="focusable" value="<?php echo $fecha_inicio; ?>">
            <label>📅 HASTA:</label>
            <input type="date" id="fecha-fin" class="focusable" value="<?php echo $fecha_fin; ?>">
            <button id="btn-cargar-fecha" class="period-btn focusable">Ver</button>
            <button id="btn-hoy" class="period-btn focusable">📆 Hoy</button>
            <button id="btn-semana" class="period-btn focusable">📅 Semana</button>
            <button id="btn-mes" class="period-btn focusable">📆 Mes</button>
            <button id="btn-exportar-csv" class="btn-csv focusable">📎 CSV</button>
        </div>
        <span id="badge-estado" class="badge-live">
            <span class="dot"></span>En vivo
        </span>
        <span id="reloj" class="status-text">--:--:--</span>
        <span id="last-update" class="status-note"></span>
        <a href="dashboard_monitor_produccion.php" class="focusable">← Ir a Producción</a>
        <a href="login_monitor.php" class="focusable">🚪 Cerrar sesión</a>
    </div>
</div>

<div class="content-wrapper">

    <div id="spinner">
        <div>🔄 Cargando datos del sistema...</div>
        <div class="spinner-note">Optimizado para Smart TV</div>
    </div>

    <div id="main-content" class="hidden">

        <div class="page-header">
            <span class="rango-fechas" id="rango-info">Cargando...</span>
        </div>

        <div id="alertas-container" class="alertas-container"></div>

        <div class="stats-grid" id="stats-grid">
            <div class="stat-card" tabindex="0">
                <div class="stat-label">👥 Conectados ahora</div>
                <div class="stat-val val-green" id="s-online">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">👑 Administradores activos</div>
                <div class="stat-val val-amber" id="s-admins">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">👷 Empleados activos</div>
                <div class="stat-val val-green" id="s-emps">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">📊 Total empleados BD</div>
                <div class="stat-val" id="s-total">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">📝 Acciones registradas</div>
                <div class="stat-val" id="s-acciones">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">✅ Activos hoy</div>
                <div class="stat-val val-green" id="s-activos-hoy">—</div>
            </div>
        </div>

        <div class="two-col">

            <div class="panel">
                <div class="panel-title">
                    <span>👥 Usuarios en sesión activa</span>
                    <span id="user-count" style="font-size:16px;">0</span>
                </div>
                <div class="table-scroll">
                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Rol</th>
                                <th>📍 Módulo Actual</th>
                                <th>🌐 IP</th>
                                <th>⏱️ Inactivo</th>
                            </tr>
                        </thead>
                        <tbody id="user-table-body">
                            <tr><td colspan="5" style="text-align:center;">Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel">
                <div class="panel-title">🗄️ Base de datos — produccion_quiebras</div>
                <div class="db-row">
                    <span class="db-key">📋 Total empleados</span>
                    <span class="db-val" id="db-total">—</span>
                </div>
                <div class="db-row">
                    <span class="db-key">🔗 Conexiones activas</span>
                    <span class="db-val" id="db-conex">—</span>
                </div>
                <div class="db-row">
                    <span class="db-key">🕐 Último cambio tabla</span>
                    <span class="db-val" id="db-last">—</span>
                </div>
                <div class="db-row">
                    <span class="db-key">💾 Tamaño BD</span>
                    <span class="db-val" id="db-size">—</span>
                </div>

                <div class="prog-wrap">
                    <div class="prog-label">
                        <span>📊 Carga del servidor</span>
                        <span id="lbl-load">—</span>
                    </div>
                    <div class="prog-bar">
                        <div class="prog-fill" id="bar-load" style="width:0%;background:#22c55e"></div>
                    </div>
                </div>
                <div class="prog-wrap">
                    <div class="prog-label">
                        <span>🔄 Sesiones PHP activas</span>
                        <span id="lbl-sess">—</span>
                    </div>
                    <div class="prog-bar">
                        <div class="prog-fill" id="bar-sess" style="width:0%;background:#f59e0b"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title">📜 Actividad reciente del sistema</div>
            <div class="filters" id="filters">
                <button class="filter-btn focusable active" data-filtro="todos">📋 Todos</button>
                <button class="filter-btn focusable" data-filtro="login">🔓 Acceso</button>
                <button class="filter-btn focusable" data-filtro="agregar">➕ Agregar</button>
                <button class="filter-btn focusable" data-filtro="modificar">✏️ Modificar</button>
                <button class="filter-btn focusable" data-filtro="eliminar">🗑️ Eliminar</button>
                <button class="filter-btn focusable" data-filtro="otro">📌 Otros</button>
            </div>
            <div class="act-feed" id="act-feed">
                <p style="color:#475569;">Cargando actividad...</p>
            </div>
        </div>

    </div>
</div>

<div class="firma">
    Monitor en vivo — Sistema de Monitoreo &nbsp;|&nbsp; © <?php echo date("Y"); ?>
    <p style="font-size:14px;margin-top:8px">Desarrollado Por: Nestor Rosales | Rosalesdev91</p>
</div>

<script>
// ============================================
// VARIABLES GLOBALES
// ============================================
let filtroActual = 'todos';
let datosActuales = null;
let autoScrollInterval = null;
let autoScrollEnabled = true;

// ============================================
// RELOJ EN VIVO
// ============================================
function actualizarReloj() {
    const now = new Date();
    const fecha = now.toLocaleDateString('es-CR');
    const hora = now.toLocaleTimeString('es-CR');
    const reloj = document.getElementById('reloj');
    if (reloj) reloj.textContent = `${fecha} ${hora}`;
}
actualizarReloj();
setInterval(actualizarReloj, 1000);

function formatFechaLocal(fecha) {
    const d = fecha instanceof Date ? fecha : new Date(fecha);
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

// ============================================
// ACTUALIZAR BADGE SEGÚN RANGO DE FECHAS
// ============================================
function actualizarBadgeFecha(fechaInicio, fechaFin) {
    const badge = document.getElementById('badge-estado');
    const hoy = formatFechaLocal(new Date());
    if (fechaInicio === hoy && fechaFin === hoy) {
        if (badge) {
            badge.innerHTML = '<span class="dot"></span>En vivo';
            badge.classList.remove('badge-historic');
        }
    } else {
        if (badge) {
            badge.innerHTML = '<span class="dot dot-historic"></span>Histórico';
            badge.classList.add('badge-historic');
        }
    }
    const rangoInfo = document.getElementById('rango-info');
    if (rangoInfo) rangoInfo.innerHTML = `📅 Mostrando datos del ${fechaInicio} al ${fechaFin}`;
}

// ============================================
// FILTROS DE ACTIVIDAD
// ============================================
function aplicarFiltro() {
    document.querySelectorAll('#act-feed .act-row').forEach(row => {
        const tipo = row.dataset.tipo;
        row.style.display = (filtroActual === 'todos' || tipo === filtroActual) ? 'flex' : 'none';
    });
}

document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        filtroActual = this.dataset.filtro;
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        aplicarFiltro();
    });
});

// ============================================
// FUNCIONES UTILITARIAS
// ============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function iniciales(nombre) {
    if (!nombre) return '??';
    const partes = nombre.trim().split(' ');
    if (partes.length === 1) return partes[0].substring(0, 2).toUpperCase();
    return (partes[0][0] + (partes[1][0] || '')).toUpperCase();
}

const moduloNombres = {
    'dashboard_monitor': '📡 Monitor en Vivo',
    'dashboard_monitor_produccion': '🏭 Monitor Producción',
    'dashboard_admin_empleados': '👥 Gestión de Empleados',
    'dashboard_admin_quiebras': '📊 Registro de Quiebras',
    'dashboard_admin_produccion': '🏭 Producción',
    'dashboard_admin_check': '✅ Check de Calidad',
    'dashboard_admin_asistencia': '⏰ Control de Pausas',
    'dashboard_admin_paros': '⚠️ Paros de Producción',
    'auditoria_admin': '🔍 Auditoría de Cambios',
    'registro': '📦 Registro Producción',
    'registro_picking': '📦 Registro Picking',
    'registro_asistencia': '⏰ Marcas Asistencia',
    'registro_paro': '⚠️ Solicitar Paro',
    'registro_quiebras': '💔 Registro Quiebras',
    'solicitudes_paro': '🔧 Atención Paros',
    'tareas_tecnico': '📋 Mis Tareas',
    'default': '🏠 Activo'
};

function getModuloNombre(modulo) {
    return moduloNombres[modulo] || moduloNombres['default'] + ' (' + modulo + ')';
}

function getAvatarClass(rol) {
    if (rol === 'administrador') return 'av-admin';
    if (rol === 'tecnico') return 'av-tecnico';
    return 'av-emp';
}

function getTagClass(rol) {
    if (rol === 'administrador') return 'tag-admin';
    if (rol === 'tecnico') return 'tag-tecnico';
    return 'tag-emp';
}

function getRolIcono(rol) {
    if (rol === 'administrador') return '👑';
    if (rol === 'tecnico') return '🔧';
    return '👤';
}

function getRolTexto(rol) {
    if (rol === 'administrador') return 'Admin';
    if (rol === 'tecnico') return 'Técnico';
    return 'Empleado';
}

// ============================================
// RENDERIZAR USUARIOS
// ============================================
function renderUsuarios(sesiones) {
    const tbody = document.getElementById('user-table-body');
    const userCount = document.getElementById('user-count');
    
    if (!sesiones || sesiones.length === 0) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">✨ No hay usuarios activos</td></tr>';
        if (userCount) userCount.textContent = '0';
        return;
    }
    
    if (userCount) userCount.textContent = sesiones.length;
    
    if (tbody) {
        tbody.innerHTML = sesiones.map(s => {
            const nombre = escapeHtml(s.nombre || 'Desconocido');
            const codigo = escapeHtml(s.codigo || '—');
            const modulo = s.modulo_nombre || getModuloNombre(s.modulo_actual || 'default');
            const inactivo = s.tiempo_inactivo !== undefined ? s.tiempo_inactivo : 0;
            const inactivoDisplay = (typeof inactivo === 'number' ? inactivo : parseFloat(inactivo)) + ' min';
            const rol = s.rol || 'empleado';
            const ip = escapeHtml(s.ip || 'No registrada');
            const avatarClass = getAvatarClass(rol);
            const tagClass = getTagClass(rol);
            const rolIcono = getRolIcono(rol);
            const rolTexto = getRolTexto(rol);
            
            let inactivoClass = '';
            if (inactivo > 10) inactivoClass = 'inactivo-alto';
            else if (inactivo > 5) inactivoClass = 'inactivo-medio';
            else inactivoClass = 'inactivo-bajo';
            
            return `
                <tr>
                    <td>
                        <div style="display:flex; align-items:center; gap:15px;">
                            <div class="avatar-small ${avatarClass}">
                                ${iniciales(nombre)}
                            </div>
                            <div>
                                <div style="font-weight:bold; font-size:17px;">${nombre}</div>
                                <div style="font-size:13px; color:#475569;">📛 ${codigo}</div>
                            </div>
                        </div>
                    </td>
                    <td><span class="${tagClass}">${rolIcono} ${rolTexto}</span></td>
                    <td><span class="modulo-badge">${modulo}</span></td>
                    <td><span class="ip-text">🌐 ${ip}</span></td>
                    <td class="${inactivoClass}" style="font-size:14px;">⏱️ ${inactivoDisplay}</td>
                </tr>
            `;
        }).join('');
    }
}

// ============================================
// RENDERIZAR ALERTAS
// ============================================
function renderAlertas(data) {
    const container = document.getElementById('alertas-container');
    const alertas = data.alertas || [];
    const warnings = data.warnings || [];
    
    if (!container) return;
    
    if (alertas.length === 0 && warnings.length === 0) {
        container.classList.remove('show');
        return;
    }
    
    container.classList.add('show');
    let html = '';
    alertas.forEach(alerta => {
        html += `<div class="alerta">⚠️ ${escapeHtml(alerta)}</div>`;
    });
    warnings.forEach(warning => {
        html += `<div class="alerta">📌 ${escapeHtml(warning)}</div>`;
    });
    container.innerHTML = html;
}

// ============================================
// ACTUALIZAR BARRAS DE PROGRESO
// ============================================
function actualizarBarras(serverLoad, sesionesCount) {
    const load = Math.min(100, Math.max(0, serverLoad || 35));
    const sess = Math.min(95, Math.max(5, sesionesCount * 5 + 10));
    
    const lblLoad = document.getElementById('lbl-load');
    const barLoad = document.getElementById('bar-load');
    const lblSess = document.getElementById('lbl-sess');
    const barSess = document.getElementById('bar-sess');
    
    if (lblLoad) lblLoad.textContent = load + '%';
    if (barLoad) {
        barLoad.style.width = load + '%';
        barLoad.style.background = load > 70 ? '#ef4444' : load > 50 ? '#f59e0b' : '#22c55e';
    }
    
    if (lblSess) lblSess.textContent = Math.round(sess) + '%';
    if (barSess) barSess.style.width = sess + '%';
}

// ============================================
// RENDERIZAR ACTIVIDAD
// ============================================
function renderActividad(actividad) {
    const el = document.getElementById('act-feed');
    if (!el) return;
    
    if (!actividad || actividad.length === 0) {
        el.innerHTML = '<p style="text-align:center;">📭 No hay actividad registrada</p>';
        return;
    }
    
    const iconos = { login:'🔓', agregar:'➕', modificar:'✏️', eliminar:'🗑️', otro:'📌' };
    const clases = { login:'ico-login', agregar:'ico-agregar', modificar:'ico-modificar', eliminar:'ico-eliminar', otro:'ico-otro' };
    
    let html = '';
    for (let i = 0; i < actividad.length; i++) {
        const a = actividad[i];
        const tipo = a.tipo;
        let detalle = escapeHtml(a.detalle || 'Sin detalles');
        detalle = detalle.replace(/👤 ([^—]+) —/, '<span class="act-empleado">👤 $1</span> —');
        
        const fecha = escapeHtml(a.fecha_hora || '—');
        const ip = a.ip ? escapeHtml(a.ip) : '';
        const usuario = a.usuario ? escapeHtml(a.usuario) : '';
        
        html += `
            <div class="act-row" data-tipo="${tipo}">
                <div class="act-icon ${clases[tipo] || 'ico-otro'}">${iconos[tipo] || '📌'}</div>
                <div style="flex:1">
                    ${usuario ? `<span class="act-empleado">👤 ${usuario}</span>` : ''}
                    <div class="act-text">${detalle}</div>
                    <div class="act-time">
                        🕐 ${fecha}
                        ${ip ? `<span style="margin-left:12px;">🌐 ${ip}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    }
    el.innerHTML = html;
    aplicarFiltro();
}

// ============================================
// EXPORTAR CSV
// ============================================
function exportarCSV() {
    if (!datosActuales) {
        alert('No hay datos para exportar');
        return;
    }
    
    const fechaInicio = document.getElementById('fecha-inicio').value;
    const fechaFin = document.getElementById('fecha-fin').value;
    const filename = `actividad_monitor_${fechaInicio}_a_${fechaFin}.csv`;
    
    let csvContent = "\uFEFF";
    csvContent += "Tipo,Usuario,Detalle,IP,Fecha/Hora\n";
    
    if (datosActuales.actividad && datosActuales.actividad.length > 0) {
        datosActuales.actividad.forEach(act => {
            const tipo = act.tipo || 'otro';
            const usuario = act.usuario || '—';
            const detalle = (act.detalle || '—').replace(/,/g, ';').replace(/\n/g, ' ');
            const ip = act.ip || '—';
            const fecha = act.fecha_hora || '—';
            csvContent += `"${tipo}","${usuario}","${detalle}","${ip}","${fecha}"\n`;
        });
    }
    
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

// ============================================
// AUTO-SCROLL PARA TV
// ============================================
function startAutoScroll() {
    if (autoScrollInterval) clearInterval(autoScrollInterval);
    autoScrollInterval = setInterval(() => {
        if (autoScrollEnabled && document.visibilityState === 'visible') {
            const actFeed = document.querySelector('.act-feed');
            if (actFeed) {
                actFeed.scrollTop += 50;
                if (actFeed.scrollTop + actFeed.clientHeight >= actFeed.scrollHeight) {
                    actFeed.scrollTop = 0;
                }
            }
        }
    }, 5000);
}

// Detectar interacción del usuario para pausar auto-scroll
document.addEventListener('keydown', () => {
    autoScrollEnabled = false;
    setTimeout(() => { autoScrollEnabled = true; }, 10000);
});
document.addEventListener('click', () => {
    autoScrollEnabled = false;
    setTimeout(() => { autoScrollEnabled = true; }, 10000);
});

// ============================================
// CARGAR DATOS DESDE API
// ============================================
let cargando = false;

function cargarDatos() {
    if (cargando) return;
    cargando = true;
    
    const fechaInicio = document.getElementById('fecha-inicio').value;
    const fechaFin = document.getElementById('fecha-fin').value;
    
    actualizarBadgeFecha(fechaInicio, fechaFin);
    
    fetch(`api_monitor.php?fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&t=${Date.now()}`)
        .then(r => {
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
        })
        .then(data => {
            // Manejar respuesta
            if (!data || (data.success === false && data.exitoso !== true)) {
                throw new Error(data.error || 'Respuesta inválida');
            }
            
            datosActuales = data;
            
            const spinner = document.getElementById('spinner');
            const mainContent = document.getElementById('main-content');
            if (spinner) spinner.classList.add('hidden');
            if (mainContent) mainContent.classList.remove('hidden');
            
            // Actualizar estadísticas
            const elementos = {
                's-online': data.total_conectados || (data.estadisticas && data.estadisticas.empleados_online) || 0,
                's-admins': data.admins_activos || (data.estadisticas && data.estadisticas.admins_activos) || 0,
                's-emps': data.empleados_activos || (data.estadisticas && data.estadisticas.empleados_activos_hoy) || 0,
                's-total': data.total_empleados || (data.estadisticas && data.estadisticas.total_empleados) || 0,
                's-acciones': ((Array.isArray(data.actividad) ? data.actividad.length : 0) || 0) + ((data.estadisticas && data.estadisticas.total_acciones) || 0),
                's-activos-hoy': data.activos_hoy || data.empleados_activos || 0,
                'db-total': data.total_empleados || 0,
                'db-conex': data.total_conectados || 0
            };
            
            for (const [id, valor] of Object.entries(elementos)) {
                const el = document.getElementById(id);
                if (el) el.textContent = typeof valor === 'number' ? valor.toLocaleString() : valor;
            }
            
            const dbLast = document.getElementById('db-last');
            if (dbLast) dbLast.textContent = data.ultimo_insert_db || '—';
            
            const dbSize = document.getElementById('db-size');
            if (dbSize) dbSize.textContent = data.db_size_mb ? data.db_size_mb + ' MB' : '—';
            
            const serverLoad = (data.server_info && data.server_info.load) ? data.server_info.load : 35;
            actualizarBarras(serverLoad, data.total_conectados || 0);
            
            renderUsuarios(data.sesiones || data.sesiones_activas || []);
            renderActividad(data.actividad || []);
            renderAlertas(data);
            
            const lastUpdate = document.getElementById('last-update');
            if (lastUpdate) lastUpdate.textContent = `🔄 Actualizado: ${new Date().toLocaleTimeString('es-CR')}`;
        })
        .catch(err => {
            console.error('Error:', err);
            const spinner = document.getElementById('spinner');
            if (spinner) spinner.innerHTML = '<div>❌ Error de conexión con el servidor</div>';
        })
        .finally(() => {
            cargando = false;
        });
}

// ============================================
// FUNCIONES DE FECHAS RÁPIDAS
// ============================================
function irAHoy() {
    const hoy = formatFechaLocal(new Date());
    const fechaInicio = document.getElementById('fecha-inicio');
    const fechaFin = document.getElementById('fecha-fin');
    if (fechaInicio) fechaInicio.value = hoy;
    if (fechaFin) fechaFin.value = hoy;
    cargarDatos();
}

function irASemana() {
    const hoy = new Date();
    const diaSemana = hoy.getDay();
    const diasALunes = (diaSemana === 0 ? 6 : diaSemana - 1);
    const inicio = new Date(hoy);
    inicio.setDate(hoy.getDate() - diasALunes);
    const fin = new Date(inicio);
    fin.setDate(inicio.getDate() + 6);
    
    const fechaInicio = document.getElementById('fecha-inicio');
    const fechaFin = document.getElementById('fecha-fin');
    if (fechaInicio) fechaInicio.value = formatFechaLocal(inicio);
    if (fechaFin) fechaFin.value = formatFechaLocal(fin);
    cargarDatos();
}

function irAMes() {
    const hoy = new Date();
    const inicio = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
    const fin = new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0);
    
    const fechaInicio = document.getElementById('fecha-inicio');
    const fechaFin = document.getElementById('fecha-fin');
    if (fechaInicio) fechaInicio.value = formatFechaLocal(inicio);
    if (fechaFin) fechaFin.value = formatFechaLocal(fin);
    cargarDatos();
}

// ============================================
// EVENTOS
// ============================================
const btnCargarFecha = document.getElementById('btn-cargar-fecha');
if (btnCargarFecha) btnCargarFecha.addEventListener('click', cargarDatos);
const btnHoy = document.getElementById('btn-hoy');
if (btnHoy) btnHoy.addEventListener('click', irAHoy);
const btnSemana = document.getElementById('btn-semana');
if (btnSemana) btnSemana.addEventListener('click', irASemana);
const btnMes = document.getElementById('btn-mes');
if (btnMes) btnMes.addEventListener('click', irAMes);
const btnExportarCSV = document.getElementById('btn-exportar-csv');
if (btnExportarCSV) btnExportarCSV.addEventListener('click', exportarCSV);

// Navegación por teclado para control remoto
document.addEventListener('keydown', (e) => {
    const focusable = document.querySelector('.focusable:focus');
    if (!focusable) return;
    
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'Space') {
        focusable.click();
        e.preventDefault();
    }
});

// ============================================
// INICIALIZACIÓN
// ============================================
cargarDatos();
setInterval(cargarDatos, 10000); // Actualizar cada 10 segundos
startAutoScroll();

document.addEventListener('visibilitychange', () => {
    if (!document.hidden) cargarDatos();
});

// ============================================
// TRACKING
// ============================================
(function() {
    const pagina = window.location.pathname.split('/').pop().replace('.php', '');
    fetch('track.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ modulo: pagina, pagina: window.location.pathname, timestamp: Date.now() })
    }).catch(() => {});
})();
</script>
</body>
</html>