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
         * ReflectionClass already validates that the
         * class/interface exists, so separate class_exists()
         * and interface_exists() checks are unnecessary.
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

        if ($constructor === null) {
            return new $concrete();
        }

        $this->resolving[$concrete] = true;

        try {
            $dependencies = [];

            /*
             * Numeric makeWith() positions apply only to
             * parameters that are not automatically resolved
             * as class dependencies.
             */
            $position = 0;

            foreach ($constructor->getParameters() as $parameter) {
                $name = $parameter->getName();
                $type = $parameter->getType();

                $classTyped = ($type instanceof ReflectionNamedType && !$type->isBuiltin());

                /*
                 * Named makeWith() override has highest priority.
                 */
                if (array_key_exists($name, $parameters)) {
                    $dependencies[] = $parameters[$name];

                    /*
                     * A manually supplied scalar/untyped/union
                     * parameter consumes its positional slot.
                     *
                     * Class dependencies do not.
                     */
                    if (!$classTyped) {
                        $position++;
                    }

                    continue;
                }

                /*
                 * Single class/interface dependency.
                 */
                if ($classTyped) {
                    $dependency = $type->getName();

                    /*
                     * Explicit binding/singleton always wins.
                     */
                    if ($this->has($dependency)) {
                        $dependencies[] = $this->make($dependency);
                        continue;
                    }

                    /*
                     * Use Reflection cache directly instead of
                     * performing class_exists()/interface_exists()
                     * checks on every resolution.
                     */
                    try {
                        $dependencyReflector =
                            self::$reflectionCache[$dependency]
                            ??= new ReflectionClass($dependency);
                    } catch (ReflectionException $e) {
                        throw new RuntimeException(
                            "Dependency [{$dependency}] "
                            . "for [{$concrete}] does not exist.",
                            0,
                            $e
                        );
                    }

                    /*
                     * Concrete dependency: auto-wire.
                     */
                    if ($dependencyReflector->isInstantiable()) {
                        $dependencies[] = $this->make($dependency);
                        continue;
                    }

                    /*
                     * Existing but unbound interface/abstract
                     * dependency may be optional when nullable.
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
                 * Numeric makeWith() values apply to the
                 * non-autowired parameter sequence.
                 */
                $currentPosition = $position++;

                if (array_key_exists($currentPosition, $parameters)) {
                    $dependencies[] = $parameters[$currentPosition];
                    continue;
                }

                /*
                 * Union/intersection dependencies are intentionally
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