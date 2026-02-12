@echo off
REM ============================================
REM Data Harvest - Weekly Analytics Report
REM ============================================
REM Dumps schema and generates PDF analytics report

setlocal enabledelayedexpansion

REM Configuration
set PHP_PATH=D:\ledger_server\php\php.exe
set MYSQL_PATH=D:\ledger_server\mariadb\bin\mysqldump.exe
set DB_USER=ledger_user
set DB_PASS=ledger123
set DB_NAME=ledger_db
set DB_HOST=127.0.0.1
set DB_PORT=3307

REM Create reports directory
if not exist "reports" mkdir reports

REM Generate timestamp
for /f "tokens=2-4 delims=/ " %%a in ('date /t') do (set mydate=%%c-%%a-%%b)
for /f "tokens=1-2 delims=/: " %%a in ('time /t') do (set mytime=%%a-%%b)
set TIMESTAMP=%mydate%_%mytime%

echo.
echo ============================================
echo Data Harvest Report Generator
echo ============================================
echo Timestamp: %TIMESTAMP%
echo.

REM Step 1: Dump schema
echo [1/2] Dumping database schema...
"%MYSQL_PATH%" -h %DB_HOST% -P %DB_PORT% -u %DB_USER% -p%DB_PASS% --no-data %DB_NAME% > "reports\schema_%TIMESTAMP%.sql"
if errorlevel 1 (
    echo ERROR: Schema dump failed
    pause
    exit /b 1
)
echo Schema saved to: reports\schema_%TIMESTAMP%.sql

REM Step 2: Generate analytics report
echo.
echo [2/2] Generating analytics PDF...
"%PHP_PATH%" includes\data_harvest_analytics.php
if errorlevel 1 (
    echo ERROR: Analytics generation failed
    pause
    exit /b 1
)

echo.
echo ============================================
echo Report Generation Complete
echo ============================================
echo Reports saved in: reports\
echo.
pause
