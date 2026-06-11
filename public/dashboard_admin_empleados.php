<?php
session_start();

// Validación de sesión
if (!isset($_SESSION['empleado']) || $_SESSION['rol'] != 'administrador') {
    header("Location: login.php");
    exit();
}

require_once '../config/database.php';
require_once 'auto_audit.php';
require_once 'registrar_actividad.php';

// Inicializar mensajes de error y éxito
$mensaje_error = '';
$mensaje_exito = '';

// Agregar un empleado
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agregar_empleado'])) {
    $nombre = trim($_POST['nombre_empleado']);
    $codigo_empleado = trim($_POST['codigo_empleado']);
    $rol = $_POST['rol'];

    // Verificar campos vacíos
    if (!empty($nombre) && !empty($codigo_empleado) && !empty($rol)) {
        // Validar que el código no esté duplicado
        $verificar = $conn->prepare("SELECT id FROM empleados WHERE codigo_empleado = ?");
        $verificar->bind_param("s", $codigo_empleado);
        $verificar->execute();
        $verificar->store_result();

        if ($verificar->num_rows > 0) {
            $mensaje_error = "El código de empleado ya existe.";
        } else {
            $stmt = $conn->prepare("INSERT INTO empleados (nombre_empleado, codigo_empleado, rol) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sss", $nombre, $codigo_empleado, $rol);
                $stmt->execute();
                $stmt->close();
                $mensaje_exito = "Empleado agregado con éxito.";
                // Refresca la página para mostrar al nuevo empleado
                header("Refresh: 0");
                exit();
            } else {
                $mensaje_error = "Error al preparar la consulta.";
            }
        }

        $verificar->close();
    } else {
        $mensaje_error = "Todos los campos son obligatorios.";
    }
}

// Modificar empleado (nombre, código y rol)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['modificar_empleado'])) {
    $id = $_POST['id_empleado'];
    $nombre_modificado = trim($_POST['nombre_empleado']);
    $codigo_modificado = trim($_POST['codigo_empleado']);
    $nuevo_rol = $_POST['nuevo_rol'];

    if (is_numeric($id) && !empty($nombre_modificado) && !empty($codigo_modificado) && !empty($nuevo_rol)) {
        $verificar = $conn->prepare("SELECT id FROM empleados WHERE codigo_empleado = ? AND id != ?");
        $verificar->bind_param("si", $codigo_modificado, $id);
        $verificar->execute();
        $verificar->store_result();

        if ($verificar->num_rows > 0) {
            $mensaje_error = "El código de empleado ya existe para otro registro.";
        } else {
            $stmt = $conn->prepare("UPDATE empleados SET nombre_empleado = ?, codigo_empleado = ?, rol = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("sssi", $nombre_modificado, $codigo_modificado, $nuevo_rol, $id);
                $stmt->execute();
                $stmt->close();
                $mensaje_exito = "Empleado modificado con éxito.";
                header("Refresh: 0");
                exit();
            } else {
                $mensaje_error = "Error al preparar la consulta de modificación.";
            }
        }

        $verificar->close();
    } else {
        $mensaje_error = "Todos los campos son obligatorios para modificar el empleado.";
    }
}

// Eliminar empleado
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    if (is_numeric($id)) {
        $stmt = $conn->prepare("DELETE FROM empleados WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            // Redirigir después de la eliminación para evitar duplicados
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

// Obtener empleados ordenados alfabéticamente por nombre completo
$empleados = $conn->query("SELECT * FROM empleados ORDER BY nombre_empleado ASC");

// Cerrar conexión
$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Empleado</title>
    <style>
        body {
            background: #155724;
            color: white;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            padding-bottom: 90px;
        }

        .content-wrapper {
            max-width: 900px;
            margin: auto;
            padding: 20px;
        }

        form, .tabla-empleados {
            background: rgba(0, 0, 0, 0.25);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            width: 100%;
            margin-top: 20px;
        }

        input, select, button {
            padding: 12px;
            width: 100%;
            margin: 12px 0;
            border-radius: 6px;
            border: 1px solid #c3e6cb;
            background-color: #d4edda;
            color: #155724;
            font-weight: bold;
            box-sizing: border-box;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #28a745;
            box-shadow: 0 0 5px #28a745;
        }

        table {
            border-collapse: collapse;
            background-color: #f0fdf4;
            color: #155724;
            width: 100%;
        }

        th, td {
            padding: 10px;
            border: 1px solid #333;
            text-align: center;
            font-weight: bold;
        }

        th {
            background: #218838;
            color: white;
        }

        tr:hover {
            background-color: #d4edda;
        }

        button {
            background: #006400;
            color: white;
            border: none;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        button:hover {
            background: #004d00;
        }

        .btn-delete, .btn-modificar {
            background-color: #dc3545;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: auto;
            margin-right: 5px;
        }

        .btn-modificar {
            background-color: #007bff;
        }

        .btn-modificar:hover {
            background-color: #0056b3;
        }

        .btn-delete:hover {
            background-color: #c82333;
        }

        .mensaje-error {
            color: #721c24;
            background-color: #f8d7da;
            padding: 8px;
            border-radius: 4px;
        }

        .mensaje-exito {
            color: #155724;
            background-color: #d4edda;
            padding: 8px;
            border-radius: 4px;
        }

        .firma {
            text-align: center;
            font-size: 15px;
            color: #d4fcd4;
            padding: 15px 0;
            background-color: #003300;
            border-top: 1px solid #d4fcd4;
            position: fixed;
            left: 0;
            bottom: 0;
            width: 100%;
            box-sizing: border-box;
        }

        .logo {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 200px;
            height: auto;
        }

        .toggle-btn {
            background: #117a37;
            margin-top: 20px;
            padding: 10px;
            width: auto;
        }

        .toggle-btn:hover {
            background: #0b5c28;
        }

        .tabla-empleados {
            display: none; /* Oculta inicialmente */
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: #f0fdf4;
            padding: 20px;
            border-radius: 8px;
            width: 400px;
            max-width: 90%;
        }

        .modal-content h3 {
            margin-top: 0;
            color: #155724;
        }

        .modal-content select {
            width: 100%;
            margin: 10px 0;
        }

        .modal-content button {
            width: auto;
            padding: 8px 16px;
        }

        .close-btn {
            background: #dc3545;
            margin-left: 10px;
        }

        .close-btn:hover {
            background: #c82333;
        }
    </style>
</head>

<body>
    <img src="/control_produccion/public/logo.png" alt="Logo" class="logo">

    <div class="content-wrapper">
        <h2>Dashboard de Empleados</h2>
        <h2>Bienvenid@, <?php echo htmlspecialchars($_SESSION['empleado']); ?> | <a href="login_admin.php" style="color: white;">Cerrar sesión</a></h2>

        <!-- Agregar nuevo empleado -->
        <h3>Agregar Empleado</h3>
        <?php if (!empty($mensaje_error)): ?>
            <div class="mensaje-error"><?= htmlspecialchars($mensaje_error) ?></div>
        <?php endif; ?>
        <?php if (!empty($mensaje_exito)): ?>
            <div class="mensaje-exito"><?= htmlspecialchars($mensaje_exito) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="text" name="nombre_empleado" placeholder="Nombre del empleado" required>
            <input type="text" name="codigo_empleado" placeholder="Código de empleado" required>
            <select name="rol" required>
                <option value="empleado">Empleado</option>
                <option value="administrador">Administrador</option>
            </select>
            <button type="submit" name="agregar_empleado">Agregar Empleado</button>
        </form>

        <!-- Botón para mostrar/ocultar tabla -->
        <button class="toggle-btn" onclick="toggleTabla()">Ver/Ocultar Lista de Empleados</button>

        <!-- Lista de empleados en un contenedor colapsable -->
        <div class="tabla-empleados" id="tablaEmpleados">
            <h3>Lista de Empleados</h3>
            <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; border-radius: 8px; padding: 8px; background-color: #f9f9f9;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: #1e7e34; color: white;">
                            <th style="padding: 8px; text-align: left;">Nombre</th>
                            <th style="padding: 8px; text-align: left;">Código</th>
                            <th style="padding: 8px; text-align: left;">Rol</th>
                            <th style="padding: 8px; text-align: left;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($empleado = $empleados->fetch_assoc()): ?>
                            <tr style="border-bottom: 1px solid #ddd;">
                                <td style="padding: 8px;"><?= htmlspecialchars($empleado['nombre_empleado']) ?></td>
                                <td style="padding: 8px;"><?= !empty($empleado['codigo_empleado']) ? '*******' : '' ?></td>
                                <td style="padding: 8px;"><?= htmlspecialchars($empleado['rol']) ?></td>
                                <td style="padding: 8px;">
                                    <button class="btn-modificar" onclick='mostrarModal(<?= $empleado['id'] ?>, <?= json_encode($empleado['nombre_empleado']) ?>, <?= json_encode($empleado['codigo_empleado']) ?>, <?= json_encode($empleado['rol']) ?>)'>Editar</button>
                                    <form method="GET" onsubmit="return confirm('¿Estás seguro de eliminar este empleado?');" style="display:inline;">
                                        <input type="hidden" name="eliminar" value="<?= $empleado['id'] ?>">
                                        <button class="btn-delete" type="submit">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal para editar empleado -->
        <div id="modalEmpleado" class="modal">
            <div class="modal-content">
                <h3>Editar Empleado: <span id="nombreEmpleado"></span></h3>
                <form method="POST">
                    <input type="hidden" name="id_empleado" id="idEmpleado">
                    <label for="nombreEmpleadoInput" style="color: #155724; font-weight: bold;">Nombre</label>
                    <input type="text" name="nombre_empleado" id="nombreEmpleadoInput" placeholder="Nombre del empleado" required>
                    <label for="codigoEmpleadoInput" style="color: #155724; font-weight: bold;">Código</label>
                    <input type="text" name="codigo_empleado" id="codigoEmpleadoInput" placeholder="Código de empleado" required>
                    <label for="nuevoRol" style="color: #155724; font-weight: bold;">Rol</label>
                    <select name="nuevo_rol" id="nuevoRol" required>
                        <option value="empleado">Empleado</option>
                        <option value="administrador">Administrador</option>
                    </select>
                    <button type="submit" name="modificar_empleado">Guardar Cambios</button>
                    <button type="button" class="close-btn" onclick="cerrarModal()">Cancelar</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function toggleTabla() {
            var tabla = document.getElementById("tablaEmpleados");
            tabla.style.display = (tabla.style.display === "none" || tabla.style.display === "") ? "block" : "none";
        }

        function mostrarModal(id, nombre, codigo, rol) {
            document.getElementById("idEmpleado").value = id;
            document.getElementById("nombreEmpleado").textContent = nombre;
            document.getElementById("nombreEmpleadoInput").value = nombre;
            document.getElementById("codigoEmpleadoInput").value = codigo;
            document.getElementById("nuevoRol").value = rol;
            document.getElementById("modalEmpleado").style.display = "flex";
        }

        function cerrarModal() {
            document.getElementById("modalEmpleado").style.display = "none";
        }
    </script>

    <!-- Firma -->
    <div class="firma">
        Sistema de administración de empleados | © <?php echo date("Y"); ?>
        <p>Desarrollado por: Nestor Rosales | Rosalesdev91</p>
    </div>

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