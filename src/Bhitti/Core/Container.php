<?php

declare(strict_types=1);

namespace Bhitti\Core;

use Closure;
use ReflectionClass;
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

        if (!class_exists($concrete)  && !interface_exists($concrete)) {
            throw new RuntimeException(
                "Class or interface [{$concrete}] does not exist."
            );
        }

        if (isset($this->resolving[$concrete])) {
            throw new RuntimeException(
                "Circular dependency detected while resolving [{$concrete}]."
            );
        }

        $this->resolving[$concrete] = true;

        try {
            $reflector = self::$reflectionCache[$concrete]
                ??= new ReflectionClass($concrete);

            if (!$reflector->isInstantiable()) {
                throw new RuntimeException(
                    "Class [{$concrete}] is not instantiable."
                );
            }

            $constructor = $reflector->getConstructor();

            if ($constructor === null) {
                return new $concrete();
            }

            $dependencies = [];
            $position = 0;

            foreach ($constructor->getParameters() as $parameter) {
                $name = $parameter->getName();

                /*
                * Named makeWith() override.
                */
                if (array_key_exists($name, $parameters)) {
                    $dependencies[] = $parameters[$name];
                    continue;
                }

                $type = $parameter->getType();

                /*
                * Single class/interface dependency.
                */
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $dependency = $type->getName();

                    /*
                    * Explicit binding/singleton.
                    */
                    if ($this->has($dependency)) {
                        $dependencies[] = $this->make($dependency);
                        continue;
                    }

                    /*
                    * Missing dependency type:
                    * likely typo/programming error.
                    */
                    if (!class_exists($dependency) && !interface_exists($dependency)) {
                        throw new RuntimeException(
                            "Dependency [{$dependency}] "
                            . "for [{$concrete}] does not exist."
                        );
                    }

                    $dependencyReflector = self::$reflectionCache[$dependency]
                        ??= new ReflectionClass($dependency);

                    /*
                    * Concrete class: auto-wire.
                    */
                    if ($dependencyReflector->isInstantiable()) {
                        $dependencies[] = $this->make($dependency);
                        continue;
                    }

                    /*
                    * Optional abstract/interface dependency.
                    */
                    if ($parameter->allowsNull()) {
                        $dependencies[] = null;
                        continue;
                    }

                    throw new RuntimeException(
                        "Dependency [{$dependency}] "
                        . "in class [{$concrete}] "
                        . "is not instantiable and has no binding."
                    );
                }

                /*
                * Numeric makeWith() parameters apply only
                * to non-autowired parameters.
                */
                $currentPosition = $position++;

                if (array_key_exists($currentPosition, $parameters)) {
                    $dependencies[] =  $parameters[$currentPosition];
                    continue;
                }

                /*
                * Union/intersection types are intentionally
                * not automatically resolved.
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
