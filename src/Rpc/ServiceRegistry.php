<?php

namespace Bschmitt\Amqp\Rpc;

/**
 * String-name registry for {@see RpcService} classes.
 *
 * Lets clients call services by short name instead of FQCN:
 *
 *   Rpc::register('payments', PaymentsService::class);
 *   Rpc::service('payments')->call(GetPaymentRequest::make(['id' => 1]));
 *
 * Auto-registration: a service class may expose `public static function
 * alias(): ?string` to declare its own name; {@see autodiscover()} pulls
 * those into the registry without configuration.
 */
class ServiceRegistry
{
    /**
     * @var array<string, class-string<RpcService>>
     */
    protected $services = [];

    /**
     * @param string                   $name
     * @param class-string<RpcService> $serviceClass
     * @return $this
     */
    public function register(string $name, string $serviceClass): self
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Service name must be non-empty');
        }
        if (!class_exists($serviceClass)) {
            throw new \InvalidArgumentException(sprintf('Service class [%s] does not exist', $serviceClass));
        }
        if (!is_subclass_of($serviceClass, RpcService::class)) {
            throw new \InvalidArgumentException(sprintf(
                'Service class [%s] must extend %s',
                $serviceClass,
                RpcService::class
            ));
        }

        $this->services[$name] = $serviceClass;

        return $this;
    }

    /**
     * Auto-register every service class that exposes a non-null `alias()`.
     *
     * @param array<int, class-string<RpcService>> $serviceClasses
     * @return $this
     */
    public function autodiscover(array $serviceClasses): self
    {
        foreach ($serviceClasses as $class) {
            if (!class_exists($class) || !is_subclass_of($class, RpcService::class)) {
                continue;
            }
            if (!method_exists($class, 'alias')) {
                continue;
            }
            $alias = $class::alias();
            if (is_string($alias) && $alias !== '') {
                $this->register($alias, $class);
            }
        }

        return $this;
    }

    /**
     * @param string $name
     * @return class-string<RpcService>
     */
    public function resolve(string $name): string
    {
        if (!isset($this->services[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'No service registered under name [%s]. Registered: [%s]',
                $name,
                implode(', ', array_keys($this->services))
            ));
        }

        return $this->services[$name];
    }

    /**
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->services[$name]);
    }

    /**
     * @return array<string, class-string<RpcService>>
     */
    public function all(): array
    {
        return $this->services;
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        $this->services = [];
    }
}
