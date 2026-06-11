<?php
session_start();

// Validación de sesión
if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login_admin.php");
    exit();
}

require_once '../config/database.php';
require_once 'auto_audit.php';
require_once 'registrar_actividad.php';

// Zona horaria
date_default_timezone_set('America/Guatemala');

// Cache de resultados en sesión (5 minutos)
$cache_key = md5(serialize($_POST));
$cache_time = 300; // 5 minutos

// Obtener variables POST con valores por defecto
$empleado_seleccionado = $_POST['empleado'] ?? '';
$area_seleccionada = $_POST['area'] ?? '';
$turno_seleccionado = $_POST['turno'] ?? '';
$fecha_inicio = $_POST['fecha_inicio'] ?? '';
$fecha_fin = $_POST['fecha_fin'] ?? '';
$hora_inicio = $_POST['hora_inicio'] ?? '00:00';
$ampm_inicio = $_POST['ampm_inicio'] ?? 'AM';
$hora_fin = $_POST['hora_fin'] ?? '11:59';
$ampm_fin = $_POST['ampm_fin'] ?? 'PM';
$agrupar_por = $_POST['agrupar_por'] ?? '';

$hay_filtros = isset($_POST['buscar']) || isset($_POST['exportar_csv']);

// Función de caché simple
function getCachedResult($key, $callback, $ttl = 300) {
    if (!isset($_SESSION['cache'])) {
        $_SESSION['cache'] = [];
    }
    
    if (isset($_SESSION['cache'][$key]) && (time() - $_SESSION['cache'][$key]['time'] < $ttl)) {
        return $_SESSION['cache'][$key]['data'];
    }
    
    $data = $callback();
    $_SESSION['cache'][$key] = [
        'time' => time(),
        'data' => $data
    ];
    
    // Limpiar caché vieja
    foreach ($_SESSION['cache'] as $k => $v) {
        if (time() - $v['time'] > $ttl) {
            unset($_SESSION['cache'][$k]);
        }
    }
    
    return $data;
}

if (!function_exists('convertirHoraConSegundos')) {
    function convertirHoraConSegundos($hora, $ampm) {
        if (empty($hora)) return '00:00:00';

        list($h, $m) = explode(':', $hora);
        $h = (int)$h;

        if (strtoupper($ampm) === 'PM' && $h < 12) {
            $h += 12;
        } elseif (strtoupper($ampm) === 'AM' && $h == 12) {
            $h = 0;
        }

        return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . $m . ':00';
    }
}

// Validación de fechas
$errores = [];
if ($hay_filtros) {
    if (!empty($fecha_inicio) || !empty($fecha_fin)) {
        if (empty($fecha_inicio) || empty($fecha_fin)) {
            $errores[] = "Debes seleccionar un rango completo de fechas.";
        } else {
            $fecha_inicio_valida = DateTime::createFromFormat('Y-m-d', $fecha_inicio);
            $fecha_fin_valida = DateTime::createFromFormat('Y-m-d', $fecha_fin);

            if (!$fecha_inicio_valida || !$fecha_fin_valida
                || $fecha_inicio_valida->format('Y-m-d') !== $fecha_inicio
                || $fecha_fin_valida->format('Y-m-d') !== $fecha_fin) {
                $errores[] = "Formato de fecha inválido. Usa YYYY-MM-DD.";
            } elseif ($fecha_inicio > $fecha_fin) {
                $errores[] = "La fecha inicio no puede ser mayor a la fecha fin.";
            }
        }
    }
}

// Construcción dinámica del WHERE con seguridad (placeholders)
$condiciones = [];
$parametros = [];
$tipos = '';

if ($empleado_seleccionado !== '') {
    $condiciones[] = "todas.empleado = ?";
    $parametros[] = $empleado_seleccionado;
    $tipos .= 's';
}
if ($area_seleccionada !== '') {
    $condiciones[] = "todas.area = ?";
    $parametros[] = $area_seleccionada;
    $tipos .= 's';
}
if ($turno_seleccionado !== '') {
    $condiciones[] = "todas.turno = ?";
    $parametros[] = $turno_seleccionado;
    $tipos .= 's';
}

if (empty($errores) && !empty($fecha_inicio) && !empty($fecha_fin)) {
    $hora_inicio_24 = convertirHoraConSegundos($hora_inicio, $ampm_inicio);
    $hora_fin_24 = convertirHoraConSegundos($hora_fin, $ampm_fin);

    $fecha_inicio_esc = $fecha_inicio . " " . $hora_inicio_24;
    $fecha_fin_esc = $fecha_fin . " " . $hora_fin_24;

    $condiciones[] = "todas.fecha BETWEEN ? AND ?";
    $parametros[] = $fecha_inicio_esc;
    $parametros[] = $fecha_fin_esc;
    $tipos .= 'ss';
}

$where_sql = !empty($condiciones) ? "WHERE " . implode(' AND ', $condiciones) : "";

// Función optimizada para obtener resumen agrupado - CORREGIDA
function obtenerResumenOptimizado($conn, $campo, $condiciones, $tipos, $parametros) {
    $cond_sin_prefijo = [];
    foreach ($condiciones as $cond) {
        $cond_sin_prefijo[] = preg_replace('/\btodas\./', '', $cond);
    }
    $where_subconsulta = !empty($cond_sin_prefijo) ? "WHERE " . implode(" AND ", $cond_sin_prefijo) : "";

    // Consulta optimizada con índices
    if ($campo === 'area') {
        $sql = "
            SELECT todas.area, 
                   COALESCE(todas.equipo, '') AS equipo, 
                   COUNT(*) AS total_ordenes
            FROM (
                SELECT area, equipo 
                FROM produccion 
                $where_subconsulta
                UNION ALL
                SELECT area, equipo 
                FROM registros_antiguos 
                $where_subconsulta
            ) AS todas
            GROUP BY todas.area, todas.equipo
            ORDER BY todas.area, total_ordenes DESC
        ";
    } else {
        $sql = "
            SELECT todas.$campo, COUNT(*) AS total_ordenes
            FROM (
                SELECT $campo 
                FROM produccion 
                $where_subconsulta
                UNION ALL
                SELECT $campo 
                FROM registros_antiguos 
                $where_subconsulta
            ) AS todas
            GROUP BY todas.$campo
            ORDER BY total_ordenes DESC
        ";
    }

    $stmt = $conn->prepare($sql);
    
    // CORRECCIÓN: Solo hacer bind si hay parámetros
    if (!empty($parametros)) {
        // Para cada subconsulta necesitamos duplicar los parámetros
        $parametros_duplicados = array_merge($parametros, $parametros);
        $tipos_duplicados = str_repeat($tipos, 2);
        
        $bind_params = array_merge([$tipos_duplicados], $parametros_duplicados);
        $refs = [];
        foreach ($bind_params as $key => $val) {
            $refs[$key] = &$bind_params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    
    $stmt->execute();
    $resultado = $stmt->get_result();

    $datos = [];
    while ($row = $resultado->fetch_assoc()) {
        $datos[] = $row;
    }
    $stmt->close();
    return $datos;
}

// Función para obtener valores únicos (cacheados)
function obtenerValoresUnicos($conn, $campo, $tabla_campo = null) {
    $campo_buscar = $tabla_campo ?? $campo;
    $cache_key = "unicos_$campo";
    
    if (isset($_SESSION[$cache_key]) && (time() - $_SESSION[$cache_key]['time'] < 3600)) {
        return $_SESSION[$cache_key]['data'];
    }
    
    if ($campo === 'nombre_equipo') {
        $sql = "SELECT DISTINCT nombre_equipo FROM equipos ORDER BY nombre_equipo";
    } else {
        $sql = "SELECT DISTINCT $campo_buscar FROM (
                    SELECT $campo_buscar FROM produccion
                    UNION
                    SELECT $campo_buscar FROM registros_antiguos
                ) AS total
                ORDER BY $campo_buscar";
    }
    
    $result = $conn->query($sql);
    $datos = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $datos[] = $row;
        }
    }
    
    $_SESSION[$cache_key] = [
        'time' => time(),
        'data' => $datos
    ];
    
    return $datos;
}

// Función para convertir hora 24h a formato AM/PM para encabezado CSV
function formatoHoraAMPMParaCSV($hora24, $ampm) {
    if (empty($hora24)) return '';
    $parts = explode(':', $hora24);
    $hora = (int)$parts[0];
    $minutos = $parts[1] ?? '00';

    if (strtoupper($ampm) === 'PM' && $hora < 12) {
        $hora += 12;
    } elseif (strtoupper($ampm) === 'AM' && $hora == 12) {
        $hora = 0;
    }

    $dt = DateTime::createFromFormat('H:i', str_pad($hora, 2, '0', STR_PAD_LEFT) . ':' . $minutos);
    return $dt ? $dt->format('h:i A') : '';
}

// Exportar CSV
if (
    isset($_POST['exportar_csv']) &&
    empty($errores) &&
    (!isset($_SESSION['exportando_csv']) || $_SESSION['exportando_csv'] === false)
) {
    $_SESSION['exportando_csv'] = true;

    $fecha_inicio_raw = $_POST['fecha_inicio'] ?? '';
    $fecha_fin_raw = $_POST['fecha_fin'] ?? '';
    $hora_inicio_raw = $_POST['hora_inicio'] ?? '';
    $ampm_inicio_raw = $_POST['ampm_inicio'] ?? '';
    $hora_fin_raw = $_POST['hora_fin'] ?? '';
    $ampm_fin_raw = $_POST['ampm_fin'] ?? '';
    $hora_inicio_formato = formatoHoraAMPMParaCSV($hora_inicio_raw, $ampm_inicio_raw);
    $hora_fin_formato = formatoHoraAMPMParaCSV($hora_fin_raw, $ampm_fin_raw);
    $agrupar_por = $_POST['agrupar_por'] ?? '';

    // REUTILIZAR los mismos filtros para la exportación con placeholders y bind:
    $cond_export = [];
    $params_export = [];
    $types_export = '';

    if ($empleado_seleccionado !== '') {
        $cond_export[] = "empleado = ?";
        $params_export[] = $empleado_seleccionado;
        $types_export .= 's';
    }
    if ($area_seleccionada !== '') {
        $cond_export[] = "area = ?";
        $params_export[] = $area_seleccionada;
        $types_export .= 's';
    }
    if ($turno_seleccionado !== '') {
        $cond_export[] = "turno = ?";
        $params_export[] = $turno_seleccionado;
        $types_export .= 's';
    }
    if (!empty($fecha_inicio_raw) && !empty($fecha_fin_raw)) {
        $fecha_inicio_export = $fecha_inicio_raw . " " . convertirHoraConSegundos($hora_inicio_raw, $ampm_inicio_raw);
        $fecha_fin_export = $fecha_fin_raw . " " . convertirHoraConSegundos($hora_fin_raw, $ampm_fin_raw);
        $cond_export[] = "fecha BETWEEN ? AND ?";
        $params_export[] = $fecha_inicio_export;
        $params_export[] = $fecha_fin_export;
        $types_export .= 'ss';
    }

    // Obtener resumenes exportar
    $resumenes_exportar = [];
    if ($agrupar_por === 'todos') {
        foreach (['empleado', 'area', 'turno'] as $campo) {
            $resumenes_exportar[$campo] = obtenerResumenOptimizado($conn, $campo, $cond_export, $types_export, $params_export);
        }
    } elseif (in_array($agrupar_por, ['empleado', 'area', 'turno'])) {
        $resumenes_exportar[$agrupar_por] = obtenerResumenOptimizado($conn, $agrupar_por, $cond_export, $types_export, $params_export);
    }

    // Headers CSV
    header('Content-Type: text/csv; charset=utf-8');
    $fecha_actual = date('Y-m-d_H-i-s');
    $nombre_archivo = "resumen_produccion_{$fecha_actual}.csv";
    header("Content-Disposition: attachment; filename=\"{$nombre_archivo}\"");
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // BOM UTF-8

    // Título
    fputcsv($output, ["Resumen de producción desde $fecha_inicio_raw $hora_inicio_formato hasta $fecha_fin_raw $hora_fin_formato"]);
    fputcsv($output, []);

    $etiquetas = [
        'empleado' => 'Empleado',
        'area' => 'Área',
        'turno' => 'Turno'
    ];

    foreach ($resumenes_exportar as $tipo => $resumen) {
        if ($tipo === 'empleado') {
            fputcsv($output, ["Resumen por Empleado"]);
            fputcsv($output, ["Empleado", "Área", "Turno", "Total de Órdenes", "Total de Quiebras", "% de Quiebras"]);

            foreach ($resumen as $fila) {
                $empleado = $fila['empleado'];
                $total_ordenes = $fila['total_ordenes'];

                // Consultas para área, turno y quiebras con bind_param:
                $stmt_area_cant = $conn->prepare("
                    SELECT area, COUNT(*) AS ordenes_area
                    FROM produccion
                    WHERE empleado = ? AND fecha BETWEEN ? AND ?
                      AND area <> '' AND area IS NOT NULL AND area NOT LIKE 'N/A'
                    GROUP BY area
                ");
                $stmt_area_cant->bind_param("sss", $empleado, $fecha_inicio_export, $fecha_fin_export);
                $stmt_area_cant->execute();
                $res_area_cant = $stmt_area_cant->get_result();

                $areas_cant = [];
                while ($fila_area = $res_area_cant->fetch_assoc()) {
                    $areas_cant[] = trim($fila_area['area']) . " ({$fila_area['ordenes_area']})";
                }
                $stmt_area_cant->close();

                $area = !empty($areas_cant) ? implode(", ", $areas_cant) : 'Sin datos';

                $stmt_turno = $conn->prepare("
                    SELECT DISTINCT turno
                    FROM produccion
                    WHERE empleado = ? AND fecha BETWEEN ? AND ?
                      AND turno <> '' AND turno IS NOT NULL AND turno NOT LIKE 'N/A'
                ");
                $stmt_turno->bind_param("sss", $empleado, $fecha_inicio_export, $fecha_fin_export);
                $stmt_turno->execute();
                $res_turno = $stmt_turno->get_result();

                $turnos = [];
                while ($fila_turno = $res_turno->fetch_assoc()) {
                    $turno_db = trim($fila_turno['turno']);
                    if (!in_array($turno_db, $turnos)) {
                        $turnos[] = $turno_db;
                    }
                }
                $stmt_turno->close();

                $turno = !empty($turnos) ? implode(", ", $turnos) : 'Sin datos';

                $stmt_quiebras = $conn->prepare("
                    SELECT COUNT(*) AS total_quiebras
                    FROM registro_quiebras
                    WHERE empleado = ? AND fecha BETWEEN ? AND ?
                ");
                $stmt_quiebras->bind_param("sss", $empleado, $fecha_inicio_export, $fecha_fin_export);
                $stmt_quiebras->execute();
                $res_quiebras = $stmt_quiebras->get_result();
                $total_quiebras = $res_quiebras->fetch_assoc()['total_quiebras'] ?? 0;
                $stmt_quiebras->close();

                $denominador = $total_ordenes + $total_quiebras;
                $porcentaje = ($denominador > 0) ? round(($total_quiebras / $denominador) * 100, 2) : 0;

                fputcsv($output, [$empleado, $area, $turno, $total_ordenes, $total_quiebras, "{$porcentaje}%"]);
            }
            fputcsv($output, []);
        } else {
            fputcsv($output, ["Resumen por " . ($etiquetas[$tipo] ?? $tipo)]);
            fputcsv($output, [($etiquetas[$tipo] ?? $tipo), "Total de Órdenes"]);

            foreach ($resumen as $fila) {
                fputcsv($output, [$fila[$tipo], $fila['total_ordenes']]);
            }

            fputcsv($output, []);
        }
    }

    fclose($output);
    unset($_SESSION['exportando_csv']);
    exit();
}

// Obtener valores únicos para filtros (cacheados)
$empleados_data = obtenerValoresUnicos($conn, 'empleado');
$areas_data = obtenerValoresUnicos($conn, 'area');
$turnos_data = obtenerValoresUnicos($conn, 'turno');

// Mostrar resumen si hay filtros y no exportar CSV ni errores
$resumenes = [];
if ($hay_filtros && !isset($_POST['exportar_csv']) && empty($errores)) {
    // Usar caché para los resultados
    $callback = function() use ($conn, $agrupar_por, $condiciones, $tipos, $parametros) {
        $res = [];
        if ($agrupar_por === 'todos') {
            foreach (['empleado', 'area', 'turno'] as $campo) {
                $res[$campo] = obtenerResumenOptimizado($conn, $campo, $condiciones, $tipos, $parametros);
            }
        } elseif (in_array($agrupar_por, ['empleado', 'area', 'turno'])) {
            $res[$agrupar_por] = obtenerResumenOptimizado($conn, $agrupar_por, $condiciones, $tipos, $parametros);
        }
        return $res;
    };
    
    $resumenes = getCachedResult($cache_key, $callback, 300);
}

// Búsqueda por orden
$historial_orden = [];
if (isset($_POST['buscar_orden'])) {
    $orden_buscar = trim($_POST['orden_buscar']);
    $stmt = $conn->prepare("
        SELECT empleado, area, turno, fecha
        FROM (
            SELECT empleado, area, turno, fecha, orden FROM produccion
            UNION ALL
            SELECT empleado, area, turno, fecha, orden FROM registros_antiguos
        ) AS todas
        WHERE orden = ?
        ORDER BY fecha DESC
    ");
    $stmt->bind_param("s", $orden_buscar);
    $stmt->execute();
    $resultado = $stmt->get_result();

    while ($fila = $resultado->fetch_assoc()) {
        $historial_orden[] = $fila;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resumen de Producción</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
   <style>
body {
    background: #155724;
    color: white;
    font-family: Arial, sans-serif;
    margin: 0;
    padding: 0;
    position: relative;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    padding-bottom: 80px;
    box-sizing: border-box;
}

.container {
    width: 90%;
    max-width: 1200px;
    margin: 40px auto;
    padding: 20px;
}

form, table {
    background: rgba(0, 0, 0, 0.05);
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
}

input, select, button, input[type="date"] {
    padding: 12px;
    width: 100%;
    margin: 12px 0;
    border-radius: 8px;
    border: 1px solid #ced4da;
    background-color: #e9f7ef;
    color: #212529;
    font-weight: 500;
    font-size: 15px;
    transition: all 0.3s ease;
}

input:focus, select:focus, button:focus {
    outline: none;
    border-color: #28a745;
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.2);
}

select {
    background-color: #e9f7ef;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    font-size: 15px;
    color: #212529;
}

th, td {
    padding: 12px;
    border: 1px solid #ccc;
    text-align: center;
}

th {
    background: #28a745;
    color: white;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

tr:nth-child(even) {
    background-color: #f8f9fa;
}

button {
    background: #28a745;
    color: white;
    border: none;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: background 0.3s ease;
}

button:hover {
    background: #218838;
}

.logo {
    position: absolute;
    top: 20px;
    right: 20px;
    width: 250px;
    height: auto;
}

.firma {
    text-align: center;
    font-size: 15px;
    color: #d4fcd4;
    padding: 15px 0;
    background-color: #003300;
    border-top: 1px solid #d4fcd4;
    width: 100%;
    position: fixed;
    left: 0;
    bottom: 0;
    box-sizing: border-box;
}

.flex-row {
    display: flex;
    gap: 20px;
    align-items: flex-start;
    flex-wrap: nowrap;
}

.flex-column {
    display: flex;
    flex-direction: column;
    min-width: 130px;
}

.flex-column-wide {
    min-width: 170px;
}

.flex-time-group {
    display: flex;
    gap: 6px;
}

/* Estilos adicionales */
.tab-container {
    max-width: 900px;
    margin: 30px auto;
    border: 1px solid #ddd;
    border-radius: 8px;
    box-shadow: 0 0 8px rgba(0,0,0,0.1);
    background-color: #fff;
    font-family: Arial, sans-serif;
    padding: 20px;
}

.alert {
    margin-top: 15px;
    padding: 10px 15px;
    background: #ffc107;
    border-radius: 4px;
    color: #856404;
    font-weight: 600;
}

#resultadoBusquedaOrden table {
    width: 100%;
    border-collapse: collapse;
}

#resultadoBusquedaOrden th,
#resultadoBusquedaOrden td {
    padding: 8px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

#resultadoBusquedaOrden thead tr {
    background-color: #1e7e34;
    color: white;
}

#formBuscarOrden {
    margin-top: 10px;
    display: flex;
    gap: 8px;
}

#formBuscarOrden input[type="text"] {
    flex-grow: 1;
    padding: 8px;
    font-size: 14px;
    border: 1px solid #ccc;
    border-radius: 4px;
}

#formBuscarOrden button {
    padding: 8px 16px;
    font-size: 14px;
    background-color: #1e7e34;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

#formBuscarOrden button:hover {
    background-color: #155724;
}

.firma {
    color: white;
    margin-top: 20px;
    text-align: center;
    font-size: 13px;
}

.paro-container {
    background-color: #155724;
    padding: 20px;
    border-radius: 10px;
    margin: 30px auto;
    color: white;
    font-family: Arial, sans-serif;
    max-width: 1200px;
    width: 95%;
    box-shadow: 0 0 15px rgba(0, 0, 0, 0.3);
}

.paro-container form {
    display: flex;
    flex-wrap: wrap;
    gap: 15px 20px;
    align-items: center;
    justify-content: flex-start;
    margin-bottom: 20px;
}

.filtro-grupo {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 250px;
}

.filtro-grupo label {
    min-width: 60px;
    font-weight: bold;
    color: white;
}

.filtro-grupo input[type="date"],
.filtro-grupo input[type="time"],
.filtro-grupo select {
    padding: 5px 8px;
    border-radius: 5px;
    border: 1px solid #ccc;
    font-size: 14px;
    min-width: 90px;
}

.botones-filtro {
    display: flex;
    gap: 10px;
    margin-top: 10px;
    width: 100%;
    justify-content: flex-start;
}

.paro-container form button {
    padding: 8px 14px;
    background-color: #28a745;
    border: none;
    color: white;
    border-radius: 5px;
    cursor: pointer;
    font-weight: bold;
    transition: background-color 0.3s ease;
    min-width: 120px;
}

.paro-container form button:hover {
    background-color: #1e7e34;
}

.paro-table-wrapper {
    max-height: 400px;
    overflow-y: auto;
    overflow-x: auto;
    border: 1px solid #117a3c;
    border-radius: 5px;
    background-color: #1e7e34;
    color: white;
    margin-top: 20px;
}

.paro-container table {
    width: 100%;
    border-collapse: collapse;
    background-color: #1e7e34;
}

.paro-container th,
.paro-container td {
    padding: 8px;
    border: 1px solid #117a3c;
    text-align: center;
    min-width: 100px;
}

.paro-container th {
    background-color: #117a3c;
    font-weight: bold;
    position: sticky;
    top: 0;
    z-index: 1;
}

.paro-container tbody tr:nth-child(even) {
    background-color: #155724;
}

.paro-container tbody tr:nth-child(odd) {
    background-color: #1e7e34;
}

.paro-container tbody tr:hover {
    background-color: #218838;
}

.paro-container tbody tr {
    color: white;
}

@media (max-width: 600px) {
    .paro-container form {
        flex-direction: column;
        align-items: stretch;
    }

    .filtro-grupo {
        min-width: auto;
        width: 100%;
        justify-content: flex-start;
    }

    .botones-filtro {
        justify-content: center;
    }

    .paro-container form button {
        width: 100%;
        min-width: auto;
    }
}
</style>
</head>
<body>

<img src="/control_produccion/public/logo.png" alt="Logo" class="logo">

<div class="container">
    <h2>Resumen de Producción</h2>
    <h2>Dashboard del Administrador</h2>
    <h2>Bienvenid@, <?= htmlspecialchars($_SESSION['empleado']) ?></h2>
    <p><a href="login_admin.php" style="color:#d4fcd4;">Cerrar sesión</a></p>

    <form method="POST" id="filtrosForm">
        <!-- Agrupamiento -->
        <label>Agrupar por:</label>
        <select name="agrupar_por">
            <option value=""></option>
            <option value="empleado" <?= ($agrupar_por === 'empleado') ? 'selected' : '' ?>>Empleado</option>
            <option value="area" <?= ($agrupar_por === 'area') ? 'selected' : '' ?>>Área</option>
            <option value="turno" <?= ($agrupar_por === 'turno') ? 'selected' : '' ?>>Turno</option>
            <option value="todos" <?= ($agrupar_por === 'todos') ? 'selected' : '' ?>>Todos</option>
        </select>

<!-- Filtros -->
<label>Empleado:</label>
<input type="text" 
       id="filtro_empleado" 
       placeholder="Escriba para filtrar empleados..." 
       style="margin-bottom: 5px; padding: 8px; width: 100%; border-radius: 4px; border: 1px solid #ccc;"
       autocomplete="off">

<select name="empleado" id="empleado_select" style="width: 100%;" data-selected-value="<?= htmlspecialchars($empleado_seleccionado) ?>">
    <option value=""></option>
    <?php foreach ($empleados_data as $row): ?>
        <option value="<?= htmlspecialchars($row['empleado']) ?>" <?= ($empleado_seleccionado === $row['empleado']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($row['empleado']) ?>
        </option>
    <?php endforeach; ?>
</select>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const filtroInput = document.getElementById('filtro_empleado');
    const selectEmpleado = document.getElementById('empleado_select');
    
    // Guardar todas las opciones originales al cargar
    const todasLasOpciones = Array.from(selectEmpleado.options).map(option => ({
        value: option.value,
        text: option.textContent,
        element: option
    }));

    // Mostrar el empleado seleccionado en el input de filtro
    if (selectEmpleado.value !== '') {
        const selectedOption = selectEmpleado.options[selectEmpleado.selectedIndex];
        filtroInput.value = selectedOption.textContent;
    }

    // Variable para controlar si el select está expandido
    let selectExpandido = false;

    filtroInput.addEventListener('input', function() {
        const textoFiltro = this.value.toLowerCase().trim();
        
        // Limpiar el select
        selectEmpleado.innerHTML = '';
        
        // Agregar opción vacía
        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = '';
        selectEmpleado.appendChild(emptyOption);
        
        // Filtrar y agregar opciones que coincidan
        let opcionesFiltradas = 0;
        todasLasOpciones.forEach(opcion => {
            if (opcion.value === '') return; // Saltar la opción vacía original
            
            if (opcion.text.toLowerCase().includes(textoFiltro) || textoFiltro === '') {
                const newOption = document.createElement('option');
                newOption.value = opcion.value;
                newOption.textContent = opcion.text;
                
                // Mantener la selección original CORREGIDO
                if (opcion.value === selectEmpleado.getAttribute('data-selected-value')) {
                    newOption.selected = true;
                }
                
                selectEmpleado.appendChild(newOption);
                opcionesFiltradas++;
            }
        });

        // Expandir el select solo si hay texto en el filtro
        if (textoFiltro !== '') {
            selectEmpleado.size = Math.min(8, opcionesFiltradas);
            selectExpandido = true;
        } else {
            selectEmpleado.size = 0;
            selectExpandido = false;
        }
    });

    // Guardar la selección actual en un atributo data
    selectEmpleado.setAttribute('data-selected-value', '<?= htmlspecialchars($empleado_seleccionado) ?>');

    // Actualizar el input de filtro cuando se selecciona una opción
    selectEmpleado.addEventListener('change', function() {
        if (this.value !== '') {
            filtroInput.value = this.options[this.selectedIndex].textContent;
            // Actualizar el valor seleccionado
            this.setAttribute('data-selected-value', this.value);
        } else {
            filtroInput.value = '';
            this.setAttribute('data-selected-value', '');
        }
        // Cerrar el select después de seleccionar
        selectEmpleado.size = 0;
        selectExpandido = false;
    });

    // Cerrar el select cuando se quita el foco
    filtroInput.addEventListener('blur', function() {
        setTimeout(() => {
            if (selectExpandido && this.value === '') {
                selectEmpleado.size = 0;
                selectExpandido = false;
                restaurarOpcionesCompletas();
            }
        }, 200);
    });

    selectEmpleado.addEventListener('blur', function() {
        setTimeout(() => {
            if (selectExpandido && filtroInput.value === '') {
                selectEmpleado.size = 0;
                selectExpandido = false;
                restaurarOpcionesCompletas();
            }
        }, 200);
    });

    // Limpiar filtro al hacer doble clic
    filtroInput.addEventListener('dblclick', function() {
        this.value = '';
        selectEmpleado.value = '';
        selectEmpleado.setAttribute('data-selected-value', '');
        restaurarOpcionesCompletas();
        selectEmpleado.size = 0;
        selectExpandido = false;
    });

    // Expandir el select al hacer clic en el input
    filtroInput.addEventListener('click', function() {
        if (this.value !== '' && !selectExpandido) {
            this.dispatchEvent(new Event('input'));
        }
    });

    // Función para restaurar todas las opciones del select
    function restaurarOpcionesCompletas() {
        selectEmpleado.innerHTML = '';
        const selectedValue = selectEmpleado.getAttribute('data-selected-value');
        
        todasLasOpciones.forEach(opcion => {
            const newOption = document.createElement('option');
            newOption.value = opcion.value;
            newOption.textContent = opcion.text;
            if (opcion.value === selectedValue) {
                newOption.selected = true;
            }
            selectEmpleado.appendChild(newOption);
        });
    }

    // Inicializar con la selección correcta
    restaurarOpcionesCompletas();
});
</script>

        <label>Área:</label>
        <select name="area">
            <option value=""></option>
            <?php foreach ($areas_data as $row): ?>
                <option value="<?= htmlspecialchars($row['area']) ?>" <?= ($area_seleccionada === $row['area']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($row['area']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Turno:</label>
        <select name="turno">
            <option value=""></option>
            <?php foreach ($turnos_data as $row): ?>
                <option value="<?= htmlspecialchars($row['turno']) ?>" <?= ($turno_seleccionado === $row['turno']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($row['turno']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Fechas y horas -->
        <div class="flex-row" style="margin-top: 10px;">
            <div class="flex-column">
                <label for="fecha_inicio" style="font-weight: bold; font-size: 14px; margin-bottom: 4px;">Fecha Inicio:</label>
                <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= htmlspecialchars($_POST['fecha_inicio'] ?? '') ?>" style="padding: 6px; font-size: 14px;">
            </div>

            <div class="flex-column">
                <label for="fecha_fin" style="font-weight: bold; font-size: 14px; margin-bottom: 4px;">Fecha Fin:</label>
                <input type="date" id="fecha_fin" name="fecha_fin" value="<?= htmlspecialchars($_POST['fecha_fin'] ?? '') ?>" style="padding: 6px; font-size: 14px;">
            </div>

            <div class="flex-column flex-column-wide">
                <label for="hora_inicio" style="font-weight: bold; font-size: 14px; margin-bottom: 4px;">Hora Inicio:</label>
                <div class="flex-time-group">
                    <input type="time" id="hora_inicio" name="hora_inicio" value="<?= htmlspecialchars($_POST['hora_inicio'] ?? '') ?>" style="flex-grow: 1; padding: 6px; font-size: 14px;">
                    <select name="ampm_inicio" style="width: 60px; padding: 6px; font-size: 14px;">
                        <option value="AM" <?= (($_POST['ampm_inicio'] ?? '') === 'AM') ? 'selected' : '' ?>>AM</option>
                        <option value="PM" <?= (($_POST['ampm_inicio'] ?? '') === 'PM') ? 'selected' : '' ?>>PM</option>
                    </select>
                </div>
            </div>

            <div class="flex-column flex-column-wide">
                <label for="hora_fin" style="font-weight: bold; font-size: 14px; margin-bottom: 4px;">Hora Fin:</label>
                <div class="flex-time-group">
                    <input type="time" id="hora_fin" name="hora_fin" value="<?= htmlspecialchars($_POST['hora_fin'] ?? '') ?>" style="flex-grow: 1; padding: 6px; font-size: 14px;">
                    <select name="ampm_fin" style="width: 60px; padding: 6px; font-size: 14px;">
                        <option value="AM" <?= (($_POST['ampm_fin'] ?? '') === 'AM') ? 'selected' : '' ?>>AM</option>
                        <option value="PM" <?= (($_POST['ampm_fin'] ?? '') === 'PM') ? 'selected' : '' ?>>PM</option>
                    </select>
                </div>
            </div>
        </div>

        <button type="submit" name="buscar" data-section="filtros">Aplicar Filtros</button>
        <button type="button" onclick="exportarCSV()">Exportar CSV</button>
    </form>

    <?php if ($hay_filtros && !empty($resumenes)): ?>

    <?php
    // Función para formatear fecha y hora con AM/PM
    function formatearFechaHoraConAmPm($fecha, $hora, $ampm) {
        if (!$fecha) return '';
        if (!$hora || !$ampm) return date('d/m/Y', strtotime($fecha));

        $timestamp = strtotime("$fecha $hora $ampm");
        return $timestamp ? date('d/m/Y h:i A', $timestamp) : '';
    }

    $rango_inicio = formatearFechaHoraConAmPm($_POST['fecha_inicio'] ?? '', $_POST['hora_inicio'] ?? '', $_POST['ampm_inicio'] ?? '');
    $rango_fin = formatearFechaHoraConAmPm($_POST['fecha_fin'] ?? '', $_POST['hora_fin'] ?? '', $_POST['ampm_fin'] ?? '');

    // --- CONSULTA TOTAL QUIEBRAS POR EMPLEADO PARA MEJORAR RENDIMIENTO
    $empleados_list = [];
    if (isset($resumenes['empleado'])) {
        foreach ($resumenes['empleado'] as $fila) {
            $empleados_list[] = $fila['empleado'];
        }
    }

    $total_quiebras_por_empleado = [];
    if (!empty($empleados_list)) {
        $placeholders = implode(',', array_fill(0, count($empleados_list), '?'));
        $tipos_quiebras = str_repeat('s', count($empleados_list)) . 'ss';
        $params_quiebras = $empleados_list;
        $params_quiebras[] = $fecha_inicio_esc ?? '';
        $params_quiebras[] = $fecha_fin_esc ?? '';

        $sql_quiebras = "
            SELECT empleado, COUNT(*) AS total_quiebras
            FROM registro_quiebras
            WHERE empleado IN ($placeholders)
            AND fecha BETWEEN ? AND ?
            GROUP BY empleado
        ";

        $stmt = $conn->prepare($sql_quiebras);

        // Bind params dynamic
        if (!empty($params_quiebras)) {
            $bind_params = array_merge([$tipos_quiebras], $params_quiebras);
            $refs = [];
            foreach ($bind_params as $key => $val) {
                $refs[$key] = &$bind_params[$key];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $total_quiebras_por_empleado[$row['empleado']] = $row['total_quiebras'];
        }
        $stmt->close();
    }
    ?>

    <h3 style="color:white;">
        Rango filtrado: del <?= htmlspecialchars($rango_inicio) ?> al <?= htmlspecialchars($rango_fin) ?>
    </h3>

<?php foreach ($resumenes as $tipo => $datos): ?>
    <h3 style="color:white; font-weight: bold; text-shadow: 2px 2px 4px rgba(21, 18, 18, 0.5);">
        Resumen por <?= ucfirst(htmlspecialchars(str_replace('_', ' ', $tipo))) ?>
    </h3>

    <div style="max-height: 300px; overflow-y: auto; border-radius: 10px; background-color: #1e7e34; padding: 10px;">
        <table style="width:100%; color: white; border-collapse: collapse;">
            <thead>
                <tr>
                    <th><?= ucfirst(htmlspecialchars(str_replace('_', ' ', $tipo))) ?></th>
                    <?php if ($tipo === 'area'): ?>
                        <th>Equipo</th>
                    <?php endif; ?>
                    <th>Total de Órdenes</th>
                    <?php if ($tipo === 'empleado'): ?>
                        <th>Total de Quiebras</th>
                        <th>% de Quiebras</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (count($datos) > 0): ?>
                    <?php foreach ($datos as $row): ?>
                        <?php
                        $nombre = $row[$tipo];
                        if ($tipo === 'empleado') {
                            $total_quiebras = $total_quiebras_por_empleado[$nombre] ?? 0;
                            $total_produccion = $row['total_ordenes'];
                            $denominador = $total_produccion + $total_quiebras;
                            $porcentaje_quiebras = ($denominador > 0) ? round(($total_quiebras / $denominador) * 100, 2) : 0;
                        } else {
                            $total_quiebras = 'N/A';
                            $porcentaje_quiebras = 'N/A';
                        }
                        ?>
                        <tr style="background-color: #28a745;">
                            <td style="color: white; font-weight: bold;"><?= htmlspecialchars($nombre) ?></td>
                            <?php if ($tipo === 'area'): ?>
                                <td style="color: white; font-weight: bold;"><?= htmlspecialchars($row['equipo']) ?></td>
                            <?php endif; ?>
                            <td style="color: white; font-weight: bold;"><?= htmlspecialchars($row['total_ordenes']) ?></td>
                            <?php if ($tipo === 'empleado'): ?>
                                <td style="color: white; font-weight: bold;"><?= $total_quiebras ?></td>
                                <td style="color: white; font-weight: bold;"><?= $porcentaje_quiebras ?>%</td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?= ($tipo === 'empleado') ? 4 : ($tipo === 'area' ? 3 : 2) ?>" style="color: white;">No hay resultados.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Gráfico con Chart.js -->
    <?php if (count($datos) > 0): ?>
        <canvas id="grafico_<?= $tipo ?>" style="background-color: #1e7e34; border-radius: 10px; margin-top: 20px; border: 2px solid #155724;"></canvas>
        <script>
            const ctx_<?= $tipo ?> = document.getElementById('grafico_<?= $tipo ?>').getContext('2d');
            new Chart(ctx_<?= $tipo ?>, {
                type: 'bar',
                data: {
                    labels: <?= $tipo === 'area' ? json_encode(array_map(function($row) { return $row['area'] . ($row['equipo'] ? ' - ' . $row['equipo'] : ''); }, $datos)) : json_encode(array_column($datos, $tipo)) ?>,
                    datasets: [{
                        label: 'Total de Órdenes por <?= ucfirst($tipo) ?>' + <?= $tipo === 'area' ? "' y Equipo'" : "''" ?>,
                        data: <?= json_encode(array_column($datos, 'total_ordenes')) ?>,
                        backgroundColor: 'rgba(255, 255, 255, 0.6)',
                        borderColor: 'rgba(255, 255, 255, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: 'white', font: { weight: 'bold' } }
                        },
                        x: {
                            ticks: { color: 'white', font: { weight: 'bold' } }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        title: {
                            display: true,
                            text: 'Resumen Gráfico por <?= ucfirst($tipo) ?>' + <?= $tipo === 'area' ? "' y Equipo'" : "''" ?>,
                            color: 'white',
                            font: { weight: 'bold' }
                        },
                        tooltip: {
                            bodyColor: 'white',
                            titleColor: 'white'
                        }
                    }
                }
            });
        </script>
    <?php endif; ?>
<?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function exportarCSV() {
    const form = document.getElementById('filtrosForm');
    let input = form.querySelector('input[name="exportar_csv"]');
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'exportar_csv';
        input.value = '1';
        form.appendChild(input);
    }
    form.submit();
}
</script>

<?php
// Lista de equipos (cacheados)
$equipos_data = obtenerValoresUnicos($conn, 'nombre_equipo', 'nombre_equipo');

// Función para formatear duración
function formatearDuracion($inicio, $fin) {
    if (!$fin) return 'En curso';
    $inicio_dt = new DateTime($inicio);
    $fin_dt = new DateTime($fin);
    $interval = $inicio_dt->diff($fin_dt);
    return sprintf('%02d:%02d:%02d', $interval->h + ($interval->days * 24), $interval->i, $interval->s);
}

// Variables POST
$equipo_seleccionado = $_POST['equipo'] ?? '';
$fecha_inicio_paro = $_POST['fecha_inicio_paro'] ?? $_POST['fecha_inicio'] ?? '';
$hora_inicio_paro = $_POST['hora_inicio_paro'] ?? '00:00';
$ampm_inicio_paro = $_POST['ampm_inicio_paro'] ?? 'AM';
$fecha_fin_paro = $_POST['fecha_fin_paro'] ?? $_POST['fecha_fin'] ?? '';
$hora_fin_paro = $_POST['hora_fin_paro'] ?? '11:59';
$ampm_fin_paro = $_POST['ampm_fin_paro'] ?? 'PM';
$mostrar_paros = isset($_POST['ver_paros']);
$exportar_csv = isset($_POST['exportar_csv']);

// Conversión de hora AM/PM a 24h
function convertirHora24($hora, $ampm) {
    $parts = explode(':', $hora);
    $hora_num = intval($parts[0]);
    $minutos = $parts[1] ?? '00';
    if (strtoupper($ampm) === 'PM' && $hora_num < 12) $hora_num += 12;
    elseif (strtoupper($ampm) === 'AM' && $hora_num == 12) $hora_num = 0;
    return str_pad($hora_num, 2, '0', STR_PAD_LEFT) . ':' . $minutos . ':00';
}

$hora_inicio_24 = convertirHora24($hora_inicio_paro, $ampm_inicio_paro);
$hora_fin_24 = convertirHora24($hora_fin_paro, $ampm_fin_paro);

$inicio_paro = (!empty($fecha_inicio_paro)) ? "$fecha_inicio_paro $hora_inicio_24" : null;
$fin_paro = (!empty($fecha_fin_paro)) ? "$fecha_fin_paro $hora_fin_24" : null;

// Si no hay filtros aplicados, usar la fecha actual
if (!$mostrar_paros && !$exportar_csv && empty($equipo_seleccionado) && empty($fecha_inicio_paro) && empty($fecha_fin_paro)) {
    date_default_timezone_set('America/Costa_Rica');
    $hoy = date('Y-m-d');
    $inicio_paro = "$hoy 00:00:00";
    $fin_paro = "$hoy 23:59:59";
}

// Filtro WHERE
$where_paro = [];

if (!empty($equipo_seleccionado)) {
    $equipo_esc = $conn->real_escape_string($equipo_seleccionado);
    $where_paro[] = "pp.equipo = '$equipo_esc'";
}

if ($inicio_paro && $fin_paro) {
    $inicio_esc = $conn->real_escape_string($inicio_paro);
    $fin_esc = $conn->real_escape_string($fin_paro);
    $where_paro[] = "(
        (pp.fecha_inicio BETWEEN '$inicio_esc' AND '$fin_esc') OR
        (pp.fecha_fin BETWEEN '$inicio_esc' AND '$fin_esc') OR
        (pp.fecha_inicio <= '$inicio_esc' AND pp.fecha_fin >= '$fin_esc')
    )";
} elseif ($inicio_paro) {
    $inicio_esc = $conn->real_escape_string($inicio_paro);
    $where_paro[] = "pp.fecha_fin >= '$inicio_esc'";
} elseif ($fin_paro) {
    $fin_esc = $conn->real_escape_string($fin_paro);
    $where_paro[] = "pp.fecha_inicio <= '$fin_esc'";
}

$where_sql = count($where_paro) ? 'WHERE ' . implode(' AND ', $where_paro) : '';
$sql_paros = "SELECT pp.*, tp.nombre as tipo_paro_nombre 
              FROM paro_produccion pp 
              LEFT JOIN tipos_paro tp ON pp.tipo_paro = tp.id 
              $where_sql 
              ORDER BY pp.fecha_inicio DESC";
$result_paros = ($mostrar_paros || $exportar_csv || empty($_POST)) ? $conn->query($sql_paros) : null;

// Función para mostrar fecha y hora en formato AM/PM 
function formatoHoraAMPM($fechaHora) {
    if (!$fechaHora) return '';
    $dt = new DateTime($fechaHora);
    return $dt->format('Y-m-d h:i A');
}
?>

<!-- FORMULARIO DE PAROS -->
<div class="paro-container">
    <h2>📋 Registros de Paros de produccion</h2>

    <form method="POST">
        <div class="filtro-grupo">
            <label for="equipo">Equipo:</label>
            <select name="equipo" id="equipo">
                <option value="">-- Todos --</option>
                <?php
                if ($equipos_data):
                    foreach ($equipos_data as $eq):
                        $selected = ($equipo_seleccionado === $eq['nombre_equipo']) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($eq['nombre_equipo']) . '" ' . $selected . '>' . htmlspecialchars($eq['nombre_equipo']) . '</option>';
                    endforeach;
                endif;
                ?>
            </select>
        </div>

        <div class="filtro-grupo">
            <label for="fecha_inicio_paro">Fecha Inicio:</label>
            <input type="date" name="fecha_inicio_paro" id="fecha_inicio_paro" value="<?= htmlspecialchars($fecha_inicio_paro) ?>">
        </div>
        <div class="filtro-grupo">
            <label for="hora_inicio_paro">Hora Inicio:</label>
            <input type="time" name="hora_inicio_paro" id="hora_inicio_paro" value="<?= htmlspecialchars($hora_inicio_paro) ?>">
        </div>
        <div class="filtro-grupo">
            <label for="ampm_inicio_paro">AM/PM:</label>
            <select name="ampm_inicio_paro" id="ampm_inicio_paro">
                <option value="AM" <?= $ampm_inicio_paro === 'AM' ? 'selected' : '' ?>>AM</option>
                <option value="PM" <?= $ampm_inicio_paro === 'PM' ? 'selected' : '' ?>>PM</option>
            </select>
        </div>

        <div class="filtro-grupo">
            <label for="fecha_fin_paro">Fecha Fin:</label>
            <input type="date" name="fecha_fin_paro" id="fecha_fin_paro" value="<?= htmlspecialchars($fecha_fin_paro) ?>">
        </div>
        <div class="filtro-grupo">
            <label for="hora_fin_paro">Hora Fin:</label>
            <input type="time" name="hora_fin_paro" id="hora_fin_paro" value="<?= htmlspecialchars($hora_fin_paro) ?>">
        </div>
        <div class="filtro-grupo">
            <label for="ampm_fin_paro">AM/PM:</label>
            <select name="ampm_fin_paro" id="ampm_fin_paro">
                <option value="AM" <?= $ampm_fin_paro === 'AM' ? 'selected' : '' ?>>AM</option>
                <option value="PM" <?= $ampm_fin_paro === 'PM' ? 'selected' : '' ?>>PM</option>
            </select>
        </div>

        <div class="botones-filtro">
            <button type="submit" name="ver_paros">🔍 Ver Paros</button>
            <button type="submit" name="exportar_csv_paros" formaction="exportar_paros_csv.php">📁 Exportar CSV</button>
        </div>
    </form>

<!-- TABLA DE RESULTADOS DE PAROS -->
<?php if ($mostrar_paros): ?>
    <?php if ($result_paros && $result_paros->num_rows > 0): ?>
        <div class="paro-table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Empleado</th>
                        <th>Área</th>
                        <th>Equipo</th>
                        <th>Tipo Paro</th>
                        <th>Motivo</th>
                        <th>Fecha Inicio</th>
                        <th>Fecha Fin</th>
                        <th>Duración</th>
                        <th>Activo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result_paros->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['id']) ?></td>
                        <td><?= htmlspecialchars($row['empleado']) ?></td>
                        <td><?= htmlspecialchars($row['area']) ?></td>
                        <td><?= htmlspecialchars($row['equipo']) ?></td>
                        <td><?= htmlspecialchars($row['tipo_paro_nombre'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($row['motivo']) ?></td>
                        <td><?= formatoHoraAMPM($row['fecha_inicio']) ?></td>
                        <td><?= formatoHoraAMPM($row['fecha_fin']) ?></td>
                        <td><?= formatearDuracion($row['fecha_inicio'], $row['fecha_fin']) ?></td>
                        <td><?= $row['activo'] ? 'Sí' : 'No' ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="margin-top: 10px; color: white;">No se encontraron registros con los filtros aplicados.</p>
    <?php endif; ?>
<?php endif; ?>
</div>

<!-- 🔍 CONSULTA PRODUCCIÓN POR HORA PARA CADA EMPLEADO -->

<div class="container">
    <h3 style="color: white;">Consulta de Producción por Hora</h3>

    <form method="POST">
        <label for="empleado_detalle">Seleccionar Empleado:</label>
        <select name="empleado_detalle" required>
            <option value="">Seleccione un empleado</option>
            <?php
            // Obtener empleados de la tabla empleados
            $empleados_query = $conn->query("SELECT DISTINCT nombre_empleado FROM empleados ORDER BY nombre_empleado");
            while ($row = $empleados_query->fetch_assoc()) {
                $nombre = htmlspecialchars($row['nombre_empleado']);
                $selected = (isset($_POST['empleado_detalle']) && $_POST['empleado_detalle'] === $row['nombre_empleado']) ? 'selected' : '';
                echo "<option value=\"$nombre\" $selected>$nombre</option>";
            }
            ?>
        </select>

        <label>Fecha Inicio:</label>
        <input type="date" name="fecha_detalle_inicio" required value="<?= htmlspecialchars($_POST['fecha_detalle_inicio'] ?? '') ?>">

        <label>Hora Inicio:</label>
        <input type="time" name="hora_detalle_inicio" required value="<?= htmlspecialchars($_POST['hora_detalle_inicio'] ?? '00:00') ?>">

        <label>Fecha Fin:</label>
        <input type="date" name="fecha_detalle_fin" required value="<?= htmlspecialchars($_POST['fecha_detalle_fin'] ?? '') ?>">

        <label>Hora Fin:</label>
        <input type="time" name="hora_detalle_fin" required value="<?= htmlspecialchars($_POST['hora_detalle_fin'] ?? '23:59') ?>">

        <button type="submit" name="ver_produccion_hora" data-section="produccion">Ver Producción por Hora</button>
    </form>

    <?php
if (isset($_POST['ver_produccion_hora'])) {
    $empleado = $_POST['empleado_detalle'] ?? '';
    $fecha_inicio = $_POST['fecha_detalle_inicio'] ?? '';
    $hora_inicio = $_POST['hora_detalle_inicio'] ?? '00:00';
    $fecha_fin = $_POST['fecha_detalle_fin'] ?? '';
    $hora_fin = $_POST['hora_detalle_fin'] ?? '23:59';

    $inicio_completo = "$fecha_inicio $hora_inicio:00";
    $fin_completo = "$fecha_fin $hora_fin:00";

    if ($inicio_completo > $fin_completo) {
        echo "<p style='color: red;'>La fecha y hora de inicio no pueden ser posteriores a la fecha y hora de fin.</p>";
    } else {
        echo "<h3 style='color: white;'>Rango filtrado: del " . htmlspecialchars($inicio_completo) . " al " . htmlspecialchars($fin_completo) . "</h3>";

        // Producción por hora - Optimizada con índices
        $stmt = $conn->prepare("
            SELECT 
                DATE_FORMAT(fecha, '%l %p') AS hora_exacta,
                HOUR(fecha) AS hora_24,
                COUNT(*) AS total
            FROM (
                SELECT fecha FROM produccion 
                WHERE empleado = ? AND fecha BETWEEN ? AND ?
                UNION ALL
                SELECT fecha FROM registros_antiguos 
                WHERE empleado = ? AND fecha BETWEEN ? AND ?
            ) AS todas
            GROUP BY hora_24
            ORDER BY hora_24
        ");
        $stmt->bind_param("ssssss", $empleado, $inicio_completo, $fin_completo, $empleado, $inicio_completo, $fin_completo);
        $stmt->execute();
        $res = $stmt->get_result();

        $horas = [];
        $totales = [];

        while ($row = $res->fetch_assoc()) {
            $horas[] = $row['hora_exacta'];
            $totales[] = (int)$row['total'];
        }
        $stmt->close();

        // Quiebras - Optimizada con índice
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total_quiebras 
            FROM registro_quiebras 
            WHERE empleado = ? AND fecha BETWEEN ? AND ?
        ");
        $stmt->bind_param("sss", $empleado, $inicio_completo, $fin_completo);
        $stmt->execute();
        $res_q = $stmt->get_result();
        $total_quiebras = $res_q->fetch_assoc()['total_quiebras'] ?? 0;
        $stmt->close();

        if (!empty($horas)) {
            $total_ordenes = array_sum($totales);
            $total_global = $total_ordenes + $total_quiebras;
            $promedio = $total_ordenes / count($horas);
            $porcentaje_quiebras = ($total_global > 0) ? ($total_quiebras / $total_global) * 100 : 0;
            ?>

        <!-- Tabla por hora con scroll y fondo verde -->
<div style="margin-top: 25px; background-color: #1e7e34; padding: 20px; border-radius: 12px; color: white;">
    <h4 style="font-weight: bold;">Producción por Hora</h4>

    <div style="max-height: 300px; overflow-y: auto; border: 2px solid #145214; border-radius: 8px;">
        <table style="width: 100%; border-collapse: collapse; margin-top: 10px; color: white;">
            <thead>
                <tr style="background-color: #145214; color: white; position: sticky; top: 0;">
                    <th style="padding: 8px;">Hora</th>
                    <th style="padding: 8px;">Total Órdenes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($horas as $i => $hora): ?>
                    <tr style="background-color: <?= $i % 2 === 0 ? '#1e7e34' : '#2e8b57' ?>; color: white; border-bottom: 1px solid #145214;">
                        <td style="padding: 8px;"><?= htmlspecialchars($hora) ?></td>
                        <td style="padding: 8px;"><?= $totales[$i] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
            <!-- Gráfico -->
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <canvas id="grafico_produccion" style="background: #1e7e34; border-radius: 12px; margin-top: 25px; padding: 12px;"></canvas>
            <script>
                const ctx = document.getElementById('grafico_produccion').getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?= json_encode($horas) ?>,
                        datasets: [{
                            label: 'Órdenes por hora',
                            data: <?= json_encode($totales) ?>,
                            backgroundColor: 'rgba(40, 167, 69, 0.2)',
                            borderColor: 'rgba(40, 167, 69, 1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.3,
                            pointRadius: 5,
                            pointBackgroundColor: 'white'
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { color: 'white' },
                                grid: { color: 'rgba(255,255,255,0.1)' }
                            },
                            x: {
                                ticks: { color: 'white' },
                                grid: { color: 'rgba(255,255,255,0.1)' }
                            }
                        },
                        plugins: {
                            legend: {
                                labels: { color: 'white' }
                            },
                            title: {
                                display: true,
                                text: 'Producción por hora de <?= addslashes(htmlspecialchars($empleado)) ?>',
                                color: 'white',
                                font: { size: 18, weight: 'bold' }
                            },
                            tooltip: {
                                backgroundColor: '#28a745',
                                titleColor: 'white',
                                bodyColor: 'white'
                            }
                        }
                    }
                });
            </script>

            <!-- Resumen final -->
            <div style="margin-top: 25px; background-color: #2c2f33; padding: 20px; border-radius: 12px; color: white;">
                <h4 style="font-weight: bold;">Resumen</h4>
                <p><strong>Total producción:</strong> <?= $total_ordenes ?> órdenes</p>
                <p><strong>Promedio por hora:</strong> <?= number_format($promedio, 2) ?></p>
                <p><strong>Total quiebras:</strong> <?= $total_quiebras ?></p>
                <p><strong>Porcentaje de quiebras:</strong> <?= number_format($porcentaje_quiebras, 2) ?>%</p>
            </div>

        <?php
        } else {
            echo "<div style='background:#2c2f33; color:white; padding:15px; border-radius:10px; margin-top:20px'>";
            echo "<h4>Resumen</h4>";
            echo "<p style='color:yellow;'>No se encontraron datos de producción.</p>";
            echo "<p><strong>Total quiebras:</strong> $total_quiebras</p>";
            if ($total_quiebras > 0) {
                echo "<p style='color:orange;'>No hubo producción, pero sí se registraron quiebras.</p>";
            }
            echo "</div>";
        }
    }
}
    ?>
</div>

<!-- BUSCAR ORDEN -->
<div class="tab-container">
  <div class="tabs" role="tablist" aria-label="Pestañas">
    <button class="tab-btn active" data-tab="buscar-orden" role="tab" aria-selected="true" aria-controls="buscar-orden" id="buscar-orden-tab">Buscar Orden</button>
  </div>

  <div class="tab-content">
    <div id="buscar-orden" class="tab-panel active" role="tabpanel" aria-labelledby="buscar-orden-tab" tabindex="0">
      <h3>Buscar historial por Orden</h3>
      <form id="formBuscarOrden" method="POST" action="">
        <input
          type="text"
          name="orden_buscar"
          placeholder="Ingrese número o código de orden"
          required
          value="<?= isset($_POST['orden_buscar']) ? htmlspecialchars($_POST['orden_buscar']) : '' ?>"
          aria-label="Número o código de orden"
        />
        <button type="submit" name="buscar_orden">Buscar</button>
      </form>

      <div id="resultadoBusquedaOrden">
        <?php if (!empty($historial_orden)) : ?>
          <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; border-radius: 8px; padding: 8px; background-color: #f9f9f9;">
            <table>
              <thead>
                <tr>
                  <th>Empleado</th>
                  <th>Área</th>
                  <th>Turno</th>
                  <th>Fecha</th>
                </tr>
              </thead>
              <tbody>
  <?php foreach ($historial_orden as $fila) : ?>
    <tr>
      <td><?= htmlspecialchars($fila['empleado']) ?></td>
      <td><?= htmlspecialchars($fila['area']) ?></td>
      <td><?= htmlspecialchars($fila['turno']) ?></td>
      <td>
        <?php
          $fecha_raw = $fila['fecha'];
          $dt = DateTime::createFromFormat('Y-m-d H:i:s', $fecha_raw);
          if ($dt) {
            echo $dt->format('Y-m-d h:i:s A');
          } else {
            echo htmlspecialchars($fecha_raw);
          }
        ?>
      </td>
    </tr>
  <?php endforeach; ?>
</tbody>
            </table>
          </div>
        <?php elseif (isset($_POST['buscar_orden'])) : ?>
          <div class="alert">No se encontraron registros para esa orden.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="firma">
  Sistema de control de producción | © <?= date("Y"); ?>
  <p>Desarrollado por: Nestor Rosales | Rosalesdev91</p> 
</div>

<!-- JavaScript para gestionar la sección activa y scroll -->
<script>
  function guardarSeccion(seccion) {
    localStorage.setItem('seccionActiva', seccion);
  }

  window.onload = function() {
    const activa = localStorage.getItem('seccionActiva') || 'filtros';

    document.querySelectorAll('.seccion').forEach(seccion => {
      if (seccion) {
        seccion.style.display = 'none';
        seccion.style.maxHeight = null;
        seccion.style.overflowY = null;
      }
    });

    const seccionMostrar = document.getElementById(activa);
    if (seccionMostrar) {
      seccionMostrar.style.display = 'block';
      seccionMostrar.style.maxHeight = '400px';
      seccionMostrar.style.overflowY = 'auto';
    }

    const graficoProduccion = document.getElementById('grafico_produccion');
    if (graficoProduccion) {
      graficoProduccion.scrollIntoView({ behavior: 'smooth' });
    }
  };

  document.querySelectorAll('button[data-section]').forEach(button => {
    button.addEventListener('click', function () {
      const section = this.getAttribute('data-section');
      localStorage.setItem('seccionActiva', section);
    });
  });
</script>

<?php
// Cerrar conexión al final
$conn->close();
?>

<!-- Tracking de navegación para monitor en vivo -->
<script>
(function() {
    const pagina = window.location.pathname.split('/').pop().replace('.php', '');
    fetch('track.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            modulo: pagina, 
            pagina: window.location.pathname 
        })
    }).catch(err => console.log('Tracking error:', err));
})();
</script>
</body>
</html>