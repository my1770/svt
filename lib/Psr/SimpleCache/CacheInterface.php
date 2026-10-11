<?php

namespace Psr\SimpleCache;

use DateInterval;
use Traversable;

interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool;
    public function delete(string $key): bool;
    public function clear(): bool;
    /**
     * @param iterable<string> $keys
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable;
    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool;
    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool;
    public function has(string $key): bool;
}
