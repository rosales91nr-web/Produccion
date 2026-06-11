<!DOCTYPE html>
<?php
/**
 * FRONTEND - ia_queries (frontend.php)
 * Solo renderiza la vista. Los datos llegan desde backend.php (require al inicio).
 *
 * USO: Este archivo debe incluirse DESPUÉS de ejecutar backend.php, o bien
 * el archivo principal (ia_queries.php) hace require de backend.php y luego
 * de este frontend.php.
 *
 * Variables PHP esperadas (provistas por backend.php):
 *  $filtros, $mostrar_filtros, $total_produccion, $total_quiebras,
 *  $eficiencia_global, $top_ordenes, $top_empleados_quiebras,
 *  $top_empleados_produccion, $top_equipos, $top_responsables,
 *  $timeline_data, $top_motivos, $quiebras_turno, $areas_lista,
 *  $timeline_json, $top_motivos_json, $quiebras_turno_json,
 *  $top_empleados_produccion_json, $areas_lista_json,
 *  $top_equipos_json, $top_responsables_json
 */
?>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Analítico | Sistema IA</title>
    <meta name="description" content="Dashboard de análisis de producción y quiebras">
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
          rel="stylesheet" media="print" onload="this.media='all'">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"
          rel="stylesheet" media="print" onload="this.media='all'">

    <script src="https://cdn.tailwindcss.com/3.3.0"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>

    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #059669;
            --danger: #dc2626;
            --warning: #d97706;
            --info: #0891b2;
            --bg-body: #f3f4f6;
            --card-bg: #ffffff;
            --text-primary: #111827;
            --text-secondary: #4b5563;
            --text-muted: #6b7280;
            --border-light: #e5e7eb;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            padding-bottom: 50px;
        }
        .card {
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.05), 0 1px 2px -1px rgb(0 0 0 / 0.05);
            border: 1px solid var(--border-light);
            transition: all 0.2s ease;
        }
        .card:hover {
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }
        .tab-content { display: none; animation: fadeIn .25s ease-out; }
        .tab-content.active { display: block; }
        .tab-button {
            transition: all 0.2s ease;
            color: var(--text-secondary);
            font-weight: 500;
            border-radius: 12px;
        }
        .tab-button:hover {
            background-color: #f3f4f6;
            color: var(--primary);
        }
        .tab-button.active {
            background-color: #eff6ff;
            color: var(--primary);
            font-weight: 600;
        }
        .chart-container { height: 300px; position: relative; }
        .kpi-card {
            transition: all 0.2s ease;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1);
        }
        .spinner {
            width: 40px; height: 40px;
            border: 3px solid #e5e7eb;
            border-left-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
        .custom-scroll {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f1f5f9;
        }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .custom-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        [data-tooltip] { position: relative; cursor: help; }
        [data-tooltip]:hover::before {
            content: attr(data-tooltip);
            position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%);
            background: #1f2937; color: #fff; padding: 6px 10px; border-radius: 8px;
            font-size: 12px; white-space: nowrap; z-index: 1000; margin-bottom: 5px;
        }
        .hover-lift {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .hover-lift:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.15);
        }
        @media (max-width: 640px) {
            .chart-container { height: 250px; }
            .tab-button { padding: 8px 12px; font-size: 13px; }
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-warning { background: #fed7aa; color: #92400e; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-neutral { background: #f3f4f6; color: #1f2937; }
        .table-header {
            background-color: #f9fafb;
            font-weight: 600;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border-light);
        }
        .input-light {
            background-color: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 0.625rem 1rem;
            transition: all 0.2s;
        }
        .input-light:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .no-data-message {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            min-height: 200px;
            color: #9ca3af;
        }
        .firma-inferior {
            position: fixed; bottom: 0; left: 0; right: 0;
            text-align: center; background: #ffffff; color: #4b5563;
            padding: 8px 5px; font-size: 12px; z-index: 9999;
            border-top: 1px solid var(--border-light);
            box-shadow: 0 -1px 3px rgba(0,0,0,0.05);
        }
        /* Estilos para el indicador de mes en WIP */
        .wip-month-indicator {
            font-size: 0.7rem;
            background: #fff3e0;
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            color: #c2410c;
        }
    </style>
</head>
<body class="antialiased">

    <!-- Loading Screen -->
    <div id="loadingScreen" class="fixed inset-0 bg-white/95 z-50 flex items-center justify-center">
        <div class="text-center">
            <div class="spinner mx-auto mb-4"></div>
            <p class="text-lg font-semibold text-gray-700">Cargando dashboard...</p>
            <p class="text-sm text-gray-500 mt-2">Por favor espere</p>
        </div>
    </div>

    <div class="max-w-7xl mx-auto p-4 lg:p-6">

        <!-- ── HEADER ───────────────────────────────────────── -->
        <div class="card p-6 mb-6 hover-lift">
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-3 text-gray-800">
                        <i class="bi bi-graph-up-arrow text-blue-600 text-3xl"></i>
                        Dashboard Analítico
                    </h1>
                    <p class="text-gray-500 mt-1 text-sm">Análisis de Producción y Quiebras</p>
                    <div class="flex items-center gap-2 mt-2 text-xs text-gray-400">
                        <i class="bi bi-calendar3"></i>
                        <span><?= date('d/m/Y', strtotime($filtros['fecha_inicio'])) ?> – <?= date('d/m/Y', strtotime($filtros['fecha_fin'])) ?></span>
                                        <div class="relative inline-block ml-2 group">
                    <i class="bi bi-info-circle-fill text-gray-400 hover:text-blue-500 cursor-help text-sm"></i>
                    <div class="absolute bottom-full left-0 mb-2 hidden group-hover:block bg-gray-800 text-white text-xs rounded-lg p-2 w-64 z-10 shadow-xl">
                        <p class="font-semibold mb-1">🎯 Filtros actuales:</p>
                        <p>📅 Fechas: <?= date('d/m/Y', strtotime($filtros['fecha_inicio'])) ?> → <?= date('d/m/Y', strtotime($filtros['fecha_fin'])) ?></p>
                        <p>⏰ Horas: <?= tiempo24a12($filtros['hora_inicio']) ?> → <?= tiempo24a12($filtros['hora_fin']) ?></p>
                        <?php if (rangoCruzaMedianoche($filtros['hora_inicio'], $filtros['hora_fin'])): ?>
                        <p class="text-orange-300">🌙 El rango horario cruza medianoche</p>
                        <?php endif; ?>
                        <p class="mt-1 text-gray-400 text-xs">Los filtros afectan todos los gráficos y tablas</p>
                    </div>
                </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2 bg-gray-50 px-4 py-2 rounded-full">
                        <i class="bi bi-person-circle text-blue-500"></i>
                        <div>
                            <span class="font-medium text-gray-700 text-sm"><?= htmlspecialchars($_SESSION['empleado'] ?? 'Admin') ?></span>
                            <div class="text-xs text-gray-400"><?= htmlspecialchars($_SESSION['rol'] ?? 'Usuario') ?></div>
                        </div>
                    </div>
                    <a href="login_admin.php"
                       class="inline-flex items-center gap-2 text-gray-500 hover:text-gray-700 bg-gray-50 hover:bg-gray-100 px-4 py-2 rounded-full transition text-sm">
                        <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                    </a>
                </div>
            </div>
        </div>

        <!-- ── FILTROS ───────────────────────────────────────── -->
        <div class="card p-5 mb-6">
            <form method="GET" id="filterForm" class="space-y-4">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($_GET['tab'] ?? 'resumen') ?>">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-gray-600 font-medium mb-1 text-sm">
                            <i class="bi bi-calendar-check"></i> Fecha Inicio
                        </label>
                        <input type="date" name="fecha_inicio"
                               class="input-light w-full"
                               value="<?= htmlspecialchars($mostrar_filtros['fecha_inicio']) ?>"
                               max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label class="block text-gray-600 font-medium mb-1 text-sm">
                            <i class="bi bi-calendar-x"></i> Fecha Fin
                        </label>
                        <input type="date" name="fecha_fin"
                               class="input-light w-full"
                               value="<?= htmlspecialchars($mostrar_filtros['fecha_fin']) ?>"
                               max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label class="block text-gray-600 font-medium mb-1 text-sm">
                            <i class="bi bi-clock"></i> Hora Inicio
                        </label>
                        <div class="flex gap-2">
                            <input type="time" name="hora_inicio_time"
                                   class="input-light flex-1"
                                   value="<?= htmlspecialchars($mostrar_filtros['hora_inicio_time']) ?>" required>
                            <select name="hora_inicio_ampm"
                                    class="input-light w-20">
                                <option value="AM" <?= $mostrar_filtros['hora_inicio_ampm'] === 'AM' ? 'selected' : '' ?>>AM</option>
                                <option value="PM" <?= $mostrar_filtros['hora_inicio_ampm'] === 'PM' ? 'selected' : '' ?>>PM</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-gray-600 font-medium mb-1 text-sm">
                            <i class="bi bi-clock-history"></i> Hora Fin
                        </label>
                        <div class="flex gap-2">
                            <input type="time" name="hora_fin_time"
                                   class="input-light flex-1"
                                   value="<?= htmlspecialchars($mostrar_filtros['hora_fin_time']) ?>" required>
                            <select name="hora_fin_ampm"
                                    class="input-light w-20">
                                <option value="AM" <?= $mostrar_filtros['hora_fin_ampm'] === 'AM' ? 'selected' : '' ?>>AM</option>
                                <option value="PM" <?= $mostrar_filtros['hora_fin_ampm'] === 'PM' ? 'selected' : '' ?>>PM</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit"
                            class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl transition flex items-center justify-center font-semibold gap-2 shadow-sm">
                        <i class="bi bi-funnel-fill"></i> Aplicar Filtros
                    </button>
                    <button type="button" id="resetBtn"
                            class="px-5 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl transition"
                            data-tooltip="Restablecer filtros">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                    <button type="button" id="exportBtn"
                            class="px-5 py-2.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded-xl transition"
                            data-tooltip="Exportar datos">
                        <i class="bi bi-download"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- ── TABS ──────────────────────────────────────────── -->
        <div class="mb-6 border-b border-gray-200 bg-white rounded-t-xl p-1">
            <div class="flex flex-wrap gap-1">
                <?php
                $tabs = [
                    ['id'=>'resumen',      'icon'=>'speedometer2',  'label'=>'Resumen'],
                    ['id'=>'ordenes',      'icon'=>'clipboard-x',   'label'=>'Órdenes'],
                    ['id'=>'empleados',    'icon'=>'people',        'label'=>'Empleados'],
                    ['id'=>'equipos',      'icon'=>'cpu',           'label'=>'Equipos'],
                    ['id'=>'analisis',     'icon'=>'graph-up',      'label'=>'Análisis'],
                    ['id'=>'responsables', 'icon'=>'person-gear',   'label'=>'Responsables'],
                    ['id'=>'produccion-vivo', 'icon'=>'broadcast',  'label'=>'Producción en Vivo'],
                ];
                $activeTab = $_GET['tab'] ?? 'resumen';
                foreach ($tabs as $t):
                    $isActive = $activeTab === $t['id'] ? 'active' : '';
                ?>
                <button class="tab-button py-2.5 px-5 flex items-center gap-2 transition-all <?= $isActive ?>"
                        data-tab="<?= $t['id'] ?>">
                    <i class="bi bi-<?= $t['icon'] ?>"></i>
                    <span class="hidden sm:inline"><?= $t['label'] ?></span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ╔══════════════════════════════════════════════════╗
             ║  TAB: RESUMEN                                    ║
             ╚══════════════════════════════════════════════════╝ -->
        <div id="resumen" class="tab-content <?= $activeTab === 'resumen' ? 'active' : '' ?>">

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
<div class="card p-5 kpi-card border-l-4 border-l-emerald-500">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-gray-500 text-sm font-medium">Salidas Final</p>
            <p class="text-3xl font-bold text-emerald-600 mt-1"><?= format_number($total_produccion) ?></p>
            
            <?php if ($mostrar_ordenes_periodo): ?>
            <p class="text-xs text-gray-400 mt-2">
                <i class="bi bi-check-circle text-emerald-500"></i> 
                <?= format_number($total_ordenes_mes_completo) ?> órdenes en el período
            </p>
            <?php endif; ?>
            
            <?php if ($mostrar_mes_info): ?>
            <p class="text-xs text-gray-400">
                <i class="bi bi-calendar3"></i> 
                <?= $nombre_mes ?> <?= date('Y', strtotime($filtros['fecha_inicio'])) ?>
                <br><span class="text-gray-400 text-xs"><?= $rango_mes ?></span>
            </p>
            <?php endif; ?>
        </div>
        <div class="p-3 bg-emerald-50 rounded-full">
            <i class="bi bi-check-circle-fill text-2xl text-emerald-500"></i>
        </div>
    </div>
</div>
                <div class="card p-5 kpi-card border-l-4 border-l-red-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm font-medium">Quiebras</p>
                            <p class="text-3xl font-bold text-red-600 mt-1"><?= format_number($total_quiebras) ?></p>
                            <p class="text-xs text-gray-400 mt-2"><i class="bi bi-exclamation-triangle text-red-500"></i> Total detectadas</p>
                        </div>
                        <div class="p-3 bg-red-50 rounded-full">
                            <i class="bi bi-exclamation-triangle-fill text-2xl text-red-500"></i>
                        </div>
                    </div>
                </div>
                <div class="card p-5 kpi-card border-l-4 border-l-cyan-500">
                    <div class="flex items-center justify-between">
                        <div class="flex-1">
                            <p class="text-gray-500 text-sm font-medium">Eficiencia Global</p>
                            <p class="text-3xl font-bold text-cyan-600 mt-1"><?= $eficiencia_global ?>%</p>
                            <div class="mt-2 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                <div class="h-full bg-cyan-500 rounded-full transition-all duration-500"
                                     style="width: <?= $eficiencia_global ?>%"></div>
                            </div>
                        </div>
                        <div class="p-3 bg-cyan-50 rounded-full">
                            <i class="bi bi-speedometer2 text-2xl text-cyan-500"></i>
                        </div>
                    </div>
                </div>
                <div class="card p-5 kpi-card border-l-4 border-l-amber-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm font-medium">Rango Analizado</p>
                            <p class="text-lg font-bold text-amber-600 mt-1">
                                <?= date('d/m', strtotime($filtros['fecha_inicio'])) ?> –
                                <?= date('d/m', strtotime($filtros['fecha_fin'])) ?>
                            </p>
                            <p class="text-xs text-gray-400 mt-1">
                                <i class="bi bi-clock"></i>
                                <?= tiempo24a12($filtros['hora_inicio']) ?> –
                                <?= tiempo24a12($filtros['hora_fin']) ?>
                            </p>
                        </div>
                        <div class="p-3 bg-amber-50 rounded-full">
                            <i class="bi bi-calendar3 text-2xl text-amber-500"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gráficos fila 1 -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <!-- Tendencia de Quiebras (período filtrado) - ocupa 2 columnas -->
                <div class="card p-5 lg:col-span-2">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="bi bi-graph-up text-blue-500"></i> Tendencia de Quiebras
                    </h3>
                    <div class="chart-container" style="height: 320px;"><canvas id="timelineChart"></canvas></div>
                    <div class="mt-4 pt-3 border-t border-gray-100">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center">
                            <div class="bg-blue-50 rounded-lg p-2">
                                <p class="text-xs text-gray-500">Promedio diario</p>
                                <p class="text-xl font-bold text-blue-600" id="promedioQuiebrasVal">--</p>
                            </div>
                            <div class="bg-cyan-50 rounded-lg p-2">
                                <p class="text-xs text-gray-500">Mediana diaria</p>
                                <p class="text-xl font-bold text-cyan-600" id="medianaQuiebrasVal">--</p>
                            </div>
                            <div class="bg-amber-50 rounded-lg p-2">
                                <p class="text-xs text-gray-500">Máximo diario</p>
                                <p class="text-xl font-bold text-amber-600" id="maximoQuiebrasVal">--</p>
                            </div>
                            <div class="bg-emerald-50 rounded-lg p-2">
                                <p class="text-xs text-gray-500">Mínimo diario</p>
                                <p class="text-xl font-bold text-emerald-600" id="minimoQuiebrasVal">--</p>
                            </div>
                        </div>
                        <p class="text-xs text-gray-400 text-center mt-2" id="infoDiasQuiebras"></p>
                    </div>
                </div>

                <!-- Distribución por Turno - ocupa 1 columna -->
                <div class="card p-5">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="bi bi-pie-chart-fill text-amber-500"></i> Distribución por Turno
                    </h3>
                    <div class="chart-container" style="height: 280px;"><canvas id="turnoChart"></canvas></div>
                    
                    <!-- Tarjetas de información por turno -->
                    <div class="mt-4 pt-3 border-t border-gray-200">
                        <p class="text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wide flex items-center gap-1">
                            <i class="bi bi-bar-chart-steps"></i> Desglose por Turno
                        </p>
                        <div id="turnoDetalleContainer" class="grid grid-cols-1 gap-2">
                            <!-- Los datos se llenarán desde JavaScript -->
                            <div class="bg-gray-100 animate-pulse rounded-xl p-2 h-16"></div>
                            <div class="bg-gray-100 animate-pulse rounded-xl p-2 h-16"></div>
                            <div class="bg-gray-100 animate-pulse rounded-xl p-2 h-16"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gráficos fila 2: Eficiencia últimos 6 meses -->
            <div class="grid grid-cols-1 gap-6 mb-6">
                <div class="card p-5">
                    <div class="flex justify-between items-center mb-4 flex-wrap gap-2">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="bi bi-bar-chart-fill text-emerald-500"></i> Eficiencia — Últimos 6 Meses
                        </h3>
                        <span class="text-xs bg-emerald-50 text-emerald-700 px-3 py-1 rounded-full font-medium">
                            <i class="bi bi-arrow-repeat"></i> Auto-calculado
                        </span>
                    </div>
                    <div class="chart-container" style="height:270px;"><canvas id="eficiencia6mChart"></canvas></div>
                    <div class="mt-3 pt-3 border-t border-gray-100 space-y-2">
                        <!-- Fila de órdenes OK por mes -->
                        <div id="eficienciaOrdenesRow" class="flex flex-wrap gap-2 justify-center min-h-[24px]">
                            <!-- Poblado por JS -->
                        </div>
                        <div class="flex justify-between items-center flex-wrap gap-2 text-xs text-gray-500 pt-1">
                            <span><i class="bi bi-info-circle"></i> Basado en órdenes Empaque vs Quiebras</span>
                            <span id="eficienciaPromedioLabel" class="font-semibold text-emerald-600">--</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Áreas -->
            <div class="card p-5 mt-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                        <i class="bi bi-building text-cyan-600"></i> Áreas con Actividad
                    </h3>
                    <span class="badge badge-neutral text-sm">
                        <?= count($areas_lista) ?> áreas
                    </span>
                </div>

                <?php if (empty($areas_lista)): ?>
                    <div class="text-center py-8 text-gray-400">
                        <i class="bi bi-inbox text-4xl mb-3 opacity-50"></i>
                        <p>No hay áreas con actividad en el período</p>
                    </div>
                <?php else:
                    usort($areas_lista, fn($a,$b) => (int)$b['total_produccion'] - (int)$a['total_produccion']); ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($areas_lista as $area):
                            $prod = (int)($area['total_produccion'] ?? 0);
                            $quie = (int)($area['total_quiebras']   ?? 0);
                            $eqc  = (int)($area['empleados_con_quiebras'] ?? 0);
                            $tc   = $prod + $quie;
                            $ef   = $tc > 0 ? round(max(0, min(100, 100 - ($quie / $tc) * 100)), 1) : 100;
                            $efClass = $ef >= 95 ? 'text-emerald-600' : ($ef >= 90 ? 'text-amber-600' : 'text-red-600');
                            $barClass = $ef >= 95 ? 'bg-emerald-500' : ($ef >= 90 ? 'bg-amber-500' : 'bg-red-500');
                        ?>
                        <div class="bg-gray-50 p-4 rounded-xl hover:bg-gray-100 transition cursor-pointer hover:shadow-md"
                             onclick="mostrarDetallesArea('<?= htmlspecialchars(addslashes($area['area'])) ?>')"
                             data-tooltip="Click para ver detalles completos">
                            <div class="flex justify-between items-start">
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-gray-800 truncate text-sm">
                                        <?= htmlspecialchars(mb_strimwidth($area['area'], 0, 30, '...')) ?>
                                    </p>
                                    <div class="mt-2 space-y-1">
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-gray-500">Producción:</span>
                                            <span class="text-emerald-600 font-semibold"><?= format_number($prod) ?></span>
                                        </div>
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-gray-500">Quiebras:</span>
                                            <span class="text-red-600 font-semibold"><?= format_number($quie) ?></span>
                                        </div>
                                        <?php if ($eqc > 0): ?>
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-gray-500">Emp. con quiebras:</span>
                                            <span class="text-amber-600 font-semibold"><?= $eqc ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-gray-500">Eficiencia:</span>
                                            <span class="<?= $efClass ?> font-semibold"><?= $ef ?>%</span>
                                        </div>
                                    </div>
                                </div>
                                <i class="bi bi-arrow-right-circle text-gray-400 text-xl ml-2 mt-1"></i>
                            </div>
                            <div class="mt-3 h-1 bg-gray-200 rounded-full overflow-hidden">
                                <div class="h-full <?= $barClass ?> rounded-full" style="width: <?= $ef ?>%"></div>
                            </div>
                            <?php if ($quie > 0): ?>
                            <div class="mt-2 flex items-center gap-1 text-xs text-gray-500">
                                <i class="bi bi-exclamation-triangle text-red-500"></i>
                                <span><?= $quie ?> quiebras detectadas</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div><!-- /resumen -->

        <!-- ╔══════════════════════════════════════════════════╗
             ║  TAB: ÓRDENES                                    ║
             ╚══════════════════════════════════════════════════╝ -->
        <div id="ordenes" class="tab-content <?= $activeTab === 'ordenes' ? 'active' : '' ?>">
            <div class="card p-5">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 gap-4">
                    <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                        <i class="bi bi-clipboard-x-fill text-red-500"></i> Órdenes con más Quiebras
                    </h3>
                    <div class="flex gap-2">
                        <span class="badge badge-neutral"><?= count($top_ordenes) ?> órdenes</span>
                        <button class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded-lg text-sm transition"
                                onclick="exportarTabla('tablaOrdenes','ordenes_quiebras.csv')">
                            <i class="bi bi-download mr-1"></i> Exportar
                        </button>
                    </div>
                </div>

                <?php if (empty($top_ordenes)): ?>
                    <div class="text-center py-12 text-gray-400">
                        <i class="bi bi-inbox text-5xl mb-4 opacity-30"></i>
                        <p class="text-lg">No hay datos de órdenes en el período</p>
                    </div>
                <?php else: ?>
                <div class="overflow-x-auto custom-scroll rounded-lg border border-gray-200" id="tablaOrdenes">
                    <table class="w-full text-sm">
                        <thead class="table-header">
                            <tr>
                                <th class="p-3 text-left">Orden</th>
                                <th class="p-3 text-left">Quiebras</th>
                                <th class="p-3 text-left">Motivos</th>
                                <th class="p-3 text-left">Empleados</th>
                                <th class="p-3 text-left">Equipos</th>
                                <th class="p-3 text-left">Período</th>
                                <th class="p-3 text-left">Último Movimiento</th>
                                <th class="p-3 text-left">Área/Equipo</th>
                                <th class="p-3 text-left">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_ordenes as $ord): ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                                <td class="p-3 font-mono font-medium text-gray-700">
                                    <span class="bg-gray-100 px-2 py-1 rounded text-xs"><?= htmlspecialchars($ord['orden']) ?></span>
                                </td>
                                <td class="p-3">
                                    <span class="badge-danger"><?= $ord['total_quiebras'] ?></span>
                                </td>
                                <td class="p-3 text-sm text-gray-600 max-w-xs">
                                    <div class="truncate" title="<?= htmlspecialchars($ord['motivos']) ?>">
                                        <?= htmlspecialchars(mb_strimwidth($ord['motivos'], 0, 40, '...')) ?>
                                    </div>
                                </td>
                                <td class="p-3 text-sm text-gray-600 max-w-xs">
                                    <div class="truncate" title="<?= htmlspecialchars($ord['empleados']) ?>">
                                        <?= htmlspecialchars(mb_strimwidth($ord['empleados'], 0, 30, '...')) ?>
                                    </div>
                                </td>
                                <td class="p-3 text-sm text-gray-600 max-w-xs">
                                    <div class="truncate" title="<?= htmlspecialchars($ord['equipos']) ?>">
                                        <?= htmlspecialchars(mb_strimwidth($ord['equipos'], 0, 30, '...')) ?>
                                    </div>
                                </td>
                                <td class="p-3 text-xs text-gray-500 whitespace-nowrap">
                                    <?= $ord['primera_quiebra'] ?> – <?= $ord['ultima_quiebra'] ?>
                                </td>
                                <td class="p-3">
                                    <?php if ($ord['ultimo_movimiento_completo'] !== 'N/A'): ?>
                                    <div class="flex flex-col">
                                        <span class="text-xs text-emerald-600 font-mono">
                                            <?= $ord['ultimo_movimiento_fecha'] ?> <?= $ord['ultimo_movimiento_hora'] ?>
                                        </span>
                                        <span class="text-xs text-gray-400"><?= $ord['fuente_ultimo_mov'] ?></span>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-xs text-gray-400">Sin registro</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3">
                                    <?php if ($ord['ultimo_movimiento_area'] !== 'N/A'): ?>
                                    <div class="flex flex-col">
                                        <span class="text-xs text-cyan-600"><?= htmlspecialchars(mb_strimwidth($ord['ultimo_movimiento_area'], 0, 20, '...')) ?></span>
                                        <span class="text-xs text-purple-600"><?= htmlspecialchars(mb_strimwidth($ord['ultimo_movimiento_equipo'], 0, 15, '...')) ?></span>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-xs text-gray-400">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3">
                                    <div class="flex items-center gap-1">
                                    <button onclick="mostrarDetallesOrden('<?= htmlspecialchars(addslashes($ord['orden'])) ?>')"
                                            class="bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-1.5 rounded-lg text-sm transition">
                                        <i class="bi bi-eye"></i> <span class="hidden sm:inline">Ver</span>
                                    </button>
                                    <?php if ($_SESSION['rol'] === 'administrador'): ?>
                                    <button onclick="eliminarOrdenCompleta('<?= htmlspecialchars(addslashes($ord['orden'])) ?>')"
                                            class="bg-red-50 hover:bg-red-100 text-red-600 px-3 py-1.5 rounded-lg text-sm transition"
                                            title="Eliminar orden (solo si está cancelada en Odoo)">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                    <?php endif; ?>
                                    </div>
                                </td>
                             </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div><!-- /ordenes -->

        <!-- ╔══════════════════════════════════════════════════╗
             ║  TAB: EMPLEADOS                                  ║
             ╚══════════════════════════════════════════════════╝ -->
        <div id="empleados" class="tab-content <?= $activeTab === 'empleados' ? 'active' : '' ?>">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="card p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="bi bi-person-exclamation text-amber-500"></i> Con Quiebras
                        </h3>
                        <span class="badge badge-neutral"><?= count($top_empleados_quiebras) ?></span>
                    </div>
                    <?php if (empty($top_empleados_quiebras)): ?>
                        <div class="text-center py-8 text-gray-400"><i class="bi bi-inbox text-4xl opacity-30"></i><p>Sin datos</p></div>
                    <?php else: ?>
                        <div class="space-y-3 max-h-[500px] overflow-y-auto custom-scroll pr-2">
                            <?php foreach ($top_empleados_quiebras as $emp): ?>
                            <div onclick="mostrarDetallesEmpleado('<?= htmlspecialchars(addslashes($emp['empleado'])) ?>')"
                                 class="bg-gray-50 hover:bg-gray-100 p-4 rounded-xl cursor-pointer transition border border-gray-100 hover:shadow-sm">
                                <div class="flex justify-between items-center">
                                    <div class="flex-1 min-w-0">
                                        <strong class="block text-gray-800 truncate"><?= htmlspecialchars($emp['empleado']) ?></strong>
                                        <div class="flex flex-wrap gap-2 mt-2">
                                            <span class="badge-danger text-xs">
                                                <?= format_number($emp['total_quiebras']) ?> quiebras
                                            </span>
                                            <?php if ($emp['ordenes_afectadas'] > 0): ?>
                                            <span class="badge-warning text-xs">
                                                <?= $emp['ordenes_afectadas'] ?> órdenes
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full font-bold text-sm">
                                        <?= format_number($emp['total_quiebras']) ?>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="bi bi-person-check-fill text-emerald-600"></i> Con Producción
                        </h3>
                        <span class="badge badge-neutral"><?= count($top_empleados_produccion) ?></span>
                    </div>
                    <?php if (empty($top_empleados_produccion)): ?>
                        <div class="text-center py-8 text-gray-400"><i class="bi bi-inbox text-4xl opacity-30"></i><p>Sin datos</p></div>
                    <?php else: ?>
                        <div class="space-y-3 max-h-[500px] overflow-y-auto custom-scroll pr-2">
                            <?php foreach ($top_empleados_produccion as $emp): ?>
                            <div onclick="mostrarDetallesEmpleado('<?= htmlspecialchars(addslashes($emp['empleado'])) ?>')"
                                 class="bg-gray-50 hover:bg-gray-100 p-4 rounded-xl cursor-pointer transition border border-gray-100 hover:shadow-sm">
                                <div class="flex justify-between items-center">
                                    <div class="flex-1 min-w-0">
                                        <strong class="block text-gray-800 truncate"><?= htmlspecialchars($emp['empleado']) ?></strong>
                                        <div class="flex flex-wrap gap-2 mt-2">
                                            <span class="badge-success text-xs">
                                                <?= format_number($emp['total_produccion']) ?> prod
                                            </span>
                                            <span class="badge-info text-xs">
                                                <?= $emp['horas_trabajadas'] ?? 1 ?> hrs
                                            </span>
                                            <?php if (isset($emp['productividad_hora']) && $emp['productividad_hora'] > 0): ?>
                                            <span class="badge-warning text-xs">
                                                <?= $emp['productividad_hora'] ?>/h
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($emp['total_quiebras'] > 0): ?>
                                            <span class="badge-danger text-xs">
                                                <?= $emp['total_quiebras'] ?> quiebras
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex flex-col items-end gap-1">
                                        <span class="bg-emerald-100 text-emerald-700 px-3 py-1 rounded-full font-bold text-sm">
                                            <?= format_number($emp['total_produccion']) ?>
                                        </span>
                                        <span class="text-xs text-blue-600 bg-blue-50 px-2 py-0.5 rounded">
                                            <?= $emp['horas_trabajadas'] ?? 1 ?>h
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- /empleados -->

        <!-- ╔══════════════════════════════════════════════════╗
             ║  TAB: EQUIPOS                                    ║
             ╚══════════════════════════════════════════════════╝ -->
        <div id="equipos" class="tab-content <?= $activeTab === 'equipos' ? 'active' : '' ?>">
            <div class="card p-5">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 gap-4">
                    <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                        <i class="bi bi-cpu-fill text-purple-600"></i> Equipos con más Quiebras
                    </h3>
                    <div class="flex gap-2">
                        <span class="badge badge-neutral"><?= count($top_equipos) ?> equipos</span>
                        <button class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded-lg text-sm transition"
                                onclick="exportarEquipos()">
                            <i class="bi bi-download mr-1"></i> Exportar
                        </button>
                    </div>
                </div>

                <?php if (empty($top_equipos)): ?>
                    <div class="text-center py-12 text-gray-400"><i class="bi bi-inbox text-5xl opacity-30"></i><p>Sin datos</p></div>
                <?php else: ?>
                    <div class="overflow-x-auto custom-scroll rounded-lg border border-gray-200" id="tablaEquipos">
                        <table class="w-full text-sm">
                            <thead class="table-header">
                                <tr><th class="p-3 text-left">Equipo</th><th class="p-3 text-left">Quiebras</th><th class="p-3 text-left">Empleados</th><th class="p-3 text-left">Órdenes</th><th class="p-3 text-left">Motivos</th><th class="p-3 text-left">Áreas</th><th class="p-3 text-left">Período</th><th class="p-3 text-left">Acciones</th></tr></thead>
                            <tbody>
                                <?php foreach ($top_equipos as $eq): ?>
                                <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                                    <td class="p-3 font-semibold text-gray-700"><span class="bg-purple-50 text-purple-700 px-2 py-1 rounded text-xs"><?= htmlspecialchars($eq['equipo']) ?></span></td>
                                    <td class="p-3"><span class="badge-danger"><?= $eq['total_quiebras'] ?></span></td>
                                    <td class="p-3 text-sm text-gray-600"><div class="truncate max-w-[150px]" title="<?= htmlspecialchars($eq['empleados_relacionados']) ?>"><?= htmlspecialchars(mb_strimwidth($eq['empleados_relacionados'], 0, 25, '...')) ?></div><div class="text-xs text-gray-400 mt-1"><i class="bi bi-people"></i> <?= $eq['empleados_afectados'] ?></div></td>
                                    <td class="p-3"><span class="badge-warning text-xs"><?= $eq['ordenes_afectadas'] ?></span></td>
                                    <td class="p-3 text-sm text-gray-600 max-w-xs"><div class="truncate" title="<?= htmlspecialchars($eq['motivos_frecuentes']) ?>"><?= htmlspecialchars(mb_strimwidth($eq['motivos_frecuentes'], 0, 30, '...')) ?></div><div class="text-xs text-gray-400"><i class="bi bi-tags"></i> <?= $eq['motivos_diferentes'] ?> tipos</div></td>
                                    <td class="p-3"><span class="badge-info text-xs"><?= $eq['areas_afectadas'] ?> áreas</span></td>
                                    <td class="p-3 text-xs text-gray-500"><?= $eq['primera_quiebra'] ?><br><?= $eq['ultima_quiebra'] ?></td>
                                    <td class="p-3"><button onclick="mostrarDetallesEquipo('<?= htmlspecialchars(addslashes($eq['equipo'])) ?>')" class="bg-purple-50 hover:bg-purple-100 text-purple-700 px-3 py-1.5 rounded-lg text-sm transition"><i class="bi bi-eye"></i> <span class="hidden sm:inline">Ver</span></button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div><!-- /equipos -->

        <!-- ╔══════════════════════════════════════════════════╗
             ║  TAB: ANÁLISIS                                   ║
             ╚══════════════════════════════════════════════════╝ -->
        <div id="analisis" class="tab-content <?= $activeTab === 'analisis' ? 'active' : '' ?>">
            <div class="card p-5 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2"><i class="bi bi-bar-chart-fill text-cyan-600"></i> Análisis de Motivos</h3>
                    <span class="badge badge-neutral">Top <?= count($top_motivos) ?></span>
                </div>
                <div class="chart-container" style="height:400px"><canvas id="motivosChart"></canvas></div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="card p-5">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-calendar-heart text-blue-600"></i> Estadísticas Diarias</h3>
                    <?php
                    $dias_con_datos    = array_filter($timeline_data, fn($d) => $d['total'] > 0);
                    $promedio_diario   = count($dias_con_datos) > 0 ? array_sum(array_column($dias_con_datos, 'total')) / count($dias_con_datos) : 0;
                    $max_dia           = !empty($timeline_data) ? max(array_column($timeline_data, 'total')) : 0;
                    $min_dia           = count($dias_con_datos) > 0 ? min(array_column($dias_con_datos, 'total')) : 0;
                    $total_dias        = count($timeline_data);
                    ?>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-gray-50 p-4 rounded-xl"><p class="text-gray-500 text-sm">Promedio Diario</p><p class="text-2xl font-bold text-cyan-600"><?= number_format($promedio_diario, 1) ?></p></div>
                        <div class="bg-gray-50 p-4 rounded-xl"><p class="text-gray-500 text-sm">Día Máximo</p><p class="text-2xl font-bold text-red-600"><?= $max_dia ?></p></div>
                        <div class="bg-gray-50 p-4 rounded-xl"><p class="text-gray-500 text-sm">Día Mínimo</p><p class="text-2xl font-bold text-emerald-600"><?= $min_dia ?></p></div>
                        <div class="bg-gray-50 p-4 rounded-xl"><p class="text-gray-500 text-sm">Días con Datos</p><p class="text-2xl font-bold text-amber-600"><?= count($dias_con_datos) ?></p></div>
                        <div class="bg-gray-50 p-4 rounded-xl col-span-2"><p class="text-gray-500 text-sm">Días Totales / Con Datos</p><p class="text-2xl font-bold"><span class="text-blue-600"><?= $total_dias ?></span> / <span class="text-emerald-600"><?= count($dias_con_datos) ?></span></p><div class="mt-2 h-1.5 bg-gray-200 rounded-full overflow-hidden"><div class="h-full bg-emerald-500 rounded-full" style="width: <?= $total_dias > 0 ? round(count($dias_con_datos) / $total_dias * 100) : 0 ?>%"></div></div></div>
                    </div>
                </div>

                <div class="card p-5">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-info-circle text-emerald-600"></i> Información del Período</h3>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between pb-2 border-b border-gray-100"><div><p class="text-gray-500 text-sm">Rango de Fechas</p><p class="font-semibold text-gray-700"><?= date('d/m/Y', strtotime($filtros['fecha_inicio'])) ?> – <?= date('d/m/Y', strtotime($filtros['fecha_fin'])) ?></p></div><i class="bi bi-calendar-range text-xl text-gray-400"></i></div>
                        <div class="flex items-center justify-between pb-2 border-b border-gray-100"><div><p class="text-gray-500 text-sm">Horario Analizado</p><p class="font-semibold text-gray-700"><?= tiempo24a12($filtros['hora_inicio']) ?> – <?= tiempo24a12($filtros['hora_fin']) ?></p></div><i class="bi bi-clock-history text-xl text-gray-400"></i></div>
                        <div class="flex items-center justify-between pb-2 border-b border-gray-100"><div><p class="text-gray-500 text-sm">Total Registros</p><p class="font-semibold"><span class="text-emerald-600"><?= format_number($total_produccion) ?></span> producción | <span class="text-red-600"><?= format_number($total_quiebras) ?></span> quiebras</p></div><i class="bi bi-database text-xl text-gray-400"></i></div>
                        <div class="flex items-center justify-between"><div><p class="text-gray-500 text-sm">Eficiencia Global</p><p class="font-semibold text-2xl text-cyan-600"><?= $eficiencia_global ?>%</p><div class="mt-1 h-1.5 bg-gray-100 rounded-full overflow-hidden"><div class="h-full bg-cyan-500 rounded-full" style="width: <?= $eficiencia_global ?>%"></div></div></div><i class="bi bi-speedometer text-2xl text-cyan-600 opacity-50"></i></div>
                    </div>
                </div>
            </div>
        </div><!-- /analisis -->

        <!-- ╔══════════════════════════════════════════════════╗
             ║  TAB: RESPONSABLES                               ║
             ╚══════════════════════════════════════════════════╝ -->
        <div id="responsables" class="tab-content <?= $activeTab === 'responsables' ? 'active' : '' ?>">
            <div class="card p-5">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 gap-4">
                    <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2"><i class="bi bi-person-gear text-purple-600"></i> Responsables con más Quiebras</h3>
                    <div class="flex gap-2"><span class="badge badge-neutral"><?= count($top_responsables) ?> responsables</span><button class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded-lg text-sm transition" onclick="exportarTabla('tablaResponsables','responsables_quiebras.csv')"><i class="bi bi-download mr-1"></i> Exportar</button></div>
                </div>

                <?php if (empty($top_responsables)): ?>
                    <div class="text-center py-12 text-gray-400"><i class="bi bi-inbox text-5xl opacity-30"></i><p>Sin datos</p></div>
                <?php else: ?>
                    <div class="overflow-x-auto custom-scroll rounded-lg border border-gray-200" id="tablaResponsables">
                        <table class="w-full text-sm">
                            <thead class="table-header">
                                <tr><th class="p-3 text-left">Responsable</th><th class="p-3 text-left">Quiebras</th><th class="p-3 text-left">Empleados</th><th class="p-3 text-left">Órdenes</th><th class="p-3 text-left">Motivos</th><th class="p-3 text-left">Áreas</th><th class="p-3 text-left">Equipos</th><th class="p-3 text-left">Período</th><th class="p-3 text-left">Acciones</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_responsables as $resp): ?>
                                <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                                    <td class="p-3 font-semibold text-gray-700"><span class="bg-purple-50 text-purple-700 px-2 py-1 rounded text-xs"><?= htmlspecialchars($resp['responsable']) ?></span></td>
                                    <td class="p-3"><span class="badge-danger"><?= $resp['total_quiebras'] ?></span></td>
                                    <td class="p-3 text-sm text-gray-600"><div class="truncate max-w-[150px]" title="<?= htmlspecialchars($resp['empleados_relacionados']) ?>"><?= htmlspecialchars(mb_strimwidth($resp['empleados_relacionados'], 0, 25, '...')) ?></div><div class="text-xs text-gray-400 mt-1"><i class="bi bi-people"></i> <?= $resp['empleados_afectados'] ?></div></td>
                                    <td class="p-3"><span class="badge-warning text-xs"><?= $resp['ordenes_afectadas'] ?> órdenes</span></td>
                                    <td class="p-3 text-sm text-gray-600 max-w-xs"><div class="truncate" title="<?= htmlspecialchars($resp['motivos_frecuentes']) ?>"><?= htmlspecialchars(mb_strimwidth($resp['motivos_frecuentes'], 0, 30, '...')) ?></div><div class="text-xs text-gray-400"><i class="bi bi-tags"></i> <?= $resp['motivos_diferentes'] ?> tipos</div></td>
                                    <td class="p-3"><span class="badge-info text-xs"><?= $resp['areas_afectadas'] ?> áreas</span></td>
                                    <td class="p-3"><span class="badge-info text-xs"><?= $resp['equipos_afectados'] ?? 'N/A' ?> equipos</span></td>
                                    <td class="p-3 text-xs text-gray-500"><?= $resp['primera_quiebra'] ?><br><?= $resp['ultima_quiebra'] ?></td>
                                    <td class="p-3"><button onclick="mostrarDetallesResponsable('<?= htmlspecialchars(addslashes($resp['responsable'])) ?>')" class="bg-purple-50 hover:bg-purple-100 text-purple-700 px-3 py-1.5 rounded-lg text-sm transition"><i class="bi bi-eye"></i> <span class="hidden sm:inline">Ver</span></button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div><!-- /responsables -->

        <!-- ╔══════════════════════════════════════════════════╗
             ║  TAB: PRODUCCIÓN EN VIVO  — DASHBOARD COMPLETO  ║
             ╚══════════════════════════════════════════════════╝ -->
        <div id="produccion-vivo" class="tab-content <?= $activeTab === 'produccion-vivo' ? 'active' : '' ?>">

            <!-- HEADER DEL TABLERO -->
            <div class="card p-5 mb-5">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h3 class="text-xl font-bold text-gray-800 flex items-center gap-3">
                            <span class="relative flex h-3 w-3">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
                            </span>
                            Tablero de Producción en Vivo
                        </h3>
                        <p class="text-gray-500 text-sm mt-1">
                            <i class="bi bi-calendar3"></i> <strong><?= date('d/m/Y') ?></strong>
                            &nbsp;•&nbsp; Actualización automática cada 30 segundos
                        </p>
                    </div>
                    <div class="flex gap-3 items-center flex-wrap">
                        <div class="flex items-center gap-2">
                            <label class="text-sm text-gray-600 font-medium"><i class="bi bi-building"></i> Área:</label>
                            <select id="vivoAreaFilter" class="input-light text-sm py-2 min-w-[160px]">
                                <option value="">Todas las áreas</option>
                                <?php foreach ($areas_lista as $area): ?>
                                <option value="<?= htmlspecialchars($area['area']) ?>"><?= htmlspecialchars($area['area']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <span id="vivoLastUpdate" class="text-xs bg-blue-50 text-blue-700 border border-blue-200 px-3 py-1.5 rounded-full font-medium">
                            <i class="bi bi-clock"></i> Actualizando...
                        </span>
                        <button id="vivoRefreshBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition flex items-center gap-2 text-sm font-medium shadow-sm">
                            <i class="bi bi-arrow-repeat"></i> Actualizar
                        </button>
                    </div>
                </div>
            </div>

            <!-- ╔══════════════════════════════════════════╗
                 ║  PANEL WIP — EMPAQUE (MES EN CURSO)      ║
                 ╚══════════════════════════════════════════╝ -->
            <div class="card p-5 mb-5 border-l-4 border-l-orange-500" id="wipEmpaquePanel">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-4">
                    <div>
                        <h4 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="bi bi-boxes text-orange-500 text-xl"></i>
                            WIP Total Órdenes
                        </h4>
                        <p class="text-xs text-gray-500 mt-0.5" id="wipRangoInfo">
                            <i class="bi bi-calendar-month"></i> Cargando mes...
                        </p>
                    </div>
                    <div class="flex items-center gap-3 flex-wrap">
                        <div class="flex items-center gap-2 bg-orange-50 border border-orange-200 px-3 py-1.5 rounded-full">
                            <span class="w-2.5 h-2.5 rounded-full bg-orange-500 animate-pulse inline-block"></span>
                            <span class="text-xs font-semibold text-orange-700">En proceso: <span id="wipContadorActivo">--</span></span>
                        </div>
                        <div class="flex items-center gap-2 bg-green-50 border border-green-200 px-3 py-1.5 rounded-full">
                            <i class="bi bi-check-circle-fill text-green-600 text-xs"></i>
                            <span class="text-xs font-semibold text-green-700">Finalizadas: <span id="wipContadorFinalizado">--</span></span>
                        </div>
                        <button id="wipRefreshBtn" class="bg-orange-50 hover:bg-orange-100 text-orange-700 px-3 py-1.5 rounded-lg text-sm transition flex items-center gap-1 border border-orange-200">
                            <i class="bi bi-arrow-repeat"></i> Actualizar
                        </button>
                    </div>
                </div>

                <div id="wipTablaContainer">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-orange-400 inline-block"></span>
                        <span class="text-sm font-semibold text-orange-700">Órdenes Únicas en Proceso</span>
                    </div>
<!-- Dentro de la tabla WIP, modifica la cabecera y las celdas -->
<div class="overflow-x-auto rounded-lg border border-orange-100 mb-4 max-h-[320px] overflow-y-auto custom-scroll">
    <table class="w-full text-sm" id="wipTablaActiva">
        <thead class="bg-orange-50 sticky top-0">
            <tr>
                <th class="p-3 text-left text-orange-700 font-semibold">Orden</th>
                <th class="p-3 text-center text-orange-700 font-semibold">Unidades</th>
                <th class="p-3 text-left text-orange-700 font-semibold">Áreas</th>
                <th class="p-3 text-left text-orange-700 font-semibold">Equipos</th>
                <th class="p-3 text-center text-orange-700 font-semibold">Inicio</th>
                <th class="p-3 text-left text-orange-700 font-semibold">Último Movimiento</th>
                <th class="p-3 text-center text-orange-700 font-semibold">Acciones</th>
            </tr>
        </thead>
        <tbody id="wipTbodyActivo">
            <tr><td colspan="7" class="text-center py-6 text-gray-400"><div class="spinner mx-auto mb-2"></div>Cargando WIP del mes...</td></tr>
        </tbody>
    </table>
</div>
                  <details id="wipDetallesFinalizadas" class="group">
                        <summary class="flex items-center gap-2 cursor-pointer text-sm font-semibold text-green-700 mb-2 select-none list-none">
                            <i class="bi bi-chevron-right group-open:rotate-90 transition-transform text-xs"></i>
                            <i class="bi bi-check-circle-fill text-green-500"></i>
                            Órdenes Finalizadas — Empacadas (Final)
                            <span class="ml-1 bg-green-100 text-green-700 text-xs px-2 py-0.5 rounded-full" id="wipBadgeFinalizado">0</span>
                        </summary>
                        <div class="overflow-x-auto rounded-lg border border-green-100 mt-2 max-h-[280px] overflow-y-auto custom-scroll">
                            <table class="w-full text-sm" id="wipTablaFinalizada">
                                <thead class="bg-green-50 sticky top-0">
                                    <tr>
                                        <th class="p-3 text-left text-green-700 font-semibold">Orden</th>
                                        <th class="p-3 text-center text-green-700 font-semibold">Unidades</th>
                                        <th class="p-3 text-left text-green-700 font-semibold">Áreas</th>
                                        <th class="p-3 text-center text-green-700 font-semibold">Inicio</th>
                                        <th class="p-3 text-center text-green-700 font-semibold">Empaque y Salida</th>
                                        <th class="p-3 text-center text-green-700 font-semibold">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="wipTbodyFinalizado">
                                    <tr><td colspan="6" class="text-center py-4 text-gray-400 text-sm">Sin órdenes finalizadas este mes</tr>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>

                <p class="text-xs text-gray-400 mt-3 flex items-center gap-1">
                    <i class="bi bi-info-circle"></i>
                    Muestra órdenes del mes en curso. Una orden se marca <strong>Finalizada</strong> cuando aparece en el área <em>Bodega de Aros con equipo Empaque</em>.
                </p>
            </div><!-- /wipEmpaquePanel -->

            <!-- KPI CARDS GRANDES -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-5">
                <div class="card p-5 kpi-card border-t-4 border-t-emerald-500 text-center">
                    <div class="p-2 bg-emerald-50 rounded-full w-10 h-10 mx-auto mb-2 flex items-center justify-center">
                        <i class="bi bi-check-circle-fill text-emerald-500 text-lg"></i>
                    </div>
                    <p class="text-gray-500 text-xs font-medium uppercase tracking-wide">Producción Hoy</p>
                    <p class="text-3xl font-bold text-emerald-600 mt-1" id="vivoTotalProd">--</p>
                    <p class="text-xs text-gray-400 mt-1">unidades</p>
                </div>
                <div class="card p-5 kpi-card border-t-4 border-t-cyan-500 text-center">
                    <div class="p-2 bg-cyan-50 rounded-full w-10 h-10 mx-auto mb-2 flex items-center justify-center">
                        <i class="bi bi-people-fill text-cyan-500 text-lg"></i>
                    </div>
                    <p class="text-gray-500 text-xs font-medium uppercase tracking-wide">Empleados</p>
                    <p class="text-3xl font-bold text-cyan-600 mt-1" id="vivoEmpleados">--</p>
                    <p class="text-xs text-gray-400 mt-1">activos</p>
                </div>
                <div class="card p-5 kpi-card border-t-4 border-t-purple-500 text-center">
                    <div class="p-2 bg-purple-50 rounded-full w-10 h-10 mx-auto mb-2 flex items-center justify-center">
                        <i class="bi bi-building text-purple-500 text-lg"></i>
                    </div>
                    <p class="text-gray-500 text-xs font-medium uppercase tracking-wide">Áreas</p>
                    <p class="text-3xl font-bold text-purple-600 mt-1" id="vivoAreas">--</p>
                    <p class="text-xs text-gray-400 mt-1">activas</p>
                </div>
                <div class="card p-5 kpi-card border-t-4 border-t-amber-500 text-center">
                    <div class="p-2 bg-amber-50 rounded-full w-10 h-10 mx-auto mb-2 flex items-center justify-center">
                        <i class="bi bi-cpu-fill text-amber-500 text-lg"></i>
                    </div>
                    <p class="text-gray-500 text-xs font-medium uppercase tracking-wide">Equipos</p>
                    <p class="text-3xl font-bold text-amber-600 mt-1" id="vivoEquipos">--</p>
                    <p class="text-xs text-gray-400 mt-1">activos</p>
                </div>
                <div class="card p-5 kpi-card border-t-4 border-t-blue-500 text-center">
                    <div class="p-2 bg-blue-50 rounded-full w-10 h-10 mx-auto mb-2 flex items-center justify-center">
                        <i class="bi bi-clipboard-check-fill text-blue-500 text-lg"></i>
                    </div>
                    <p class="text-gray-500 text-xs font-medium uppercase tracking-wide">Órdenes</p>
                    <p class="text-3xl font-bold text-blue-600 mt-1" id="vivoOrdenes">--</p>
                    <p class="text-xs text-gray-400 mt-1">procesadas</p>
                </div>
            </div>

            <!-- FILA 1: PRODUCCIÓN POR HORA + POR ÁREA -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
                <div class="card p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h4 class="font-bold text-gray-800 flex items-center gap-2">
                            <i class="bi bi-clock-history text-amber-500"></i> Producción por Hora
                        </h4>
                        <span class="badge badge-neutral text-xs">Hoy</span>
                    </div>
                    <div class="chart-container" style="height: 260px;">
                        <canvas id="vivoHoraChart"></canvas>
                    </div>
                </div>

                <div class="card p-5">
                    <div class="flex justify-between items-center mb-4 flex-wrap gap-2">
                        <h4 class="font-bold text-gray-800 flex items-center gap-2">
                            <i class="bi bi-building text-cyan-600"></i> Producción por Área
                        </h4>
                        <div class="flex gap-2 items-center">
                            <select id="areaChartTypeSelector" class="input-light text-sm py-1.5">
                                <option value="bar">📊 Barras</option>
                                <option value="doughnut" selected>🍩 Dona</option>
                                <option value="pie">🥧 Pastel</option>
                                <option value="polarArea">🌐 Polar</option>
                            </select>
                            <button id="refreshAreaChartBtn" class="bg-cyan-50 hover:bg-cyan-100 text-cyan-700 px-3 py-1.5 rounded-lg transition text-sm">
                                <i class="bi bi-arrow-repeat"></i>
                            </button>
                        </div>
                    </div>
                    <div class="chart-container" style="height: 260px;">
                        <canvas id="vivoAreaChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- FILA 2: PRODUCCIÓN POR EMPLEADO + POR EQUIPO -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
                <div class="card p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h4 class="font-bold text-gray-800 flex items-center gap-2">
                            <i class="bi bi-person-lines-fill text-blue-600"></i> Top Empleados (Hoy)
                        </h4>
                        <span class="badge badge-info text-xs" id="vivoEmpleadosCount">--</span>
                    </div>
                    <div class="chart-container" style="height: 260px;">
                        <canvas id="vivoEmpleadosChart"></canvas>
                    </div>
                </div>

                <div class="card p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h4 class="font-bold text-gray-800 flex items-center gap-2">
                            <i class="bi bi-cpu-fill text-purple-600"></i> Producción por Equipo (Hoy)
                        </h4>
                        <span class="badge badge-neutral text-xs" id="vivoEquiposCount">--</span>
                    </div>
                    <div class="chart-container" style="height: 260px;">
                        <canvas id="vivoEquiposChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- FILA 3: RANKING ÁREAS + RANKING EMPLEADOS -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
                <div class="card p-5">
                    <h4 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="bi bi-bar-chart-steps text-cyan-600"></i> Ranking por Área
                    </h4>
                    <div id="vivoRankingAreas" class="space-y-2 max-h-[320px] overflow-y-auto custom-scroll">
                        <div class="text-center py-6 text-gray-400 text-sm"><div class="spinner mx-auto mb-2"></div>Cargando...</div>
                    </div>
                </div>
                <div class="card p-5">
                    <h4 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="bi bi-trophy-fill text-amber-500"></i> Ranking por Empleado
                    </h4>
                    <div id="vivoRankingEmpleados" class="space-y-2 max-h-[320px] overflow-y-auto custom-scroll">
                        <div class="text-center py-6 text-gray-400 text-sm"><div class="spinner mx-auto mb-2"></div>Cargando...</div>
                    </div>
                </div>
            </div>

            <!-- TABLA ÚLTIMOS REGISTROS -->
            <div class="card p-5">
                <div class="flex justify-between items-center mb-4 flex-wrap gap-3">
                    <h4 class="font-bold text-gray-800 flex items-center gap-2">
                        <i class="bi bi-list-check text-emerald-600"></i> Últimos Registros de Producción
                    </h4>
                    <div class="flex items-center gap-3">
                        <span class="text-xs bg-gray-100 text-gray-600 px-3 py-1 rounded-full" id="vivoRegistrosCount">0 registros</span>
                        <button onclick="exportarRegistrosVivo()" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-700 px-3 py-1.5 rounded-lg text-sm transition">
                            <i class="bi bi-download mr-1"></i> Exportar CSV
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto max-h-[420px] overflow-y-auto custom-scroll rounded-lg border border-gray-200">
                    <table class="w-full text-sm" id="vivoTablaCompleta">
                        <thead class="bg-gray-50 sticky top-0 border-b border-gray-200">
                            <tr>
                                <th class="p-3 text-left text-gray-600 font-semibold">Hora</th>
                                <th class="p-3 text-left text-gray-600 font-semibold">Empleado</th>
                                <th class="p-3 text-left text-gray-600 font-semibold">Área</th>
                                <th class="p-3 text-left text-gray-600 font-semibold">Equipo</th>
                                <th class="p-3 text-left text-gray-600 font-semibold">Orden</th>
                                <th class="p-3 text-left text-gray-600 font-semibold">Estado</th>
                            </tr>
                        </thead>
                        <tbody id="vivoTablaBody">
                            <tr><td colspan="6" class="text-center py-8 text-gray-400">Cargando datos...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /produccion-vivo -->

    </div><!-- /container -->

    <!-- ╔══════════════════════════════════════════════════╗
         ║  BOTÓN FLOTANTE Y MODAL DE REPORTES (ÚNICO)      ║
         ╚══════════════════════════════════════════════════╝ -->
    <button id="btnAbrirReportes" class="fixed bottom-6 right-6 bg-blue-600 hover:bg-blue-700 text-white p-4 rounded-full shadow-lg transition-all hover:scale-105 z-40" data-tooltip="Generar Reportes Avanzados">
        <i class="bi bi-file-text-fill text-xl"></i>
    </button>

    <div id="modalReportes" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl max-w-4xl w-full max-h-[90vh] overflow-hidden flex flex-col">
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 p-5 rounded-t-2xl flex justify-between items-center">
                <h3 class="text-xl font-bold text-white flex items-center gap-2"><i class="bi bi-file-earmark-spreadsheet"></i><span>Generador de Reportes Avanzados</span></h3>
                <button onclick="cerrarModalReportes()" class="bg-white/20 hover:bg-white/30 w-10 h-10 rounded-full flex items-center justify-center transition text-white"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="p-5 overflow-y-auto flex-grow custom-scroll">
                <div class="bg-gray-50 p-4 rounded-xl mb-6">
                    <h4 class="font-bold text-gray-700 mb-4 flex items-center gap-2"><i class="bi bi-calendar-range"></i> Rango de Fechas y Horas</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label class="block text-sm text-gray-600 mb-1">Fecha Desde</label><input type="date" id="reporteFechaDesde" class="input-light w-full" value="<?= date('Y-m-d', strtotime('-7 days')) ?>"></div>
                        <div><label class="block text-sm text-gray-600 mb-1">Fecha Hasta</label><input type="date" id="reporteFechaHasta" class="input-light w-full" value="<?= date('Y-m-d') ?>"></div>
                        <div><label class="block text-sm text-gray-600 mb-1">Hora Desde</label><div class="flex gap-2"><input type="time" id="reporteHoraDesdeTime" class="input-light flex-1" value="00:00"><select id="reporteHoraDesdeAmpm" class="input-light w-20"><option value="AM">AM</option><option value="PM">PM</option></select></div></div>
                        <div><label class="block text-sm text-gray-600 mb-1">Hora Hasta</label><div class="flex gap-2"><input type="time" id="reporteHoraHastaTime" class="input-light flex-1" value="23:59"><select id="reporteHoraHastaAmpm" class="input-light w-20"><option value="AM">AM</option><option value="PM" selected>PM</option></select></div></div>
                    </div>
                </div>
                <div class="bg-gray-50 p-4 rounded-xl mb-6">
                    <h4 class="font-bold text-gray-700 mb-4 flex items-center gap-2"><i class="bi bi-filetype-csv"></i> Tipo de Reporte</h4>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                        <button class="tipo-reporte-btn bg-blue-50 hover:bg-blue-100 text-blue-700 p-3 rounded-xl transition" data-tipo="completo"><i class="bi bi-table text-xl block mb-1"></i><span class="text-sm">Completo</span></button>
                        <button class="tipo-reporte-btn bg-cyan-50 hover:bg-cyan-100 text-cyan-700 p-3 rounded-xl transition" data-tipo="area"><i class="bi bi-building text-xl block mb-1"></i><span class="text-sm">Por Área</span></button>
                        <button class="tipo-reporte-btn bg-purple-50 hover:bg-purple-100 text-purple-700 p-3 rounded-xl transition" data-tipo="equipo"><i class="bi bi-cpu text-xl block mb-1"></i><span class="text-sm">Por Equipo</span></button>
                        <button class="tipo-reporte-btn bg-emerald-50 hover:bg-emerald-100 text-emerald-700 p-3 rounded-xl transition" data-tipo="empleado"><i class="bi bi-person text-xl block mb-1"></i><span class="text-sm">Por Empleado</span></button>
                        <button class="tipo-reporte-btn bg-red-50 hover:bg-red-100 text-red-700 p-3 rounded-xl transition" data-tipo="quiebras"><i class="bi bi-exclamation-triangle text-xl block mb-1"></i><span class="text-sm">Quiebras x Emp</span></button>
                    </div>
                </div>
                <div class="bg-gray-50 p-4 rounded-xl mb-6">
                    <div class="flex justify-between items-center mb-4"><h4 class="font-bold text-gray-700 flex items-center gap-2"><i class="bi bi-eye"></i> Vista Previa</h4><div class="flex gap-2"><button id="btnPrevisualizar" class="bg-amber-50 hover:bg-amber-100 text-amber-700 px-3 py-1 rounded-lg text-sm transition"><i class="bi bi-arrow-repeat"></i> Actualizar Vista</button><button id="btnDescargarReporte" class="bg-green-50 hover:bg-green-100 text-green-700 px-3 py-1 rounded-lg text-sm transition"><i class="bi bi-download"></i> Descargar CSV</button></div></div>
                    <div class="overflow-x-auto max-h-[300px] overflow-y-auto custom-scroll border border-gray-200 rounded-lg" id="previewContainer"><table class="w-full text-xs"><thead class="bg-gray-100 sticky top-0"><tr><td colspan="5" class="p-4 text-center text-gray-400">Seleccione un tipo de reporte y haga clic en "Actualizar Vista"</thead><tbody></tbody></table></div>
                </div>
            </div>
            <div class="bg-gray-50 p-4 border-t border-gray-200 flex justify-between items-center"><div class="text-sm text-gray-500" id="reporteInfo"></div><div class="flex gap-2"><button onclick="cerrarModalReportes()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg transition">Cerrar</button></div></div>
        </div>
    </div>

    <!-- ╔══════════════════════════════════════════════════╗
         ║  MODAL DE DETALLES                               ║
         ╚══════════════════════════════════════════════════╝ -->
    <div id="modalDetalles" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl max-w-4xl w-full max-h-[85vh] overflow-hidden flex flex-col">
            <div class="bg-gradient-to-r from-blue-600 to-cyan-600 p-5 rounded-t-2xl flex justify-between items-center">
                <h3 id="modalTitulo" class="text-xl font-bold text-white flex items-center gap-2"><i class="bi bi-info-circle"></i><span>Detalles</span></h3>
                <div class="flex gap-2"><button id="modalExportBtn" class="bg-white/20 hover:bg-white/30 w-10 h-10 rounded-full flex items-center justify-center transition text-white" data-tooltip="Exportar"><i class="bi bi-download"></i></button><button onclick="cerrarModal()" class="bg-white/20 hover:bg-white/30 w-10 h-10 rounded-full flex items-center justify-center transition text-white"><i class="bi bi-x-lg"></i></button></div>
            </div>
            <div id="modalCuerpo" class="p-5 overflow-y-auto flex-grow custom-scroll"></div>
            <div class="bg-gray-50 p-4 border-t border-gray-200 flex justify-between items-center text-sm text-gray-500"><div id="modalInfo"></div><button onclick="cerrarModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg transition">Cerrar</button></div>
        </div>
    </div>

    <!-- ╔══════════════════════════════════════════════════╗
         ║  FIRMA (opcional)                                ║
         ╚══════════════════════════════════════════════════╝ -->
    <div class="firma-inferior">
        <p class="text-xs opacity-70">Dashboard Analítico | Sistema de Control de Calidad</p>
        <p class="text-xs opacity-70">Desarrollado Por: Nestor Rosales - RosalesDev91</p>
    </div>

    <!-- ================================================== -->
    <!-- DATOS PHP → JS (DEBE IR ANTES DE api.js)           -->
    <!-- ================================================== -->
    <script>
    // Datos del dashboard - Asegurar que todos existan
    window.AppData = <?= json_encode([
        "timelineData" => $timeline_data ?? [],
        "topMotivos" => $top_motivos ?? [],
        "quiebrasTurno" => $quiebras_turno ?? [],
        "topEmpleadosProduccion" => $top_empleados_produccion ?? [],
        "areasLista" => $areas_lista ?? [],
        "topEquipos" => $top_equipos ?? [],
        "topResponsables" => $top_responsables ?? [],
        "currentTab" => $_GET['tab'] ?? 'resumen',
        "userRol" => $_SESSION['rol'] ?? ''
    ], JSON_UNESCAPED_UNICODE) ?>;

    // Datos de promedios de quiebras
    window.PromedioQuiebrasData = <?= isset($promedio_quiebras_json) && $promedio_quiebras_json ? $promedio_quiebras_json : '{"promedio":0,"mediana":0,"dias_con_quiebras":0,"total_quiebras":0,"maximo":0,"minimo":0}' ?>;
    
    console.log('✅ Datos PHP cargados correctamente');
    console.log('📊 window.AppData recibido:', window.AppData ? 'Sí' : 'No');
    console.log('📊 timelineData:', window.AppData.timelineData ? window.AppData.timelineData.length : 0, 'registros');
    console.log('📊 topMotivos:', window.AppData.topMotivos ? window.AppData.topMotivos.length : 0, 'registros');
    console.log('📊 quiebrasTurno:', window.AppData.quiebrasTurno ? window.AppData.quiebrasTurno.length : 0, 'registros');
    console.log('📊 PromedioQuiebrasData:', window.PromedioQuiebrasData);
    
    // Verificar si hay datos para los gráficos
    if (window.AppData.timelineData && window.AppData.timelineData.length === 0) {
        console.warn('⚠️ No hay datos de timeline para mostrar en el gráfico');
    }
    if (window.AppData.quiebrasTurno && window.AppData.quiebrasTurno.length === 0) {
        console.warn('⚠️ No hay datos de turnos para mostrar en el gráfico');
    }
    if (window.AppData.topMotivos && window.AppData.topMotivos.length === 0) {
        console.warn('⚠️ No hay datos de motivos para mostrar en el gráfico');
    }
    </script>

    <!-- JS de APIs (carga SIN defer para que ejecute después de los datos) -->
    <script src="api.js?v=<?= time() ?>"></script>

</body>
</html>