<?php

namespace BlueprintX\Kernel;

use BlueprintX\Contracts\ArchitectureDriver;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class DriverManager
{
    /**
     * @var array<string, ArchitectureDriver>
     */
    private array $drivers = [];

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array{default_architecture:string,package_templates_path:string,override_templates_path?:string|null} $options
     */
    public function __construct(
        private readonly TemplateEngine $engine,
        private readonly Container $container,
        private readonly array $definitions,
        private readonly array $options,
    ) {
    }

    public function resolve(string $architecture): ArchitectureDriver
    {
        if (isset($this->drivers[$architecture])) {
            return $this->drivers[$architecture];
        }

        if (! isset($this->definitions[$architecture]['driver'])) {
            throw new InvalidArgumentException(sprintf('Architecture driver "%s" no está configurado.', $architecture));
        }

        $definition = $this->definitions[$architecture];
        $class = $definition['driver'];

        $options = $definition['options'] ?? [];
        $packagePath = rtrim($this->options['package_templates_path'], '\\/') . DIRECTORY_SEPARATOR . $architecture;
        $overrideBase = $this->options['override_templates_path'] ?? null;
        $overridePath = $overrideBase ? rtrim($overrideBase, '\\/') . DIRECTORY_SEPARATOR . $architecture : null;

        $options = array_merge([
            'package_path' => $packagePath,
            'override_path' => $overridePath,
        ], $options);

        $driver = $this->container->make($class, ['options' => $options]);

        if (! $driver instanceof ArchitectureDriver) {
            throw new InvalidArgumentException(sprintf('El driver "%s" debe implementar %s.', $class, ArchitectureDriver::class));
        }

        foreach ($driver->templateNamespaces() as $namespace => $paths) {
            $this->engine->registerNamespace($namespace, $paths);
        }

        return $this->drivers[$architecture] = $driver;
    }

    public function default(): ArchitectureDriver
    {
        return $this->resolve($this->options['default_architecture']);
    }

    /**
     * @return array<int, string>
     */
    public function available(): array
    {
        return array_keys($this->definitions);
    }

}
