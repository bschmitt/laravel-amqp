<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Exception\Stop;
use Bschmitt\Amqp\Test\Support\ConsumeQueueHelper;
use Bschmitt\Amqp\Test\Support\IntegrationTestBase;

/**
 * Test to consume ALL existing messages from a specific queue
 * 
 * This test will consume all messages from the queue specified in the config,
 * regardless of how many there are. Use this to clear out messages that
 * have accumulated in your queue.
 * 
 * IMPORTANT: To consume from YOUR actual queue (not the test queue), you need to:
 * 1. Update the queue name in your .env or config to point to your actual queue
 * 2. Or modify this test to use your queue name directly
 * 
 * Usage: Run this test to consume all messages from your queue
 */
class ConsumeExistingQueueMessagesTest extends IntegrationTestBase
{
    /**
     * Test: Consume all existing messages from the queue
     * This will consume ALL messages currently in the queue
     */
    public function testConsumeAllExistingMessages()
    {
        echo "\n[CONSUME EXISTING] Test: Consume All Existing Messages From Queue\n";
        echo "[CONSUME EXISTING] ============================================================\n";
        
        // Step 1: Check current queue status
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $initialMessageCount = $consumer->getQueueMessageCount();
        echo "[CONSUME EXISTING] Queue name: {$this->testQueueName}\n";
        echo "[CONSUME EXISTING] Exchange: {$this->testExchange}\n";
        echo "[CONSUME EXISTING] Initial queue message count: {$initialMessageCount}\n";
        echo "[CONSUME EXISTING] ============================================================\n";
        
        if ($initialMessageCount === 0) {
            echo "[CONSUME EXISTING] ⚠ Queue is empty. Nothing to consume.\n";
            \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
            $this->markTestSkipped('Queue is empty - no messages to consume');
            return;
        }
        
        echo "[CONSUME EXISTING] Found {$initialMessageCount} messages in queue.\n";
        echo "[CONSUME EXISTING] Waiting 3 seconds for Web UI visibility...\n";
        echo "[CONSUME EXISTING] Check http://localhost:15672 -> Queues -> {$this->testQueueName}\n";
        sleep(3);
        
        // Step 2: Consume ALL messages using iterative approach
        $consumedMessages = [];
        $maxIterations = 100; // Prevent infinite loop
        $iteration = 0;
        
        echo "[CONSUME EXISTING] Starting to consume all {$initialMessageCount} messages...\n";
        echo "[CONSUME EXISTING] This may take a few moments...\n";
        
        while ($iteration < $maxIterations) {
            $iteration++;
            
            // Refresh consumer to get current message count
            $consumer->setup();
            $currentCount = $consumer->getQueueMessageCount();
            
            if ($currentCount === 0) {
                echo "[CONSUME EXISTING] ✓ Queue is now empty! All messages consumed.\n";
                break;
            }
            
            if ($iteration === 1 || $iteration % 5 === 0) {
                echo "[CONSUME EXISTING] Iteration {$iteration}: {$currentCount} messages remaining\n";
            }
            
            // Consume messages in batches
            $consumedInIteration = 0;
            $batchSize = min($currentCount, 20); // Consume up to 20 at a time
            
            try {
                $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessages, &$consumedInIteration, $batchSize) {
                    $consumedInIteration++;
                    $consumedMessages[] = [
                        'body' => $msg->body,
                        'delivery_tag' => $msg->getDeliveryTag(),
                        'timestamp' => time()
                    ];
                    
                    // Acknowledge the message
                    $resolver->acknowledge($msg);
                    
                    // Stop after consuming batch
                    if ($consumedInIteration >= $batchSize) {
                        $resolver->stopWhenProcessed();
                    }
                });
            } catch (Stop $e) {
                // Expected when stopWhenProcessed is called
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                // Timeout - might mean queue is empty
                if ($currentCount > 0) {
                    echo "[CONSUME EXISTING] Timeout in iteration {$iteration} (but {$currentCount} messages still remain)\n";
                }
            }
            
            // Close connection for next iteration
            \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
            
            // Small delay between iterations to allow RabbitMQ to process
            usleep(200000); // 0.2 seconds
        }
        
        // Step 3: Final verification
        $finalConsumer = new Consumer($this->configRepository);
        $finalConsumer->setup();
        $finalMessageCount = $finalConsumer->getQueueMessageCount();
        \Bschmitt\Amqp\Core\Request::shutdown($finalConsumer->getChannel(), $finalConsumer->getConnection());
        
        $totalConsumed = count($consumedMessages);
        
        // Step 4: Report results
        echo "[CONSUME EXISTING] ============================================================\n";
        echo "[CONSUME EXISTING] CONSUMPTION SUMMARY:\n";
        echo "[CONSUME EXISTING] - Initial messages in queue: {$initialMessageCount}\n";
        echo "[CONSUME EXISTING] - Messages consumed: {$totalConsumed}\n";
        echo "[CONSUME EXISTING] - Remaining messages: {$finalMessageCount}\n";
        echo "[CONSUME EXISTING] - Iterations performed: {$iteration}\n";
        echo "[CONSUME EXISTING] ============================================================\n";
        
        if ($totalConsumed > 0) {
            echo "[CONSUME EXISTING] Sample of consumed messages:\n";
            $sampleSize = min(5, $totalConsumed);
            for ($i = 0; $i < $sampleSize; $i++) {
                $msg = $consumedMessages[$i];
                $preview = strlen($msg['body']) > 50 ? substr($msg['body'], 0, 50) . '...' : $msg['body'];
                echo "[CONSUME EXISTING]   - Message " . ($i + 1) . ": {$preview}\n";
            }
            if ($totalConsumed > $sampleSize) {
                echo "[CONSUME EXISTING]   ... and " . ($totalConsumed - $sampleSize) . " more messages\n";
            }
        }
        
        echo "[CONSUME EXISTING] ============================================================\n";
        
        // Assertions
        $this->assertGreaterThan(0, $totalConsumed, 'Should have consumed at least one message');
        
        if ($initialMessageCount <= 100) {
            // Only assert exact match if queue wasn't too large (to account for any race conditions)
            $this->assertEquals($initialMessageCount, $totalConsumed, "Should have consumed all {$initialMessageCount} messages");
        }
        
        $this->assertEquals(0, $finalMessageCount, 'Queue should be empty after consuming all messages');
        
        echo "[CONSUME EXISTING] ✓ Test passed: All messages consumed successfully!\n";
        echo "[CONSUME EXISTING] Check RabbitMQ Web UI to verify queue is empty.\n";
    }

    /**
     * Test: Consume from a specific queue name (useful for consuming from your actual queue)
     * 
     * To use this test with YOUR queue:
     * 1. Check RabbitMQ Web UI to find your queue name
     * 2. Update the $queueName variable below to match your queue
     * 3. Run this test
     */
    public function testConsumeFromSpecificQueue()
    {
        // TODO: Update this to your actual queue name
        $queueName = 'test-queue-integration';
        
        echo "\n[CONSUME SPECIFIC] Test: Consume From Specific Queue\n";
        echo "[CONSUME SPECIFIC] Queue name: {$queueName}\n";
        echo "[CONSUME SPECIFIC] ============================================================\n";
        
        // Use the helper to consume all messages
        $helper = new ConsumeQueueHelper($this->configRepository);
        $result = $helper->consumeAllFromQueue($queueName);
        
        echo "[CONSUME SPECIFIC] ============================================================\n";
        echo "[CONSUME SPECIFIC] Result:\n";
        echo "[CONSUME SPECIFIC] - Initial messages: {$result['initial_count']}\n";
        echo "[CONSUME SPECIFIC] - Consumed: {$result['consumed']}\n";
        echo "[CONSUME SPECIFIC] - Remaining: {$result['remaining']}\n";
        echo "[CONSUME SPECIFIC] ============================================================\n";
        
        if ($result['initial_count'] === 0) {
            $this->markTestSkipped("Queue '{$queueName}' is empty or doesn't exist. Update \$queueName to your actual queue name.");
            return;
        }
        
        $this->assertGreaterThan(0, $result['consumed'], 'Should have consumed at least one message');
        $this->assertEquals(0, $result['remaining'], 'Queue should be empty after consuming all messages');
        
        echo "[CONSUME SPECIFIC] ✓ Test passed!\n";
    }
}

