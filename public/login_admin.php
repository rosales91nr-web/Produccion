<?php
/**
 * login_admin.php
 * Login para administradores con registro de actividad
 * Desarrollado por: Nestor Rosales | Rosalesdev91
 */

session_start();
require_once 'registrar_actividad.php';

$error = $area = $codigo = '';

// Función para obtener IP real
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

// Procesamiento POST optimizado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $area = trim($_POST['area'] ?? '');
    $codigo = trim($_POST['codigo'] ?? '');

    if ($area && $codigo) {
        require_once '../config/database.php';

        $stmt = $conn->prepare("SELECT id, nombre_empleado, codigo_empleado, rol FROM empleados WHERE codigo_empleado = ? AND rol = 'administrador' LIMIT 1");
        $stmt->bind_param("s", $codigo);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            
            // Obtener IP real
            $ip = getRealIP();
            
            // Guardar datos en sesión
            $_SESSION['empleado'] = $row['nombre_empleado'];
            $_SESSION['rol'] = 'administrador';
            $_SESSION['codigo_empleado'] = $row['codigo_empleado'];
            $_SESSION['id_empleado'] = $row['id'];
            $_SESSION['ip'] = $ip;
            $_SESSION['ultimo_acceso'] = time();
            $_SESSION['fecha_login'] = date('Y-m-d H:i:s');
            
            // Registrar actividad de login
            $modulo_destino = '';
            $routes = [
                'produccion' => 'dashboard_admin_produccion.php',
                'ia_queries' => 'ia_queries.php',
                'empleados' => 'dashboard_admin_empleados.php',
                'quiebras' => 'dashboard_admin_quiebras.php',
                'tablas' => 'administracion_tablas.php',
                'pausas_empleados' => 'dashboard_admin_asistencia.php',
                'pruebas_calidad' => 'dashboard_admin_check.php',
                'paros_equipos' => 'dashboard_admin_paros.php',
                'asignar_tarea' => 'asignar_tarea.php',
                'backups'       => 'dashboard_admin_backups.php',
            ];
            
            $modulo_destino = $routes[$area] ?? 'dashboard_monitor.php';
            
            // Registrar en actividad_monitor
            registrar_actividad($conn, 'login', $row['nombre_empleado'], 
                "🔐 Administrador inició sesión | Módulo destino: " . ucfirst($area) . " | IP: {$ip}", $ip);

            if (isset($routes[$area])) {
                header("Location: " . $routes[$area]);
                exit;
            }
            $error = "Área inválida.";
        } else {
            $error = "Acceso denegado. Código incorrecto o no tiene permisos de administrador.";
            // Registrar intento fallido
            registrar_actividad($conn, 'login', $codigo, 
                "❌ Intento de acceso fallido como administrador | IP: " . getRealIP(), getRealIP());
        }
        $stmt->close();
        $conn->close();
    } else {
        $error = "Completa todos los campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SIA-LAB - Acceso Administrativo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&family=Rajdhani:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root{--g:#28a745;--dg:#218838;--lg:#d4fcd4;--vdg:#003300;--glow:0 0 15px rgba(40,167,69,.7);--gi:0 0 30px rgba(40,167,69,.9);--bg-light:rgba(14, 53, 23, 0.7);--text-dark:#333;--text-light:var(--lg);--card-bg:rgba(255,255,255,0.95);--input-bg:#fff;}
        *{margin:0;padding:0;box-sizing:border-box}
        html,body{height:100%;font-family:'Rajdhani',sans-serif;background:var(--bg-light);color:var(--text-light);overflow:hidden}
        .grid{position:fixed;inset:0;background:linear-gradient(90deg,rgba(40,167,69,.05) 1px,transparent 1px),linear-gradient(rgba(40,167,69,.05) 1px,transparent 1px);background-size:40px 40px;animation:g 25s linear infinite;will-change:background-position}
        @keyframes g{to{background-position:40px 40px}}
        .particles{position:fixed;inset:0;pointer-events:none;z-index:1}
        .wrapper{max-width:1280px;margin:auto;display:grid;grid-template-columns:1fr 1fr;gap:40px;padding:30px;align-items:center;min-height:100vh;position:relative;z-index:10}
        .brand{text-align:center;opacity:0;animation:f 1s ease-out .3s forwards}
        @keyframes f{to{opacity:1;transform:none}}
        .logo{width:200px;height:200px;margin:auto auto 20px;filter:drop-shadow(0 0 10px rgba(40,167,69,.3));animation:float 5s ease-in-out infinite}
        .logo img{width:100%;height:100%;object-fit:contain;border:2px solid var(--g);border-radius:50%;padding:12px;background:rgba(40,167,69,.1);animation:r 18s linear infinite}
        @keyframes r{to{transform:rotate(360deg)}}
        @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-12px)}}
        .title{font:900 3rem 'Orbitron',monospace;letter-spacing:3px;background:linear-gradient(90deg,var(--g),var(--dg),var(--g));-webkit-background-clip:text;-webkit-text-fill-color:transparent;text-shadow:0 0 10px rgba(40,167,69,.5);margin-bottom:12px}
        .subtitle{font-size:1.05rem;opacity:.85;line-height:1.6;margin-bottom:20px;color:var(--text-light);text-shadow:0 0 5px rgba(212,252,212,0.5)}
        .features{list-style:none;margin-top:25px}
        .features li{display:flex;align-items:center;justify-content:center;gap:10px;font-size:1rem;opacity:0;animation:s .5s ease-out forwards;color:var(--text-light);text-shadow:0 0 3px rgba(212,252,212,0.3)}
        .features li:nth-child(1){animation-delay:.8s}
        .features li:nth-child(2){animation-delay:.9s}
        .features li:nth-child(3){animation-delay:1s}
        .features li:nth-child(4){animation-delay:1.1s}
        .features li:nth-child(5){animation-delay:1.2s}
        .features li:nth-child(6){animation-delay:1.3s}
        @keyframes s{to{opacity:1;transform:none}}
        .features i{color:var(--g);filter:drop-shadow(0 0 4px currentColor)}
        .card{background:var(--card-bg);backdrop-filter:blur(14px);border:1px solid rgba(40,167,69,.3);border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,0.1);opacity:0;transform:translateY(20px);animation:c 1s cubic-bezier(.25,.8,.25,1) .5s forwards;will-change:transform,opacity}
        @keyframes c{to{opacity:1;transform:none}}
        .header{background:linear-gradient(135deg,rgba(40,167,69,.9),rgba(33,136,56,.95));padding:30px 25px;text-align:center;position:relative;overflow:hidden;color:#fff}
        .header::before{content:'';position:absolute;inset:-50%;background:radial-gradient(circle,rgba(255,255,255,.1) 1px,transparent 1px);background-size:18px 18px;animation:scan 7s linear infinite}
        @keyframes scan{to{transform:translate(50%,50%)}}
        .header h2{font:700 1.7rem 'Orbitron',monospace;color:var(--lg);text-shadow:var(--glow);position:relative;z-index:2}
        .header p{font-size:.9rem;opacity:.9;margin-top:6px;position:relative;z-index:2}
        .body{padding:35px;color:var(--text-dark)}
        .group{margin-bottom:22px;position:relative}
        .group label{display:flex;align-items:center;gap:8px;font-weight:600;font-size:.92rem;color:var(--text-dark);margin-bottom:8px;text-shadow:none}
        .group select,.group input{width:100%;padding:13px 16px;background:var(--input-bg);border:1px solid rgba(40,167,69,.5);border-radius:10px;color:var(--text-dark);font-size:1rem;transition:.3s;backdrop-filter:blur(4px)}
        .group select:focus,.group input:focus{outline:none;border-color:var(--g);box-shadow:var(--glow);transform:translateY(-2px)}
        .group select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 14 14'%3E%3Cpath fill='%2328a745' d='M7 10L2 5h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 16px center}
        .input{position:relative}
        .toggle{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--g);cursor:pointer;font-size:1.1rem;transition:.3s}
        .toggle:hover{transform:translateY(-50%) scale(1.25);filter:drop-shadow(0 0 8px currentColor)}
        .btn{width:100%;padding:15px;background:linear-gradient(135deg,var(--g),var(--dg));color:#fff;font:700 1.05rem 'Orbitron',monospace;letter-spacing:1px;border:none;border-radius:10px;cursor:pointer;transition:.4s;box-shadow:var(--glow);position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;gap:8px}
        .btn::before{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.15),transparent);transform:translateX(-100%);transition:.6s}
        .btn:hover::before{transform:translateX(100%)}
        .btn:hover{transform:translateY(-3px);box-shadow:var(--gi)}
        .btn:active{transform:none}
        .error{background:rgba(220,53,69,.15);color:#dc3545;padding:12px 16px;border-radius:8px;border:1px solid #dc3545;font-size:.88rem;margin-top:18px;display:flex;align-items:center;gap:8px;animation:p .4s ease-out;box-shadow:0 0 12px rgba(220,53,69,.3)}
        @keyframes p{0%{transform:scale(.95);opacity:0}100%{transform:scale(1);opacity:1}}
        .footer{position:fixed;bottom:0;left:0;right:0;background:rgba(40,167,69,.1);backdrop-filter:blur(8px);color:var(--text-light);text-align:center;padding:10px;font-size:.82rem;border-top:1px solid rgba(40,167,69,.3);z-index:100;opacity:0;animation:f 1s ease-out 1.2s forwards}
        @media (max-width:992px){.wrapper{grid-template-columns:1fr;gap:25px;padding:20px}.features{display:none}}
        @media (max-width:576px){.title{font-size:2.4rem}.body{padding:25px}}
        @media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms !important;animation-iteration-count:1 !important;transition-duration:.01ms !important}}
    </style>
</head>
<body>

    <canvas class="particles" id="p"></canvas>
    <div class="grid"></div>

    <div class="wrapper">
        <div class="brand">
            <div class="logo"><img src="/logo.svg" alt="SIA-LAB"></div>
            <h1 class="title">SIA-LAB</h1>
            <p class="subtitle">Sistema Administrativo Para Laboratorio Optico</p>
            <ul class="features">
                <li><i class="fas fa-chart-line"></i>Monitoreo en Tiempo Real de Producción</li>
                <li><i class="fas fa-users"></i>Gestión y Control de Empleados</li>
                <li><i class="fas fa-exclamation-triangle"></i>Gestión y Reporte de Quiebras</li>
                <li><i class="fas fa-brain"></i>Reportes y Consultas Optimizadas</li>
                <li><i class="fas fa-clock"></i>Control de Pausas Empleados</li>
                <li><i class="fas fa-check-circle"></i>Control y Gestión de Pruebas de Calidad</li>
            </ul>
        </div>

        <div class="card">
            <div class="header">
                <h2>ACCESO ADMINISTRATIVO</h2>
                <p>Ingresa Tus Credenciales Para Acceder</p>
            </div>
            <div class="body">
                <form method="post" id="f" novalidate>
                    <div class="group">
                        <label for="a"><i class="fas fa-layer-group"></i>Módulo</label>
                        <select id="a" name="area" required>
                            <option value="">-- SELECCIONAR --</option>
                            <option value="produccion" <?= $area==='produccion'?'selected':'' ?>>Producción</option>
                            <option value="empleados" <?= $area==='empleados'?'selected':'' ?>>Empleados</option>
                            <option value="quiebras" <?= $area==='quiebras'?'selected':'' ?>>Quiebras</option>
                            <option value="ia_queries" <?= $area==='ia_queries'?'selected':'' ?>>Datos Avanzados</option>
                            <option value="pausas_empleados" <?= $area==='pausas_empleados'?'selected':'' ?>>Pausas</option>
                            <option value="tablas" <?= $area==='tablas'?'selected':'' ?>>Administrar tablas</option>
                            <option value="pruebas_calidad" <?= $area==='pruebas_calidad'?'selected':'' ?>>Pruebas de calidad</option>
                            <option value="paros_equipos" <?= $area==='paros_equipos'?'selected':'' ?>>Paros Equipos</option>
                            <option value="asignar_tarea" <?= $area==='asignar_tarea'?'selected':'' ?>>Asignar Tareas</option>     
                        </select>
                    </div>
                    <div class="group">
                        <label for="c"><i class="fas fa-key"></i>Código Administrativo</label>
                        <div class="input">
                            <input type="password" id="c" name="codigo" placeholder="••••••••" value="<?= htmlspecialchars($codigo) ?>" autocomplete="off" required>
                            <button type="button" class="toggle" onclick="t()" aria-label="Mostrar"><i class="fas fa-eye" id="i"></i></button>
                        </div>
                    </div>
                    <button type="submit" class="btn" id="b"><i class="fas fa-sign-in-alt"></i>INICIAR SESIÓN</button>
                </form>
                <?php if ($error): ?>
                <div class="error" role="alert" aria-live="assertive">
                    <i class="fas fa-exclamation-triangle"></i><?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="footer">
        <p><strong>Sistema Integrado Administrativo</strong> | © <?= date("Y") ?></p>
        <p>Desarrollado Por: <strong>Nestor Rosales</strong> | Rosalesdev91</p>
    </div>

    <script>
        // Worker en línea para partículas
        const workerCode = `
            let particles = [], w, h, ctx;
            class P { constructor() { this.x=Math.random()*w; this.y=Math.random()*h; this.s=Math.random()*1.5+.5; this.vx=Math.random()*.6-.3; this.vy=Math.random()*.6-.3; } update() { this.x+=this.vx; this.y+=this.vy; if(this.x>w||this.x<0)this.vx*=-1; if(this.y>h||this.y<0)this.vy*=-1; } draw() { ctx.fillStyle='rgba(40,167,69,.3)'; ctx.beginPath(); ctx.arc(this.x,this.y,this.s,0,Math.PI*2); ctx.fill(); } }
            self.onmessage = e => {
                if(e.data.type==='init'){ w=e.data.w; h=e.data.h; ctx=e.data.ctx.getContext('2d'); for(let i=0;i<60;i++)particles.push(new P()); animate(); }
                if(e.data.type==='resize'){ w=e.data.w; h=e.data.h; }
            };
            function animate(){ ctx.clearRect(0,0,w,h); particles.forEach(p=>{p.update();p.draw()}); requestAnimationFrame(animate); }
        `;
        const blob = new Blob([workerCode], {type: 'application/javascript'});
        const worker = new Worker(URL.createObjectURL(blob));
        const canvas = document.getElementById('p');
        canvas.width = innerWidth;
        canvas.height = innerHeight;
        const offscreen = canvas.transferControlToOffscreen();
        worker.postMessage({type:'init', w:innerWidth, h:innerHeight, ctx:offscreen}, [offscreen]);
        
        addEventListener('resize', () => { 
            worker.postMessage({type:'resize', w:innerWidth, h:innerHeight}); 
        });

        // Toggle password
        function t() { 
            const i = document.getElementById('c'); 
            const e = document.getElementById('i'); 
            i.type = i.type === 'password' ? 'text' : 'password'; 
            e.classList.toggle('fa-eye'); 
            e.classList.toggle('fa-eye-slash'); 
        }

        // Formulario
        const f = document.getElementById('f'), b = document.getElementById('b');
        f.addEventListener('submit', e => {
            if(!f.checkValidity()){ 
                e.preventDefault(); 
                mostrarError('Completa todos los campos.'); 
                return; 
            }
            b.disabled = true; 
            b.innerHTML = '<i class="fas fa-spinner fa-spin"></i>VERIFICANDO...';
        });
        
        function mostrarError(m) { 
            if(document.querySelector('.error')) return; 
            const d = document.createElement('div'); 
            d.className = 'error'; 
            d.innerHTML = '<i class="fas fa-exclamation-triangle"></i>' + m; 
            f.after(d); 
            setTimeout(() => d.remove(), 3500); 
        }

        // Focus inicial
        requestIdleCallback(() => document.getElementById('a').focus());
    </script>
</body>
</html>