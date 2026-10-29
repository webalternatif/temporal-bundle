# Temporal bundle

[![Source code](https://img.shields.io/badge/source-GitHub-blue)](https://github.com/webalternatif/temporal-bundle)
[![Packagist Version](https://img.shields.io/packagist/v/webalternatif/temporal-bundle)](https://packagist.org/packages/webalternatif/temporal-bundle)
[![Software license](https://img.shields.io/github/license/webalternatif/temporal-bundle)](https://github.com/webalternatif/temporal-bundle/blob/main/LICENSE)
[![GitHub issues](https://img.shields.io/github/issues/webalternatif/temporal-bundle)](https://github.com/webalternatif/temporal-bundle/issues) \
[![Test status](https://img.shields.io/github/actions/workflow/status/webalternatif/temporal-bundle/tests.yml?branch=main&label=tests)](https://github.com/webalternatif/temporal-bundle/actions/workflows/tests.yml)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fwebalternatif%2Ftemporal-bundle%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/webalternatif/temporal-bundle/main)

This bundle integrates the [Temporal PHP SDK][1] into Symfony applications:

* Temporal clients (`WorkflowClient` and `ScheduleClient`) are available as
  services, configured from the bundle configuration.
* Workflows and activities are discovered from their Temporal attributes, and
  activities are regular services (with autowiring).
* A console command runs workers inside [RoadRunner][2].
* Temporal API keys can be static or fetched from an OIDC server, and are kept
  fresh in long-running processes.
* Doctrine entity managers are reset before each activity, like Messenger does
  between two messages.

This project adheres to [Semantic Versioning][3].

## Installation

Make sure Composer is installed globally, as explained in the
[installation chapter][4] of the Composer documentation.

### Applications that use Symfony Flex

Open a command console, enter your project directory and execute:

```console
$ composer require webalternatif/temporal-bundle
```

### Applications that don't use Symfony Flex

#### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the following
command to download the latest stable version of this bundle:

```console
$ composer require webalternatif/temporal-bundle
```

#### Step 2: Enable the Bundle

Then, enable the bundle by adding it to the list of registered bundles in the
`config/bundles.php` file of your project:

```php
// config/bundles.php

return [
    // ...
    Webf\TemporalBundle\WebfTemporalBundle::class => ['all' => true],
];
```

### Requirements

* The [`grpc` PHP extension][5] is required by the Temporal clients (i.e. to
  start workflows).
* [RoadRunner][2] is required to run workers. The `rr` binary can be downloaded
  with `vendor/bin/rr get-binary`.

## Configuration

The only required option is the address of the Temporal server:

```yaml
# config/packages/webf_temporal.yaml
webf_temporal:
    address: '%env(TEMPORAL_ADDRESS)%' # e.g. "localhost:7233"
```

Secrets (`api_key`, `oidc.client_secret`) should also be given with
[environment variables][6], such as `'%env(TEMPORAL_API_KEY)%'`: a value
written in the configuration is dumped as it is in the compiled container
(in `var/cache/`).

Here is the full configuration reference, with default values:

```yaml
webf_temporal:
    # Address of the Temporal frontend service (e.g. "localhost:7233")
    address: ~ # Required

    # Static API key used to authenticate to the Temporal server
    api_key: null

    # Temporal namespace used by the clients
    namespace: default

    # Fetch API keys from an OIDC server with the client credentials grant
    # (cannot be used with "api_key")
    oidc:
        enabled: false
        client_id: ~ # Required
        client_secret: ~ # Required
        token_endpoint: ~ # Required

    # Task queues polled by the "temporal:worker" command (or the name of a
    # string-backed enum whose values are the task queues)
    task_queues: [default]

    # Connect to the Temporal server with TLS
    tls:
        enabled: false
        client_certificate: null # Path of the client certificate (mTLS)
        client_key: null # Path of the client private key (mTLS)
        root_certificate: null # Path of the root certificate (system roots if null)
        server_name: null # Overrides the server name used to verify the certificate

    worker:
        # Maximum number of activities executed at the same time by each worker (Temporal default if null)
        max_concurrent_activity_execution_size: null

        # Clear Doctrine managers and check their connection before each activity (if DoctrineBundle is enabled)
        reset_doctrine_managers: true
```

## Usage

### Writing workflows and activities

Any service whose class has the `#[ActivityInterface]` or `#[WorkflowInterface]`
attribute is registered on workers, as long as it is autoconfigured (which is
the case of services loaded from the `src/` directory with the default Symfony
configuration).

Activities are instantiated by the service container, so they can use
dependency injection:

```php
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Exception\Failure\ApplicationFailure;

#[ActivityInterface]
final readonly class OrderActivities
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderRepository $orderRepository,
    ) {
    }

    public function markAsShipped(int $orderId): void
    {
        $order = $this->orderRepository->find($orderId)
            ?? throw new ApplicationFailure("Order #{$orderId} not found.", 'OrderNotFound', nonRetryable: true);

        $order->markAsShipped();

        $this->entityManager->flush();
    }
}
```

Workflows are instantiated by Temporal itself, so they must not have
constructor dependencies:

```php
use App\Temporal\Activity\OrderActivities;
use Temporal\Activity\ActivityOptions;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
final class ShipOrderWorkflow
{
    #[WorkflowMethod]
    public function ship(int $orderId): \Generator
    {
        $orderActivities = Workflow::newActivityStub(
            OrderActivities::class,
            ActivityOptions::new()->withStartToCloseTimeout('5s'),
        );

        yield $orderActivities->markAsShipped($orderId);
    }
}
```

> [!NOTE]
> Symfony only looks for attributes on the class of a service, not on the
> interfaces it implements: when `#[ActivityInterface]` is put on an interface,
> tag the implementation with `webf_temporal.activity` yourself.

### Starting workflows

Inject `Temporal\Client\WorkflowClientInterface` (or `WorkflowClient`) where you
need to start workflows:

```php
use App\Entity\Order;
use App\Temporal\Workflow\ShipOrderWorkflow;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\Common\WorkflowIdConflictPolicy;

final readonly class OrderService
{
    public function __construct(
        private WorkflowClientInterface $workflowClient,
    ) {
    }

    public function ship(Order $order): void
    {
        $workflow = $this->workflowClient->newWorkflowStub(
            ShipOrderWorkflow::class,
            WorkflowOptions::new()
                ->withWorkflowId(sprintf('ship-order-%d', $order->getId()))
                ->withWorkflowIdConflictPolicy(WorkflowIdConflictPolicy::UseExisting)
                ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicateFailedOnly),
        );

        $this->workflowClient->start($workflow, $order->getId());
    }
}
```

`Temporal\Client\ScheduleClientInterface` (or `ScheduleClient`) can be injected
the same way to manage [schedules][7].

### Running workers

Workers are PHP processes managed by RoadRunner, which polls Temporal and gives
them workflow and activity tasks. Its configuration file (`.rr.yaml`) must run
the `temporal:worker` command:

```yaml
version: "3"

rpc:
  listen: tcp://127.0.0.1:6001

server:
  command: "php bin/console temporal:worker"

temporal:
  address: "${TEMPORAL_ADDRESS}"
  namespace: "default"

logs:
  level: info
```

Then start RoadRunner with `rr serve`.

The `temporal:worker` command polls every task queue listed in the
`task_queues` option. To dedicate a RoadRunner instance to a single task queue,
give its name to the command (e.g. `php bin/console temporal:worker
${TASK_QUEUE}`), it must be one of the `task_queues` option.

When task queues are already defined by a string-backed enum, its name can be
given instead of the list, the task queues are then the values of its cases:

```yaml
webf_temporal:
    task_queues: App\Temporal\TaskQueue
```

> [!NOTE]
> RoadRunner connects to the Temporal server on its own: TLS must also be
> configured in the `temporal.tls` section of `.rr.yaml`.

### Authentication

With a static API key (`api_key` option), the key is given to the Temporal
clients and to RoadRunner when workers start.

With OIDC (`oidc` option), API keys are access tokens fetched with the
[client credentials grant][8]. They are refreshed at half of their lifetime,
both by the Temporal clients and by the `temporal:refresh-api-key` command,
which pushes them to RoadRunner. This command must run alongside workers, as a
RoadRunner service:

```yaml
# .rr.yaml
service:
  temporal_api_key:
    command: "php bin/console temporal:refresh-api-key"
    remain_after_exit: true
    restart_sec: 5
```

The command pushes keys to RoadRunner through its RPC server, at the address
given by the `RR_RPC` environment variable (`tcp://127.0.0.1:6001` by
default). When `rpc.listen` is another address, give it to the service:

```yaml
# .rr.yaml
rpc:
  listen: tcp://127.0.0.1:6002

service:
  temporal_api_key:
    command: "php bin/console temporal:refresh-api-key"
    env:
      RR_RPC: tcp://127.0.0.1:6002
    remain_after_exit: true
    restart_sec: 5
```

The HTTP client of FrameworkBundle (`http_client` service) is used to fetch
tokens when available.

### Resetting services between activities

Workers are long-running processes, so a state left by an activity could leak
into the next ones. Before instantiating each activity, workers call every
service implementing `Webf\TemporalBundle\Worker\ActivityResetterInterface`
(autoconfigured with the `webf_temporal.activity_resetter` tag).

When DoctrineBundle is enabled, a resetter clears every Doctrine manager,
reconnects database connections closed by the server, and resets closed entity
managers. It can be disabled with the `worker.reset_doctrine_managers` option.

### Services

| Service id                      | Aliases                                                                     |
|---------------------------------|-----------------------------------------------------------------------------|
| `webf_temporal.workflow_client` | `Temporal\Client\WorkflowClient`, `Temporal\Client\WorkflowClientInterface` |
| `webf_temporal.schedule_client` | `Temporal\Client\ScheduleClient`, `Temporal\Client\ScheduleClientInterface` |
| `webf_temporal.service_client`  | `Temporal\Client\GRPC\ServiceClientInterface`                               |
| `webf_temporal.client_options`  |                                                                             |
| `webf_temporal.worker_factory`  |                                                                             |
| `webf_temporal.worker_options`  |                                                                             |

## Tests

To run all tests, execute the command:

```bash
composer test
```

This will run the [Mago][9] analyzer, [PHPUnit][10], [Infection][11], the Mago
linter and a Mago formatting check, but you can run them individually like this:

```bash
composer analyze
composer phpunit
composer infection
composer lint
composer fmt-check
```

To format the code, execute `composer fmt`.

## Releasing

Commits follow the [conventional commits][12] specification, which allows
generating the changelog with [git-cliff][13]. To release a new version, run
`just release` (see [just][14]), which updates `CHANGELOG.md`, then commits and
tags the new version. Then, push the release commit and its tag:

```bash
git push --follow-tags
```

Pushing the tag publishes a GitHub release with the notes of the new version.

## License

This bundle is released under the [MIT license](LICENSE).

[1]: https://github.com/temporalio/sdk-php
[2]: https://roadrunner.dev
[3]: https://semver.org
[4]: https://getcomposer.org/doc/00-intro.md
[5]: https://pecl.php.net/package/grpc
[6]: https://symfony.com/doc/current/configuration.html#configuration-based-on-environment-variables
[7]: https://docs.temporal.io/schedule
[8]: https://datatracker.ietf.org/doc/html/rfc6749#section-4.4
[9]: https://mago.carthage.software
[10]: https://phpunit.de
[11]: https://infection.github.io
[12]: https://www.conventionalcommits.org
[13]: https://git-cliff.org
[14]: https://just.systems
