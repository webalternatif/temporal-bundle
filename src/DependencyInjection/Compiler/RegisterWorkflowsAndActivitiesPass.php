<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Webf\TemporalBundle\WebfTemporalBundle;

/**
 * Gives every service tagged as a Temporal activity or workflow to the worker factory configurator. Activities are
 * instantiated by the container (through a service locator) to benefit from autowiring, while workflows are only
 * registered by class because Temporal instantiates them itself.
 */
final class RegisterWorkflowsAndActivitiesPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(WebfTemporalBundle::WORKER_FACTORY_CONFIGURATOR_ID)) {
            return;
        }

        // Abstract services are rejected with a clear message. Otherwise an abstract activity only fails later with an
        // unclear service locator error, and an abstract workflow class fails at runtime in the SDK.
        $activityClasses = [];
        $activityReferences = [];
        foreach (array_keys($container->findTaggedServiceIds(WebfTemporalBundle::ACTIVITY_TAG, true)) as $id) {
            $activityClasses[$id] = $this->getClass($container, $id);
            $activityReferences[$id] = new Reference($id);
        }

        $workflowClasses = [];
        foreach (array_keys($container->findTaggedServiceIds(WebfTemporalBundle::WORKFLOW_TAG, true)) as $id) {
            $workflowClasses[$id] = $this->getClass($container, $id);
        }

        $this->rejectSharedClasses($container, WebfTemporalBundle::ACTIVITY_TAG, $activityClasses);
        $this->rejectSharedClasses($container, WebfTemporalBundle::WORKFLOW_TAG, $workflowClasses);

        $container
            ->getDefinition(WebfTemporalBundle::WORKER_FACTORY_CONFIGURATOR_ID)
            ->setArgument('$activityClasses', $activityClasses)
            ->setArgument('$workflowClasses', array_values($workflowClasses))
            ->setArgument('$activityContainer', ServiceLocatorTagPass::register($container, $activityReferences));
    }

    /**
     * The SDK registers activities and workflows by class, and a second registration of the same class makes the worker
     * fail at start with an `OutOfBoundsException` that names the activity or workflow, but not the services.
     *
     * @param array<string, string> $classes classes indexed by service id
     */
    private function rejectSharedClasses(ContainerBuilder $container, string $tag, array $classes): void
    {
        $idsByClass = [];
        foreach ($classes as $id => $class) {
            // The class can be a parameter ("%...%"), so it is resolved to compare it with the others.
            $resolvedClass = $container->getParameterBag()->resolveValue($class);
            if (\is_string($resolvedClass)) {
                $idsByClass[$resolvedClass][] = $id;
            }
        }

        foreach ($idsByClass as $class => $ids) {
            if (\count($ids) > 1) {
                throw new InvalidArgumentException(\sprintf(
                    'The services "%s" tagged "%s" share the class "%s". Only one of them can be tagged.',
                    implode('", "', $ids),
                    $tag,
                    $class,
                ));
            }
        }
    }

    private function getClass(ContainerBuilder $container, string $id): string
    {
        // This pass runs before `ResolveChildDefinitionsPass`, so a child definition (`parent:`) has no class when it
        // inherits the one of its parent. The pass cannot run after it: `ResolveNamedArgumentsPass` runs in the same
        // step, and the named arguments set here would then never be resolved. Class parameters ("%...%") are not
        // resolved here because `ResolveParameterPlaceHoldersPass` resolves them later in the arguments set here.
        $definition = $container->findDefinition($id);
        while (null === $definition->getClass() && $definition instanceof ChildDefinition) {
            $definition = $container->findDefinition($definition->getParent());
        }

        // `ResolveClassPass` only infers the class of services whose id is a class name, a definition may still have none.
        return $definition->getClass() ?? $id;
    }
}
