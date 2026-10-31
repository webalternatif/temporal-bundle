<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
            $workflowClasses[] = $this->getClass($container, $id);
        }

        $container
            ->getDefinition(WebfTemporalBundle::WORKER_FACTORY_CONFIGURATOR_ID)
            ->setArgument('$activityClasses', $activityClasses)
            ->setArgument('$workflowClasses', $workflowClasses)
            ->setArgument('$activityContainer', ServiceLocatorTagPass::register($container, $activityReferences));
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
