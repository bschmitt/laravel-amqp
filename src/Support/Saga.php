<?php

namespace Bschmitt\Amqp\Support;

/**
 * Lightweight in-process SAGA workflow.
 *
 * A {@see Saga} is an ordered list of steps. Each step is a callable that
 * receives a mutable context array; an optional compensation callable can be
 * paired with the step to roll back its side effects on failure.
 *
 * Steps must be idempotent or guard against partial replay. Compensations
 * run in reverse order, only for steps that completed successfully.
 *
 *   $saga = (new Saga('checkout'))
 *       ->step('reserveStock', $reserve, $releaseStock)
 *       ->step('chargeCard', $charge, $refund)
 *       ->step('shipOrder', $ship);
 *
 *   $result = $saga->execute(['orderId' => 42]);
 *   if (!$result->succeeded()) { ... $result->getException() ... }
 */
class Saga
{
    /** @var string */
    protected $name;

    /**
     * @var array<int, array{name:string, action:callable, compensation:callable|null}>
     */
    protected $steps = [];

    /** @var callable|null */
    protected $logger;

    /**
     * @param string $name
     */
    public function __construct(string $name = 'saga')
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Register a step (and its optional compensation).
     *
     * @param string        $name
     * @param callable      $action       function (array $context): mixed
     * @param callable|null $compensation function (array $context, mixed $stepResult): void
     * @return $this
     */
    public function step(string $name, callable $action, ?callable $compensation = null): self
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Saga step name must be non-empty');
        }

        $this->steps[] = [
            'name' => $name,
            'action' => $action,
            'compensation' => $compensation,
        ];

        return $this;
    }

    /**
     * Optional logger callable: function (string $level, string $message, array $context): void
     *
     * @param callable $logger
     * @return $this
     */
    public function setLogger(callable $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function stepNames(): array
    {
        $names = [];
        foreach ($this->steps as $step) {
            $names[] = $step['name'];
        }

        return $names;
    }

    /**
     * Run every step in order. On failure, run compensations for completed
     * steps in reverse order. The result reports per-step status.
     *
     * @param array<string, mixed> $context
     * @return SagaResult
     */
    public function execute(array $context = []): SagaResult
    {
        $completed = [];
        $stepResults = [];
        $failure = null;
        $failedStep = null;

        foreach ($this->steps as $step) {
            $name = $step['name'];
            try {
                $this->log('info', sprintf('saga.%s.step.start', $this->name), ['step' => $name]);
                $result = call_user_func($step['action'], $context);
                $stepResults[$name] = $result;
                $completed[] = $step;
                $this->log('info', sprintf('saga.%s.step.ok', $this->name), ['step' => $name]);
            } catch (\Throwable $e) {
                $failure = $e;
                $failedStep = $name;
                $this->log('error', sprintf('saga.%s.step.failed', $this->name), [
                    'step' => $name,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        if ($failure === null) {
            return new SagaResult(true, $stepResults, [], null, null);
        }

        $compensated = $this->compensateCompleted($completed, $stepResults, $context);

        return new SagaResult(false, $stepResults, $compensated, $failure, $failedStep);
    }

    /**
     * @param array<int, array{name:string, action:callable, compensation:callable|null}> $completed
     * @param array<string, mixed> $stepResults
     * @param array<string, mixed> $context
     * @return array<int, string> Names of steps whose compensations executed.
     */
    protected function compensateCompleted(array $completed, array $stepResults, array $context): array
    {
        $compensated = [];
        foreach (array_reverse($completed) as $step) {
            if ($step['compensation'] === null) {
                continue;
            }
            $name = $step['name'];
            try {
                $this->log('info', sprintf('saga.%s.compensate.start', $this->name), ['step' => $name]);
                call_user_func($step['compensation'], $context, $stepResults[$name] ?? null);
                $compensated[] = $name;
                $this->log('info', sprintf('saga.%s.compensate.ok', $this->name), ['step' => $name]);
            } catch (\Throwable $e) {
                $this->log('error', sprintf('saga.%s.compensate.failed', $this->name), [
                    'step' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $compensated;
    }

    /**
     * @param string $level
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }
        call_user_func($this->logger, $level, $message, $context);
    }
}
