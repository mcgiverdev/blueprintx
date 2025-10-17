<?php

namespace BlueprintX\Console\Commands;

use BlueprintX\Kernel\History\GenerationHistoryManager;
use Illuminate\Console\Command;

class RollbackBlueprintsCommand extends Command
{
    protected $signature = <<<SIGNATURE
    blueprintx:rollback
        {run? : ID de la corrida a revertir (por defecto usa la más reciente)}
        {--execution= : ID de ejecución (agrupa múltiples corridas generadas en la misma ejecución)}
        {--dry-run : Muestra las acciones sin aplicar cambios}
        {--force : Omite la confirmación manual}
SIGNATURE;

    protected $description = 'Revierte los archivos generados por blueprintx:generate usando el historial más reciente.';

    public function __construct(private readonly GenerationHistoryManager $history)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->history->isEnabled()) {
            $this->error('El historial de generación no está habilitado. Asegúrate de configurar "blueprintx.history.enabled" y "blueprintx.history.path".');

            return self::FAILURE;
        }

        $requestedRun = $this->normalizeNullableString($this->argument('run'));
        $requestedExecution = $this->normalizeNullableString($this->option('execution'));

        $targetRuns = $this->resolveTargetRuns($requestedRun, $requestedExecution);

        if ($targetRuns === []) {
            if ($requestedRun !== null) {
                $this->error(sprintf('No se encontró historial con ID "%s".', $requestedRun));
            } elseif ($requestedExecution !== null) {
                $this->error(sprintf('No se encontraron corridas para la ejecución "%s".', $requestedExecution));
            } else {
                $this->error('No hay ejecuciones registradas para revertir.');
            }

            $this->suggestAvailableRuns();

            return self::FAILURE;
        }

        $entries = $this->collectRollbackEntries($targetRuns);

        if ($entries === []) {
            $this->warn('La selección no tiene archivos para revertir.');

            return self::SUCCESS;
        }

        $this->renderSelectionInfo($targetRuns, $requestedRun, $requestedExecution);
        $this->renderPreviewTable($entries);

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->comment('Modo previsualización: no se realizaron cambios.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('¿Deseas revertir estos cambios?', true)) {
            $this->info('Operación cancelada.');

            return self::SUCCESS;
        }

        $results = [
            'restored' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($entries as $item) {
            $outcome = $this->rollbackEntry($item['entry'], $item['run_path']);

            $status = $outcome['status'];
            $detail = $outcome['detail'];

            match ($status) {
                'restored' => $results['restored']++,
                'deleted' => $results['deleted']++,
                'skipped' => $results['skipped']++,
                'error' => $results['errors']++,
                default => null,
            };

            $label = sprintf('[%s]', $item['label']);
            $this->line(sprintf('%s %s', $label, $detail));
        }

        $this->info(sprintf(
            'Resumen (%d corrida(s)): restaurados=%d, eliminados=%d, omitidos=%d, errores=%d',
            count($entries) > 0 ? count(array_unique(array_column($entries, 'run_id'))) : 0,
            $results['restored'],
            $results['deleted'],
            $results['skipped'],
            $results['errors'],
        ));

        if ($results['errors'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{status:string,detail:string}
     */
    private function rollbackEntry(array $entry, string $runPath): array
    {
        $status = (string) ($entry['status'] ?? '');
        $fullPath = isset($entry['full_path']) ? (string) $entry['full_path'] : '';

        if ($fullPath === '') {
            return [
                'status' => 'error',
                'detail' => sprintf('[error] Ruta no disponible para "%s".', $entry['path'] ?? 'desconocido'),
            ];
        }

        if ($status === 'written') {
            if (! is_file($fullPath)) {
                return [
                    'status' => 'skipped',
                    'detail' => sprintf('[omitido] "%s" ya no existe.', $fullPath),
                ];
            }

            if (@unlink($fullPath)) {
                return [
                    'status' => 'deleted',
                    'detail' => sprintf('[ok] Eliminado "%s".', $fullPath),
                ];
            }

            return [
                'status' => 'error',
                'detail' => sprintf('[error] No se pudo eliminar "%s".', $fullPath),
            ];
        }

        if ($status === 'overwritten') {
            $backupFile = isset($entry['backup']) ? (string) $entry['backup'] : '';

            if ($backupFile === '') {
                return [
                    'status' => 'error',
                    'detail' => sprintf('[error] No hay respaldo para "%s".', $fullPath),
                ];
            }

            $backupPath = $runPath . DIRECTORY_SEPARATOR . $backupFile;

            if (! is_file($backupPath)) {
                return [
                    'status' => 'error',
                    'detail' => sprintf('[error] Respaldo no encontrado "%s".', $backupPath),
                ];
            }

            $contents = file_get_contents($backupPath);

            if ($contents === false) {
                return [
                    'status' => 'error',
                    'detail' => sprintf('[error] No se pudo leer el respaldo "%s".', $backupPath),
                ];
            }

            $directory = dirname($fullPath);

            if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                return [
                    'status' => 'error',
                    'detail' => sprintf('[error] No se pudo crear el directorio "%s".', $directory),
                ];
            }

            if (file_put_contents($fullPath, $contents) === false) {
                return [
                    'status' => 'error',
                    'detail' => sprintf('[error] No se pudo restaurar "%s".', $fullPath),
                ];
            }

            return [
                'status' => 'restored',
                'detail' => sprintf('[ok] Restaurado "%s".', $fullPath),
            ];
        }

        return [
            'status' => 'skipped',
            'detail' => sprintf('[omitido] Estado "%s" no soportado.', $status),
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function suggestAvailableRuns(): void
    {
        $runs = $this->history->listRuns();

        if ($runs === []) {
            return;
        }

        $this->line('Historial disponible:');

        foreach (array_slice($runs, 0, 5) as $run) {
            $this->line(sprintf(
                '  - %s | exec=%s | blueprint=%s | %s',
                $run['id'],
                $run['manifest']['execution_id'] ?? '-',
                $run['manifest']['blueprint']['path'] ?? '-',
                $run['manifest']['timestamp'] ?? 'sin fecha'
            ));
        }

        if (count($runs) > 5) {
            $this->line(sprintf('  (total: %d ejecuciones)', count($runs)));
        }
    }

    /**
     * @return array<int, array{id:string,path:string,manifest:array<string,mixed>}>
     */
    private function resolveTargetRuns(?string $runId, ?string $executionId): array
    {
        if ($runId !== null) {
            $record = $this->history->getRun($runId);

            return $record === null ? [] : [$record];
        }

        $runs = $this->history->listRuns();

        if ($runs === []) {
            return [];
        }

        if ($executionId !== null) {
            $filtered = array_values(array_filter($runs, static function (array $run) use ($executionId): bool {
                return ($run['manifest']['execution_id'] ?? null) === $executionId;
            }));

            return $filtered;
        }

        $latest = $runs[0];
        $latestExecution = $latest['manifest']['execution_id'] ?? null;

        if ($latestExecution === null) {
            return [$latest];
        }

        $group = array_values(array_filter($runs, static function (array $run) use ($latestExecution): bool {
            return ($run['manifest']['execution_id'] ?? null) === $latestExecution;
        }));

        return $group === [] ? [$latest] : $group;
    }

    /**
     * @param array<int, array{id:string,path:string,manifest:array<string,mixed>}> $runs
     * @return array<int, array{entry:array<string,mixed>,run_id:string,run_path:string,label:string}>
     */
    private function collectRollbackEntries(array $runs): array
    {
        $entries = [];

        foreach ($runs as $run) {
            $candidateEntries = $run['manifest']['entries'] ?? [];

            if (! is_array($candidateEntries) || $candidateEntries === []) {
                continue;
            }

            foreach ($candidateEntries as $entry) {
                $status = $entry['status'] ?? null;

                if (! in_array($status, ['written', 'overwritten'], true)) {
                    continue;
                }

                $blueprint = $run['manifest']['blueprint']['path'] ?? $run['id'];

                $entries[] = [
                    'entry' => $entry,
                    'run_id' => $run['id'],
                    'run_path' => $run['path'],
                    'label' => $blueprint,
                ];
            }
        }

        return $entries;
    }

    private function renderSelectionInfo(array $runs, ?string $requestedRun, ?string $requestedExecution): void
    {
        if ($requestedRun !== null) {
            $run = $runs[0];
            $this->info(sprintf(
                'Corrida seleccionada: %s (blueprint=%s, fecha=%s)',
                $run['id'],
                $run['manifest']['blueprint']['path'] ?? '-',
                $run['manifest']['timestamp'] ?? 'sin fecha'
            ));

            return;
        }

        $executionId = $requestedExecution ?? ($runs[0]['manifest']['execution_id'] ?? null);
        $timestamp = $runs[0]['manifest']['timestamp'] ?? 'sin fecha';
        $count = count($runs);

        if ($executionId !== null) {
            $this->info(sprintf(
                'Ejecución seleccionada: %s (%d corrida(s), fecha=%s)',
                $executionId,
                $count,
                $timestamp
            ));
        } else {
            $this->info(sprintf(
                'Corrida seleccionada: %s (fecha=%s)',
                $runs[0]['id'],
                $timestamp
            ));
        }
    }

    /**
     * @param array<int, array{entry:array<string,mixed>,run_id:string,run_path:string,label:string}> $entries
     */
    private function renderPreviewTable(array $entries): void
    {
        $rows = [];

        foreach ($entries as $item) {
            $entry = $item['entry'];
            $status = $entry['status'] ?? '';
            $action = $status === 'written' ? 'Eliminar' : 'Restaurar';

            $rows[] = [
                $action,
                $item['label'],
                (string) ($entry['path'] ?? '-'),
                (string) ($entry['full_path'] ?? '-'),
            ];
        }

        $this->table(['Acción', 'Blueprint', 'Archivo', 'Ruta absoluta'], $rows);
    }
}
