<?php

namespace BlueprintX\Kernel\Generation;

final class PipelineResult
{
    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, string> $warnings
     */
    public function __construct(
        private array $files = [],
        private array $warnings = []
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function addFile(array $file): void
    {
        $this->files[] = $file;
    }

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    public function merge(self $other): self
    {
        $this->files = array_merge($this->files, $other->files());
        $this->warnings = array_merge($this->warnings, $other->warnings());

        return $this;
    }
}
