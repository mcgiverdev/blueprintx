<?php

namespace BlueprintX\Kernel;

use Illuminate\Support\Str;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

class TemplateEngine
{
    private FilesystemLoader $loader;

    private Environment $twig;

    /**
     * @param array<int, string> $basePaths
     * @param array<string, mixed> $options
     */
    public function __construct(array $basePaths = [], array $options = [])
    {
        $this->loader = new FilesystemLoader();

        foreach ($basePaths as $path) {
            if (is_string($path) && is_dir($path)) {
                $this->loader->addPath($path);
            }
        }

        $environmentOptions = [
            'cache' => $options['cache'] ?? false,
            'debug' => (bool) ($options['debug'] ?? false),
            'autoescape' => false,
            'strict_variables' => false,
            'auto_reload' => (bool) ($options['auto_reload'] ?? true),
        ];

        $this->twig = new Environment($this->loader, $environmentOptions);

        if (! empty($environmentOptions['debug'])) {
            $this->twig->addExtension(new DebugExtension());
        }

        $this->registerFilters();
    }

    /**
     * @param array<int, string> $paths
     */
    public function registerNamespace(string $namespace, array $paths): void
    {
        $validPaths = array_values(array_filter($paths, static fn ($path): bool => is_string($path) && is_dir($path)));

        if ($validPaths === []) {
            return;
        }

        $this->loader->setPaths($validPaths, $namespace);
    }

    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }

    public function exists(string $template): bool
    {
        return $this->loader->exists($template);
    }

    public function environment(): Environment
    {
        return $this->twig;
    }

    private function registerFilters(): void
    {
        $filters = [
            new TwigFilter('studly', static fn (string $value): string => Str::studly($value)),
            new TwigFilter('camel', static fn (string $value): string => Str::camel($value)),
            new TwigFilter('snake', static fn (string $value, string $delimiter = '_'): string => Str::snake($value, $delimiter)),
            new TwigFilter('kebab', static fn (string $value): string => Str::kebab($value)),
            new TwigFilter('plural', static fn (string $value): string => Str::plural($value)),
            new TwigFilter('singular', static fn (string $value): string => Str::singular($value)),
            new TwigFilter('namespace_path', static fn (string $value): string => str_replace('\\', DIRECTORY_SEPARATOR, trim($value, '\\'))),
            new TwigFilter('class_basename', static fn (string $value): string => class_basename($value)),
        ];

        foreach ($filters as $filter) {
            $this->twig->addFilter($filter);
        }
    }
}
