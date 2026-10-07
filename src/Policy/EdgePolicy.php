<?php

namespace Dynamic\EdgeCache\Policy;

/**
 * How long the edge may keep a page, in seconds. Immutable; built from config by EdgeCache.
 */
class EdgePolicy
{
    public function __construct(
        private int $edgeTtl,
        private int $staleWhileRevalidate = 0,
        private int $staleIfError = 0
    ) {
    }

    public function getEdgeTtl(): int
    {
        return $this->edgeTtl;
    }

    public function getStaleWhileRevalidate(): int
    {
        return $this->staleWhileRevalidate;
    }

    public function getStaleIfError(): int
    {
        return $this->staleIfError;
    }
}
