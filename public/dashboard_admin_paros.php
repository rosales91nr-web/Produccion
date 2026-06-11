<?php
/**
 * Dashboard Administrativo Avanzado Optimizado - Sistema de Gestión de Paros de Producción
 * @author Nestor Rosales | Rosalesdev91
 * @version 1.1
 */

declare(strict_types=1);

session_start();

// Expiración de sesión de 3 horas para Admin Paros
$sessionTimeoutSeconds = 3 * 60 * 60; // 3 horas
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $sessionTimeoutSeconds) {
    session_unset();
    session_destroy();
    session_start();
    header("Location: login_admin.php");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

if (!isset($_SESSION['CREATED'])) {
    $_SESSION['CREATED'] = time();
}
if ((time() - $_SESSION['CREATED']) > $sessionTimeoutSeconds) {
    session_unset();
    session_destroy();
    session_start();
    header("Location: login_admin.php");
    exit();
}

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

// Configuración
const DEBUG = false;
const CACHE_ENABLED = true;
const MAX_EQUIPOS_LIMIT = 50;
const MAX_AREAS_LIMIT = 10;
const HORAS_OPERATIVAS_DIARIAS = 24; // 24 horas por día
/**
 * Clase principal para gestión del dashboard
 */
class DashboardManager {
    private mysqli $conn;
    private array $filters;
    private array $cache = [];
    private array $errorLog = [];
    
    // Estados válidos del sistema
    private const ESTADOS_VALIDOS = ['pendiente', 'iniciada', 'rechazada', 'finalizada'];
    
    // Configuración OEE
    private const OEE_RENDIMIENTO = 0.85;
    private const OEE_CALIDAD = 0.95;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
        $this->setupEnvironment();
        $this->initializeFilters();
    }

    /**
     * Configuración inicial del entorno
     */
    private function setupEnvironment(): void {
        if (!$this->conn->set_charset("utf8mb4")) {
            $this->logError("Error al setear charset: {$this->conn->error}");
        }
        date_default_timezone_set('America/Costa_Rica');
    }

    /**
     * Inicializa y valida filtros de entrada
     */
    private function initializeFilters(): void {
        $fechaHasta = $this->validarFecha($_GET['fecha_hasta'] ?? date('Y-m-d'));
        $fechaDesde = $this->validarFecha($_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-0 days')));

        // Asegurar que fecha_desde <= fecha_hasta
        if (strtotime($fechaDesde) > strtotime($fechaHasta)) {
            $fechaDesde = $fechaHasta;
        }

        $this->filters = [
            'fecha_desde' => $fechaDesde,
            'fecha_hasta' => $fechaHasta,
            'area' => $this->sanitizeString($_GET['area'] ?? ''),
            'equipo' => $this->sanitizeString($_GET['equipo'] ?? ''),
            'estado' => $this->sanitizeString($_GET['estado'] ?? ''),
            'tipo_paro' => (int)($_GET['tipo_paro'] ?? 0)
        ];
    }

    /**
     * Valida formato de fecha
     */
    private function validarFecha(string $fecha): string {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) && strtotime($fecha) !== false) {
            return $fecha;
        }
        return date('Y-m-d');
    }

    /**
     * Sanitiza strings de entrada
     */
    private function sanitizeString(string $input): string {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Obtiene filtros actuales
     */
    public function getFilters(): array {
        return $this->filters;
    }

    /**
     * Obtiene lista de áreas activas
     */
    public function getAreasList(): array {
        return $this->getCachedData('areas', function() {
            $query = "SELECT id, area FROM areas WHERE activo = 1 ORDER BY area ASC";
            return $this->executeQuery($query, [], 'all');
        });
    }

    /**
     * Obtiene lista de equipos activos
     */
    public function getEquiposList(?int $areaId = null): array {
        $cacheKey = 'equipos_' . ($areaId ?? 'all');
        
        return $this->getCachedData($cacheKey, function() use ($areaId) {
            $query = "SELECT e.id, e.nombre_equipo, a.area 
                      FROM equipos e 
                      JOIN areas a ON e.area_id = a.id 
                      WHERE e.activo = 1";
            
            $params = [];
            $types = '';
            
            if ($areaId !== null) {
                $query .= " AND e.area_id = ?";
                $params[] = $areaId;
                $types = 'i';
            }
            
            $query .= " ORDER BY a.area, e.nombre_equipo ASC";
            
            return $this->executeQuery($query, $params, 'all', $types);
        });
    }

    /**
     * Obtiene el total de equipos activos
     */
    public function getTotalEquiposActivos(): int {
        $cacheKey = 'total_equipos_activos';
        
        return $this->getCachedData($cacheKey, function() {
            $query = "SELECT COUNT(*) as total FROM equipos WHERE activo = 1";
            $result = $this->executeQuery($query, [], 'single');
            return (int)($result['total'] ?? 0);
        });
    }

    /**
     * Obtiene estados válidos del sistema
     */
    public function getEstadosList(): array {
        return self::ESTADOS_VALIDOS;
    }

    /**
     * Obtiene tipos de paro
     */
    public function getTiposParoList(): array {
        return $this->getCachedData('tipos_paro', function() {
            $query = "SELECT id, nombre FROM tipos_paro ORDER BY nombre ASC";
            return $this->executeQuery($query, [], 'all');
        });
    }

    /**
     * Construye cláusula WHERE y parámetros para queries filtradas
     * EXCLUYE automáticamente los paros de tipo "Sin WIP"
     */
    private function buildWhereClause(): array {
        $conditions = ["DATE(sp.fecha_solicitud) BETWEEN ? AND ?"];
        $params = [$this->filters['fecha_desde'], $this->filters['fecha_hasta']];
        $types = "ss";

        // EXCLUIR automáticamente paros de tipo "Sin WIP"
        $conditions[] = "sp.tipo_paro != 'Sin WIP'";

        $filterMap = [
            'area' => ['sp.area = ?', 's'],
            'equipo' => ['sp.equipo = ?', 's'],
            'tipo_paro' => ['sp.tipo_paro = ?', 's']
        ];

        foreach ($filterMap as $key => [$condition, $type]) {
            if (!empty($this->filters[$key])) {
                $conditions[] = $condition;
                $params[] = $this->filters[$key];
                $types .= $type;
            }
        }

        // Manejo especial para estado 'finalizada'
        if (!empty($this->filters['estado'])) {
            if ($this->filters['estado'] === 'finalizada') {
                $conditions[] = "pp.fecha_fin IS NOT NULL";
            } else {
                $conditions[] = "sp.estado = ?";
                $params[] = $this->filters['estado'];
                $types .= "s";
            }
        }

        return [
            'where' => implode(' AND ', $conditions),
            'params' => $params,
            'types' => $types
        ];
    }

    /**
     * Calcula KPIs globales del sistema
     */
    public function getKPIs(): array {
        $cacheKey = 'kpis_' . $this->getFilterHash();
        
        return $this->getCachedData($cacheKey, function() {
            $clause = $this->buildWhereClause();
            
            $query = "
    SELECT
        COUNT(*) AS total_solicitudes,
        COUNT(CASE WHEN sp.estado = 'pendiente' THEN 1 END) AS pendientes,
        COUNT(CASE WHEN sp.estado = 'iniciada' THEN 1 END) AS iniciadas,
        COUNT(CASE WHEN sp.estado = 'rechazada' THEN 1 END) AS rechazadas,
        COUNT(CASE WHEN pp.fecha_fin IS NOT NULL THEN 1 END) AS finalizadas,
        ROUND(AVG(TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, pp.fecha_inicio)), 1) AS avg_tiempo_respuesta_min,
        ROUND(AVG(CASE WHEN pp.fecha_fin IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, pp.fecha_inicio, pp.fecha_fin) END), 1) AS avg_tiempo_resolucion_min,
        ROUND(AVG(CASE WHEN pp.fecha_fin IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, pp.fecha_fin) END), 1) AS avg_ciclo_total_min,
        -- Disponibilidad: tiempo de paro desde apertura del ticket hasta cierre
        SUM(CASE WHEN pp.fecha_fin IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, pp.fecha_fin) ELSE 0 END) AS total_downtime_min,
        -- MTTR: pausa real desde pausas_paro (suma por ticket, tope en fecha_fin si no fue reanudada)
        SUM(COALESCE((
            SELECT SUM(TIMESTAMPDIFF(MINUTE, pa.fecha_pausa, COALESCE(pa.fecha_reanudacion, pp.fecha_fin)))
            FROM pausas_paro pa
            WHERE pa.id_paro = pp.id
              AND pa.fecha_pausa IS NOT NULL
              AND (pa.fecha_reanudacion IS NOT NULL OR pp.fecha_fin IS NOT NULL)
        ), 0)) AS total_pausa_min,
        COUNT(CASE WHEN pp.fecha_fin IS NOT NULL THEN 1 END) AS num_fallos
    FROM solicitudes_paro sp
    LEFT JOIN paro_produccion pp ON sp.id = pp.id_solicitud
    WHERE {$clause['where']}
";

            $result = $this->executeQuery($query, $clause['params'], 'single', $clause['types']);
            
            if (!$result) {
                return $this->getDefaultKPIs();
            }

            return $this->calculateDerivedKPIs($result);
        });
    }

/**
 * Calcula métricas derivadas de KPIs - FÓRMULAS CORREGIDAS
 */
private function calculateDerivedKPIs(array $data): array {
    // Cast string values to appropriate types from DB
    $data['avg_tiempo_respuesta_min'] = (float)($data['avg_tiempo_respuesta_min'] ?? 0);
    $data['avg_tiempo_resolucion_min'] = (float)($data['avg_tiempo_resolucion_min'] ?? 0);
    $data['avg_ciclo_total_min'] = (float)($data['avg_ciclo_total_min'] ?? 0);
    $data['total_downtime_min'] = (float)($data['total_downtime_min'] ?? 0);
    $data['num_fallos'] = (int)($data['num_fallos'] ?? 0);
    $data['total_solicitudes'] = (int)($data['total_solicitudes'] ?? 0);
    $data['pendientes'] = (int)($data['pendientes'] ?? 0);
    $data['iniciadas'] = (int)($data['iniciadas'] ?? 0);
    $data['rechazadas'] = (int)($data['rechazadas'] ?? 0);
    $data['finalizadas'] = (int)($data['finalizadas'] ?? 0);

    $numDias = $this->getNumDiasRango();
    
    // OBTENER EL NÚMERO TOTAL DE EQUIPOS ACTIVOS DURANTE EL PERÍODO
    $totalEquiposActivos = $this->getTotalEquiposActivos();
    
    // CÁLCULO CORREGIDO: Usar 24 horas por día (no minutos)
    $horasPorDia = 24; // Horas por día
    $totalHorasTeoricas = $totalEquiposActivos * $numDias * $horasPorDia;
    $totalMinutosTeoricos = $totalHorasTeoricas * 60; // Convertir a minutos para cálculos internos
    
    $totalDowntimeMin = $data['total_downtime_min']; // Ya está en minutos
    $totalDowntimeHoras = $totalDowntimeMin / 60; // Convertir a horas para mostrar
    $numFallos = $data['num_fallos'];
    
    // CÁLCULO CORRECTO: 
    // 1. Convertir downtime de minutos a horas
    // 2. Calcular tiempo operativo en horas
    $tiempoOperativoHoras = max($totalHorasTeoricas - $totalDowntimeHoras, 0);
    $tiempoOperativoMin = $tiempoOperativoHoras * 60; // Para compatibilidad con otros cálculos
    
    // Cálculo de disponibilidad CORREGIDO
    $availability = ($totalHorasTeoricas > 0) 
        ? max(min(round(($tiempoOperativoHoras / $totalHorasTeoricas) * 100, 2), 100), 0)
        : 100;
    
    // Cálculo MTBF y MTTR (solo para equipos con fallos)
    // MTBF = Tiempo Operativo Total (minutos) / Número de Fallos
    $mtbf = $numFallos > 0 ? round($tiempoOperativoMin / $numFallos) : $totalMinutosTeoricos;
    
    // MTTR = (Ciclo total ticket abierto→cerrado) - (tiempo en pausa) / Número de Fallos
    $totalPausaMin = (float)($data['total_pausa_min'] ?? 0);
    $mttr = $numFallos > 0 ? round(($totalDowntimeMin - $totalPausaMin) / $numFallos) : 0;
    
    // OEE = Disponibilidad × Rendimiento × Calidad
    $oee = $availability > 0 
        ? round($availability * self::OEE_RENDIMIENTO * self::OEE_CALIDAD, 2)
        : 0;
    
    // Tiempo disponible promedio por equipo por día (en horas)
    $tiempoDisponiblePromedioH = ($totalEquiposActivos > 0 && $numDias > 0)
        ? round($tiempoOperativoHoras / ($totalEquiposActivos * $numDias), 1)
        : 0;
    
    return array_merge($data, [
        'total_minutos_operativos' => $totalMinutosTeoricos,       // Tiempo teórico total en minutos
        'total_horas_operativas' => $totalHorasTeoricas,           // Tiempo teórico total en horas
        'tiempo_operativo_min' => $tiempoOperativoMin,            // Tiempo operativo real en minutos
        'tiempo_operativo_h' => $tiempoOperativoHoras,            // Tiempo operativo real en horas
        'tiempo_disponible_promedio_h' => $tiempoDisponiblePromedioH, // Horas disponibles promedio por equipo por día
        'mtbf_min' => $mtbf,
        'mttr_min' => $mttr,
        'availability' => $availability,
        'oee' => $oee,
        'total_equipos_activos_periodo' => $totalEquiposActivos,   // Número de equipos activos
        'dias_analizados' => $numDias,                            // Días en el período
        'total_downtime_h' => $totalDowntimeHoras                 // Downtime en horas para mostrar
    ]);
}

    /**
     * Obtiene número de días en el rango de fechas
     */
    private function getNumDiasRango(): int {
        $inicio = strtotime($this->filters['fecha_desde']);
        $fin = strtotime($this->filters['fecha_hasta']);
        return (int)(($fin - $inicio) / 86400) + 1;
    }

    /**
     * KPIs por defecto
     */
    public function getDefaultKPIs(): array {
        return [
            'total_solicitudes' => 0,
            'pendientes' => 0,
            'iniciadas' => 0,
            'finalizadas' => 0,
            'rechazadas' => 0,
            'avg_tiempo_respuesta_min' => 0.0,
            'avg_tiempo_resolucion_min' => 0.0,
            'avg_ciclo_total_min' => 0.0,
            'total_downtime_min' => 0.0,
            'num_fallos' => 0,
            'total_minutos_operativos' => 0,
            'tiempo_disponible_promedio_h' => 24.0,
            'mtbf_min' => 0.0,
            'mttr_min' => 0.0,
            'availability' => 100.0,
            'oee' => 100.0
        ];
    }

    /**
     * Obtiene métricas detalladas por equipo
     */
    public function getMetricasPorEquipo(): array {
        $cacheKey = 'metricas_equipo_' . $this->getFilterHash();
        
        return $this->getCachedData($cacheKey, function() {
            $numDias = $this->getNumDiasRango();
            $clause = $this->buildWhereClause();
            
            $query = "
    SELECT
        sp.equipo,
        sp.area,
        {$numDias} AS dias_operativos,
        COUNT(*) AS total_solicitudes_eq,
        COUNT(CASE WHEN pp.fecha_fin IS NOT NULL THEN 1 END) AS num_fallos_eq,
        -- Disponibilidad: tiempo de paro desde apertura del ticket hasta cierre
        SUM(CASE WHEN pp.fecha_fin IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, pp.fecha_fin) ELSE 0 END) AS total_downtime_min_eq,
        -- MTTR: pausa real desde pausas_paro
        SUM(COALESCE((
            SELECT SUM(TIMESTAMPDIFF(MINUTE, pa.fecha_pausa, COALESCE(pa.fecha_reanudacion, pp.fecha_fin)))
            FROM pausas_paro pa
            WHERE pa.id_paro = pp.id
              AND pa.fecha_pausa IS NOT NULL
              AND (pa.fecha_reanudacion IS NOT NULL OR pp.fecha_fin IS NOT NULL)
        ), 0)) AS total_pausa_min_eq,
        ROUND(AVG(TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, pp.fecha_inicio)), 1) AS avg_tiempo_respuesta_min_eq,
        ROUND(AVG(CASE WHEN pp.fecha_fin IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, pp.fecha_inicio, pp.fecha_fin) END), 1) AS avg_tiempo_resolucion_min_eq,
        ROUND(AVG(CASE WHEN pp.fecha_fin IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, pp.fecha_fin) END), 1) AS avg_ciclo_total_min_eq
    FROM solicitudes_paro sp
    LEFT JOIN paro_produccion pp ON sp.id = pp.id_solicitud
    WHERE {$clause['where']}
    GROUP BY sp.equipo, sp.area
    ORDER BY total_downtime_min_eq DESC
    LIMIT " . MAX_EQUIPOS_LIMIT . "
";

            $metricas = $this->executeQuery($query, $clause['params'], 'all', $clause['types']);
            
            return array_map([$this, 'calculateMetricasEquipo'], $metricas);
        });
    }

    /**
     * Calcula métricas derivadas por equipo - FÓRMULAS CORREGIDAS
     */
private function calculateMetricasEquipo(array $eq): array {
    // Cast string values to appropriate types from DB
    $eq['avg_tiempo_respuesta_min_eq'] = (float)($eq['avg_tiempo_respuesta_min_eq'] ?? 0);
    $eq['avg_tiempo_resolucion_min_eq'] = (float)($eq['avg_tiempo_resolucion_min_eq'] ?? 0);
    $eq['avg_ciclo_total_min_eq'] = (float)($eq['avg_ciclo_total_min_eq'] ?? 0);
    $eq['total_downtime_min_eq'] = (float)($eq['total_downtime_min_eq'] ?? 0);
    $eq['num_fallos_eq'] = (int)($eq['num_fallos_eq'] ?? 0);
    $eq['dias_operativos'] = (int)($eq['dias_operativos'] ?? 0);
    $eq['total_solicitudes_eq'] = (int)($eq['total_solicitudes_eq'] ?? 0);

    // CORRECCIÓN: Usar 24 horas por día
    $horasPorDia = 24;
    $totalHorasDia = $horasPorDia * $eq['dias_operativos'];
    $totalMinutosDia = $totalHorasDia * 60;
    
    // Downtime en minutos del query
    $downtimeMin = $eq['total_downtime_min_eq'];
    $downtimeHoras = $downtimeMin / 60;
    $numFallos = $eq['num_fallos_eq'];
    
    // CÁLCULO CORREGIDO: 
    $tiempoOperativoHoras = max($totalHorasDia - $downtimeHoras, 0);
    $tiempoOperativoMin = $tiempoOperativoHoras * 60;
    
    // Asegurar que la disponibilidad esté entre 0% y 100%
    $disponibilidad = ($totalHorasDia > 0) 
        ? max(min(round(($tiempoOperativoHoras / $totalHorasDia) * 100, 2), 100), 0)
        : 100;
    
    // Cálculo MTBF y MTTR (solo para equipos con fallos)
    $mtbf = $numFallos > 0 ? round($tiempoOperativoMin / $numFallos) : $totalMinutosDia;
    // MTTR = (Ciclo total ticket abierto→cerrado) - (tiempo en pausa) / Número de Fallos
    $pausaMin = (float)($eq['total_pausa_min_eq'] ?? 0);
    $mttr = $numFallos > 0 ? round(($downtimeMin - $pausaMin) / $numFallos) : 0;
    
    // Calcular OEE solo si disponibilidad es positiva
    $oee = $disponibilidad > 0 
        ? round($disponibilidad * self::OEE_RENDIMIENTO * self::OEE_CALIDAD, 2)
        : 0;
    
    return array_merge($eq, [
        'tiempo_disponible_min_eq' => $tiempoOperativoMin,
        'tiempo_disponible_h_eq' => round($tiempoOperativoHoras, 1),
        'mtbf_min_eq' => $mtbf,
        'mttr_min_eq' => $mttr,
        'disponibilidad_pct_eq' => $disponibilidad,
        'oee_eq' => $oee
    ]);
}

    /**
     * Calcula disponibilidad promedio por equipo (incluyendo TODOS los equipos activos)
     * Nuevo método corregido
     */
    public function getDisponibilidadPromedioEquipos(): array {
        $cacheKey = 'disponibilidad_promedio_equipos_' . $this->getFilterHash();
        
        return $this->getCachedData($cacheKey, function() {
            // 1. Obtener todos los equipos activos
            $queryEquipos = "
                SELECT e.id, e.nombre_equipo, a.area 
                FROM equipos e 
                JOIN areas a ON e.area_id = a.id 
                WHERE e.activo = 1
                ORDER BY e.nombre_equipo
            ";
            
            $todosEquipos = $this->executeQuery($queryEquipos, [], 'all');
            
            if (empty($todosEquipos)) {
                return [
                    'promedio' => 100.0,
                    'total_equipos' => 0,
                    'equipos_con_paros' => 0,
                    'equipos_sin_paros' => 0
                ];
            }
            
            // 2. Obtener métricas de equipos con paros (ya calculadas)
            $metricasEquiposConParos = $this->getMetricasPorEquipo();
            
            // Crear mapa rápido de equipos con paros
            $mapaEquiposConParos = [];
            foreach ($metricasEquiposConParos as $equipoConParos) {
                $mapaEquiposConParos[$equipoConParos['equipo']] = $equipoConParos['disponibilidad_pct_eq'];
            }
            
            // 3. Calcular suma total de disponibilidades
            $sumDisponibilidadTotal = 0;
            $equiposConParosCount = 0;
            $equiposSinParosCount = 0;
            
            foreach ($todosEquipos as $equipo) {
                $nombreEquipo = $equipo['nombre_equipo'];
                
                if (isset($mapaEquiposConParos[$nombreEquipo])) {
                    // Equipo tiene paros en el período
                    $sumDisponibilidadTotal += $mapaEquiposConParos[$nombreEquipo];
                    $equiposConParosCount++;
                } else {
                    // Equipo NO tiene paros (disponibilidad 100%)
                    $sumDisponibilidadTotal += 100.0;
                    $equiposSinParosCount++;
                }
            }
            
            // 4. Calcular promedio
            $totalEquipos = count($todosEquipos);
            $promedio = $totalEquipos > 0 
                ? round($sumDisponibilidadTotal / $totalEquipos, 2)
                : 100.0;
            
            return [
                'promedio' => $promedio,
                'total_equipos' => $totalEquipos,
                'equipos_con_paros' => $equiposConParosCount,
                'equipos_sin_paros' => $equiposSinParosCount
            ];
        });
    }

    /**
     * Obtiene datos para gráficos temporales
     */
    public function getGraficosData(): array {
        $cacheKey = 'graficos_' . $this->getFilterHash();
        
        return $this->getCachedData($cacheKey, function() {
            $query = "
    SELECT
        DATE(sp.fecha_solicitud) AS fecha,
        COUNT(*) AS total_diario,
        COUNT(CASE WHEN pp.fecha_fin IS NOT NULL THEN 1 END) AS finalizadas_diarias,
        SUM(CASE WHEN pp.fecha_fin IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, pp.fecha_inicio, pp.fecha_fin) ELSE 0 END) AS downtime_diario_min
    FROM solicitudes_paro sp
    LEFT JOIN paro_produccion pp ON sp.id = pp.id_solicitud
    WHERE DATE(sp.fecha_solicitud) BETWEEN ? AND ?
    AND sp.tipo_paro != 'Sin WIP'  -- EXCLUIR Sin WIP también en gráficos
    GROUP BY DATE(sp.fecha_solicitud)
    ORDER BY fecha ASC
";

            $datos = $this->executeQuery(
                $query, 
                [$this->filters['fecha_desde'], $this->filters['fecha_hasta']], 
                'all',
                'ss'
            );

            return $this->procesarDatosGraficos($datos);
        });
    }

    /**
     * Procesa datos de gráficos rellenando días faltantes
     */
    private function procesarDatosGraficos(array $datos): array {
        $grafData = [];
        $current = new DateTime($this->filters['fecha_desde']);
        $end = new DateTime($this->filters['fecha_hasta']);

        // Inicializar todos los días
        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $grafData[$dateStr] = ['total' => 0, 'finalizadas' => 0, 'downtime' => 0];
            $current->modify('+1 day');
        }

        // Rellenar con datos reales
        foreach ($datos as $dia) {
            $grafData[$dia['fecha']] = [
                'total' => (int)$dia['total_diario'],
                'finalizadas' => (int)$dia['finalizadas_diarias'],
                'downtime' => (float)$dia['downtime_diario_min']
            ];
        }

        // Formatear para gráficos
        $labels = [];
        $datosTotal = [];
        $datosFinalizadas = [];
        $datosDowntime = [];

        foreach ($grafData as $date => $data) {
            $labels[] = date('d/m', strtotime($date));
            $datosTotal[] = $data['total'];
            $datosFinalizadas[] = $data['finalizadas'];
            $datosDowntime[] = round($data['downtime'] / 60, 2); // Convertir a horas
        }

        return [
            'labels_grafico' => $labels,
            'datos_total' => $datosTotal,
            'datos_finalizadas' => $datosFinalizadas,
            'datos_downtime' => $datosDowntime
        ];
    }

    /**
     * Obtiene datos para gráfico de equipos (top 5 por downtime)
     */
    public function getDatosEquipoGrafico(): array {
        $clause = $this->buildWhereClause();
        
        $query = "
    SELECT 
        sp.equipo, 
        SUM(CASE WHEN pp.fecha_fin IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, pp.fecha_inicio, pp.fecha_fin) ELSE 0 END) AS downtime_min
    FROM solicitudes_paro sp
    LEFT JOIN paro_produccion pp ON sp.id = pp.id_solicitud
    WHERE {$clause['where']}
    GROUP BY sp.equipo
    ORDER BY downtime_min DESC
    LIMIT 5
";

        $datos = $this->executeQuery($query, $clause['params'], 'all', $clause['types']);

        return [
            'labels' => array_column($datos, 'equipo'),
            'data' => array_column($datos, 'downtime_min')
        ];
    }

    /**
     * Ejecuta query con prepared statements
     */
    private function executeQuery(
        string $query, 
        array $params = [], 
        string $fetchType = 'all',
        string $types = ''
    ): array|null {
        $stmt = $this->conn->prepare($query);
        
        if (!$stmt) {
            $this->logError("Error preparando query: {$this->conn->error}");
            return $fetchType === 'all' ? [] : null;
        }

        if (!empty($params)) {
            if (!$stmt->bind_param($types, ...$params)) {
                $this->logError("Error en bind_param: {$stmt->error}");
                $stmt->close();
                return $fetchType === 'all' ? [] : null;
            }
        }

        if (!$stmt->execute()) {
            $this->logError("Error ejecutando query: {$stmt->error}");
            $stmt->close();
            return $fetchType === 'all' ? [] : null;
        }

        $result = $stmt->get_result();
        $data = $fetchType === 'single' 
            ? $result->fetch_assoc() 
            : $result->fetch_all(MYSQLI_ASSOC);
        
        $stmt->close();
        
        return $data ?? ($fetchType === 'all' ? [] : null);
    }

    /**
     * Sistema de caché simple
     */
    private function getCachedData(string $key, callable $callback): mixed {
        if (CACHE_ENABLED && isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $data = $callback();
        
        if (CACHE_ENABLED) {
            $this->cache[$key] = $data;
        }

        return $data;
    }

    /**
     * Genera hash único para filtros actuales
     */
    private function getFilterHash(): string {
        return md5(serialize($this->filters));
    }

    /**
     * Registra errores
     */
    private function logError(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "{$timestamp}: {$message}";
        
        if (DEBUG) {
            $this->errorLog[] = $logMessage;
        }
        
        error_log($logMessage);
    }

    /**
     * Obtiene log de errores
     */
    public function getErrors(): array {
        return $this->errorLog;
    }
}

// ============================================================================
// INICIALIZACIÓN Y OBTENCIÓN DE DATOS
// ============================================================================

try {
    $dashboard = new DashboardManager($conn);
    
    // Obtener datos necesarios
    $filters = $dashboard->getFilters();
    $areasLi = $dashboard->getAreasList();
    $equiposList = $dashboard->getEquiposList();
    $estadosList = $dashboard->getEstadosList();
    $tiposParoList = $dashboard->getTiposParoList();
    
// KPIs y métricas
$kpis = $dashboard->getKPIs();
$metricasEquipos = $dashboard->getMetricasPorEquipo();

// CÁLCULO CORREGIDO: Disponibilidad promedio por equipo (incluyendo TODOS los equipos)
$disponibilidadPromedioData = $dashboard->getDisponibilidadPromedioEquipos();
$avgDisponibilidadEquipos = $disponibilidadPromedioData['promedio'];
$numEquiposActivos = $disponibilidadPromedioData['total_equipos'];
$equiposConParos = $disponibilidadPromedioData['equipos_con_paros'];
$equiposSinParos = $disponibilidadPromedioData['equipos_sin_paros'];

// ============================================================================
// CÁLCULO ADICIONAL: PORCENTAJE DE FALLOS
// ============================================================================
$totalSolicitudes = $kpis['total_solicitudes'];
$totalFallos = $kpis['num_fallos'];
$porcentajeFallos = ($totalSolicitudes > 0) ? round(($totalFallos / $totalSolicitudes) * 100, 2) : 0;

// AÑADE ESTA LÍNEA: Calcular número de días una sola vez
$numDiasAnalizados = (int)((strtotime($filters['fecha_hasta']) - strtotime($filters['fecha_desde'])) / 86400) + 1;

// Datos para gráficos
$graficosData = $dashboard->getGraficosData();
$datosEquipoGraf = $dashboard->getDatosEquipoGrafico();

$errors = $dashboard->getErrors();
    
} catch (Exception $e) {
    error_log("Error crítico inicializando dashboard: {$e->getMessage()}");
    $kpis = [
        'total_solicitudes' => 0,
        'pendientes' => 0,
        'iniciadas' => 0,
        'finalizadas' => 0,
        'rechazadas' => 0,
        'avg_tiempo_respuesta_min' => 0.0,
        'avg_tiempo_resolucion_min' => 0.0,
        'avg_ciclo_total_min' => 0.0,
        'total_downtime_min' => 0.0,
        'num_fallos' => 0,
        'total_minutos_operativos' => 0,
        'tiempo_disponible_promedio_h' => 24.0,
        'mtbf_min' => 0.0,
        'mttr_min' => 0.0,
        'availability' => 100.0,
        'oee' => 100.0
    ];
    $metricasEquipos = [];
    $graficosData = ['labels_grafico' => [], 'datos_total' => [], 'datos_finalizadas' => [], 'datos_downtime' => []];
    $datosEquipoGraf = ['labels' => [], 'data' => []];
    $avgDisponibilidadEquipos = 100.0;
    $numEquiposActivos = 0;
    $equiposConParos = 0;
    $equiposSinParos = 0;
}

// ============================================================================
// FUNCIONES DE UTILIDAD PARA TEMPLATES
// ============================================================================

function formatNumber(float $number, int $decimals = 1): string {
    return number_format($number, $decimals, '.', ',');
}

function getDisponibilidadClass(float $disponibilidad): string {
    if ($disponibilidad > 95) return 'badge-success';
    if ($disponibilidad < 80) return 'badge-danger';
    return 'badge-warning';
}

function getDisponibilidadGradient(float $disponibilidad): string {
    if ($disponibilidad > 95) return 'var(--success-gradient)';
    if ($disponibilidad < 80) return 'var(--danger-gradient)';
    return 'var(--warning-gradient)';
}

function escapeHtml(?string $text): string {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Dashboard Optimizado - KPIs Paros de Producción: MTTR/MTBF/Disponibilidad/OEE con Modal Detalle">
    <title>Dashboard Equipos Pro - Gestión Paros</title>
    
    <!-- Estilos externos -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #66ea92ff 0%, #4ba252ff 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --success-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --warning-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            --shadow: 0 2px 10px rgba(0,0,0,0.1);
            --radius: 8px;
            --spacing: 1rem;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            color: #2d3748;
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
            min-height: 100vh;
        }

        header {
            background: var(--primary-gradient);
            color: white;
            padding: 2rem var(--spacing);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }

        .header-left {
            flex: 1 1 60%;
            min-width: 260px;
        }

        .header-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
            justify-content: flex-end;
            min-width: 220px;
        }

        header h1 {
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        header p {
            font-size: 1.1rem;
            opacity: 0.95;
            margin-bottom: 1rem;
        }

        #realTimeClock {
            font-size: 1rem;
            font-weight: 500;
            opacity: 0.9;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 var(--spacing);
        }

        /* Filtros */
        .filters {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: calc(var(--spacing) * 2);
        }

        .filters form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing);
            align-items: end;
        }

        .filters input,
        .filters select,
        .filters button {
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.3s;
        }

        .filters input:focus,
        .filters select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Botones */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            background: var(--primary-gradient);
            color: white;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .btn:active {
            transform: translateY(0);
        }

        /* Grid de KPIs */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: var(--spacing);
            margin-bottom: calc(var(--spacing) * 2);
        }

        .kpi-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }

        .kpi-icon {
            font-size: 2.5rem;
            color: #667eea;
            margin-bottom: 0.5rem;
        }

        .kpi-value {
            font-size: clamp(1.5rem, 3vw, 2rem);
            font-weight: 700;
            color: #2d3748;
            margin: 0.5rem 0;
        }

        .kpi-label {
            color: #718096;
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Grid de gráficos */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: var(--spacing);
            margin-bottom: calc(var(--spacing) * 2);
        }

        .chart-container {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .chart-container h3 {
            margin-bottom: 1rem;
            color: #2d3748;
            font-size: 1.2rem;
        }

        .chart-container canvas {
            max-height: 300px;
        }

        /* Tablas */
        .table-container {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow-x: auto;
            margin-bottom: calc(var(--spacing) * 2);
        }

        .table-container h3 {
            margin-bottom: 1rem;
            color: #2d3748;
        }

        .table-container .btn {
            margin-bottom: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        th, td {
            padding: 0.875rem;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #f7fafc;
            font-weight: 600;
            color: #4a5568;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        tbody tr:hover {
            background: #f7fafc;
        }

        /* Barra de progreso */
        .progress-bar {
            background: #e2e8f0;
            border-radius: 4px;
            height: 24px;
            position: relative;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* Badges */
        .badge {
            padding: 0.35rem 0.75rem;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success {
            background: #48bb78;
            color: white;
        }

        .badge-warning {
            background: #ed8936;
            color: white;
        }

        .badge-danger {
            background: #f56565;
            color: white;
        }

        /* Footer */
        footer {
            text-align: center;
            padding: 2rem var(--spacing);
            background: #2d3748;
            color: white;
            margin-top: calc(var(--spacing) * 2);
        }

        footer div:first-child {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        footer div:last-child {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .kpi-grid,
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .filters form {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 0.8rem;
            }

            th, td {
                padding: 0.5rem;
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

        .kpi-card,
        .chart-container,
        .table-container {
            animation: fadeIn 0.5s ease-out;
        }

        /* Estados vacíos */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #718096;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* ============ MODAL DETALLE EQUIPO ============ */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s;
        }

        .modal-content {
            background-color: white;
            margin: 3% auto;
            padding: 0;
            border-radius: var(--radius);
            width: 95%;
            max-width: 1200px;
            max-height: 90vh;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 1.5rem;
            background: var(--primary-gradient);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }

        .close-modal {
            color: white;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-modal:hover {
            background: rgba(255,255,255,0.2);
        }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            flex: 1;
        }

        #detalleEquipoTable {
            width: 100%;
            font-size: 0.9rem;
        }

        #detalleEquipoTable th {
            background: #f7fafc;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        #loadingDetalle {
            text-align: center;
            padding: 3rem;
            color: #718096;
            font-size: 1.1rem;
        }

        #emptyDetalle {
            text-align: center;
            padding: 3rem;
            color: #a0aec0;
        }

        #emptyDetalle i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        /* Filas clickeables */
        .clickable-row {
            cursor: pointer;
            transition: background 0.2s;
        }

        .clickable-row:hover {
            background: #f0f4ff !important;
        }

        /* Info adicional sobre filtro Sin WIP */
        .info-badge {
            background: #e2e8f0;
            color: #4a5568;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            font-size: 0.85rem;
            margin-bottom: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
    </style>
</head>
<body>
    <header>
        <div class="header-left">
            <h1><i class="fas fa-cogs"></i> Dashboard Optimizado - Gestión de Paros de Producción</h1>
            <p>Análisis integral de equipos, tiempos de respuesta y disponibilidad operativa"</p>
            <div id="realTimeClock"></div>
        </div>
        <div class="header-actions">
            <a href="login_admin.php" class="btn" style="background: rgba(255,255,255,0.15); color: white;">
                <i class="fas fa-sign-out-alt"></i> Cerrar sesión
            </a>
        </div>
    </header>

    <div class="container">
        <!-- Sección de Filtros -->
        <section class="filters">
            <div class="info-badge">
                <i class="fas fa-info-circle"></i>
                <strong>Nota:</strong> Los datos excluyen automáticamente los paros de tipo "Sin WIP"
            </div>

            <form method="GET" id="advancedFilterForm" aria-label="Filtros de dashboard">
                <div>
                    <label for="fecha_desde" style="display: block; margin-bottom: 0.25rem; font-weight: 500; font-size: 0.9rem;">Fecha Desde</label>
                    <input type="date" name="fecha_desde" id="fecha_desde" value="<?= escapeHtml($filters['fecha_desde']) ?>" required>
                </div>
                
                <div>
                    <label for="fecha_hasta" style="display: block; margin-bottom: 0.25rem; font-weight: 500; font-size: 0.9rem;">Fecha Hasta</label>
                    <input type="date" name="fecha_hasta" id="fecha_hasta" value="<?= escapeHtml($filters['fecha_hasta']) ?>" required>
                </div>
                
                <div>
                    <label for="equipo" style="display: block; margin-bottom: 0.25rem; font-weight: 500; font-size: 0.9rem;">Equipo</label>
                    <select name="equipo" id="equipo">
                        <option value="">Todos los Equipos</option>
                        <?php foreach ($equiposList as $eq): ?>
                            <option value="<?= escapeHtml($eq['nombre_equipo']) ?>" <?= $filters['equipo'] === $eq['nombre_equipo'] ? 'selected' : '' ?>>
                                <?= escapeHtml($eq['nombre_equipo']) ?> (<?= escapeHtml($eq['area']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="estado" style="display: block; margin-bottom: 0.25rem; font-weight: 500; font-size: 0.9rem;">Estado</label>
                    <select name="estado" id="estado">
                        <option value="">Todos los Estados</option>
                        <?php foreach ($estadosList as $est): ?>
                            <option value="<?= escapeHtml($est) ?>" <?= $filters['estado'] === $est ? 'selected' : '' ?>>
                                <?= ucfirst(escapeHtml($est)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="tipo_paro" style="display: block; margin-bottom: 0.25rem; font-weight: 500; font-size: 0.9rem;">Tipo de Paro</label>
                    <select name="tipo_paro" id="tipo_paro">
                        <option value="">Todos los Tipos</option>
                        <?php foreach ($tiposParoList as $tipo): ?>
                            <option value="<?= (int)$tipo['id'] ?>" <?= $filters['tipo_paro'] == $tipo['id'] ? 'selected' : '' ?>>
                                <?= escapeHtml($tipo['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="display: flex; align-items: end;">
                    <button type="submit" class="btn">
                        <i class="fas fa-search"></i> Filtrar Datos
                    </button>
                </div>
            </form>

            <!-- Enlace a dashboard_admin_paros.php  -->
            <div style="text-align: center; margin: 20px 0;">
                <a href="admin_solicitudes_paros.php" class="btn" style="background: var(--secondary-gradient);">
                    <i class="fas fa-list"></i> Ver Todas las Solicitudes de Paros
                </a>
            </div>

            <div style="text-align: center; margin: 20px 0;">
    <a href="admin_crud_panel.php" class="btn" style="background: linear-gradient(135deg, #667eea, #764ba2);">
        <i class="fas fa-cogs"></i> Panel de Administración CRUD
    </a>
</div>
        </section>

        <!-- KPIs Globales -->
        <section class="kpi-grid">
            <div class="kpi-card">
                <i class="fas fa-stopwatch kpi-icon"></i>
                <div class="kpi-value"><?= formatNumber($kpis['avg_tiempo_respuesta_min']) ?> min</div>
                <div class="kpi-label">T. Promedio Solicitud-Respuesta</div>
            </div>
            
            <div class="kpi-card">
                <i class="fas fa-tools kpi-icon"></i>
                <div class="kpi-value"><?= formatNumber($kpis['avg_tiempo_resolucion_min']) ?> min</div>
                <div class="kpi-label">T. Promedio Respuesta-Fin Paro</div>
            </div>
            
            <div class="kpi-card">
                <i class="fas fa-clock kpi-icon"></i>
                <div class="kpi-value"><?= formatNumber($kpis['tiempo_disponible_promedio_h']) ?> h</div>
                <div class="kpi-label">Tiempo Disponible Promedio (24h/día)</div>
            </div>
            
            <div class="kpi-card">
                <i class="fas fa-chart-line kpi-icon"></i>
                <div class="kpi-value"><?= formatNumber($kpis['mttr_min']) ?> min</div>
                <div class="kpi-label">MTTR (Tiempo Medio de Reparación)</div>
            </div>
            
            <div class="kpi-card">
                <i class="fas fa-sync-alt kpi-icon"></i>
                <div class="kpi-value"><?= formatNumber($kpis['mtbf_min']) ?> min</div>
                <div class="kpi-label">MTBF (Tiempo Medio Entre Fallos)</div>
            </div>

<div class="kpi-card" title="Equipos activos: <?= $numEquiposActivos ?>
Equipos con paros: <?= $equiposConParos ?> (<?= formatNumber(($equiposConParos / $numEquiposActivos) * 100, 1) ?>%)
Equipos sin paros: <?= $equiposSinParos ?> (<?= formatNumber(($equiposSinParos / $numEquiposActivos) * 100, 1) ?>%)">
    <i class="fas fa-percentage kpi-icon"></i>
    <div class="kpi-value"><?= formatNumber($avgDisponibilidadEquipos, 2) ?>%</div>
    <div class="kpi-label">Disponibilidad Promedio por Equipo</div>
    <div style="font-size: 0.85rem; margin-top: 0.5rem; color: #718096; line-height: 1.4;">
        <?= $numEquiposActivos ?> equipos activos<br>
        <?= $equiposConParos ?> con paros (<?= $numEquiposActivos > 0 ? formatNumber(($equiposConParos / $numEquiposActivos) * 100, 1) : 0 ?>%)<br>
        <?= $equiposSinParos ?> sin paros (<?= $numEquiposActivos > 0 ? formatNumber(($equiposSinParos / $numEquiposActivos) * 100, 1) : 0 ?>%)
    </div>
</div>
            
<!-- KPI 1: TOTAL DE SOLICITUDES -->
<div class="kpi-card">
    <i class="fas fa-clipboard-list kpi-icon"></i>
    <div class="kpi-value"><?= $totalSolicitudes ?></div>
    <div class="kpi-label">Total de Solicitudes</div>
    <div style="font-size: 0.85rem; margin-top: 0.25rem; color: #718096;">
        Solicitudes recibidas en el período
    </div>
</div>

<!-- KPI 2: TOTAL DE PAROS FINALIZADOS -->
<div class="kpi-card">
    <i class="fas fa-tools kpi-icon"></i>
    <div class="kpi-value"><?= $kpis['num_fallos'] ?></div>
    <div class="kpi-label">Paros Finalizados</div>
    <div style="font-size: 0.85rem; margin-top: 0.25rem; color: #718096;">
        Paros atendidos y completados
    </div>
</div>

<!-- KPI 3: EFICIENCIA DE DETECCIÓN -->
<div class="kpi-card">
    <i class="fas fa-percentage kpi-icon"></i>
    <div class="kpi-value"><?= formatNumber($porcentajeFallos, 2) ?>%</div>
    <div class="kpi-label">Solicitudes que requirieron Paro</div>
    <div style="font-size: 0.85rem; margin-top: 0.25rem; color: #718096;">
        <?= $kpis['num_fallos'] ?> de <?= $totalSolicitudes ?> solicitudes<br>
        <small><i>Paros finalizados vs total solicitudes</i></small>
    </div>
</div>

<!-- KPI 4: SOLICITUDES SIN PARO (OPCIONAL) -->
<div class="kpi-card">
    <i class="fas fa-times-circle kpi-icon"></i>
    <div class="kpi-value"><?= $totalSolicitudes - $kpis['num_fallos'] ?></div>
    <div class="kpi-label">Solicitudes sin Paro</div>
    <div style="font-size: 0.85rem; margin-top: 0.25rem; color: #718096;">
        Rechazadas, canceladas o no requirieron acción
    </div>
</div>
        </section>

        <!-- Gráficos -->
        <section class="charts-grid">
            <div class="chart-container">
                <h3><i class="fas fa-chart-area"></i> Evolución de Solicitudes y Downtime</h3>
                <canvas id="chartSolicitudes"></canvas>
            </div>
            
            <div class="chart-container">
                <h3><i class="fas fa-chart-bar"></i> Downtime por Equipo (Top 5)</h3>
                <canvas id="chartEquipoDowntime"></canvas>
            </div>
        </section>

        <!-- Tabla Principal: Métricas por Equipo -->
        <section class="table-container">
            <h3><i class="fas fa-table"></i> Métricas Detalladas por Equipo</h3>
            <p style="color: #718096; margin-bottom: 1rem; font-size: 0.9rem;">
                Período: <?= date('d/m/Y', strtotime($filters['fecha_desde'])) ?> - <?= date('d/m/Y', strtotime($filters['fecha_hasta'])) ?>
                | <strong>Excluye:</strong> Paros tipo "Sin WIP"
            </p>
            
            <button class="btn" onclick="exportTableToCSV('equipos-table', 'metricas_equipos')">
                <i class="fas fa-download"></i> Exportar a CSV
            </button>
            
            <?php if (empty($metricasEquipos)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No hay datos disponibles para el período y filtros seleccionados.</p>
                </div>
            <?php else: ?>
                <table id="equipos-table">
                    <thead>
                        <tr>
                            <th>Área</th>
                            <th>Equipo</th>
                            <th>Solicitudes</th>
                            <th>Fallos</th>
                            <th>MTTR (min)</th>
                            <th>MTBF (min)</th>
                            <th>Disponibilidad (%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($metricasEquipos as $eq): ?>
                            <tr class="clickable-row" onclick="abrirDetalleEquipo('<?= escapeHtml($eq['equipo']) ?>', '<?= escapeHtml($eq['area']) ?>')" title="Haga clic para ver el detalle completo de paros">
                                <td><?= escapeHtml($eq['area']) ?></td>
                                <td><strong><?= escapeHtml($eq['equipo']) ?></strong></td>
                                <td><?= $eq['total_solicitudes_eq'] ?></td>
                                <td><?= $eq['num_fallos_eq'] ?></td>
                                <td><?= formatNumber($eq['mttr_min_eq']) ?></td>
                                <td><?= formatNumber($eq['mtbf_min_eq']) ?></td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-fill" 
                                             style="width: <?= $eq['disponibilidad_pct_eq'] ?>%; background: <?= getDisponibilidadGradient($eq['disponibilidad_pct_eq']) ?>;">
                                            <?= formatNumber($eq['disponibilidad_pct_eq'], 2) ?>%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>

    <footer>
        <div>Dashboard Equipos - Análisis de Tiempos y Disponibilidad Operativa © <?= date("Y") ?></div>
        <div>Desarrollado por Nestor Rosales | Todos Los Derechos Reservados</div>
    </footer>

    <!-- Modal Detalle de Paros por Equipo -->
    <div id="modalDetalleEquipo" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-wrench"></i> Detalle de Paros - <span id="modalEquipoNombre">Equipo</span></h2>
                <button class="close-modal" onclick="cerrarModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 1rem; text-align: right;">
                    <button class="btn" onclick="exportarDetalleCSV()" id="btnExportarDetalle" style="display: none;">
                        <i class="fas fa-download"></i> Exportar a CSV
                    </button>
                </div>

                <div id="loadingDetalle">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i><br><br>
                    Cargando detalles del equipo...
                </div>

                <div id="emptyDetalle" style="display: none;">
                    <i class="fas fa-inbox"></i>
                    <p>No se encontraron registros de paros para este equipo en el período seleccionado.</p>
                </div>

                <table id="detalleEquipoTable" style="display: none;">
                    <thead>
                        <tr>
                            <th>Fecha Solicitud</th>
                            <th>Tipo de Paro</th>
                            <th>Estado</th>
                            <th>Inicio</th>
                            <th>Fin</th>
                            <th>T. Respuesta</th>
                            <th>T. Resolución</th>
                            <th>T. Pausa</th>
                            <th>Técnico</th>
                            <th>Descripción</th>
                            <th>Motivo Pausas</th>
                            <th>Solución</th>
                        </tr>
                    </thead>
                    <tbody id="detalleEquipoBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Configuración global de Chart.js
        Chart.defaults.color = '#2d3748';
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.plugins.legend.display = true;
        Chart.defaults.plugins.legend.position = 'top';

        // Gráfico de Evolución de Solicitudes
        const ctxSolicitudes = document.getElementById('chartSolicitudes');
        if (ctxSolicitudes && <?= json_encode($graficosData['labels_grafico']) ?>.length > 0) {
            new Chart(ctxSolicitudes, {
                type: 'line',
                data: {
                    labels: <?= json_encode($graficosData['labels_grafico']) ?>,
                    datasets: [
                        {
                            label: 'Total Solicitudes',
                            data: <?= json_encode($graficosData['datos_total']) ?>,
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2
                        },
                        {
                            label: 'Finalizadas',
                            data: <?= json_encode($graficosData['datos_finalizadas']) ?>,
                            borderColor: '#48bb78',
                            backgroundColor: 'rgba(72, 187, 120, 0.1)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2
                        },
                        {
                            label: 'Downtime (horas)',
                            data: <?= json_encode($graficosData['datos_downtime']) ?>,
                            borderColor: '#f56565',
                            backgroundColor: 'rgba(245, 101, 101, 0.1)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Cantidad de Solicitudes'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Downtime (horas)'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: {
                                size: 14
                            },
                            bodyFont: {
                                size: 13
                            }
                        }
                    }
                }
            });
        }

        // Gráfico de Downtime por Equipo
        const ctxEquipoDowntime = document.getElementById('chartEquipoDowntime');
        if (ctxEquipoDowntime && <?= json_encode($datosEquipoGraf['labels']) ?>.length > 0) {
            new Chart(ctxEquipoDowntime, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($datosEquipoGraf['labels']) ?>,
                    datasets: [{
                        label: 'Downtime (minutos)',
                        data: <?= json_encode($datosEquipoGraf['data']) ?>,
                        backgroundColor: [
                            'rgba(245, 101, 101, 0.8)',
                            'rgba(237, 137, 54, 0.8)',
                            'rgba(250, 112, 154, 0.8)',
                            'rgba(102, 126, 234, 0.8)',
                            'rgba(118, 75, 162, 0.8)'
                        ],
                        borderColor: [
                            '#f56565',
                            '#ed8936',
                            '#fa709a',
                            '#667eea',
                            '#764ba2'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Tiempo de Inactividad (min)'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            callbacks: {
                                label: function(context) {
                                    const minutes = context.parsed.y;
                                    const hours = (minutes / 60).toFixed(2);
                                    return `Downtime: ${minutes} min (${hours} h)`;
                                }
                            }
                        }
                    }
                }
            });
        }

        // === MODAL Y DETALLE POR EQUIPO ===
        let datosDetalleEquipo = [];

        // Abrir modal con detalle del equipo
        function abrirDetalleEquipo(nombreEquipo, area) {
            const modal = document.getElementById('modalDetalleEquipo');
            document.getElementById('modalEquipoNombre').textContent = `${nombreEquipo} (${area})`;
            document.getElementById('loadingDetalle').style.display = 'block';
            document.getElementById('detalleEquipoTable').style.display = 'none';
            document.getElementById('emptyDetalle').style.display = 'none';
            document.getElementById('btnExportarDetalle').style.display = 'none';
            modal.style.display = 'block';

            // Obtener filtros actuales del formulario
            const params = new URLSearchParams(new FormData(document.getElementById('advancedFilterForm')));
            params.set('equipo', nombreEquipo); // Forzar el equipo

            fetch(`ajax_detalle_equipo.php?${params.toString()}`)
                .then(res => res.json())
                .then(data => {
                    document.getElementById('loadingDetalle').style.display = 'none';

                    if (!data.success || data.data.length === 0) {
                        document.getElementById('emptyDetalle').style.display = 'block';
                        return;
                    }

                    datosDetalleEquipo = data.data;
                    renderizarDetalleEquipo(data.data);
                    document.getElementById('btnExportarDetalle').style.display = 'inline-flex';
                })
                .catch(err => {
                    console.error('Error fetching detalle:', err);
                    document.getElementById('loadingDetalle').innerHTML = 
                        '<i class="fas fa-exclamation-triangle" style="color:#f56565;"></i><br>Error al cargar datos';
                });
        }

        function renderizarDetalleEquipo(data) {
            const tbody = document.getElementById('detalleEquipoBody');
            tbody.innerHTML = '';

            data.forEach(row => {
                const tr = document.createElement('tr');

                const estadoClass = row.estado === 'finalizada' ? 'badge-success' :
                                row.estado === 'pendiente' ? 'badge-warning' :
                                row.estado === 'rechazada' ? 'badge-danger' : 'badge';

                tr.innerHTML = `
                    <td>${new Date(row.fecha_solicitud).toLocaleDateString('es-GT')} ${new Date(row.fecha_solicitud).toLocaleTimeString('es-GT', {hour:'2-digit', minute:'2-digit'})}</td>
                    <td>${row.tipo_paro_nombre || '—'}</td>
                    <td><span class="badge ${estadoClass}">${row.estado || '—'}</span></td>
                    <td>${row.fecha_inicio ? new Date(row.fecha_inicio).toLocaleString('es-GT', {hour:'2-digit', minute:'2-digit'}) : '—'}</td>
                    <td>${row.fecha_fin ? new Date(row.fecha_fin).toLocaleString('es-GT', {hour:'2-digit', minute:'2-digit'}) : '—'}</td>
                    <td>${row.tiempo_respuesta_fmt || '—'}</td>
                    <td>${row.tiempo_resolucion_fmt || '—'}</td>
                    <td>${row.tiempo_pausa_fmt 
                        ? `<span class="badge badge-warning" title="Este ticket estuvo en pausa ${row.tiempo_pausa_fmt} (${row.num_pausas} vez${row.num_pausas > 1 ? 'ces' : ''} pausado)"><i class="fas fa-pause-circle"></i> ${row.tiempo_pausa_fmt} <small>(${row.num_pausas}x)</small></span>` 
                        : '<span style="color:#a0aec0;">—</span>'}</td>
                    <td>${row.tecnico || '—'}</td>
                    <td style="max-width: 250px; word-wrap: break-word;">${escapeHtml(row.descripcion)}</td>
                    <td style="max-width: 220px; word-wrap: break-word;">${
                        row.comentarios_pausas
                        ? `<span style="color:#b7791f;" title="${escapeHtml(row.comentarios_pausas)}"><i class="fas fa-pause-circle"></i> ${escapeHtml(row.comentarios_pausas)}</span>`
                        : '<span style="color:#a0aec0;">—</span>'
                    }</td>
                    <td style="max-width: 250px; word-wrap: break-word;">${
                        row.solucion
                        ? `<span style="color:#276749;"><i class="fas fa-check-circle"></i> ${escapeHtml(row.solucion)}</span>`
                        : '<span style="color:#a0aec0;">—</span>'
                    }</td>
                `;
                tbody.appendChild(tr);
            });

            document.getElementById('detalleEquipoTable').style.display = 'table';
        }

        // Cerrar modal
        function cerrarModal() {
            document.getElementById('modalDetalleEquipo').style.display = 'none';
        }

        // Cerrar al hacer clic fuera
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('modalDetalleEquipo');
            if (e.target === modal) {
                cerrarModal();
            }
        });

        // Exportar detalle a CSV
        function exportarDetalleCSV() {
            if (datosDetalleEquipo.length === 0) return;

            const headers = ['Fecha Solicitud', 'Tipo Paro', 'Estado', 'Inicio', 'Fin', 'T.Respuesta', 'T.Resolución', 'T.Pausa', 'Técnico', 'Descripción', 'Motivo Pausas', 'Solución'];
            const rows = datosDetalleEquipo.map(r => [
                new Date(r.fecha_solicitud).toLocaleString('es-GT'),
                r.tipo_paro_nombre || '',
                r.estado || '',
                r.fecha_inicio ? new Date(r.fecha_inicio).toLocaleString('es-GT') : '',
                r.fecha_fin ? new Date(r.fecha_fin).toLocaleString('es-GT') : '',
                r.tiempo_respuesta_fmt || '',
                r.tiempo_resolucion_fmt || '',
                r.tiempo_pausa_fmt ? `${r.tiempo_pausa_fmt} (${r.num_pausas}x)` : '—',
                r.tecnico || '',
                r.descripcion || '',
                r.comentarios_pausas || '—',
                r.solucion || '—'
            ]);

            let csv = headers.join(',') + '\n';
            rows.forEach(row => {
                csv += row.map(cell => `"${(cell+'').replace(/"/g, '""')}"`).join(',') + '\n';
            });

            const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.setAttribute('href', url);
            link.setAttribute('download', `paros_${document.getElementById('modalEquipoNombre').textContent.replace(/[^a-z0-9]/gi, '_')}_${new Date().toISOString().slice(0,10)}.csv`);
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        // Cerrar con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') cerrarModal();
        });

        // Reloj en tiempo real
        function updateClock() {
            const now = new Date();
            const options = { 
                timeZone: 'America/Costa_Rica',
                dateStyle: 'full',
                timeStyle: 'medium'
            };
            const clockElement = document.getElementById('realTimeClock');
            if (clockElement) {
                clockElement.textContent = now.toLocaleString('es-CR', options);
            }
        }
        updateClock();
        setInterval(updateClock, 1000);

        // Validación de fechas
        const fechaDesde = document.getElementById('fecha_desde');
        const fechaHasta = document.getElementById('fecha_hasta');
        
        if (fechaHasta && fechaDesde) {
            fechaHasta.addEventListener('change', function() {
                if (this.value < fechaDesde.value) {
                    this.value = fechaDesde.value;
                }
            });
            
            fechaDesde.addEventListener('change', function() {
                if (fechaHasta.value < this.value) {
                    fechaHasta.value = this.value;
                }
            });
        }

        // Función de exportación a CSV optimizada
        function exportTableToCSV(tableId, filename) {
            const table = document.getElementById(tableId);
            if (!table) {
                console.error('Tabla no encontrada');
                return;
            }

            const rows = Array.from(table.querySelectorAll('tr'));
            const csvData = rows.map(row => {
                const cols = Array.from(row.querySelectorAll('td, th'));
                return cols.map(col => {
                    // Limpiar el texto y escapar comillas
                    let text = col.textContent.trim();
                    text = text.replace(/\s+/g, ' '); // Normalizar espacios
                    text = text.replace(/"/g, '""'); // Escapar comillas
                    return `"${text}"`;
                }).join(',');
            });

            const csvContent = csvData.join('\n');
            const BOM = '\uFEFF'; // UTF-8 BOM para Excel
            const blob = new Blob([BOM + csvContent], { 
                type: 'text/csv;charset=utf-8;' 
            });
            
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            const timestamp = new Date().toISOString().split('T')[0];
            
            link.setAttribute('href', url);
            link.setAttribute('download', `${filename}_${timestamp}.csv`);
            link.style.display = 'none';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            URL.revokeObjectURL(url);
        }

        // Función escapeHtml para JavaScript
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Mostrar errores en consola si DEBUG está activo
        <?php if (DEBUG && !empty($errors)): ?>
        console.group('Debug Errors');
        <?php foreach ($errors as $error): ?>
        console.error(<?= json_encode($error) ?>);
        <?php endforeach; ?>
        console.groupEnd();
        <?php endif; ?>
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
<?php 
// Cerrar conexión
if (isset($conn)) {
    $conn->close();
}
?>