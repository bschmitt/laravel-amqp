@echo off
REM Test script for Queue Max Length feature (Windows)
REM This script helps test the queue max length functionality with Docker RabbitMQ

echo ==========================================
echo Queue Max Length Feature Test Script
echo ==========================================
echo.

REM Check if Docker is running
docker info >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Docker is not running. Please start Docker first.
    exit /b 1
)

REM Start RabbitMQ if not running
docker ps | findstr /i "rabbit" >nul 2>&1
if errorlevel 1 (
    echo [INFO] Starting RabbitMQ container...
    docker-compose up -d rabbit
    echo [INFO] Waiting for RabbitMQ to be ready...
    timeout /t 5 /nobreak >nul
) else (
    echo [OK] RabbitMQ container is already running
)

echo [OK] RabbitMQ should be accessible on localhost:5672
echo.

REM Check if vendor directory exists
if not exist "vendor" (
    echo [INFO] Installing dependencies...
    composer install
)

echo.
echo [TEST] Running Unit Tests (no RabbitMQ required)...
echo ----------------------------------------
php vendor\bin\phpunit test\QueueMaxLengthTest.php --colors=always

echo.
echo [TEST] Running Integration Tests (requires RabbitMQ)...
echo ----------------------------------------
php vendor\bin\phpunit test\QueueMaxLengthIntegrationTest.php --colors=always

echo.
echo ==========================================
echo [OK] All tests completed!
echo ==========================================
echo.
echo To stop RabbitMQ: docker-compose down



