<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Unit;

use ArrayObject;
use Laminas\Http\Headers;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\ExceptionHandlerInterface;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\Http as AppHttp;
use Magento\Framework\App\ObjectManager\ConfigLoader;
use Magento\Framework\App\Request\Http as RequestHttp;
use Magento\Framework\App\Request\PathInfo;
use Magento\Framework\App\Request\PathInfoProcessorInterface;
use Magento\Framework\App\Response\Http as ResponseHttp;
use Magento\Framework\App\Route\ConfigInterface\Proxy;
use Magento\Framework\Event\Manager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieReaderInterface;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as HelperObjectManager;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\Event;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HttpTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;

    private $objectManager;

    /**
     * @var ResponseHttp|MockObject
     */
    private $responseMock;

    /**
     * @var AppHttp
     */
    private $http;

    /**
     * @var FrontControllerInterface|MockObject
     */
    private $frontControllerMock;

    /**
     * @var Manager|MockObject
     */
    private $eventManagerMock;

    /**
     * @var RequestHttp|MockObject
     */
    private $requestMock;

    /**
     * @var ObjectManagerInterface|MockObject
     */
    private $objectManagerMock;

    /**
     * @var AreaList|MockObject
     */
    private $areaListMock;

    /**
     * @var ConfigLoader|MockObject
     */
    private $configLoaderMock;

    /**
     * @var ExceptionHandlerInterface|MockObject
     */
    private $exceptionHandlerMock;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->activate();

        $this->objectManager = new HelperObjectManager($this);
        $objects = [
            [
                PathInfo::class,
                $this->createMock(PathInfo::class),
            ],
        ];
        $this->objectManager->prepareObjectManager($objects);
        $cookieReaderMock = $this->getMockBuilder(CookieReaderInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $routeConfigMock = $this->getMockBuilder(Proxy::class)
            ->disableOriginalConstructor()
            ->getMock();
        $pathInfoProcessorMock = $this->getMockBuilder(PathInfoProcessorInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $converterMock = $this->getMockBuilder(StringUtils::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['cleanString'])
            ->getMock();
        $objectManagerMock = $this->createStub(ObjectManagerInterface::class);
        $this->requestMock = $this->getMockBuilder(RequestHttp::class)
            ->setConstructorArgs(
                [
                    'cookieReader' => $cookieReaderMock,
                    'converter' => $converterMock,
                    'routeConfig' => $routeConfigMock,
                    'pathInfoProcessor' => $pathInfoProcessorMock,
                    'objectManager' => $objectManagerMock,
                ]
            )
            ->onlyMethods(['getFrontName', 'isHead'])
            ->getMock();
        $this->areaListMock = $this->getMockBuilder(AreaList::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCodeByFrontName'])
            ->getMock();
        $this->configLoaderMock = $this->getMockBuilder(ConfigLoader::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load'])
            ->getMock();
        $this->objectManagerMock = $this->createMock(ObjectManagerInterface::class);
        $this->responseMock = $this->createMock(ResponseHttp::class);

        // $this->frontController = new FrontController();

        $this->frontControllerMock = $this->createMock(FrontControllerInterface::class);
        $this->eventManagerMock = $this->getMockBuilder(Manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['dispatch'])
            ->getMock();
        $this->exceptionHandlerMock = $this->createMock(ExceptionHandlerInterface::class);

        $this->http = $this->objectManager->getObject(
            AppHttp::class,
            [
                'objectManager' => $this->objectManagerMock,
                'eventManager' => $this->eventManagerMock,
                'areaList' => $this->areaListMock,
                'request' => $this->requestMock,
                'response' => $this->responseMock,
                'configLoader' => $this->configLoaderMock,
                'exceptionHandler' => $this->exceptionHandlerMock,
            ]
        );
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    /**
     * Sets up all launch dependencies except the FrontController dispatch result, allowing
     * individual tests to define whether dispatch succeeds or throws.
     */
    private function setUpLaunchDependencies(): void
    {
        $frontName = 'frontName';
        $areaCode = 'areaCode';
        $this->requestMock->expects($this->once())
            ->method('getFrontName')
            ->willReturn($frontName);
        $this->areaListMock->expects($this->once())
            ->method('getCodeByFrontName')
            ->with($frontName)
            ->willReturn($areaCode);
        $this->configLoaderMock->expects($this->once())
            ->method('load')
            ->with($areaCode)
            ->willReturn([]);
        $this->objectManagerMock->expects($this->once())->method('configure')->with([]);
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with(FrontControllerInterface::class)
            ->willReturn($this->frontControllerMock);
    }

    /**
     * Full happy-path launch setup: dispatch returns the response mock.
     */
    private function setUpLaunch(): void
    {
        $this->setUpLaunchDependencies();
        $this->frontControllerMock->expects($this->once())
            ->method('dispatch')
            ->with($this->requestMock)
            ->willReturn($this->responseMock);
    }

    public function test_launch()
    {
        $this->setUpLaunch();
        $this->requestMock->expects($this->once())
            ->method('isHead')
            ->willReturn(false);
        $this->responseMock->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $this->responseMock->expects($this->once())
            ->method('getBody')
            ->willReturn('Body');
        $this->responseMock->expects($this->once())
            ->method('toString')
            ->willReturn('String');
        $headers = new Headers();
        $headers->addHeaders(['k1' => 'v1', 'k2' => 'v2', 'k3' => 'v3']);
        $this->responseMock->expects($this->once())
            ->method('getHeaders')
            ->willReturn($headers);
        $this->eventManagerMock->expects($this->once())
            ->method('dispatch')
            ->with(
                'controller_front_send_response_before',
                ['request' => $this->requestMock, 'response' => $this->responseMock]
            );
        $this->eventManagerMock->expects($this->once())
            ->method('dispatch')
            ->with(
                'controller_front_send_response_before',
                ['request' => $this->requestMock, 'response' => $this->responseMock]
            );
        $this->http->launch();

        $this->assertCount(1, $this->storage);
        $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);
        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $this->assertNotEmpty($span->getName());
        $this->assertCount(0, $span->getEvents());

        $attributes = $span->getAttributes()->toArray();

        // --- code attributes ---
        $this->assertArrayHasKey(CodeAttributes::CODE_FUNCTION_NAME, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_FUNCTION_NAME]);
        $this->assertArrayHasKey(CodeAttributes::CODE_FILE_PATH, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_FILE_PATH]);
        $this->assertArrayHasKey(CodeAttributes::CODE_LINE_NUMBER, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_LINE_NUMBER]);

        // --- request attributes (values are environment-derived; assert presence and type) ---
        $this->assertArrayHasKey(TraceAttributes::URL_FULL, $attributes);
        $this->assertIsString($attributes[TraceAttributes::URL_FULL]);
        $this->assertArrayHasKey(TraceAttributes::URL_SCHEME, $attributes);
        $this->assertArrayHasKey(TraceAttributes::URL_PATH, $attributes);
        $this->assertArrayHasKey(TraceAttributes::HTTP_REQUEST_METHOD, $attributes);
        $this->assertArrayHasKey(TraceAttributes::NETWORK_PROTOCOL_VERSION, $attributes);
        $this->assertArrayHasKey(TraceAttributes::SERVER_ADDRESS, $attributes);
        $this->assertArrayHasKey(TraceAttributes::SERVER_PORT, $attributes);

        // --- response attributes (values are controlled by the mock) ---
        $this->assertArrayHasKey(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $attributes);
        $this->assertSame(200, $attributes[TraceAttributes::HTTP_RESPONSE_STATUS_CODE]);
        $this->assertArrayHasKey(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $attributes);
        $this->assertSame(4, $attributes[TraceAttributes::HTTP_RESPONSE_BODY_SIZE]);
        $this->assertArrayHasKey(TraceAttributes::HTTP_RESPONSE_SIZE, $attributes);
        $this->assertSame(6, $attributes[TraceAttributes::HTTP_RESPONSE_SIZE]);

        // --- response headers ---
        $this->assertArrayHasKey(TraceAttributes::HTTP_RESPONSE_HEADER . '.k1', $attributes);
        $this->assertSame('v1', $attributes[TraceAttributes::HTTP_RESPONSE_HEADER . '.k1']);
        $this->assertArrayHasKey(TraceAttributes::HTTP_RESPONSE_HEADER . '.k2', $attributes);
        $this->assertSame('v2', $attributes[TraceAttributes::HTTP_RESPONSE_HEADER . '.k2']);
        $this->assertArrayHasKey(TraceAttributes::HTTP_RESPONSE_HEADER . '.k3', $attributes);
        $this->assertSame('v3', $attributes[TraceAttributes::HTTP_RESPONSE_HEADER . '.k3']);
    }

    public function test_launch_exception(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Message');

        $this->setUpLaunchDependencies();
        $this->frontControllerMock->expects($this->once())
            ->method('dispatch')
            ->with($this->requestMock)
            ->willThrowException(new \Exception('Message'));

        try {
            $this->http->launch();
        } finally {
            $this->assertCount(1, $this->storage);
            $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);
            /** @var ImmutableSpan $span */
            $span = $this->storage[0];
            $this->assertNotEmpty($span->getName());

            $attributes = $span->getAttributes()->toArray();
            $this->assertArrayHasKey(CodeAttributes::CODE_FUNCTION_NAME, $attributes);
            $this->assertNotEmpty($attributes[CodeAttributes::CODE_FUNCTION_NAME]);
            $this->assertArrayHasKey(CodeAttributes::CODE_FILE_PATH, $attributes);
            $this->assertNotEmpty($attributes[CodeAttributes::CODE_FILE_PATH]);
            $this->assertArrayHasKey(CodeAttributes::CODE_LINE_NUMBER, $attributes);
            $this->assertNotEmpty($attributes[CodeAttributes::CODE_LINE_NUMBER]);

            $this->assertCount(1, $span->getEvents());
            $this->assertInstanceOf(Event::class, $span->getEvents()[0]);
            $event = $span->getEvents()[0];
            $this->assertSame('exception', $event->getName());

            $eventAttributes = $event->getAttributes()->toArray();
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_TYPE, $eventAttributes);
            $this->assertStringContainsString('Exception', (string) $eventAttributes[ExceptionAttributes::EXCEPTION_TYPE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_MESSAGE, $eventAttributes);
            $this->assertSame('Message', $eventAttributes[ExceptionAttributes::EXCEPTION_MESSAGE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_STACKTRACE, $eventAttributes);
            $this->assertNotEmpty($eventAttributes[ExceptionAttributes::EXCEPTION_STACKTRACE]);
        }
    }


}
