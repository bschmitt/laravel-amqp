<?php

namespace Bschmitt\Amqp\Support;

/**
 * Outcome of a {@see Saga} execution.
 */
class SagaResult
{
    /** @var bool */
    protected $succeeded;

    /** @var array<string, mixed> */
    protected $stepResults;

    /** @var array<int, string> */
    protected $compensated;

    /** @var \Throwable|null */
    protected $exception;

    /** @var string|null */
    protected $failedStep;

    /**
     * @param bool $succeeded
     * @param array<string, mixed> $stepResults
     * @param array<int, string> $compensated
     * @param \Throwable|null $exception
     * @param string|null $failedStep
     */
    public function __construct(
        bool $succeeded,
        array $stepResults,
        array $compensated,
        ?\Throwable $exception,
        ?string $failedStep
    ) {
        $this->succeeded = $succeeded;
        $this->stepResults = $stepResults;
        $this->compensated = $compensated;
        $this->exception = $exception;
        $this->failedStep = $failedStep;
    }

    public function succeeded(): bool
    {
        return $this->succeeded;
    }

    public function failed(): bool
    {
        return !$this->succeeded;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStepResults(): array
    {
        return $this->stepResults;
    }

    /**
     * @return array<int, string>
     */
    public function getCompensatedSteps(): array
    {
        return $this->compensated;
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    public function getFailedStep(): ?string
    {
        return $this->failedStep;
    }
}
