<?php

namespace BlueprintX\Contracts;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Exceptions\BlueprintParseException;

interface BlueprintParser
{
    /**
     * @throws BlueprintParseException
     */
    public function parse(string $path): Blueprint;

    /**
     * @param iterable<string> $paths
     *
     * @return array<int, Blueprint>
     *
     * @throws BlueprintParseException
     */
    public function parseMany(iterable $paths): array;
}
