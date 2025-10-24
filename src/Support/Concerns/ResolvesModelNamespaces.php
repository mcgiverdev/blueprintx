<?php

namespace BlueprintX\Support\Concerns;

use BlueprintX\Blueprint\Blueprint;
use Illuminate\Support\Str;

trait ResolvesModelNamespaces
{
	/**
	 * @return array{entity:?string,module:?string}
	 */
	private function parseRelationTarget(?string $target): array
	{
		if (! is_string($target)) {
			return ['entity' => null, 'module' => null];
		}

		$normalized = trim($target);

		if ($normalized === '') {
			return ['entity' => null, 'module' => null];
		}

		$normalized = str_replace('\\', '/', $normalized);

		$segments = array_values(array_filter(
			array_map('trim', explode('/', $normalized)),
			static fn (string $segment): bool => $segment !== ''
		));

		if ($segments === []) {
			return ['entity' => null, 'module' => null];
		}

		$entitySegment = array_pop($segments);

		if ($entitySegment === null || $entitySegment === '') {
			return ['entity' => null, 'module' => null];
		}

		$entity = Str::studly($entitySegment);

		$module = null;

		if ($segments !== []) {
			$module = implode('\\', array_map(
				static fn (string $segment): string => Str::studly($segment),
				$segments
			));
		}

		if ($entity === '') {
			$entity = null;
		}

		if ($module === '') {
			$module = null;
		}

		return [
			'entity' => $entity,
			'module' => $module,
		];
	}

	private function resolveModelNamespace(
		Blueprint $blueprint,
		string $relatedEntity,
		?string $explicitModule,
		?string $domainModelsNamespace,
		?string $sharedRootNamespace
	): string {
		$sharedRoot = $sharedRootNamespace !== null
			? trim($sharedRootNamespace, '\\')
			: 'App\\Domain';

		$domainNamespace = $domainModelsNamespace !== null
			? trim($domainModelsNamespace, '\\')
			: null;

		$module = $this->normalizeModule($explicitModule);

		if ($module === null) {
			$module = $this->defaultModuleForEntity($blueprint, $relatedEntity);
		}

		if ($module === null) {
			if ($domainNamespace !== null) {
				return $domainNamespace;
			}

			$currentModule = $this->normalizeModule($blueprint->module());

			if ($currentModule !== null) {
				return $sharedRoot . '\\' . $currentModule . '\\Models';
			}

			return $sharedRoot . '\\Models';
		}

		return $sharedRoot . '\\' . $module . '\\Models';
	}

	private function resolveRelatedModelFqcn(
		Blueprint $blueprint,
		string $relatedEntity,
		?string $explicitModule,
		?string $domainModelsNamespace,
		?string $sharedRootNamespace
	): string {
		$namespace = $this->resolveModelNamespace(
			$blueprint,
			$relatedEntity,
			$explicitModule,
			$domainModelsNamespace,
			$sharedRootNamespace
		);

		return trim($namespace, '\\') . '\\' . Str::studly($relatedEntity);
	}

	/**
	 * @return array<int, string>
	 */
	private function moduleSegments(Blueprint $blueprint): array
	{
		$normalized = $this->normalizeModule($blueprint->module());

		if ($normalized === null) {
			return [];
		}

		return explode('\\', $normalized);
	}

	private function normalizeModule(?string $module): ?string
	{
		if (! is_string($module)) {
			return null;
		}

		$trimmed = trim($module);

		if ($trimmed === '') {
			return null;
		}

		$trimmed = str_replace(['App\\Domain\\', 'App/Domain/'], '', $trimmed);
		$trimmed = trim($trimmed, '\\/');

		if ($trimmed === '') {
			return null;
		}

		$segments = preg_split('#[\\\\/]+#', $trimmed) ?: [];

		$segments = array_values(array_filter(
			array_map(static fn ($segment): string => trim((string) $segment), $segments),
			static fn (string $segment): bool => $segment !== '' && Str::lower($segment) !== 'models'
		));

		if ($segments === []) {
			return null;
		}

		$studly = array_map(static fn (string $segment): string => Str::studly($segment), $segments);

		return implode('\\', $studly);
	}

	private function defaultModuleForEntity(Blueprint $blueprint, string $entity): ?string
	{
		$entityName = Str::lower($entity);
		$segments = $this->moduleSegments($blueprint);
		$root = Str::lower($segments[0] ?? '');
		$overrides = [
			'central' => [
				'user' => 'Central\\Auth',
				'company' => 'Central\\Hr',
				'tenant' => 'Central\\Tenancy',
			],
			'shared' => [
				'user' => 'Central\\Auth',
				'company' => 'Central\\Hr',
				'tenant' => 'Central\\Tenancy',
			],
			'tenant' => [
				'user' => 'Tenant\\Auth',
				'company' => 'Central\\Hr',
				'tenant' => 'Central\\Tenancy',
				'jobposition' => 'Shared\\Hr',
			],
		];

		if (isset($overrides[$root][$entityName])) {
			return $overrides[$root][$entityName];
		}

		if ($entityName === 'jobposition' && $root !== 'tenant') {
			return 'Shared\\Hr';
		}

		if ($entityName === 'user') {
			return 'Central\\Auth';
		}

		return null;
	}
}
