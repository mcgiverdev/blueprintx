<?php

namespace BlueprintX\Generators;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Blueprint\Endpoint;
use BlueprintX\Blueprint\Field;
use BlueprintX\Blueprint\Relation;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Contracts\LayerGenerator;
use BlueprintX\Kernel\Generation\GeneratedFile;
use BlueprintX\Kernel\Generation\GenerationResult;
use BlueprintX\Kernel\TemplateEngine;
use Illuminate\Support\Str;

class ApplicationLayerGenerator implements LayerGenerator
{
    public function __construct(private readonly TemplateEngine $templates)
    {
    }

    public function layer(): string
    {
        return 'application';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(Blueprint $blueprint, ArchitectureDriver $driver, array $options = []): GenerationResult
    {
        $result = new GenerationResult();

        $context = $this->buildContext($blueprint, $driver, $options);
        $paths = $this->derivePaths($blueprint, $options);

        $templates = [
            [
                'template' => sprintf('@%s/application/commands/create.stub.twig', $driver->name()),
                'path' => $paths['create_command'],
            ],
            [
                'template' => sprintf('@%s/application/commands/update.stub.twig', $driver->name()),
                'path' => $paths['update_command'],
            ],
            [
                'template' => sprintf('@%s/application/commands/delete.stub.twig', $driver->name()),
                'path' => $paths['delete_command'],
            ],
            [
                'template' => sprintf('@%s/application/queries/list.stub.twig', $driver->name()),
                'path' => $paths['list_query'],
            ],
            [
                'template' => sprintf('@%s/application/queries/show.stub.twig', $driver->name()),
                'path' => $paths['show_query'],
            ],
            [
                'template' => sprintf('@%s/application/queries/filter.stub.twig', $driver->name()),
                'path' => $paths['filter'],
            ],
        ];

        foreach ($templates as $item) {
            if (! $this->templates->exists($item['template'])) {
                $result->addWarning(sprintf('No se encontró la plantilla "%s" para la capa application en "%s".', $item['template'], $driver->name()));
                continue;
            }

            $result->addFile(new GeneratedFile(
                $item['path'],
                $this->templates->render($item['template'], $context)
            ));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildContext(Blueprint $blueprint, ArchitectureDriver $driver, array $options): array
    {
        $entity = [
            'name' => $blueprint->entity(),
            'module' => $blueprint->module(),
            'table' => $blueprint->table(),
            'fields' => array_map(static fn (Field $field): array => $field->toArray(), $blueprint->fields()),
            'relations' => array_map(static fn (Relation $relation): array => $relation->toArray(), $blueprint->relations()),
            'endpoints' => array_map(static fn (Endpoint $endpoint): array => $endpoint->toArray(), $blueprint->endpoints()),
            'options' => $blueprint->options(),
        ];

        $namespaces = $this->deriveNamespaces($blueprint, $options);

        return [
            'blueprint' => $blueprint->toArray(),
            'entity' => $entity,
            'module' => $this->moduleSegment($blueprint),
            'namespaces' => $namespaces,
            'naming' => $this->namingContext($blueprint),
            'domain' => $this->deriveDomainNamespaces($blueprint, $options),
            'filter' => $this->deriveFilterContext($blueprint),
            'driver' => [
                'name' => $driver->name(),
                'layers' => $driver->layers(),
                'metadata' => $driver->metadata(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function deriveNamespaces(Blueprint $blueprint, array $options): array
    {
        $base = trim($options['namespaces']['application'] ?? 'App\\Application', '\\');
        $module = $this->moduleSegment($blueprint);
        $root = $base;

        if ($module !== null) {
            $root .= '\\' . $module;
        }

        return [
            'application_root' => $root,
            'commands' => $root . '\\Commands',
            'queries' => $root . '\\Queries',
            'filters' => $root . '\\Queries\\Filters',
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function derivePaths(Blueprint $blueprint, array $options): array
    {
        $basePath = rtrim($options['paths']['application'] ?? 'app/Application', '/');
        $module = $this->moduleSegment($blueprint);
        $entityName = Str::studly($blueprint->entity());
        $entityPlural = Str::pluralStudly($entityName);

        $root = $basePath;

        if ($module !== null) {
            $root .= '/' . $module;
        }

        return [
            'create_command' => sprintf('%s/Commands/Create%sCommand.php', $root, $entityName),
            'update_command' => sprintf('%s/Commands/Update%sCommand.php', $root, $entityName),
            'delete_command' => sprintf('%s/Commands/Delete%sCommand.php', $root, $entityName),
            'list_query' => sprintf('%s/Queries/List%sQuery.php', $root, $entityPlural),
            'show_query' => sprintf('%s/Queries/Show%sQuery.php', $root, $entityName),
            'filter' => sprintf('%s/Queries/Filters/%sFilter.php', $root, $entityName),
        ];
    }

    private function namingContext(Blueprint $blueprint): array
    {
        $entityName = Str::studly($blueprint->entity());
        $entityPlural = Str::pluralStudly($entityName);

        return [
            'entity_studly' => $entityName,
            'entity_plural_studly' => $entityPlural,
            'entity_variable' => Str::camel($blueprint->entity()),
            'entity_plural_variable' => Str::camel(Str::plural($blueprint->entity())),
        ];
    }

    private function deriveFilterContext(Blueprint $blueprint): array
    {
        $allowedIncludes = [];

        foreach ($blueprint->relations() as $relation) {
            $type = strtolower($relation->type);
            $target = $relation->target;

            if ($target === null || $target === '') {
                continue;
            }

            if (in_array($type, ['belongsto', 'hasone'], true)) {
                $name = Str::camel($target);
            } else {
                $name = Str::camel(Str::plural($target));
            }

            if ($name !== '' && ! in_array($name, $allowedIncludes, true)) {
                $allowedIncludes[] = $name;
            }
        }

        $sortable = [];

        foreach ($blueprint->fields() as $field) {
            $type = strtolower($field->type);
            $name = $field->name;

            if (in_array($type, ['json', 'array', 'object'], true)) {
                continue;
            }

            if (! in_array($name, $sortable, true)) {
                $sortable[] = $name;
            }
        }

        $options = $blueprint->options();
        $timestampsEnabled = (bool) ($options['timestamps'] ?? true);

        if ($timestampsEnabled) {
            foreach (['created_at', 'updated_at'] as $column) {
                if (! in_array($column, $sortable, true)) {
                    $sortable[] = $column;
                }
            }
        }

        $searchable = [];

        foreach ($blueprint->endpoints() as $endpoint) {
            if (strtolower($endpoint->type) !== 'search') {
                continue;
            }

            foreach ($endpoint->fields as $field) {
                if (! is_string($field) || $field === '') {
                    continue;
                }

                if (! in_array($field, $searchable, true)) {
                    $searchable[] = $field;
                }
            }
        }

        if ($searchable === []) {
            foreach ($blueprint->fields() as $field) {
                if (! in_array(strtolower($field->type), ['string', 'text'], true)) {
                    continue;
                }

                if (! in_array($field->name, $searchable, true)) {
                    $searchable[] = $field->name;
                }
            }
        }

        $defaultSortColumn = $sortable[0] ?? 'created_at';

        return [
            'allowed_includes' => $allowedIncludes,
            'allowed_sorts' => $sortable,
            'searchable' => $searchable,
            'default_sort' => [
                'column' => $defaultSortColumn,
                'direction' => 'asc',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function deriveDomainNamespaces(Blueprint $blueprint, array $options): array
    {
        $base = trim($options['namespaces']['domain'] ?? 'App\\Domain', '\\');
        $module = $this->moduleSegment($blueprint);
        $root = $base;

        if ($module !== null) {
            $root .= '\\' . $module;
        }

        return [
            'root' => $root,
            'models' => $root . '\\Models',
            'repositories' => $root . '\\Repositories',
            'shared_exceptions' => trim($options['namespaces']['domain_shared_exceptions'] ?? 'App\\Domain\\Shared\\Exceptions', '\\'),
        ];
    }

    private function moduleSegment(Blueprint $blueprint): ?string
    {
        $module = $blueprint->module();

        if ($module === null || $module === '') {
            return null;
        }

        return Str::studly($module);
    }
}
