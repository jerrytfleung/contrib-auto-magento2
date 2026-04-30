<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Integration;

use ArrayObject;
use Laminas\Http\Headers;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\ExceptionHandlerInterface;
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
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class Magento2InstrumentationTest extends TestCase
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
     * Asserts mock objects with methods that are expected to be called when http->launch() is invoked.
     */
    private function setUpLaunch()
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
            ->willReturn('<html><head></head><body>Test</body></html>');
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
        $response = $this->http->launch();
        $this->assertCount(1, $this->storage);
        $this->assertArrayHasKey('X-Foo', $response->getHeaders());
    }
}
