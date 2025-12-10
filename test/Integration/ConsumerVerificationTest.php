<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Exception\Stop;

/**
 * Test to verify message consumption is working correctly
 * This test helps debug consumption issues
 */
class ConsumerVerificationTest extends IntegrationTestBase
{
    /**
     * Test that we can publish and immediately consume a message
     */
    public function testPublishAndConsumeImmediately()
    {
        // Publish a message
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        
        $testMessage = 'immediate-consume-test';
        $message = $this->createMessage($testMessage);
        
        echo "\n[TEST] Publishing message: {$testMessage}\n";
        $result = $publisher->publish($this->testRoutingKey, $message);
        echo "[TEST] Publish result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
        
        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
        echo "[TEST] Publisher connection closed\n";

        // Small delay to ensure message is in queue
        usleep(500000); // 0.5 seconds

        // Now consume it
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $messageCount = $consumer->getQueueMessageCount();
        echo "[TEST] Queue message count: {$messageCount}\n";
        
        $consumedMessage = null;
        $callbackExecuted = false;
        
        echo "[TEST] Starting consumer...\n";
        
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessage, &$callbackExecuted, $testMessage) {
                $callbackExecuted = true;
                $consumedMessage = $msg->body;
                echo "[TEST] Callback executed! Message received: {$consumedMessage}\n";
                echo "[TEST] Expected message: {$testMessage}\n";
                
                $resolver->acknowledge($msg);
                echo "[TEST] Message acknowledged\n";
                
                $resolver->stopWhenProcessed();
                echo "[TEST] Stop requested\n";
            });
        } catch (Stop $e) {
            echo "[TEST] Stop exception caught (expected)\n";
        } catch (\Exception $e) {
            echo "[TEST] Unexpected exception: " . $e->getMessage() . "\n";
            echo "[TEST] Exception class: " . get_class($e) . "\n";
            throw $e;
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        echo "[TEST] Consumer connection closed\n";

        // Assertions
        $this->assertTrue($callbackExecuted, 'Consumer callback should have been executed');
        $this->assertNotNull($consumedMessage, 'Message should have been consumed');
        $this->assertEquals($testMessage, $consumedMessage, 'Consumed message should match published message');
        
        echo "[TEST] All assertions passed!\n";
    }

    /**
     * Test consuming with timeout
     */
    public function testConsumeWithTimeout()
    {
        // Update config to have a timeout
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['timeout'] = 5;
        $config['properties']['test']['persistent'] = true; // Don't stop when queue is empty
        
        $configRepo = new \Illuminate\Config\Repository(['amqp' => $config]);
        
        // Publish a message
        $publisher = new Publisher($configRepo);
        $publisher->setup();
        
        $testMessage = 'timeout-test-' . time();
        $message = $this->createMessage($testMessage);
        
        echo "\n[TEST] Publishing message: {$testMessage}\n";
        $publisher->publish($this->testRoutingKey, $message);
        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Consume with timeout
        $consumer = new Consumer($configRepo);
        $consumer->setup();
        
        $consumedMessage = null;
        $callbackExecuted = false;
        
        echo "[TEST] Starting consumer with 5 second timeout...\n";
        
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessage, &$callbackExecuted) {
                $callbackExecuted = true;
                $consumedMessage = $msg->body;
                echo "[TEST] Message received: {$consumedMessage}\n";
                $resolver->acknowledge($msg);
                $resolver->stopWhenProcessed();
            });
        } catch (Stop $e) {
            echo "[TEST] Consumer stopped\n";
        } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
            echo "[TEST] Timeout occurred (this is OK if no more messages)\n";
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        $this->assertTrue($callbackExecuted, 'Callback should have executed');
        $this->assertEquals($testMessage, $consumedMessage);
    }

    /**
     * Test that we can check queue status
     */
    public function testQueueStatusCheck()
    {
        // Override queue_properties to remove max-length for this test
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['queue_properties'] = []; // No max-length
        
        $configRepo = new \Illuminate\Config\Repository(['amqp' => $config]);
        
        $publisher = new Publisher($configRepo);
        $publisher->setup();
        
        // Publish 3 messages
        for ($i = 1; $i <= 3; $i++) {
            $message = $this->createMessage("status-check-{$i}");
            $publisher->publish($this->testRoutingKey, $message);
            echo "[TEST] Published message {$i}\n";
            usleep(100000); // Small delay between messages
        }
        
        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Small delay to ensure all messages are in queue
        usleep(300000);

        // Check queue status
        $consumer = new Consumer($configRepo);
        $consumer->setup();
        
        $messageCount = $consumer->getQueueMessageCount();
        echo "[TEST] Queue has {$messageCount} messages\n";
        
        // If max-length is set to 1, only 1 message will be in queue
        // Otherwise, all 3 should be there
        $this->assertGreaterThanOrEqual(1, $messageCount, 'Queue should have at least 1 message');
        
        // Consume all messages
        $consumedCount = 0;
        $consumedMessages = [];
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedCount, &$consumedMessages) {
                $consumedCount++;
                $consumedMessages[] = $msg->body;
                echo "[TEST] Consumed message {$consumedCount}: {$msg->body}\n";
                $resolver->acknowledge($msg);
                if ($consumedCount >= $messageCount) {
                    $resolver->stopWhenProcessed();
                }
            });
        } catch (Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        
        // Should consume at least 1 message (or all if no max-length)
        $this->assertGreaterThanOrEqual(1, $consumedCount, 'Should have consumed at least 1 message');
        $this->assertContains('status-check-1', $consumedMessages, 'Should contain first message');
    }

    /**
     * Test consuming from empty queue (should handle gracefully)
     */
    public function testConsumeFromEmptyQueue()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['persistent'] = false; // Stop when empty
        
        $configRepo = new \Illuminate\Config\Repository(['amqp' => $config]);
        
        $consumer = new Consumer($configRepo);
        $consumer->setup();
        
        $messageCount = $consumer->getQueueMessageCount();
        echo "[TEST] Queue message count: {$messageCount}\n";
        
        $callbackExecuted = false;
        
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$callbackExecuted) {
                $callbackExecuted = true;
                $resolver->acknowledge($msg);
            });
        } catch (Stop $e) {
            echo "[TEST] Consumer stopped (expected for empty queue with persistent=false)\n";
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        
        $this->assertFalse($callbackExecuted, 'Callback should not execute for empty queue');
    }
}

