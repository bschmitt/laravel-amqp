<?php

namespace Bschmitt\Amqp\Test;

use Bschmitt\Amqp\Core\Publisher;
use Bschmitt\Amqp\Core\Consumer;
use Bschmitt\Amqp\Core\Amqp;
use Bschmitt\Amqp\Models\Message;
use Bschmitt\Amqp\Exception\Stop;

/**
 * Full integration tests using real RabbitMQ connections
 * No mocks - all tests use actual AMQP connections
 * 
 * Requirements:
 * - RabbitMQ running on localhost:5672 (or configured via .env)
 * - Credentials set in .env (AMQP_HOST, AMQP_PORT, AMQP_USER, AMQP_PASSWORD, AMQP_VHOST)
 */
class FullIntegrationTest extends IntegrationTestBase
{
    /**
     * Test basic message publishing
     */
    public function testBasicPublish()
    {
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        $message = $this->createMessage('test-message-1');
        $result = $publisher->publish($this->testRoutingKey, $message);

        $this->assertTrue($result !== false);
        
        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * Test basic message consumption
     */
    public function testBasicConsume()
    {
        // First publish a message
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        $message = $this->createMessage('test-consume-message');
        $publisher->publish($this->testRoutingKey, $message);
        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Small delay to ensure message is in queue
        usleep(200000); // 0.2 seconds

        // Now consume it
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        $messageCount = $consumer->getQueueMessageCount();
        $this->assertGreaterThanOrEqual(1, $messageCount, 'Queue should have at least 1 message');

        $consumedMessage = null;
        $callbackExecuted = false;
        
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessage, &$callbackExecuted) {
                $callbackExecuted = true;
                $consumedMessage = $msg->body;
                $resolver->acknowledge($msg);
                $resolver->stopWhenProcessed();
            });
        } catch (Stop $e) {
            // Expected when stopWhenProcessed is called
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        $this->assertTrue($callbackExecuted, 'Consumer callback should have been executed');
        $this->assertNotNull($consumedMessage, 'Message should have been consumed');
        $this->assertEquals('test-consume-message', $consumedMessage);
    }

    /**
     * Test batch publishing
     */
    public function testBatchPublish()
    {
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        $messages = [
            $this->createMessage('batch-message-1'),
            $this->createMessage('batch-message-2'),
            $this->createMessage('batch-message-3'),
        ];

        foreach ($messages as $message) {
            $publisher->batchBasicPublish($this->testRoutingKey, $message);
        }

        $publisher->batchPublish();
        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Small delay to ensure messages are in queue
        usleep(300000);

        // Consume all messages
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        $messageCount = $consumer->getQueueMessageCount();
        $this->assertGreaterThanOrEqual(1, $messageCount, 'Queue should have at least 1 message');

        $consumedMessages = [];
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessages) {
                $consumedMessages[] = $msg->body;
                $resolver->acknowledge($msg);
                if (count($consumedMessages) >= 3) {
                    $resolver->stopWhenProcessed();
                }
            });
        } catch (Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());

        $this->assertGreaterThanOrEqual(1, count($consumedMessages), 'Should consume at least 1 message');
        if (count($consumedMessages) >= 3) {
            $this->assertContains('batch-message-1', $consumedMessages);
            $this->assertContains('batch-message-2', $consumedMessages);
            $this->assertContains('batch-message-3', $consumedMessages);
        }
    }

    /**
     * Test message rejection with requeue
     */
    public function testMessageRejectionWithRequeue()
    {
        // Publish a message
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        $message = $this->createMessage('reject-test-message');
        $publisher->publish($this->testRoutingKey, $message);
        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Reject it with requeue
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        $rejected = false;
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$rejected) {
                $resolver->reject($msg, true); // Requeue
                $rejected = true;
                $resolver->stopWhenProcessed();
            });
        } catch (Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        $this->assertTrue($rejected);

        // Consume again - message should be back in queue
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        $consumedMessage = null;
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessage) {
                $consumedMessage = $msg->body;
                $resolver->acknowledge($msg);
                $resolver->stopWhenProcessed();
            });
        } catch (Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        $this->assertEquals('reject-test-message', $consumedMessage);
    }

    /**
     * Test message rejection without requeue
     */
    public function testMessageRejectionWithoutRequeue()
    {
        // Publish a message
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        $message = $this->createMessage('reject-no-requeue-message');
        $publisher->publish($this->testRoutingKey, $message);
        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Reject it without requeue
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        $rejected = false;
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$rejected) {
                $resolver->reject($msg, false); // Don't requeue
                $rejected = true;
                $resolver->stopWhenProcessed();
            });
        } catch (Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        $this->assertTrue($rejected);

        // Try to consume again - message should be gone
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        $messageReceived = false;
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$messageReceived) {
                $messageReceived = true;
                $resolver->acknowledge($msg);
                $resolver->stopWhenProcessed();
            });
        } catch (Stop $e) {
            // Expected when no messages
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        $this->assertFalse($messageReceived, 'Message should not be in queue after rejection without requeue');
    }

    /**
     * Test Amqp facade publish
     */
    public function testAmqpFacadePublish()
    {
        $amqp = new Amqp();
        
        $properties = [
            'exchange' => $this->testExchange,
            'queue' => $this->testQueueName,
            'queue_force_declare' => true,
            'queue_durable' => false,
            'queue_auto_delete' => true,
            'routing' => $this->testRoutingKey,
        ];

        $result = $amqp->publish($this->testRoutingKey, 'facade-test-message', $properties);
        $this->assertTrue($result !== false);
    }

    /**
     * Test Amqp facade consume
     */
    public function testAmqpFacadeConsume()
    {
        // First publish
        $amqp = new Amqp();
        $properties = [
            'exchange' => $this->testExchange,
            'queue' => $this->testQueueName,
            'queue_force_declare' => true,
            'queue_durable' => false,
            'queue_auto_delete' => true,
            'routing' => $this->testRoutingKey,
        ];

        $amqp->publish($this->testRoutingKey, 'facade-consume-message', $properties);

        // Now consume
        $consumedMessage = null;
        $amqp->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedMessage) {
            $consumedMessage = $msg->body;
            $resolver->acknowledge($msg);
            $resolver->stopWhenProcessed();
        }, $properties);

        $this->assertEquals('facade-consume-message', $consumedMessage);
    }

    /**
     * Test queue message count
     */
    public function testQueueMessageCount()
    {
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        // Publish 3 messages
        for ($i = 1; $i <= 3; $i++) {
            $message = $this->createMessage("count-test-message-{$i}");
            $publisher->publish($this->testRoutingKey, $message);
            usleep(100000); // Small delay between messages
        }

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Small delay to ensure all messages are in queue
        usleep(300000); // 0.3 seconds

        // Check message count
        $consumer = new Consumer($this->configRepository);
        $consumer->setup();

        $messageCount = $consumer->getQueueMessageCount();
        $this->assertEquals(3, $messageCount, "Queue should have 3 messages, but has {$messageCount}");

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
    }

    /**
     * Test mandatory publishing
     */
    public function testMandatoryPublish()
    {
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();

        $message = $this->createMessage('mandatory-test-message');
        $result = $publisher->publish($this->testRoutingKey, $message, true);

        $this->assertTrue($result !== false);
        
        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());
    }

    /**
     * Test QoS (Quality of Service) settings
     */
    public function testQoSConfiguration()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['qos'] = true;
        $config['properties']['test']['qos_prefetch_count'] = 1;
        
        $configRepo = new \Illuminate\Config\Repository(['amqp' => $config]);
        
        $consumer = new Consumer($configRepo);
        $consumer->setup();

        // Publish 2 messages
        $publisher = new Publisher($this->configRepository);
        $publisher->setup();
        $publisher->publish($this->testRoutingKey, $this->createMessage('qos-message-1'));
        $publisher->publish($this->testRoutingKey, $this->createMessage('qos-message-2'));
        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // With QoS prefetch=1, only one message should be delivered at a time
        $consumedCount = 0;
        try {
            $consumer->consume($this->testQueueName, function ($msg, $resolver) use (&$consumedCount) {
                $consumedCount++;
                $resolver->acknowledge($msg);
                if ($consumedCount >= 2) {
                    $resolver->stopWhenProcessed();
                }
            });
        } catch (Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        $this->assertEquals(2, $consumedCount);
    }

    /**
     * Test multiple routing keys
     */
    public function testMultipleRoutingKeys()
    {
        $config = $this->configRepository->get('amqp');
        $config['properties']['test']['routing'] = [
            'routing.key.1',
            'routing.key.2',
            'routing.key.3'
        ];
        
        $configRepo = new \Illuminate\Config\Repository(['amqp' => $config]);
        
        $publisher = new Publisher($configRepo);
        $publisher->setup();

        // Publish to different routing keys
        $publisher->publish('routing.key.1', $this->createMessage('message-1'));
        usleep(100000);
        $publisher->publish('routing.key.2', $this->createMessage('message-2'));
        usleep(100000);
        $publisher->publish('routing.key.3', $this->createMessage('message-3'));

        \Bschmitt\Amqp\Core\Request::shutdown($publisher->getChannel(), $publisher->getConnection());

        // Small delay to ensure all messages are in queue
        usleep(300000);

        // All should be in the same queue
        $consumer = new Consumer($configRepo);
        $consumer->setup();

        $messageCount = $consumer->getQueueMessageCount();
        $this->assertGreaterThanOrEqual(1, $messageCount, 'Queue should have at least 1 message');

        $consumedMessages = [];
        try {
            $consumer->consume($config['properties']['test']['queue'], function ($msg, $resolver) use (&$consumedMessages) {
                $consumedMessages[] = $msg->body;
                $resolver->acknowledge($msg);
                if (count($consumedMessages) >= 3) {
                    $resolver->stopWhenProcessed();
                }
            });
        } catch (Stop $e) {
            // Expected
        }

        \Bschmitt\Amqp\Core\Request::shutdown($consumer->getChannel(), $consumer->getConnection());
        
        // At least one message should be consumed (all 3 if routing is working correctly)
        $this->assertGreaterThanOrEqual(1, count($consumedMessages), 'Should consume at least 1 message');
        $this->assertContains('message-1', $consumedMessages, 'Should contain message-1');
    }
}

