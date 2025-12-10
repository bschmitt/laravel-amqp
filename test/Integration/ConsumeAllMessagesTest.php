<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Exception\Stop;

/**
 * Test to consume ALL messages from a queue
 * This test will consume all existing messages in the queue, not just one
 */
class ConsumeAllMessagesTest extends IntegrationTestBase
{
    /**
     * Test: Consume all messages from the queue
     * This will consume any existing messages (like the 6 messages you mentioned)
     */
    public function testConsumeAllMessagesFromQueue()
    {
        echo "\n[CONSUME ALL] Test: Consume All Messages From Queue\n";
        
        // Step 1: Check current queue status
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $initialMessageCount = $consumer->getQueueMessageCount();
        echo "[CONSUME ALL] Initial queue message count: {$initialMessageCount}\n";
        echo "[CONSUME ALL] Queue name: {$this->testQueueName}\n";
        echo "[CONSUME ALL] Exchange: {$this->testExchange}\n";
        
        if ($initialMessageCount === 0) {
            echo "[CONSUME ALL] Queue is empty. Publishing 3 test messages first...\n";
            
            // Publish some test messages
            $publisher = new Publisher($this->configRepository);
            $publisher->setup();
            
            for ($i = 1; $i <= 3; $i++) {
                $message = $this->createMessage("Test Message {$i} - " . time());
                $publisher->publish($this->testRoutingKey, $message);
                echo "[CONSUME ALL] Published message {$i}\n";
            }
            
            \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
            
            // Wait for messages to be in queue
            sleep(2);
            
            // Re-check message count
            $consumer->setup(); // Re-setup to refresh message count
            $initialMessageCount = $consumer->getQueueMessageCount();
            echo "[CONSUME ALL] Queue message count after publishing: {$initialMessageCount}\n";
        }
        
        if ($initialMessageCount === 0) {
            echo "[CONSUME ALL] ⚠ Queue is still empty. Nothing to consume.\n";
            \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
            $this->markTestSkipped('Queue is empty - no messages to consume');
            return;
        }
        
        echo "[CONSUME ALL] Starting to consume all {$initialMessageCount} messages...\n";
        echo "[CONSUME ALL] Waiting 5 seconds for messages to be visible in Web UI...\n";
        echo "[CONSUME ALL] Check http://localhost:15672 -> Queues -> {$this->testQueueName}\n";
        sleep(5);
        
        // Step 2: Consume ALL messages using a loop approach
        $consumedMessages = [];
        $maxIterations = 50; // Prevent infinite loop
        $iteration = 0;
        
        echo "[CONSUME ALL] Consumer started. Will consume until queue is empty...\n";
        
        while ($iteration < $maxIterations) {
            $iteration++;
            
            // Refresh consumer to get current message count
            $consumer->setup();
            $currentCount = $consumer->getQueueMessageCount();
            
            if ($currentCount === 0) {
                echo "[CONSUME ALL] Queue is empty. All messages consumed!\n";
                break;
            }
            
            echo "[CONSUME ALL] Iteration {$iteration}: {$currentCount} messages remaining\n";
            
            // Consume messages in this iteration
            $consumedInIteration = 0;
            $expectedToConsume = min($currentCount, 10); // Consume up to 10 at a time
            
            try {
                $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessages, &$consumedInIteration, $expectedToConsume) {
                    $consumedInIteration++;
                    $consumedMessages[] = $msg->body;
                    
                    echo "[CONSUME ALL] Consumed message " . count($consumedMessages) . ": {$msg->body}\n";
                    
                    // Acknowledge the message
                    $resolver->acknowledge($msg);
                    
                    // Stop after consuming expected amount
                    if ($consumedInIteration >= $expectedToConsume) {
                        $resolver->stopWhenProcessed();
                    }
                });
            } catch (Stop $e) {
                // Expected when stopWhenProcessed is called
                echo "[CONSUME ALL] Stopped after consuming {$consumedInIteration} messages in this iteration\n";
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                // Timeout - might mean queue is empty or no more messages
                echo "[CONSUME ALL] Timeout in iteration {$iteration} (consumed {$consumedInIteration} messages)\n";
            }
            
            // Close connection for next iteration
            \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
            
            // Small delay between iterations
            usleep(300000); // 0.3 seconds
        }
        
        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        
        // Step 3: Check final queue status
        $finalConsumer = new Consumer($this->configRepository);
        $finalConsumer->setup();
        $finalMessageCount = $finalConsumer->getQueueMessageCount();
        echo "[CONSUME ALL] Final queue message count: {$finalMessageCount}\n";
        \Bschmitt\Amqp\Core\Request::shutdown($finalConsumer->getChannel(), $finalConsumer->getConnection());
        
        // Step 4: Report results
        $totalConsumed = count($consumedMessages);
        
        echo "[CONSUME ALL] ============================================================\n";
        echo "[CONSUME ALL] SUMMARY:\n";
        echo "[CONSUME ALL] - Initial messages: {$initialMessageCount}\n";
        echo "[CONSUME ALL] - Messages consumed: {$totalConsumed}\n";
        echo "[CONSUME ALL] - Remaining messages: {$finalMessageCount}\n";
        echo "[CONSUME ALL] ============================================================\n";
        
        $this->assertGreaterThan(0, $totalConsumed, 'Should have consumed at least one message');
        $this->assertEquals($initialMessageCount, $totalConsumed, "Should have consumed all {$initialMessageCount} messages");
        $this->assertEquals(0, $finalMessageCount, 'Queue should be empty after consuming all messages');
        
        echo "[CONSUME ALL] ✓ Test passed: All messages consumed successfully!\n";
    }

    /**
     * Test: Consume messages in a loop until queue is empty
     * This is a more reliable way to consume all messages
     */
    public function testConsumeAllMessagesInLoop()
    {
        echo "\n[CONSUME LOOP] Test: Consume All Messages In Loop\n";
        
        // Step 1: Check and publish if needed
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $initialMessageCount = $consumer->getQueueMessageCount();
        echo "[CONSUME LOOP] Initial queue message count: {$initialMessageCount}\n";
        
        if ($initialMessageCount === 0) {
            echo "[CONSUME LOOP] Queue is empty. Publishing 5 test messages...\n";
            
            $publisher = new Publisher($this->configRepository);
            $publisher->setup();
            
            for ($i = 1; $i <= 5; $i++) {
                $message = $this->createMessage("Loop Test Message {$i} - " . time());
                $publisher->publish($this->testRoutingKey, $message);
            }
            
            \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
            sleep(2);
            
            $consumer->setup();
            $initialMessageCount = $consumer->getQueueMessageCount();
            echo "[CONSUME LOOP] Queue message count after publishing: {$initialMessageCount}\n";
        }
        
        if ($initialMessageCount === 0) {
            $this->markTestSkipped('Queue is empty');
            return;
        }
        
        echo "[CONSUME LOOP] Waiting 5 seconds for Web UI visibility...\n";
        sleep(5);
        
        // Step 2: Consume messages in a loop until queue is empty
        $consumedMessages = [];
        $maxIterations = 20; // Prevent infinite loop
        $iteration = 0;
        
        while ($iteration < $maxIterations) {
            $iteration++;
            
            // Check current message count
            $consumer->setup();
            $currentCount = $consumer->getQueueMessageCount();
            
            if ($currentCount === 0) {
                echo "[CONSUME LOOP] Queue is empty. Stopping consumption.\n";
                break;
            }
            
            echo "[CONSUME LOOP] Iteration {$iteration}: {$currentCount} messages remaining\n";
            
            // Consume one or more messages
            $consumedInThisIteration = 0;
            
            try {
                $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessages, &$consumedInThisIteration, $currentCount) {
                    $consumedInThisIteration++;
                    $consumedMessages[] = $msg->body;
                    
                    echo "[CONSUME LOOP] Consumed: {$msg->body}\n";
                    
                    $resolver->acknowledge($msg);
                    
                    // Stop after consuming current batch
                    if ($consumedInThisIteration >= $currentCount) {
                        $resolver->stopWhenProcessed();
                    }
                });
            } catch (Stop $e) {
                // Expected when stopWhenProcessed is called
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                // Timeout is OK - might mean queue is empty
                echo "[CONSUME LOOP] Timeout in iteration {$iteration}\n";
            }
            
            // Small delay between iterations
            usleep(500000); // 0.5 seconds
            
            // Close and reopen connection for next iteration
            \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        }
        
        // Final check
        $finalConsumer = new Consumer($this->configRepository);
        $finalConsumer->setup();
        $finalMessageCount = $finalConsumer->getQueueMessageCount();
        echo "[CONSUME LOOP] Final queue message count: {$finalMessageCount}\n";
        \Bschmitt\Amqp\Core\Request::shutdown($finalConsumer->getChannel(), $finalConsumer->getConnection());
        
        echo "[CONSUME LOOP] ============================================================\n";
        echo "[CONSUME LOOP] SUMMARY:\n";
        echo "[CONSUME LOOP] - Initial messages: {$initialMessageCount}\n";
        echo "[CONSUME LOOP] - Messages consumed: " . count($consumedMessages) . "\n";
        echo "[CONSUME LOOP] - Remaining messages: {$finalMessageCount}\n";
        echo "[CONSUME LOOP] ============================================================\n";
        
        $this->assertGreaterThan(0, count($consumedMessages), 'Should have consumed at least one message');
        $this->assertEquals(0, $finalMessageCount, 'Queue should be empty after consuming all messages');
        
        echo "[CONSUME LOOP] ✓ Test passed: All messages consumed in loop!\n";
    }
}

