<?php

declare(strict_types=1);

namespace Webf\TemporalBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Temporal\Activity\ActivityInterface;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Client\ScheduleClient;
use Temporal\Client\ScheduleClientInterface;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Worker\Transport\Goridge;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerOptions;
use Temporal\WorkerFactory;
use Temporal\Workflow\WorkflowInterface;
use Webf\TemporalBundle\Client\ClientAuthMethod;
use Webf\TemporalBundle\Client\ClientConfig;
use Webf\TemporalBundle\Client\OidcApiKey;
use Webf\TemporalBundle\Client\StaticApiKey;
use Webf\TemporalBundle\Command\RefreshApiKeyCommand;
use Webf\TemporalBundle\Command\WorkerCommand;
use Webf\TemporalBundle\DependencyInjection\Compiler\RegisterWorkflowsAndActivitiesPass;
use Webf\TemporalBundle\Worker\ActivityResetter\DoctrineActivityResetter;
use Webf\TemporalBundle\Worker\ActivityResetterInterface;
use Webf\TemporalBundle\Worker\ServiceCredentialsFactory;
use Webf\TemporalBundle\Worker\WorkerFactoryConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * @phpstan-type Config array{
 *     address: string,
 *     api_key: string|null,
 *     namespace: string,
 *     oidc: array{
 *         enabled: bool,
 *         cache_pool: string|null,
 *         client_auth_method: value-of<ClientAuthMethod>,
 *         client_id: string,
 *         client_secret: string,
 *         token_endpoint: string
 *     },
 *     task_queues: non-empty-list<string>,
 *     tls: array{
 *         enabled: bool,
 *         client_certificate: string|null,
 *         client_key: string|null,
 *         root_certificate: string|null,
 *         server_name: string|null
 *     },
 *     worker: array{
 *         max_concurrent_activity_execution_size: int|null,
 *         reset_doctrine_managers: bool
 *     },
 * }
 */
final class WebfTemporalBundle extends AbstractBundle
{
    public const string ACTIVITY_RESETTER_TAG = 'webf_temporal.activity_resetter';
    public const string ACTIVITY_TAG = 'webf_temporal.activity';
    public const string API_KEY_ID = 'webf_temporal.api_key';
    public const string CLIENT_OPTIONS_ID = 'webf_temporal.client_options';
    public const string DOCTRINE_ACTIVITY_RESETTER_ID = 'webf_temporal.activity_resetter.doctrine';
    public const string REFRESH_API_KEY_COMMAND_ID = 'webf_temporal.command.refresh_api_key';
    public const string RPC_ID = 'webf_temporal.rpc';
    public const string SCHEDULE_CLIENT_ID = 'webf_temporal.schedule_client';
    public const string SERVICE_CLIENT_ID = 'webf_temporal.service_client';
    public const string WORKER_COMMAND_ID = 'webf_temporal.command.worker';
    public const string WORKER_FACTORY_CONFIGURATOR_ID = 'webf_temporal.worker_factory_configurator';
    public const string WORKER_FACTORY_ID = 'webf_temporal.worker_factory';
    public const string WORKER_OPTIONS_ID = 'webf_temporal.worker_options';
    public const string WORKFLOW_CLIENT_ID = 'webf_temporal.workflow_client';
    public const string WORKFLOW_TAG = 'webf_temporal.workflow';

    #[\Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        // @mago-format-ignore-next
        $definition
            ->rootNode()
            ->validate()
                ->ifTrue(
                    /** @param array{api_key: string|null, oidc: array{enabled: bool}} $config */
                    static fn(array $config): bool => null !== $config['api_key'] && $config['oidc']['enabled'],
                )
                ->thenInvalid('"api_key" and "oidc" cannot be used together.')
            ->end()
            ->children()
                ->scalarNode('address')
                    ->info('Address of the Temporal frontend service (e.g. "localhost:7233")')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('api_key')
                    ->info('Static API key used to authenticate to the Temporal server')
                    ->defaultNull()
                ->end()
                ->scalarNode('namespace')
                    ->info('Temporal namespace used by the clients')
                    ->defaultValue(ClientOptions::DEFAULT_NAMESPACE)
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('oidc')
                    ->info('Fetch API keys from an OIDC server with the client credentials grant')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('cache_pool')
                            ->info('Cache pool that shares tokens between processes (null to keep them in the memory of each process)')
                            ->defaultNull()
                        ->end()
                        ->enumNode('client_auth_method')
                            ->info('How the client authenticates to the token endpoint (HTTP Basic is the one every server supports)')
                            ->values(array_column(ClientAuthMethod::cases(), 'value'))
                            ->defaultValue(ClientAuthMethod::ClientSecretBasic->value)
                        ->end()
                        ->scalarNode('client_id')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('client_secret')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('token_endpoint')->isRequired()->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('task_queues')
                    ->info('Task queues polled by the "temporal:worker" command (or the name of a string-backed enum whose values are the task queues)')
                    ->beforeNormalization()
                        ->ifString()
                        ->then(self::normalizeTaskQueueEnum(...))
                    ->end()
                    ->requiresAtLeastOneElement()
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([WorkerFactoryInterface::DEFAULT_TASK_QUEUE])
                ->end()
                ->arrayNode('tls')
                    ->info('Connect to the Temporal server with TLS')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('client_certificate')->info('Path of the client certificate (mTLS)')->defaultNull()->end()
                        ->scalarNode('client_key')->info('Path of the client private key (mTLS)')->defaultNull()->end()
                        ->scalarNode('root_certificate')->info('Path of the root certificate (system roots if null)')->defaultNull()->end()
                        ->scalarNode('server_name')->info('Overrides the server name used to verify the certificate')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('worker')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_concurrent_activity_execution_size')
                            ->info('Maximum number of activities executed at the same time by each worker (Temporal default if null)')
                            ->defaultNull()
                            ->min(1)
                        ->end()
                        ->booleanNode('reset_doctrine_managers')
                            ->info('Clear Doctrine managers and check their connection before each activity (if DoctrineBundle is enabled)')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * A string that is not a string-backed enum is rejected instead of being treated as a single task queue, so that a
     * typo in the enum name does not silently become a task queue name.
     *
     * @return list<string>
     */
    private static function normalizeTaskQueueEnum(string $enum): array
    {
        $reflection = enum_exists($enum) ? new \ReflectionEnum($enum) : null;
        if ('string' !== $reflection?->getBackingType()?->getName()) {
            throw new InvalidConfigurationException(sprintf(
                'The path "webf_temporal.task_queues" must be a list of task queues or the name of a string-backed enum, "%s" given.',
                $enum,
            ));
        }

        // Every case of a backed enum is a backed case.
        /** @var list<\ReflectionEnumBackedCase> $cases */
        $cases = $reflection->getCases();

        // Backing values are strings, as checked above (a cast would be an equivalent mutant for Infection).
        /** @var list<string> */
        return array_map(static fn(\ReflectionEnumBackedCase $case): int|string => $case->getBackingValue(), $cases);
    }

    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(ActivityInterface::class, static function (ChildDefinition $definition): void {
            $definition->addTag(self::ACTIVITY_TAG);
        });
        $container->registerAttributeForAutoconfiguration(WorkflowInterface::class, static function (ChildDefinition $definition): void {
            $definition->addTag(self::WORKFLOW_TAG);
        });
        $container->registerForAutoconfiguration(ActivityResetterInterface::class)->addTag(self::ACTIVITY_RESETTER_TAG);

        $container->addCompilerPass(new RegisterWorkflowsAndActivitiesPass());
    }

    #[\Override]
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        /** @var Config $config */
        $services = $configurator->services();

        $apiKey = null;
        if (null !== $config['api_key']) {
            $services->set(self::API_KEY_ID, StaticApiKey::class)->args([$config['api_key']]);
            $apiKey = service(self::API_KEY_ID);
        }
        if ($config['oidc']['enabled']) {
            $oidcApiKey = $services
                ->set(self::API_KEY_ID, OidcApiKey::class)
                ->arg('$tokenEndpoint', $config['oidc']['token_endpoint'])
                ->arg('$client', inline_service(ClientConfig::class)
                    ->arg('$id', $config['oidc']['client_id'])
                    ->arg('$secret', $config['oidc']['client_secret'])
                    ->arg('$authMethod', ClientAuthMethod::from($config['oidc']['client_auth_method'])))
                // The default HTTP client of the API key is used when FrameworkBundle does not provide one.
                ->arg('$httpClient', service('http_client')->nullOnInvalid());
            $cachePool = $config['oidc']['cache_pool'];
            if (null !== $cachePool) {
                $oidcApiKey->arg('$cache', service($cachePool));
            }
            $apiKey = service(self::API_KEY_ID);

            $services->set(self::RPC_ID, Goridge::class)->factory([Goridge::class, 'create']);

            $services
                ->set(self::REFRESH_API_KEY_COMMAND_ID, RefreshApiKeyCommand::class)
                ->args([service(self::API_KEY_ID), service(self::RPC_ID)])
                ->tag('console.command');
        }

        $tls = $config['tls'];
        $serviceClient = $services
            ->set(self::SERVICE_CLIENT_ID, ServiceClient::class)
            ->factory([ServiceClient::class, $tls['enabled'] ? 'createSSL' : 'create'])
            ->args(
                $tls['enabled']
                    ? [
                        $config['address'],
                        $tls['root_certificate'],
                        $tls['client_key'],
                        $tls['client_certificate'],
                        $tls['server_name'],
                    ]
                    : [$config['address']],
            );
        if (null !== $apiKey) {
            $serviceClient->call('withAuthKey', [$apiKey], true);
        }
        $services->alias(ServiceClientInterface::class, self::SERVICE_CLIENT_ID);

        $services->set(self::CLIENT_OPTIONS_ID, ClientOptions::class)->call(
            'withNamespace',
            [$config['namespace']],
            true,
        );

        $services->set(self::WORKFLOW_CLIENT_ID, WorkflowClient::class)->args([
            service(self::SERVICE_CLIENT_ID),
            service(self::CLIENT_OPTIONS_ID),
        ]);
        $services->alias(WorkflowClient::class, self::WORKFLOW_CLIENT_ID);
        $services->alias(WorkflowClientInterface::class, self::WORKFLOW_CLIENT_ID);

        $services->set(self::SCHEDULE_CLIENT_ID, ScheduleClient::class)->args([
            service(self::SERVICE_CLIENT_ID),
            service(self::CLIENT_OPTIONS_ID),
        ]);
        $services->alias(ScheduleClient::class, self::SCHEDULE_CLIENT_ID);
        $services->alias(ScheduleClientInterface::class, self::SCHEDULE_CLIENT_ID);

        // Lazy because the console builds `WorkerCommand` to describe it (`help`, `list --format=json`, completion...),
        // and building the factory casts the API key, which would fetch an OIDC token on every such console call. A
        // proxy of the interface, rather than a ghost or a subclass proxy, does not depend on the internals of
        // `WorkerFactory` (a ghost cannot be built by a factory method anyway).
        $workerFactory = $services
            ->set(self::WORKER_FACTORY_ID, WorkerFactory::class)
            ->factory([WorkerFactory::class, 'create'])
            ->lazy(WorkerFactoryInterface::class);
        if (null !== $apiKey) {
            $workerFactory->arg(
                '$credentials',
                inline_service()->factory([ServiceCredentialsFactory::class, 'create'])->args([$apiKey]),
            );
        }

        $workerOptions = $services->set(self::WORKER_OPTIONS_ID, WorkerOptions::class);
        if (null !== $config['worker']['max_concurrent_activity_execution_size']) {
            $workerOptions->call(
                'withMaxConcurrentActivityExecutionSize',
                [$config['worker']['max_concurrent_activity_execution_size']],
                true,
            );
        }

        // Arguments (except resetters) are set by `RegisterWorkflowsAndActivitiesPass`.
        $services->set(self::WORKER_FACTORY_CONFIGURATOR_ID, WorkerFactoryConfigurator::class)->arg(
            '$activityResetters',
            tagged_iterator(self::ACTIVITY_RESETTER_TAG),
        );

        $services
            ->set(self::WORKER_COMMAND_ID, WorkerCommand::class)
            ->args([
                service(self::WORKER_FACTORY_ID),
                service(self::WORKER_FACTORY_CONFIGURATOR_ID),
                $config['task_queues'],
                service(self::WORKER_OPTIONS_ID),
            ])
            ->tag('console.command');

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');
        if ($config['worker']['reset_doctrine_managers'] && array_key_exists('DoctrineBundle', $bundles)) {
            $services
                ->set(self::DOCTRINE_ACTIVITY_RESETTER_ID, DoctrineActivityResetter::class)
                ->args([service('doctrine')])
                ->tag(self::ACTIVITY_RESETTER_TAG);
        }
    }
}
