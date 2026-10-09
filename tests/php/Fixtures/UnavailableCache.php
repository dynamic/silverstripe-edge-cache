<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * A cache whose backend is down: every call throws.
 */
class UnavailableCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        throw new RuntimeException('cache down');
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        throw new RuntimeException('cache down');
    }

    public function delete(string $key): bool
    {
        throw new RuntimeException('cache down');
    }

    public function clear(): bool
    {
        throw new RuntimeException('cache down');
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        throw new RuntimeException('cache down');
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        throw new RuntimeException('cache down');
    }

    public function deleteMultiple(iterable $keys): bool
    {
        throw new RuntimeException('cache down');
    }

    public function has(string $key): bool
    {
        throw new RuntimeException('cache down');
    }
}
