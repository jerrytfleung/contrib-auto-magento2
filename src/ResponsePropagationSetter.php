<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Magento2;

use function assert;
use Magento\Framework\App\Response\Http as HttpResponse;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;

final class ResponsePropagationSetter implements PropagationSetterInterface
{
    public static function instance(): self
    {
        static $instance;

        return $instance ??= new self();
    }

    #[\Override]
    public function set(&$carrier, string $key, string $value): void
    {
        assert($carrier instanceof HttpResponse);

        $carrier = $carrier->setHeader($key, $value);
    }
}
