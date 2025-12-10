<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Consumer;
use Bschmitt\Amqp\Exception\Stop;

/**
 * Helper class to consume all messages from a queue
 * 
 * Usage:
 *   $helper = new ConsumeQueueHelper($configRepository);
 *   $helper->consumeAllFromQueue('your-queue-name');
 */
class ConsumeQueueHelper
{
    protected $configRepository;
    
    public function __construct($configRepository)
    {
        $this->configRepository = $configRepository;
    }
    
    /**
     * Consume all messages from a specific queue
     * 
     * @param string $queueName The name of the queue to consume from
     * @return array Statistics about consumption
     */
    public function consumeAllFromQueue(string $queueName): array
    {
        echo "\n[CONSUME HELPER] Consuming all messages from queue: {$queueName}\n";
        
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();
        
        $initialCount = $consumer->getQueueMessageCount();
        echo "[CONSUME HELPER] Initial message count: {$initialCount}\n";
        
        if ($initialCount === 0) {
            \Bschmitt\Amqp\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
            return [
                'initial_count' => 0,
                'consumed' => 0,
                'remaining' => 0,
                'messages' => []
            ];
        }
        
        $consumedMessages = [];
        $maxIterations = 200;
        $iteration = 0;
        
        while ($iteration < $maxIterations) {
            $iteration++;
            
            // Refresh to get current count
            $consumer->setup();
            $currentCount = $consumer->getQueueMessageCount();
            
            if ($currentCount === 0) {
                echo "[CONSUME HELPER] ✓ Queue is now empty!\n";
                break;
            }
            
            if ($iteration === 1 || $iteration % 10 === 0) {
                echo "[CONSUME HELPER] Iteration {$iteration}: {$currentCount} messages remaining\n";
            }
            
            // Consume batch
            $consumedInIteration = 0;
            $batchSize = min($currentCount, 50);
            
            try {
                $consumer->consume($queueName, function ($msg, $resolver) use (&$consumedMessages, &$consumedInIteration, $batchSize) {
                    $consumedInIteration++;
                    $consumedMessages[] = $msg->body;
                    
                    $resolver->acknowledge($msg);
                    
                    if ($consumedInIteration >= $batchSize) {
                        $resolver->stopWhenProcessed();
                    }
                });
            } catch (Stop $e) {
                // Expected
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                // Timeout is OK
            }
            
            \Bschmitt\Amqp\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
            usleep(200000);
        }
        
        // Final count
        $finalConsumer = new Consumer($this->configRepository);
        $finalConsumer->setup();
        $finalCount = $finalConsumer->getQueueMessageCount();
        \Bschmitt\Amqp\Request::shutdown($finalConsumer->getChannel(), $finalConsumer->getConnection());
        
        $totalConsumed = count($consumedMessages);
        
        echo "[CONSUME HELPER] ============================================================\n";
        echo "[CONSUME HELPER] SUMMARY:\n";
        echo "[CONSUME HELPER] - Initial messages: {$initialCount}\n";
        echo "[CONSUME HELPER] - Messages consumed: {$totalConsumed}\n";
        echo "[CONSUME HELPER] - Remaining messages: {$finalCount}\n";
        echo "[CONSUME HELPER] ============================================================\n";
        
        return [
            'initial_count' => $initialCount,
            'consumed' => $totalConsumed,
            'remaining' => $finalCount,
            'messages' => $consumedMessages
        ];
    }
}

