<?php

namespace BlueprintX\Support\Http\Controllers\Concerns;

use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

trait HandlesDomainExceptions
{
    /**
     * @param array<int, mixed> $parameters
     * @return mixed
     */
    public function callAction($method, $parameters)
    {
        try {
            return parent::callAction($method, $parameters);
        } catch (DomainException $exception) {
            return $this->renderDomainException($exception);
        }
    }

    protected function renderDomainException(DomainException $exception): JsonResponse
    {
        $context = [
            'code' => $exception->errorCode(),
            'status' => $exception->statusCode(),
            'controller' => static::class,
        ];

        if ($exception->details() !== []) {
            $context['details'] = $exception->details();
        }

        Log::notice('Se capturó una DomainException durante la ejecución del controlador.', $context);

        return response()->json([
            'error' => $exception->toArray(),
        ], $exception->statusCode());
    }
}
