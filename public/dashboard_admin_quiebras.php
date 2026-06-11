<?php
session_start();
require_once '../config/database.php';
require_once 'registrar_actividad.php';

if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login_admin.php");
    exit();
}

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

function obtenerOpciones($conn, $columna) {
    $sql = "SELECT DISTINCT $columna FROM registro_quiebras WHERE $columna IS NOT NULL AND $columna != ''";
    $result = $conn->query($sql);
    $opciones = [];

    while ($row = $result->fetch_assoc()) {
        $opciones[] = $row[$columna];
    }

    return $opciones;
}

function obtenerQuiebrasFiltradas($conn, $filtros) {
    // Verificar si hay algún filtro activo relevante
    $filtrosActivos = false;
    foreach (['turno', 'responsable', 'empleado', 'equipo', 'motivo', 'fecha_inicio', 'fecha_fin', 'id', 'orden'] as $campo) {
        if (!empty($filtros[$campo]) && $filtros[$campo] != 'todos') {
            $filtrosActivos = true;
            break;
        }
    }
    if (!$filtrosActivos) {
        // Si no hay filtros, no mostrar nada (retorna arreglo vacío)
        return [];
    }

    $sql = "SELECT * FROM registro_quiebras WHERE 1=1";
    $params = [];
    $types = "";

    // Campos con filtro exacto
    foreach (['turno', 'responsable', 'empleado', 'equipo', 'motivo'] as $campo) {
        if (!empty($filtros[$campo]) && $filtros[$campo] != 'todos') {
            $sql .= " AND $campo = ?";
            $params[] = $filtros[$campo];
            $types .= "s";
        }
    }

    // Función para convertir hora + AM/PM a formato 24h con segundos
    function convertirHoraConSegundos($hora, $ampm) {
        if (!$hora) return null;
        $hora24 = date("H:i:s", strtotime("$hora $ampm"));
        return $hora24;
    }

    // Función para mostrar fecha y hora en formato dd/mm/YYYY hh:mm AM/PM
    function formatearFechaHoraConAmPm($fecha, $hora, $ampm) {
        if (!$fecha) return '';
        if (!$hora) return date('d/m/Y', strtotime($fecha));

        $fechaHoraStr = "$fecha $hora $ampm";
        $timestamp = strtotime($fechaHoraStr);
        if (!$timestamp) return '';

        return date('d/m/Y h:i A', $timestamp);
    }

    // Construcción de filtros para consulta SQL
    if (!empty($filtros['fecha_inicio'])) {
        $hora_inicio = !empty($filtros['hora_inicio']) && !empty($filtros['ampm_inicio'])
            ? convertirHoraConSegundos($filtros['hora_inicio'], $filtros['ampm_inicio'])
            : '00:00:00';

        $fechaHoraInicio = $filtros['fecha_inicio'] . ' ' . $hora_inicio;
        $sql .= " AND CONCAT(fecha, ' ', hora) >= ?";
        $params[] = $fechaHoraInicio;
        $types .= "s";
    }

    if (!empty($filtros['fecha_fin'])) {
        $hora_fin = !empty($filtros['hora_fin']) && !empty($filtros['ampm_fin'])
            ? convertirHoraConSegundos($filtros['hora_fin'], $filtros['ampm_fin'])
            : '23:59:59';

        $fechaHoraFin = $filtros['fecha_fin'] . ' ' . $hora_fin;
        $sql .= " AND CONCAT(fecha, ' ', hora) <= ?";
        $params[] = $fechaHoraFin;
        $types .= "s";
    }

    // Filtro número de id con LIKE para búsqueda parcial
    if (!empty($filtros['id'])) {
        $sql .= " AND id LIKE ?";
        $params[] = "%" . $filtros['id'] . "%";
        $types .= "s";
    }

    // Filtro número de orden con LIKE para búsqueda parcial
    if (!empty($filtros['orden'])) {
        $sql .= " AND orden LIKE ?";
        $params[] = "%" . $filtros['orden'] . "%";
        $types .= "s";
    }

    $stmt = $conn->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $quiebras = [];

    while ($row = $result->fetch_assoc()) {
        $quiebras[] = $row;
    }

    return $quiebras;
}

// Ejemplo de obtención de filtros desde GET
$filtros = [
    'turno' => $_GET['turno'] ?? '',
    'responsable' => $_GET['responsable'] ?? '',
    'empleado' => $_GET['empleado'] ?? '',
    'equipo' => $_GET['equipo'] ?? '',
    'motivo' => $_GET['motivo'] ?? '',
    'fecha_inicio' => $_GET['fecha_inicio'] ?? '',
    'hora_inicio' => $_GET['hora_inicio'] ?? '',
    'ampm_inicio' => $_GET['ampm_inicio'] ?? '',
    'fecha_fin' => $_GET['fecha_fin'] ?? '',
    'hora_fin' => $_GET['hora_fin'] ?? '',
    'ampm_fin' => $_GET['ampm_fin'] ?? '',
    'id' => $_GET['id'] ?? '',
    'orden' => $_GET['orden'] ?? '',
    'agrupar_por' => $_GET['agrupar_por'] ?? '',
];

$agrupar_por = $_GET['agrupar_por'] ?? null;
$id_buscar = isset($_GET['id_id']) ? trim($_GET['id_id']) : '';
$orden_buscar = isset($_GET['id_orden']) ? trim($_GET['id_orden']) : '';




// Obtener datos de quiebras según los filtros, incluyendo orden
$data = obtenerQuiebrasFiltradas($conn, $filtros);

$campos_para_graficos = ['turno', 'responsable', 'empleado', 'equipo', 'motivo'];
foreach ($campos_para_graficos as $campo) {
    ${"labels_$campo"} = [];
    ${"counts_$campo"} = [];

    $conteo = [];
    foreach ($data as $row) {
        $valor = $row[$campo] ?? 'No especificado';
        $conteo[$valor] = ($conteo[$valor] ?? 0) + 1;
    }

    foreach ($conteo as $label => $count) {
        ${"labels_$campo"}[] = $label;
        ${"counts_$campo"}[] = $count;
    }

    // Convertir a JSON si lo vas a usar en JavaScript
    ${"labels_{$campo}_json"} = json_encode(${"labels_$campo"});
    ${"counts_{$campo}_json"} = json_encode(${"counts_$campo"});
}


$agrupados = [];
if ($agrupar_por && $agrupar_por != 'todo') {
    foreach ($data as $row) {
        if (!empty($row[$agrupar_por])) {
            $clave = $row[$agrupar_por];
            $agrupados[$clave][] = $row;
        }
        // Si el valor está vacío o no existe, se omite
    }
} else {
    $agrupados['Todos'] = $data;
}


$totalRegistros = count($data);


// Obtener opciones para filtros
$turnos = obtenerOpciones($conn, 'turno');
$responsables = obtenerOpciones($conn, 'responsable');
$empleados = obtenerOpciones($conn, 'empleado');
$equipos = obtenerOpciones($conn, 'equipo');
$motivos = obtenerOpciones($conn, 'motivo');

// Preparar datos para el gráfico (ejemplo: contar registros por grupo seleccionado)
$labels = [];
$counts = [];

if ($agrupar_por && $agrupar_por != 'todo') {
    foreach ($agrupados as $clave => $items) {
        $labels[] = $clave;
        $counts[] = count($items);
    }
} else {
    $labels[] = 'Todos';
    $counts[] = $totalRegistros;
}

// Convertir a JSON para usar en JS
$labels_json = json_encode($labels);
$counts_json = json_encode($counts);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['eliminar_quiebra_id'])) {
    $idAEliminar = filter_var($_POST['eliminar_quiebra_id'], FILTER_SANITIZE_STRING);

    if ($stmtEliminar = $conn->prepare("DELETE FROM registro_quiebras WHERE id = ?")) {
        $stmtEliminar->bind_param("s", $idAEliminar);

        if ($stmtEliminar->execute()) {
            $stmtEliminar->close();

            // Si es una petición AJAX
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => true]);
                exit;
            }

            // Redirección normal si no es AJAX (sin mantener filtros)
            header("Location: " . $_SERVER['PHP_SELF'] . "?mensaje=eliminado");
            exit;
        } else {
            $stmtEliminar->close();
            $errorMsg = "Error al eliminar el registro con id " . htmlspecialchars($idAEliminar);

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => false, 'error' => $errorMsg]);
                exit;
            } else {
                echo "<p style='color: red;'>$errorMsg</p>";
            }
        }
    } else {
        $errorMsg = "Error al preparar la consulta para eliminar.";

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => false, 'error' => $errorMsg]);
            exit;
        } else {
            echo "<p style='color: red;'>$errorMsg</p>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Registro de Quiebras</title>
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
    max-width: 1200px;
    width: 95%;
    margin: 0 auto;
    padding: 20px;
    box-sizing: border-box;
}

form, table {
    background: rgba(0, 0, 0, 0.22);
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.3);
    margin-top: 20px;
}

input, select {
    padding: 12px;
    width: 100%;
    margin: 12px 0;
    border-radius: 5px;
    border: 1px solid #c3e6cb;
    background-color: #d4edda;
    color: #155724;
    font-weight: bold;
    font-size: 15px;
}

select:focus, input:focus {
    outline: none;
    border-color: #28a745;
    box-shadow: 0 0 5px #28a745;
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    color: #155724;
    background-color: #f9f9f9;
    table-layout: auto;
    font-size: 15px;
}

th, td {
    padding: 12px;
    border: 1px solid #333;
    text-align: center;
}

th {
    background: #218838;
    color: white;
    font-size: 16px;
}

tr:hover {
    background-color: #d4edda;
    color: #000;
}

button {
    padding: 12px 20px;
    background: #006400;
    color: white;
    border: none;
    border-radius: 5px;
    margin-top: 12px;
    cursor: pointer;
    font-size: 15px;
}

button:hover {
    background: #004d00;
}

.logo {
    position: absolute;
    top: 20px;
    right: 20px;
    width: 300px;
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

.filtros-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.filtros-header h2 {
    margin: 0;
    font-size: 20px;
}

.logout-link {
    font-size: 14px;
}

a {
    color: #c3e6cb;
    text-decoration: none;
}

a:hover {
    text-decoration: underline;
}

.tab-container {
    margin-bottom: 15px;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0,0,0,0.3);
}

.tab-button {
    width: 100%;
    background-color: #218838;
    color: white;
    padding: 15px;
    font-size: 18px;
    border: none;
    text-align: left;
    cursor: pointer;
    outline: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 10px;
    user-select: none;
}

.tab-button:hover {
    background-color: #1b6e2e;
}

.arrow {
    transition: transform 0.3s ease;
}

.tab-button.active .arrow {
    transform: rotate(180deg);
}

.tab-content {
    background: #f9f9f9;
    color: #155724;
    padding: 15px 20px;
    border-top: 2px solid #218838;
    border-radius: 0 0 10px 10px;
    display: none; /* oculto por defecto */
}

</style>

<body>

<img src="/logo.svg" class="logo" alt="Logo">

<div class="container">

    <style>
        .filtro-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
        }
        .filtro-item {
            display: flex;
            flex-direction: column;
            min-width: 150px;
        }
        .filtro-item label {
            font-size: 0.85rem;
            margin-bottom: 4px;
            font-weight: bold;
        }
        .filtro-item input,
        .filtro-item select {
            padding: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
            font-size: 0.9rem;
        }
        button[type="submit"] {
            background-color: #007bff;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
        }

        .btn-eliminar {
    background-color: #dc3545;
    color: white;
    border: none;
    padding: 5px 10px;
    border-radius: 4px;
    cursor: pointer;
}
.btn-eliminar:hover {
    background-color: #c82333;
}

    </style>

    <div class="filtros-header">
        <h2>Bienvenid@, <?= htmlspecialchars($_SESSION['empleado'] ?? 'Usuario'); ?></h2>
    </div>
    <div class="logout-link"><a href="login_admin.php">Cerrar sesión</a></div>

    <h3 class="text-center my-4">Dashboard - Registro de Quiebras</h3>

<?php
// Suponiendo que estas variables se asignan así (con control para evitar errores):
$agrupar_por = $_GET['agrupar_por'] ?? '';
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';
$hora_inicio = $_GET['hora_inicio'] ?? '';
$ampm_inicio = $_GET['ampm_inicio'] ?? 'AM';
$hora_fin = $_GET['hora_fin'] ?? '';
$ampm_fin = $_GET['ampm_fin'] ?? 'AM';
$id_buscar = $_GET['id'] ?? '';
$orden_buscar = $_GET['orden'] ?? '';

?>

<form method="GET">
    <fieldset>
        <div class="filtro-item">
            <label for="agrupar_por">Agrupar por</label>
            <select name="agrupar_por" id="agrupar_por">
                <option value="" disabled <?= empty($agrupar_por) || $agrupar_por == 'todo' ? 'selected' : '' ?>>Selecciona una opción</option>
                <option value="todo" <?= $agrupar_por == 'todo' ? 'selected' : '' ?>>Mostrar todo</option>
                <option value="turno" <?= $agrupar_por == 'turno' ? 'selected' : '' ?>>Turno</option>
                <option value="responsable" <?= $agrupar_por == 'responsable' ? 'selected' : '' ?>>Responsable</option>
                <option value="empleado" <?= $agrupar_por == 'empleado' ? 'selected' : '' ?>>Empleado</option>
                <option value="equipo" <?= $agrupar_por == 'equipo' ? 'selected' : '' ?>>Equipo</option>
                <option value="motivo" <?= $agrupar_por == 'motivo' ? 'selected' : '' ?>>Motivo</option>
            </select>
        </div>

        <div class="filtro-row">
            <div class="filtro-item">
                <label for="fecha_inicio">Fecha Inicio</label>
                <input type="date" name="fecha_inicio" id="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>">
            </div>
            <div class="filtro-item">
                <label for="fecha_fin">Fecha Fin</label>
                <input type="date" name="fecha_fin" id="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>">
            </div>
            <div class="filtro-item">
                <label for="hora_inicio">Hora Inicio (12h)</label>
                <input type="text" id="hora_inicio" name="hora_inicio" placeholder="hh:mm" pattern="^(0[1-9]|1[0-2]):[0-5][0-9]$" value="<?= htmlspecialchars($hora_inicio) ?>">
            </div>
            <div class="filtro-item">
                <label for="ampm_inicio">AM/PM</label>
                <select id="ampm_inicio" name="ampm_inicio">
                    <option value="AM" <?= $ampm_inicio == 'AM' ? 'selected' : '' ?>>AM</option>
                    <option value="PM" <?= $ampm_inicio == 'PM' ? 'selected' : '' ?>>PM</option>
                </select>
            </div>
            <div class="filtro-item">
                <label for="hora_fin">Hora Fin (12h)</label>
                <input type="text" id="hora_fin" name="hora_fin" placeholder="hh:mm" pattern="^(0[1-9]|1[0-2]):[0-5][0-9]$" value="<?= htmlspecialchars($hora_fin) ?>">
            </div>
            <div class="filtro-item">
                <label for="ampm_fin">AM/PM</label>
                <select id="ampm_fin" name="ampm_fin">
                    <option value="AM" <?= $ampm_fin == 'AM' ? 'selected' : '' ?>>AM</option>
                    <option value="PM" <?= $ampm_fin == 'PM' ? 'selected' : '' ?>>PM</option>
                </select>
            </div>

            <div class="filtro-item" style="flex-grow:1">
                <label for="id">Buscar por Número ID:</label>
                <input type="text" name="id" id="id" value="<?= htmlspecialchars($id_buscar) ?>">
            </div>
            <div class="filtro-item" style="flex-grow:1">
                <label for="orden">Buscar por Número de orden:</label>
                <input type="text" name="orden" id="orden" value="<?= htmlspecialchars($orden_buscar) ?>">
            </div>
        </div>
        <button type="submit">Filtrar</button>
    </fieldset>
</form>

<?php
function renderChartData($nombre, $labels, $counts) {
    return [
        'nombre' => $nombre,
        'labels' => $labels,
        'counts' => $counts
    ];
}

// Asegura que existan las variables necesarias
$agrupar_por = $agrupar_por ?? '';
$graficos = [];

if ($agrupar_por === 'todo' || empty($agrupar_por)) {
    $graficos[] = renderChartData('Turno', $labels_turno ?? [], $counts_turno ?? []);
    $graficos[] = renderChartData('Responsable', $labels_responsable ?? [], $counts_responsable ?? []);
    $graficos[] = renderChartData('Empleado', $labels_empleado ?? [], $counts_empleado ?? []);
    $graficos[] = renderChartData('Equipo', $labels_equipo ?? [], $counts_equipo ?? []);
    $graficos[] = renderChartData('Motivo', $labels_motivo ?? [], $counts_motivo ?? []);
} else {
    $graficos[] = renderChartData(ucfirst($agrupar_por), $labels ?? [], $counts ?? []);
}

$claseContenedor = ($agrupar_por === 'todo' || empty($agrupar_por)) ? 'todos' : 'individual';
$ampm_inicio = $ampm_inicio ?? null;
$ampm_fin = $ampm_fin ?? null;

function combinar_fecha_hora($fecha, $hora, $ampm) {
    if (!$fecha) return null;
    if (!$hora) return $fecha;
    if (!$ampm) return "$fecha $hora";
    $hora24 = date("H:i", strtotime("$hora $ampm"));
    return "$fecha $hora24";
}

function formatear_fecha($fecha) {
    if (!$fecha) return '';
    $formato = (strlen($fecha) > 10) ? 'd/m/Y h:i A' : 'd/m/Y';
    return date($formato, strtotime($fecha));
}

$fechaHoraInicio = combinar_fecha_hora($fecha_inicio ?? null, $hora_inicio ?? null, $ampm_inicio);
$fechaHoraFin = combinar_fecha_hora($fecha_fin ?? null, $hora_fin ?? null, $ampm_fin);

$nombresAgrupaciones = [
    'empleado' => 'Empleado',
    'equipo' => 'Equipo',
    'motivo' => 'Motivo',
    'porque_defecto' => 'Defecto',
    'turno' => 'Turno',
    'responsable' => 'Responsable',
    'lado_lente' => 'Lado del Lente',
    'todo' => 'General'
];
$tipo = $nombresAgrupaciones[$agrupar_por] ?? ucfirst($agrupar_por);

$totalRegistros = 0;
if (!empty($agrupados)) {
    foreach ($agrupados as $grupo => $filas) {
        $totalRegistros += count($filas);
    }
}

// Total de gráficos para identificar última fila
$total = count($graficos);
?>

<!-- =================== ESTILOS =================== -->
<style>
/* Contenedor para cada grupo de quiebras */
.tab-container {
    margin-bottom: 20px;
    border: 1px solid #ccc;
    border-radius: 10px;
    background: #f2f2f2;
    overflow: hidden;
}

/* Botón de cabecera desplegable */
.tab-button {
    width: 100%;
    padding: 12px 16px;
    background: #1e7e34;
    color: #fff;
    border: none;
    border-radius: 10px 10px 0 0;
    font-size: 16px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: bold;
    transition: background 0.3s;
}

.tab-button:hover {
    background: #155724;
}

.arrow {
    font-size: 18px;
    transition: transform 0.3s;
}

/* Contenido oculto por defecto */
.tab-content {
    display: none;
    padding: 16px;
    background: white;
}

/* Mostrar contenido al activar */
.tab-container.active .tab-content {
    display: block;
}

.tab-container.active .arrow {
    transform: rotate(180deg);
}

/* Tabla scrollable */
.tabla-scroll {
    max-height: 600px;
    overflow-y: auto;
    border: 1px solid #ccc;
    border-radius: 8px;
}

/* Tabla general */
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

thead tr {
    background: #1e7e34;
    color: white;
    position: sticky;
    top: 0;
    z-index: 5;
}

th, td {
    padding: 10px;
    border: 1px solid #ddd;
    text-align: left;
}

/* Botón eliminar */
.btn-eliminar {
    background-color: #dc3545;
    color: #fff;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
}

.btn-eliminar:hover {
    background-color: #b02a37;
}

/* Estilo contenedor de gráficos */
#contenedorGraficos {
    width: 100%;
    margin: 0 auto;
    padding: 20px 0;
}

/* Distribución grid para "todos" */
#contenedorGraficos.todos {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 20px;
    justify-items: center;
    max-width: 1200px;
}

/* Distribución vertical para "individual" */
#contenedorGraficos.individual {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
}

/* Cada tarjeta de gráfico */
.grafico-individual {
    background: #e6f2e6;
    padding: 20px;
    border-radius: 10px;
    border: 1px solid #bbb;
    width: 100%;
    max-width: 600px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    text-align: center;
}

/* Selector de tipo de gráfico */
.chart-type-selector {
    margin-bottom: 12px;
    padding: 8px;
    font-size: 14px;
    border-radius: 6px;
}

/* Resumen de datos */
.resumen-chart {
    margin-bottom: 10px;
    font-size: 15px;
    color: #0b421e;
    background: rgba(33, 136, 56, 0.1);
    padding: 10px;
    border-radius: 10px;
    font-weight: bold;
}

.legend-container {
    max-height: 150px;
    overflow-y: auto;
    text-align: left;
    margin-bottom: 12px;
    padding-right: 10px;
}
.legend-item {
    display: flex;
    align-items: center;
    margin-bottom: 6px;
    font-size: 14px;
    color: #155724;
}
.legend-color-box {
    width: 18px;
    height: 12px;
    margin-right: 8px;
    border-radius: 3px;
    border: 1px solid #aaa;
}
</style>

<h4>Total de Quiebras: <?= (int)$totalRegistros ?> registros</h4>

<?php if (!empty($agrupados)): ?>
    <h4>Total de Quiebras por <?= htmlspecialchars($tipo) ?>: <?= $totalRegistros ?> registros</h4>

    <?php foreach ($agrupados as $grupo => $filas): ?>
        <div class="tab-container">
            <button class="tab-button" type="button" onclick="this.parentElement.classList.toggle('active')">
                <?= htmlspecialchars($agrupar_por == 'todo' ? 'Todos' : $tipo) ?>: <?= htmlspecialchars($grupo) ?> (<?= count($filas) ?>)
                <span class="arrow">▼</span>
            </button>
            <div class="tab-content">
                <p><strong>Total Quiebras: <?= count($filas) ?> registros</strong></p>
                <div class="tabla-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Registro</th>
                                <th>N° Orden</th>
                                <th>Turno</th>
                                <th>Responsable</th>
                                <th>Empleado</th>
                                <th>Equipo</th>
                                <th>Motivo</th>
                                <th>Defecto</th>
                                <th>Lado Lente</th>
                                <th>Fecha y Hora</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filas as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['id']) ?></td>
                                    <td><?= htmlspecialchars($row['empleado_registro']) ?></td>
                                    <td><?= htmlspecialchars($row['orden']) ?></td>
                                    <td><?= htmlspecialchars($row['turno']) ?></td>
                                    <td><?= htmlspecialchars($row['responsable']) ?></td>
                                    <td><?= htmlspecialchars($row['empleado']) ?></td>
                                    <td><?= htmlspecialchars($row['equipo']) ?></td>
                                    <td><?= htmlspecialchars($row['motivo']) ?></td>
                                    <td><?= htmlspecialchars($row['porque_defecto']) ?></td>
                                    <td><?= htmlspecialchars($row['lado_lente']) ?></td>
                                    <td>
                                        <?php 
                                            $fechaCompleta = $row['fecha'] . ' ' . ($row['hora'] ?? '00:00:00');
                                            echo date('d-m-Y h:i:s A', strtotime($fechaCompleta));
                                        ?>
                                    </td>
                                    <td>
                                        <form method="POST" data-orden="<?= htmlspecialchars($row['id']) ?>">
                                            <input type="hidden" name="eliminar_quiebra_id" value="<?= htmlspecialchars($row['id']) ?>">
                                            <button type="submit" class="btn-eliminar">🗑️ Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <p style="color: #888;">No hay resultados para mostrar.</p>
<?php endif; ?>

<!-- Título y rango de fechas -->
<div style="text-align: center; margin-bottom: 20px;">
    <h3 style="margin-bottom: 10px; color: #ffffff;">
        Gráfico(s) de quiebras agrupadas por <?= htmlspecialchars($agrupar_por ?: 'todos') ?>
    </h3>
    <?php if (!empty($fecha_inicio) && !empty($fecha_fin)): ?>
        <p style="font-style: italic; color: rgb(248, 248, 248); margin: 0;">
            Fecha de los datos mostrados: <?= htmlspecialchars(formatear_fecha($fechaHoraInicio)) ?> al <?= htmlspecialchars(formatear_fecha($fechaHoraFin)) ?>
        </p>
    <?php endif; ?>
</div>

<!-- Contenedor de gráficos -->
<div id="contenedorGraficos" class="<?= htmlspecialchars($claseContenedor) ?>">
<?php
$totalGraficos = count($graficos);
foreach ($graficos as $i => $grafico):
    $esUltimaFila = ($i >= ($totalGraficos - ($totalGraficos % 3 === 0 ? 3 : $totalGraficos % 3)));
    $claseBorde = $esUltimaFila ? 'no-border-bottom' : '';
    $datosFiltrados = [];
    $totalFiltrado = 0;

    foreach ($grafico['labels'] as $idx => $label) {
        if (strtolower(trim($label)) !== 'no especificado') {
            $datosFiltrados[] = [
                'label' => $label,
                'count' => $grafico['counts'][$idx]
            ];
            $totalFiltrado += $grafico['counts'][$idx];
        }
    }

    usort($datosFiltrados, fn($a, $b) => $b['count'] <=> $a['count']);
    $labelsOrdenados = array_column($datosFiltrados, 'label');
    $countsOrdenados = array_column($datosFiltrados, 'count');
?>
<div class="grafico-individual <?= $claseBorde ?>">
    <h4 style="text-align: center; color: black;">
        <?= htmlspecialchars($grafico['nombre']) ?>
    </h4>
    <select class="chart-type-selector" onchange="updateChartType(<?= $i ?>, this.value)">
        <option value="bar">Barra</option>
        <option value="pie">Torta</option>
        <option value="doughnut">Rosquilla</option>
    </select>
    <div id="resumen<?= $i ?>" class="resumen-chart"></div>
    <div id="legend<?= $i ?>" class="legend-container"></div>
    <canvas id="chart<?= $i ?>" style="max-width: 100%; height: 400px;"></canvas>
    <script>
    (function () {
        const labels = <?= json_encode($labelsOrdenados, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const counts = <?= json_encode($countsOrdenados, JSON_NUMERIC_CHECK) ?>;
        const ctx = document.getElementById('chart<?= $i ?>').getContext('2d');
        let chartInstance;

        const total = counts.reduce((a, b) => a + b, 0);
        let resumenHTML = `Total: ${total} registros (100%)<br>`;
        labels.forEach((label, idx) => {
            const porcentaje = total > 0 ? ((counts[idx] / total) * 100).toFixed(2) : 0;
            resumenHTML += `${label}: ${counts[idx]} (${porcentaje}%)<br>`;
        });
        document.getElementById('resumen<?= $i ?>').innerHTML = resumenHTML;

        function generarColoresUnicosHSL(cantidad, saturacion = 70, luminosidad = 60, opacidad = 0.7) {
            const colores = [];
            for (let i = 0; i < cantidad; i++) {
                const hue = Math.floor((360 / cantidad) * i);
                colores.push(`hsla(${hue}, ${saturacion}%, ${luminosidad}%, ${opacidad})`);
            }
            return colores;
        }

        const colors = generarColoresUnicosHSL(labels.length);

        const legendHTML = labels.map((label, idx) => {
            const porcentaje = total > 0 ? ((counts[idx] / total) * 100).toFixed(2) : 0;
            return `<div class="legend-item">
                <div class="legend-color-box" style="background-color: ${colors[idx]};"></div>
                ${label} - ${counts[idx]} (${porcentaje}%)
            </div>`;
        }).join('');
        document.getElementById('legend<?= $i ?>').innerHTML = legendHTML;

        function createChart(type) {
            if (chartInstance) chartInstance.destroy();
            chartInstance = new Chart(ctx, {
                type: type,
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Cantidad de registros',
                        data: counts,
                       backgroundColor: colors,
                       borderColor: colors.map(c => c.replace(/[\d.]+\)$/g, '1)')),
                       hoverBackgroundColor: colors.map(c => c.replace(/[\d.]+\)$/g, '0.9)')),

                    }]
                },
                options: {
                    responsive: true,
                    scales: type === 'bar' ? {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1 }
                        }
                    } : {},
                    plugins: {
                        legend: { display: false },
                        title: { display: false }
                    }
                }
            });
        }

        createChart('bar');
        window.chartUpdaters = window.chartUpdaters || {};
        window.chartUpdaters[<?= $i ?>] = createChart;
    })();
    </script>
</div>
<?php endforeach; ?>
</div>

<script>
function updateChartType(index, type) {
    if (window.chartUpdaters?.[index]) {
        window.chartUpdaters[index](type);
    }
}
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.querySelectorAll('.btn-eliminar').forEach(button => {
    button.addEventListener('click', function(e) {
        e.preventDefault();
        const form = this.closest('form');
        const orden = form.dataset.orden;

        if (confirm(`¿Seguro que deseas eliminar la orden con el ID ${orden}?`)) {
            const formData = new FormData(form);

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(`Orden ${orden} eliminada con éxito.`);
                    location.reload();
                } else {
                    alert('Error al eliminar: ' + (data.error || 'Error desconocido.'));
                }
            })
            .catch(err => {
                alert('Error de red: ' + err.message);
            });
        }
    });
});
</script>

<!-- Exportación a CSV -->
<form method="POST" action="exportar_csv.php">
    <input type="hidden" name="turno" value="<?= htmlspecialchars($_GET['turno'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="responsable" value="<?= htmlspecialchars($_GET['responsable'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="empleado" value="<?= htmlspecialchars($_GET['empleado'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="equipo" value="<?= htmlspecialchars($_GET['equipo'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="motivo" value="<?= htmlspecialchars($_GET['motivo'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="porque_defecto" value="<?= htmlspecialchars($_GET['porque_defecto'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="lado_lente" value="<?= htmlspecialchars($_GET['lado_lente'] ?? '', ENT_QUOTES) ?>">

    <!-- Filtros completos de fecha y hora -->
    <input type="hidden" name="fecha_inicio" value="<?= htmlspecialchars($_GET['fecha_inicio'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="hora_inicio" value="<?= htmlspecialchars($_GET['hora_inicio'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="ampm_inicio" value="<?= htmlspecialchars($_GET['ampm_inicio'] ?? '', ENT_QUOTES) ?>">

    <input type="hidden" name="fecha_fin" value="<?= htmlspecialchars($_GET['fecha_fin'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="hora_fin" value="<?= htmlspecialchars($_GET['hora_fin'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="ampm_fin" value="<?= htmlspecialchars($_GET['ampm_fin'] ?? '', ENT_QUOTES) ?>">

    <button type="submit">📥 Descargar Reporte </button>
</form>


<!-- Pie de página -->
<div class="firma">
  Sistema de control de Quiebras | © <?= date("Y"); ?>
  <p>Desarrollado por: Nestor Rosales | Rosalesdev91</p>
</div>

<!-- Script para tabs -->
<script>
document.querySelectorAll('.tab-button').forEach(button => {
  button.addEventListener('click', () => {
    const content = button.nextElementSibling;
    const isVisible = content.style.display === 'block';

    // Ocultar todos
    document.querySelectorAll('.tab-content').forEach(tc => tc.style.display = 'none');
    document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));

    // Mostrar si no estaba visible
    if (!isVisible) {
      content.style.display = 'block';
      button.classList.add('active');
    }
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