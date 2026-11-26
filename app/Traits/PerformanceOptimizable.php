<?php

namespace App\Traits;

trait PerformanceOptimizable
{
    /**
     * Process large dataset in chunks.
     */
    protected function processInChunks($query, callable $callback, int $chunkSize = 500): void
    {
        $query->chunk($chunkSize, function ($items) use ($callback) {
            $callback($items);
        });
    }

    /**
     * Process large dataset using cursor for memory efficiency.
     */
    protected function processWithCursor($query, callable $callback): void
    {
        foreach ($query->cursor() as $item) {
            $callback($item);
        }
    }

    /**
     * Optimize collection by removing unnecessary data.
     */
    protected function optimizeCollection($collection, array $fields): \Illuminate\Support\Collection
    {
        return $collection->map(function ($item) use ($fields) {
            return $item->only($fields);
        });
    }

    /**
     * Lazy load relationships only when needed.
     */
    protected function lazyLoadRelations($model, array $relations): void
    {
        foreach ($relations as $relation) {
            if (!$model->relationLoaded($relation)) {
                $model->load($relation);
            }
        }
    }

    /**
     * Eager load relationships to prevent N+1 queries.
     */
    protected function eagerLoadRelations($query, array $relations)
    {
        return $query->with($relations);
    }

    /**
     * Use exists() instead of count() for existence checks.
     */
    protected function recordExists($query): bool
    {
        return $query->exists();
    }

    /**
     * Use pluck() for single column retrieval.
     */
    protected function pluckColumn($query, string $column, string $key = null)
    {
        return $query->pluck($column, $key);
    }

    /**
     * Use value() for single value retrieval.
     */
    protected function getSingleValue($query, string $column)
    {
        return $query->value($column);
    }

    /**
     * Batch insert for better performance.
     */
    protected function batchInsert(string $table, array $data, int $chunkSize = 500): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($table, $data, $chunkSize) {
            collect($data)->chunk($chunkSize)->each(function ($chunk) use ($table) {
                \Illuminate\Support\Facades\DB::table($table)->insert($chunk->toArray());
            });
            return true;
        });
    }

    /**
     * Batch update for better performance.
     */
    protected function batchUpdate(string $table, array $data, string $key = 'id'): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($table, $data, $key) {
            collect($data)->chunk(500)->each(function ($chunk) use ($table, $key) {
                foreach ($chunk as $item) {
                    \Illuminate\Support\Facades\DB::table($table)
                        ->where($key, $item[$key])
                        ->update($item);
                }
            });
            return true;
        });
    }

    /**
     * Cache query results for performance.
     */
    protected function cacheQuery($query, string $key, int $ttl = 3600)
    {
        return \Illuminate\Support\Facades\Cache::remember($key, $ttl, function () use ($query) {
            return $query->get();
        });
    }

    /**
     * Clear memory for large operations.
     */
    protected function clearMemory(): void
    {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
}
