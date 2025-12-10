# Laravel AMQP Package Documentation

Welcome to the Laravel AMQP package documentation. This directory contains comprehensive documentation for all features, architecture, and implementation details.

## Table of Contents

### Feature Documentation

1. **[Maximum Queue Length](./RABBITMQ_MAXLENGTH_SUPPORT.md)**
   - `x-max-length`, `x-max-length-bytes`, `x-overflow` properties
   - Queue size limits and overflow behaviors

2. **[Time-to-Live (TTL)](./TTL_FEATURE_DOCUMENTATION.md)**
   - `x-message-ttl` - Message expiration
   - `x-expires` - Queue expiration

3. **[Dead Letter Exchange (DLX)](./DEAD_LETTER_EXCHANGE_DOCUMENTATION.md)**
   - `x-dead-letter-exchange` - Dead letter routing
   - `x-dead-letter-routing-key` - Custom routing for dead letters

4. **[Message Priority](./MESSAGE_PRIORITY_DOCUMENTATION.md)**
   - `x-max-priority` - Maximum priority level
   - Message priority handling

5. **[Lazy Queues](./LAZY_QUEUES_DOCUMENTATION.md)**
   - `x-queue-mode` - Lazy vs default queue mode
   - Disk-based message storage for large backlogs

6. **[Queue Types](./QUEUE_TYPE_DOCUMENTATION.md)**
   - `x-queue-type` - Classic, Quorum, and Stream queues
   - Queue type selection and requirements

### Architecture & Design Documentation

7. **[Architecture Review](./ARCHITECTURE_REVIEW.md)**
   - Package architecture overview
   - Component relationships

8. **[Refactoring Recommendations](./REFACTORING_RECOMMENDATIONS.md)**
   - Code improvement suggestions
   - Design pattern recommendations

9. **[Refactoring Complete](./REFACTORING_COMPLETE.md)**
   - Summary of completed refactoring work
   - Improvements implemented

10. **[Coupling & Scalability Summary](./COUPLING_SCALABILITY_SUMMARY.md)**
    - Coupling analysis
    - Scalability assessment

### Feature Status

11. **[Missing Features & Coupling Analysis](./MISSING_FEATURES_AND_COUPLING.md)**
    - Complete list of RabbitMQ features
    - Implementation status
    - Missing features and priorities

## Quick Links

- [Main README](../README.md) - Package overview and installation
- [Test Documentation](../test/README.md) - Testing guide
- [Integration Test Guide](../test/README-INTEGRATION.md) - Integration testing

## Feature Support Matrix

| Feature | Status | Documentation |
|---------|--------|---------------|
| Maximum Queue Length | ✅ Supported | [RABBITMQ_MAXLENGTH_SUPPORT.md](./RABBITMQ_MAXLENGTH_SUPPORT.md) |
| Message TTL | ✅ Supported | [TTL_FEATURE_DOCUMENTATION.md](./TTL_FEATURE_DOCUMENTATION.md) |
| Queue Expires | ✅ Supported | [TTL_FEATURE_DOCUMENTATION.md](./TTL_FEATURE_DOCUMENTATION.md) |
| Dead Letter Exchange | ✅ Supported | [DEAD_LETTER_EXCHANGE_DOCUMENTATION.md](./DEAD_LETTER_EXCHANGE_DOCUMENTATION.md) |
| Message Priority | ✅ Supported | [MESSAGE_PRIORITY_DOCUMENTATION.md](./MESSAGE_PRIORITY_DOCUMENTATION.md) |
| Lazy Queues | ✅ Supported | [LAZY_QUEUES_DOCUMENTATION.md](./LAZY_QUEUES_DOCUMENTATION.md) |
| Queue Types | ✅ Supported | [QUEUE_TYPE_DOCUMENTATION.md](./QUEUE_TYPE_DOCUMENTATION.md) |

## Getting Started

1. Read the [Main README](../README.md) for installation and basic usage
2. Review [Missing Features & Coupling Analysis](./MISSING_FEATURES_AND_COUPLING.md) for feature status
3. Check individual feature documentation for detailed usage examples
4. See [Test Documentation](../test/README.md) for testing guidelines

## Contributing

When adding new features:
1. Update the relevant feature documentation
2. Add test cases (unit and integration)
3. Update [MISSING_FEATURES_AND_COUPLING.md](./MISSING_FEATURES_AND_COUPLING.md) with status
4. Update this README if adding new documentation files

