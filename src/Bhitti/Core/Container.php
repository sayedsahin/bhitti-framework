<?php

declare(strict_types=1);

namespace Bhitti\Core;

use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use RuntimeException;

class Container
{
    protected array $bindings = [];
    protected array $singletons = [];
    protected static array $reflectionCache = [];
    protected array $resolving = [];

    public function bind(string $abstract, Closure|string|null $concrete = null): void
    {
        unset($this->singletons[$abstract]);
        $this->bindings[$abstract] = $concrete ?? $abstract;
    }

    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        unset($this->bindings[$abstract]);
        $this->singletons[$abstract] = [
            'concrete' => $concrete ?? $abstract,
            'resolved' => false,
            'instance' => null,
        ];
    }

    public function instance(string $abstract, object $instance): void
    {
        unset($this->bindings[$abstract]);

        $this->singletons[$abstract] = [
            'concrete' => $abstract,
            'resolved' => true,
            'instance' => $instance,
        ];
    }

    public function make(string $abstract): mixed
    {
        if (isset($this->singletons[$abstract])) {
            if ($this->singletons[$abstract]['resolved']) {
                return $this->singletons[$abstract]['instance'];
            }

            $object = $this->build($this->singletons[$abstract]['concrete']);
            $this->singletons[$abstract]['instance'] = $object;
            $this->singletons[$abstract]['resolved'] = true;

            return $object;
        }

        $concrete = $this->bindings[$abstract] ?? $abstract;
        return $this->build($concrete);
    }

    /**
     * Build a fresh instance while supplying scalar/array constructor values.
     * Class-typed dependencies are still resolved through the container.
     */
    public function makeWith(string $abstract, array $parameters = []): mixed
    {
        if ($parameters === []) {
            return $this->make($abstract);
        }

        $concrete = $this->bindings[$abstract]
            ?? $this->singletons[$abstract]['concrete']
            ?? $abstract;

        return $this->build($concrete, $parameters);
    }

    protected function build(Closure|string $concrete, array $parameters = []): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, ...$parameters);
        }

        if (isset($this->resolving[$concrete])) {
            throw new RuntimeException(
                "Circular dependency detected while resolving [{$concrete}]."
            );
        }

        /*
        * Reflection itself validates class/interface existence.
        * No separate class_exists()/interface_exists() hot-path checks.
        */
        try {
            $reflector = self::$reflectionCache[$concrete]
                ??= new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw new RuntimeException(
                "Class or interface [{$concrete}] does not exist.",
                0,
                $e
            );
        }

        if (!$reflector->isInstantiable()) {
            throw new RuntimeException(
                "Class [{$concrete}] is not instantiable."
            );
        }

        $constructor = $reflector->getConstructor();

        /*
        * Fast path for classes without constructors.
        */
        if ($constructor === null) {
            return new $concrete();
        }

        $this->resolving[$concrete] = true;

        try {
            $dependencies = [];
            $position = 0;

            foreach ($constructor->getParameters() as $parameter) {
                $name = $parameter->getName();

                /*
                * Named makeWith() override has highest priority.
                */
                if (array_key_exists($name, $parameters)) {
                    $dependencies[] = $parameters[$name];

                    $type = $parameter->getType();

                    /*
                    * Numeric positions represent only parameters
                    * that are not automatically class-resolved.
                    */
                    if (
                        !$type instanceof ReflectionNamedType
                        || $type->isBuiltin()
                    ) {
                        $position++;
                    }

                    continue;
                }

                $type = $parameter->getType();

                /*
                * Single class/interface dependency.
                */
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $dependency = $type->getName();

                    /*
                    * Common hot path:
                    *
                    * - explicitly bound dependency
                    * - required/non-nullable class dependency
                    *
                    * Let make() resolve it directly.
                    * Avoid duplicate Reflection inspection here.
                    */
                    if ($this->has($dependency) || !$parameter->allowsNull()) {
                        $dependencies[] = $this->make($dependency);
                        continue;
                    }

                    /*
                    * Only nullable + unbound dependencies require
                    * additional inspection.
                    *
                    * Example:
                    * ?LoggerInterface $logger
                    */
                    try {
                        $dependencyReflector =
                            self::$reflectionCache[$dependency]
                            ??= new ReflectionClass($dependency);
                    } catch (ReflectionException $e) {
                        /*
                        * Nullable does not hide a missing/typo type.
                        */
                        throw new RuntimeException(
                            "Dependency [{$dependency}] "
                            . "for [{$concrete}] does not exist.",
                            0,
                            $e
                        );
                    }

                    /*
                    * Nullable concrete class still gets autowired.
                    */
                    if ($dependencyReflector->isInstantiable()) {
                        $dependencies[] = $this->make($dependency);
                        continue;
                    }

                    /*
                    * Existing nullable interface/abstract class
                    * without binding is treated as optional.
                    */
                    $dependencies[] = null;
                    continue;
                }

                /*
                * Numeric makeWith() arguments apply only to
                * non-autowired parameters.
                */
                $currentPosition = $position++;

                if (array_key_exists($currentPosition, $parameters)) {
                    $dependencies[] = $parameters[$currentPosition];
                    continue;
                }

                /*
                * Union/intersection types are intentionally
                * outside Bhitti's automatic DI contract.
                */
                if ($type !== null && !$type instanceof ReflectionNamedType) {
                    throw new RuntimeException(
                        "Union/intersection dependency [{$name}] "
                        . "in class [{$concrete}] cannot be autowired. "
                        . "Provide it using makeWith()."
                    );
                }

                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }

                if ($parameter->allowsNull()) {
                    $dependencies[] = null;
                    continue;
                }

                throw new RuntimeException(
                    "Unresolvable dependency [{$name}] "
                    . "in class [{$concrete}]."
                );
            }

            return new $concrete(...$dependencies);
        } finally {
            unset($this->resolving[$concrete]);
        }
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->singletons[$abstract]);
    }
}