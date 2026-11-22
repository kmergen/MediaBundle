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
         // Role that may manage all media. Applications can grant more with their own voter
         // for MediaAlbumVoter::MANAGE (e.g. the owner of an album).
         ->scalarNode('manage_role')->defaultValue('ROLE_ADMIN')->cannotBeEmpty()->end()
         ->arrayNode('upload')
           ->addDefaultsIfNotSet()
           ->children()
             ->integerNode('max_file_size')->info('Maximum upload size in MB')->defaultValue(10)->min(1)->end()
             ->arrayNode('allowed_mime_types')
               ->info('MIME types detected from the file content; only raster images are supported')
               ->scalarPrototype()->end()
               ->defaultValue(['image/jpeg', 'image/png', 'image/webp'])
             ->end()
           ->end()
         ->end()
      ->end();
    // @formatter:on
  }

  public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
  {
    // load an XML, PHP or YAML file
    $container->import('../config/services.yaml');

    // you can also add or replace parameters and services
    $container->parameters()
      ->set('media.phrase', $config['phrase'])
      ->set('kmergen_media.manage_role', $config['manage_role'])
      ->set('kmergen_media.upload.max_file_size', $config['upload']['max_file_size'])
      ->set('kmergen_media.upload.allowed_mime_types', $config['upload']['allowed_mime_types']);
  }
}
