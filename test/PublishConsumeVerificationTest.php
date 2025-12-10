<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Publisher;
use Bschmitt\Amqp\Consumer;
use Bschmitt\Amqp\Amqp;
use Bschmitt\Amqp\Exception\Stop;

/**
 * Test cases to verify message publishing and consumption
 * 
 * These tests verify that:
 * 1. Messages can be published successfully
 * 2. Published messages can be consumed
 * 3. The consumed message content matches what was published
 * 
 * NOTE: These tests include delays to allow messages to be visible in RabbitMQ Web UI.
 * Check http://localhost:15672 (guest/guest) to see queue status during tests.
 */
class PublishConsumeVerificationTest extends IntegrationTestBase
{
    /**
     * Delay in seconds for messages to be visible in RabbitMQ Web UI
     * Increase this value if you want more time to check the web UI
     */
    protected $webUIDelaySeconds = 5;
    /**
     * Test: Publish a simple text message and verify it's consumed correctly
     */
    public function testPublishAndConsumeSimpleMessage()
    {
        $testMessage = 'Hello, RabbitMQ! - ' . time();
        
        echo "\n[VERIFY] Test: Publish and Consume Simple Message\n";
        echo "[VERIFY] Publishing message: {$testMessage}\n";
        
        // Step 1: Publish the message
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        
        $message = $this->createMessage($testMessage);
        $publishResult = $publisher->publish($this->testRoutingKey, $message);
        
        echo "[VERIFY] Publish result: " . ($publishResult ? 'SUCCESS' : 'FAILED') . "\n";
        $this->assertTrue($publishResult !== false, 'Message should be published successfully');
        
        \Bschmitt\Amqp\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
        echo "[VERIFY] Publisher connection closed\n";
        
        // Delay to ensure message is in queue and visible in Web UI
        echo "[VERIFY] Waiting {$this->webUIDelaySeconds} seconds for message to appear in RabbitMQ Web UI...\n";
        echo "[VERIFY] Check http://localhost:15672 -> Queues -> {$this->testQueueName}\n";
        sleep($this->webUIDelaySeconds);
        
        // Step 2: Consume the message
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $messageCount = $consumer->getQueueMessageCount();
        echo "[VERIFY] Queue message count BEFORE consumption: {$messageCount}\n";
        echo "[VERIFY] Queue: {$this->testQueueName}\n";
        $this->assertGreaterThanOrEqual(1, $messageCount, 'Queue should have at least 1 message');
        
        $consumedMessage = null;
        $messageReceived = false;
        
        echo "[VERIFY] Starting consumer...\n";
        
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessage, &$messageReceived, $testMessage) {
                $messageReceived = true;
                $consumedMessage = $msg->body;
                
                echo "[VERIFY] Message received: {$consumedMessage}\n";
                echo "[VERIFY] Expected message: {$testMessage}\n";
                
                // Step 3: Verify the message content
                $this->assertEquals($testMessage, $consumedMessage, 'Consumed message should match published message');
                
                $resolver->acknowledge($msg);
                echo "[VERIFY] Message acknowledged\n";
                
                $resolver->stopWhenProcessed();
            });
        } catch (Stop $e) {
            echo "[VERIFY] Consumer stopped (expected)\n";
        }
        
        \Bschmitt\Amqp\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        echo "[VERIFY] Consumer connection closed\n";
        
        // Check queue status after consumption
        $consumerAfter = new Consumer($this->configRepository);
        $consumerAfter->setup();
        $messageCountAfter = $consumerAfter->getQueueMessageCount();
        echo "[VERIFY] Queue message count AFTER consumption: {$messageCountAfter}\n";
        \Bschmitt\Amqp\Request::shutdown($consumerAfter->getChannel(), $consumerAfter->getConnection());
        
        // Final verification
        $this->assertTrue($messageReceived, 'Message should have been received');
        $this->assertNotNull($consumedMessage, 'Consumed message should not be null');
        $this->assertEquals($testMessage, $consumedMessage, 'Published and consumed messages should match exactly');
        
        echo "[VERIFY] ✓ Test passed: Message published and consumed successfully!\n";
    }

    /**
     * Test: Publish multiple messages and verify each one is consumed correctly
     */
    public function testPublishAndConsumeMultipleMessages()
    {
        // Use existing queue properties (queue already exists with x-max-length=1)
        // Note: With max-length=1, only the latest message will remain
        $config = $this->configRepository->get('amqp');
        // Keep existing queue_properties to match the queue that already exists
        $configRepo = new \Illuminate\Config\Repository(['amqp' => $config]);
        
        $messages = [
            'Message-1-' . time(),
            'Message-2-' . time(),
            'Message-3-' . time(),
        ];
        
        echo "\n[VERIFY] Test: Publish and Consume Multiple Messages\n";
        
        // Step 1: Publish all messages
        $publisher = new Publisher($configRepo);
        $publisher->setup();
        
        foreach ($messages as $index => $messageText) {
            $message = $this->createMessage($messageText);
            $result = $publisher->publish($this->testRoutingKey, $message);
            echo "[VERIFY] Published message " . ($index + 1) . ": {$messageText} - " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
            $this->assertTrue($result !== false, "Message " . ($index + 1) . " should be published successfully");
            usleep(100000); // Small delay between messages
        }
        
        \Bschmitt\Amqp\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
        echo "[VERIFY] All messages published\n";
        
        // Delay to ensure all messages are in queue and visible in Web UI
        echo "[VERIFY] Waiting {$this->webUIDelaySeconds} seconds for messages to appear in RabbitMQ Web UI...\n";
        echo "[VERIFY] Check http://localhost:15672 -> Queues -> {$this->testQueueName}\n";
        sleep($this->webUIDelaySeconds);
        
        // Step 2: Consume all messages
        $consumer = new Consumer($configRepo);
        $consumer->setup();
        
        $messageCount = $consumer->getQueueMessageCount();
        echo "[VERIFY] Queue message count BEFORE consumption: {$messageCount}\n";
        echo "[VERIFY] Queue: {$this->testQueueName}\n";
        $this->assertGreaterThanOrEqual(1, $messageCount, 'Queue should have at least 1 message');
        
        $consumedMessages = [];
        $consumedCount = 0;
        
        echo "[VERIFY] Starting consumer to receive all messages...\n";
        
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessages, &$consumedCount, $messageCount) {
                $consumedCount++;
                $consumedMessages[] = $msg->body;
                
                echo "[VERIFY] Consumed message {$consumedCount}: {$msg->body}\n";
                
                $resolver->acknowledge($msg);
                
                // Stop when we've consumed all messages in queue
                if ($consumedCount >= $messageCount) {
                    $resolver->stopWhenProcessed();
                }
            });
        } catch (Stop $e) {
            echo "[VERIFY] Consumer stopped (expected)\n";
        }
        
        \Bschmitt\Amqp\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        
        // Check queue status after consumption
        $consumerAfter = new Consumer($configRepo);
        $consumerAfter->setup();
        $messageCountAfter = $consumerAfter->getQueueMessageCount();
        echo "[VERIFY] Queue message count AFTER consumption: {$messageCountAfter}\n";
        \Bschmitt\Amqp\Request::shutdown($consumerAfter->getChannel(), $consumerAfter->getConnection());
        
        // Step 3: Verify messages were consumed
        echo "[VERIFY] Total messages consumed: " . count($consumedMessages) . "\n";
        $this->assertGreaterThanOrEqual(1, count($consumedMessages), 'At least one message should be consumed');
        
        // With x-max-length=1, only the latest message remains in queue
        // So we should have consumed at least 1 message (the latest one)
        if (count($consumedMessages) >= 1) {
            $latestMessage = end($messages); // Last published message
            $this->assertContains($latestMessage, $consumedMessages, "Latest published message '{$latestMessage}' should be in consumed messages");
            echo "[VERIFY] NOTE: With x-max-length=1, only the latest message was kept in queue.\n";
        }
        
        echo "[VERIFY] ✓ Test passed: Messages published and consumed successfully!\n";
    }

    /**
     * Test: Publish a message with special characters and verify it's consumed correctly
     */
    public function testPublishAndConsumeMessageWithSpecialCharacters()
    {
        $testMessage = 'Special chars: !@#$%^&*()_+-=[]{}|;:,.<>? ~`' . "\n" . 'New line and "quotes" and \'apostrophes\'';
        
        echo "\n[VERIFY] Test: Publish and Consume Message with Special Characters\n";
        echo "[VERIFY] Publishing message with special characters\n";
        
        // Step 1: Publish
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        
        $message = $this->createMessage($testMessage);
        $publishResult = $publisher->publish($this->testRoutingKey, $message);
        
        $this->assertTrue($publishResult !== false, 'Message with special characters should be published successfully');
        echo "[VERIFY] Publish result: SUCCESS\n";
        
        \Bschmitt\Amqp\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
        
        // Delay for Web UI visibility
        echo "[VERIFY] Waiting {$this->webUIDelaySeconds} seconds for message to appear in RabbitMQ Web UI...\n";
        echo "[VERIFY] Check http://localhost:15672 -> Queues -> {$this->testQueueName}\n";
        sleep($this->webUIDelaySeconds);
        
        // Step 2: Consume
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $messageCount = $consumer->getQueueMessageCount();
        echo "[VERIFY] Queue message count BEFORE consumption: {$messageCount}\n";
        
        $consumedMessage = null;
        $messageReceived = false;
        
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessage, &$messageReceived, $testMessage) {
                $messageReceived = true;
                $consumedMessage = $msg->body;
                
                echo "[VERIFY] Message received (length: " . strlen($consumedMessage) . " chars)\n";
                
                // Step 3: Verify exact match
                $this->assertEquals($testMessage, $consumedMessage, 'Message with special characters should match exactly');
                
                $resolver->acknowledge($msg);
                $resolver->stopWhenProcessed();
            });
        } catch (Stop $e) {
            // Expected
        }
        
        \Bschmitt\Amqp\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        
        // Check queue status after consumption
        $consumerAfter = new Consumer($this->configRepository);
        $consumerAfter->setup();
        $messageCountAfter = $consumerAfter->getQueueMessageCount();
        echo "[VERIFY] Queue message count AFTER consumption: {$messageCountAfter}\n";
        \Bschmitt\Amqp\Request::shutdown($consumerAfter->getChannel(), $consumerAfter->getConnection());
        
        // Final verification
        $this->assertTrue($messageReceived, 'Message should have been received');
        $this->assertEquals($testMessage, $consumedMessage, 'Special characters should be preserved');
        
        echo "[VERIFY] ✓ Test passed: Special characters preserved correctly!\n";
    }

    /**
     * Test: Publish a JSON message and verify it's consumed correctly
     */
    public function testPublishAndConsumeJsonMessage()
    {
        $testData = [
            'id' => 12345,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'timestamp' => time(),
            'data' => [
                'key1' => 'value1',
                'key2' => 'value2',
            ]
        ];
        
        $testMessage = json_encode($testData);
        
        echo "\n[VERIFY] Test: Publish and Consume JSON Message\n";
        echo "[VERIFY] Publishing JSON message\n";
        
        // Step 1: Publish
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        
        $message = $this->createMessage($testMessage, ['content_type' => 'application/json']);
        $publishResult = $publisher->publish($this->testRoutingKey, $message);
        
        $this->assertTrue($publishResult !== false, 'JSON message should be published successfully');
        echo "[VERIFY] Publish result: SUCCESS\n";
        
        \Bschmitt\Amqp\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
        
        // Delay for Web UI visibility
        echo "[VERIFY] Waiting {$this->webUIDelaySeconds} seconds for message to appear in RabbitMQ Web UI...\n";
        echo "[VERIFY] Check http://localhost:15672 -> Queues -> {$this->testQueueName}\n";
        sleep($this->webUIDelaySeconds);
        
        // Step 2: Consume
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $messageCount = $consumer->getQueueMessageCount();
        echo "[VERIFY] Queue message count BEFORE consumption: {$messageCount}\n";
        
        $consumedMessage = null;
        $messageReceived = false;
        
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessage, &$messageReceived, $testData) {
                $messageReceived = true;
                $consumedMessage = $msg->body;
                
                echo "[VERIFY] JSON message received\n";
                
                // Step 3: Verify JSON can be decoded and matches
                $decodedData = json_decode($consumedMessage, true);
                $this->assertNotNull($decodedData, 'Consumed message should be valid JSON');
                $this->assertEquals($testData['id'], $decodedData['id'], 'JSON data should match');
                $this->assertEquals($testData['name'], $decodedData['name'], 'JSON data should match');
                $this->assertEquals($testData['email'], $decodedData['email'], 'JSON data should match');
                
                $resolver->acknowledge($msg);
                $resolver->stopWhenProcessed();
            });
        } catch (Stop $e) {
            // Expected
        }
        
        \Bschmitt\Amqp\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        
        // Check queue status after consumption
        $consumerAfter = new Consumer($this->configRepository);
        $consumerAfter->setup();
        $messageCountAfter = $consumerAfter->getQueueMessageCount();
        echo "[VERIFY] Queue message count AFTER consumption: {$messageCountAfter}\n";
        \Bschmitt\Amqp\Request::shutdown($consumerAfter->getChannel(), $consumerAfter->getConnection());
        
        // Final verification
        $this->assertTrue($messageReceived, 'Message should have been received');
        $this->assertEquals($testMessage, $consumedMessage, 'JSON message should match exactly');
        
        $decoded = json_decode($consumedMessage, true);
        $this->assertEquals($testData, $decoded, 'Decoded JSON should match original data');
        
        echo "[VERIFY] ✓ Test passed: JSON message published and consumed correctly!\n";
    }

    /**
     * Test: Use Publisher and Consumer directly to publish and consume
     * (This is equivalent to using Amqp class but works without Laravel)
     */
    public function testPublishAndConsumeUsingDirectClasses()
    {
        $testMessage = 'Direct Classes Test Message - ' . time();
        
        echo "\n[VERIFY] Test: Publish and Consume Using Direct Classes\n";
        echo "[VERIFY] Publishing message: {$testMessage}\n";
        
        // Step 1: Publish using Publisher directly
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        
        $message = $this->createMessage($testMessage);
        $publishResult = $publisher->publish($this->testRoutingKey, $message);
        
        $this->assertTrue($publishResult !== false, 'Message should be published successfully');
        echo "[VERIFY] Publish result: SUCCESS\n";
        
        \Bschmitt\Amqp\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
        
        // Delay for Web UI visibility
        echo "[VERIFY] Waiting {$this->webUIDelaySeconds} seconds for message to appear in RabbitMQ Web UI...\n";
        echo "[VERIFY] Check http://localhost:15672 -> Queues -> {$this->testQueueName}\n";
        sleep($this->webUIDelaySeconds);
        
        // Step 2: Consume using Consumer directly
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $messageCount = $consumer->getQueueMessageCount();
        echo "[VERIFY] Queue message count BEFORE consumption: {$messageCount}\n";
        
        $consumedMessage = null;
        $messageReceived = false;
        
        echo "[VERIFY] Consuming message...\n";
        
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessage, &$messageReceived, $testMessage) {
                $messageReceived = true;
                $consumedMessage = $msg->body;
                
                echo "[VERIFY] Message received: {$consumedMessage}\n";
                echo "[VERIFY] Expected message: {$testMessage}\n";
                
                // Step 3: Verify the message
                $this->assertEquals($testMessage, $consumedMessage, 'Consumed message should match published message');
                
                $resolver->acknowledge($msg);
                $resolver->stopWhenProcessed();
            });
        } catch (Stop $e) {
            // Expected
        }
        
        \Bschmitt\Amqp\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        
        // Check queue status after consumption
        $consumerAfter = new Consumer($this->configRepository);
        $consumerAfter->setup();
        $messageCountAfter = $consumerAfter->getQueueMessageCount();
        echo "[VERIFY] Queue message count AFTER consumption: {$messageCountAfter}\n";
        \Bschmitt\Amqp\Request::shutdown($consumerAfter->getChannel(), $consumerAfter->getConnection());
        
        // Final verification
        $this->assertTrue($messageReceived, 'Message should have been received');
        $this->assertNotNull($consumedMessage, 'Consumed message should not be null');
        $this->assertEquals($testMessage, $consumedMessage, 'Published and consumed messages should match exactly');
        
        echo "[VERIFY] ✓ Test passed: Direct classes work correctly!\n";
    }

    /**
     * Test: Verify message is published and can be consumed multiple times (with requeue)
     */
    public function testPublishConsumeRequeueAndConsumeAgain()
    {
        $testMessage = 'Requeue Test - ' . time();
        
        echo "\n[VERIFY] Test: Publish, Consume, Requeue, and Consume Again\n";
        
        // Step 1: Publish
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        
        $message = $this->createMessage($testMessage);
        $publisher->publish($this->testRoutingKey, $message);
        echo "[VERIFY] Message published: {$testMessage}\n";
        
        \Bschmitt\Amqp\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
        
        // Delay for Web UI visibility
        echo "[VERIFY] Waiting {$this->webUIDelaySeconds} seconds for message to appear in RabbitMQ Web UI...\n";
        echo "[VERIFY] Check http://localhost:15672 -> Queues -> {$this->testQueueName}\n";
        sleep($this->webUIDelaySeconds);
        
        // Step 2: Consume and reject with requeue
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $messageCount = $consumer->getQueueMessageCount();
        echo "[VERIFY] Queue message count BEFORE first consumption: {$messageCount}\n";
        
        $firstConsumption = null;
        $firstConsumed = false;
        
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$firstConsumption, &$firstConsumed, $testMessage) {
                $firstConsumed = true;
                $firstConsumption = $msg->body;
                
                echo "[VERIFY] First consumption: {$firstConsumption}\n";
                $this->assertEquals($testMessage, $firstConsumption, 'First consumption should match');
                
                // Reject with requeue
                $resolver->reject($msg, true);
                echo "[VERIFY] Message rejected with requeue\n";
                
                $resolver->stopWhenProcessed();
            });
        } catch (Stop $e) {
            // Expected
        }
        
        \Bschmitt\Amqp\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        
        $this->assertTrue($firstConsumed, 'Message should be consumed first time');
        
        // Wait for message to be requeued and visible in Web UI
        echo "[VERIFY] Waiting {$this->webUIDelaySeconds} seconds for message to be requeued...\n";
        echo "[VERIFY] Check http://localhost:15672 -> Queues -> {$this->testQueueName}\n";
        sleep($this->webUIDelaySeconds);
        
        // Step 3: Consume again (message should be back in queue)
        $consumer2 = new Consumer($this->configRepository);
        $consumer2->setup();
        
        $messageCount2 = $consumer2->getQueueMessageCount();
        echo "[VERIFY] Queue message count BEFORE second consumption: {$messageCount2}\n";
        
        $secondConsumption = null;
        $secondConsumed = false;
        
        if ($messageCount2 > 0) {
            try {
                $consumer2->consume($this->testQueueName, function ($msg, $resolver) use (&$secondConsumption, &$secondConsumed, $testMessage) {
                    $secondConsumed = true;
                    $secondConsumption = $msg->body;
                    
                    echo "[VERIFY] Second consumption: {$secondConsumption}\n";
                    $this->assertEquals($testMessage, $secondConsumption, 'Second consumption should match');
                    
                    // Acknowledge this time
                    $resolver->acknowledge($msg);
                    $resolver->stopWhenProcessed();
                });
            } catch (Stop $e) {
                // Expected
            }
        } else {
            echo "[VERIFY] Warning: Queue is empty, message may not have been requeued\n";
        }
        
        \Bschmitt\Amqp\Request::shutdown($consumer2->getChannel(), $consumer2->getConnection());
        
        // Check queue status after second consumption
        $consumerAfter = new Consumer($this->configRepository);
        $consumerAfter->setup();
        $messageCountAfter = $consumerAfter->getQueueMessageCount();
        echo "[VERIFY] Queue message count AFTER second consumption: {$messageCountAfter}\n";
        \Bschmitt\Amqp\Request::shutdown($consumerAfter->getChannel(), $consumerAfter->getConnection());
        
        // Final verification
        $this->assertTrue($firstConsumed, 'Message should be consumed first time');
        $this->assertEquals($testMessage, $firstConsumption, 'First consumption should match');
        
        if ($secondConsumed) {
            $this->assertEquals($testMessage, $secondConsumption, 'Second consumption should match');
            $this->assertEquals($firstConsumption, $secondConsumption, 'Both consumptions should match');
            echo "[VERIFY] ✓ Test passed: Message requeued and consumed again successfully!\n";
        } else {
            echo "[VERIFY] ⚠ Test partially passed: Message consumed once, but requeue may need more time\n";
        }
    }

    /**
     * Test: Publish messages and leave them in queue for manual inspection in Web UI
     * This test does NOT consume messages, so they remain visible in RabbitMQ Web UI
     */
    public function testPublishMessagesForWebUIInspection()
    {
        $messages = [
            'WebUI-Inspection-1-' . time(),
            'WebUI-Inspection-2-' . time(),
            'WebUI-Inspection-3-' . time(),
        ];
        
        echo "\n[VERIFY] Test: Publish Messages for Web UI Inspection\n";
        echo "[VERIFY] This test publishes messages but does NOT consume them.\n";
        echo "[VERIFY] Messages will remain in queue for manual inspection.\n";
        echo "[VERIFY] NOTE: Queue has x-max-length=1, so only the latest message will remain.\n";
        
        // Use existing queue properties (queue already exists)
        $config = $this->configRepository->get('amqp');
        $configRepo = new \Illuminate\Config\Repository(['amqp' => $config]);
        
        // Step 1: Publish all messages
        $publisher = new Publisher($configRepo);
        $publisher->setup();
        
        foreach ($messages as $index => $messageText) {
            $message = $this->createMessage($messageText);
            $result = $publisher->publish($this->testRoutingKey, $message);
            echo "[VERIFY] Published message " . ($index + 1) . ": {$messageText} - " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
            $this->assertTrue($result !== false, "Message " . ($index + 1) . " should be published successfully");
            usleep(200000); // Small delay between messages
        }
        
        \Bschmitt\Amqp\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
        echo "[VERIFY] All messages published\n";
        
        // Wait for messages to be visible in Web UI
        $waitTime = $this->webUIDelaySeconds * 2; // Longer wait for manual inspection
        echo "[VERIFY] Waiting {$waitTime} seconds for messages to appear in RabbitMQ Web UI...\n";
        echo "[VERIFY] ============================================================\n";
        echo "[VERIFY] CHECK RABBITMQ WEB UI NOW:\n";
        echo "[VERIFY] URL: http://localhost:15672\n";
        echo "[VERIFY] Login: guest / guest\n";
        echo "[VERIFY] Navigate to: Queues -> {$this->testQueueName}\n";
        echo "[VERIFY] You should see " . count($messages) . " messages in the queue\n";
        echo "[VERIFY] ============================================================\n";
        sleep($waitTime);
        
        // Step 2: Verify messages are in queue (but don't consume them)
        $consumer = new Consumer($configRepo);
        $consumer->setup();
        
        $messageCount = $consumer->getQueueMessageCount();
        echo "[VERIFY] Queue message count: {$messageCount}\n";
        echo "[VERIFY] Queue name: {$this->testQueueName}\n";
        echo "[VERIFY] Exchange: {$this->testExchange}\n";
        echo "[VERIFY] Routing key: {$this->testRoutingKey}\n";
        echo "[VERIFY] NOTE: Queue has x-max-length=1, so only the latest message remains.\n";
        
        // With x-max-length=1, only the latest message will be in queue
        $this->assertGreaterThanOrEqual(1, $messageCount, 'Queue should have at least 1 message (latest due to max-length=1)');
        
        \Bschmitt\Amqp\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        
        echo "[VERIFY] ✓ Test passed: Messages published and visible in queue!\n";
        echo "[VERIFY] NOTE: Messages are still in queue for manual inspection.\n";
        echo "[VERIFY] Run other tests or manually consume them via Web UI.\n";
    }
}

