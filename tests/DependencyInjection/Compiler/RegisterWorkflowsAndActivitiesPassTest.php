<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Tests\Webf\TemporalBundle\Fixtures\Activity\GreetingActivity;
use Tests\Webf\TemporalBundle\Fixtures\Workflow\GreetingWorkflow;
use Webf\TemporalBundle\DependencyInjection\Compiler\RegisterWorkflowsAndActivitiesPass;
use Webf\TemporalBundle\WebfTemporalBundle;
use Webf\TemporalBundle\Worker\WorkerFactoryConfigurator;

#[CoversClass(RegisterWorkflowsAndActivitiesPass::class)]
final class RegisterWorkflowsAndActivitiesPassTest extends TestCase
{
    public function test_nothing_is_done_without_worker_factory_configurator(): void
    {
        $container = new ContainerBuilder();
        $container->register('activity', GreetingActivity::class)->addTag(WebfTemporalBundle::ACTIVITY_TAG);
        $definitions = $container->getDefinitions();

        (new RegisterWorkflowsAndActivitiesPass())->process($container);

        static::assertSame($definitions, $container->getDefinitions());
    }

    public function test_tagged_services_are_given_to_the_worker_factory_configurator(): void
    {
        $container = new ContainerBuilder();
        $configurator = $container->register(
            WebfTemporalBundle::WORKER_FACTORY_CONFIGURATOR_ID,
            WorkerFactoryConfigurator::class,
        );
        $container->register('greeting_activity', GreetingActivity::class)->addTag(WebfTemporalBundle::ACTIVITY_TAG);
        $container->setDefinition(\stdClass::class, new Definition())->addTag(WebfTemporalBundle::ACTIVITY_TAG);
        $container->register('greeting_workflow', GreetingWorkflow::class)->addTag(WebfTemporalBundle::WORKFLOW_TAG);
        $container->setDefinition(\ArrayObject::class, new Definition())->addTag(WebfTemporalBundle::WORKFLOW_TAG);
        $container->setAlias('aliased_activity', 'greeting_activity');
        $container->register('untagged', \SplQueue::class);

        (new RegisterWorkflowsAndActivitiesPass())->process($container);

        static::assertSame(
            ['greeting_activity' => GreetingActivity::class, \stdClass::class => \stdClass::class],
            $configurator->getArgument('$activityClasses'),
        );
        static::assertSame(
            [GreetingWorkflow::class, \ArrayObject::class],
            $configurator->getArgument('$workflowClasses'),
        );

        $locatorReference = $configurator->getArgument('$activityContainer');
        static::assertInstanceOf(Reference::class, $locatorReference);
        $locator = $container->getDefinition((string) $locatorReference);
        static::assertSame(ServiceLocator::class, $locator->getClass());

        $services = $locator->getArgument(0);
        static::assertIsArray($services);
        static::assertSame(['greeting_activity', \stdClass::class], array_keys($services));
        foreach ($services as $id => $service) {
            static::assertInstanceOf(ServiceClosureArgument::class, $service);
            static::assertSame($id, (string) $service->getValues()[0]);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tags(): iterable
    {
        yield 'activity' => [WebfTemporalBundle::ACTIVITY_TAG];
        yield 'workflow' => [WebfTemporalBundle::WORKFLOW_TAG];
    }

    #[DataProvider('tags')]
    public function test_abstract_tagged_services_are_rejected(string $tag): void
    {
        $container = new ContainerBuilder();
        $container->register(WebfTemporalBundle::WORKER_FACTORY_CONFIGURATOR_ID, WorkerFactoryConfigurator::class);
        $container->register('abstract_service', GreetingActivity::class)->setAbstract(true)->addTag($tag);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'The service "abstract_service" tagged "%s" must not be abstract.',
            $tag,
        ));

        (new RegisterWorkflowsAndActivitiesPass())->process($container);
    }

    public function test_class_of_child_definitions_is_inherited_from_their_parents(): void
    {
        $container = new ContainerBuilder();
        $configurator = $container->register(
            WebfTemporalBundle::WORKER_FACTORY_CONFIGURATOR_ID,
            WorkerFactoryConfigurator::class,
        );
        $container->register('parent_activity', GreetingActivity::class)->setAbstract(true);
        $container->setDefinition('intermediate_activity', new ChildDefinition('parent_activity'))->setAbstract(true);
        $container
            ->setDefinition('child_activity', new ChildDefinition('intermediate_activity'))
            ->addTag(WebfTemporalBundle::ACTIVITY_TAG);
        $container->register('parent_workflow', GreetingWorkflow::class)->setAbstract(true);
        $container
            ->setDefinition('child_workflow', new ChildDefinition('parent_workflow'))
            ->addTag(WebfTemporalBundle::WORKFLOW_TAG);

        (new RegisterWorkflowsAndActivitiesPass())->process($container);

        static::assertSame(
            ['child_activity' => GreetingActivity::class],
            $configurator->getArgument('$activityClasses'),
        );
        static::assertSame([GreetingWorkflow::class], $configurator->getArgument('$workflowClasses'));
    }
}
