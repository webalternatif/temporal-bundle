<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\DependencyInjection\Compiler;

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

        $activityClasses = [];
        $activityReferences = [];
        foreach (array_keys($container->findTaggedServiceIds(WebfTemporalBundle::ACTIVITY_TAG)) as $id) {
            $activityClasses[$id] = $this->getClass($container, $id);
            $activityReferences[$id] = new Reference($id);
        }

        $workflowClasses = [];
        foreach (array_keys($container->findTaggedServiceIds(WebfTemporalBundle::WORKFLOW_TAG)) as $id) {
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
        // `ResolveClassPass` only infers the class of services whose id is a class name, a definition may still have none.
        return $container->findDefinition($id)->getClass() ?? $id;
    }
}
