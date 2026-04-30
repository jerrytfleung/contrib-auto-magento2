<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Magento2;

use function assert;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use Magento\Framework\App\Response\Http as HttpResponse;

final class ResponsePropagationSetter implements PropagationSetterInterface
{
    public static function instance(): self
    {
        static $instance;

        return $instance ??= new self();
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function keys($carrier): array
    {
        assert($carrier instanceof HttpResponse);

        return array_keys($carrier->getHeaders()->toArray());
    }

    public function set(&$carrier, string $key, string $value): void
    {
        assert($carrier instanceof HttpResponse);

        $carrier = $carrier->setHeader($key, $value);
    }
}