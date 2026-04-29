<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Magento2;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use Throwable;

// @phan-file-suppress PhanUndeclaredClassReference
// @phan-file-suppress PhanUndeclaredTypeParameter
final class Magento2Instrumentation
{
    use LogsMessagesTrait;

    public const NAME = 'magento2';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.magento2',
            null,
            'https://opentelemetry.io/schemas/1.32.0',
        );

        hook(
            Http::class,
            'launch',
            pre: static function (Http $http, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $builder = $instrumentation->tracer()
                    ->spanBuilder('launch')
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (Http $http, array $params, ?ResponseInterface $response, ?Throwable $exception) {
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

        /** @psalm-suppress UndefinedClass */
        hook(
            FrontController::class,
            'dispatch',
            /** @psalm-suppress UndefinedClass */
            pre: static function (FrontController $frontController, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $requestInterface = ($params[0] instanceof RequestInterface) ? $params[0] : null;
                self::logInfo($requestInterface?->getModuleName() ?? 'Unknown module');
                self::logInfo($requestInterface?->getActionName() ?? 'Unknown action');
                $params = $requestInterface?->getParams() ?? [];
                self::logInfo(implode(',', array_map(
                    fn ($k, $v) => "$k=$v",
                    array_keys($params),
                    $params
                )));

                //
                //                        $moduleName = 'unknown';
                //                        if (is_object($requestInterface) && method_exists($requestInterface, 'getModuleName')) {
                //                            /** @phan-suppress-next-line PhanUndeclaredClassMethod */
                //                            $moduleNameValue = $requestInterface->getModuleName();
                //                            if (is_string($moduleNameValue) && $moduleNameValue !== '') {
                //                                $moduleName = $moduleNameValue;
                //                            }
                //                        }

                $builder = $instrumentation->tracer()
                    ->spanBuilder('dispatch')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                $parent = Context::getCurrent();
                //                        if ($requestInterface) {
                //                            // $parent = Globals::propagator()->extract($requestInterface->getHeaders());
                //                            $span = $builder
                ////                                ->setParent($parent)
                ////                                ->setAttribute(UrlAttributes::URL_FULL, $request->getUri()->__toString())
                ////                                ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                ////                                ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                ////                                ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                ////                                ->setAttribute(ServerAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                ////                                ->setAttribute(ServerAttributes::SERVER_PORT, $request->getUri()->getPort())
                ////                                ->setAttribute(UrlAttributes::URL_SCHEME, $request->getUri()->getScheme())
                ////                                ->setAttribute(UrlAttributes::URL_PATH, $request->getUri()->getPath())
                //                                ->startSpan();
                //                            // $request = $request->withAttribute(SpanInterface::class, $span);
                //                        } else {
                $span = $builder->startSpan();
                // }
                Context::storage()->attach($span->storeInContext($parent));
            },
            /** @psalm-suppress UndefinedClass */
            post: static function (FrontController $frontController, array $params, ResponseInterface $response, ?Throwable $exception) {
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
            FrontController::class,
            'processRequest',
            pre: static function (FrontController $frontController, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $builder = $instrumentation->tracer()
                    ->spanBuilder('processRequest')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (FrontController $frontController, array $params, ResponseInterface $response, ?Throwable $exception) {
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
            FrontController::class,
            'getActionResponse',
            pre: static function (FrontController $frontController, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $builder = $instrumentation->tracer()
                    ->spanBuilder('getActionResponse')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (FrontController $frontController, array $params, ResponseInterface|ResultInterface $response, ?Throwable $exception) {
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

//        hook(
//            ActionInterface::class,
//            'execute',
//            pre: static function (ActionInterface $actionInterface, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
//                $builder = $instrumentation->tracer()
//                    ->spanBuilder('execute')
//                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
//                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
//                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
//                $parent = Context::getCurrent();
//                $span = $builder->startSpan();
//                Context::storage()->attach($span->storeInContext($parent));
//            },
//            post: static function (ActionInterface $actionInterface, array $params, ResultInterface|ResponseInterface $response, ?Throwable $exception) {
//                $scope = Context::storage()->scope();
//                if (!$scope) {
//                    return;
//                }
//                $scope->detach();
//                $span = Span::fromContext($scope->context());
//                if ($exception) {
//                    $span->recordException($exception);
//                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
//                }
//                $span->end();
//            }
//        );
    }
}
