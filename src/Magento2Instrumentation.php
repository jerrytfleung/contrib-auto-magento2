<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Magento2;

use Http\Discovery\Psr17Factory;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\Http;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Framework\App\View;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Event\Observer;
use Nyholm\Psr7Server\ServerRequestCreator;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
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
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.magento2',
            null,
            'https://opentelemetry.io/schemas/1.32.0',
        );

        hook(
            Bootstrap::class,
            'run',
            pre: static function (Bootstrap $bootstrap, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $factory = new Psr17Factory();
                $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
                $parent = Globals::propagator()->extract($request->getHeaders());
                $span = $instrumentation->tracer()
                    ->spanBuilder(sprintf('%s %s', $request->getMethod(), self::getScriptNameFromRequest($request)))
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(TraceAttributes::URL_FULL, (string) $request->getUri())
                    ->setAttribute(TraceAttributes::URL_SCHEME, $request->getUri()->getScheme())
                    ->setAttribute(TraceAttributes::URL_PATH, $request->getUri()->getPath())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                    ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                    ->setAttribute(TraceAttributes::CLIENT_ADDRESS, $request->getUri()->getHost())
                    ->setAttribute(TraceAttributes::CLIENT_PORT, $request->getUri()->getPort())
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (Bootstrap $bootstrap, array $params) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                $span->end();
            }
        );

        hook(
            Http::class,
            'launch',
            pre: static function (Http $http, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $builder = $instrumentation->tracer()
                    ->spanBuilder('Http.launch')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (Http $http, array $params, ResponseInterface $response, ?Throwable $exception) {
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
            AreaList::class,
            'getCodeByFrontName',
            pre: static function (AreaList $areaList, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $frontName = $params[0] ?? 'null';
                $builder = $instrumentation->tracer()
                    ->spanBuilder('AreaList.getCodeByFrontName')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(Magento2Attributes::MAGENTO2_FRONT_NAME, $frontName);
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (AreaList $areaList, array $params, ?string $areaCode) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($areaCode) {
                    $span->setAttribute(Magento2Attributes::MAGENTO2_AREA_CODE, $areaCode);
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
                $request = $params[0] instanceof RequestInterface ? $params[0] : null;

                $builder = $instrumentation->tracer()
                    ->spanBuilder('FrontController.dispatch')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(Magento2Attributes::MAGENTO2_MODULE_NAME, $request?->getModuleName() ?? null)
                    ->setAttribute(Magento2Attributes::MAGENTO2_ACTION_NAME, $request?->getActionName() ?? null)
                    ->setAttribute(Magento2Attributes::MAGENTO2_CACHE_SESSION_ID, $request?->getCookie('mage-cache-sessid', null) ?? null);
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            /** @psalm-suppress UndefinedClass */
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

        hook(
            RouterInterface::class,
            'match',
            pre: static function (RouterInterface $routerInterface, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $request = $params[0] instanceof RequestInterface ? $params[0] : null;

                $builder = $instrumentation->tracer()
                    ->spanBuilder('RouterInterface.match')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(Magento2Attributes::MAGENTO2_MODULE_NAME, $request?->getModuleName() ?? null)
                    ->setAttribute(Magento2Attributes::MAGENTO2_ACTION_NAME, $request?->getActionName() ?? null);
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (RouterInterface $routerInterface, array $params, ?ActionInterface $actionInterface, ?Throwable $exception) {
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
                    ->spanBuilder('FrontController.processRequest')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
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
                $spanName = $request?->getFullActionName() ?? 'unknown';
                $builder = $instrumentation->tracer()
                    ->spanBuilder($spanName)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (Action $action, array $params, ?ResponseInterface $response, ?Throwable $exception) {
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
                $builder = $instrumentation->tracer()
                    ->spanBuilder('execute')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                $span = $builder->startSpan();
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

        hook(
            Area::class,
            '_loadPart',
            pre: static function (Area $area, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $part = $params[0] ?? 'unknown';
                $builder = $instrumentation->tracer()
                    ->spanBuilder('_loadPart:' . $part)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (Area $area, array $params, Area $ret, ?Throwable $exception) {
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
            View::class,
            'renderLayout',
            pre: static function (View $view, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $output = $params[0] ?? 'unknown';
                $builder = $instrumentation->tracer()
                    ->spanBuilder('renderLayout')
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(Magento2Attributes::MAGENTO2_VIEW_OUTPUT, $output);
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (View $view, array $params, View $ret, ?Throwable $exception) {
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
            Observer::class,
            'dispatch',
            pre: static function (Observer $observer, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $callback = $observer->getCallback();
                $spanName = 'OBSERVER: ';
                if (is_object($callback[0])) {
                    $spanName .= get_class($callback[0]);
                } else {
                    $spanName .= (string) $callback[0];
                }
                $spanName .= ' -> ' . $callback[1];

                $builder = $instrumentation->tracer()
                    ->spanBuilder($spanName)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (Observer $observer, array $params, Observer $ret, ?Throwable $exception) {
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
        //            FrontController::class,
        //            'getActionResponse',
        //            pre: static function (FrontController $frontController, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
        //                $builder = $instrumentation->tracer()
        //                    ->spanBuilder('getActionResponse')
        //                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
        //                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
        //                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
        //                $parent = Context::getCurrent();
        //                $span = $builder->startSpan();
        //                Context::storage()->attach($span->storeInContext($parent));
        //            },
        //            post: static function (FrontController $frontController, array $params, ResponseInterface|ResultInterface $response, ?Throwable $exception) {
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

    private static function getScriptNameFromRequest(ServerRequestInterface $request): string
    {
        return $request->getServerParams()['SCRIPT_NAME'] ?? '/';
    }
}
