<?php

declare(strict_types=1);

namespace Kmergen\MediaBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class MediaBundle extends AbstractBundle
{
  public function configure(DefinitionConfigurator $definition): void
  {
    // @formatter:off
    $definition->rootNode()
      ->children()
         ->scalarNode('phrase')->defaultValue('Hallo Ihr da')->end()
      ->end();
    // @formatter:on
  }

  public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
  {
    // load an XML, PHP or YAML file
    $container->import('../config/services.yaml');

    // you can also add or replace parameters and services
    $container->parameters()
      ->set('media.phrase', $config['phrase']);
  }
}
