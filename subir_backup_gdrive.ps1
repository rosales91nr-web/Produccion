# ==============================================================================
# subir_backup_gdrive.ps1
# Sube backups de C:\backups a Google Drive via rclone
# y actualiza backup_status.json en tu repo de GitHub
# Autor: Nestor Rosales | Rosalesdev91
#
# REQUISITOS:
#   1. rclone instalado: winget install Rclone.Rclone
#   2. rclone configurado con Google Drive: rclone config
#   3. Token de GitHub con permisos repo
#
# CONFIGURAR ESTAS 5 VARIABLES:
# ==============================================================================

$BACKUP_DIR    = "C:\backups"
$RCLONE_REMOTE = "gdrive"                          # Nombre del remote en rclone config
$GDRIVE_FOLDER = "SIA-LAB/backups"                 # Carpeta destino en Google Drive
$GITHUB_TOKEN  = "TU_GITHUB_TOKEN_AQUI"            # GitHub -> Settings -> Developer settings -> Tokens
$GITHUB_USER   = "TU_USUARIO_GITHUB"
$GITHUB_REPO   = "TU_REPOSITORIO"
$GITHUB_BRANCH = "main"
$JSON_FILE     = "backup_status.json"              # Nombre del archivo JSON en el repo
$PREFIJO       = "produccion_quiebras_"

# ==============================================================================
# NO MODIFICAR DEBAJO DE ESTA LINEA
# ==============================================================================

$ErrorActionPreference = "Continue"
$logFile = "$env:TEMP\sia_backup_$(Get-Date -Format 'yyyyMMdd').log"

function Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $line = "[$ts] $msg"
    Write-Host $line
    Add-Content -Path $logFile -Value $line -Encoding UTF8
}

function FormatSize($bytes) {
    if ($bytes -ge 1GB) { return "{0:N2} GB" -f ($bytes / 1GB) }
    if ($bytes -ge 1MB) { return "{0:N2} MB" -f ($bytes / 1MB) }
    if ($bytes -ge 1KB) { return "{0:N2} KB" -f ($bytes / 1KB) }
    return "$bytes B"
}

Log "============================================"
Log "Inicio proceso de subida de backups"
Log "Directorio: $BACKUP_DIR"
Log "Remote rclone: $RCLONE_REMOTE`:$GDRIVE_FOLDER"

# ── 1. Verificar rclone ───────────────────────────────────────────────────────
$rclonePath = (Get-Command rclone -ErrorAction SilentlyContinue)?.Source
if (-not $rclonePath) {
    # Buscar en paths comunes
    $candidates = @(
        "$env:ProgramFiles\rclone\rclone.exe",
        "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\Rclone.Rclone*\rclone.exe",
        "C:\ProgramData\chocolatey\bin\rclone.exe"
    )
    foreach ($c in $candidates) {
        if (Test-Path $c) { $rclonePath = $c; break }
    }
}
if (-not $rclonePath) {
    Log "ERROR: rclone no encontrado. Instala con: winget install Rclone.Rclone"
    exit 1
}
Log "rclone encontrado en: $rclonePath"

# ── 2. Escanear backups locales ───────────────────────────────────────────────
if (-not (Test-Path $BACKUP_DIR)) {
    Log "ERROR: Directorio de backups no existe: $BACKUP_DIR"
    exit 1
}

$items = Get-ChildItem -Path $BACKUP_DIR -Force |
    Where-Object { $_.Name -match "^$([regex]::Escape($PREFIJO))\d{4}-\d{2}-\d{2}_\d{2}-00$" } |
    Sort-Object LastWriteTime -Descending

Log "Backups encontrados localmente: $($items.Count)"

# ── 3. Para cada backup: subir si no existe ya en Drive ───────────────────────
$backups_info = @()
$subidos = 0
$errores = 0

foreach ($item in $items) {
    $nombre  = $item.Name
    $ruta    = $item.FullName
    $esCarpeta = $item.PSIsContainer

    # Calcular tamaño
    if ($esCarpeta) {
        $bytes = (Get-ChildItem -Path $ruta -Recurse -Force -ErrorAction SilentlyContinue |
                  Measure-Object -Property Length -Sum).Sum
        if ($null -eq $bytes) { $bytes = 0 }
    } else {
        $bytes = $item.Length
    }

    $tamStr = FormatSize($bytes)
    $fechaMod = $item.LastWriteTime.ToString("yyyy-MM-dd HH:mm:ss")

    Log "Procesando: $nombre ($tamStr)"

    # Verificar si ya existe en Google Drive
    $checkArgs = @("lsf", "$RCLONE_REMOTE`:$GDRIVE_FOLDER/$nombre", "--max-depth", "0")
    $checkOut = & $rclonePath @checkArgs 2>&1
    $yaExiste = ($LASTEXITCODE -eq 0) -and ($checkOut -ne "")

    $gdrive_link = ""
    $estado = "pendiente"

    if ($yaExiste) {
        Log "  -> Ya existe en Drive, omitiendo subida"
        $estado = "ok"
    } else {
        Log "  -> Subiendo a Google Drive..."
        
        if ($esCarpeta) {
            $uploadArgs = @("copy", $ruta, "$RCLONE_REMOTE`:$GDRIVE_FOLDER/$nombre", "--progress", "--transfers", "4")
        } else {
            $uploadArgs = @("copy", $ruta, "$RCLONE_REMOTE`:$GDRIVE_FOLDER", "--progress")
        }

        & $rclonePath @uploadArgs 2>&1 | ForEach-Object { Log "    rclone: $_" }

        if ($LASTEXITCODE -eq 0) {
            Log "  -> Subido correctamente"
            $estado = "ok"
            $subidos++
        } else {
            Log "  -> ERROR al subir (exit code: $LASTEXITCODE)"
            $estado = "error"
            $errores++
        }
    }

    # Obtener link de Drive
    if ($estado -eq "ok") {
        $linkArgs = @("link", "$RCLONE_REMOTE`:$GDRIVE_FOLDER/$nombre")
        $linkOut  = & $rclonePath @linkArgs 2>&1
        if ($LASTEXITCODE -eq 0) {
            $gdrive_link = $linkOut.Trim()
        }
    }

    # Parsear fecha y hora del nombre del backup
    if ($nombre -match "$([regex]::Escape($PREFIJO))(\d{4}-\d{2}-\d{2})_(\d{2})-00$") {
        $fechaBackup = $Matches[1]
        $horaBackup  = $Matches[2] + ":00"
    } else {
        $fechaBackup = $fechaMod.Substring(0,10)
        $horaBackup  = "00:00"
    }

    $backups_info += @{
        nombre      = $nombre
        fecha       = $fechaBackup
        hora        = $horaBackup
        tamanio_bytes = $bytes
        tamanio_str = $tamStr
        tipo        = if ($esCarpeta) { "carpeta" } else { "archivo" }
        estado      = $estado
        gdrive_link = $gdrive_link
        modificado  = $fechaMod
        timestamp   = [int](Get-Date $fechaMod -UFormat "%s")
    }
}

Log "Subidos nuevos: $subidos | Errores: $errores"

# ── 4. Construir JSON de estado ───────────────────────────────────────────────
$json_obj = @{
    generado_en    = (Get-Date -Format "yyyy-MM-dd HH:mm:ss")
    total_backups  = $backups_info.Count
    subidos_hoy    = $subidos
    errores        = $errores
    directorio_local = $BACKUP_DIR
    gdrive_folder  = "$RCLONE_REMOTE`:$GDRIVE_FOLDER"
    backups        = $backups_info
}

$json_str = $json_obj | ConvertTo-Json -Depth 10 -Compress:$false

# ── 5. Actualizar backup_status.json en GitHub via API ───────────────────────
Log "Actualizando $JSON_FILE en GitHub ($GITHUB_USER/$GITHUB_REPO)..."

$headers = @{
    Authorization = "token $GITHUB_TOKEN"
    Accept        = "application/vnd.github.v3+json"
    "User-Agent"  = "SIA-LAB-Backup-Script"
}

$apiUrl = "https://api.github.com/repos/$GITHUB_USER/$GITHUB_REPO/contents/$JSON_FILE"

# Obtener SHA actual del archivo (necesario para actualizar)
try {
    $getResp = Invoke-RestMethod -Uri $apiUrl -Headers $headers -Method GET -ErrorAction Stop
    $sha = $getResp.sha
    Log "SHA actual del archivo: $sha"
} catch {
    $sha = $null
    Log "Archivo no existe aún, se creará nuevo"
}

# Codificar contenido en Base64
$b64 = [Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes($json_str))

$body = @{
    message = "chore: actualizar backup_status.json $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
    content = $b64
    branch  = $GITHUB_BRANCH
}
if ($sha) { $body.sha = $sha }

try {
    $putResp = Invoke-RestMethod -Uri $apiUrl -Headers $headers -Method PUT `
                -Body ($body | ConvertTo-Json -Depth 5) -ContentType "application/json" -ErrorAction Stop
    Log "backup_status.json actualizado en GitHub correctamente"
    Log "Commit: $($putResp.commit.sha.Substring(0,8)) - $($putResp.commit.message)"
} catch {
    Log "ERROR al actualizar GitHub: $($_.Exception.Message)"
    Log "Respuesta: $($_.ErrorDetails.Message)"
}

Log "Proceso finalizado. Log en: $logFile"
Log "============================================"