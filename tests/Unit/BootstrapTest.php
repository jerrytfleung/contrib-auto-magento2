<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Unit;

use ArrayObject;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\AppInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\ObjectManager\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\Event;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class BootstrapTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    /**
     * @var AppInterface|MockObject
     */
    protected $application;

    /**
     * @var ObjectManagerFactory|MockObject
     */
    protected $objectManagerFactory;

    /**
     * @var ObjectManager|MockObject
     */
    protected $objectManager;

    /**
     * @var LoggerInterface|MockObject
     */
    protected $logger;

    /**
     * @var DirectoryList|MockObject
     */
    protected $dirs;

    /**
     * @var ReadInterface|MockObject
     */
    protected $configDir;

    /**
     * @var MaintenanceMode|MockObject
     */
    protected $maintenanceMode;

    /**
     * @var MockObject
     */
    protected $deploymentConfig;

    /**
     * @var \Magento\Framework\App\Bootstrap|MockObject
     */
    protected $bootstrapMock;

    /**
     * @var RemoteAddress|MockObject
     */
    protected $remoteAddress;

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

        $this->objectManagerFactory = $this->createMock(ObjectManagerFactory::class);
        $this->objectManager = $this->createMock(ObjectManagerInterface::class);
        $this->dirs = $this->createPartialMock(DirectoryList::class, ['getPath']);
        $this->maintenanceMode = $this->createPartialMock(MaintenanceMode::class, ['isOn']);
        $this->remoteAddress = $this->createMock(RemoteAddress::class);
        $filesystem = $this->createMock(Filesystem::class);

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);

        $mapObjectManager = [
            [DirectoryList::class, $this->dirs],
            [MaintenanceMode::class, $this->maintenanceMode],
            [RemoteAddress::class, $this->remoteAddress],
            [Filesystem::class, $filesystem],
            [DeploymentConfig::class, $this->deploymentConfig],
            [LoggerInterface::class, $this->logger],
        ];

        $this->objectManager->expects($this->any())->method('get')
            ->willReturnMap($mapObjectManager);

        $this->configDir = $this->createMock(ReadInterface::class);

        $filesystem->expects($this->any())->method('getDirectoryRead')
            ->willReturn($this->configDir);

        $this->application = $this->createMock(AppInterface::class);

        $this->objectManager->expects($this->any())->method('create')
            ->willReturn($this->application);

        $this->objectManagerFactory->expects($this->any())->method('create')
            ->willReturn($this->objectManager);

        $this->bootstrapMock = $this->getMockBuilder(Bootstrap::class)
            ->onlyMethods(['assertMaintenance', 'assertInstalled', 'getIsExpected', 'isInstalled', 'terminate'])
            ->setConstructorArgs([$this->objectManagerFactory, '', ['value1', 'value2']])
            ->getMock();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    public function test_run_with_maintenance_errors()
    {
        $expectedException = new \Exception('Message');
        $this->bootstrapMock->expects($this->once())->method('assertMaintenance')
            ->willThrowException($expectedException);
        $this->bootstrapMock->expects($this->once())->method('terminate')->with($expectedException);
        $this->application->expects($this->once())->method('catchException')->willReturn(false);
        $this->runAndRestoreErrorHandler($this->bootstrapMock, $this->application);

        $this->assertCount(1, $this->storage);
        $this->assertInstanceOf(ImmutableSpan::class, $this->storage[0]);
        $span = $this->storage[0];
        $this->assertCount(1, $span->getEvents());
        $this->assertInstanceOf(Event::class, $span->getEvents()[0]);
        $event = $span->getEvents()[0];
        $this->assertEquals('exception', $event->getName());
        $eventAttributes = $event->getAttributes()->toArray();
        $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_TYPE, $eventAttributes);
        $this->assertEquals('Exception', $eventAttributes[ExceptionAttributes::EXCEPTION_TYPE]);
        $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_MESSAGE, $eventAttributes);
        $this->assertEquals('Message', $eventAttributes[ExceptionAttributes::EXCEPTION_MESSAGE]);
        $this->assertArrayHasKey(ExceptionAttributes::EXCEPTION_STACKTRACE, $eventAttributes);
        $this->assertNotEmpty($eventAttributes[ExceptionAttributes::EXCEPTION_STACKTRACE]);
    }

    private function runAndRestoreErrorHandler(Bootstrap $bootstrap, AppInterface $application): void
    {
        try {
            $bootstrap->run($application);
        } finally {
            restore_error_handler();
        }
    }
}
