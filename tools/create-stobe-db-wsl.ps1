param(
    [switch]$Reset,
    [string]$Database = "stobe",
    [string]$Owner = "dwemer",
    [string]$Password = "dwemer"
)

$ErrorActionPreference = "Stop"

if ($Database -notmatch '^[A-Za-z_][A-Za-z0-9_]*$') {
    throw "Invalid database name '$Database'."
}
if ($Owner -notmatch '^[A-Za-z_][A-Za-z0-9_]*$') {
    throw "Invalid database owner '$Owner'."
}

$serverRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
$schemaWin = Resolve-Path (Join-Path $serverRoot "data\schema.sql")
$bootstrapWin = Resolve-Path (Join-Path $serverRoot "tools\bootstrap-database.php")
$schemaLinux = (& wsl.exe -- wslpath -a $schemaWin.Path).Trim()
$bootstrapLinux = (& wsl.exe -- wslpath -a $bootstrapWin.Path).Trim()

Write-Host "Ensuring WSL PostgreSQL database '$Database' exists with owner '$Owner'."

if ($Reset) {
    Write-Host "Reset requested; dropping '$Database'."
    & wsl.exe -- sudo -n -u postgres dropdb --force --if-exists $Database
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to drop PostgreSQL database '$Database'."
    }
}

$databaseExistsOutput = & wsl.exe -- sudo -n -u postgres psql -Atqc "SELECT 1 FROM pg_database WHERE datname='$Database';"
$databaseExists = ($databaseExistsOutput | Out-String).Trim()
if ($databaseExists -ne "1") {
    & wsl.exe -- sudo -n -u postgres createdb -O $Owner --encoding=UTF8 --locale=C --template=template0 $Database
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to create PostgreSQL database '$Database'."
    }
} else {
    $databaseEncodingOutput = & wsl.exe -- sudo -n -u postgres psql -d $Database -Atqc "SHOW server_encoding;"
    $databaseEncoding = ($databaseEncodingOutput | Out-String).Trim()
    if ($databaseEncoding -ne 'UTF8') {
        throw "Database '$Database' uses $databaseEncoding. Run tools/migrate-stobe-db-utf8-wsl.sh inside WSL before continuing."
    }
    Write-Host "Database '$Database' already exists with UTF8 encoding."
}

& wsl.exe -- sudo -n -u postgres psql -d $Database -v ON_ERROR_STOP=1 `
    -c "CREATE EXTENSION IF NOT EXISTS vector;" `
    -c "ALTER DATABASE $Database OWNER TO $Owner;"
if ($LASTEXITCODE -ne 0) {
    throw "Failed to prepare PostgreSQL database '$Database'."
}

$eventlogExistsOutput = & wsl.exe -- env "PGPASSWORD=$Password" psql -h 127.0.0.1 -U $Owner -d $Database -Atqc "SELECT to_regclass('public.eventlog') IS NOT NULL;"
$eventlogExists = ($eventlogExistsOutput | Out-String).Trim()
if ($eventlogExists -ne "t") {
    Write-Host "Importing Stobe baseline schema into '$Database'."
    & wsl.exe -- env "PGPASSWORD=$Password" psql -h 127.0.0.1 -U $Owner -d $Database -v ON_ERROR_STOP=1 -f $schemaLinux
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to import baseline schema into '$Database'."
    }
}

Write-Host "Running Stobe database updates and verification."
& wsl.exe -- env `
    "STOBE_DB_NAME=$Database" `
    "STOBE_DB_USER=$Owner" `
    "STOBE_DB_PASSWORD=$Password" `
    php $bootstrapLinux
if ($LASTEXITCODE -ne 0) {
    throw "Stobe database updates failed."
}

Write-Host "Database '$Database' is ready."
