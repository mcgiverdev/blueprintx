<?php

namespace BlueprintX\Kernel\Generation;

final class GenerationResult
{
    /**
     * @param GeneratedFile[] $files
     * @param array<int, string> $warnings
     */
    public function __construct(
        private array $files = [],
        private array $warnings = []
    ) {
    }

    public static function single(GeneratedFile $file): self
    {
        return new self([$file]);
    }

    public function addFile(GeneratedFile $file): void
    {
        $this->files[] = $file;
    }

    /**
     * @return GeneratedFile[]
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
