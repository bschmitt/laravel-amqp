const { createApp } = Vue;

createApp({
  data() {
    return {
      currentPage: "home",
      activeTab: "install",
      searchQuery: "",
      isDark: false,
      mobileMenuOpen: false,
      documentation: {},
      searchOpen: false,
      searchResults: [],
      selectedResultIndex: 0,
      contributors: [],
      loadingContributors: true,
    };
  },
  computed: {
    currentContent() {
      const page = this.currentPage;

      if (this.documentation[page]) {
        return marked.parse(this.documentation[page]);
      }

      return "<h1>Page Not Found</h1><p>The requested page could not be found.</p>";
    },
    filteredSearchResults() {
      if (!this.searchQuery.trim()) {
        return [];
      }

      const query = this.searchQuery.toLowerCase();
      const results = [];

      // Search through all documentation
      Object.keys(this.documentation).forEach((pageKey) => {
        const content = this.documentation[pageKey];
        const lines = content.split("\n");

        // Search in headings and content
        lines.forEach((line, index) => {
          if (line.toLowerCase().includes(query)) {
            // Extract heading or context
            let title = pageKey.replace(/-/g, " ");
            title = title.charAt(0).toUpperCase() + title.slice(1);

            let snippet = line.trim();
            // If it's a heading, use it as title
            if (line.startsWith("#")) {
              snippet = line.replace(/^#+\s*/, "");
            }

            // Limit snippet length
            if (snippet.length > 100) {
              snippet = snippet.substring(0, 100) + "...";
            }

            results.push({
              page: pageKey,
              title: title,
              snippet: snippet,
              line: index,
            });
          }
        });
      });

      return results.slice(0, 10); // Limit to 10 results
    },
  },
  watch: {
    currentPage() {
      // Scroll to top when page changes
      window.scrollTo({ top: 0, behavior: "smooth" });

      // Re-highlight code blocks
      this.$nextTick(() => {
        Prism.highlightAll();
      });
    },
    activeTab() {
      this.$nextTick(() => {
        Prism.highlightAll();
      });
    },
    searchQuery() {
      this.selectedResultIndex = 0;
    },
  },
  mounted() {
    try {
      // Load documentation content
      this.loadDocumentation();

      // Check for dark mode preference
      const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
      this.isDark =
        localStorage.getItem("theme") === "dark" ||
        (prefersDark && !localStorage.getItem("theme"));
      this.applyTheme();

      // Highlight code blocks
      if (typeof Prism !== "undefined") {
        Prism.highlightAll();
      }

      // Handle hash navigation
      if (window.location.hash) {
        const page = window.location.hash.substring(1);
        if (page && page !== "home" && this.documentation[page]) {
          this.currentPage = page;
        }
      }

      // Add keyboard shortcuts
      document.addEventListener("keydown", this.handleKeyboard);

      // Fetch contributors
      this.fetchContributors();
    } catch (error) {
      console.error("Error in mounted hook:", error);
      // Ensure we still try to fetch contributors even if something else fails
      this.fetchContributors();
    }
  },
  beforeUnmount() {
    document.removeEventListener("keydown", this.handleKeyboard);
  },
  methods: {
    handleKeyboard(e) {
      // Ctrl+K or Cmd+K to open search
      if ((e.ctrlKey || e.metaKey) && e.key === "k") {
        e.preventDefault();
        this.toggleSearch();
        return;
      }

      // Escape to close search
      if (e.key === "Escape" && this.searchOpen) {
        e.preventDefault();
        this.closeSearch();
        return;
      }

      // Arrow keys to navigate results
      if (this.searchOpen && this.filteredSearchResults.length > 0) {
        if (e.key === "ArrowDown") {
          e.preventDefault();
          this.selectedResultIndex = Math.min(
            this.selectedResultIndex + 1,
            this.filteredSearchResults.length - 1
          );
        } else if (e.key === "ArrowUp") {
          e.preventDefault();
          this.selectedResultIndex = Math.max(this.selectedResultIndex - 1, 0);
        } else if (e.key === "Enter") {
          e.preventDefault();
          if (this.filteredSearchResults[this.selectedResultIndex]) {
            this.selectResult(this.filteredSearchResults[this.selectedResultIndex]);
          }
        }
      }
    },
    toggleSearch() {
      this.searchOpen = !this.searchOpen;
      if (this.searchOpen) {
        this.$nextTick(() => {
          const input = document.querySelector(".command-palette-input");
          if (input) input.focus();
        });
      } else {
        this.searchQuery = "";
      }
    },
    closeSearch() {
      this.searchOpen = false;
      this.searchQuery = "";
      this.selectedResultIndex = 0;
    },
    selectResult(result) {
      this.currentPage = result.page;
      this.closeSearch();
    },
    toggleTheme() {
      this.isDark = !this.isDark;
      this.applyTheme();
      localStorage.setItem("theme", this.isDark ? "dark" : "light");
    },
    applyTheme() {
      if (this.isDark) {
        document.documentElement.setAttribute("data-theme", "dark");
      } else {
        document.documentElement.removeAttribute("data-theme");
      }
    },
    async fetchContributors() {
      try {
        const response = await fetch(
          "https://api.github.com/repos/bschmitt/laravel-amqp/contributors?per_page=3"
        );
        if (response.ok) {
          const data = await response.json();
          this.contributors = data.map((c) => ({
            login: c.login,
            avatar_url: c.avatar_url,
            html_url: c.html_url,
            contributions: c.contributions,
          }));
        } else {
          throw new Error("Failed to fetch");
        }
      } catch (error) {
        console.error("Failed to fetch contributors:", error);
        // Fallback to static data
        this.contributors = [
          {
            login: "bschmitt",
            avatar_url: "https://avatars.githubusercontent.com/u/239644?v=4",
            html_url: "https://github.com/bschmitt",
            contributions: 55,
          },
          {
            login: "zfhassaan",
            avatar_url: "https://avatars.githubusercontent.com/u/17079656?v=4",
            html_url: "https://github.com/zfhassaan",
            contributions: 53,
          },
          {
            login: "petekelly",
            avatar_url: "https://avatars.githubusercontent.com/u/1177933?v=4",
            html_url: "https://github.com/petekelly",
            contributions: 6,
          },
        ];
      } finally {
        this.loadingContributors = false;
      }
    },
    loadDocumentation() {
      // Getting Started
      this.documentation[
        "getting-started"
      ] = `# Getting Started with Laravel AMQP

## Quick Start

### 1. Installation

\`\`\`bash
composer require bschmitt/laravel-amqp
\`\`\`

### 2. Publish Configuration

\`\`\`bash
php artisan vendor:publish --provider="Bschmitt\\\\Amqp\\\\Providers\\\\AmqpServiceProvider"
\`\`\`

### 3. Configure Environment

Add to your \`.env\`:

\`\`\`env
AMQP_HOST=localhost
AMQP_PORT=5672
AMQP_USER=guest
AMQP_PASSWORD=guest
AMQP_VHOST=/
AMQP_EXCHANGE=amq.topic
AMQP_EXCHANGE_TYPE=topic
\`\`\`

### 4. Basic Usage

#### Publish a Message

\`\`\`php
use Bschmitt\\Amqp\\Facades\\Amqp;

Amqp::publish('routing.key', 'Hello World');
\`\`\`

#### Consume Messages

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    echo $message->body;
    $resolver->acknowledge($message);
    $resolver->stopWhenProcessed();
});
\`\`\`

That's it! You're ready to use Laravel AMQP.

## Next Steps

- [Configuration](#configuration)
- [Publishing Messages](#publishing)
- [Consuming Messages](#consuming)
- [RPC Pattern](#rpc)`;

      // Installation
      this.documentation["installation"] = `# Installation

## Requirements

- PHP 7.3+ or PHP 8.0+
- Laravel 6.20+ / Lumen 6.20+
- RabbitMQ 3.x server
- php-amqplib/php-amqplib ^3.0

## Composer Installation

\`\`\`bash
composer require bschmitt/laravel-amqp
\`\`\`

## Laravel Setup

The package will auto-register its service provider. Publish the configuration file:

\`\`\`bash
php artisan vendor:publish --provider="Bschmitt\\\\Amqp\\\\Providers\\\\AmqpServiceProvider"
\`\`\`

## Lumen Setup

For Lumen, register the service provider in \`bootstrap/app.php\`:

\`\`\`php
$app->register(Bschmitt\\Amqp\\Providers\\LumenServiceProvider::class);
\`\`\`

Then copy the config file manually:

\`\`\`bash
cp vendor/bschmitt/laravel-amqp/config/amqp.php config/amqp.php
\`\`\`

## Verify Installation

Test your connection:

\`\`\`php
use Bschmitt\\Amqp\\Facades\\Amqp;

try {
    Amqp::publish('test', 'test');
    echo "Connection successful";
} catch (\\Exception $e) {
    echo "Connection failed: " . $e->getMessage();
}
\`\`\``;

      // Configuration
      this.documentation["configuration"] = `# Configuration

## Basic Configuration

Edit \`config/amqp.php\`:

\`\`\`php
return [
    'use' => env('AMQP_ENV', 'production'),

    'properties' => [
        'production' => [
            'host' => env('AMQP_HOST', 'localhost'),
            'port' => env('AMQP_PORT', 5672),
            'username' => env('AMQP_USER', ''),
            'password' => env('AMQP_PASSWORD', ''),
            'vhost' => env('AMQP_VHOST', '/'),
            'exchange' => env('AMQP_EXCHANGE', 'amq.topic'),
            'exchange_type' => env('AMQP_EXCHANGE_TYPE', 'topic'),
            'exchange_durable' => true,
            'queue_durable' => true,
            'queue_auto_delete' => false,
        ],
    ],
];
\`\`\`

## Environment Variables

Add to your \`.env\` file:

\`\`\`env
AMQP_HOST=localhost
AMQP_PORT=5672
AMQP_USER=guest
AMQP_PASSWORD=guest
AMQP_VHOST=/
AMQP_EXCHANGE=amq.topic
AMQP_EXCHANGE_TYPE=topic
\`\`\`

## Management API Configuration

To use Management API features, add:

\`\`\`php
'properties' => [
    'production' => [
        // ... existing config ...
        'management_api_url' => env('AMQP_MANAGEMENT_URL', 'http://localhost:15672'),
        'management_api_user' => env('AMQP_MANAGEMENT_USER', 'guest'),
        'management_api_password' => env('AMQP_MANAGEMENT_PASSWORD', 'guest'),
    ],
],
\`\`\`

## Multiple Environments

You can configure multiple environments:

\`\`\`php
'properties' => [
    'production' => [
        'host' => 'prod-rabbitmq.example.com',
        // ...
    ],
    'staging' => [
        'host' => 'staging-rabbitmq.example.com',
        // ...
    ],
],
\`\`\`

Then switch using:

\`\`\`env
AMQP_ENV=staging
\`\`\``;

      // Publishing
      this.documentation["publishing"] = `# Publishing Messages

## Simple Publishing

\`\`\`php
use Bschmitt\\Amqp\\Facades\\Amqp;

// Publish to default exchange and routing key
Amqp::publish('routing.key', 'Hello World');
\`\`\`

## Publish with Custom Properties

\`\`\`php
Amqp::publish('routing.key', 'Message', [
    'exchange' => 'my-exchange',
    'exchange_type' => 'direct',
    'queue' => 'my-queue',
]);
\`\`\`

## Publish with Message Properties

\`\`\`php
Amqp::publish('routing.key', 'Message', [
    'priority' => 10,
    'correlation_id' => 'unique-id',
    'reply_to' => 'reply-queue',
    'application_headers' => [
        'X-Custom-Header' => 'value'
    ],
]);
\`\`\`

## Publish JSON Data

\`\`\`php
$data = ['user_id' => 123, 'action' => 'login'];

Amqp::publish('user.events', json_encode($data), [
    'content_type' => 'application/json',
]);
\`\`\`

## Exchange Types

### Topic Exchange

\`\`\`php
Amqp::publish('user.created', 'message', [
    'exchange' => 'events',
    'exchange_type' => 'topic',
]);
\`\`\`

### Direct Exchange

\`\`\`php
Amqp::publish('high-priority', 'message', [
    'exchange' => 'tasks',
    'exchange_type' => 'direct',
]);
\`\`\`

### Fanout Exchange

\`\`\`php
Amqp::publish('', 'broadcast message', [
    'exchange' => 'amq.fanout',
    'exchange_type' => 'fanout',
]);
\`\`\`

## Persistent Messages

\`\`\`php
Amqp::publish('routing.key', 'important message', [
    'delivery_mode' => 2, // Persistent
    'queue_durable' => true,
]);
\`\`\`

## Message TTL

\`\`\`php
Amqp::publish('routing.key', 'temporary message', [
    'expiration' => '60000', // 60 seconds in milliseconds
]);
\`\`\``;

      // Consuming
      this.documentation["consuming"] = `# Consuming Messages

## Basic Consume

\`\`\`php
use Bschmitt\\Amqp\\Facades\\Amqp;

$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    // Process message
    $data = $message->body;
    
    // Acknowledge message
    $resolver->acknowledge($message);
    
    // Stop consuming after processing
    $resolver->stopWhenProcessed();
});
\`\`\`

## Consume with Options

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    // Process message
    $resolver->acknowledge($message);
}, [
    'exchange' => 'my-exchange',
    'exchange_type' => 'direct',
    'routing' => ['routing.key'],
    'timeout' => 60,
    'message_limit' => 100,
]);
\`\`\`

## Rejecting Messages

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    try {
        // Process message
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\\Exception $e) {
        // Reject and requeue
        $resolver->reject($message, true);
    }
});
\`\`\`

## Error Handling

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    try {
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\\Exception $e) {
        \\Log::error('Message processing failed', [
            'error' => $e->getMessage(),
            'message' => $message->body,
        ]);
        
        // Reject without requeue (send to DLQ)
        $resolver->reject($message, false);
    }
});
\`\`\`

## Consumer Prefetch (QoS)

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    // Process message
}, [
    'qos_prefetch_count' => 10,  // Max 10 unacked messages
    'qos_prefetch_size' => 0,    // No size limit
    'qos_a_global' => false,     // Per consumer
]);
\`\`\`

## Listen to Multiple Routing Keys

\`\`\`php
$amqp = app('Amqp');
$amqp->listen(['key1', 'key2', 'key3'], function ($message, $resolver) {
    // Handle message from any of the routing keys
    $resolver->acknowledge($message);
}, [
    'exchange' => 'my-exchange',
    'exchange_type' => 'topic',
]);
\`\`\``;

      // RPC Pattern
      this.documentation["rpc"] = `# RPC Pattern

## Overview

The RPC (Request-Response) pattern allows you to make synchronous-like calls over message queues.

## Making RPC Calls

### Simple RPC Call

\`\`\`php
use Bschmitt\\Amqp\\Facades\\Amqp;

$amqp = app('Amqp');
$response = $amqp->rpc('rpc-queue', 'request-data', [], 30);

if ($response !== null) {
    echo "Response: " . $response;
} else {
    echo "Timeout or no response";
}
\`\`\`

### RPC with JSON Data

\`\`\`php
$request = ['action' => 'getUser', 'id' => 123];
$amqp = app('Amqp');
$response = $amqp->rpc('rpc-queue', json_encode($request), [
    'content_type' => 'application/json',
], 30);

$result = json_decode($response, true);
\`\`\`

## Creating RPC Servers

### Basic RPC Server

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('rpc-queue', function ($message, $resolver) {
    // Get request
    $request = $message->body;
    
    // Process request
    $result = processRequest($request);
    
    // Send reply
    $resolver->reply($message, $result);
    
    // Acknowledge original request
    $resolver->acknowledge($message);
});
\`\`\`

### RPC Server with Error Handling

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('rpc-queue', function ($message, $resolver) {
    try {
        $request = json_decode($message->body, true);
        $result = processRequest($request);
        
        $resolver->reply($message, json_encode([
            'success' => true,
            'data' => $result
        ]));
    } catch (\\Exception $e) {
        $resolver->reply($message, json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]));
    }
    
    $resolver->acknowledge($message);
});
\`\`\`

## Production RPC Server

\`\`\`php
// app/Console/Commands/RpcServer.php
namespace App\\Console\\Commands;

use Illuminate\\Console\\Command;
use Bschmitt\\Amqp\\Facades\\Amqp;

class RpcServer extends Command
{
    protected $signature = 'amqp:rpc-server {queue}';
    protected $description = 'Run RPC server';

    public function handle()
    {
        $queue = $this->argument('queue');
        $this->info("Starting RPC server for queue: {$queue}");
        
        $amqp = app('Amqp');
        $amqp->consume($queue, function ($message, $resolver) {
            try {
                $request = json_decode($message->body, true);
                $result = $this->processRequest($request);
                
                $resolver->reply($message, json_encode($result));
                $resolver->acknowledge($message);
            } catch (\\Exception $e) {
                \\Log::error('RPC processing error', [
                    'error' => $e->getMessage(),
                    'request' => $message->body
                ]);
                
                $resolver->reply($message, json_encode([
                    'error' => $e->getMessage()
                ]));
                $resolver->acknowledge($message);
            }
        });
    }
    
    private function processRequest($request)
    {
        // Your processing logic
        return ['result' => 'processed'];
    }
}
\`\`\`

## Best Practices

### 1. Always Handle Timeouts

\`\`\`php
$amqp = app('Amqp');
$response = $amqp->rpc('queue', 'request', [], 30);

if ($response === null) {
    // Handle timeout
    return ['error' => 'Service unavailable'];
}
\`\`\`

### 2. Use Appropriate Timeouts

\`\`\`php
// Quick operations: 5-10 seconds
$response = $amqp->rpc('quick-queue', 'request', [], 5);

// Database operations: 10-30 seconds
$response = $amqp->rpc('db-queue', 'request', [], 30);

// Long operations: 60+ seconds
$response = $amqp->rpc('long-queue', 'request', [], 120);
\`\`\``;

      // Queue Management
      this.documentation["queue-management"] = `# Queue Management

## Queue Operations

### Purge Queue

Remove all messages from a queue without deleting it.

\`\`\`php
use Bschmitt\\Amqp\\Facades\\Amqp;

$amqp = app('Amqp');
$amqp->queuePurge('my-queue', [
    'queue' => 'my-queue'
]);
\`\`\`

### Delete Queue

Delete a queue completely.

\`\`\`php
// Delete queue (only if unused and empty)
$amqp = app('Amqp');
$amqp->queueDelete('my-queue', [
    'queue' => 'my-queue'
], false, false);

// Force delete (even if not empty)
$amqp->queueDelete('my-queue', [
    'queue' => 'my-queue'
], false, false);
\`\`\`

### Unbind Queue

Remove binding between a queue and an exchange.

\`\`\`php
$amqp = app('Amqp');
$amqp->queueUnbind('my-queue', 'my-exchange', 'routing-key', null, [
    'queue' => 'my-queue',
    'exchange' => 'my-exchange'
]);
\`\`\`

## Exchange Operations

### Delete Exchange

\`\`\`php
$amqp = app('Amqp');
$amqp->exchangeDelete('my-exchange', [
    'exchange' => 'my-exchange'
], false);
\`\`\`

### Unbind Exchange

\`\`\`php
$amqp = app('Amqp');
$amqp->exchangeUnbind('destination-exchange', 'source-exchange', 'routing-key', null, [
    'exchange' => 'destination-exchange'
]);
\`\`\`

## Practical Examples

### Cleanup Script

\`\`\`php
// app/Console/Commands/CleanupQueues.php
namespace App\\Console\\Commands;

use Illuminate\\Console\\Command;
use Bschmitt\\Amqp\\Facades\\Amqp;

class CleanupQueues extends Command
{
    protected $signature = 'amqp:cleanup {action} {queue}';
    protected $description = 'Cleanup queues';

    public function handle()
    {
        $action = $this->argument('action');
        $queue = $this->argument('queue');
        
        $properties = ['queue' => $queue];
        $amqp = app('Amqp');
        
        switch ($action) {
            case 'purge':
                $amqp->queuePurge($queue, $properties);
                $this->info("Queue {$queue} purged");
                break;
                
            case 'delete':
                $amqp->queueDelete($queue, $properties);
                $this->info("Queue {$queue} deleted");
                break;
                
            default:
                $this->error("Unknown action: {$action}");
        }
    }
}
\`\`\``;

      // Management API
      this.documentation["management-api"] = `# Management API

## Queue Statistics

Get queue information:

\`\`\`php
use Bschmitt\\Amqp\\Facades\\Amqp;

$amqp = app('Amqp');
$stats = $amqp->getQueueStats('my-queue', '/');

// Returns:
// [
//     'messages' => 10,
//     'consumers' => 2,
//     'message_bytes' => 1024,
//     ...
// ]
\`\`\`

## Connection Information

\`\`\`php
// Get all connections
$amqp = app('Amqp');
$connections = $amqp->getConnections();

// Get specific connection
$connection = $amqp->getConnections('connection-name');
\`\`\`

## Channel Information

\`\`\`php
// Get all channels
$amqp = app('Amqp');
$channels = $amqp->getChannels();

// Get specific channel
$channel = $amqp->getChannels('channel-name');
\`\`\`

## Node Information

\`\`\`php
// Get all nodes
$amqp = app('Amqp');
$nodes = $amqp->getNodes();

// Get specific node
$node = $amqp->getNodes('node-name');
\`\`\`

## Policy Management

### Create Policy

\`\`\`php
$amqp = app('Amqp');
$amqp->createPolicy('my-policy', [
    'pattern' => '^my-queue$',
    'definition' => [
        'max-length' => 1000,
        'max-length-bytes' => 1048576,
    ]
], '/');
\`\`\`

### Update Policy

\`\`\`php
$amqp = app('Amqp');
$amqp->updatePolicy('my-policy', [
    'pattern' => '^my-queue$',
    'definition' => [
        'max-length' => 2000,
    ]
], '/');
\`\`\`

### Delete Policy

\`\`\`php
$amqp = app('Amqp');
$amqp->deletePolicy('my-policy', '/');
\`\`\`

### List Policies

\`\`\`php
$amqp = app('Amqp');
$policies = $amqp->getPolicies();
\`\`\`

## Feature Flags

\`\`\`php
// List all feature flags
$amqp = app('Amqp');
$flags = $amqp->listFeatureFlags();

// Get specific feature flag
$flag = $amqp->getFeatureFlag('quorum_queue');
\`\`\``;

      // Message Properties
      this.documentation["message-properties"] = `# Message Properties

## Setting Message Properties

\`\`\`php
use Bschmitt\\Amqp\\Facades\\Amqp;

Amqp::publish('routing.key', 'Message', [
    // Standard properties
    'priority' => 10,                    // 0-255
    'correlation_id' => 'unique-id',
    'reply_to' => 'reply-queue',
    'message_id' => 'msg-123',
    'timestamp' => time(),
    'type' => 'notification',
    'user_id' => 'user123',
    'app_id' => 'my-app',
    'expiration' => '60000',             // TTL in milliseconds
    'content_type' => 'application/json',
    'content_encoding' => 'utf-8',
    'delivery_mode' => 2,                // 2 = persistent
    
    // Custom headers
    'application_headers' => [
        'X-Custom-Header' => 'value',
        'X-Request-ID' => 'req-123',
    ],
]);
\`\`\`

## Accessing Message Properties

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    // Get properties
    $priority = $message->getPriority();
    $correlationId = $message->getCorrelationId();
    $replyTo = $message->getReplyTo();
    $headers = $message->getHeaders();
    $customHeader = $message->getHeader('X-Custom-Header');
    
    // Process message
    $resolver->acknowledge($message);
});
\`\`\`

## Common Use Cases

### Priority Messages

\`\`\`php
// High priority
Amqp::publish('tasks', 'urgent task', [
    'priority' => 10,
    'queue_properties' => [
        'x-max-priority' => 10,
    ],
]);

// Normal priority
Amqp::publish('tasks', 'normal task', [
    'priority' => 5,
]);

// Low priority
Amqp::publish('tasks', 'low priority task', [
    'priority' => 1,
]);
\`\`\`

### Message TTL

\`\`\`php
// Message expires in 60 seconds
Amqp::publish('routing.key', 'temporary message', [
    'expiration' => '60000', // milliseconds
]);
\`\`\`

### Custom Headers

\`\`\`php
Amqp::publish('routing.key', 'message', [
    'application_headers' => [
        'X-User-ID' => '123',
        'X-Request-ID' => 'req-456',
        'X-Retry-Count' => 0,
    ],
]);
\`\`\``;

      // Advanced Features
      this.documentation["advanced"] = `# Advanced Features

## Publisher Confirms

Enable publisher confirms for guaranteed delivery:

\`\`\`php
$publisher = app('amqp.publisher');
$publisher->enablePublisherConfirms();

$publisher->setAckHandler(function ($message) {
    // Message was acknowledged
});

$publisher->setNackHandler(function ($message) {
    // Message was not acknowledged
});

$publisher->publish('routing.key', 'message');
$publisher->waitForConfirms();
\`\`\`

## Consumer Prefetch (QoS)

Control message delivery rate:

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    // Process message
}, [
    'qos_prefetch_count' => 10,  // Max 10 unacked messages
    'qos_prefetch_size' => 0,    // No size limit
    'qos_a_global' => false,     // Per consumer
]);
\`\`\`

## Queue Types

### Quorum Queue

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    // Process message
}, [
    'queue_properties' => [
        'x-queue-type' => 'quorum',
    ],
]);
\`\`\`

### Stream Queue

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    // Process message
}, [
    'queue_properties' => [
        'x-queue-type' => 'stream',
    ],
    'queue_durable' => true,
]);
\`\`\`

## Dead Letter Exchanges

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    try {
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\\Exception $e) {
        // Reject without requeue - goes to DLQ
        $resolver->reject($message, false);
    }
}, [
    'queue_properties' => [
        'x-dead-letter-exchange' => 'dlx',
        'x-dead-letter-routing-key' => 'failed',
    ],
]);
\`\`\`

## Message Priority

\`\`\`php
// Configure queue with priority support
$amqp = app('Amqp');
$amqp->consume('priority-queue', function ($message, $resolver) {
    // Process message
}, [
    'queue_properties' => [
        'x-max-priority' => 10,
    ],
]);

// Publish with priority
Amqp::publish('routing.key', 'high priority', [
    'priority' => 10,
]);
\`\`\`

## Lazy Queues

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('lazy-queue', function ($message, $resolver) {
    // Process message
}, [
    'queue_properties' => [
        'x-queue-mode' => 'lazy',
    ],
]);
\`\`\``;

      // Best Practices
      this.documentation["best-practices"] = `# Best Practices

## 1. Error Handling

Always handle errors in consumers:

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    try {
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\\Exception $e) {
        // Log error
        \\Log::error('Message processing failed', [
            'error' => $e->getMessage(),
            'message' => $message->body,
        ]);
        
        // Reject and requeue (or send to DLQ)
        $resolver->reject($message, true);
    }
});
\`\`\`

## 2. Idempotency

Make message processing idempotent:

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    $id = $message->getHeader('X-Message-ID');
    
    // Check if already processed
    if (Cache::has("processed:{$id}")) {
        $resolver->acknowledge($message);
        return;
    }
    
    // Process message
    processMessage($message->body);
    
    // Mark as processed
    Cache::put("processed:{$id}", true, 3600);
    
    $resolver->acknowledge($message);
});
\`\`\`

## 3. Dead Letter Queues

Configure DLQ for failed messages:

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    try {
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\\Exception $e) {
        // Reject without requeue - goes to DLQ
        $resolver->reject($message, false);
    }
}, [
    'queue_properties' => [
        'x-dead-letter-exchange' => 'dlx',
        'x-dead-letter-routing-key' => 'failed',
    ],
]);
\`\`\`

## 4. Production Consumers

Use Artisan commands with process managers:

\`\`\`php
// app/Console/Commands/ProcessQueue.php
class ProcessQueue extends Command
{
    protected $signature = 'queue:process {queue}';
    
    public function handle()
    {
        $amqp = app('Amqp');
        $amqp->consume($this->argument('queue'), function ($message, $resolver) {
            // Process message
            $resolver->acknowledge($message);
        });
    }
}
\`\`\`

## 5. Monitoring

Monitor queue statistics:

\`\`\`php
$amqp = app('Amqp');
$stats = $amqp->getQueueStats('my-queue', '/');

if ($stats['messages'] > 1000) {
    // Alert: Queue backlog
}

if ($stats['consumers'] === 0) {
    // Alert: No consumers
}
\`\`\``;

      // FAQ
      this.documentation["faq"] = `# Frequently Asked Questions

## General Questions

### What is AMQP?

AMQP (Advanced Message Queuing Protocol) is an open standard for message-oriented middleware. RabbitMQ is the most popular implementation.

### Why use Laravel AMQP instead of Laravel Queues?

Laravel AMQP provides:
- Direct RabbitMQ integration
- Advanced RabbitMQ features
- RPC pattern support
- Management API access
- More control over message properties

### What PHP versions are supported?

PHP 7.3+ and PHP 8.0+ are supported.

## Installation & Configuration

### How do I install RabbitMQ?

Using Docker:
\`\`\`bash
docker run -d --name rabbitmq -p 5672:5672 -p 15672:15672 rabbitmq:3-management
\`\`\`

### Connection timeout errors?

Check:
1. RabbitMQ is running
2. Credentials are correct in \`.env\`
3. Port 5672 is accessible
4. Firewall settings

## Usage Questions

### How do I consume messages forever?

\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    processMessage($message->body);
    $resolver->acknowledge($message);
}, ['persistent' => true]);
\`\`\`

### Can I use the Facade for consume()?

No, you must use \`app('Amqp')\` or \`resolve('Amqp')\` for consume(), listen(), and rpc() methods.

### How do I handle failed messages?

Use Dead Letter Exchanges:
\`\`\`php
$amqp = app('Amqp');
$amqp->consume('queue', function ($message, $resolver) {
    try {
        processMessage($message->body);
        $resolver->acknowledge($message);
    } catch (\\Exception $e) {
        $resolver->reject($message, false); // Send to DLQ
    }
}, [
    'queue_properties' => [
        'x-dead-letter-exchange' => 'dlx',
    ],
]);
\`\`\`

## Troubleshooting

### Messages not being consumed?

Check:
1. Consumer is running
2. Routing key matches
3. Queue is bound to exchange
4. Messages are being acknowledged

### RPC timeout?

1. Increase timeout value
2. Check server is running
3. Verify queue name
4. Check server processing time

### Memory issues?

1. Use consumer prefetch (QoS)
2. Process messages in batches
3. Use message_limit option
4. Monitor memory usage`;

      // Troubleshooting
      this.documentation["troubleshooting"] = `# Troubleshooting

## Connection Errors

### Cannot connect to RabbitMQ

**Problem:** Connection timeout or refused

**Solutions:**
- Check RabbitMQ is running: \`rabbitmqctl status\`
- Verify credentials in \`.env\`
- Check firewall/network settings
- Ensure RabbitMQ port (5672) is accessible

### Authentication failed

**Problem:** ACCESS_REFUSED error

**Solutions:**
- Verify username and password
- Check user permissions
- Ensure vhost exists
- Check user has access to vhost

## Queue Errors

### Queue not found

**Problem:** \`PRECONDITION_FAILED - queue not found\`

**Solutions:**
- Ensure queue exists before consuming
- Check queue name spelling
- Verify vhost permissions
- Use \`queue_passive => true\` to check existence

### Exchange type mismatch

**Problem:** \`PRECONDITION_FAILED - inequivalent arg 'exchange_type'\`

**Solutions:**
- Use \`exchange_passive => true\` for existing exchanges
- Match exchange type exactly
- Delete and recreate exchange if needed

## Message Issues

### Messages not received

**Problem:** Messages published but not consumed

**Solutions:**
- Check routing key matches binding
- Verify queue is bound to exchange
- Check consumer is actively running
- Ensure message is acknowledged

### Messages disappearing

**Problem:** Messages lost after restart

**Solutions:**
- Set \`delivery_mode => 2\` for persistent messages
- Use \`queue_durable => true\`
- Enable publisher confirms

## Performance Issues

### High memory usage

**Solutions:**
- Use consumer prefetch (QoS)
- Set \`qos_prefetch_count\`
- Use \`message_limit\` option
- Process messages in batches

### Slow processing

**Solutions:**
- Increase number of consumers
- Optimize message processing
- Use multiple workers
- Consider message priority

## Debug Mode

Enable debug logging:

\`\`\`php
// In config/amqp.php or .env
define('APP_DEBUG', true);
\`\`\`

## Testing Connection

\`\`\`php
use Bschmitt\\Amqp\\Facades\\Amqp;

try {
    Amqp::publish('test', 'test');
    echo "Connection successful";
} catch (\\Exception $e) {
    echo "Connection failed: " . $e->getMessage();
}
\`\`\``;

      // Guide (main documentation page)
      this.documentation["guide"] = `# Laravel AMQP Documentation

Welcome to the Laravel AMQP package documentation. This guide will help you get started with RabbitMQ in your Laravel application.

## Quick Navigation

### Getting Started
- [Quick Start](#getting-started) - Get up and running quickly
- [Installation](#installation) - Detailed installation guide
- [Configuration](#configuration) - Configure your connection

### Core Features
- [Publishing Messages](#publishing) - Send messages to queues
- [Consuming Messages](#consuming) - Process messages from queues
- [RPC Pattern](#rpc) - Request-response communication

### Management
- [Queue Management](#queue-management) - Manage queues and exchanges
- [Management API](#management-api) - Use RabbitMQ Management API

### Advanced
- [Message Properties](#message-properties) - Work with message metadata
- [Advanced Features](#advanced) - Publisher confirms, QoS, queue types
- [Best Practices](#best-practices) - Production-ready patterns

### Reference
- [FAQ](#faq) - Common questions
- [Troubleshooting](#troubleshooting) - Solve common issues

## Package Features

-   Simple API for publishing and consuming
-   RPC pattern support
-   Queue management operations
-   RabbitMQ Management API integration
-   Full message properties support
-   Publisher confirms
-   Consumer prefetch (QoS)
-   Multiple queue types (classic, quorum, stream)
-   Dead letter exchanges
-   Message priority
-   TTL support

## Quick Example

\`\`\`php
use Bschmitt\\Amqp\\Facades\\Amqp;

// Publish
Amqp::publish('routing.key', 'Hello World');

// Consume
$amqp = app('Amqp');
$amqp->consume('queue-name', function ($message, $resolver) {
    echo $message->body;
    $resolver->acknowledge($message);
});
\`\`\`

## Support

- **GitHub:** [bschmitt/laravel-amqp](https://github.com/bschmitt/laravel-amqp)
- **Issues:** [GitHub Issues](https://github.com/bschmitt/laravel-amqp/issues)
- **Packagist:** [bschmitt/laravel-amqp](https://packagist.org/packages/bschmitt/laravel-amqp)`;
    },
  },
}).mount("#app");
