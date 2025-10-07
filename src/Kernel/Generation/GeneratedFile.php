<?php

namespace BlueprintX\Kernel\Generation;

class GeneratedFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $contents,
        public readonly bool $overwrite = false
    ) {
    }
}
