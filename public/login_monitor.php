<?php
/**
 * login_monitor.php
 * Página de login para el monitor - con selector de módulo
 * Desarrollado por: Nestor Rosales | Rosalesdev91
 */

session_start();

// Si ya está logueado como admin, redirigir según elección o mostrar selector
if (isset($_SESSION['empleado']) && $_SESSION['rol'] == 'administrador') {
    // Si ya eligió un módulo en esta sesión, redirigir directamente
    if (isset($_SESSION['modulo_destino']) && $_SESSION['modulo_destino'] !== '') {
        $destino = $_SESSION['modulo_destino'];
        unset($_SESSION['modulo_destino']);
        header("Location: $destino");
        exit();
    }
    // Si no, mostrar selector (no redirigir automáticamente)
}

require_once '../config/database.php';
require_once 'registrar_actividad.php';

$error = '';
$modulo_seleccionado = $_POST['modulo'] ?? '';

// Función para obtener la IP real del usuario
if (!function_exists('getRealIP')) {
    function getRealIP() {
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        if (isset($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// Función para obtener el nombre del equipo/hostname
if (!function_exists('getHostnameFromIP')) {
    function getHostnameFromIP($ip) {
        if ($ip && $ip != '0.0.0.0' && $ip != '::1') {
            $hostname = @gethostbyaddr($ip);
            if ($hostname && $hostname != $ip) {
                return $hostname;
            }
        }
        return 'localhost';
    }
}

// Función para obtener el navegador
if (!function_exists('getUserAgent')) {
    function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido';
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $codigo = trim($_POST['codigo'] ?? '');
    $modulo = trim($_POST['modulo'] ?? '');
    
    if (empty($codigo)) {
        $error = 'Por favor, ingrese el código de empleado.';
    } elseif (empty($modulo)) {
        $error = 'Por favor, seleccione el módulo al que desea acceder.';
    } else {
        // Buscar administrador por código de empleado
        $stmt = $conn->prepare("SELECT id, nombre_empleado, codigo_empleado, rol FROM empleados WHERE codigo_empleado = ? AND rol = 'administrador'");
        $stmt->bind_param("s", $codigo);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $admin = $result->fetch_assoc();

            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $_SESSION['ip'] = $ip;    
            
            // Obtener información del cliente
            $ip = getRealIP();
            $hostname = getHostnameFromIP($ip);
            $user_agent = getUserAgent();
            
            // Establecer variables de sesión
            $_SESSION['empleado'] = $admin['nombre_empleado'];
            $_SESSION['rol'] = $admin['rol'];
            $_SESSION['codigo_empleado'] = $admin['codigo_empleado'];
            $_SESSION['id_empleado'] = $admin['id'];
            $_SESSION['ip'] = $ip;
            $_SESSION['hostname'] = $hostname;
            $_SESSION['user_agent'] = $user_agent;
            $_SESSION['ultimo_acceso'] = time();
            $_SESSION['fecha_login'] = date('Y-m-d H:i:s');
            
            // Registrar actividad de login
            registrar_actividad($conn, 'login', $admin['nombre_empleado'], 
                "Inició sesión como administrador desde IP: $ip ($hostname) - Módulo destino: $modulo");
            
            // Redirigir al módulo seleccionado
            header("Location: $modulo");
            exit();
        } else {
            $error = 'Código de empleado no válido o no tiene permisos de administrador.';
        }
        $stmt->close();
    }
}

// Obtener módulo guardado en sesión si existe (para mostrar selector)
$modulo_guardado = $_SESSION['modulo_guardado'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Monitor - Sistema Quiebras</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #0a3d2a 0%, #155724 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .login-container {
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(92, 223, 133, 0.3);
        }
        
        .logo-area {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo-area h2 {
            color: #5cdf85;
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .logo-area p {
            color: #a8f0b8;
            font-size: 13px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            color: #a8f0b8;
            font-size: 14px;
            margin-bottom: 10px;
            font-weight: bold;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 14px 15px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(92, 223, 133, 0.5);
            border-radius: 10px;
            color: white;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .form-group input {
            text-align: center;
            letter-spacing: 1px;
        }
        
        .form-group select option {
            background: #0a3d2a;
            color: white;
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #5cdf85;
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 0 10px rgba(92, 223, 133, 0.3);
        }
        
        .btn-login {
            width: 100%;
            padding: 14px;
            background: #006400;
            border: none;
            border-radius: 10px;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-login:hover {
            background: #008000;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 100, 0, 0.3);
        }
        
        .error-message {
            background: rgba(255, 0, 0, 0.2);
            border: 1px solid #ff6b6b;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 20px;
            color: #ff6b6b;
            font-size: 13px;
            text-align: center;
        }
        
        .info-text {
            text-align: center;
            margin-top: 20px;
            color: #a8f0b8;
            font-size: 12px;
        }
        
        .demo-credentials {
            background: rgba(92, 223, 133, 0.1);
            border-radius: 10px;
            padding: 15px;
            margin-top: 25px;
            text-align: center;
        }
        
        .code-badge {
            background: #006400;
            padding: 8px 15px;
            border-radius: 25px;
            display: inline-block;
            font-family: monospace;
            font-size: 16px;
            letter-spacing: 2px;
            color: white;
            margin-top: 10px;
        }
        
        .modulos-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 10px;
        }
        
        .modulo-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(92, 223, 133, 0.3);
            border-radius: 12px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        
        .modulo-card:hover {
            background: rgba(92, 223, 133, 0.15);
            border-color: #5cdf85;
            transform: translateY(-2px);
        }
        
        .modulo-card.selected {
            background: rgba(92, 223, 133, 0.25);
            border-color: #5cdf85;
            box-shadow: 0 0 15px rgba(92, 223, 133, 0.3);
        }
        
        .modulo-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .modulo-titulo {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .modulo-desc {
            font-size: 11px;
            color: #a8f0b8;
        }
        
        .radio-hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-area">
            <h2>📡 Sistema de Control</h2>
            <p>Producción - Quiebras - Monitoreo</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error-message">
                ⚠️ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="loginForm">
            <div class="form-group">
                <label>📋 Código de Empleado</label>
                <input type="password" 
                       name="codigo" 
                       id="codigo"
                       placeholder="•••••••" 
                       value="<?php echo htmlspecialchars($_POST['codigo'] ?? ''); ?>" 
                       autofocus
                       autocomplete="off">
            </div>
            
            <div class="form-group">
                <label>🎯 Seleccione el Módulo</label>
                <div class="modulos-grid">
                    <!-- Módulo 1: Monitor General -->
                    <div class="modulo-card" data-modulo="dashboard_monitor.php">
                        <div class="modulo-icon">📡</div>
                        <div class="modulo-titulo">Monitor General</div>
                        <div class="modulo-desc">Usuarios conectados, actividad, auditoría</div>
                    </div>
                    
                    <!-- Módulo 2: Monitor Producción -->
                    <div class="modulo-card" data-modulo="dashboard_monitor_produccion.php">
                        <div class="modulo-icon">🏭</div>
                        <div class="modulo-titulo">Monitor Producción</div>
                        <div class="modulo-desc">Producción por área, quiebras en vivo</div>
                    </div>
                </div>
                <input type="hidden" name="modulo" id="modulo_seleccionado" value="">
            </div>
            
            <button type="submit" class="btn-login" id="btnLogin" disabled>🔓 Acceder al Módulo</button>
        </form>
        
        <div class="demo-credentials">
            <p><strong>📝 Acceso Administrador</strong></p>
            <p>Ingrese el código:</p>
            <div class="code-badge">•••••••</div>
        </div>
        
        <div class="info-text">
            ⚡ Acceso autorizado solo para administradores<br>
            Seleccione el módulo que desea monitorear
        </div>
    </div>

    <script>
        // Selección de módulo
        const moduloCards = document.querySelectorAll('.modulo-card');
        const moduloInput = document.getElementById('modulo_seleccionado');
        const btnLogin = document.getElementById('btnLogin');
        const codigoInput = document.getElementById('codigo');
        
        let moduloSeleccionado = '';
        
        moduloCards.forEach(card => {
            card.addEventListener('click', function() {
                // Remover selección de todos
                moduloCards.forEach(c => c.classList.remove('selected'));
                // Seleccionar este
                this.classList.add('selected');
                moduloSeleccionado = this.dataset.modulo;
                moduloInput.value = moduloSeleccionado;
                
                // Habilitar botón si hay código
                if (codigoInput.value.trim() !== '') {
                    btnLogin.disabled = false;
                }
            });
        });
        
        // Verificar código
        codigoInput.addEventListener('input', function() {
            if (this.value.trim() !== '' && moduloSeleccionado !== '') {
                btnLogin.disabled = false;
            } else {
                btnLogin.disabled = true;
            }
        });
        
        // Prevenir envío si no hay módulo seleccionado
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            if (moduloSeleccionado === '') {
                e.preventDefault();
                alert('Por favor, seleccione un módulo');
                return false;
            }
        });
    </script>
</body>
</html>