<?php

namespace BlueprintX\Kernel;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Contracts\LayerGenerator;
use BlueprintX\Kernel\Generation\GenerationResult;
use BlueprintX\Kernel\Generation\PipelineResult;
use Illuminate\Support\Str;

class GenerationPipeline
{
    /**
     * @param array<string, LayerGenerator> $generators
     */
    public function __construct(
        private readonly DriverManager $drivers,
        private readonly OutputWriter $writer,
        private array $generators = [],
        private array $beforeHooks = [],
        private array $afterHooks = []
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(Blueprint $blueprint, array $options = []): PipelineResult
    {
        $architecture = $blueprint->architecture();
        $driver = $this->drivers->resolve($architecture);

        $only = $options['only'] ?? null;
        if (is_string($only)) {
            $only = array_filter(array_map('trim', explode(',', $only)));
        }
        if (is_array($only)) {
            $only = array_map(static fn (string $layer): string => Str::lower($layer), $only);
        }

        $layers = $driver->layers();
        if (is_array($only) && $only !== []) {
            $layers = array_values(array_filter($layers, static fn (string $layer): bool => in_array(Str::lower($layer), $only, true)));
        }

        $result = new PipelineResult();

        foreach ($layers as $layer) {
            $generator = $this->generators[Str::lower($layer)] ?? null;

            if (! $generator instanceof LayerGenerator) {
                $result->addWarning(sprintf('No se encontró generador para la capa "%s".', $layer));
                continue;
            }

            $this->runBeforeHooks($layer, $blueprint, $driver, $options);
            $generation = $generator->generate($blueprint, $driver, $options);
            $this->handleGenerationResult($generation, $result, $layer, $options);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function handleGenerationResult(GenerationResult $generation, PipelineResult $result, string $layer, array $options): void
    {
        foreach ($generation->warnings() as $warning) {
            $result->addWarning($warning);
        }

        $writeResults = $this->writer->writeFiles($generation->files(), $options);

        foreach ($writeResults as $record) {
            $record['layer'] = $layer;
            $result->addFile($record);
        }

        $this->runAfterHooks($layer, $generation, $result, $options);
    }

    public function registerGenerator(LayerGenerator $generator): void
    {
        $this->generators[Str::lower($generator->layer())] = $generator;
    }

    public function registerBeforeHook(string $layer, callable $callback): void
    {
        $key = $this->normalizeLayerKey($layer);
        $this->beforeHooks[$key][] = $callback;
    }

    public function registerAfterHook(string $layer, callable $callback): void
    {
        $key = $this->normalizeLayerKey($layer);
        $this->afterHooks[$key][] = $callback;
    }

    private function runBeforeHooks(string $layer, Blueprint $blueprint, ArchitectureDriver $driver, array $options): void
    {
        foreach ($this->hooksFor($this->beforeHooks, $layer) as $hook) {
            $hook($blueprint, $driver, $layer, $options);
        }
    }

    private function runAfterHooks(string $layer, GenerationResult $generation, PipelineResult $pipeline, array $options): void
    {
        foreach ($this->hooksFor($this->afterHooks, $layer) as $hook) {
            $hook($generation, $pipeline, $layer, $options);
        }
    }

    /**
     * @param array<string, array<int, callable>> $collection
     * @return array<int, callable>
     */
    private function hooksFor(array $collection, string $layer): array
    {
        $key = Str::lower($layer);

        return array_merge($collection['*'] ?? [], $collection[$key] ?? []);
    }

    private function normalizeLayerKey(string $layer): string
    {
        return $layer === '*' ? '*' : Str::lower($layer);
    }
}
