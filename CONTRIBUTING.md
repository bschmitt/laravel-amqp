# Contributing Guide

Thanks for your interest in contributing to `bschmitt/laravel-amqp`.

## How to Contribute

- Report bugs using the issue templates.
- Propose features with clear use cases.
- Submit pull requests with focused, reviewable changes.

## Development Setup

1. Fork and clone the repository.
2. Install dependencies:
   ```bash
   composer install
   ```
3. Run tests:
   ```bash
   php vendor/bin/phpunit
   ```

## Branch Naming

Use descriptive branch names, for example:

- `fix/rpc-correlation-id`
- `feature/laravel-13-support`
- `docs/update-readme`

## Coding Standards

- Keep changes small and focused.
- Follow existing project style and naming conventions.
- Add or update tests for behavior changes.
- Avoid unrelated formatting-only changes in feature/fix PRs.

## Test Expectations

Before opening a PR, run:

```bash
php vendor/bin/phpunit
```

If your change affects RabbitMQ behavior, run integration tests as well:

```bash
php vendor/bin/phpunit test/Integration
```

## Pull Request Checklist

- [ ] Change is scoped and well described.
- [ ] Tests added/updated where needed.
- [ ] Relevant tests pass locally.
- [ ] README/docs updated if behavior changed.
- [ ] No unrelated changes included.

## Commit Messages

Use clear, concise commit messages, for example:

- `fix: set correlation_id as message property in reply()`
- `docs: add Laravel 13 support badge`

## Questions

If you are unsure about design direction, open an issue before implementing a large change.
