<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;
use Temporal\Worker\WorkerOptions;
use Tests\Webf\TemporalBundle\Fixtures\Activity\GreetingActivity;
use Tests\Webf\TemporalBundle\Fixtures\CountingActivityResetter;
use Tests\Webf\TemporalBundle\Fixtures\TestKernel;
use Tests\Webf\TemporalBundle\Fixtures\Workflow\GreetingWorkflow;
use Webf\TemporalBundle\Client\OidcApiKey;
use Webf\TemporalBundle\Command\RefreshApiKeyCommand;
use Webf\TemporalBundle\Command\WorkerCommand;
use Webf\TemporalBundle\DependencyInjection\Compiler\RegisterWorkflowsAndActivitiesPass;
use Webf\TemporalBundle\WebfTemporalBundle;
use Webf\TemporalBundle\Worker\WorkerFactoryConfigurator;

/**
 * Checks that the bundle works within a real application: autoconfiguration, compiler passes and console commands.
 */
#[CoversClass(WebfTemporalBundle::class)]
#[UsesClass(OidcApiKey::class)]
#[UsesClass(RefreshApiKeyCommand::class)]
#[UsesClass(RegisterWorkflowsAndActivitiesPass::class)]
#[UsesClass(WorkerCommand::class)]
#[UsesClass(WorkerFactoryConfigurator::class)]
final class KernelTest extends TestCase
{
    private ?TestKernel $kernel = null;

    /** @var callable|null */
    private mixed $exceptionHandler = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->exceptionHandler = self::getExceptionHandler();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->shutdown();

        // FrameworkBundle < 7.0 registers its exception handler when booting, which makes PHPUnit flag tests as risky.
        if (self::getExceptionHandler() !== $this->exceptionHandler) {
            restore_exception_handler();
        }
    }

    public function test_annotated_workflows_and_activities_are_registered(): void
    {
        $container = $this->boot(['address' => 'temporal:7233']);

        $configurator = $container->get(WebfTemporalBundle::WORKER_FACTORY_CONFIGURATOR_ID);
        static::assertInstanceOf(WorkerFactoryConfigurator::class, $configurator);

        $activityFactories = [];
        $worker = $this->createMock(WorkerInterface::class);
        $worker
            ->expects($this->once())
            ->method('registerActivity')
            ->willReturnCallback(static function (string $type, ?callable $factory) use (&$activityFactories, $worker) {
                $activityFactories[$type] = $factory;

                return $worker;
            });
        $worker
            ->expects($this->once())
            ->method('registerWorkflowTypes')
            ->with(GreetingWorkflow::class)
            ->willReturnSelf();

        $factory = $this->createMock(WorkerFactoryInterface::class);
        $factory->expects($this->once())->method('newWorker')->willReturn($worker);

        $configurator->configure($factory, 'default', WorkerOptions::new());

        static::assertSame([GreetingActivity::class], array_keys($activityFactories));
        $activityFactory = $activityFactories[GreetingActivity::class];
        static::assertNotNull($activityFactory);
        static::assertSame($container->get(GreetingActivity::class), $activityFactory());

        // Activity resetters are autoconfigured from their interface.
        $resetter = $container->get(CountingActivityResetter::class);
        static::assertInstanceOf(CountingActivityResetter::class, $resetter);
        static::assertSame(1, $resetter->count);
    }

    public function test_api_key_refresh_command_is_only_registered_with_oidc(): void
    {
        $this->boot(['address' => 'temporal:7233']);
        $application = $this->createApplication();
        static::assertTrue($application->has('temporal:worker'));
        static::assertFalse($application->has('temporal:refresh-api-key'));
        $this->shutdown();

        $container = $this->boot([
            'address' => 'temporal:7233',
            'oidc' => [
                'client_id' => 'id',
                'client_secret' => 'secret',
                'token_endpoint' => 'https://oidc.example.com/token',
            ],
        ]);
        static::assertTrue($this->createApplication()->has('temporal:refresh-api-key'));
        static::assertInstanceOf(OidcApiKey::class, $container->get(WebfTemporalBundle::API_KEY_ID));
    }

    /**
     * The console builds commands to describe them (`help`, `list --format=json`, completion...), so building the
     * worker factory there (which casts the API key) would make these calls crash wherever the OIDC server is
     * unreachable (dev, CI, image builds).
     */
    public function test_describing_the_worker_command_does_not_fetch_an_oidc_token(): void
    {
        $this->boot([
            'address' => 'temporal:7233',
            'oidc' => [
                'client_id' => 'id',
                'client_secret' => 'secret',
                // Nothing listens on port 1, so any token request fails.
                'token_endpoint' => 'http://127.0.0.1:1/token',
            ],
        ]);
        $application = $this->createApplication();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);

        static::assertSame(Command::SUCCESS, $tester->run(['command' => 'help', 'command_name' => 'temporal:worker']));
        static::assertStringContainsString('task-queue', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config): ContainerInterface
    {
        $this->kernel = new TestKernel($config);
        $this->kernel->boot();

        $container = $this->kernel->getContainer()->get('test.service_container');
        static::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function shutdown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel?->removeVarDir();
        $this->kernel = null;
    }

    private static function getExceptionHandler(): ?callable
    {
        // `get_exception_handler()` is only available since PHP 8.5.
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    private function createApplication(): Application
    {
        static::assertNotNull($this->kernel);

        return new Application($this->kernel);
    }
}
