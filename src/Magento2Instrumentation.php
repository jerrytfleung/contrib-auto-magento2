<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Magento2;

use Http\Discovery\Psr17Factory;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\Http;
use Magento\Framework\App\Request\Http as HttpRequest;

use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Nyholm\Psr7Server\ServerRequestCreator;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

// @phan-file-suppress PhanUndeclaredClassReference
// @phan-file-suppress PhanUndeclaredTypeParameter
final class Magento2Instrumentation
{
    use LogsMessagesTrait;

    public const NAME = 'magento2';

    public static function register(): void
    {
        /** @var \OpenTelemetry\API\Metrics\HistogramInterface|null */
        static $histogram;

        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.magento2',
            null,
            'https://opentelemetry.io/schemas/1.32.0',
        );

        hook(
            Bootstrap::class,
            'terminate',
            pre: static function (Bootstrap $bootstrap, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $exception = $params[0] instanceof Throwable ? $params[0] : null;
                $span = $instrumentation->tracer()
                    ->spanBuilder('Bootstrap::terminate')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
                // end current span
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $currentSpan = Span::fromContext($scope->context());
                if ($exception) {
                    $currentSpan->recordException($exception);
                    $currentSpan->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $currentSpan->end();
            }
        );

        hook(
            Http::class,
            'launch',
            pre: static function (Http $http, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $factory = new Psr17Factory();
                $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
                $parent = Globals::propagator()->extract($request->getHeaders());
                $spanBuilder = $instrumentation->tracer()
                    ->spanBuilder(sprintf('%s %s', $request->getMethod(), self::getScriptNameFromRequest($request)))
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(TraceAttributes::URL_FULL, $request->getUri())
                    ->setAttribute(TraceAttributes::URL_SCHEME, $request->getUri()->getScheme())
                    ->setAttribute(TraceAttributes::URL_PATH, $request->getUri()->getPath())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                    ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                    ->setAttribute(TraceAttributes::SERVER_PORT, $request->getUri()->getPort() ?? ($request->getUri()->getScheme() === 'https' ? 443 : 80));

                foreach ($request->getHeaders() as $key => $value) {
                    $spanBuilder->setAttribute(TraceAttributes::HTTP_REQUEST_HEADER . '.' . $key, implode(',',$value));
                }

                $span = $spanBuilder->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (Http $http, array $params, ResultInterface|HttpResponse|null $response, ?Throwable $exception) use (&$histogram, $instrumentation) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());

                $responseMeta = [];

                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                    $responseMeta[TraceAttributes::ERROR_TYPE] = (string) $exception->getCode();
                }
                if ($response instanceof HttpResponse) {
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, strlen($response->getBody()));
                    foreach ($response->getHeaders()->toArray() as $key => $value) {
                        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_HEADER . '.' . $key, $value);
                    }
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_SIZE, strlen($response->toString()));
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                    $responseMeta[TraceAttributes::HTTP_RESPONSE_STATUS_CODE] = (string) $response->getStatusCode();
                    $prop = Globals::responsePropagator();
                    $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());
                }
                //https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#http-server
                $histogram ??= $instrumentation->meter()->createHistogram(
                    'http.server.request.duration',
                    's',
                    'Duration of HTTP server requests.',
                    ['ExplicitBucketBoundaries' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]]
                );
                if ($span instanceof ReadableSpanInterface) {
                    $spanAttrs = [
                        TraceAttributes::HTTP_REQUEST_METHOD => (string) $span->getAttribute(TraceAttributes::HTTP_REQUEST_METHOD),
                        TraceAttributes::URL_SCHEME => (string) $span->getAttribute(TraceAttributes::URL_SCHEME),
                        TraceAttributes::NETWORK_PROTOCOL_VERSION => (string) $span->getAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION),
                    ];
                    $histogram->record($span->getDuration() / ClockInterface::NANOS_PER_SECOND, array_merge($spanAttrs, $responseMeta));
                }
                $span->end();
            }
        );

        /** @psalm-suppress UndefinedClass */
        hook(
            FrontController::class,
            'dispatch',
            /** @psalm-suppress UndefinedClass */
            pre: static function (FrontController $frontController, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = $instrumentation->tracer()
                    ->spanBuilder('FrontController.dispatch')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            /** @psalm-suppress UndefinedClass */
            post: static function (FrontController $frontController, array $params, ResponseInterface|ResultInterface|null $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );

        hook(
            Action::class,
            'dispatch',
            pre: static function (Action $action, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $request = $params[0] instanceof HttpRequest ? $params[0] : null;
                $span = $instrumentation->tracer()
                    ->spanBuilder($request?->getFullActionName() ?? 'unknown')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (Action $action, array $params, ResponseInterface|ResultInterface|null $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );

        hook(
            ActionInterface::class,
            'execute',
            pre: static function (ActionInterface $actionInterface, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = $instrumentation->tracer()
                    ->spanBuilder('ActionInterface.execute')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (ActionInterface $action, array $params, ResponseInterface|ResultInterface|null $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );
    }

    private static function getScriptNameFromRequest(ServerRequestInterface $request): string
    {
        return $request->getServerParams()['SCRIPT_NAME'] ?? '/';
    }
}
