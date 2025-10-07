<?php

namespace BlueprintX\Support\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;

trait FormatsPagination
{
    /**
     * @return array<string, mixed>
     */
    protected function formatPaginatedResponse(Paginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => $this->buildMeta($paginator),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function buildMeta(Paginator $paginator): array
    {
        $meta = [
            'current_page' => $paginator->currentPage(),
            'from' => $this->resolveFromItem($paginator),
            'per_page' => $paginator->perPage(),
            'to' => $this->resolveToItem($paginator),
        ];

        if ($paginator instanceof LengthAwarePaginator) {
            $meta['last_page'] = $this->resolveLastPage($paginator);
            $meta['total'] = $this->resolveTotal($paginator);
        }

        return array_filter(
            $meta,
            static fn ($value): bool => $value !== null
        );
    }

    private function resolveFromItem(Paginator $paginator): ?int
    {
        $from = $paginator->firstItem();

        if ($from !== null) {
            return $from;
        }

        if (count($paginator->items()) === 0) {
            return null;
        }

        return (($paginator->currentPage() - 1) * $paginator->perPage()) + 1;
    }

    private function resolveToItem(Paginator $paginator): ?int
    {
        $to = $paginator->lastItem();

        if ($to !== null) {
            return $to;
        }

        if (count($paginator->items()) === 0) {
            return null;
        }

        return $this->resolveFromItem($paginator) + count($paginator->items()) - 1;
    }

    private function resolveLastPage(Paginator $paginator): ?int
    {
        if ($paginator instanceof LengthAwarePaginator) {
            return $paginator->lastPage();
        }

        return null;
    }

    private function resolveTotal(Paginator $paginator): ?int
    {
        if ($paginator instanceof LengthAwarePaginator) {
            return $paginator->total();
        }

        return null;
    }
}
