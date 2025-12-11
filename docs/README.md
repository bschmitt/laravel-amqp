# Laravel AMQP Package Documentation

Welcome to the Laravel AMQP package documentation. This directory contains comprehensive documentation for all features, architecture, and implementation details.

## Table of Contents

### Feature Documentation

1. **[Maximum Queue Length](./modules/RABBITMQ_MAXLENGTH_SUPPORT.md)**
   - `x-max-length`, `x-max-length-bytes`, `x-overflow` properties
   - Queue size limits and overflow behaviors

2. **[Time-to-Live (TTL)](./modules/TTL.md)**
   - `x-message-ttl` - Message expiration
   - `x-expires` - Queue expiration

3. **[Dead Letter Exchange (DLX)](./modules/DEAD_LETTER_EXCHANGE.md)**
   - `x-dead-letter-exchange` - Dead letter routing
   - `x-dead-letter-routing-key` - Custom routing for dead letters

4. **[Message Priority](./modules/MESSAGE_PRIORITY.md)**
   - `x-max-priority` - Maximum priority level
   - Message priority handling

5. **[Lazy Queues](./modules/LAZY_QUEUES.md)**
   - `x-queue-mode` - Lazy vs default queue mode
   - Disk-based message storage for large backlogs

6. **[Queue Types](./modules/QUEUE_TYPE.md)**
   - `x-queue-type` - Classic, Quorum, and Stream queues
   - Queue type selection and requirements

7. **[Alternate Exchange](./modules/ALTERNATE_EXCHANGE.md)**
   - `alternate-exchange` - Handling unroutable messages
   - Dead letter routing for exchanges

8. **[Exchange Types](./modules/EXCHANGE_TYPES.md)**
   - All RabbitMQ exchange types (topic, direct, fanout, headers)
   - Exchange type validation
   - Routing behavior guide

9. **[Quorum Queues](./modules/QUORUM_QUEUES.md)**
   - High availability queues with automatic leader election
   - Raft consensus and replication
   - Modern alternative to mirrored queues

10. **[Stream Queues](./modules/STREAM_QUEUES.md)**
   - High-throughput append-only log queues
   - Message replay and offset management
   - Ideal for event sourcing and logging

11. **[Publisher Confirms](./modules/PUBLISHER_CONFIRMS.md)**
   - Guaranteed message delivery confirmation
   - Ack/nack/return callback registration
   - Wait for confirms API

12. **[Master Locator](./modules/MASTER_LOCATOR.md)** (Deprecated)
   - `x-queue-master-locator` - Master node locator strategy
   - Note: Deprecated - use Quorum Queues instead

### Architecture & Design Documentation

7. **[Architecture Review](./modules/ARCHITECTURE_REVIEW.md)**
   - Package architecture overview
   - Component relationships

8. **[Refactoring Recommendations](./modules/REFACTORING_RECOMMENDATIONS.md)**
   - Code improvement suggestions
   - Design pattern recommendations

9. **[Refactoring Complete](./modules/REFACTORING_COMPLETE.md)**
   - Summary of completed refactoring work
   - Improvements implemented

10. **[Coupling & Scalability Summary](./modules/COUPLING_SCALABILITY_SUMMARY.md)**
    - Coupling analysis
    - Scalability assessment

### New Modules (Version 3.1.0)

13. **[RPC Module](./modules/RPC_MODULE.md)**
    - `rpc()` - Make RPC calls
    - `reply()` - Send RPC responses
    - `listen()` - Auto-create queues with multiple bindings
    - Request-response pattern support

14. **[Management Operations Module](./modules/MANAGEMENT_OPERATIONS.md)**
    - `queuePurge()` - Remove all messages
    - `queueDelete()` - Delete queues
    - `queueUnbind()` - Unbind queues
    - `exchangeDelete()` - Delete exchanges
    - `exchangeUnbind()` - Unbind exchanges

15. **[Management API Module](./modules/MANAGEMENT_API.md)**
    - `getQueueStats()` - Queue statistics
    - `getConnections()` - Connection information
    - `getChannels()` - Channel information
    - `getNodes()` - Node information
    - `getPolicies()` - Policy management
    - `createPolicy()` - Create policies
    - `updatePolicy()` - Update policies
    - `deletePolicy()` - Delete policies
    - `listFeatureFlags()` - Feature flags
    - `getFeatureFlag()` - Feature flag status

16. **[Message Properties Module](./modules/MESSAGE_PROPERTIES_MODULE.md)**
    - Full AMQP property support
    - Priority, correlation_id, reply_to
    - Application headers
    - Content type and encoding
    - Message expiration

17. **[Listen Method Module](./modules/LISTEN_METHOD.md)**
    - `listen()` - Auto-create queues
    - Multiple routing key binding
    - Simplified multi-key consumption

18. **[Consumer Prefetch Module](./modules/CONSUMER_PREFETCH.md)**
    - QoS prefetch configuration
    - Dynamic prefetch adjustment
    - Rate limiting control

19. **[Connection Configuration Module](./modules/CONNECTION_CONFIG.md)**
    - `getConnectionConfig()` - Retrieve connection configs
    - Multi-connection support
    - Dynamic configuration access

### Feature Status


## Quick Links

- [Main README](../README.md) - Package overview and installation
- [Test Documentation](../test/README.md) - Testing guide
- [Integration Test Guide](../test/README-INTEGRATION.md) - Integration testing

## Feature Support Matrix

| Feature | Status | Documentation |
|---------|--------|---------------|
| Maximum Queue Length | Supported | [RABBITMQ_MAXLENGTH_SUPPORT.md](./modules/RABBITMQ_MAXLENGTH_SUPPORT.md) |
| Message TTL | Supported | [TTL.md](./modules/TTL.md) |
| Queue Expires | Supported | [TTL.md](./modules/TTL.md) |
| Dead Letter Exchange | Supported | [DEAD_LETTER_EXCHANGE.md](./modules/DEAD_LETTER_EXCHANGE.md) |
| Message Priority | Supported | [MESSAGE_PRIORITY.md](./modules/MESSAGE_PRIORITY.md) |
| Lazy Queues | Supported | [LAZY_QUEUES.md](./modules/LAZY_QUEUES.md) |
| Queue Types | Supported | [QUEUE_TYPE.md](./modules/QUEUE_TYPE.md) |
| Alternate Exchange | Supported | [ALTERNATE_EXCHANGE.md](./modules/ALTERNATE_EXCHANGE.md) |
| Exchange Types | Supported | [EXCHANGE_TYPES.md](./modules/EXCHANGE_TYPES.md) |
| Quorum Queues | Supported | [QUORUM_QUEUES.md](./modules/QUORUM_QUEUES.md) |
| Stream Queues | Supported | [STREAM_QUEUES.md](./modules/STREAM_QUEUES.md) |
| Publisher Confirms | Supported | [PUBLISHER_CONFIRMS.md](./modules/PUBLISHER_CONFIRMS.md) |
| Master Locator | Supported (Deprecated) | [MASTER_LOCATOR.md](./modules/MASTER_LOCATOR.md) |
| RPC Pattern | Supported | [RPC_MODULE.md](./modules/RPC_MODULE.md) |
| Management Operations | Supported | [MANAGEMENT_OPERATIONS.md](./modules/MANAGEMENT_OPERATIONS.md) |
| Management API | Supported | [MANAGEMENT_API.md](./modules/MANAGEMENT_API.md) |
| Message Properties | Supported | [MESSAGE_PROPERTIES_MODULE.md](./modules/MESSAGE_PROPERTIES_MODULE.md) |
| Listen Method | Supported | [LISTEN_METHOD.md](./modules/LISTEN_METHOD.md) |
| Consumer Prefetch | Supported | [CONSUMER_PREFETCH.md](./modules/CONSUMER_PREFETCH.md) |
| Connection Config | Supported | [CONNECTION_CONFIG.md](./modules/CONNECTION_CONFIG.md) |

## Getting Started

1. Read the [Main README](../README.md) for installation and basic usage
2. Check individual feature documentation for detailed usage examples
3. See [Test Documentation](../test/README.md) for testing guidelines
4. Visit the [Wiki Documentation](./wiki/Home.md) for comprehensive guides

## Contributing

When adding new features:
1. Update the relevant feature documentation
2. Add test cases (unit and integration)
3. Update this README if adding new documentation files
4. Update the wiki documentation as needed

