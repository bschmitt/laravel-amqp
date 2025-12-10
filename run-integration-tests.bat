@echo off
REM Run integration tests with real RabbitMQ credentials
REM No mocks - all tests use actual AMQP connections

echo Running integration tests with real RabbitMQ credentials...
echo Make sure RabbitMQ is running and credentials are set in .env
echo.

cd /d "%~dp0"

php vendor/bin/phpunit test/FullIntegrationTest.php test/QueueMaxLengthIntegrationTest.php --colors=always


