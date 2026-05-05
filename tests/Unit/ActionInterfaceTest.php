<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Unit;

use ArrayObject;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Http\Context;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\Event;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ActionInterface::execute instrumentation hook.
 */
class ActionInterfaceTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;

    /** @var Forward */
    private Forward $forward;

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

        $request = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->disableOriginalConstructor()
            ->getMock();

        $response = $objectManager->getObject(
            Http::class,
            [
                'cookieManager' => $this->createMock(CookieManagerInterface::class),
                'cookieMetadataFactory' => $this->getMockBuilder(CookieMetadataFactory::class)
                    ->disableOriginalConstructor()
                    ->getMock(),
                'context' => $this->getMockBuilder(Context::class)
                    ->disableOriginalConstructor()
                    ->getMock(),
            ]
        );

        $this->forward = $objectManager->getObject(
            Forward::class,
            [
                'request' => $request,
                'response' => $response,
            ]
        );
    }

    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_execute_records_span_and_code_attributes(): void
    {
        $this->forward->execute();

        $this->assertCount(1, $this->storage);
        $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);

        /** @var ImmutableSpan $span */
        $span = $this->storage[0];
        $this->assertSame('ActionInterface.execute', $span->getName());

        $attributes = $span->getAttributes()->toArray();
        $this->assertArrayHasKey(CodeAttributes::CODE_FUNCTION_NAME, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_FUNCTION_NAME]);
        $this->assertArrayHasKey(CodeAttributes::CODE_FILE_PATH, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_FILE_PATH]);
        $this->assertArrayHasKey(CodeAttributes::CODE_LINE_NUMBER, $attributes);
        $this->assertNotEmpty($attributes[CodeAttributes::CODE_LINE_NUMBER]);
    }

    public function test_execute_records_exception_event(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $action = new class implements ActionInterface {
            public function execute(): ResultInterface|\Magento\Framework\App\ResponseInterface
            {
                throw new \RuntimeException('boom');
            }
        };

        try {
            $action->execute();
        } finally {
            $this->assertCount(1, $this->storage);
            $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);

            /** @var ImmutableSpan $span */
            $span = $this->storage[0];
            $this->assertSame('ActionInterface.execute', $span->getName());

            $events = $span->getEvents();
            $this->assertCount(1, $events);
            $this->assertInstanceOf(Event::class, $events[0]);

            /** @var Event $event */
            $event = $events[0];
            $this->assertSame('exception', $event->getName());

            $eventAttributes = $event->getAttributes()->toArray();
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_TYPE, $eventAttributes);
            $this->assertStringContainsString('RuntimeException', (string) $eventAttributes[ExceptionAttributes::EXCEPTION_TYPE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_MESSAGE, $eventAttributes);
            $this->assertSame('boom', $eventAttributes[ExceptionAttributes::EXCEPTION_MESSAGE]);
            $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_STACKTRACE, $eventAttributes);
            $this->assertNotEmpty($eventAttributes[ExceptionAttributes::EXCEPTION_STACKTRACE]);
        }
    }
}

