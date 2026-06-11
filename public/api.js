/**
 * api.js  –  Dashboard Analítico de Quiebras y Producción
 *
 * Depende de:
 *   - AppData  (objeto global inyectado por frontend.php)
 *   - Chart.js 4.x   (CDN)
 *   - Bootstrap Icons (CDN)
 *
 * Funciones exportadas al scope global...
 */

'use strict';

// Función global para formatear fechas de manera local (sin zona horaria)
function formatLocalDate(dateStr, includeTime = false) {
    if (!dateStr) return 'N/A';
    
    // Si es un string vacío o "0000-00-00"
    if (dateStr === '0000-00-00') return 'N/A';
    
    let d;
    
    // Si es un string en formato YYYY-MM-DD
    if (typeof dateStr === 'string' && dateStr.match(/^\d{4}-\d{2}-\d{2}/)) {
        const parts = dateStr.split(' ')[0].split('-');
        // Crear fecha usando UTC para evitar desplazamiento de zona horaria
        d = new Date(Date.UTC(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2])));
    } else {
        d = new Date(dateStr);
    }
    
    if (isNaN(d.getTime())) return dateStr;
    
    const day = d.getUTCDate().toString().padStart(2, '0');
    const month = (d.getUTCMonth() + 1).toString().padStart(2, '0');
    const year = d.getUTCFullYear();
    const hours = d.getUTCHours().toString().padStart(2, '0');
    const minutes = d.getUTCMinutes().toString().padStart(2, '0');
    
    if (includeTime && dateStr.includes(' ')) {
        return `${day}/${month}/${year} ${hours}:${minutes}`;
    }
    return `${day}/${month}/${year}`;
}

// ============================================================
// CORRECCIÓN GLOBAL DE FECHAS - Solución unificada (MEJORADA)
// ============================================================

(function() {
    const originalToLocaleDateString = Date.prototype.toLocaleDateString;
    
    Date.prototype.toLocaleDateString = function(locale, options) {
        // Obtener la fecha en formato ISO y verificar si es una fecha pura
        const isoString = this.toISOString();
        
        // Si la fecha tiene hora 00:00:00 en UTC, probablemente es solo fecha
        // O si la diferencia entre UTC y local es exactamente el offset de zona horaria
        const isPureDate = (this.getUTCHours() === 0 && this.getUTCMinutes() === 0 && this.getUTCSeconds() === 0) ||
                           (Math.abs(this.getTime() - Date.UTC(this.getFullYear(), this.getMonth(), this.getDate())) < 1000);
        
        if (isPureDate) {
            // Extraer componentes directamente del objeto Date local (ya ajustado a la zona horaria)
            const year = this.getFullYear();
            const month = String(this.getMonth() + 1).padStart(2, '0');
            const day = String(this.getDate()).padStart(2, '0');
            return `${day}/${month}/${year}`;
        }
        
        // Para fechas con hora, usar comportamiento normal
        return originalToLocaleDateString.call(this, locale, options);
    };
})();

// ============================================================
// ESTADO GLOBAL
// ============================================================

const App = {
    charts:     {},
    isLoading:  false,
    colors: {
        primary: '#3b82f6', success: '#10b981',
        danger:  '#ef4444', warning: '#f59e0b',
        info:    '#0891b2', purple:  '#8b5cf6',
    },
};

// Variables globales para auto-refresh separados
let vivoInterval = null;
let wipInterval = null;

// ============================================================
// UTILIDADES
// ============================================================

function fmt(n) {
    return Number(n ?? 0).toLocaleString('es-CR');
}

function buildQueryParams(extra = {}) {
    const fd = new FormData(document.getElementById('filterForm'));
    const p  = new URLSearchParams();
    for (const [k, v] of fd.entries()) {
        if (k !== 'tab') p.append(k, v);
    }
    Object.entries(extra).forEach(([k, v]) => p.append(k, v));
    p.append('_t', Date.now());
    return p.toString();
}

async function apiFetch(extra = {}) {
    const resp = await fetch(`?${buildQueryParams(extra)}`);
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    const text = await resp.text();
    const match = text.match(/(\{[\s\S]*\}|\[[\s\S]*\])/);
    if (!match) throw new Error('Respuesta no JSON del servidor');
    return JSON.parse(match[0]);
}

function showModalLoading(title = 'Cargando datos...') {
    document.getElementById('modalTitulo').innerHTML =
        `<i class="bi bi-hourglass-split"></i> ${title}`;
    document.getElementById('modalCuerpo').innerHTML = `
        <div class="text-center py-12">
            <div class="spinner mx-auto mb-4"></div>
            <p class="text-lg">Cargando datos...</p>
            <p class="text-sm opacity-70 mt-2">Por favor espere</p>
        </div>`;
    document.getElementById('modalDetalles').classList.remove('hidden');
}

function setModalInfo(html) {
    document.getElementById('modalInfo').innerHTML = html;
}

function cerrarModal() {
    document.getElementById('modalDetalles').classList.add('hidden');
    document.getElementById('modalCuerpo').innerHTML = '';
    document.getElementById('modalTitulo').textContent = 'Detalles';
    document.getElementById('modalInfo').textContent = '';
}

function buildChartDailyOptions(yLabel = 'Cantidad') {
    return {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'top', labels: { color: '#374151' } },
            tooltip: {
                mode: 'index', intersect: false,
                backgroundColor: 'rgba(0,0,0,.85)',
                titleColor: '#fff', bodyColor: '#fff',
            },
        },
        scales: {
            x: { grid: { color: '#e5e7eb' }, ticks: { color: '#4b5563' } },
            y: {
                beginAtZero: true,
                title: { display: true, text: yLabel, color: '#6b7280' },
                grid: { color: '#e5e7eb' },
                ticks: { color: '#4b5563', precision: 0 },
            },
        },
    };
}

function renderDailyChart(canvasId, datosDiarios) {
    const ctx = document.getElementById(canvasId);
    if (!ctx || !datosDiarios?.length) return;
    
    if (ctx.chart) {
        ctx.chart.destroy();
    }
    
    const labels = datosDiarios.map(d => {
        const dt = new Date(d.fecha_dia);
        return `${dt.getDate()}/${dt.getMonth() + 1}`;
    });
    
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Producción',
                    data: datosDiarios.map(d => +d.produccion_dia || 0),
                    borderColor: App.colors.success,
                    backgroundColor: 'rgba(16,185,129,.1)',
                    tension: 0.3, fill: true,
                    pointBackgroundColor: App.colors.success,
                    pointBorderColor: '#fff', pointBorderWidth: 2,
                    pointRadius: 4, pointHoverRadius: 6,
                },
                {
                    label: 'Quiebras',
                    data: datosDiarios.map(d => +d.quiebras_dia || 0),
                    borderColor: App.colors.danger,
                    backgroundColor: 'rgba(239,68,68,.05)',
                    tension: 0.3, fill: true,
                    pointBackgroundColor: App.colors.danger,
                    pointBorderColor: '#fff', pointBorderWidth: 2,
                    pointRadius: 4, pointHoverRadius: 6,
                },
            ],
        },
        options: buildChartDailyOptions('Cantidad'),
    });
    
    ctx.chart = chart;
}

function renderHourChart(canvasId, prodPorHora) {
    const ctx = document.getElementById(canvasId);
    if (!ctx || !prodPorHora?.length) return;
    
    if (ctx.chart) {
        ctx.chart.destroy();
    }

    const horasCompletas = Array.from({ length: 24 }, (_, i) => i);
    const map = {};
    prodPorHora.forEach(item => { map[item.hora] = +item.total_produccion; });

    const chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: horasCompletas.map(h => `${h}:00`),
            datasets: [{
                label: 'Unidades Producidas',
                data: horasCompletas.map(h => map[h] || 0),
                backgroundColor: App.colors.warning,
                borderRadius: 4,
                borderSkipped: false,
            }],
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,.85)',
                    titleColor: '#fff', bodyColor: '#fff',
                    callbacks: { label: ctx => `${ctx.dataset.label}: ${fmt(ctx.parsed.y)}` },
                },
            },
            scales: {
                x: {
                    title: { display: true, text: 'Hora del Día', color: '#6b7280' },
                    grid: { color: '#f3f4f6' },
                    ticks: { color: '#4b5563', maxRotation: 45, minRotation: 45 },
                },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Unidades', color: '#6b7280' },
                    grid: { color: '#e5e7eb' },
                    ticks: { color: '#4b5563', precision: 0 },
                },
            },
        },
    });
    
    ctx.chart = chart;
}

// ============================================================
// ACTUALIZAR PROMEDIOS
// ============================================================

function actualizarPromediosQuiebras() {
    const data = window.PromedioQuiebrasData;
    
    // Actualizar elementos con los valores reales (incluyendo todos los días)
    const elementos = {
        promedio: document.getElementById('promedioQuiebrasVal'),
        mediana: document.getElementById('medianaQuiebrasVal'),
        maximo: document.getElementById('maximoQuiebrasVal'),
        minimo: document.getElementById('minimoQuiebrasVal'),
        infoDias: document.getElementById('infoDiasQuiebras')
    };
    
    if (elementos.promedio) elementos.promedio.textContent = fmt(data.promedio || 0);
    if (elementos.mediana) elementos.mediana.textContent = fmt(data.mediana || 0);
    if (elementos.maximo) elementos.maximo.textContent = fmt(data.maximo || 0);
    if (elementos.minimo) elementos.minimo.textContent = fmt(data.minimo || 0);
    
    if (elementos.infoDias) {
        elementos.infoDias.innerHTML = `<i class="bi bi-calendar-week"></i> ${data.dias_con_quiebras || 0} días con quiebras | Total: ${fmt(data.total_quiebras || 0)} quiebras`;
    }
}

// ============================================================
// GRÁFICO: EFICIENCIA ÚLTIMOS 6 MESES (VERSIÓN LÍNEA)
// ============================================================

async function cargarEficiencia6Meses() {
    try {
        const resp = await fetch(`?eficiencia_6meses=1`);
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        const json = await resp.json();
        if (!json.success || !json.data?.length) return;

        const data = json.data;
        const labels = data.map(d => d.mes);
        const eficiencias = data.map(d => +d.eficiencia);
        const quiebras = data.map(d => +d.quiebras);
        const produccion = data.map(d => +d.produccion);
        const ccValidas = data.map(d => +d.cc_validas);
        const totalOk = produccion.map((p, i) => p + ccValidas[i]);

        const ctx = document.getElementById('eficiencia6mChart');
        if (!ctx) return;
        if (ctx.chartInstance) { try { ctx.chartInstance.destroy(); } catch(e) {} }

        ctx.chartInstance = new Chart(ctx, {
            type: 'line',  // ← CAMBIADO A LÍNEA
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Eficiencia (%)',
                        data: eficiencias,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 3,
                        tension: 0.3,
                        fill: true,
                        pointBackgroundColor: eficiencias.map(e => e >= 95 ? '#10b981' : (e >= 90 ? '#f59e0b' : '#ef4444')),
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 8,
                        yAxisID: 'yEf',
                        order: 1,
                    },
                    {
                        label: 'Quiebras',
                        data: quiebras,
                        type: 'line',
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.05)',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        tension: 0.3,
                        fill: false,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        yAxisID: 'yQ',
                        order: 0,
                    }
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { color: '#374151', font: { size: 11, weight: 'bold' } } },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,0.85)',
                        callbacks: {
                            label: (ctx) => {
                                if (ctx.dataset.label === 'Eficiencia (%)') return `🎯 Eficiencia: ${ctx.raw}%`;
                                if (ctx.dataset.label === 'Quiebras') return `⚠️ Quiebras: ${fmt(ctx.raw)}`;
                                return `${ctx.dataset.label}: ${ctx.raw}`;
                            },
                            afterBody: (tooltipItems) => {
                                const idx = tooltipItems[0].dataIndex;
                                const ok = totalOk[idx];
                                return `✅ Órdenes OK: ${fmt(ok)}\n📦 Producción: ${fmt(produccion[idx])}\n🔬 CC Válidas: ${fmt(ccValidas[idx])}`;
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { color: '#f3f4f6' }, ticks: { color: '#4b5563', font: { size: 11 } } },
                    yEf: {
                        beginAtZero: false,
                        min: Math.max(0, Math.min(...eficiencias) - 5),
                        max: 100,
                        position: 'left',
                        title: { display: true, text: 'Eficiencia (%)', color: '#059669', font: { weight: 'bold' } },
                        ticks: { color: '#059669', callback: v => `${v}%` },
                        grid: { color: '#e5e7eb' },
                    },
                    yQ: {
                        beginAtZero: true,
                        position: 'right',
                        title: { display: true, text: 'Quiebras', color: '#dc2626', font: { weight: 'bold' } },
                        ticks: { color: '#dc2626', callback: v => fmt(v) },
                        grid: { drawOnChartArea: false },
                    }
                },
            },
        });

        // Métricas de resumen
        const mejor = Math.max(...eficiencias);
        const peor = Math.min(...eficiencias);
        const prom6m = (eficiencias.reduce((a,b) => a + b, 0) / eficiencias.length).toFixed(1);
        const tendencia = eficiencias[eficiencias.length - 1] > eficiencias[eficiencias.length - 2]
            ? '<span class="text-emerald-600"><i class="bi bi-arrow-up-short"></i> Mejora</span>'
            : '<span class="text-red-500"><i class="bi bi-arrow-down-short"></i> Baja</span>';

        const elMejor = document.getElementById('eficienciaMejor');
        const elPeor = document.getElementById('eficienciaPeor');
        const elProm = document.getElementById('eficienciaPromedio6m');
        const elTend = document.getElementById('eficienciaTendencia');
        const elLabel = document.getElementById('eficienciaPromedioLabel');

        if (elMejor) elMejor.textContent = `${mejor}%`;
        if (elPeor) elPeor.textContent = `${peor}%`;
        if (elProm) elProm.textContent = `${prom6m}%`;
        if (elTend) elTend.innerHTML = tendencia;
        if (elLabel) elLabel.textContent = `Promedio 6M: ${prom6m}%`;

        // Pastillas de órdenes OK por mes
        const ordenesRow = document.getElementById('eficienciaOrdenesRow');
        if (ordenesRow) {
            ordenesRow.innerHTML = data.map((d, idx) => {
                const ok = totalOk[idx];
                const ef = eficiencias[idx];
                const color = ef >= 95 ? 'bg-emerald-100 text-emerald-700'
                            : ef >= 90 ? 'bg-amber-100 text-amber-700'
                            : 'bg-red-100 text-red-700';
                return `<span class="inline-flex flex-col items-center ${color} rounded-lg px-2 py-1 text-xs font-medium shadow-sm" title="Órdenes OK en ${d.mes}">
                    <span class="font-bold text-sm leading-tight">${fmt(ok)}</span>
                    <span class="opacity-70 text-[10px]">${d.mes}</span>
                </span>`;
            }).join('');
        }

    } catch (err) {
        console.error('❌ Error cargando eficiencia 6 meses:', err);
    }
}

function mostrarDetalleTurnos(quiebrasTurno) {
    const container = document.getElementById('turnoDetalleContainer');
    if (!container) return;
    
    if (!quiebrasTurno || quiebrasTurno.length === 0) {
        container.innerHTML = '<div class="col-span-full text-center py-4 text-gray-400 text-sm">Sin datos de turnos</div>';
        return;
    }
    
    const totalQuiebras = quiebrasTurno.reduce((sum, t) => sum + (t.total || 0), 0);
    
    // Colores para cada turno (coincidentes con el gráfico)
    const coloresTurno = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'];
    
    const html = quiebrasTurno.map((turno, index) => {
        const cantidad = turno.total || 0;
        const porcentaje = totalQuiebras > 0 ? ((cantidad / totalQuiebras) * 100).toFixed(1) : 0;
        const nombreTurno = `Turno ${turno.turno || 'Sin especificar'}`;
        const colorFondo = coloresTurno[index % coloresTurno.length];
        
        return `
            <div class="bg-white rounded-xl p-3 shadow-sm border border-gray-100 hover:shadow-md transition-all">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 rounded-full" style="background-color: ${colorFondo}"></div>
                        <span class="text-sm font-semibold text-gray-700">${nombreTurno}</span>
                    </div>
                    <span class="text-xs font-bold text-gray-500">${porcentaje}%</span>
                </div>
                <div class="flex items-baseline justify-between">
                    <span class="text-2xl font-bold" style="color: ${colorFondo}">${fmt(cantidad)}</span>
                    <span class="text-xs text-gray-400">quiebras</span>
                </div>
                <div class="mt-2 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full rounded-full" style="width: ${porcentaje}%; background-color: ${colorFondo}"></div>
                </div>
            </div>
        `;
    }).join('');
    
    container.innerHTML = html;
}

function initCharts() {
    console.log('📈 initCharts() ejecutándose');
    console.log('🔍 AppData disponible:', !!window.AppData);
    
    actualizarPromediosQuiebras();
    cargarEficiencia6Meses();   // ★ Eficiencia últimos 6 meses
    
    Object.keys(App.charts).forEach(key => {
        if (App.charts[key]) {
            try { App.charts[key].destroy(); } catch(e) {}
            App.charts[key] = null;
        }
    });
    
// GRÁFICO DE TIMELINE - Ocultar visualmente domingos (día 1 en MySQL)
const timelineCtx = document.getElementById('timelineChart');
if (timelineCtx && window.AppData?.timelineData?.length > 0) {
    console.log('📊 Creando gráfico de timeline con', window.AppData.timelineData.length, 'días con datos');
    
    // Mapear datos y marcar domingos para ocultar en el eje X
    const timelineData = window.AppData.timelineData;
    
    // Filtrar para excluir domingos completamente del gráfico
    const timelineDataFiltered = timelineData.filter(d => d.dia_semana !== 1);
    
    if (timelineDataFiltered.length === 0) {
        mostrarMensajeSinDatos(timelineCtx, 'No hay registros de quiebras en días laborables');
        return;
    }
    
    const valoresQuiebras = timelineDataFiltered.map(d => d.total);
    const promedioLinea = valoresQuiebras.length > 0 ? valoresQuiebras.reduce((a,b) => a + b, 0) / valoresQuiebras.length : 0;
    
    timelineCtx.style.display = '';
    
    App.charts.timeline = new Chart(timelineCtx, {
        type: 'line',
        data: {
            labels: timelineDataFiltered.map(d => d.fecha_display || d.fecha || ''),
            datasets: [
                {
                    label: 'Quiebras por Día',
                    data: valoresQuiebras,
                    borderColor: App.colors.danger,
                    backgroundColor: 'rgba(239,68,68,.15)',
                    tension: 0.3,
                    fill: true,
                    pointBackgroundColor: App.colors.danger,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                },
                {
                    label: `Promedio Laborable: ${promedioLinea.toFixed(1)}`,
                    data: Array(timelineDataFiltered.length).fill(promedioLinea),
                    borderColor: App.colors.info,
                    borderDash: [6, 6],
                    borderWidth: 2,
                    pointRadius: 0,
                    fill: false,
                    tension: 0,
                }
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { 
                    position: 'top', 
                    labels: { color: '#374151' } 
                },
                tooltip: {
                    callbacks: {
                        afterBody: function(context) {
                            return `📊 Promedio días laborables: ${promedioLinea.toFixed(1)} quiebras`;
                        }
                    }
                }
            },
            scales: {
                x: { 
                    title: { display: true, text: 'Fecha (Laborables)', color: '#6b7280' },
                    grid: { color: '#e5e7eb' }, 
                    ticks: { 
                        color: '#4b5563',
                        maxRotation: 45,
                        minRotation: 45,
                        autoSkip: true,
                        maxTicksLimit: 12
                    } 
                },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Cantidad de Quiebras', color: '#6b7280' },
                    grid: { color: '#e5e7eb' },
                    ticks: { color: '#4b5563', precision: 0 },
                },
            },
        },
    });
}
    
// GRÁFICO DE TURNOS
const turnoCtx = document.getElementById('turnoChart');
if (turnoCtx && window.AppData?.quiebrasTurno?.length > 0) {
    console.log('📊 Creando gráfico de turnos con', window.AppData.quiebrasTurno.length, 'turnos');
    
    const colors = [App.colors.primary, App.colors.success, App.colors.warning, App.colors.danger, App.colors.purple, App.colors.info];
    
    App.charts.turno = new Chart(turnoCtx, {
        type: 'doughnut',
        data: {
            labels: window.AppData.quiebrasTurno.map(d => `Turno ${d.turno || 'Sin especificar'}`),
            datasets: [{
                data: window.AppData.quiebrasTurno.map(d => d.total),
                backgroundColor: colors.slice(0, window.AppData.quiebrasTurno.length),
                borderColor: '#e5e7eb',
                borderWidth: 2,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: { position: 'right', labels: { color: '#374151', font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw;
                            const total = context.dataset.data.reduce((a,b) => a + b, 0);
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                            return `${label}: ${fmt(value)} quiebras (${percentage}%)`;
                        }
                    }
                }
            },
        },
    });
    console.log('✅ Gráfico de turnos creado');
    
    // 🔥 NUEVO: Llenar las tarjetas de detalle por turno
    mostrarDetalleTurnos(window.AppData.quiebrasTurno);
    
} else if (turnoCtx) {
    console.warn('⚠️ No hay datos de turnos');
    mostrarMensajeSinDatos(turnoCtx, 'No hay datos de turnos en el período seleccionado');
    // Mostrar mensaje en el contenedor de detalle
    const container = document.getElementById('turnoDetalleContainer');
    if (container) {
        container.innerHTML = '<div class="col-span-full text-center py-4 text-gray-400 text-sm">No hay datos de turnos disponibles</div>';
    }
}
    
    // GRÁFICO DE MOTIVOS
    const motivosCtx = document.getElementById('motivosChart');
    if (motivosCtx && window.AppData?.topMotivos?.length > 0) {
        console.log('📊 Creando gráfico de motivos con', window.AppData.topMotivos.length, 'motivos');
        
        App.charts.motivos = new Chart(motivosCtx, {
            type: 'bar',
            data: {
                labels: window.AppData.topMotivos.map(d => {
                    const motivo = d.motivo || 'Desconocido';
                    return motivo.length > 30 ? motivo.substring(0, 27) + '…' : motivo;
                }),
                datasets: [{
                    label: 'Cantidad de Quiebras',
                    data: window.AppData.topMotivos.map(d => d.total),
                    backgroundColor: App.colors.danger,
                    borderRadius: 4,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.raw} quiebras`;
                            }
                        }
                    }
                },
                scales: {
                    x: { 
                        beginAtZero: true, 
                        grid: { color: '#e5e7eb' }, 
                        ticks: { color: '#4b5563' } 
                    },
                    y: { 
                        grid: { color: '#f3f4f6' }, 
                        ticks: { color: '#4b5563', font: { size: 11 } } 
                    },
                },
            },
        });
        console.log('✅ Gráfico de motivos creado');
    } else if (motivosCtx) {
        console.warn('⚠️ No hay datos de motivos');
        mostrarMensajeSinDatos(motivosCtx, 'No hay datos de motivos en el período seleccionado');
    }
}

function mostrarMensajeSinDatos(canvas, mensaje) {
    const parent = canvas.parentElement;
    if (!parent) return;
    
    let msgDiv = parent.querySelector('.no-data-message');
    if (!msgDiv) {
        msgDiv = document.createElement('div');
        msgDiv.className = 'no-data-message';
        msgDiv.innerHTML = `<i class="bi bi-inbox text-3xl opacity-50"></i><p class="mt-2 text-sm text-gray-500">${mensaje}</p>`;
        parent.appendChild(msgDiv);
    }
    canvas.style.display = 'none';
}

function resizeCharts() {
    Object.values(App.charts).forEach(c => {
        if (c && typeof c.resize === 'function') {
            try { c.resize(); } catch(e) {}
        }
    });
}

// ============================================================
// TABS
// ============================================================

function cambiarTab(tabId) {
    if (App.isLoading) return;
    document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

    const btn = document.querySelector(`[data-tab="${tabId}"]`);
    const cnt = document.getElementById(tabId);
    if (btn) btn.classList.add('active');
    if (cnt) cnt.classList.add('active');

    const tabInput = document.querySelector('input[name="tab"]');
    if (tabInput) tabInput.value = tabId;

    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.pushState({}, '', url);

    if (tabId === 'resumen' || tabId === 'analisis') {
        setTimeout(() => {
            document.querySelectorAll('.chart-container canvas').forEach(canvas => {
                canvas.style.display = '';
            });
            document.querySelectorAll('.no-data-message').forEach(msg => msg.remove());
            initCharts();
        }, 100);
    }
    
    if (tabId === 'produccion-vivo') {
        setTimeout(() => iniciarAutoRefreshVivo(), 100);
    } else {
        detenerAutoRefreshVivo();
    }
    
    setTimeout(resizeCharts, 150);
}

// ============================================================
// EXPORTACIÓN CSV
// ============================================================

function exportarTabla(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) { alert('Tabla no encontrada'); return; }
    let csv = '';
    table.querySelectorAll('tr').forEach(row => {
        const cols = Array.from(row.querySelectorAll('th, td'))
            .map(c => `"${(c.textContent || '').replace(/"/g, '""').replace(/\n/g, ' ').trim()}"`);
        csv += cols.join(',') + '\n';
    });
    descargarCSV(csv, filename);
}

function exportarDatosModal(datos, filename) {
    if (!datos?.length) { alert('Sin datos para exportar'); return; }
    const headers = Object.keys(datos[0]);
    let csv = headers.join(',') + '\n';
    datos.forEach(row => {
        csv += headers.map(h => {
            let v = row[h] ?? '';
            if (typeof v === 'string') v = v.replace(/"/g, '""');
            if (String(v).includes(',') || String(v).includes('"') || String(v).includes('\n'))
                v = `"${v}"`;
            return v;
        }).join(',') + '\n';
    });
    descargarCSV(csv, filename);
}

function descargarCSV(content, filename) {
    const blob = new Blob(["\uFEFF" + content], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = filename; a.style.display = 'none';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function exportarEquipos() {
    const tabla = document.getElementById('tablaEquipos');
    if (!tabla) { alert('Tabla no encontrada'); return; }
    
    let csv = 'Equipo,Quiebras,Empleados,Órdenes,Motivos,Áreas,Período\n';
    const filas = tabla.querySelectorAll('tbody tr');
    filas.forEach(row => {
        const celdas = row.querySelectorAll('td');
        if (celdas.length >= 7) {
            const fila = [
                celdas[0]?.innerText.trim() || '',
                celdas[1]?.innerText.trim() || '',
                celdas[2]?.innerText.trim().replace(/,/g, ';') || '',
                celdas[3]?.innerText.trim() || '',
                celdas[4]?.innerText.trim().replace(/,/g, ';') || '',
                celdas[5]?.innerText.trim() || '',
                celdas[6]?.innerText.trim().replace(/\n/g, ' - ') || ''
            ].map(v => `"${v.replace(/"/g, '""')}"`).join(',');
            csv += fila + '\n';
        }
    });
    descargarCSV(csv, `equipos_quiebras_${new Date().toISOString().slice(0,19).replace(/:/g, '-')}.csv`);
}

// ============================================================
// MODAL – DETALLES DE ORDEN (VERSIÓN CORREGIDA)
// ============================================================

// Función global para mostrar detalles de orden (SIN PRODUCCIÓN EN TABLA DE EMPLEADOS)
window.mostrarDetallesOrden = async function(orden) {
    console.log('🔍 mostrarDetallesOrden:', orden);
    if (!orden) return;
    
    const modal = document.getElementById('modalDetalles');
    const titulo = document.getElementById('modalTitulo');
    const cuerpo = document.getElementById('modalCuerpo');
    const info = document.getElementById('modalInfo');
    const exportBtn = document.getElementById('modalExportBtn');
    
    if (!modal) return;
    
    modal.classList.remove('hidden');
    if (titulo) titulo.innerHTML = `<i class="bi bi-clipboard-check"></i> Estadísticas de Orden: ${escapeHtml(orden)}`;
    if (cuerpo) cuerpo.innerHTML = `<div class="text-center py-12"><div class="spinner mx-auto mb-4"></div><p>Cargando estadísticas...</p></div>`;
    
    // Función auxiliar para formatear fecha local
    const fmtDate = (dateStr) => {
        if (!dateStr) return 'N/A';
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return `${d.getDate().toString().padStart(2,'0')}/${(d.getMonth()+1).toString().padStart(2,'0')}/${d.getFullYear()}`;
    };
    
    try {
        const form = document.getElementById('filterForm');
        const formData = new FormData(form);
        const params = new URLSearchParams();
        
        params.append('detalles_orden_completo', orden);
        params.append('_t', Date.now());
        
        const fechaInicio = formData.get('fecha_inicio');
        const fechaFin = formData.get('fecha_fin');
        if (fechaInicio) params.append('fecha_inicio', fechaInicio);
        if (fechaFin) params.append('fecha_fin', fechaFin);
        
        const resp = await fetch(`?${params.toString()}`);
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        
        const data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Error al cargar datos');
        
        // Construir tabla de empleados (solo quiebras)
        let empleadosHtml = '';
        if (data.empleados && data.empleados.length > 0) {
            empleadosHtml = data.empleados.map(emp => `
                <tr class="border-b hover:bg-gray-50">
                    <td class="p-2 text-sm font-medium">${escapeHtml(emp.empleado)}</td>
                    <td class="p-2 text-center text-red-600 font-bold">${fmt(emp.quiebras)}</td>
                 </tr>
            `).join('');
        } else {
            empleadosHtml = '<tr><td colspan="2" class="p-4 text-center text-gray-400">Sin datos de empleados</td></tr>';
        }
        
        // Construir tabla de quiebras
        let quiebrasHtml = '';
        if (data.quiebras && data.quiebras.length > 0) {
            quiebrasHtml = data.quiebras.map(r => {
                let horaMostrar = r.hora || 'N/A';
                if (horaMostrar === '00:00' || horaMostrar === '00:00:00') {
                    horaMostrar = '<span class="text-gray-400">--:--</span>';
                }
                
                const fechaStr = fmtDate(r.fecha);
                const empleadoMostrar = r.empleado && r.empleado !== 'N/A' && r.empleado !== 'No registrado' 
                    ? escapeHtml(r.empleado) 
                    : '<span class="text-gray-400">No registrado</span>';
                const areaMostrar = r.area && r.area !== 'N/A' && r.area !== 'No registrada' 
                    ? escapeHtml(r.area) 
                    : '<span class="text-gray-400">No registrada</span>';
                const equipoMostrar = r.equipo && r.equipo !== 'N/A' && r.equipo !== 'No registrado' && r.equipo !== '' 
                    ? escapeHtml(r.equipo) 
                    : '<span class="text-gray-400">No registrado</span>';
                
                return `<tr class="border-b hover:bg-gray-50">
                    <td class="p-2 text-xs whitespace-nowrap">${fechaStr}</td>
                    <td class="p-2 text-xs font-mono whitespace-nowrap">${horaMostrar}</td>
                    <td class="p-2 text-xs">${empleadoMostrar}</td>
                    <td class="p-2 text-xs">${areaMostrar}</td>
                    <td class="p-2 text-xs">${equipoMostrar}</td>
                    <td class="p-2 text-xs max-w-[200px] truncate" title="${escapeHtml(r.motivo || '')}">${escapeHtml((r.motivo || 'N/A').substring(0, 50))}</td>
                 </tr>`;
            }).join('');
        } else {
            quiebrasHtml = '<tr><td colspan="6" class="p-4 text-center text-gray-400">No hay registros de quiebras</td></tr>';
        }
        
        // HTML completo del modal
        let html = `
            <!-- KPIs -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-gradient-to-br from-red-50 to-red-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-red-600">${fmt(data.total_quiebras || 0)}</div>
                    <div class="text-xs text-gray-600">Quiebras</div>
                </div>
                <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-emerald-600">${fmt(data.total_produccion || 0)}</div>
                    <div class="text-xs text-gray-600">Registros de Producción</div>
                </div>
                <div class="bg-gradient-to-br from-amber-50 to-amber-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-amber-600">${data.empleados_involucrados || 0}</div>
                    <div class="text-xs text-gray-600">Empleados</div>
                </div>
                <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-purple-600">${data.motivos_diferentes || 0}</div>
                    <div class="text-xs text-gray-600">Motivos</div>
                </div>
            </div>
            
            <!-- Empleados Involucrados (solo quiebras) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
                <div>
                    <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                        <i class="bi bi-person-lines-fill text-blue-500"></i> Empleados Involucrados
                    </h4>
                    <div class="overflow-x-auto max-h-[250px] overflow-y-auto border rounded-lg">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="p-2 text-left">Empleado</th>
                                    <th class="p-2 text-center">Quiebras</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${empleadosHtml}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Historial de Quiebras -->
            <div class="mb-4">
                <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                    <i class="bi bi-list-ul text-gray-500"></i> Historial de Quiebras
                </h4>
                <div class="overflow-x-auto max-h-[300px] overflow-y-auto border rounded-lg">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 sticky top-0">
                            <tr>
                                <th class="p-2 text-left">Fecha</th>
                                <th class="p-2 text-left">Hora</th>
                                <th class="p-2 text-left">Empleado</th>
                                <th class="p-2 text-left">Área</th>
                                <th class="p-2 text-left">Equipo</th>
                                <th class="p-2 text-left">Motivo</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${quiebrasHtml}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
        
        if (cuerpo) cuerpo.innerHTML = html;
        
        // Botón de exportación
        if (exportBtn) {
            exportBtn.onclick = () => exportarOrdenStats(data, orden);
        }
        
        // Información del período
        const periodoInicio = data.fecha_inicio || fmtDate(fechaInicio);
        const periodoFin = data.fecha_fin || fmtDate(fechaFin);
        if (info) info.innerHTML = `<span class="opacity-70">Período:</span> ${periodoInicio} → ${periodoFin}`;
        
    } catch (err) {
        console.error('❌ Error en mostrarDetallesOrden:', err);
        if (cuerpo) cuerpo.innerHTML = `<div class="text-center py-8 text-red-500">Error: ${escapeHtml(err.message)}</div>`;
    }
};

// Función auxiliar para exportar estadísticas de orden
function exportarOrdenStats(data, orden) {
    let csv = `Estadísticas de Orden: ${orden}\n`;
    csv += `Total Quiebras,${data.total_quiebras || 0}\n`;
    csv += `Total Producción,${data.total_produccion || 0}\n`;
    csv += `Empleados Involucrados,${data.empleados_involucrados || 0}\n`;
    csv += `Motivos Diferentes,${data.motivos_diferentes || 0}\n\n`;
    
    csv += `Empleados con Quiebras\nEmpleado,Quiebras\n`;
    (data.empleados || []).forEach(emp => {
        csv += `${emp.empleado},${emp.quiebras}\n`;
    });
    
    csv += `\nHistorial de Quiebras\nFecha,Hora,Empleado,Área,Equipo,Motivo\n`;
    (data.quiebras || []).forEach(q => {
        let hora = q.hora || '';
        if (hora === '00:00' || hora === '00:00:00') hora = '--:--';
        csv += `${q.fecha || ''},${hora},${escapeCsv(q.empleado || '')},${escapeCsv(q.area || '')},${escapeCsv(q.equipo || '')},${escapeCsv(q.motivo || '')}\n`;
    });
    
    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `orden_${orden.replace(/[^a-z0-9]/gi, '_')}_detalles.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

// Función auxiliar para escapar CSV
function escapeCsv(str) {
    if (!str) return '';
    return `"${str.replace(/"/g, '""')}"`;
}

// ============================================================
// PRODUCCIÓN EN VIVO — DASHBOARD COMPLETO
// ============================================================

let vivoCharts = {};
let vivoRegistrosCache = []; // cache para exportar CSV
let vivoLastDataHash = null; // para detectar cambios en los datos

function simpleHash(obj) {
    return JSON.stringify(obj).split('').reduce((a, b) => ((a << 5) - a) + b.charCodeAt(0), 0).toString();
}

function generarColoresPastel(cantidad) {
    const coloresBase = [
        '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', 
        '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#14b8a6',
        '#6366f1', '#d946ef', '#f43f5e', '#0ea5e9', '#22c55e'
    ];
    
    if (cantidad <= coloresBase.length) return coloresBase.slice(0, cantidad);
    
    const coloresExtendidos = [...coloresBase];
    for (let i = coloresBase.length; i < cantidad; i++) {
        const hue = (i * 137) % 360;
        coloresExtendidos.push(`hsl(${hue}, 65%, 55%)`);
    }
    return coloresExtendidos;
}

function actualizarGraficoAreas(produccion_por_area) {
    const areaCtx = document.getElementById('vivoAreaChart');
    if (!areaCtx || !produccion_por_area?.length) return;
    
    const chartType = document.getElementById('areaChartTypeSelector')?.value || 'doughnut';
    
    if (vivoCharts.area) {
        try { vivoCharts.area.destroy(); } catch(e) {}
        vivoCharts.area = null;
    }
    
    const labels = produccion_por_area.map(a => a.area);
    const valores = produccion_por_area.map(a => a.total_produccion);
    const colores = generarColoresPastel(produccion_por_area.length);
    
    let config = {};
    
    switch(chartType) {
        case 'bar':
            config = {
                type: 'bar',
                data: { labels, datasets: [{ label: 'Unidades Producidas', data: valores, backgroundColor: colores, borderColor: '#ffffff', borderWidth: 2, borderRadius: 6 }] },
                options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(0,0,0,.85)', callbacks: { label: (ctx) => `${ctx.dataset.label}: ${fmt(ctx.raw)} unidades` } } }, scales: { y: { beginAtZero: true, title: { display: true, text: 'Unidades', color: '#6b7280' }, ticks: { callback: (v) => fmt(v), color: '#4b5563' }, grid: { color: '#e5e7eb' } }, x: { title: { display: true, text: 'Área', color: '#6b7280' }, ticks: { autoSkip: true, maxRotation: 45, minRotation: 45, color: '#4b5563' }, grid: { display: false } } } }
            };
            break;
        case 'line':
            config = {
                type: 'line',
                data: { labels, datasets: [{ label: 'Unidades Producidas', data: valores, fill: true, backgroundColor: 'rgba(59, 130, 246, 0.1)', borderColor: '#3b82f6', borderWidth: 2, tension: 0.3, pointBackgroundColor: colores, pointBorderColor: '#ffffff', pointBorderWidth: 2, pointRadius: 5, pointHoverRadius: 7 }] },
                options: { responsive: true, maintainAspectRatio: true, plugins: { tooltip: { backgroundColor: 'rgba(0,0,0,.85)', callbacks: { label: (ctx) => `Producción: ${fmt(ctx.raw)} unidades` } } }, scales: { y: { beginAtZero: true, title: { display: true, text: 'Unidades', color: '#6b7280' }, ticks: { callback: (v) => fmt(v), color: '#4b5563' }, grid: { color: '#e5e7eb' } }, x: { title: { display: true, text: 'Área', color: '#6b7280' }, ticks: { autoSkip: true, maxRotation: 45, minRotation: 45, color: '#4b5563' } } } }
            };
            break;
        case 'polarArea':
            config = {
                type: 'polarArea',
                data: { labels, datasets: [{ data: valores, backgroundColor: colores, borderColor: '#ffffff', borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'right', labels: { font: { size: 10 }, boxWidth: 10 } }, tooltip: { backgroundColor: 'rgba(0,0,0,.85)', callbacks: { label: (ctx) => { const total = valores.reduce((a,b) => a + b, 0); const percentage = ((ctx.raw / total) * 100).toFixed(1); return `${ctx.label}: ${fmt(ctx.raw)} unidades (${percentage}%)`; } } } }, scales: { r: { ticks: { callback: (v) => fmt(v), stepSize: Math.ceil(Math.max(...valores, 1) / 5) } } } }
            };
            break;
        case 'radar':
            config = {
                type: 'radar',
                data: { labels, datasets: [{ label: 'Producción por Área', data: valores, backgroundColor: 'rgba(59, 130, 246, 0.2)', borderColor: '#3b82f6', borderWidth: 2, pointBackgroundColor: colores, pointBorderColor: '#ffffff', pointRadius: 4, pointHoverRadius: 6 }] },
                options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'top', labels: { font: { size: 10 } } }, tooltip: { backgroundColor: 'rgba(0,0,0,.85)', callbacks: { label: (ctx) => `${ctx.label}: ${fmt(ctx.raw)} unidades` } } }, scales: { r: { beginAtZero: true, ticks: { stepSize: Math.ceil(Math.max(...valores, 1) / 5), callback: (v) => fmt(v) } } } }
            };
            break;
        default:
            config = {
                type: chartType,
                data: { labels, datasets: [{ data: valores, backgroundColor: colores, borderColor: '#ffffff', borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'right', labels: { font: { size: 10 }, boxWidth: 10 } }, tooltip: { backgroundColor: 'rgba(0,0,0,.85)', callbacks: { label: (ctx) => { const total = valores.reduce((a,b) => a + b, 0); const percentage = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : 0; return `${ctx.label}: ${fmt(ctx.raw)} unidades (${percentage}%)`; } } } } }
            };
    }
    
    vivoCharts.area = new Chart(areaCtx, config);
}

async function cargarProduccionVivo() {
    const areaFilter = document.getElementById('vivoAreaFilter')?.value || '';
    const updateSpan = document.getElementById('vivoLastUpdate');

    // ★ SIEMPRE consultar la fecha de HOY — ignorar filtros del dashboard
    const hoy = new Date();
    const fechaConsulta = `${hoy.getFullYear()}-${String(hoy.getMonth()+1).padStart(2,'0')}-${String(hoy.getDate()).padStart(2,'0')}`;
    
    if (updateSpan) updateSpan.innerHTML = '<i class="bi bi-clock"></i> Actualizando...';
    
    // ★ No usar _t= aquí — el servidor tiene caché de 15s y el timestamp lo rompe
    const url = `?produccion_vivo=1&fecha=${fechaConsulta}&area=${encodeURIComponent(areaFilter)}`;
    
    try {
        const resp = await fetch(url);
        
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        
        const data = await resp.json();
        
        if (!data.success) throw new Error(data.error || 'Sin datos');
        if (!data.data) throw new Error('data.data es undefined');
        
        // ★ DETECT CHANGE: Si los datos no cambiaron, no re-renderizar
        const dataHash = simpleHash(data.data);
        if (dataHash === vivoLastDataHash && updateSpan.innerHTML.includes('✅')) {
            return; // No hacer nada si los datos no cambiaron
        }
        vivoLastDataHash = dataHash;
        
        const { stats, produccion_por_hora, produccion_por_area, produccion_por_empleado, produccion_por_equipo, ultima_actualizacion } = data.data;
        
        // ── KPIs ──────────────────────────────────────
        const totalProdEl = document.getElementById('vivoTotalProd');
        const empleadosEl = document.getElementById('vivoEmpleados');
        const areasEl = document.getElementById('vivoAreas');
        const equiposEl = document.getElementById('vivoEquipos');
        const ordenesEl = document.getElementById('vivoOrdenes');
        
        if (totalProdEl) totalProdEl.innerHTML = fmt(stats?.total_produccion || 0);
        if (empleadosEl) empleadosEl.innerHTML = fmt(stats?.empleados_activos || 0);
        if (areasEl) areasEl.innerHTML = fmt(stats?.areas_activas || 0);
        if (equiposEl) equiposEl.innerHTML = fmt(stats?.equipos_activos || 0);
        if (ordenesEl) ordenesEl.innerHTML = fmt(stats?.ordenes_procesadas || 0);
        
        if (updateSpan) updateSpan.innerHTML = `<i class="bi bi-check-circle text-green-500"></i> ${ultima_actualizacion || new Date().toLocaleTimeString()} (${fechaConsulta})`;
        
        // ── GRÁFICO POR HORA ──────────────────────────
        if (vivoCharts.hora) { try { vivoCharts.hora.destroy(); } catch(e) {} vivoCharts.hora = null; }
        const horaCtx = document.getElementById('vivoHoraChart');
        if (horaCtx && produccion_por_hora?.length) {
            vivoCharts.hora = new Chart(horaCtx, {
                type: 'bar',
                data: {
                    labels: produccion_por_hora.map(h => `${h.hora}:00`),
                    datasets: [{
                        label: 'Unidades',
                        data: produccion_por_hora.map(h => h.produccion),
                        backgroundColor: produccion_por_hora.map(h => h.produccion > 0 ? '#f59e0b' : '#e5e7eb'),
                        borderRadius: 5,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,.85)',
                            callbacks: { label: ctx => `Producción: ${fmt(ctx.parsed.y)} unidades` }
                        }
                    },
                    scales: {
                        x: {
                            title: { display: true, text: 'Hora del Día', color: '#6b7280' },
                            grid: { color: '#f3f4f6' },
                            ticks: { color: '#4b5563', maxRotation: 45, minRotation: 45 },
                        },
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Unidades', color: '#6b7280' },
                            grid: { color: '#e5e7eb' },
                            ticks: { color: '#4b5563', callback: v => fmt(v) },
                        },
                    },
                }
            });
        } else {
            console.warn('⚠️ No hay datos para gráfico de horas o canvas no encontrado');
        }
        
        // ── GRÁFICO POR ÁREA ──────────────────────────
        if (produccion_por_area?.length) {
            actualizarGraficoAreas(produccion_por_area);
            renderRankingAreas(produccion_por_area);
        } else {
            console.warn('⚠️ No hay datos de producción por área');
            const rankingAreas = document.getElementById('vivoRankingAreas');
            if (rankingAreas) rankingAreas.innerHTML = '<div class="text-center py-4 text-gray-400 text-sm">Sin datos de áreas</div>';
        }

        // ── GRÁFICO POR EMPLEADO ──────────────────────
        if (produccion_por_empleado?.length) {
            renderVivoEmpleadosChart(produccion_por_empleado);
        } else {
            console.warn('⚠️ No hay datos de empleados');
            const badge = document.getElementById('vivoEmpleadosCount');
            if (badge) badge.textContent = '0 empleados';
            const rankingEmp = document.getElementById('vivoRankingEmpleados');
            if (rankingEmp) rankingEmp.innerHTML = '<div class="text-center py-4 text-gray-400 text-sm">Sin datos de empleados para esta fecha</div>';
            const empChart = document.getElementById('vivoEmpleadosChart');
            if (empChart && vivoCharts.empleados) {
                try { vivoCharts.empleados.destroy(); } catch(e) {}
                vivoCharts.empleados = null;
            }
        }

        // ── GRÁFICO POR EQUIPO ────────────────────────
        if (produccion_por_equipo?.length) {
            renderVivoEquiposChart(produccion_por_equipo);
        } else {
            console.warn('⚠️ No hay datos de equipos');
            const badge = document.getElementById('vivoEquiposCount');
            if (badge) badge.textContent = '0 equipos';
            const eqChart = document.getElementById('vivoEquiposChart');
            if (eqChart && vivoCharts.equipos) {
                try { vivoCharts.equipos.destroy(); } catch(e) {}
                vivoCharts.equipos = null;
            }
        }
        
        // Cargar la tabla de registros
        await cargarUltimosRegistrosProduccion(areaFilter);

    } catch (err) {
        console.error('❌ Error en cargarProduccionVivo:', err);
        console.error('❌ Stack:', err.stack);
        if (updateSpan) updateSpan.innerHTML = '<i class="bi bi-exclamation-triangle text-red-500"></i> Error al cargar: ' + err.message;
    }
}

function cambiarTipoGraficoArea() {
    cargarProduccionVivo();
}

async function cargarUltimosRegistrosProduccion(areaFilter = '') {
    // ★ SIEMPRE consultar la fecha de HOY como historial del día actual
    const hoy = new Date();
    const fechaConsulta = `${hoy.getFullYear()}-${String(hoy.getMonth()+1).padStart(2,'0')}-${String(hoy.getDate()).padStart(2,'0')}`;
    
    console.log('🔍 Cargando registros de producción para fecha:', fechaConsulta, '(siempre hoy)');
    console.log('🔍 Filtro de área:', areaFilter || 'Todas');
    
    let url = `?detalles_produccion_vivo=1&fecha=${fechaConsulta}`;
    if (areaFilter && areaFilter !== 'todos' && areaFilter !== '') {
        url += `&area=${encodeURIComponent(areaFilter)}`;
    }
    // ★ Sin _t= — el servidor tiene caché de 30s
    
    try {
        const resp = await fetch(url);
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        const data = await resp.json();
        
        console.log('📊 Respuesta de producción:', data);
        
        const tbody = document.getElementById('vivoTablaBody');
        const countSpan = document.getElementById('vivoRegistrosCount');
        
        if (!tbody) {
            console.error('❌ No se encontró el elemento vivoTablaBody');
            return;
        }
        
        if (!data.success || !data.data || data.data.length === 0) {
            vivoRegistrosCache = [];
            console.warn('⚠️ No hay registros de producción para hoy:', fechaConsulta);
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-8 opacity-60">
                <i class="bi bi-inbox text-2xl"></i>
                <p class="mt-2">Sin registros de producción para hoy (${fechaConsulta})</p>
                <p class="text-xs mt-1 text-gray-400">Los datos aparecerán aquí conforme se registren durante el día</p>
            </td></tr>`;
            if (countSpan) countSpan.textContent = '0 registros';
            return;
        }
        
        vivoRegistrosCache = data.data;
        if (countSpan) countSpan.textContent = `${data.data.length} registros`;
        
        const rows = data.data.map(reg => {
            let estadoClass = '', estadoText = '';
            const horaMostrar = reg.hora || 'N/A';
            
            if (reg.estado === 'justo_ahora') { 
                estadoClass = 'bg-green-500/30 text-green-700'; 
                estadoText = '🟢 Justo ahora'; 
            } else if (reg.estado === 'reciente') { 
                estadoClass = 'bg-yellow-500/30 text-yellow-700'; 
                estadoText = '🟡 Reciente'; 
            } else { 
                estadoClass = 'bg-gray-500/30 text-gray-700'; 
                estadoText = '⚪ Anterior'; 
            }
            
            return `<tr class="border-b border-gray-200 hover:bg-gray-50">
                        <td class="p-3 font-mono text-sm">${escapeHtml(horaMostrar)}</td>
                        <td class="p-3 font-medium">${escapeHtml(reg.empleado || 'N/A')}</td>
                        <td class="p-3">${escapeHtml(reg.area || 'N/A')}</td>
                        <td class="p-3">${escapeHtml(reg.equipo || 'N/A')}</td>
                        <td class="p-3 font-mono">${escapeHtml(reg.orden || 'N/A')}</td>
                        <td class="p-3"><span class="${estadoClass} px-2 py-1 rounded-full text-xs font-medium">${estadoText}</span></td>
                      </tr>`;
        }).join('');
        tbody.innerHTML = rows;
        
    } catch (err) {
        console.error('❌ Error cargando últimos registros:', err);
        const tbody = document.getElementById('vivoTablaBody');
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-8 text-red-600">
                <i class="bi bi-exclamation-triangle"></i> Error: ${escapeHtml(err.message)}
            </td></tr>`;
        }
    }
}

// Dentro de la función que renderiza la tabla de órdenes (ej: después de initCharts)
function renderTopOrdenes(ordenesData) {
    const tbody = document.querySelector('#tablaOrdenes tbody');
    if (!tbody || !ordenesData?.length) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-gray-400">No hay datos de órdenes</td></tr>';
        return;
    }
    
    tbody.innerHTML = ordenesData.map(orden => {
        // Determinar el texto y estilo del último movimiento
        let ultimoMovimientoHtml = '';
        let areaEquipoHtml = '';
        
        if (orden.ultimo_movimiento_completo && orden.ultimo_movimiento_completo !== 'N/A') {
            // Hay movimiento real
            ultimoMovimientoHtml = `
                <div class="text-sm font-medium text-gray-800">${escapeHtml(orden.ultimo_movimiento_completo)}</div>
                <div class="text-xs text-gray-500">${escapeHtml(orden.fuente_ultimo_mov || 'producción')}</div>
            `;
            
            areaEquipoHtml = `
                <div class="text-sm text-gray-800">${escapeHtml(orden.ultimo_movimiento_area !== 'N/A' ? orden.ultimo_movimiento_area : 'Sin área')}</div>
                <div class="text-xs text-gray-500">${escapeHtml(orden.ultimo_movimiento_equipo !== 'N/A' ? orden.ultimo_movimiento_equipo : 'Sin equipo')}</div>
            `;
        } else {
            // Sin registro - mostrar mensaje más descriptivo
            ultimoMovimientoHtml = `
                <div class="text-sm text-amber-600 font-medium">
                    <i class="bi bi-clock-history mr-1"></i>Sin actividad reciente
                </div>
                <div class="text-xs text-gray-400">No hay registros de producción</div>
            `;
            
            areaEquipoHtml = `
                <div class="text-sm text-gray-400">
                    <i class="bi bi-question-circle mr-1"></i>No disponible
                </div>
                <div class="text-xs text-gray-400">Sin datos en el período</div>
            `;
        }
        
        // Motivos truncados
        let motivosMostrar = orden.motivos || '';
        if (motivosMostrar.length > 60) {
            motivosMostrar = motivosMostrar.substring(0, 57) + '…';
        }
        
        // Empleados truncados
        let empleadosMostrar = orden.empleados || '';
        if (empleadosMostrar.length > 50) {
            empleadosMostrar = empleadosMostrar.substring(0, 47) + '…';
        }
        
        // Equipos truncados
        let equiposMostrar = orden.equipos || '';
        if (equiposMostrar.length > 40) {
            equiposMostrar = equiposMostrar.substring(0, 37) + '…';
        }
        
        return `<tr class="border-b border-gray-200 hover:bg-gray-50 transition">
            <td class="p-3 font-mono font-semibold text-blue-700 whitespace-nowrap">${escapeHtml(orden.orden)}</td>
            <td class="p-3 text-center font-bold text-red-600">${fmt(orden.total_quiebras)}</td>
            <td class="p-3 text-xs text-gray-600 max-w-[180px] truncate" title="${escapeHtml(orden.motivos || '')}">${escapeHtml(motivosMostrar) || '—'}</td>
            <td class="p-3 text-xs text-gray-600 max-w-[150px] truncate" title="${escapeHtml(orden.empleados || '')}">${escapeHtml(empleadosMostrar) || '—'}</td>
            <td class="p-3 text-xs text-gray-600 max-w-[140px] truncate" title="${escapeHtml(orden.equipos || '')}">${escapeHtml(equiposMostrar) || '—'}</td>
            <td class="p-3 text-xs whitespace-nowrap">
                <span class="bg-gray-100 px-2 py-1 rounded-full text-gray-600">
                    ${escapeHtml(orden.primera_quiebra || 'N/A')} – ${escapeHtml(orden.ultima_quiebra || 'N/A')}
                </span>
            </td>
            <td class="p-3">${ultimoMovimientoHtml}</td>
            <td class="p-3">${areaEquipoHtml}</td>
            <td class="p-3 text-center">
                <button onclick="mostrarDetallesOrden('${escapeHtml(orden.orden).replace(/'/g, "\\'")}')" 
                        class="bg-blue-50 hover:bg-blue-100 text-blue-600 px-3 py-1.5 rounded-lg text-xs font-medium transition flex items-center gap-1 mx-auto">
                    <i class="bi bi-eye"></i> Ver
                </button>
            </td>
        </tr>`;
    }).join('');
}

// ── GRÁFICO TOP EMPLEADOS (VIVO) ────────────────────────────
function renderVivoEmpleadosChart(empleados) {
    const ctx = document.getElementById('vivoEmpleadosChart');
    if (!ctx) return;
    if (vivoCharts.empleados) { try { vivoCharts.empleados.destroy(); } catch(e) {} vivoCharts.empleados = null; }
    if (!empleados?.length) return;

    const top = empleados.slice(0, 10);
    const colores = generarColoresPastel(top.length);
    const total = top.reduce((s, e) => s + (+e.total_produccion || 0), 0);

    // Actualizar badge
    const badge = document.getElementById('vivoEmpleadosCount');
    if (badge) badge.textContent = `${empleados.length} empleados`;

    vivoCharts.empleados = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: top.map(e => e.empleado.length > 14 ? e.empleado.substring(0,14)+'…' : e.empleado),
            datasets: [{
                label: 'Producción',
                data: top.map(e => +e.total_produccion || 0),
                backgroundColor: colores,
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,.85)',
                    callbacks: {
                        label: ctx => {
                            const pct = total > 0 ? ((ctx.raw / total)*100).toFixed(1) : 0;
                            return `${fmt(ctx.raw)} unidades (${pct}%)`;
                        }
                    }
                }
            },
            scales: {
                x: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { color: '#4b5563', callback: v => fmt(v) } },
                y: { grid: { display: false }, ticks: { color: '#374151', font: { size: 11 } } }
            }
        }
    });

    // Ranking empleados
    renderRankingEmpleados(empleados);
}

// ── GRÁFICO TOP EQUIPOS (VIVO) ──────────────────────────────
function renderVivoEquiposChart(equipos) {
    const ctx = document.getElementById('vivoEquiposChart');
    if (!ctx) return;
    if (vivoCharts.equipos) { try { vivoCharts.equipos.destroy(); } catch(e) {} vivoCharts.equipos = null; }
    if (!equipos?.length) return;

    const badge = document.getElementById('vivoEquiposCount');
    if (badge) badge.textContent = `${equipos.length} equipos`;

    const colores = generarColoresPastel(equipos.length);
    const total = equipos.reduce((s, e) => s + (+e.total || 0), 0);

    vivoCharts.equipos = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: equipos.map(e => e.equipo.length > 14 ? e.equipo.substring(0,14)+'…' : e.equipo),
            datasets: [{
                label: 'Producción',
                data: equipos.map(e => +e.total || 0),
                backgroundColor: colores,
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,.85)',
                    callbacks: {
                        label: ctx => {
                            const pct = total > 0 ? ((ctx.raw / total)*100).toFixed(1) : 0;
                            return `${fmt(ctx.raw)} unidades (${pct}%)`;
                        }
                    }
                }
            },
            scales: {
                x: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { color: '#4b5563', callback: v => fmt(v) } },
                y: { grid: { display: false }, ticks: { color: '#374151', font: { size: 11 } } }
            }
        }
    });
}

// ── RANKINGS (barras de progreso) ───────────────────────────
function renderRankingAreas(areas) {
    const el = document.getElementById('vivoRankingAreas');
    if (!el || !areas?.length) { if (el) el.innerHTML = '<div class="text-center py-4 text-gray-400 text-sm">Sin datos</div>'; return; }
    const max = Math.max(...areas.map(a => +a.total_produccion || 0));
    el.innerHTML = areas.map((a, i) => {
        const pct = max > 0 ? Math.round((+a.total_produccion / max) * 100) : 0;
        const medals = ['🥇','🥈','🥉'];
        const medal = i < 3 ? medals[i] : `<span class="text-xs text-gray-400 font-bold">${i+1}</span>`;
        return `<div class="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 transition">
            <span class="w-7 text-center flex-shrink-0">${medal}</span>
            <div class="flex-1 min-w-0">
                <div class="flex justify-between items-center mb-1">
                    <span class="text-sm font-medium text-gray-700 truncate">${escapeHtml(a.area)}</span>
                    <span class="text-sm font-bold text-emerald-600 ml-2 flex-shrink-0">${fmt(a.total_produccion)}</span>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-emerald-500 rounded-full transition-all duration-500" style="width:${pct}%"></div>
                </div>
            </div>
        </div>`;
    }).join('');
}

function renderRankingEmpleados(empleados) {
    const el = document.getElementById('vivoRankingEmpleados');
    if (!el || !empleados?.length) { if (el) el.innerHTML = '<div class="text-center py-4 text-gray-400 text-sm">Sin datos</div>'; return; }
    const max = Math.max(...empleados.map(e => +e.total_produccion || 0));
    el.innerHTML = empleados.slice(0, 15).map((e, i) => {
        const pct = max > 0 ? Math.round((+e.total_produccion / max) * 100) : 0;
        const medals = ['🥇','🥈','🥉'];
        const medal = i < 3 ? medals[i] : `<span class="text-xs text-gray-400 font-bold">${i+1}</span>`;
        const nombre = e.empleado || 'N/A';
        return `<div class="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 transition">
            <span class="w-7 text-center flex-shrink-0">${medal}</span>
            <div class="flex-1 min-w-0">
                <div class="flex justify-between items-center mb-1">
                    <span class="text-sm font-medium text-gray-700 truncate">${escapeHtml(nombre)}</span>
                    <span class="text-sm font-bold text-blue-600 ml-2 flex-shrink-0">${fmt(e.total_produccion)}</span>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-blue-500 rounded-full transition-all duration-500" style="width:${pct}%"></div>
                </div>
            </div>
        </div>`;
    }).join('');
}

// ── EXPORTAR CSV DE REGISTROS VIVO ──────────────────────────
function exportarRegistrosVivo() {
    if (!vivoRegistrosCache?.length) { alert('No hay registros para exportar.'); return; }
    const headers = ['Hora','Empleado','Área','Equipo','Orden','Estado'];
    const rows = vivoRegistrosCache.map(r => [
        r.hora || '', r.empleado || '', r.area || '', r.equipo || '', r.orden || '', r.estado || ''
    ]);
    let csv = headers.join(',') + '\n';
    rows.forEach(r => { csv += r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',') + '\n'; });
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = `produccion_vivo_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// ============================================================
// WIP EMPAQUE - MES EN CURSO (VERSIÓN CORREGIDA)
// ============================================================

// Función cargarWipEmpaque - Versión corregida con órdenes únicas
async function cargarWipEmpaque() {
    const tbodyActivo = document.getElementById('wipTbodyActivo');
    const tbodyFinalizado = document.getElementById('wipTbodyFinalizado');
    const cntActivo = document.getElementById('wipContadorActivo');
    const cntFinalizado = document.getElementById('wipContadorFinalizado');
    const badgeFin = document.getElementById('wipBadgeFinalizado');
    const rangoInfo = document.getElementById('wipRangoInfo');
    const wipDetalleOrigen = document.getElementById('wipDetalleOrigen'); // Nuevo elemento opcional

    if (tbodyActivo) {
        tbodyActivo.innerHTML = `<tr><td colspan="7" class="text-center py-6 text-gray-400">
            <div class="spinner mx-auto mb-2"></div>Cargando WIP del mes... Sed</tr>`;
    }

    console.log('📦 WIP Empaque - Cargando órdenes del mes actual');

    try {
        // ★ Sin _t= — el servidor tiene caché de 5 min; no romper con timestamp
        const url = `?wip_empaque=1`;
        const resp = await fetch(url);
        
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        
        const text = await resp.text();
        const jsonMatch = text.match(/\{[\s\S]*\}/);
        if (!jsonMatch) throw new Error('No se encontró JSON en la respuesta');
        
        const data = JSON.parse(jsonMatch[0]);

        if (!data.success) {
            throw new Error(data.error || 'Error desconocido del servidor');
        }

        const wip = data.wip || [];
        const finalizadas = data.finalizadas || [];

        // Mostrar información del período
        if (rangoInfo) {
            const mesActual = data.nombre_mes_actual || data.mes_actual || 'Mes actual';
            const mesAnterior = data.nombre_mes_anterior || data.mes_anterior || 'Mes anterior';
            rangoInfo.innerHTML = `<i class="bi bi-calendar-month"></i> ${mesActual} | WIP: ${mesAnterior} + ${mesActual} (pendientes)`;
        }

        console.log(`📦 WIP Empaque — WIP: ${data.total_wip} órdenes únicas | Finalizadas: ${data.total_finalizadas}`);
        console.log(`📦 Desglose: Continúa: ${data.contador_origen?.continua || 0}, Pendiente: ${data.contador_origen?.pendiente || 0}, En proceso: ${data.contador_origen?.en_proceso || 0}`);

        // Actualizar contadores - MOSTRAR ÓRDENES ÚNICAS
        if (cntActivo) {
            cntActivo.innerHTML = `
                <div class="text-2xl font-bold">${data.total_wip || 0}</div>
                <div class="text-xs text-gray-500">órdenes únicas</div>
                ${data.total_registros_wip ? `<div class="text-xs text-gray-400">${fmt(data.total_registros_wip)} registros</div>` : ''}
            `;
        }
        
        if (cntFinalizado) cntFinalizado.textContent = data.total_finalizadas || 0;
        if (badgeFin) badgeFin.textContent = data.total_finalizadas || 0;

        // Mostrar desglose por origen (si existe el contenedor)
        if (wipDetalleOrigen && data.contador_origen) {
            wipDetalleOrigen.innerHTML = `
                <div class="grid grid-cols-3 gap-2 text-center text-xs">
                    <div class="bg-purple-50 rounded-lg p-2">
                        <div class="font-bold text-purple-600">${data.contador_origen.continua || 0}</div>
                        <div class="text-gray-500">🔄 Continúa</div>
                    </div>
                    <div class="bg-orange-50 rounded-lg p-2">
                        <div class="font-bold text-orange-600">${data.contador_origen.pendiente || 0}</div>
                        <div class="text-gray-500">⏳ Pendiente</div>
                    </div>
                    <div class="bg-blue-50 rounded-lg p-2">
                        <div class="font-bold text-blue-600">${data.contador_origen.en_proceso || 0}</div>
                        <div class="text-gray-500">🆕 En proceso</div>
                    </div>
                </div>
            `;
        }

        // Tabla de órdenes activas (WIP)
        if (tbodyActivo) {
            if (!wip.length) {
                tbodyActivo.innerHTML = `<tr><td colspan="8" class="text-center py-6 text-gray-400 text-sm">
                    <i class="bi bi-inbox text-2xl block mb-1 opacity-40"></i>
                    No hay órdenes activas en el laboratorio este mes
                </tr>`;
            } else {
                tbodyActivo.innerHTML = wip.map(o => {
                    const orden = escapeHtml(o.orden || 'N/A');
                    const areas = escapeHtml(o.areas || 'N/A');
                    const equipos = escapeHtml(o.equipos || 'N/A');
                    const unidades = fmt(o.total_registros || 0);
                    
                    // Badge de origen
                    let badgeHtml = '';
                    if (o.badge_text) {
                        const badgeColor = o.badge_color === 'purple' ? 'bg-purple-100 text-purple-700' :
                                          o.badge_color === 'orange' ? 'bg-orange-100 text-orange-700' :
                                          o.badge_color === 'blue' ? 'bg-blue-100 text-blue-700' :
                                          'bg-gray-100 text-gray-700';
                        badgeHtml = `<span class="${badgeColor} text-xs px-2 py-0.5 rounded-full ml-2">${o.badge_icon || ''} ${escapeHtml(o.badge_text)}</span>`;
                    }
                    
                    // Último movimiento
                    let areaMov = o.ultimo_movimiento_area;
                    let equipoMov = o.ultimo_movimiento_equipo;
                    
                    if (!areaMov || areaMov === 'N/A' || areaMov === '') {
                        areaMov = '<span class="text-gray-400">No registrada</span>';
                    } else {
                        areaMov = `<span class="text-orange-700">${escapeHtml(areaMov)}</span>`;
                    }
                    
                    if (!equipoMov || equipoMov === 'N/A' || equipoMov === '') {
                        equipoMov = '<span class="text-gray-400">No registrado</span>';
                    } else {
                        equipoMov = `<span class="text-orange-700">${escapeHtml(equipoMov)}</span>`;
                    }
                    
                    let ultimoMovHtml = '';
                                if (o.ultimo_movimiento_completo && o.ultimo_movimiento_completo !== 'N/A') {
                        ultimoMovHtml = `
                            <div class="text-xs">
                                <div class="font-mono font-bold text-orange-700">${escapeHtml(o.ultimo_movimiento_completo)}</div>
                                <div class="text-gray-600 text-xs mt-0.5">
                                    <i class="bi bi-building mr-1"></i>${areaMov} | 
                                    <i class="bi bi-cpu mr-1"></i>${equipoMov}
                                </div>
                                <div class="text-gray-400 text-[10px] mt-0.5">Fuente: ${escapeHtml(o.fuente_ultimo_mov || 'producción')}</div>
                            </div>
                        `;
                    } else {
                        ultimoMovHtml = `<span class="text-xs text-gray-400">Sin movimiento reciente</span>`;
                    }
                    
                    return `<tr class="border-b border-orange-50 hover:bg-orange-50/50 transition">
                        <td class="p-3 font-mono font-semibold text-orange-800">
                            ${orden}
                            ${badgeHtml}
                        </td>
                        <td class="p-3 text-center font-bold text-gray-700">${unidades}</td>
                        <td class="p-3 text-xs text-gray-600 max-w-[160px] truncate" title="${areas}">${areas}</td>
                        <td class="p-3 text-xs text-gray-600 max-w-[140px] truncate" title="${equipos}">${equipos}</td>
                        <td class="p-3 text-center text-xs text-gray-500">${escapeHtml(o.primera_produccion_display || 'N/A')}</td>
                        <td class="p-3">${ultimoMovHtml}</td>
                        <td class="p-3 text-center">
                            <div class="flex items-center justify-center gap-1">
                            <button onclick="mostrarDetallesOrden('${escapeHtml(o.orden).replace(/'/g, "\\'")}')" 
                                    class="bg-orange-50 hover:bg-orange-100 text-orange-600 px-2 py-1 rounded text-xs transition">
                                <i class="bi bi-eye"></i> Ver
                            </button>
                            ${(window.AppData?.userRol === 'administrador') ? `
                            <button onclick="eliminarOrdenCompleta('${escapeHtml(o.orden).replace(/'/g, "\\'")}')"
                                    class="bg-red-50 hover:bg-red-100 text-red-500 px-2 py-1 rounded text-xs transition"
                                    title="Eliminar orden (solo si está cancelada en Odoo)">
                                <i class="bi bi-trash3"></i>
                            </button>` : ''}
                            </div>
                        </td>
                    </tr>`;
                }).join('');
            }
        }

        // Tabla de órdenes finalizadas
        if (tbodyFinalizado) {
            if (!finalizadas.length) {
                tbodyFinalizado.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-gray-400 text-sm">Sin órdenes finalizadas este mes</tr>`;
            } else {
                tbodyFinalizado.innerHTML = finalizadas.map(o => {
                    const orden = escapeHtml(o.orden || 'N/A');
                    const areas = escapeHtml(o.areas || 'N/A');
                    const unidades = fmt(o.total_registros || 0);
                    let badgeOrigen = '';
                    if (o.actividad_mes_anterior) {
                        badgeOrigen = '<span class="bg-purple-100 text-purple-700 text-xs px-1.5 py-0.5 rounded-full ml-2">Abril→Mayo</span>';
                    }
                    return `<tr class="border-b border-green-50 hover:bg-green-50/50 transition">
                        <td class="p-3 font-mono font-semibold text-green-800">
                            ${orden}
                            ${badgeOrigen}
                        </td>
                        <td class="p-3 text-center font-bold text-gray-700">${unidades}</td>
                        <td class="p-3 text-xs text-gray-600 max-w-[200px] truncate" title="${areas}">${areas}</td>
                        <td class="p-3 text-center text-xs text-gray-500">${escapeHtml(o.primera_produccion_display || 'N/A')}</td>
                        <td class="p-3 text-center">
                            <span class="bg-green-100 text-green-700 text-xs font-mono px-2 py-0.5 rounded-full">
                                <i class="bi bi-check-circle-fill mr-1"></i>${escapeHtml(o.hora_bodega || 'N/A')}
                            </span>
                        </td>
                        <td class="p-3 text-center">
                            <div class="flex items-center justify-center gap-1">
                            <button onclick="mostrarDetallesOrden('${escapeHtml(o.orden).replace(/'/g, "\\'")}')" 
                                    class="bg-green-50 hover:bg-green-100 text-green-600 px-2 py-1 rounded text-xs transition">
                                <i class="bi bi-eye"></i> Ver
                            </button>
                            ${(window.AppData?.userRol === 'administrador') ? `
                            <button onclick="eliminarOrdenCompleta('${escapeHtml(o.orden).replace(/'/g, "\\'")}')"
                                    class="bg-red-50 hover:bg-red-100 text-red-500 px-2 py-1 rounded text-xs transition"
                                    title="Eliminar orden (solo si está cancelada en Odoo)">
                                <i class="bi bi-trash3"></i>
                            </button>` : ''}
                            </div>
                        </td>
                    </tr>`;
                }).join('');
            }
        }

    } catch (err) {
        console.error('❌ Error WIP Empaque:', err);
        const cntActivo = document.getElementById('wipContadorActivo');
        if (cntActivo) cntActivo.textContent = '!';
        const tbodyActivo = document.getElementById('wipTbodyActivo');
        if (tbodyActivo) {
            tbodyActivo.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-red-500 text-sm">
                <i class="bi bi-exclamation-triangle"></i> ${escapeHtml(err.message)}
            </tr>`;
        }
    }
}

// ============================================================
// AUTO-REFRESH: PRODUCCIÓN (60 seg) y WIP (1 hora)
// ============================================================

let vivoFilterDebounceTimer = null;

function iniciarAutoRefreshVivo() {
    console.log('🔄 Iniciando auto-refresh - Producción: 60 seg | WIP: 1 hora');
    
    // Detener intervalos existentes
    if (vivoInterval) {
        clearInterval(vivoInterval);
        vivoInterval = null;
    }
    if (wipInterval) {
        clearInterval(wipInterval);
        wipInterval = null;
    }
    
    // Carga inicial
    cargarProduccionVivo();
    cargarWipEmpaque();
    
    // Intervalo PRODUCCIÓN: cada 60 SEGUNDOS (mejor caché + menos carga)
    vivoInterval = setInterval(() => {
        console.log('🟢 Auto-refresh PRODUCCIÓN ejecutándose cada 60s...');
        cargarProduccionVivo();
    }, 60000); // 60,000 ms = 60 segundos
    
    // Intervalo WIP: cada 5 MINUTOS (alineado con caché del servidor)
    wipInterval = setInterval(() => {
        console.log('📦 Auto-refresh WIP ejecutándose cada 5 min...');
        cargarWipEmpaque();
    }, 300000); // 300,000 ms = 5 minutos
    
    // Agregar listener con debounce al filtro de área
    const vivoAreaFilter = document.getElementById('vivoAreaFilter');
    if (vivoAreaFilter) {
        vivoAreaFilter.addEventListener('change', () => {
            clearTimeout(vivoFilterDebounceTimer);
            vivoFilterDebounceTimer = setTimeout(() => {
                console.log('🔄 Filtro de área cambió, recargando...');
                cargarProduccionVivo();
                cargarUltimosRegistrosProduccion(vivoAreaFilter.value);
            }, 500); // Esperar 500ms antes de cargar
        });
    }
}

function detenerAutoRefreshVivo() {
    console.log('🛑 Deteniendo auto-refresh');
    
    if (vivoInterval) {
        clearInterval(vivoInterval);
        vivoInterval = null;
    }
    if (wipInterval) {
        clearInterval(wipInterval);
        wipInterval = null;
    }
}

// ============================================================
// REPORTES AVANZADOS
// ============================================================

let tipoReporteActual = 'completo';

function abrirModalReportes() {
    const modal = document.getElementById('modalReportes');
    if (modal) modal.classList.remove('hidden');
}

function cerrarModalReportes() {
    const modal = document.getElementById('modalReportes');
    if (modal) modal.classList.add('hidden');
}

async function previsualizarReporte() {
    const fechaDesde = document.getElementById('reporteFechaDesde').value;
    const fechaHasta = document.getElementById('reporteFechaHasta').value;
    const horaDesdeTime = document.getElementById('reporteHoraDesdeTime').value;
    const horaDesdeAmpm = document.getElementById('reporteHoraDesdeAmpm').value;
    const horaHastaTime = document.getElementById('reporteHoraHastaTime').value;
    const horaHastaAmpm = document.getElementById('reporteHoraHastaAmpm').value;
    
    const url = `?previsualizar_reporte=1&fecha_desde=${fechaDesde}&fecha_hasta=${fechaHasta}&hora_desde_time=${horaDesdeTime}&hora_desde_ampm=${horaDesdeAmpm}&hora_hasta_time=${horaHastaTime}&hora_hasta_ampm=${horaHastaAmpm}&tipo=${tipoReporteActual}&_t=${Date.now()}`;
    
    try {
        const resp = await fetch(url);
        if (!resp.ok) throw new Error('Error en la petición');
        const data = await resp.json();
        const previewContainer = document.getElementById('previewContainer');
        const reporteInfo = document.getElementById('reporteInfo');
        
        if (!data.success || !data.data?.length) {
            if (previewContainer) previewContainer.innerHTML = '<div class="text-center py-8 text-gray-500">No hay datos para el período seleccionado</div>';
            if (reporteInfo) reporteInfo.innerHTML = 'Total: 0 registros';
            return;
        }
        
        const headers = Object.keys(data.data[0]);
        const previewData = data.data.slice(0, 100);
        let html = '<table class="w-full text-xs"><thead class="bg-gray-100 sticky top-0"><tr>';
        headers.forEach(h => html += `<th class="p-2 text-left text-gray-700">${escapeHtml(h)}</th>`);
        html += '</table></thead><tbody>';
        previewData.forEach(row => {
            html += '<tr class="border-b border-gray-200 hover:bg-gray-50">';
            headers.forEach(h => {
                let val = row[h] ?? '';
                if (val.length > 50) val = val.substring(0, 50) + '…';
                html += `<td class="p-2 text-gray-600">${escapeHtml(String(val))}</td>`;
            });
            html += '</tr>';
        });
        if (data.data.length > 100) html += `<tr class="bg-gray-50"><td colspan="${headers.length}" class="p-3 text-center text-gray-500">Mostrando 100 de ${data.data.length} registros totales</td></tr>`;
        html += '</tbody></table>';
        if (previewContainer) previewContainer.innerHTML = html;
        if (reporteInfo) reporteInfo.innerHTML = `Total: ${data.total} registros | Mostrando: ${Math.min(100, data.total)}`;
    } catch (err) {
        console.error('Error en previsualización:', err);
        const previewContainer = document.getElementById('previewContainer');
        if (previewContainer) previewContainer.innerHTML = `<div class="text-center py-8 text-red-600">Error al cargar los datos: ${escapeHtml(err.message)}</div>`;
    }
}

function descargarReporte() {
    const fechaDesde = document.getElementById('reporteFechaDesde').value;
    const fechaHasta = document.getElementById('reporteFechaHasta').value;
    const horaDesdeTime = document.getElementById('reporteHoraDesdeTime').value;
    const horaDesdeAmpm = document.getElementById('reporteHoraDesdeAmpm').value;
    const horaHastaTime = document.getElementById('reporteHoraHastaTime').value;
    const horaHastaAmpm = document.getElementById('reporteHoraHastaAmpm').value;
    const tipo = tipoReporteActual;
    
    // Mostrar loading
    const btn = document.getElementById('btnDescargarReporte');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-hourglass-split spin"></i> Generando...';
    btn.disabled = true;
    
    let url = '';
    
    switch(tipo) {
        case 'completo':
            // Usar el endpoint existente que genera ZIP con 3 archivos
            url = `?exportar_reporte_completo=1&reporte_fecha_desde=${fechaDesde}&reporte_fecha_hasta=${fechaHasta}&reporte_hora_desde_time=${horaDesdeTime}&reporte_hora_desde_ampm=${horaDesdeAmpm}&reporte_hora_hasta_time=${horaHastaTime}&reporte_hora_hasta_ampm=${horaHastaAmpm}&tipo_reporte=completo`;
            break;
        case 'area':
            url = `?exportar_area=1&fecha_desde=${fechaDesde}&fecha_hasta=${fechaHasta}&hora_desde_time=${horaDesdeTime}&hora_desde_ampm=${horaDesdeAmpm}&hora_hasta_time=${horaHastaTime}&hora_hasta_ampm=${horaHastaAmpm}`;
            break;
        case 'equipo':
            url = `?exportar_equipo=1&fecha_desde=${fechaDesde}&fecha_hasta=${fechaHasta}`;
            break;
        case 'empleado':
            url = `?exportar_empleado=1&fecha_desde=${fechaDesde}&fecha_hasta=${fechaHasta}&hora_desde_time=${horaDesdeTime}&hora_desde_ampm=${horaDesdeAmpm}&hora_hasta_time=${horaHastaTime}&hora_hasta_ampm=${horaHastaAmpm}`;
            break;
        case 'quiebras':
            url = `?exportar_quiebras=1&fecha_desde=${fechaDesde}&fecha_hasta=${fechaHasta}&hora_desde_time=${horaDesdeTime}&hora_desde_ampm=${horaDesdeAmpm}&hora_hasta_time=${horaHastaTime}&hora_hasta_ampm=${horaHastaAmpm}`;
            break;
        default:
            url = `?exportar_produccion=1&fecha_desde=${fechaDesde}&fecha_hasta=${fechaHasta}&hora_desde_time=${horaDesdeTime}&hora_desde_ampm=${horaDesdeAmpm}&hora_hasta_time=${horaHastaTime}&hora_hasta_ampm=${horaHastaAmpm}`;
    }
    
    // Abrir en nueva ventana para descargar
    window.open(url, '_blank');
    
    // Restaurar botón
    setTimeout(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }, 2000);
}

function initReportes() {
    const btnAbrir = document.getElementById('btnAbrirReportes');
    if (btnAbrir) btnAbrir.addEventListener('click', abrirModalReportes);
    
    document.querySelectorAll('.tipo-reporte-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tipo-reporte-btn').forEach(b => b.classList.remove('ring-2', 'ring-white'));
            btn.classList.add('ring-2', 'ring-white');
            tipoReporteActual = btn.dataset.tipo;
        });
    });
    
    const primerBtn = document.querySelector('.tipo-reporte-btn');
    if (primerBtn) { primerBtn.classList.add('ring-2', 'ring-white'); tipoReporteActual = primerBtn.dataset.tipo; }
    
    const btnPrevisualizar = document.getElementById('btnPrevisualizar');
    if (btnPrevisualizar) btnPrevisualizar.addEventListener('click', previsualizarReporte);
    
    const btnDescargar = document.getElementById('btnDescargarReporte');
    if (btnDescargar) btnDescargar.addEventListener('click', descargarReporte);
    
    document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModalReportes(); });
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, m => {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// ============================================================
// CSS para el spinner (agregar dinámicamente)
// ============================================================

(function addSpinCSS() {
    if (!document.getElementById('spin-css')) {
        const style = document.createElement('style');
        style.id = 'spin-css';
        style.textContent = `
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            .spin {
                animation: spin 1s linear infinite;
                display: inline-block;
            }
        `;
        document.head.appendChild(style);
    }
})();

// ============================================================
// FUNCIONES FALTANTES PARA MODALES - CORREGIDAS
// ============================================================

window.mostrarDetallesEmpleado = async function(empleado) {
    console.log('🔍 mostrarDetallesEmpleado:', empleado);
    
    if (!empleado) return;
    
    const modal = document.getElementById('modalDetalles');
    const titulo = document.getElementById('modalTitulo');
    const cuerpo = document.getElementById('modalCuerpo');
    const info = document.getElementById('modalInfo');
    const exportBtn = document.getElementById('modalExportBtn');
    
    if (!modal) return;
    
    modal.classList.remove('hidden');
    if (titulo) titulo.innerHTML = `<i class="bi bi-person-badge"></i> Estadísticas de: ${escapeHtml(empleado)}`;
    if (cuerpo) cuerpo.innerHTML = `<div class="text-center py-12"><div class="spinner mx-auto mb-4"></div><p>Cargando estadísticas detalladas...</p></div>`;
    
    try {
        const form = document.getElementById('filterForm');
        const formData = new FormData(form);
        
        // Obtener fechas del formulario
        let fechaInicio = formData.get('fecha_inicio');
        let fechaFin = formData.get('fecha_fin');
        
        // Si no hay fechas en el formulario, usar valores por defecto (últimos 30 días)
        if (!fechaInicio) {
            const hoy = new Date();
            fechaFin = `${hoy.getFullYear()}-${String(hoy.getMonth()+1).padStart(2,'0')}-${String(hoy.getDate()).padStart(2,'0')}`;
            const fechaInicioObj = new Date();
            fechaInicioObj.setDate(fechaInicioObj.getDate() - 30);
            fechaInicio = `${fechaInicioObj.getFullYear()}-${String(fechaInicioObj.getMonth()+1).padStart(2,'0')}-${String(fechaInicioObj.getDate()).padStart(2,'0')}`;
        }
        
        console.log('📅 Fechas seleccionadas:', fechaInicio, '→', fechaFin);
        
        const params = new URLSearchParams();
        params.append('detalles_empleado_completo', empleado);
        params.append('fecha_inicio', fechaInicio);
        params.append('fecha_fin', fechaFin);
        params.append('_t', Date.now());
        
        const resp = await fetch(`?${params.toString()}`);
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        
        const data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Error al cargar datos');
        
        console.log('📊 Datos recibidos:', {
            total_produccion: data.total_produccion,
            total_quiebras: data.total_quiebras,
            eficiencia: data.eficiencia,
            productividad_hora: data.productividad_hora,
            ratio_prod_quiebra: data.ratio_prod_quiebra,
            horas: data.produccion_por_hora?.length,
            dias: data.produccion_por_dia?.length,
            tendencia: data.tendencia?.length,
            registros: data.registros?.length
        });
        
        // Verificar que los datos de gráficos existan
        const tieneHoras = data.produccion_por_hora && data.produccion_por_hora.length > 0;
        const tieneDias = data.produccion_por_dia && data.produccion_por_dia.length > 0;
        const tieneTendencia = data.tendencia && data.tendencia.length > 0;
        
        console.log('📊 Datos de gráficos - Horas:', tieneHoras, 'Días:', tieneDias, 'Tendencia:', tieneTendencia);
        
        // Función auxiliar para formatear fechas
        const formatLocalDate = (dateStr) => {
            if (!dateStr) return 'N/A';
            const d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            return `${d.getDate().toString().padStart(2,'0')}/${(d.getMonth()+1).toString().padStart(2,'0')}/${d.getFullYear()}`;
        };
        
        // Calcular ratio si no viene
        const ratioProdQuiebra = data.ratio_prod_quiebra || 
            (data.total_quiebras > 0 ? (data.total_produccion / data.total_quiebras).toFixed(1) : data.total_produccion);
        
        let html = `
            <!-- KPIs -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-emerald-600">${fmt(data.total_produccion || 0)}</div>
                    <div class="text-xs text-gray-600">Producción</div>
                </div>
                <div class="bg-gradient-to-br from-red-50 to-red-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-red-600">${fmt(data.total_quiebras || 0)}</div>
                    <div class="text-xs text-gray-600">Quiebras</div>
                </div>
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-blue-600">${data.eficiencia || 100}%</div>
                    <div class="text-xs text-gray-600">Eficiencia</div>
                </div>
                <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-purple-600">${data.productividad_hora || 0}</div>
                    <div class="text-xs text-gray-600">Prod/hora</div>
                </div>
                <div class="bg-gradient-to-br from-amber-50 to-amber-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-amber-600">${ratioProdQuiebra}</div>
                    <div class="text-xs text-gray-600">Ratio Prod/Quiebra</div>
                </div>
                <div class="bg-gradient-to-br from-slate-50 to-slate-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-slate-600">${data.equipos_count || 0}</div>
                    <div class="text-xs text-gray-600">Equipos</div>
                </div>
            </div>
            <div class="mb-4">
                <h4 class="font-semibold text-gray-700 mb-2">Equipos donde produjo</h4>
                <p class="text-sm text-gray-600">${(() => {
                    const equipos = Array.isArray(data.equipos) ? data.equipos.filter(item => item && item.equipo) : [];
                    if (equipos.length === 0) return 'No aplica';
                    const lista = equipos.slice(0, 20).map(item => `${escapeHtml(item.equipo)} (${item.total})`).join(', ');
                    return escapeHtml(equipos.length > 20 ? `${lista}...` : lista);
                })()}</p>
            </div>
            <!-- Gráficos fila 1 -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
                <div>
                    <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                        <i class="bi bi-clock-history text-amber-500"></i> Producción por Hora
                    </h4>
                    <div class="chart-container" style="height: 250px;">
                        <canvas id="empleadoHoraChart" style="width:100%; height:250px;"></canvas>
                    </div>
                </div>
                <div>
                    <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                        <i class="bi bi-calendar-week text-cyan-500"></i> Producción por Día de Semana
                    </h4>
                    <div class="chart-container" style="height: 250px;">
                        <canvas id="empleadoDiaChart" style="width:100%; height:250px;"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Gráfico de tendencia -->
            <div class="mb-6">
                <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                    <i class="bi bi-graph-up text-blue-500"></i> Tendencia Diaria
                </h4>
                <div class="chart-container" style="height: 280px;">
                    <canvas id="empleadoTendenciaChart" style="width:100%; height:280px;"></canvas>
                </div>
            </div>
            
            <!-- Tabla de registros recientes -->
            <div class="mt-4">
                <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                    <i class="bi bi-table text-gray-500"></i> Registros Recientes
                </h4>
                <div class="overflow-x-auto max-h-[250px] overflow-y-auto border rounded-lg">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 sticky top-0">
                            <tr><th class="p-2">Fecha</th><th class="p-2">Hora</th><th class="p-2">Orden</th><th class="p-2">Área</th><th class="p-2">Tipo</th><th class="p-2">Detalle</th></tr>
                        </thead>
                        <tbody>
${(data.registros || []).slice(0, 50).map(r => {
    let horaMostrar = r.hora || 'N/A';
    if (horaMostrar === '00:00' || horaMostrar === '00:00:00') {
        horaMostrar = '<span class="text-gray-400">--:--</span>';
    }
    const fechaObj = new Date(r.fecha);
    const fechaStr = !isNaN(fechaObj.getTime()) ? 
        `${fechaObj.getDate().toString().padStart(2,'0')}/${(fechaObj.getMonth()+1).toString().padStart(2,'0')}/${fechaObj.getFullYear()}` : 
        (r.fecha || 'N/A');
    return `<tr class="border-b hover:bg-gray-50">
        <td class="p-2 text-xs whitespace-nowrap">${fechaStr}</td>
        <td class="p-2 text-xs font-mono">${horaMostrar}</td>
        <td class="p-2 text-xs">${escapeHtml(r.empleado || 'N/A')}</td>
        <td class="p-2 text-xs font-mono">${escapeHtml(r.orden || 'N/A')}</td>
        <td class="p-2 text-xs">${escapeHtml(r.area || 'N/A')}</td>
        <td class="p-2 text-xs max-w-[200px] truncate" title="${escapeHtml(r.motivo || '')}">${escapeHtml((r.motivo || 'N/A').substring(0, 50))}</td>
    </tr>`;
}).join('')}
                            ${(!data.registros || data.registros.length === 0) ? '<tr><td colspan="6" class="p-4 text-center text-gray-400">No hay registros disponibles</td>' : ''}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
        
        if (cuerpo) cuerpo.innerHTML = html;
        
        // Inicializar gráficos después de que el DOM se actualice
        setTimeout(() => {
            console.log('📊 Inicializando gráficos para empleado:', empleado);
            
            // Verificar que los canvas existen
            const horaCanvas = document.getElementById('empleadoHoraChart');
            const diaCanvas = document.getElementById('empleadoDiaChart');
            const tendenciaCanvas = document.getElementById('empleadoTendenciaChart');
            
            console.log('📊 Canvas encontrados - Hora:', !!horaCanvas, 'Día:', !!diaCanvas, 'Tendencia:', !!tendenciaCanvas);
            
            // Gráfico por hora
            if (data.produccion_por_hora && data.produccion_por_hora.length > 0) {
                console.log('📊 Inicializando gráfico de horas con', data.produccion_por_hora.length, 'datos');
                initEmpleadoHoraChart(data.produccion_por_hora);
            } else {
                console.warn('⚠️ Sin datos de producción por hora');
                mostrarMensajeSinDatosEnCanvas('empleadoHoraChart', 'Sin datos de producción por hora');
            }
            
            // Gráfico por día de semana
            if (data.produccion_por_dia && data.produccion_por_dia.length > 0) {
                console.log('📊 Inicializando gráfico de días con', data.produccion_por_dia.length, 'datos');
                initEmpleadoDiaChart(data.produccion_por_dia);
            } else {
                console.warn('⚠️ Sin datos de producción por día');
                mostrarMensajeSinDatosEnCanvas('empleadoDiaChart', 'Sin datos de producción por día');
            }
            
            // Gráfico de tendencia
            if (data.tendencia && data.tendencia.length > 0) {
                console.log('📊 Inicializando gráfico de tendencia con', data.tendencia.length, 'datos');
                initEmpleadoTendenciaChart(data.tendencia);
            } else {
                console.warn('⚠️ Sin datos de tendencia diaria');
                mostrarMensajeSinDatosEnCanvas('empleadoTendenciaChart', 'Sin datos de tendencia diaria');
            }
        }, 200);
        
        if (exportBtn) {
            exportBtn.onclick = () => exportarEmpleadoStats(data, empleado);
        }
        
        // Información del período
        const periodoInicio = formatLocalDate(data.fecha_inicio) || 'N/A';
        const periodoFin = formatLocalDate(data.fecha_fin) || 'N/A';
        if (info) info.innerHTML = `<span class="opacity-70">Período:</span> ${periodoInicio} → ${periodoFin}`;
        
    } catch (err) {
        console.error('❌ Error en mostrarDetallesEmpleado:', err);
        if (cuerpo) cuerpo.innerHTML = `<div class="text-center py-8 text-red-500">Error: ${escapeHtml(err.message)}</div>`;
    }
};

// Función auxiliar para mostrar mensaje cuando no hay datos en un canvas
function mostrarMensajeSinDatosEnCanvas(canvasId, mensaje) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    
    const parent = canvas.parentElement;
    if (!parent) return;
    
    // Guardar referencia al canvas
    canvas.style.display = 'none';
    
    // Verificar si ya existe un mensaje
    let msgDiv = parent.querySelector('.no-data-message');
    if (!msgDiv) {
        msgDiv = document.createElement('div');
        msgDiv.className = 'no-data-message text-center py-8 text-gray-400';
        msgDiv.innerHTML = `<i class="bi bi-bar-chart-slash text-2xl"></i><p class="mt-2 text-sm">${mensaje}</p>`;
        parent.appendChild(msgDiv);
    }
}

// Función para limpiar mensajes de sin datos
function limpiarMensajesSinDatos(parentElement) {
    if (!parentElement) return;
    const msgs = parentElement.querySelectorAll('.no-data-message');
    msgs.forEach(msg => msg.remove());
}

// Función global para mostrar detalles de área (VERSIÓN CORREGIDA CON QUIEBRAS)
window.mostrarDetallesArea = async function(area) {
    console.log('🔍 mostrarDetallesArea:', area);
    if (!area) return;
    
    const modal = document.getElementById('modalDetalles');
    const titulo = document.getElementById('modalTitulo');
    const cuerpo = document.getElementById('modalCuerpo');
    const info = document.getElementById('modalInfo');
    const exportBtn = document.getElementById('modalExportBtn');
    
    if (!modal) return;
    
    modal.classList.remove('hidden');
    if (titulo) titulo.innerHTML = `<i class="bi bi-building"></i> Estadísticas del Área: ${escapeHtml(area)}`;
    if (cuerpo) cuerpo.innerHTML = `<div class="text-center py-12"><div class="spinner mx-auto mb-4"></div><p>Cargando estadísticas...</p></div>`;
    
    try {
        const form = document.getElementById('filterForm');
        const formData = new FormData(form);
        const params = new URLSearchParams();
        
        params.append('detalles_area_completo', area);
        params.append('_t', Date.now());
        
        // Obtener fechas del formulario de filtros
        const fechaInicio = formData.get('fecha_inicio');
        const fechaFin = formData.get('fecha_fin');
        
        console.log('📅 Fechas del dashboard (formulario):', fechaInicio, '→', fechaFin);
        
        if (fechaInicio) params.append('fecha_inicio', fechaInicio);
        if (fechaFin) params.append('fecha_fin', fechaFin);
        
        // También pasar las horas para mantener consistencia
        const horaInicioTime = formData.get('hora_inicio_time');
        const horaInicioAmpm = formData.get('hora_inicio_ampm');
        const horaFinTime = formData.get('hora_fin_time');
        const horaFinAmpm = formData.get('hora_fin_ampm');
        
        if (horaInicioTime) params.append('hora_inicio_time', horaInicioTime);
        if (horaInicioAmpm) params.append('hora_inicio_ampm', horaInicioAmpm);
        if (horaFinTime) params.append('hora_fin_time', horaFinTime);
        if (horaFinAmpm) params.append('hora_fin_ampm', horaFinAmpm);
        
        const url = `?${params.toString()}`;
        console.log('🔗 URL completa:', url);
        
        const resp = await fetch(url);
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        
        const data = await resp.json();
        console.log('📊 Datos recibidos del backend:', {
            success: data.success,
            total_produccion: data.total_produccion,
            total_quiebras: data.total_quiebras,
            empleados_activos: data.empleados_activos,
            fecha_inicio: data.fecha_inicio,
            fecha_fin: data.fecha_fin,
            top_empleados_count: data.top_empleados?.length || 0,
            registros_quiebras_count: data.registros_quiebras?.length || 0
        });
        
        if (!data.success) throw new Error(data.error || 'Error al cargar datos');
        
        const formatLocalDate = (dateStr) => {
            if (!dateStr) return 'N/A';
            const d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            return `${d.getDate().toString().padStart(2,'0')}/${(d.getMonth()+1).toString().padStart(2,'0')}/${d.getFullYear()}`;
        };
        
        let html = `
            <!-- KPIs -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-emerald-600">${fmt(data.total_produccion || 0)}</div>
                    <div class="text-xs text-gray-600">Producción Total</div>
                </div>
                <div class="bg-gradient-to-br from-red-50 to-red-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-red-600">${fmt(data.total_quiebras || 0)}</div>
                    <div class="text-xs text-gray-600">Quiebras</div>
                </div>
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-blue-600">${data.eficiencia || 100}%</div>
                    <div class="text-xs text-gray-600">Eficiencia</div>
                </div>
                <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-purple-600">${data.empleados_activos || 0}</div>
                    <div class="text-xs text-gray-600">Empleados Activos</div>
                </div>
            </div>
            
            <!-- Gráficos fila 1 -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
                <div>
                    <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                        <i class="bi bi-clock-history text-amber-500"></i> Producción por Hora
                    </h4>
                    <div class="chart-container" style="height: 250px;">
                        <canvas id="areaHoraChart" style="width:100%; height:250px;"></canvas>
                    </div>
                </div>
                <div>
                    <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                        <i class="bi bi-trophy-fill text-amber-500"></i> Top Empleados (con quiebras incluidas)
                    </h4>
                    <div class="overflow-x-auto max-h-[300px] overflow-y-auto border rounded-lg">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="p-2 text-left">Empleado</th>
                                    <th class="p-2 text-center">Producción</th>
                                    <th class="p-2 text-center">Quiebras</th>
                                    <th class="p-2 text-center">Eficiencia</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${(data.top_empleados || []).map(emp => `
                                <tr class="border-b hover:bg-gray-50 cursor-pointer" onclick="mostrarDetallesEmpleado('${escapeHtml(emp.empleado).replace(/'/g, "\\'")}')">
                                    <td class="p-2 text-sm font-medium">${escapeHtml(emp.empleado)}</td>
                                    <td class="p-2 text-center text-emerald-600 font-semibold">${fmt(emp.produccion)}</td>
                                    <td class="p-2 text-center ${emp.quiebras > 0 ? 'text-red-600 font-bold' : 'text-gray-400'}">${fmt(emp.quiebras)}</td>
                                    <td class="p-2 text-center ${emp.eficiencia >= 95 ? 'text-emerald-600' : (emp.eficiencia >= 90 ? 'text-amber-600' : 'text-red-600')}">${emp.eficiencia}%</td>
                                </tr>
                                `).join('')}
                                ${(!data.top_empleados || data.top_empleados.length === 0) ? '<tr><td colspan="4" class="p-4 text-center text-gray-400">No hay empleados en esta área</td></tr>' : ''}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mb-6">
                <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                    <i class="bi bi-cpu-fill text-purple-500"></i> Producción por Equipo
                </h4>
                <div class="overflow-x-auto max-h-[280px] overflow-y-auto border rounded-lg">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 sticky top-0">
                            <tr>
                                <th class="p-2 text-left">Equipo</th>
                                <th class="p-2 text-center">Producción</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${(data.produccion_por_equipo || []).map(eq => `
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-2 text-sm font-medium">${escapeHtml(eq.equipo)}</td>
                                <td class="p-2 text-center text-emerald-600 font-semibold">${fmt(eq.produccion)}</td>
                            </tr>
                            `).join('')}
                            ${(!data.produccion_por_equipo || data.produccion_por_equipo.length === 0) ? '<tr><td colspan="2" class="p-4 text-center text-gray-400">No hay datos de producción por equipo para esta área</td></tr>' : ''}
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Gráfico de tendencia -->
            <div class="mb-6">
                <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                    <i class="bi bi-graph-up text-blue-500"></i> Tendencia Diaria de Producción
                </h4>
                <div class="chart-container" style="height: 280px;">
                    <canvas id="areaTendenciaChart" style="width:100%; height:280px;"></canvas>
                </div>
            </div>
            
            <!-- Tabla de quiebras del área -->
            <div class="mt-4">
                <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill text-red-500"></i> Registros de Quiebras en el Área
                </h4>
                <div class="overflow-x-auto max-h-[300px] overflow-y-auto border rounded-lg">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 sticky top-0">
                            <tr>
                                <th class="p-2">Fecha</th>
                                <th class="p-2">Hora</th>
                                <th class="p-2">Empleado</th>
                                <th class="p-2">Orden</th>
                                <th class="p-2">Equipo</th>
                                <th class="p-2">Motivo</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${(data.registros_quiebras || []).slice(0, 100).map(r => {
                                const fechaStr = formatLocalDate(r.fecha);
                                const horaMostrar = (r.hora && r.hora !== '00:00' && r.hora !== '00:00:00') ? r.hora : '--:--';
                                const empleadoMostrar = (r.empleado && r.empleado !== 'N/A') ? r.empleado : 'No registrado';
                                const ordenMostrar = (r.orden && r.orden !== 'N/A') ? r.orden : 'No registrada';
                                const equipoMostrar = (r.equipo && r.equipo !== 'N/A') ? r.equipo : 'No registrado';
                                const motivoMostrar = (r.motivo && r.motivo !== 'N/A') ? r.motivo.substring(0, 50) : 'N/A';
                                
                                return `<tr class="border-b hover:bg-gray-50">
                                    <td class="p-2 text-xs whitespace-nowrap">${fechaStr}</td>
                                    <td class="p-2 text-xs font-mono">${escapeHtml(horaMostrar)}</td>
                                    <td class="p-2 text-xs ${empleadoMostrar !== 'No registrado' ? 'cursor-pointer hover:text-blue-600' : ''}" ${empleadoMostrar !== 'No registrado' ? `onclick="mostrarDetallesEmpleado('${escapeHtml(empleadoMostrar).replace(/'/g, "\\'")}')"` : ''}>${escapeHtml(empleadoMostrar)}</td>
                                    <td class="p-2 text-xs font-mono">${escapeHtml(ordenMostrar)}</td>
                                    <td class="p-2 text-xs">${escapeHtml(equipoMostrar)}</td>
                                    <td class="p-2 text-xs max-w-[200px] truncate" title="${escapeHtml(r.motivo || '')}">${escapeHtml(motivoMostrar)}</td>
                                </tr>`;
                            }).join('')}
                            ${(!data.registros_quiebras || data.registros_quiebras.length === 0) ? '<tr><td colspan="6" class="p-4 text-center text-gray-400">No hay registros de quiebras en esta área</td></tr>' : ''}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
        
        if (cuerpo) cuerpo.innerHTML = html;
        
        // Inicializar gráficos después de que el DOM se actualice
        setTimeout(() => {
            console.log('📊 Inicializando gráficos para área:', area);
            console.log('📊 produccion_por_hora:', data.produccion_por_hora?.length || 0, 'registros');
            console.log('📊 tendencia:', data.tendencia?.length || 0, 'registros');
            
            if (data.produccion_por_hora && data.produccion_por_hora.length > 0) {
                initAreaHoraChart(data.produccion_por_hora);
            } else {
                console.warn('⚠️ Sin datos de producción por hora');
                mostrarMensajeSinDatosEnCanvas('areaHoraChart', 'Sin datos de producción por hora');
            }
            
            if (data.tendencia && data.tendencia.length > 0) {
                initAreaTendenciaChart(data.tendencia);
            } else {
                console.warn('⚠️ Sin datos de tendencia diaria');
                mostrarMensajeSinDatosEnCanvas('areaTendenciaChart', 'Sin datos de tendencia diaria');
            }
        }, 200);
        
        // Botón de exportación
        if (exportBtn) {
            exportBtn.onclick = () => exportarAreaStats(data, area);
        }
        
        // Información del período
        const periodoInicio = data.fecha_inicio || (fechaInicio ? formatLocalDate(fechaInicio) : 'N/A');
        const periodoFin = data.fecha_fin || (fechaFin ? formatLocalDate(fechaFin) : 'N/A');
        if (info) info.innerHTML = `<span class="opacity-70">Empleados activos:</span> ${data.empleados_activos || 0} | <span class="opacity-70">Período:</span> ${periodoInicio} → ${periodoFin}`;
        
    } catch (err) {
        console.error('❌ Error en mostrarDetallesArea:', err);
        if (cuerpo) cuerpo.innerHTML = `<div class="text-center py-8 text-red-500">Error: ${escapeHtml(err.message)}</div>`;
    }
};

// Función global para mostrar detalles de equipo
window.mostrarDetallesEquipo = async function(equipo) {
    console.log('🔍 mostrarDetallesEquipo:', equipo);
    if (!equipo) return;
    
    const modal = document.getElementById('modalDetalles');
    const titulo = document.getElementById('modalTitulo');
    const cuerpo = document.getElementById('modalCuerpo');
    const info = document.getElementById('modalInfo');
    const exportBtn = document.getElementById('modalExportBtn');
    
    if (!modal) return;
    
    modal.classList.remove('hidden');
    if (titulo) titulo.innerHTML = `<i class="bi bi-cpu"></i> Estadísticas del Equipo: ${escapeHtml(equipo)}`;
    if (cuerpo) cuerpo.innerHTML = `<div class="text-center py-12"><div class="spinner mx-auto mb-4"></div><p>Cargando estadísticas...</p></div>`;
    
    try {
        const form = document.getElementById('filterForm');
        const formData = new FormData(form);
        const params = new URLSearchParams();
        
        params.append('detalles_equipo_completo', equipo);
        params.append('_t', Date.now());
        
        const fechaInicio = formData.get('fecha_inicio');
        const fechaFin = formData.get('fecha_fin');
        if (fechaInicio) params.append('fecha_inicio', fechaInicio);
        if (fechaFin) params.append('fecha_fin', fechaFin);
        
        const resp = await fetch(`?${params.toString()}`);
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        
        const data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Error al cargar datos');
        
        let html = `
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-gradient-to-br from-red-50 to-red-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-red-600">${fmt(data.total_quiebras || 0)}</div>
                    <div class="text-xs text-gray-600">Total Quiebras</div>
                </div>
                <div class="bg-gradient-to-br from-amber-50 to-amber-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-amber-600">${data.ordenes_afectadas || 0}</div>
                    <div class="text-xs text-gray-600">Órdenes Afectadas</div>
                </div>
                <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-purple-600">${data.empleados_afectados || 0}</div>
                    <div class="text-xs text-gray-600">Empleados Afectados</div>
                </div>
                <div class="bg-gradient-to-br from-cyan-50 to-cyan-100 p-3 rounded-xl text-center">
                    <div class="text-2xl font-bold text-cyan-600">${data.motivos_diferentes || 0}</div>
                    <div class="text-xs text-gray-600">Motivos Diferentes</div>
                </div>
            </div>
            
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
                <div>
                    <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                        <i class="bi bi-calendar-week text-cyan-500"></i> Quiebras por Día
                    </h4>
                    <div class="chart-container" style="height: 250px;"><canvas id="equipoDiaChart"></canvas></div>
                </div>
            </div>
            
            <div class="mb-4">
                <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                    <i class="bi bi-list-ul text-gray-500"></i> Registros de Quiebras
                </h4>
                <div class="overflow-x-auto max-h-[300px] overflow-y-auto border rounded-lg">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 sticky top-0">
                            <tr><th class="p-2">Fecha</th><th class="p-2">Hora</th><th class="p-2">Empleado</th><th class="p-2">Orden</th><th class="p-2">Área</th><th class="p-2">Motivo</th></tr>
                        </thead>
                        <tbody>
                            ${(data.registros || []).slice(0, 50).map(r => {
                                const fechaObj = new Date(r.fecha);
                                const fechaStr = !isNaN(fechaObj.getTime()) ? 
                                    `${fechaObj.getDate().toString().padStart(2,'0')}/${(fechaObj.getMonth()+1).toString().padStart(2,'0')}/${fechaObj.getFullYear()}` : 
                                    (r.fecha || 'N/A');
                                return `<tr class="border-b hover:bg-gray-50">
                                    <td class="p-2 text-xs">${fechaStr}</td>
                                    <td class="p-2 text-xs">${r.hora || 'N/A'}</td>
                                    <td class="p-2 text-xs">${escapeHtml(r.empleado || 'N/A')}</td>
                                    <td class="p-2 text-xs font-mono">${escapeHtml(r.orden || 'N/A')}</td>
                                    <td class="p-2 text-xs">${escapeHtml(r.area || 'N/A')}</td>
                                    <td class="p-2 text-xs max-w-[200px] truncate">${escapeHtml((r.motivo || 'N/A').substring(0, 50))}</td>
                                </tr>`;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
        
        if (cuerpo) cuerpo.innerHTML = html;
        
        setTimeout(() => {
            if (data.quiebras_por_hora) initEquipoHoraChart(data.quiebras_por_hora);
            if (data.quiebras_por_dia) initEquipoDiaChart(data.quiebras_por_dia);
        }, 100);
        
        if (exportBtn) {
            exportBtn.onclick = () => exportarEquipoStats(data, equipo);
        }
        
        // Información del período
        const periodoInicio = data.fecha_inicio || 'N/A';
        const periodoFin = data.fecha_fin || 'N/A';
        if (info) info.innerHTML = `<span class="opacity-70">Período:</span> ${periodoInicio} → ${periodoFin} | <span class="opacity-70">Total registros:</span> ${(data.registros || []).length}`;
        
    } catch (err) {
        console.error('❌ Error:', err);
        if (cuerpo) cuerpo.innerHTML = `<div class="text-center py-8 text-red-500">Error: ${escapeHtml(err.message)}</div>`;
    }
};

function initEquipoHoraChart(data) {
    const ctx = document.getElementById('equipoHoraChart');
    if (!ctx || !data?.length) return;
    if (ctx.chart) ctx.chart.destroy();
    
    ctx.chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(d => `${d.hora}:00`),
            datasets: [{ label: 'Quiebras', data: data.map(d => d.total), backgroundColor: '#ef4444', borderRadius: 6 }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });
}

// ============================================================
// FUNCIONES PARA GRÁFICOS DE EQUIPO EN MODAL
// ============================================================

function initEquipoHoraChart(data) {
    const ctx = document.getElementById('equipoHoraChart');
    if (!ctx) {
        console.warn('❌ Canvas equipoHoraChart no encontrado');
        return;
    }
    
    const parent = ctx.parentElement;
    const existingMsg = parent?.querySelector('.no-data-message');
    if (existingMsg) existingMsg.remove();
    ctx.style.display = 'block';
    
    if (!data || data.length === 0) {
        mostrarMensajeSinDatosEnCanvas('equipoHoraChart', 'Sin datos de quiebras por hora');
        return;
    }
    
    if (ctx.chart) {
        try { ctx.chart.destroy(); } catch(e) {}
        ctx.chart = null;
    }
    
    // Asegurar horas 0-23
    const horaMap = {};
    data.forEach(item => {
        horaMap[item.hora] = item.total || 0;
    });
    
    const horas = Array.from({ length: 24 }, (_, i) => i);
    const valores = horas.map(h => horaMap[h] || 0);
    
    ctx.chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: horas.map(h => `${h.toString().padStart(2, '0')}:00`),
            datasets: [{
                label: 'Quiebras',
                data: valores,
                backgroundColor: '#ef4444',
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { 
                    callbacks: { 
                        label: (ctx) => `Quiebras: ${fmt(ctx.raw)}` 
                    } 
                }
            },
            scales: {
                x: {
                    title: { display: true, text: 'Hora del Día', color: '#6b7280' },
                    ticks: { maxRotation: 45, minRotation: 45, autoSkip: true, maxTicksLimit: 12 }
                },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Cantidad de Quiebras', color: '#6b7280' },
                    ticks: { callback: v => fmt(v) }
                }
            }
        }
    });
    console.log('✅ Gráfico de horas de equipo creado');
}

function initEquipoDiaChart(data) {
    const ctx = document.getElementById('equipoDiaChart');
    if (!ctx) {
        console.warn('❌ Canvas equipoDiaChart no encontrado');
        return;
    }
    
    const parent = ctx.parentElement;
    const existingMsg = parent?.querySelector('.no-data-message');
    if (existingMsg) existingMsg.remove();
    ctx.style.display = 'block';
    ctx.style.width = '100%';
    ctx.style.height = '250px';
    
    if (!data || data.length === 0) {
        mostrarMensajeSinDatosEnCanvas('equipoDiaChart', 'Sin datos de quiebras por día');
        return;
    }
    
    if (ctx.chart) {
        try { ctx.chart.destroy(); } catch(e) {}
        ctx.chart = null;
    }
    
    // Orden correcto: Lunes, Martes, Miércoles, Jueves, Viernes, Sábado, Domingo
    const ordenDias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    
    // Mapear datos usando nombre_dia si existe, o convertir dia_semana
    const dataMap = {};
    data.forEach(item => {
        let nombreDia = item.nombre_dia;
        if (!nombreDia) {
            // Convertir número de día a nombre (MySQL: 1=Domingo, 2=Lunes, ...)
            const diaNum = item.dia_semana;
            nombreDia = diaNum === 1 ? 'Domingo' :
                       diaNum === 2 ? 'Lunes' :
                       diaNum === 3 ? 'Martes' :
                       diaNum === 4 ? 'Miércoles' :
                       diaNum === 5 ? 'Jueves' :
                       diaNum === 6 ? 'Viernes' : 'Sábado';
        }
        dataMap[nombreDia] = item.total || 0;
    });
    
    const valores = ordenDias.map(dia => dataMap[dia] || 0);
    
    ctx.chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ordenDias,
            datasets: [{
                label: 'Quiebras',
                data: valores,
                backgroundColor: '#06b6d4',
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { 
                    callbacks: { 
                        label: (ctx) => `Quiebras: ${fmt(ctx.raw)}` 
                    } 
                }
            },
            scales: {
                x: {
                    title: { display: true, text: 'Día de la Semana', color: '#6b7280' },
                    ticks: { autoSkip: false, maxRotation: 45, minRotation: 45 }
                },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Cantidad de Quiebras', color: '#6b7280' },
                    ticks: { callback: v => fmt(v) }
                }
            }
        }
    });
    console.log('✅ Gráfico de días de equipo creado');
}

function exportarEquipoStats(data, equipo) {
    let csv = `Estadísticas del Equipo: ${equipo}\n`;
    csv += `Total Quiebras,${data.total_quiebras || 0}\n`;
    csv += `Órdenes Afectadas,${data.ordenes_afectadas || 0}\n`;
    csv += `Empleados Afectados,${data.empleados_afectados || 0}\n`;
    csv += `Motivos Diferentes,${data.motivos_diferentes || 0}\n\n`;
    csv += `Registros de Quiebras\nFecha,Hora,Empleado,Orden,Área,Motivo\n`;
    (data.registros || []).forEach(r => {
        let hora = r.hora || '';
        if (hora === '00:00' || hora === '00:00:00') hora = '--:--';
        csv += `${r.fecha || ''},${hora},${escapeCsv(r.empleado || '')},${escapeCsv(r.orden || '')},${escapeCsv(r.area || '')},${escapeCsv(r.motivo || '')}\n`;
    });
    
    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `equipo_${equipo.replace(/[^a-z0-9]/gi, '_')}_estadisticas.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

function exportarAreaStats(data, area) {
    let csv = `Estadísticas del Área: ${area}\n`;
    csv += `Total Producción,${data.total_produccion || 0}\n`;
    csv += `Total Quiebras,${data.total_quiebras || 0}\n`;
    csv += `Empleados Activos,${data.empleados_activos || 0}\n`;
    csv += `Período,${data.fecha_inicio || ''} → ${data.fecha_fin || ''}\n\n`;

    if (Array.isArray(data.top_empleados) && data.top_empleados.length > 0) {
        csv += 'Top Empleados\nEmpleado,Producción,Quiebras,Eficiencia\n';
        data.top_empleados.forEach(emp => {
            csv += `${escapeCsv(emp.empleado || '')},${emp.produccion || 0},${emp.quiebras || 0},${emp.eficiencia || 0}%\n`;
        });
        csv += '\n';
    }

    if (Array.isArray(data.produccion_por_equipo) && data.produccion_por_equipo.length > 0) {
        csv += 'Producción por Equipo\nEquipo,Producción\n';
        data.produccion_por_equipo.forEach(eq => {
            csv += `${escapeCsv(eq.equipo || '')},${eq.produccion || 0}\n`;
        });
        csv += '\n';
    }

    if (Array.isArray(data.registros_quiebras) && data.registros_quiebras.length > 0) {
        csv += 'Registros de Quiebras\nFecha,Hora,Empleado,Orden,Equipo,Motivo\n';
        data.registros_quiebras.forEach(r => {
            let hora = r.hora || '';
            if (hora === '00:00' || hora === '00:00:00') hora = '--:--';
            csv += `${r.fecha || ''},${hora},${escapeCsv(r.empleado || '')},${escapeCsv(r.orden || '')},${escapeCsv(r.equipo || '')},${escapeCsv(r.motivo || '')}\n`;
        });
        csv += '\n';
    }

    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `area_${area.replace(/[^a-z0-9]/gi, '_')}_estadisticas.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

function exportarEmpleadoStats(data, empleado) {
    let csv = `Estadísticas de Empleado: ${empleado}\n`;
    csv += `Total Producción,${data.total_produccion || 0}\n`;
    csv += `Total Quiebras,${data.total_quiebras || 0}\n`;
    csv += `Eficiencia,${data.eficiencia || 0}%\n`;
    csv += `Prod/hora,${data.productividad_hora || 0}\n`;
    csv += `Ratio Prod/Quiebra,${data.ratio_prod_quiebra || 0}\n`;
    csv += `Equipos Distintos,${data.equipos_count || 0}\n\n`;
    
    if (Array.isArray(data.equipos) && data.equipos.length > 0) {
        csv += `Equipos donde produjo\nEquipo,Total\n`;
        data.equipos.forEach(item => {
            csv += `${escapeCsv(item.equipo || '')},${item.total || 0}\n`;
        });
        csv += '\n';
    }
    
    csv += `Registros Recientes\nFecha,Hora,Orden,Área,Tipo,Detalle\n`;
    (data.registros || []).forEach(r => {
        let hora = r.hora || '';
        if (hora === '00:00' || hora === '00:00:00') hora = '--:--';
        csv += `${r.fecha || ''},${hora},${escapeCsv(r.orden || '')},${escapeCsv(r.area || '')},${escapeCsv(r.tipo || '')},${escapeCsv(r.detalle || '')}\n`;
    });
    
    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `empleado_${empleado.replace(/[^a-z0-9]/gi, '_')}_estadisticas.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

// ============================================================
// FUNCIONES PARA GRÁFICOS DE EMPLEADO EN MODAL
// ============================================================

function initEmpleadoHoraChart(data) {
    const ctx = document.getElementById('empleadoHoraChart');
    if (!ctx) {
        console.warn('❌ Canvas empleadoHoraChart no encontrado');
        return;
    }
    
    // Limpiar mensaje de sin datos si existe
    const parent = ctx.parentElement;
    const existingMsg = parent?.querySelector('.no-data-message');
    if (existingMsg) existingMsg.remove();
    ctx.style.display = '';
    
    if (!data || data.length === 0) {
        mostrarMensajeSinDatosEnCanvas('empleadoHoraChart', 'Sin datos de producción por hora');
        return;
    }
    
    // Destruir gráfico existente
    if (ctx.chart) {
        try { ctx.chart.destroy(); } catch(e) {}
        ctx.chart = null;
    }
    
    // Asegurar que todas las horas estén presentes (0-23)
    const horaMap = {};
    data.forEach(item => {
        horaMap[item.hora] = item.total || item.produccion || 0;
    });
    
    const horasCompletas = Array.from({ length: 24 }, (_, i) => i);
    const valores = horasCompletas.map(h => horaMap[h] || 0);
    
    const chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: horasCompletas.map(h => `${h}:00`),
            datasets: [{
                label: 'Producción',
                data: valores,
                backgroundColor: '#f59e0b',
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,.85)',
                    callbacks: {
                        label: (ctx) => `Producción: ${fmt(ctx.raw)} unidades`
                    }
                }
            },
            scales: {
                x: {
                    title: { display: true, text: 'Hora del Día', color: '#6b7280' },
                    grid: { color: '#f3f4f6' },
                    ticks: { color: '#4b5563', maxRotation: 45, minRotation: 45, autoSkip: true, maxTicksLimit: 12 }
                },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Unidades', color: '#6b7280' },
                    grid: { color: '#e5e7eb' },
                    ticks: { color: '#4b5563', callback: v => fmt(v) }
                }
            }
        }
    });
    
    ctx.chart = chart;
    console.log('✅ Gráfico de horas de empleado creado');
}

function initEmpleadoDiaChart(data) {
    console.log('📊 initEmpleadoDiaChart ejecutándose');
    const canvas = document.getElementById('empleadoDiaChart');
    if (!canvas) {
        console.warn('❌ Canvas empleadoDiaChart no encontrado');
        return;
    }
    
    // Limpiar mensaje anterior
    const parent = canvas.parentElement;
    const existingMsg = parent?.querySelector('.no-data-message');
    if (existingMsg) existingMsg.remove();
    canvas.style.display = 'block';
    
    // Forzar dimensiones
    canvas.style.width = '100%';
    canvas.style.height = '250px';
    
    if (!data || data.length === 0) {
        mostrarMensajeSinDatosEnCanvas('empleadoDiaChart', 'Sin datos de producción por día');
        return;
    }
    
    if (canvas.chart) {
        try { canvas.chart.destroy(); } catch(e) {}
        canvas.chart = null;
    }
    
    // Orden correcto: Lunes, Martes, Miércoles, Jueves, Viernes, Sábado, Domingo
    const ordenDias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    
    // Mapear datos por nombre de día
    const dataMap = {};
    data.forEach(item => {
        const nombreDia = item.nombre_dia || 
                          (item.dia_semana === 1 ? 'Domingo' :
                           item.dia_semana === 2 ? 'Lunes' :
                           item.dia_semana === 3 ? 'Martes' :
                           item.dia_semana === 4 ? 'Miércoles' :
                           item.dia_semana === 5 ? 'Jueves' :
                           item.dia_semana === 6 ? 'Viernes' : 'Sábado');
        dataMap[nombreDia] = item.produccion || item.total || 0;
    });
    
    const labels = ordenDias;
    const valores = ordenDias.map(dia => dataMap[dia] || 0);
    
    canvas.chart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Producción',
                data: valores,
                backgroundColor: '#06b6d4',
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { 
                    callbacks: { 
                        label: (ctx) => `Producción: ${fmt(ctx.raw)} unidades` 
                    } 
                }
            },
            scales: {
                x: { 
                    title: { display: true, text: 'Día de la Semana', color: '#6b7280' },
                    ticks: { autoSkip: false, maxRotation: 45, minRotation: 45 }
                },
                y: { 
                    beginAtZero: true, 
                    title: { display: true, text: 'Unidades', color: '#6b7280' },
                    ticks: { callback: v => fmt(v) }
                }
            }
        }
    });
    
    console.log('✅ Gráfico de días de empleado creado');
}

function initEmpleadoTendenciaChart(data) {
    const ctx = document.getElementById('empleadoTendenciaChart');
    if (!ctx) {
        console.warn('❌ Canvas empleadoTendenciaChart no encontrado');
        return;
    }
    
    // Limpiar mensaje de sin datos si existe
    const parent = ctx.parentElement;
    const existingMsg = parent?.querySelector('.no-data-message');
    if (existingMsg) existingMsg.remove();
    ctx.style.display = '';
    
    if (!data || data.length === 0) {
        mostrarMensajeSinDatosEnCanvas('empleadoTendenciaChart', 'Sin datos de tendencia diaria');
        return;
    }
    
    // Destruir gráfico existente
    if (ctx.chart) {
        try { ctx.chart.destroy(); } catch(e) {}
        ctx.chart = null;
    }
    
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map(d => d.fecha_display || d.fecha),
            datasets: [
                {
                    label: 'Producción',
                    data: data.map(d => d.produccion || 0),
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.3,
                    fill: true,
                    pointBackgroundColor: '#10b981',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                },
                {
                    label: 'Quiebras',
                    data: data.map(d => d.quiebras || 0),
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.05)',
                    tension: 0.3,
                    fill: true,
                    pointBackgroundColor: '#ef4444',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { color: '#374151', font: { size: 11 } } },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,.85)',
                    callbacks: {
                        label: (ctx) => {
                            const label = ctx.dataset.label;
                            const value = fmt(ctx.raw);
                            return `${label}: ${value} ${ctx.dataset.label === 'Quiebras' ? 'quiebras' : 'unidades'}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    title: { display: true, text: 'Fecha', color: '#6b7280' },
                    grid: { color: '#f3f4f6' },
                    ticks: { color: '#4b5563', maxRotation: 45, minRotation: 45, autoSkip: true, maxTicksLimit: 10 }
                },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Cantidad', color: '#6b7280' },
                    grid: { color: '#e5e7eb' },
                    ticks: { color: '#4b5563', callback: v => fmt(v) }
                }
            }
        }
    });
    
    ctx.chart = chart;
    console.log('✅ Gráfico de tendencia de empleado creado');
}

// ============================================================
// FUNCIONES PARA GRÁFICOS DE ÁREA EN MODAL
// ============================================================

function initAreaHoraChart(data) {
    const ctx = document.getElementById('areaHoraChart');
    if (!ctx) {
        console.warn('❌ Canvas areaHoraChart no encontrado');
        return;
    }
    
    // Limpiar mensaje de sin datos si existe
    const parent = ctx.parentElement;
    const existingMsg = parent?.querySelector('.no-data-message');
    if (existingMsg) existingMsg.remove();
    ctx.style.display = 'block';
    
    if (!data || data.length === 0) {
        mostrarMensajeSinDatosEnCanvas('areaHoraChart', 'Sin datos de producción por hora');
        return;
    }
    
    // Destruir gráfico existente
    if (ctx.chart) {
        try { ctx.chart.destroy(); } catch(e) {}
        ctx.chart = null;
    }
    
    // Extraer datos
    const horas = data.map(h => `${h.hora.toString().padStart(2, '0')}:00`);
    const valores = data.map(h => h.total_produccion || 0);
    
    const chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: horas,
            datasets: [{
                label: 'Producción',
                data: valores,
                backgroundColor: '#f59e0b',
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,.85)',
                    callbacks: {
                        label: (ctx) => `Producción: ${fmt(ctx.raw)} unidades`
                    }
                }
            },
            scales: {
                x: {
                    title: { display: true, text: 'Hora del Día', color: '#6b7280' },
                    grid: { color: '#f3f4f6' },
                    ticks: { color: '#4b5563', maxRotation: 45, minRotation: 45, autoSkip: true, maxTicksLimit: 12 }
                },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Unidades', color: '#6b7280' },
                    grid: { color: '#e5e7eb' },
                    ticks: { color: '#4b5563', callback: v => fmt(v) }
                }
            }
        }
    });
    
    ctx.chart = chart;
    console.log('✅ Gráfico de horas de área creado');
}

function initAreaTendenciaChart(data) {
    const ctx = document.getElementById('areaTendenciaChart');
    if (!ctx) {
        console.warn('❌ Canvas areaTendenciaChart no encontrado');
        return;
    }
    
    // Limpiar mensaje de sin datos si existe
    const parent = ctx.parentElement;
    const existingMsg = parent?.querySelector('.no-data-message');
    if (existingMsg) existingMsg.remove();
    ctx.style.display = 'block';
    
    if (!data || data.length === 0) {
        mostrarMensajeSinDatosEnCanvas('areaTendenciaChart', 'Sin datos de tendencia diaria');
        return;
    }
    
    // Destruir gráfico existente
    if (ctx.chart) {
        try { ctx.chart.destroy(); } catch(e) {}
        ctx.chart = null;
    }
    
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map(d => d.fecha_display || d.fecha),
            datasets: [{
                label: 'Producción',
                data: data.map(d => d.produccion || 0),
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                tension: 0.3,
                fill: true,
                pointBackgroundColor: '#10b981',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { color: '#374151', font: { size: 11 } } },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,.85)',
                    callbacks: {
                        label: (ctx) => `Producción: ${fmt(ctx.raw)} unidades`
                    }
                }
            },
            scales: {
                x: {
                    title: { display: true, text: 'Fecha', color: '#6b7280' },
                    grid: { color: '#f3f4f6' },
                    ticks: { color: '#4b5563', maxRotation: 45, minRotation: 45, autoSkip: true, maxTicksLimit: 10 }
                },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Unidades', color: '#6b7280' },
                    grid: { color: '#e5e7eb' },
                    ticks: { color: '#4b5563', callback: v => fmt(v) }
                }
            }
        }
    });
    
    ctx.chart = chart;
    console.log('✅ Gráfico de tendencia de área creado');
}

// Función auxiliar para mostrar mensaje cuando no hay datos en un canvas
function mostrarMensajeSinDatosEnCanvas(canvasId, mensaje) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    
    const parent = canvas.parentElement;
    if (!parent) return;
    
    // Ocultar canvas
    canvas.style.display = 'none';
    
    // Eliminar mensaje existente si lo hay
    const existingMsg = parent.querySelector('.no-data-message');
    if (existingMsg) existingMsg.remove();
    
    // Crear nuevo mensaje
    const msgDiv = document.createElement('div');
    msgDiv.className = 'no-data-message text-center py-8 text-gray-400';
    msgDiv.innerHTML = `<i class="bi bi-bar-chart-slash text-2xl"></i><p class="mt-2 text-sm">${mensaje}</p>`;
    parent.appendChild(msgDiv);
}

// Función global para mostrar detalles de responsable
window.mostrarDetallesResponsable = async function(responsable) {
    console.log('🔍 mostrarDetallesResponsable:', responsable);
    
    if (!responsable) return;
    
    const modal = document.getElementById('modalDetalles');
    const titulo = document.getElementById('modalTitulo');
    const cuerpo = document.getElementById('modalCuerpo');
    const info = document.getElementById('modalInfo');
    const exportBtn = document.getElementById('modalExportBtn');
    
    if (!modal) return;
    
    modal.classList.remove('hidden');
    if (titulo) titulo.innerHTML = `<i class="bi bi-person-gear"></i> Detalles: ${escapeHtml(responsable)}`;
    if (cuerpo) cuerpo.innerHTML = `<div class="text-center py-12"><div class="spinner mx-auto mb-4"></div><p>Cargando...</p></div>`;
    
    try {
        const form = document.getElementById('filterForm');
        const formData = new FormData(form);
        const params = new URLSearchParams();
        
        params.append('detalles_responsable', responsable);
        params.append('_t', Date.now());
        
        const fechaInicio = formData.get('fecha_inicio');
        const fechaFin = formData.get('fecha_fin');
        if (fechaInicio) params.append('fecha_inicio', fechaInicio);
        if (fechaFin) params.append('fecha_fin', fechaFin);
        
        const resp = await fetch(`?${params.toString()}`);
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        
        const data = await resp.json();
        
        if (!data.success) throw new Error(data.error || 'Error al cargar datos');
        
        const stats = data.estadisticas || {};
        const registros = data.registros || [];
        
        // Función para formatear fecha local
        const fmtFecha = (dateStr) => {
            if (!dateStr) return 'N/A';
            const d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            return `${d.getDate().toString().padStart(2,'0')}/${(d.getMonth()+1).toString().padStart(2,'0')}/${d.getFullYear()}`;
        };
        
        // Obtener fechas del período (ahora vienen del backend)
        const periodoInicio = data.fecha_inicio || fmtFecha(fechaInicio);
        const periodoFin = data.fecha_fin || fmtFecha(fechaFin);
        
        let html = '';
        
        // Tarjetas de resumen
        html += `<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="bg-gradient-to-br from-red-50 to-red-100 p-3 rounded-xl text-center">
                <div class="text-2xl font-bold text-red-600">${fmt(stats.total_quiebras || 0)}</div>
                <div class="text-xs text-gray-600">Total Quiebras</div>
            </div>
            <div class="bg-gradient-to-br from-amber-50 to-amber-100 p-3 rounded-xl text-center">
                <div class="text-2xl font-bold text-amber-600">${stats.ordenes_afectadas || 0}</div>
                <div class="text-xs text-gray-600">Órdenes</div>
            </div>
            <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-3 rounded-xl text-center">
                <div class="text-2xl font-bold text-purple-600">${stats.empleados_afectados || 0}</div>
                <div class="text-xs text-gray-600">Empleados</div>
            </div>
            <div class="bg-gradient-to-br from-cyan-50 to-cyan-100 p-3 rounded-xl text-center">
                <div class="text-2xl font-bold text-cyan-600">${stats.areas_afectadas || 0}</div>
                <div class="text-xs text-gray-600">Áreas</div>
            </div>
        </div>`;
        
        // Tabla de quiebras
        if (registros.length > 0) {
            html += `<h4 class="font-bold text-gray-800 mb-2">⚠️ Registros de Quiebras</h4>
                     <div class="overflow-x-auto max-h-[400px] overflow-y-auto border rounded-lg">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="p-2 text-left">Fecha</th>
                                    <th class="p-2 text-left">Hora</th>
                                    <th class="p-2 text-left">Empleado</th>
                                    <th class="p-2 text-left">Orden</th>
                                    <th class="p-2 text-left">Área</th>
                                    <th class="p-2 text-left">Equipo</th>
                                    <th class="p-2 text-left">Motivo</th>
                                </tr>
                            </thead>
                            <tbody>`;
            
            registros.slice(0, 100).forEach(r => {
                // Formatear hora correctamente
                let horaMostrar = r.hora || 'N/A';
                if (horaMostrar === '00:00' || horaMostrar === '00:00:00') {
                    horaMostrar = '<span class="text-gray-400">--:--</span>';
                }
                
                const fechaStr = fmtFecha(r.fecha);
                const empleadoMostrar = r.empleado && r.empleado !== 'N/A' ? escapeHtml(r.empleado) : '<span class="text-gray-400">No registrado</span>';
                const areaMostrar = r.area && r.area !== 'N/A' ? escapeHtml(r.area) : '<span class="text-gray-400">No registrada</span>';
                const equipoMostrar = r.equipo && r.equipo !== 'N/A' ? escapeHtml(r.equipo) : '<span class="text-gray-400">No registrado</span>';
                const ordenMostrar = r.orden && r.orden !== 'N/A' ? escapeHtml(r.orden) : '<span class="text-gray-400">No registrada</span>';
                
                html += `<tr class="border-b hover:bg-gray-50">
                            <td class="p-2 text-xs whitespace-nowrap">${fechaStr}</td>
                            <td class="p-2 text-xs font-mono">${horaMostrar}</td>
                            <td class="p-2 text-xs">${empleadoMostrar}</td>
                            <td class="p-2 text-xs font-mono">${ordenMostrar}</td>
                            <td class="p-2 text-xs">${areaMostrar}</td>
                            <td class="p-2 text-xs">${equipoMostrar}</td>
                            <td class="p-2 text-xs max-w-[200px] truncate" title="${escapeHtml(r.motivo || '')}">${escapeHtml((r.motivo || 'N/A').substring(0, 50))}</td>
                          </tr>`;
            });
            
            html += `</tbody>
                     </table>
                     ${registros.length > 100 ? `<div class="text-center py-2 text-xs text-gray-400">Mostrando 100 de ${registros.length} registros</div>` : ''}
                </div>`;
        } else {
            html += `<div class="text-center py-8 text-gray-400">No hay registros de quiebras para este responsable</div>`;
        }
        
        if (cuerpo) cuerpo.innerHTML = html;
        
        if (exportBtn) {
            exportBtn.onclick = () => {
                let csv = 'Fecha,Hora,Empleado,Orden,Área,Equipo,Motivo\n';
                registros.forEach(r => {
                    let hora = r.hora || '';
                    if (hora === '00:00' || hora === '00:00:00') hora = '--:--';
                    csv += `${r.fecha || ''},${hora},${escapeCsv(r.empleado || '')},${escapeCsv(r.orden || '')},${escapeCsv(r.area || '')},${escapeCsv(r.equipo || '')},${escapeCsv(r.motivo || '')}\n`;
                });
                const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `responsable_${responsable.replace(/[^a-z0-9]/gi, '_')}_detalles.csv`;
                a.click();
                URL.revokeObjectURL(url);
            };
        }
        
        // Información del período
        let periodInicio = data.fecha_inicio || 'N/A';
        let periodFin = data.fecha_fin || 'N/A';
        if (info) info.innerHTML = `<span class="opacity-70">Período:</span> ${periodInicio} → ${periodFin} | <span class="opacity-70">Total registros:</span> <b>${registros.length}</b>`;
        
    } catch (err) {
        console.error('❌ Error:', err);
        if (cuerpo) cuerpo.innerHTML = `<div class="text-center py-8 text-red-500">Error: ${escapeHtml(err.message)}</div>`;
    }
};

// ============================================================
// ELIMINAR ORDEN COMPLETA (solo administradores)
// ============================================================

window.eliminarOrdenCompleta = async function(orden) {
    if (!orden) return;

    // Confirmación inicial
    const confirmacion1 = confirm(
        `⚠️ ELIMINAR ORDEN: ${orden}\n\n` +
        `Esta acción eliminará TODOS los registros asociados a esta orden:\n` +
        `• Registros de producción\n` +
        `• Registros antiguos\n` +
        `• Registros de quiebras\n\n` +
        `¿Verificaste en Odoo que la orden está CANCELADA?\n\n` +
        `Haz clic en Aceptar para continuar.`
    );
    if (!confirmacion1) return;

    // Segunda confirmación de seguridad
    const texto = prompt(
        `🔒 CONFIRMACIÓN FINAL\n\nEscribe el número de orden exactamente para confirmar la eliminación:\n\n${orden}`
    );
    if (texto === null) return; // canceló
    if (texto.trim() !== orden.trim()) {
        alert('❌ El número de orden no coincide. Operación cancelada.');
        return;
    }

    // Mostrar modal de carga
    const modal = document.getElementById('modalDetalles');
    const titulo = document.getElementById('modalTitulo');
    const cuerpo = document.getElementById('modalCuerpo');
    const info   = document.getElementById('modalInfo');

    if (modal) modal.classList.remove('hidden');
    if (titulo) titulo.innerHTML = `<i class="bi bi-trash3"></i> Eliminando orden ${escapeHtml(orden)}…`;
    if (cuerpo) cuerpo.innerHTML = `
        <div class="text-center py-12">
            <div class="spinner mx-auto mb-4"></div>
            <p class="text-lg">Eliminando todos los registros de la orden <strong>${escapeHtml(orden)}</strong>…</p>
            <p class="text-sm opacity-70 mt-2">Por favor espere</p>
        </div>`;
    if (info) info.textContent = '';

    try {
        const params = new URLSearchParams({
            eliminar_orden_completa: orden,
            confirmar: '1',
            _t: Date.now()
        });
        const resp = await fetch(`?${params.toString()}`);
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);

        const data = await resp.json();

        if (!data.success) throw new Error(data.error || 'Error desconocido al eliminar');

        const r = data.resultado || {};
        if (titulo) titulo.innerHTML = `<i class="bi bi-check-circle text-green-600"></i> Orden eliminada correctamente`;
        if (cuerpo) cuerpo.innerHTML = `
            <div class="text-center py-6">
                <div class="text-6xl mb-4">🗑️</div>
                <h3 class="text-xl font-bold text-green-700 mb-2">Orden <code class="bg-gray-100 px-2 py-0.5 rounded">${escapeHtml(orden)}</code> eliminada</h3>
                <p class="text-gray-500 text-sm mb-6">Todos los registros han sido borrados de la base de datos.</p>
                <div class="grid grid-cols-3 gap-3 max-w-sm mx-auto">
                    <div class="bg-blue-50 rounded-xl p-3 text-center">
                        <div class="text-2xl font-bold text-blue-600">${r.produccion ?? 0}</div>
                        <div class="text-xs text-gray-500 mt-1">Producción</div>
                    </div>
                    <div class="bg-purple-50 rounded-xl p-3 text-center">
                        <div class="text-2xl font-bold text-purple-600">${r.registros_antiguos ?? 0}</div>
                        <div class="text-xs text-gray-500 mt-1">Ant. registros</div>
                    </div>
                    <div class="bg-red-50 rounded-xl p-3 text-center">
                        <div class="text-2xl font-bold text-red-600">${r.quiebras ?? 0}</div>
                        <div class="text-xs text-gray-500 mt-1">Quiebras</div>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-6">Recarga la página para ver los datos actualizados.</p>
            </div>`;
        if (info) info.innerHTML = `<span class="text-green-600 font-semibold">✅ Eliminación completada el ${new Date().toLocaleString('es-CR')}</span>`;

    } catch (err) {
        console.error('❌ Error al eliminar orden:', err);
        if (titulo) titulo.innerHTML = `<i class="bi bi-x-circle text-red-600"></i> Error al eliminar`;
        if (cuerpo) cuerpo.innerHTML = `
            <div class="text-center py-8 text-red-500">
                <div class="text-5xl mb-3">❌</div>
                <p class="font-semibold">No se pudo eliminar la orden</p>
                <p class="text-sm mt-2 text-gray-500">${escapeHtml(err.message)}</p>
            </div>`;
    }
};

// ============================================================
// EVENTOS Y ARRANQUE
// ============================================================

document.addEventListener('DOMContentLoaded', () => {
    console.log('🚀 DOMContentLoaded - Inicializando dashboard');
    
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.addEventListener('click', () => cambiarTab(btn.dataset.tab));
    });
    
    const selector = document.getElementById('areaChartTypeSelector');
    if (selector) selector.addEventListener('change', cambiarTipoGraficoArea);
    
document.getElementById('resetBtn')?.addEventListener('click', () => {
    const hoy = new Date();
    const year = hoy.getFullYear();
    const month = String(hoy.getMonth() + 1).padStart(2, '0');
    const day = String(hoy.getDate()).padStart(2, '0');
    const fechaHoy = `${year}-${month}-${day}`;
    
    const form = document.getElementById('filterForm');
    form.querySelector('[name="fecha_inicio"]').value = fechaHoy;
    form.querySelector('[name="fecha_fin"]').value = fechaHoy;
    form.querySelector('[name="hora_inicio_time"]').value = '00:00';
    form.querySelector('[name="hora_inicio_ampm"]').value = 'AM';
    form.querySelector('[name="hora_fin_time"]').value = '23:59';
    form.querySelector('[name="hora_fin_ampm"]').value = 'PM';
    form.submit();
});
    
    document.getElementById('exportBtn')?.addEventListener('click', () => {
        alert('Usa el botón "Exportar" dentro de cada pestaña para descargar los datos.');
    });
    
    document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });
    window.addEventListener('resize', () => setTimeout(resizeCharts, 100));
    
    initReportes();
    
    document.getElementById('wipRefreshBtn')?.addEventListener('click', cargarWipEmpaque);
    
    setTimeout(() => {
        console.log('📈 Ejecutando initCharts()');
        initCharts();
    }, 300);
    
    setTimeout(() => { 
        document.getElementById('loadingScreen')?.classList.add('hidden'); 
    }, 500);
});