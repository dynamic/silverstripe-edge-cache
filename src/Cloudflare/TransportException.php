<?php

namespace Dynamic\EdgeCache\Cloudflare;

use RuntimeException;

/**
 * Cloudflare could not be reached (connection, timeout). Unlike an error answer, the outcome of a
 * write that ends this way is unknown.
 */
class TransportException extends RuntimeException
{
}
