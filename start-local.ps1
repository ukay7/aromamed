$ErrorActionPreference = 'Stop'
$clinicPhp = 'D:\laragon\bin\php\php-8.4.16-Win32-vs17-x64\php.exe'
if (-not (Test-Path -LiteralPath $clinicPhp)) {
    $clinicPhp = (Get-Command php -ErrorAction Stop).Source
}
if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot 'vendor\autoload.php'))) {
    throw 'Install Composer dependencies before starting this application.'
}
Write-Host 'Aroma workspace: http://127.0.0.1:8088/admin'
$mysqlExecutable = 'D:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqld.exe'
$mysqlClient = [System.Net.Sockets.TcpClient]::new()
$mysqlRunning = $false
try { $mysqlClient.Connect('127.0.0.1',3308); $mysqlRunning = $true } catch { } finally { $mysqlClient.Dispose() }
if (-not $mysqlRunning) {
    $mysqlData = Join-Path $PSScriptRoot 'storage\mysql-data'
    if (-not (Test-Path -LiteralPath (Join-Path $mysqlData 'mysql'))) { throw 'The local MySQL data directory is missing. Initialize it before starting.' }
    Start-Process -FilePath $mysqlExecutable -WindowStyle Hidden -ArgumentList @('--no-defaults','--basedir=D:/laragon/bin/mysql/mysql-8.0.30-winx64',"--datadir=$mysqlData",'--port=3308','--bind-address=127.0.0.1','--mysqlx=0',"--log-error=$mysqlData/server-error.log","--pid-file=$mysqlData/server.pid")
    Write-Host 'MySQL is starting on localhost:3308. The web app will be ready once initialization finishes.'
}
Write-Host 'Press Ctrl+C to stop the local preview.'
Push-Location (Join-Path $PSScriptRoot 'public')
try {
    & $clinicPhp -S 127.0.0.1:8088 -t . '..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
} finally {
    Pop-Location
}
