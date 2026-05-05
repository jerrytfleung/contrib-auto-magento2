<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Unit;

use ArrayObject;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\Http\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Test Forward
 *
 * getRequest,getResponse of AbstractAction class is also tested
 */
class ForwardTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    /**
     * @var Forward
     */
    protected $actionAbstract;

    /**
     * @var MockObject|RequestInterface
     */
    protected $request;

    /**
     * @var ResponseInterface
     */
    protected $response;

    protected function setUp(): void
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

        $objectManager = new ObjectManager($this);
        $cookieMetadataFactoryMock = $this->getMockBuilder(
            CookieMetadataFactory::class
        )->disableOriginalConstructor()
            ->getMock();
        $cookieManagerMock = $this->createMock(CookieManagerInterface::class);
        $contextMock = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->response = $objectManager->getObject(
            Http::class,
            [
                'cookieManager' => $cookieManagerMock,
                'cookieMetadataFactory' => $cookieMetadataFactoryMock,
                'context' => $contextMock
            ]
        );

        $this->request = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->actionAbstract = $objectManager->getObject(
            Forward::class,
            [
                'request' => $this->request,
                'response' => $this->response
            ]
        );
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function testDispatch()
    {
        $this->request->expects($this->once())->method('setDispatched')->with(false);
        $this->actionAbstract->dispatch($this->request);
        $this->assertCount(1, $this->storage);
        $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);
        $span = $this->storage[0];
    }
}