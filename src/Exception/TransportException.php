<?php

declare(strict_types=1);

namespace OilPriceAPI\Exception;

/**
 * Thrown on network-level failures (DNS, connect, timeout, TLS) where no
 * HTTP response was received.
 */
final class TransportException extends ApiException
{
}
