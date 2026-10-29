<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Fixtures;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Webf\TemporalBundle\WebfTemporalBundle;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    private readonly string $varDir;

    /**
     * @param array<string, mixed> $temporalConfig
     */
    public function __construct(
        private readonly array $temporalConfig,
    ) {
        parent::__construct('test', false);

        // A fresh directory for each kernel, otherwise a container compiled by a previous test (or a previous run,
        // hiding `WebfTemporalBundle::build()` from code coverage and mutation testing) would be reused.
        $this->varDir = sprintf('%s/webf-temporal-bundle/%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
    }

    #[\Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new WebfTemporalBundle();
    }

    #[\Override]
    public function getProjectDir(): string
    {
        return dirname(__DIR__, levels: 2);
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return $this->varDir . '/cache';
    }

    #[\Override]
    public function getLogDir(): string
    {
        return $this->varDir . '/log';
    }

    public function removeVarDir(): void
    {
        (new Filesystem())->remove($this->varDir);
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'http_method_override' => false,
            'test' => true,
        ]);
        $container->extension('webf_temporal', $this->temporalConfig);

        $container
            ->services()
            ->defaults()
            ->autowire()
            ->autoconfigure()
            ->load('Tests\\Webf\\TemporalBundle\\Fixtures\\', __DIR__)
            ->exclude([__DIR__ . '/{StopLoop,StoppingClock,TestKernel}.php']);
    }
}
