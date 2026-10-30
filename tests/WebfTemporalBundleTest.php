<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\RegisterEnvVarProcessorsPass;
use Symfony\Component\DependencyInjection\Compiler\ValidateEnvPlaceholdersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Client\ScheduleClient;
use Temporal\Client\ScheduleClientInterface;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Worker\Transport\Goridge;
use Temporal\Worker\WorkerOptions;
use Temporal\WorkerFactory;
use Tests\Webf\TemporalBundle\Fixtures\EmptyTaskQueue;
use Tests\Webf\TemporalBundle\Fixtures\IntBackedTaskQueue;
use Tests\Webf\TemporalBundle\Fixtures\PureTaskQueue;
use Tests\Webf\TemporalBundle\Fixtures\TaskQueue;
use Webf\TemporalBundle\Client\OidcApiKey;
use Webf\TemporalBundle\Command\RefreshApiKeyCommand;
use Webf\TemporalBundle\Command\WorkerCommand;
use Webf\TemporalBundle\WebfTemporalBundle;
use Webf\TemporalBundle\Worker\ActivityResetter\DoctrineActivityResetter;
use Webf\TemporalBundle\Worker\ServiceCredentialsFactory;
use Webf\TemporalBundle\Worker\WorkerFactoryConfigurator;

#[CoversClass(WebfTemporalBundle::class)]
#[UsesClass(OidcApiKey::class)]
final class WebfTemporalBundleTest extends TestCase
{
    public function test_default_configuration(): void
    {
        $container = $this->load(['address' => 'temporal:7233']);

        $serviceClient = $container->getDefinition(WebfTemporalBundle::SERVICE_CLIENT_ID);
        static::assertSame(ServiceClient::class, $serviceClient->getClass());
        static::assertSame([ServiceClient::class, 'create'], $serviceClient->getFactory());
        static::assertSame(['temporal:7233'], $serviceClient->getArguments());
        static::assertSame([], $serviceClient->getMethodCalls());
        $this->assertAlias($container, WebfTemporalBundle::SERVICE_CLIENT_ID, ServiceClientInterface::class);

        $clientOptions = $container->getDefinition(WebfTemporalBundle::CLIENT_OPTIONS_ID);
        static::assertSame(ClientOptions::class, $clientOptions->getClass());
        static::assertSame([['withNamespace', ['default'], true]], $clientOptions->getMethodCalls());

        foreach ([
            WebfTemporalBundle::WORKFLOW_CLIENT_ID => [WorkflowClient::class, WorkflowClientInterface::class],
            WebfTemporalBundle::SCHEDULE_CLIENT_ID => [ScheduleClient::class, ScheduleClientInterface::class],
        ] as $id => [$class, $interface]) {
            $client = $container->getDefinition($id);
            static::assertSame($class, $client->getClass());
            $this->assertReferences(
                [WebfTemporalBundle::SERVICE_CLIENT_ID, WebfTemporalBundle::CLIENT_OPTIONS_ID],
                $client->getArguments(),
            );
            $this->assertAlias($container, $id, $class, $interface);
        }

        $workerFactory = $container->getDefinition(WebfTemporalBundle::WORKER_FACTORY_ID);
        static::assertSame(WorkerFactory::class, $workerFactory->getClass());
        static::assertSame([WorkerFactory::class, 'create'], $workerFactory->getFactory());
        static::assertSame([], $workerFactory->getArguments());

        $workerOptions = $container->getDefinition(WebfTemporalBundle::WORKER_OPTIONS_ID);
        static::assertSame(WorkerOptions::class, $workerOptions->getClass());
        static::assertSame([], $workerOptions->getMethodCalls());

        $configurator = $container->getDefinition(WebfTemporalBundle::WORKER_FACTORY_CONFIGURATOR_ID);
        static::assertSame(WorkerFactoryConfigurator::class, $configurator->getClass());
        $resetters = $configurator->getArgument('$activityResetters');
        static::assertInstanceOf(TaggedIteratorArgument::class, $resetters);
        static::assertSame(WebfTemporalBundle::ACTIVITY_RESETTER_TAG, $resetters->getTag());

        $workerCommand = $container->getDefinition(WebfTemporalBundle::WORKER_COMMAND_ID);
        static::assertSame(WorkerCommand::class, $workerCommand->getClass());
        static::assertTrue($workerCommand->hasTag('console.command'));
        $arguments = $workerCommand->getArguments();
        static::assertSame(['default'], $arguments[2]);
        unset($arguments[2]);
        $this->assertReferences(
            [
                WebfTemporalBundle::WORKER_FACTORY_ID,
                WebfTemporalBundle::WORKER_FACTORY_CONFIGURATOR_ID,
                WebfTemporalBundle::WORKER_OPTIONS_ID,
            ],
            array_values($arguments),
        );

        static::assertFalse($container->hasDefinition(WebfTemporalBundle::API_KEY_ID));
        static::assertFalse($container->hasDefinition(WebfTemporalBundle::RPC_ID));
        static::assertFalse($container->hasDefinition(WebfTemporalBundle::REFRESH_API_KEY_COMMAND_ID));
        static::assertFalse($container->hasDefinition(WebfTemporalBundle::DOCTRINE_ACTIVITY_RESETTER_ID));
    }

    public function test_namespace_and_task_queues_can_be_configured(): void
    {
        $container = $this->load([
            'address' => 'temporal:7233',
            'namespace' => 'custom',
            'task_queues' => ['first', 'second'],
        ]);

        static::assertSame(
            [['withNamespace', ['custom'], true]],
            $container->getDefinition(WebfTemporalBundle::CLIENT_OPTIONS_ID)->getMethodCalls(),
        );
        static::assertSame(
            ['first', 'second'],
            $container->getDefinition(WebfTemporalBundle::WORKER_COMMAND_ID)->getArgument(2),
        );
    }

    public function test_task_queues_can_be_configured_with_an_enum(): void
    {
        $container = $this->load(['address' => 'temporal:7233', 'task_queues' => TaskQueue::class]);

        static::assertSame(
            ['first', 'second'],
            $container->getDefinition(WebfTemporalBundle::WORKER_COMMAND_ID)->getArgument(2),
        );
    }

    public function test_static_api_key(): void
    {
        $container = $this->load(['address' => 'temporal:7233', 'api_key' => 'secret']);

        static::assertSame(
            [['withAuthKey', ['secret'], true]],
            $container->getDefinition(WebfTemporalBundle::SERVICE_CLIENT_ID)->getMethodCalls(),
        );

        $credentials = $container->getDefinition(WebfTemporalBundle::WORKER_FACTORY_ID)->getArgument('$credentials');
        static::assertInstanceOf(Definition::class, $credentials);
        static::assertSame([ServiceCredentialsFactory::class, 'create'], $credentials->getFactory());
        static::assertSame(['secret'], $credentials->getArguments());

        static::assertFalse($container->hasDefinition(WebfTemporalBundle::REFRESH_API_KEY_COMMAND_ID));
    }

    public function test_oidc_api_key(): void
    {
        $container = $this->load([
            'address' => 'temporal:7233',
            'oidc' => [
                'client_id' => 'id',
                'client_secret' => 'secret',
                'token_endpoint' => 'https://oidc.example.com/token',
            ],
        ]);

        $apiKey = $container->getDefinition(WebfTemporalBundle::API_KEY_ID);
        static::assertSame(OidcApiKey::class, $apiKey->getClass());
        static::assertEquals(
            [
                '$tokenEndpoint' => 'https://oidc.example.com/token',
                '$clientId' => 'id',
                '$clientSecret' => 'secret',
                '$httpClient' => new Reference('http_client', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            ],
            $apiKey->getArguments(),
        );

        static::assertEquals(
            [['withAuthKey', [new Reference(WebfTemporalBundle::API_KEY_ID)], true]],
            $container->getDefinition(WebfTemporalBundle::SERVICE_CLIENT_ID)->getMethodCalls(),
        );
        $credentials = $container->getDefinition(WebfTemporalBundle::WORKER_FACTORY_ID)->getArgument('$credentials');
        static::assertInstanceOf(Definition::class, $credentials);
        $this->assertReferences([WebfTemporalBundle::API_KEY_ID], $credentials->getArguments());

        $rpc = $container->getDefinition(WebfTemporalBundle::RPC_ID);
        static::assertSame(Goridge::class, $rpc->getClass());
        static::assertSame([Goridge::class, 'create'], $rpc->getFactory());

        $command = $container->getDefinition(WebfTemporalBundle::REFRESH_API_KEY_COMMAND_ID);
        static::assertSame(RefreshApiKeyCommand::class, $command->getClass());
        static::assertTrue($command->hasTag('console.command'));
        $this->assertReferences([WebfTemporalBundle::API_KEY_ID, WebfTemporalBundle::RPC_ID], $command->getArguments());
    }

    public function test_oidc_api_key_uses_its_own_http_client_without_framework_bundle(): void
    {
        $container = $this->load([
            'address' => 'temporal:7233',
            'oidc' => [
                'client_id' => 'id',
                'client_secret' => 'secret',
                'token_endpoint' => 'https://oidc.example.com/token',
            ],
        ]);
        $container->setAlias('public_api_key', WebfTemporalBundle::API_KEY_ID)->setPublic(true);
        static::assertFalse($container->has('http_client'));
        $container->compile();

        static::assertInstanceOf(OidcApiKey::class, $container->get('public_api_key'));
    }

    public function test_tls(): void
    {
        $container = $this->load([
            'address' => 'temporal:7233',
            'tls' => [
                'client_certificate' => 'client.pem',
                'client_key' => 'client.key',
                'root_certificate' => 'ca.pem',
                'server_name' => 'temporal.example.com',
            ],
        ]);

        $serviceClient = $container->getDefinition(WebfTemporalBundle::SERVICE_CLIENT_ID);
        static::assertSame([ServiceClient::class, 'createSSL'], $serviceClient->getFactory());
        static::assertSame(
            ['temporal:7233', 'ca.pem', 'client.key', 'client.pem', 'temporal.example.com'],
            $serviceClient->getArguments(),
        );
    }

    public function test_tls_with_system_roots(): void
    {
        $container = $this->load(['address' => 'temporal:7233', 'tls' => true]);

        $serviceClient = $container->getDefinition(WebfTemporalBundle::SERVICE_CLIENT_ID);
        static::assertSame([ServiceClient::class, 'createSSL'], $serviceClient->getFactory());
        static::assertSame(['temporal:7233', null, null, null, null], $serviceClient->getArguments());
    }

    public function test_max_concurrent_activity_execution_size(): void
    {
        $container = $this->load([
            'address' => 'temporal:7233',
            'worker' => ['max_concurrent_activity_execution_size' => 1],
        ]);

        static::assertSame(
            [['withMaxConcurrentActivityExecutionSize', [1], true]],
            $container->getDefinition(WebfTemporalBundle::WORKER_OPTIONS_ID)->getMethodCalls(),
        );
    }

    public function test_max_concurrent_activity_execution_size_from_env(): void
    {
        $container = new ContainerBuilder();
        $extension = (new WebfTemporalBundle())->getContainerExtension();
        static::assertNotNull($extension);
        $container->registerExtension($extension);
        $placeholder = $container->getParameterBag()->resolveValue('%env(int:MAX_CONCURRENT_ACTIVITIES)%');
        $container->loadFromExtension('webf_temporal', [
            'address' => 'temporal:7233',
            'worker' => ['max_concurrent_activity_execution_size' => $placeholder],
        ]);

        // Registers the "int" env var type, so that the placeholder is validated with a dummy value of 0.
        (new RegisterEnvVarProcessorsPass())->process($container);
        $pass = new ValidateEnvPlaceholdersPass();
        $pass->process($container);

        /** @var array{worker: array{max_concurrent_activity_execution_size: mixed}} $config */
        $config = $pass->getExtensionConfig()['webf_temporal'];
        static::assertSame($placeholder, $config['worker']['max_concurrent_activity_execution_size']);
    }

    public function test_doctrine_activity_resetter_is_registered_with_doctrine_bundle(): void
    {
        $container = $this->load(['address' => 'temporal:7233'], ['DoctrineBundle' => 'DoctrineBundle']);

        $resetter = $container->getDefinition(WebfTemporalBundle::DOCTRINE_ACTIVITY_RESETTER_ID);
        static::assertSame(DoctrineActivityResetter::class, $resetter->getClass());
        static::assertTrue($resetter->hasTag(WebfTemporalBundle::ACTIVITY_RESETTER_TAG));
        $this->assertReferences(['doctrine'], $resetter->getArguments());
    }

    public function test_doctrine_activity_resetter_can_be_disabled(): void
    {
        $container = $this->load(['address' => 'temporal:7233', 'worker' => ['reset_doctrine_managers' => false]], [
            'DoctrineBundle' => 'DoctrineBundle',
        ]);

        static::assertFalse($container->hasDefinition(WebfTemporalBundle::DOCTRINE_ACTIVITY_RESETTER_ID));
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('provideInvalidConfigurations')]
    public function test_invalid_configurations(array $config, string $expectedMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->load($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidConfigurations(): iterable
    {
        yield 'missing address' => [[], 'The child config "address" under "webf_temporal" must be configured'];
        yield 'empty address' => [['address' => ''], 'The path "webf_temporal.address" cannot contain an empty value'];
        yield 'empty namespace' => [
            ['address' => 'temporal:7233', 'namespace' => ''],
            'The path "webf_temporal.namespace" cannot contain an empty value',
        ];
        yield 'no task queue' => [
            ['address' => 'temporal:7233', 'task_queues' => []],
            'The path "webf_temporal.task_queues" should have at least 1 element(s) defined.',
        ];
        yield 'empty task queue' => [
            ['address' => 'temporal:7233', 'task_queues' => ['']],
            'The path "webf_temporal.task_queues.0" cannot contain an empty value',
        ];
        yield 'enum without case as task queues' => [
            ['address' => 'temporal:7233', 'task_queues' => EmptyTaskQueue::class],
            'The path "webf_temporal.task_queues" should have at least 1 element(s) defined.',
        ];
        foreach ([
            'unknown class' => 'App\UnknownTaskQueue',
            'class' => \stdClass::class,
            'pure enum' => PureTaskQueue::class,
            'int-backed enum' => IntBackedTaskQueue::class,
        ] as $name => $taskQueues) {
            yield "{$name} as task queues" => [
                ['address' => 'temporal:7233', 'task_queues' => $taskQueues],
                sprintf(
                    'The path "webf_temporal.task_queues" must be a list of task queues or the name of a string-backed enum, "%s" given.',
                    $taskQueues,
                ),
            ];
        }
        yield 'zero max concurrent activity execution size' => [
            ['address' => 'temporal:7233', 'worker' => ['max_concurrent_activity_execution_size' => 0]],
            'The value 0 is too small for path "webf_temporal.worker.max_concurrent_activity_execution_size"',
        ];
        yield 'api key and oidc' => [
            [
                'address' => 'temporal:7233',
                'api_key' => 'secret',
                'oidc' => ['client_id' => 'id', 'client_secret' => 'secret', 'token_endpoint' => 'https://oidc'],
            ],
            '"api_key" and "oidc" cannot be used together.',
        ];
        foreach (['client_id', 'client_secret', 'token_endpoint'] as $key) {
            $oidc = ['client_id' => 'id', 'client_secret' => 'secret', 'token_endpoint' => 'https://oidc'];
            unset($oidc[$key]);
            yield 'missing oidc ' . $key => [
                ['address' => 'temporal:7233', 'oidc' => $oidc],
                sprintf('The child config "%s" under "webf_temporal.oidc" must be configured.', $key),
            ];
            yield 'empty oidc ' . $key => [
                ['address' => 'temporal:7233', 'oidc' => [$key => ''] + $oidc],
                sprintf('The path "webf_temporal.oidc.%s" cannot contain an empty value', $key),
            ];
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $bundles
     */
    private function load(array $config, array $bundles = []): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.build_dir' => sys_get_temp_dir(),
            'kernel.bundles' => $bundles,
            'kernel.environment' => 'test',
        ]));

        $extension = (new WebfTemporalBundle())->getContainerExtension();
        static::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }

    private function assertAlias(ContainerBuilder $container, string $id, string ...$aliases): void
    {
        foreach ($aliases as $alias) {
            static::assertSame($id, (string) $container->getAlias($alias));
        }
    }

    /**
     * @param list<string> $expectedIds
     * @param array<mixed> $arguments
     */
    private function assertReferences(array $expectedIds, array $arguments): void
    {
        static::assertSame($expectedIds, array_map(static function (mixed $argument): string {
            static::assertInstanceOf(Reference::class, $argument);

            return (string) $argument;
        }, $arguments));
    }
}
