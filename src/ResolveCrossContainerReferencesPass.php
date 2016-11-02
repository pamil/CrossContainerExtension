<?php

namespace FriendsOfBehat\CrossContainerExtension;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class ResolveCrossContainerReferencesPass implements CompilerPassInterface
{
    /**
     * @var ContainerAccessor[]
     */
    private $containerAccessors;

    /**
     * @param ContainerAccessor[] $containerAccessors
     */
    public function __construct(array $containerAccessors)
    {
        $this->containerAccessors = $containerAccessors;
    }

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        foreach ($container->getDefinitions() as $identifier => $definition) {
            $container->setDefinition($identifier, $this->resolveDefinition($container, $definition));
        }

        $this->copyParameters($container);
    }

    /**
     * @param ContainerBuilder $container
     * @param Definition $definition
     *
     * @return Definition
     */
    private function resolveDefinition(ContainerBuilder $container, Definition $definition)
    {
        $definition->setArguments($this->resolveArguments($container, $definition->getArguments()));

        return $definition;
    }

    /**
     * @param ContainerBuilder $container
     * @param array $arguments
     *
     * @return array
     */
    private function resolveArguments(ContainerBuilder $container, array $arguments)
    {
        return array_map(function ($argument) use ($container) {
            return $this->resolveArgument($container, $argument);
        }, $arguments);
    }

    /**
     * @param ContainerBuilder $container
     * @param mixed $argument
     *
     * @return mixed
     */
    private function resolveArgument(ContainerBuilder $container, $argument)
    {
        if ($argument instanceof Definition) {
            return $this->resolveDefinition($container, $argument);
        }

        if ($argument instanceof Reference) {
            return $this->resolveReference($container, $argument);
        }

        if (is_array($argument)) {
            return $this->resolveArguments($container, $argument);
        }

        return $argument;
    }

    /**
     * @param ContainerBuilder $container
     * @param Reference $reference
     *
     * @return Definition|Reference
     */
    private function resolveReference(ContainerBuilder $container, Reference $reference)
    {
        $containerIdentifier = preg_replace('/^__([^_]+)__\..+$/', '$1', (string) $reference);

        if (!isset($this->containerAccessors[$containerIdentifier])) {
            return $reference;
        }

        $serviceIdentifier = preg_replace('/^__[^_]+__\.(.+)$/', '$1', (string) $reference);

        return $this->transformReferenceToDefinition($container, $containerIdentifier, $serviceIdentifier);
    }

    /**
     * @param ContainerBuilder $container
     * @param string $containerIdentifier
     * @param string $serviceIdentifier
     *
     * @return Definition
     */
    private function transformReferenceToDefinition(ContainerBuilder $container, $containerIdentifier, $serviceIdentifier)
    {
        $containerAccessorIdentifier = sprintf('__%s__', $containerIdentifier);
        if (!$container->has($containerAccessorIdentifier)) {
            $container->set($containerAccessorIdentifier, $this->containerAccessors[$containerIdentifier]);
        }

        $definition = new Definition(null, [$serviceIdentifier]);
        $definition->setFactory([new Reference($containerAccessorIdentifier), 'getService']);

        return $definition;
    }

    /**
     * @param ContainerBuilder $container
     */
    private function copyParameters(ContainerBuilder $container)
    {
        foreach ($this->containerAccessors as $containerIdentifier => $containerAccessor) {
            foreach ($containerAccessor->getParameters() as $name => $value) {
                $container->setParameter(sprintf('__%s__.%s', $containerIdentifier, $name), $value);
            }
        }
    }
}
