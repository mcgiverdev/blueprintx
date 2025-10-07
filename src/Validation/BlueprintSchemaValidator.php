<?php

namespace BlueprintX\Validation;

use BlueprintX\Exceptions\BlueprintValidationException;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Throwable;

class BlueprintSchemaValidator
{
    private ?object $schema = null;

    /**
     * @param string[] $architectures
     */
    public function __construct(
        private readonly string $schemaPath,
        private readonly array $architectures = []
    ) {
    }

    /**
     * @return ValidationMessage[]
     */
    public function validate(array $blueprintData): array
    {
    $schema = $this->loadSchema();
    $validator = new Validator();

    $payload = $this->preparePayload($blueprintData);
        $validator->validate($payload, $schema, Constraint::CHECK_MODE_APPLY_DEFAULTS);

        if ($validator->isValid()) {
            return [];
        }

        $messages = [];
        foreach ($validator->getErrors() as $error) {
            $property = $error['property'] ?? '';
            $path = $property !== '' ? $this->normalizePropertyPath($property) : null;
            $message = $error['message'] ?? 'Violación del schema';

            if ($property !== '') {
                $message = sprintf('%s: %s', $property, $message);
            }

            $messages[] = new ValidationMessage('schema.invalid', $message, $path);
        }

        return $this->uniqueMessages($messages);
    }

    private function preparePayload(array $blueprintData): object
    {
        if (! array_key_exists('docs', $blueprintData) || $blueprintData['docs'] === []) {
              $blueprintData['docs'] = new \stdClass();
        } else {
            $blueprintData['docs'] = $this->convertAssociativeArrays($blueprintData['docs']);
        }

        if (! array_key_exists('metadata', $blueprintData) || $blueprintData['metadata'] === []) {
            $blueprintData['metadata'] = new \stdClass();
        } else {
            $blueprintData['metadata'] = $this->convertAssociativeArrays($blueprintData['metadata']);
        }

        if (! array_key_exists('options', $blueprintData) || $blueprintData['options'] === []) {
            $blueprintData['options'] = new \stdClass();
        } else {
            $blueprintData['options'] = $this->convertAssociativeArrays($blueprintData['options']);
        }

        if (! array_key_exists('errors', $blueprintData) || $blueprintData['errors'] === []) {
            $blueprintData['errors'] = new \stdClass();
        } else {
            $blueprintData['errors'] = $this->convertAssociativeArrays($blueprintData['errors']);
        }

        return json_decode(json_encode($blueprintData, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private function convertAssociativeArrays(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->isAssociative($value)) {
            $object = new \stdClass();
            foreach ($value as $key => $item) {
                $object->{$key} = $this->convertAssociativeArrays($item);
            }

            return $object;
        }

        return array_map(fn ($item) => $this->convertAssociativeArrays($item), $value);
    }

    private function isAssociative(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function loadSchema(): object
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        if (! is_file($this->schemaPath)) {
            throw new BlueprintValidationException(sprintf('No se encontró el schema JSON en "%s".', $this->schemaPath));
        }

        $contents = file_get_contents($this->schemaPath);
        if ($contents === false) {
            throw new BlueprintValidationException(sprintf('No se pudo leer el schema JSON en "%s".', $this->schemaPath));
        }

        try {
            $data = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new BlueprintValidationException('El schema JSON está malformado.', 0, $exception);
        }

        if (isset($data->properties->architecture) && $this->architectures !== []) {
            $data->properties->architecture->enum = array_values($this->architectures);
        } elseif (isset($data->properties->architecture->enum)) {
            unset($data->properties->architecture->enum);
        }

        $this->schema = $data;

        return $this->schema;
    }

    private function normalizePropertyPath(string $property): string
    {
        $property = trim($property);
        if ($property === '') {
            return '';
        }

        $property = str_replace(['->', '/'], '.', $property);
        $parts = explode('.', $property);

        $path = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^\d+$/', $part) === 1) {
                $path .= '[' . $part . ']';
            } else {
                if ($path !== '') {
                    $path .= '.';
                }

                $path .= $part;
            }
        }

        return $path === '' ? $property : $path;
    }

    /**
     * @param ValidationMessage[] $messages
     * @return ValidationMessage[]
     */
    private function uniqueMessages(array $messages): array
    {
        $seen = [];
        $result = [];

        foreach ($messages as $message) {
            $key = $message->code . '|' . ($message->path ?? '') . '|' . $message->message;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $message;
        }

        return $result;
    }
}
