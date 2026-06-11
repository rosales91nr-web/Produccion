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

$conn->set_charset("utf8");
date_default_timezone_set('America/Costa_Rica');

// Constantes de configuración
const TIEMPOS_MAXIMOS = [
    'cafe1' => 15,
    'comida' => 30,
    'cafe2' => 15
];
const TOLERANCIA_MINUTOS = 1;

// Filtros
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d');
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$filtro_empleado = $_GET['empleado'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';

// Obtener lista de empleados (cacheable si es necesario) - Excluyendo empleados específicos
$empleados_excluidos = ['Antirrayas', 'Montaje', 'Laboratorio', 'DUARTE MORENO NUBIA VERONICA', 'Nestor Rosales Otero', 'Nestor Rosales', 'Jean Carlo Arias Chaves', 'Marvin Medina Artola'];
$empleados_excluidos_str = "'" . implode("','", $empleados_excluidos) . "'";
$empleados = [];
$stmt = $conn->prepare("SELECT codigo_empleado, nombre_empleado FROM empleados WHERE nombre_empleado NOT IN ($empleados_excluidos_str) ORDER BY nombre_empleado");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $empleados[] = $row;
}
$stmt->close();

// Generar fechas de manera eficiente
$fechas = [];
$fecha_actual = new DateTime($fecha_desde);
$fecha_fin = new DateTime($fecha_hasta);
$intervalo = new DateInterval('P1D');
while ($fecha_actual <= $fecha_fin) {
    $fechas[] = $fecha_actual->format('Y-m-d');
    $fecha_actual->add($intervalo);
}

// Función para procesar un registro de asistencia (optimizada con early returns)
function procesarRegistro($row) {
    $estados_pausas = [
        'cafe1' => 'no_tomada',
        'comida' => 'no_tomada', 
        'cafe2' => 'no_tomada'
    ];
    
    $tiempos_pausas = [
        'cafe1' => 0,
        'comida' => 0,
        'cafe2' => 0
    ];
    
    $tiempos_extra = [
        'cafe1' => 0,
        'comida' => 0,
        'cafe2' => 0
    ];
    
    $total_pausas_excedidas = 0;
    
    // Procesar cada pausa
    $pausas = [
        'cafe1' => ['salida' => 'cafe1_salida', 'entrada' => 'cafe1_entrada', 'max' => TIEMPOS_MAXIMOS['cafe1']],
        'comida' => ['salida' => 'comida_salida', 'entrada' => 'comida_entrada', 'max' => TIEMPOS_MAXIMOS['comida']],
        'cafe2' => ['salida' => 'cafe2_salida', 'entrada' => 'cafe2_entrada', 'max' => TIEMPOS_MAXIMOS['cafe2']]
    ];
    
    foreach ($pausas as $tipo => $config) {
        $salida = $row[$config['salida']];
        $entrada = $row[$config['entrada']];
        
        if (!$salida && !$entrada) {
            continue; // Early return para no tomada
        }
        
        if ($salida && $entrada) {
            $ts_salida = strtotime($salida);
            $ts_entrada = strtotime($entrada);
            $duracion = max(0, ($ts_entrada - $ts_salida) / 60);
            
            $tiempos_pausas[$tipo] = round($duracion);
            
            $limite = $config['max'] + TOLERANCIA_MINUTOS;
            if ($duracion <= $limite) {
                $estados_pausas[$tipo] = 'a_tiempo';
            } else {
                $estados_pausas[$tipo] = 'excedido';
                $tiempos_extra[$tipo] = round($duracion - $config['max']);
                $total_pausas_excedidas++;
            }
        } else {
            $estados_pausas[$tipo] = 'incompleto';
        }
    }
    
    // Análisis general
    $es_ausencia = $row['marcas_realizadas'] == 0;
    $es_completo = ($row['cafe1_salida'] && $row['cafe1_entrada'] && $row['comida_salida'] && $row['comida_entrada']);
    $es_incompleto = $row['marcas_realizadas'] > 0 && !$es_completo;
    $tiempo_total_pausas = array_sum($tiempos_pausas);
    $tiene_excedido = $total_pausas_excedidas > 0;
    
    $row['estados_pausas'] = $estados_pausas;
    $row['tiempos_pausas'] = $tiempos_pausas;
    $row['tiempos_extra'] = $tiempos_extra;
    $row['total_pausas_excedidas'] = $total_pausas_excedidas;
    $row['tiene_excedido'] = $tiene_excedido;
    $row['es_ausencia'] = $es_ausencia;
    $row['es_completo'] = $es_completo;
    $row['es_incompleto'] = $es_incompleto;
    $row['tiempo_total_pausas'] = $tiempo_total_pausas;
    
    return $row;
}

// Consulta principal optimizada (evitar repetición en export) - Excluyendo empleados específicos
// Consulta principal optimizada (evitar repetición en export) - Excluyendo empleados específicos
function ejecutarConsulta($conn, $fechas, $filtro_empleado) {
    // Construir subconsulta de fechas con UNION ALL válido
    $subquery_dates = implode(" UNION ALL ", array_map(function($fecha) {
        return "SELECT '$fecha' AS fecha";
    }, $fechas));
    
    $query = "
        SELECT 
            e.codigo_empleado,
            e.nombre_empleado,
            d.fecha,
            MAX(CASE WHEN a.tipo_marca = 'cafe1_salida' THEN TIME(a.fecha_hora) END) as cafe1_salida,
            MAX(CASE WHEN a.tipo_marca = 'cafe1_entrada' THEN TIME(a.fecha_hora) END) as cafe1_entrada,
            MAX(CASE WHEN a.tipo_marca = 'comida_salida' THEN TIME(a.fecha_hora) END) as comida_salida,
            MAX(CASE WHEN a.tipo_marca = 'comida_entrada' THEN TIME(a.fecha_hora) END) as comida_entrada,
            MAX(CASE WHEN a.tipo_marca = 'cafe2_salida' THEN TIME(a.fecha_hora) END) as cafe2_salida,
            MAX(CASE WHEN a.tipo_marca = 'cafe2_entrada' THEN TIME(a.fecha_hora) END) as cafe2_entrada,
            COUNT(DISTINCT a.tipo_marca) as marcas_realizadas
        FROM empleados e
        CROSS JOIN (
            $subquery_dates
        ) d
        LEFT JOIN asistencia a ON e.codigo_empleado = a.codigo_empleado 
            AND DATE(a.fecha_hora) = d.fecha
        WHERE e.nombre_empleado NOT IN ('Antirrayas', 'Montaje', 'Laboratorio', 'DUARTE MORENO NUBIA VERONICA', 'Nestor Rosales Otero', 'Nestor Rosales', 'Jean Carlo Arias Chaves', 'Marvin Medina Artola')
    ";

    $params = [];
    $types = '';
    if ($filtro_empleado) {
        $query .= " AND e.codigo_empleado = ?";
        $params[] = $filtro_empleado;
        $types .= 's';
    }

    $query .= " GROUP BY e.codigo_empleado, e.nombre_empleado, d.fecha
                ORDER BY d.fecha DESC, e.nombre_empleado ASC";

    $stmt = $conn->prepare($query);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

$resultado = ejecutarConsulta($conn, $fechas, $filtro_empleado);

// Procesar datos con análisis
$registros = [];
$estadisticas = [
    'total_registros' => 0,
    'excedidos' => 0,
    'ausencias' => 0,
    'completos' => 0,
    'incompletos' => 0,
    'pausas_excedidas' => 0
];

while ($row = $resultado->fetch_assoc()) {
    $row = procesarRegistro($row);
    
    // Filtrar según estado (solo para visualización en pantalla)
    if ($filtro_estado && !isset($_GET['exportar'])) {
        if (($filtro_estado === 'excedido' && !$row['tiene_excedido']) ||
            ($filtro_estado === 'ausencia' && !$row['es_ausencia']) ||
            ($filtro_estado === 'completo' && !$row['es_completo']) ||
            ($filtro_estado === 'incompleto' && !$row['es_incompleto'])) {
            continue;
        }
    }
    
    $registros[] = $row;
    
    // Estadísticas
    $estadisticas['total_registros']++;
    if ($row['tiene_excedido']) $estadisticas['excedidos']++;
    if ($row['es_ausencia']) $estadisticas['ausencias']++;
    if ($row['es_completo']) $estadisticas['completos']++;
    if ($row['es_incompleto']) $estadisticas['incompletos']++;
    $estadisticas['pausas_excedidas'] += $row['total_pausas_excedidas'];
}

// EXPORTACIÓN (ahora usa la función reutilizada)
if (isset($_GET['exportar'])) {
    $resultado_export = ejecutarConsulta($conn, $fechas, $filtro_empleado);
    $registros_export = [];
    
    while ($row = $resultado_export->fetch_assoc()) {
        $registros_export[] = procesarRegistro($row);
    }
    
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_pausas_' . $fecha_desde . '_a_' . $fecha_hasta . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background-color: #f2f2f2;'>
            <th>Empleado</th>
            <th>Código</th>
            <th>Fecha</th>
            <th>Café 1 Salida</th>
            <th>Café 1 Entrada</th>
            <th>Duración Café 1</th>
            <th>Estado Café 1</th>
            <th>Comida Salida</th>
            <th>Comida Entrada</th>
            <th>Duración Comida</th>
            <th>Estado Comida</th>
            <th>Café 2 Salida</th>
            <th>Café 2 Entrada</th>
            <th>Duración Café 2</th>
            <th>Estado Café 2</th>
            <th>Tiempo Total Pausas</th>
            <th>Pausas Excedidas</th>
            <th>Estado General</th>
        </tr>";
    
    foreach ($registros_export as $reg) {
        $estado = $reg['es_ausencia'] ? 'Ausencia' : ($reg['es_completo'] ? 'Completo' : 'Incompleto');
        
        echo "<tr>
                <td>" . htmlspecialchars($reg['nombre_empleado'], ENT_QUOTES, 'UTF-8') . "</td>
                <td>" . htmlspecialchars($reg['codigo_empleado'], ENT_QUOTES, 'UTF-8') . "</td>
                <td>" . htmlspecialchars($reg['fecha'], ENT_QUOTES, 'UTF-8') . "</td>
                <td>" . ($reg['cafe1_salida'] ? htmlspecialchars($reg['cafe1_salida'], ENT_QUOTES, 'UTF-8') : '') . "</td>
                <td>" . ($reg['cafe1_entrada'] ? htmlspecialchars($reg['cafe1_entrada'], ENT_QUOTES, 'UTF-8') : '') . "</td>
                <td>" . $reg['tiempos_pausas']['cafe1'] . " min</td>
                <td>" . htmlspecialchars($reg['estados_pausas']['cafe1'], ENT_QUOTES, 'UTF-8') . "</td>
                <td>" . ($reg['comida_salida'] ? htmlspecialchars($reg['comida_salida'], ENT_QUOTES, 'UTF-8') : '') . "</td>
                <td>" . ($reg['comida_entrada'] ? htmlspecialchars($reg['comida_entrada'], ENT_QUOTES, 'UTF-8') : '') . "</td>
                <td>" . $reg['tiempos_pausas']['comida'] . " min</td>
                <td>" . htmlspecialchars($reg['estados_pausas']['comida'], ENT_QUOTES, 'UTF-8') . "</td>
                <td>" . ($reg['cafe2_salida'] ? htmlspecialchars($reg['cafe2_salida'], ENT_QUOTES, 'UTF-8') : '') . "</td>
                <td>" . ($reg['cafe2_entrada'] ? htmlspecialchars($reg['cafe2_entrada'], ENT_QUOTES, 'UTF-8') : '') . "</td>
                <td>" . $reg['tiempos_pausas']['cafe2'] . " min</td>
                <td>" . htmlspecialchars($reg['estados_pausas']['cafe2'], ENT_QUOTES, 'UTF-8') . "</td>
                <td>" . $reg['tiempo_total_pausas'] . " min</td>
                <td>" . $reg['total_pausas_excedidas'] . "</td>
                <td>" . htmlspecialchars($estado, ENT_QUOTES, 'UTF-8') . "</td>
            </tr>";
    }
    echo "</table>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Control de Pausas</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #059668bd;
            --primary-light: #10b981;
            --primary-dark: #047857;
            --secondary: #64748b;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --success: #22c55e;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --white: #ffffff;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Header Mejorado */
        .header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            color: var(--white);
            padding: 1.5rem 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-info {
            flex: 1;
        }

        .header-info h2 {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            opacity: 0.95;
        }

        .header-info .user-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0.5rem 0;
        }

        .header-info a {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.2s;
            margin-top: 0.5rem;
        }

        .header-info a:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(-2px);
        }

        .logo {
            width: 180px;
            height: auto;
            opacity: 0.95;
        }
        
        /* Page Header Rediseñado */
        .page-header {
            background: var(--white);
            border-bottom: 1px solid var(--gray-200);
            padding: 2.5rem 2rem;
        }

        .page-header-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .page-header h1 {
            font-size: 2rem;
            font-weight: 800;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .page-header h1 i {
            color: var(--primary);
        }
        
        .page-header p {
            font-size: 1rem;
            color: var(--gray-600);
            font-weight: 400;
        }
        
        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
            flex: 1;
        }
        
        /* Cards Modernos */
        .card {
            background: var(--white);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            transition: all 0.2s;
            animation: fadeIn 0.3s ease-out;
        }

        .card:hover {
            box-shadow: var(--shadow-md);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-100);
        }

        .card-header h3 {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header i {
            color: var(--primary);
            font-size: 1.25rem;
        }

        /* Filtros Mejorados */
        .filtros {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.2s;
            background-color: var(--white);
            color: var(--gray-900);
            font-weight: 500;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }

        .form-control:hover {
            border-color: var(--gray-400);
        }
        
        /* Botones Profesionales */
        .btn-group {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            text-decoration: none;
            color: var(--white);
            box-shadow: var(--shadow-sm);
        }
        
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn:active {
            transform: translateY(0);
        }
        
        .btn-primary {
            background: var(--primary);
        }

        .btn-primary:hover {
            background: var(--primary-light);
        }
        
        .btn-success {
            background: var(--success);
        }

        .btn-success:hover {
            background: #16a34a;
        }
        
        .btn-secondary {
            background: var(--secondary);
            color: var(--white);
        }

        .btn-secondary:hover {
            background: var(--gray-600);
        }
        
        /* Estadísticas Premium */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: 1rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
            animation: fadeIn 0.4s ease-out;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary);
            transform: scaleX(0);
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card:hover::before {
            transform: scaleX(1);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .stat-icon.primary {
            background: rgba(5, 150, 105, 0.1);
            color: var(--primary);
        }

        .stat-icon.danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .stat-icon.warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.success {
            background: rgba(34, 197, 94, 0.1);
            color: var(--success);
        }

        .stat-icon.info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
            line-height: 1;
        }
        
        .stat-label {
            color: var(--gray-600);
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Tabla Premium con Scroll Vertical */
        .table-container {
            overflow-x: auto;
            overflow-y: auto;
            max-height: 600px; /* Altura máxima para activar scroll vertical */
            border-radius: 0.75rem;
            border: 1px solid var(--gray-200);
            background: var(--white);
        }
        
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 1000px;
        }
        
        thead {
            background: var(--gray-50);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        th {
            padding: 1rem 1.25rem;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--gray-700);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid var(--gray-200);
        }
        
        td {
            padding: 1rem 1.25rem;
            font-size: 0.875rem;
            border-bottom: 1px solid var(--gray-100);
        }
        
        tbody tr {
            transition: background 0.2s;
        }

        tbody tr:hover {
            background: var(--gray-50);
        }

        tbody tr:last-child td {
            border-bottom: none;
        }
        
        /* Badges Modernos */
        .badge {
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .badge-success {
            background: rgba(34, 197, 94, 0.1);
            color: #15803d;
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: #b45309;
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
        }

        .badge-secondary {
            background: var(--gray-100);
            color: var(--gray-600);
        }
        
        /* Employee Cell */
        .employee-cell {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .employee-name {
            font-weight: 600;
            color: var(--gray-900);
        }

        .employee-code {
            font-size: 0.75rem;
            color: var(--gray-500);
            font-weight: 500;
        }

        /* Time Cell */
        .time-cell {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .time-range {
            font-weight: 500;
            color: var(--gray-700);
            font-size: 0.8125rem;
        }

        .time-duration {
            font-size: 0.75rem;
            color: var(--gray-500);
            font-weight: 600;
        }

        .time-empty {
            color: var(--gray-400);
            font-style: italic;
            font-size: 0.8125rem;
        }

        /* Legend Mejorada */
        .legend-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: var(--gray-50);
            border-radius: 0.5rem;
            border: 1px solid var(--gray-200);
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 0.375rem;
            flex-shrink: 0;
            box-shadow: var(--shadow-sm);
        }

        .legend-text {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray-700);
        }

        /* Info Box */
        .info-box {
            background: linear-gradient(135deg, rgba(5, 150, 105, 0.05), rgba(16, 185, 129, 0.05));
            border: 1px solid rgba(5, 150, 105, 0.2);
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .info-box i {
            color: var(--primary);
            font-size: 1.25rem;
        }

        .info-box-content {
            flex: 1;
        }

        .info-box-title {
            font-weight: 700;
            color: var(--gray-900);
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .info-box-text {
            font-size: 0.8125rem;
            color: var(--gray-600);
            font-weight: 500;
        }

        /* Status Cell */
        .status-cell {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .extra-info {
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .extra-info.danger {
            color: var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-500);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--gray-400);
            margin-bottom: 1rem;
            display: block;
        }

        .empty-state-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
        }

        .empty-state-text {
            font-size: 0.875rem;
        }

        /* Footer */
        .footer {
            background: #059668bd;
            color: rgba(255, 255, 255, 0.9);
            padding: 2rem;
            text-align: center;
            margin-top: auto;
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
        }

        .footer p {
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        .footer small {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.9);
        }

        /* Results Header */
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-100);
        }

        .results-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .results-count {
            background: var(--primary);
            color: var(--white);
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        /* Date Badge */
        .date-badge {
            background: var(--gray-100);
            padding: 0.375rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--gray-700);
        }

        /* Total Time */
        .total-time {
            font-weight: 700;
            color: var(--primary);
            font-size: 0.9375rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }

            .table-container {
                max-height: 500px; /* Ajuste para pantallas medianas */
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .page-header {
                padding: 1.5rem 1rem;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .header-container {
                flex-direction: column;
                gap: 1rem;
            }

            .logo {
                width: 140px;
            }

            .filtros {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .btn-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .legend-grid {
                grid-template-columns: 1fr;
            }

            th, td {
                padding: 0.75rem 0.5rem;
                font-size: 0.8125rem;
            }

            .table-container {
                max-height: 400px; /* Ajuste para móviles */
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .page-header h1 {
                font-size: 1.25rem;
            }

            .stat-value {
                font-size: 1.75rem;
            }
        }

        /* Animaciones */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Loading State */
        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--gray-300);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

    <header class="header">
        <div class="header-container">
            <div class="header-info">
                <h2>📊 Resumen de Producción</h2>
                <h2>⚙️ Dashboard del Administrador</h2>
                <div class="user-name">👋 Bienvenid@, <?= htmlspecialchars($_SESSION['empleado'], ENT_QUOTES, 'UTF-8') ?></div>
                <a href="login_admin.php"><i class="fas fa-sign-out-alt"></i> Cerrar sesión</a>
            </div>
            <img src="/control_produccion/public/logo.png" alt="Logo" class="logo">
        </div>
    </header>

    <div class="page-header">
        <div class="page-header-container">
            <h1><i class="fas fa-chart-line"></i> Control de Pausas - Dashboard Analítico</h1>
            <p>Monitoreo avanzado y análisis en tiempo real de tiempos de pausas laborales</p>
        </div>
    </div>

    <div class="container">
        <!-- Filtros -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-filter"></i> Filtros de Búsqueda</h3>
            </div>
            <form method="GET" id="filtroForm">
                <div class="filtros">
                    <div class="form-group">
                        <label class="form-label" for="fecha_desde">
                            <i class="fas fa-calendar-day"></i> Fecha Desde
                        </label>
                        <input type="date" id="fecha_desde" name="fecha_desde" class="form-control" 
                               value="<?= htmlspecialchars($fecha_desde, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="fecha_hasta">
                            <i class="fas fa-calendar-day"></i> Fecha Hasta
                        </label>
                        <input type="date" id="fecha_hasta" name="fecha_hasta" class="form-control" 
                               value="<?= htmlspecialchars($fecha_hasta, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="empleado">
                            <i class="fas fa-user"></i> Empleado
                        </label>
                        <select id="empleado" name="empleado" class="form-control">
                            <option value="">🔍 Todos los empleados</option>
                            <?php foreach ($empleados as $emp): ?>
                                <option value="<?= htmlspecialchars($emp['codigo_empleado'], ENT_QUOTES, 'UTF-8') ?>" 
                                    <?= $filtro_empleado === $emp['codigo_empleado'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($emp['nombre_empleado'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="estado">
                            <i class="fas fa-tasks"></i> Estado
                        </label>
                        <select id="estado" name="estado" class="form-control">
                            <option value="">📋 Todos los estados</option>
                            <option value="excedido" <?= $filtro_estado === 'excedido' ? 'selected' : '' ?>>⚠️ Pausas Excedidas</option>
                            <option value="incompleto" <?= $filtro_estado === 'incompleto' ? 'selected' : '' ?>>⏸️ Incompletos</option>
                            <option value="ausencia" <?= $filtro_estado === 'ausencia' ? 'selected' : '' ?>>❌ Ausencias</option>
                            <option value="completo" <?= $filtro_estado === 'completo' ? 'selected' : '' ?>>✅ Completos</option>
                        </select>
                    </div>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Buscar
                    </button>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Limpiar Filtros
                    </a>
                    <button type="submit" name="exportar" value="1" class="btn btn-success">
                        <i class="fas fa-file-excel"></i> Exportar a Excel
                    </button>
                </div>
            </form>
        </div>

        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-value"><?= number_format($estadisticas['total_registros'], 0, ',', '.') ?></div>
                <div class="stat-label">Total Registros</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon danger">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-value" style="color: var(--danger);"><?= number_format($estadisticas['excedidos'], 0, ',', '.') ?></div>
                <div class="stat-label">Con Pausas Excedidas</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon danger">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value" style="color: var(--danger);"><?= number_format($estadisticas['pausas_excedidas'], 0, ',', '.') ?></div>
                <div class="stat-label">Pausas Excedidas</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-value" style="color: var(--warning);"><?= number_format($estadisticas['ausencias'], 0, ',', '.') ?></div>
                <div class="stat-label">Ausencias</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value" style="color: var(--success);"><?= number_format($estadisticas['completos'], 0, ',', '.') ?></div>
                <div class="stat-label">Completos</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-info-circle"></i>
                </div>
                <div class="stat-value" style="color: var(--info);"><?= number_format($estadisticas['incompletos'], 0, ',', '.') ?></div>
                <div class="stat-label">Incompletos</div>
            </div>
        </div>

        <!-- Leyenda -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-book-open"></i> Leyenda de Estados</h3>
            </div>
            <div class="legend-grid">
                <div class="legend-item">
                    <div class="legend-color" style="background: rgba(34, 197, 94, 0.2);"></div>
                    <span class="legend-text">✅ A tiempo</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: rgba(239, 68, 68, 0.2);"></div>
                    <span class="legend-text">⚠️ Excedido</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: rgba(245, 158, 11, 0.2);"></div>
                    <span class="legend-text">⏸️ Incompleto</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: rgba(156, 163, 175, 0.2);"></div>
                    <span class="legend-text">➖ No tomada</span>
                </div>
            </div>
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <div class="info-box-content">
                    <div class="info-box-title">Tiempos Permitidos</div>
                    <div class="info-box-text">
                        Café 1: 15 min | Comida: 30 min | Café 2: 15 min | Tolerancia: <?= TOLERANCIA_MINUTOS ?> minuto
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla de Resultados -->
        <div class="card">
            <div class="results-header">
                <div class="results-title">
                    <i class="fas fa-table"></i> Registros de Pausas
                </div>
                <span class="results-count">
                    <?= number_format(count($registros), 0, ',', '.') ?> resultados
                </span>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th><i class="fas fa-user"></i> Empleado</th>
                            <th><i class="fas fa-calendar"></i> Fecha</th>
                            <th><i class="fas fa-coffee"></i> Café 1</th>
                            <th>Estado</th>
                            <th><i class="fas fa-utensils"></i> Comida</th>
                            <th>Estado</th>
                            <th><i class="fas fa-coffee"></i> Café 2</th>
                            <th>Estado</th>
                            <th><i class="fas fa-clock"></i> Total</th>
                            <th><i class="fas fa-chart-pie"></i> General</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($registros)): ?>
                            <tr>
                                <td colspan="10">
                                    <div class="empty-state">
                                        <i class="fas fa-search"></i>
                                        <div class="empty-state-title">No se encontraron registros</div>
                                        <div class="empty-state-text">Intenta ajustar los filtros de búsqueda</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($registros as $reg): ?>
                                <tr>
                                    <td>
                                        <div class="employee-cell">
                                            <span class="employee-name"><?= htmlspecialchars($reg['nombre_empleado'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="employee-code"><?= htmlspecialchars($reg['codigo_empleado'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="date-badge"><?= date('d/m/Y', strtotime($reg['fecha'])) ?></span>
                                    </td>
                                    
                                    <!-- Café 1 -->
                                    <td>
                                        <?php if ($reg['cafe1_salida'] && $reg['cafe1_entrada']): ?>
                                            <div class="time-cell">
                                                <span class="time-range"><?= htmlspecialchars($reg['cafe1_salida'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($reg['cafe1_entrada'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="time-duration"><i class="fas fa-hourglass-half"></i> <?= $reg['tiempos_pausas']['cafe1'] ?> min</span>
                                            </div>
                                        <?php elseif ($reg['cafe1_salida'] || $reg['cafe1_entrada']): ?>
                                            <div class="time-cell">
                                                <span class="time-range" style="opacity: 0.6;">
                                                    <?= $reg['cafe1_salida'] ? htmlspecialchars($reg['cafe1_salida'], ENT_QUOTES, 'UTF-8') : '--:--' ?> - 
                                                    <?= $reg['cafe1_entrada'] ? htmlspecialchars($reg['cafe1_entrada'], ENT_QUOTES, 'UTF-8') : '--:--' ?>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span class="time-empty">Sin registro</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($reg['estados_pausas']['cafe1'] === 'a_tiempo'): ?>
                                            <span class="badge badge-success"><i class="fas fa-check"></i> A tiempo</span>
                                        <?php elseif ($reg['estados_pausas']['cafe1'] === 'excedido'): ?>
                                            <span class="badge badge-danger">
                                                <i class="fas fa-exclamation-triangle"></i> +<?= $reg['tiempos_extra']['cafe1'] ?> min
                                            </span>
                                        <?php elseif ($reg['estados_pausas']['cafe1'] === 'incompleto'): ?>
                                            <span class="badge badge-warning"><i class="fas fa-clock"></i> Incompleto</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary"><i class="fas fa-minus"></i> No tomado</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Comida -->
                                    <td>
                                        <?php if ($reg['comida_salida'] && $reg['comida_entrada']): ?>
                                            <div class="time-cell">
                                                <span class="time-range"><?= htmlspecialchars($reg['comida_salida'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($reg['comida_entrada'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="time-duration"><i class="fas fa-hourglass-half"></i> <?= $reg['tiempos_pausas']['comida'] ?> min</span>
                                            </div>
                                        <?php elseif ($reg['comida_salida'] || $reg['comida_entrada']): ?>
                                            <div class="time-cell">
                                                <span class="time-range" style="opacity: 0.6;">
                                                    <?= $reg['comida_salida'] ? htmlspecialchars($reg['comida_salida'], ENT_QUOTES, 'UTF-8') : '--:--' ?> - 
                                                    <?= $reg['comida_entrada'] ? htmlspecialchars($reg['comida_entrada'], ENT_QUOTES, 'UTF-8') : '--:--' ?>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span class="time-empty">Sin registro</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($reg['estados_pausas']['comida'] === 'a_tiempo'): ?>
                                            <span class="badge badge-success"><i class="fas fa-check"></i> A tiempo</span>
                                        <?php elseif ($reg['estados_pausas']['comida'] === 'excedido'): ?>
                                            <span class="badge badge-danger">
                                                <i class="fas fa-exclamation-triangle"></i> +<?= $reg['tiempos_extra']['comida'] ?> min
                                            </span>
                                        <?php elseif ($reg['estados_pausas']['comida'] === 'incompleto'): ?>
                                            <span class="badge badge-warning"><i class="fas fa-clock"></i> Incompleto</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary"><i class="fas fa-minus"></i> No tomado</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Café 2 -->
                                    <td>
                                        <?php if ($reg['cafe2_salida'] && $reg['cafe2_entrada']): ?>
                                            <div class="time-cell">
                                                <span class="time-range"><?= htmlspecialchars($reg['cafe2_salida'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($reg['cafe2_entrada'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="time-duration"><i class="fas fa-hourglass-half"></i> <?= $reg['tiempos_pausas']['cafe2'] ?> min</span>
                                            </div>
                                        <?php elseif ($reg['cafe2_salida'] || $reg['cafe2_entrada']): ?>
                                            <div class="time-cell">
                                                <span class="time-range" style="opacity: 0.6;">
                                                    <?= $reg['cafe2_salida'] ? htmlspecialchars($reg['cafe2_salida'], ENT_QUOTES, 'UTF-8') : '--:--' ?> - 
                                                    <?= $reg['cafe2_entrada'] ? htmlspecialchars($reg['cafe2_entrada'], ENT_QUOTES, 'UTF-8') : '--:--' ?>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span class="time-empty">Sin registro</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($reg['estados_pausas']['cafe2'] === 'a_tiempo'): ?>
                                            <span class="badge badge-success"><i class="fas fa-check"></i> A tiempo</span>
                                        <?php elseif ($reg['estados_pausas']['cafe2'] === 'excedido'): ?>
                                            <span class="badge badge-danger">
                                                <i class="fas fa-exclamation-triangle"></i> +<?= $reg['tiempos_extra']['cafe2'] ?> min
                                            </span>
                                        <?php elseif ($reg['estados_pausas']['cafe2'] === 'incompleto'): ?>
                                            <span class="badge badge-warning"><i class="fas fa-clock"></i> Incompleto</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary"><i class="fas fa-minus"></i> No tomado</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <span class="total-time"><?= $reg['tiempo_total_pausas'] ?> min</span>
                                    </td>
                                    
                                    <td>
                                        <div class="status-cell">
                                            <?php if ($reg['es_ausencia']): ?>
                                                <span class="badge badge-warning"><i class="fas fa-user-times"></i> Ausencia</span>
                                            <?php elseif ($reg['es_completo']): ?>
                                                <span class="badge badge-success"><i class="fas fa-check-circle"></i> Completo</span>
                                            <?php elseif ($reg['es_incompleto']): ?>
                                                <span class="badge badge-info"><i class="fas fa-info-circle"></i> Incompleto</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($reg['total_pausas_excedidas'] > 0): ?>
                                                <div class="extra-info danger">
                                                    <i class="fas fa-exclamation-circle"></i>
                                                    <?= $reg['total_pausas_excedidas'] ?> pausa(s) excedida(s)
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <footer class="footer">
        <div class="footer-content">
            <p><strong>Sistema de Control de Pausas</strong> © <?= date('Y') ?></p>
            <small>Desarrollado por Nestor Rosales | Rosalesdev91</small>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const fechaDesde = document.getElementById('fecha_desde');
            const fechaHasta = document.getElementById('fecha_hasta');
            
            if (!fechaDesde.value) fechaDesde.value = '<?= date('Y-m-d') ?>';
            if (!fechaHasta.value) fechaHasta.value = '<?= date('Y-m-d') ?>';

            // Validar fechas
            const form = document.getElementById('filtroForm');
            form.addEventListener('submit', function(e) {
                if (fechaDesde.value > fechaHasta.value) {
                    e.preventDefault();
                    alert('⚠️ La fecha "Desde" no puede ser mayor que la fecha "Hasta".');
                    fechaDesde.focus();
                }
            });

            // Animación de entrada para estadísticas
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>

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

<?php $conn->close(); ?>