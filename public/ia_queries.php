<?php
/**
 * ia_queries.php  –  Punto de entrada principal
 *
 * Estructura del proyecto:
 *   ia_queries.php   ← este archivo (lo que llama el navegador)
 *   backend.php      ← autenticación, helpers, consultas SQL, endpoints AJAX
 *   frontend.php     ← HTML / vista del dashboard
 *   api.js           ← toda la lógica JS (cargado como script externo)
 */

require_once 'backend.php';   // ejecuta consultas, responde AJAX y cierra la conexión
require_once 'frontend.php';  // renderiza el HTML (usa las variables de backend.php)
