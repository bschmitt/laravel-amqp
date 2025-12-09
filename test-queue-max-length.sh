#!/bin/bash

# Test script for Queue Max Length feature
# This script helps test the queue max length functionality with Docker RabbitMQ

set -e

echo "=========================================="
echo "Queue Max Length Feature Test Script"
echo "=========================================="
echo ""

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo "❌ Docker is not running. Please start Docker first."
    exit 1
fi

# Start RabbitMQ if not running
if ! docker ps | grep -q rabbit; then
    echo "🚀 Starting RabbitMQ container..."
    docker-compose up -d rabbit
    echo "⏳ Waiting for RabbitMQ to be ready..."
    sleep 5
else
    echo "✅ RabbitMQ container is already running"
fi

# Check if RabbitMQ is accessible
if ! nc -z localhost 5672 2>/dev/null; then
    echo "❌ Cannot connect to RabbitMQ on localhost:5672"
    echo "   Please check if the container is running: docker ps"
    exit 1
fi

echo "✅ RabbitMQ is accessible on localhost:5672"
echo ""

# Check if vendor directory exists
if [ ! -d "vendor" ]; then
    echo "📦 Installing dependencies..."
    composer install
fi

echo ""
echo "🧪 Running Unit Tests (no RabbitMQ required)..."
echo "----------------------------------------"
php vendor/bin/phpunit test/QueueMaxLengthTest.php --colors=always

echo ""
echo "🧪 Running Integration Tests (requires RabbitMQ)..."
echo "----------------------------------------"
php vendor/bin/phpunit test/QueueMaxLengthIntegrationTest.php --colors=always

echo ""
echo "=========================================="
echo "✅ All tests completed!"
echo "=========================================="
echo ""
echo "To stop RabbitMQ: docker-compose down"

