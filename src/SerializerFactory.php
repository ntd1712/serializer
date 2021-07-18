<?php

namespace Chaos\Support\Serializer;

use JMS\Serializer\Builder\CallbackDriverFactory;
use JMS\Serializer\Builder\DefaultDriverFactory;
use JMS\Serializer\Construction;
use JMS\Serializer\DeserializationContext;
use JMS\Serializer\EventDispatcher\EventDispatcherInterface;
use JMS\Serializer\Handler\HandlerRegistryInterface;
use JMS\Serializer\Metadata\Driver\DoctrineTypeDriver;
use JMS\Serializer\Naming\CamelCaseNamingStrategy;
use JMS\Serializer\Naming\SerializedNameAnnotationStrategy;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerBuilder;
use JMS\Serializer\Visitor\Factory\JsonDeserializationVisitorFactory;
use JMS\Serializer\Visitor\Factory\JsonSerializationVisitorFactory;
use JMS\Serializer\Visitor\Factory\XmlDeserializationVisitorFactory;
use JMS\Serializer\Visitor\Factory\XmlSerializationVisitorFactory;
use Metadata\Cache\DoctrineCacheAdapter;
use ReflectionClass;
use ReflectionException;

/**
 * Class SerializerFactory.
 *
 * <code>
 * $serializer = (new SerializerFactory())($container, null, $config['serializer']);
 * $container['serializer'] = $serializer;
 * </code>
 *
 * @author t(-.-t) <ntd1712@mail.com>
 */
class SerializerFactory
{
    /**
     * Creates a <tt>Serializer</tt> object.
     *
     * @param \Psr\Container\ContainerInterface $container The Container instance.
     * @param string $requestedName The ID of the object being instantiated.
     * @param null|array $options An array of configuration relevant to the object.
     *
     * @return \JMS\Serializer\Serializer|\JMS\Serializer\SerializerInterface
     */
    public function __invoke($container, $requestedName, array $options = null)
    {
        if ($container->has('doctrine')) {
            $managerRegistry = $container->get('doctrine');
        } elseif (null !== ($managerRegistry = $options['manager_registry'])) {
            $managerRegistry = self::make($managerRegistry);

            if (method_exists($managerRegistry, 'setContainer')) {
                $managerRegistry->setContainer($container);
            }
        }

        $serializerBuilder = SerializerBuilder::create()
            ->includeInterfaceMetadata(!empty($options['metadata']['include_interface_metadata']));

        if (empty($options['metadata']['debug'])) {
            $serializerBuilder->setCacheDir(
                $cacheDir = $options['metadata']['file_cache']['dir'] ?: sys_get_temp_dir()
            );

            switch ($options['metadata']['cache']) {
                case 'doctrine':
                    /* @var \Doctrine\Common\Cache\CacheProvider $metadataCacheImpl */
                    $metadataCacheImpl = $managerRegistry->getManager()->getConfiguration()->getMetadataCacheImpl();

                    if (null !== $metadataCacheImpl) {
                        $prefix = is_subclass_of($metadataCacheImpl, 'Doctrine\Common\Cache\Cache')
                            ? $metadataCacheImpl->getNamespace()
                            : 'dc2_' . md5($cacheDir) . '_';
                        $serializerBuilder->setMetadataCache(new DoctrineCacheAdapter($prefix, $metadataCacheImpl));
                    }
                    break;
                //  case 'psr':
                //      break;
                default:
            }
        } else {
            $serializerBuilder->setDebug(true);
        }

        if (empty($options['metadata']['auto_detection'])) {
            foreach ($options['metadata']['directories'] as $args) {
                $serializerBuilder->addMetadataDir($args['path'], @$args['namespace_prefix']);
            }
        }

        if (isset($options['metadata']['annotation_reader'])) {
            $serializerBuilder->setAnnotationReader(self::make($options['metadata']['annotation_reader']));
        }

        if (isset($options['property_naming'])) {
            $serializerBuilder->setPropertyNamingStrategy(
                $propertyNamingStrategy = new SerializedNameAnnotationStrategy(
                    self::make($options['property_naming'])
                )
            );
        } else {
            $propertyNamingStrategy = new SerializedNameAnnotationStrategy(
                new CamelCaseNamingStrategy()
            );
        }

        if (isset($options['type_parser'])) {
            $serializerBuilder->setTypeParser(
                $typeParser = self::make($options['type_parser'])
            );
        } else {
            $typeParser = null;
        }

        // <editor-fold desc="http://jmsyst.com/libs/serializer/master/cookbook/exclusion_strategies">

        if (isset($options['expression_evaluator'])) {
            $serializerBuilder->setExpressionEvaluator(
                $expressionEvaluator = self::make($options['expression_evaluator'])
            );
        } else {
            $expressionEvaluator = null;
        }

        // </editor-fold>

        if (isset($options['metadata']['driver_factory'])) {
            $serializerBuilder->setMetadataDriverFactory(
                new CallbackDriverFactory(
                    static function (
                        array $metadataDirs,
                        $annotationReader
                    ) use (
                        $managerRegistry,
                        $propertyNamingStrategy,
                        $typeParser,
                        $expressionEvaluator
                    ) {
                        $defaultFactory = new DefaultDriverFactory(
                            $propertyNamingStrategy,
                            $typeParser,
                            $expressionEvaluator
                        );

                        return new DoctrineTypeDriver(
                            $defaultFactory->createDriver($metadataDirs, $annotationReader),
                            $managerRegistry
                        );
                    }
                )
            );
        }

        // <editor-fold defaultstate="collapsed" desc="http://jmsyst.com/libs/serializer/master/handlers">

        if (!empty($options['handlers'])) {
            foreach ($options['handlers'] as $args) {
                $serializerBuilder->configureHandlers(
                    function (HandlerRegistryInterface $registry) use ($args) {
                        $registry->registerSubscribingHandler(self::make($args));
                    }
                );
            }
        }

        // </editor-fold>

        // <editor-fold defaultstate="collapsed" desc="http://jmsyst.com/libs/serializer/master/event_system">

        if (!empty($options['subscribers'])) {
            foreach ($options['subscribers'] as $args) {
                $serializerBuilder->configureListeners(
                    function (EventDispatcherInterface $dispatcher) use ($args) {
                        $dispatcher->addSubscriber(self::make($args));
                    }
                );
            }
        }

        // </editor-fold>

        if (!empty($options['visitors'])) {
            foreach ($options['visitors'] as $visitor => $args) {
                switch ($visitor) {
                    case 'json_serialization':
                        $serializerBuilder->setSerializationVisitor(
                            'json',
                            (new JsonSerializationVisitorFactory())
                                ->setOptions($args['options'])
                        );
                        break;
                    case 'json_deserialization':
                        $serializerBuilder->setDeserializationVisitor(
                            'json',
                            (new JsonDeserializationVisitorFactory())
                                ->setOptions($args['options'])
                                ->setDepth($args['depth'])
                        );
                        break;
                    case 'xml_serialization':
                        $serializerBuilder->setSerializationVisitor(
                            'xml',
                            (new XmlSerializationVisitorFactory())
                                ->setFormatOutput($args['format_output'])
                                ->setDefaultVersion($args['version'])
                                ->setDefaultEncoding($args['encoding'])
                                ->setDefaultRootName($args['default_root_name'], $args['default_root_ns'])
                        );
                        break;
                    case 'xml_deserialization':
                        $serializerBuilder->setDeserializationVisitor(
                            'xml',
                            (new XmlDeserializationVisitorFactory())
                                ->enableExternalEntities($args['external_entities'])
                                ->setDoctypeWhitelist($args['doctype_whitelist'])
                        );
                        break;
                    default:
                }
            }
        }

        // <editor-fold defaultstate="collapsed" desc="http://jmsyst.com/libs/serializer/master/configuration">

        if (null !== ($defaultSerializationContext = $options['default_context']['serialization'])) {
            $serializerBuilder->setSerializationContextFactory(
                function () use ($defaultSerializationContext) {
                    $serializationContext = SerializationContext::create();

                    if (!empty($defaultSerializationContext['serialize_null'])) {
                        $serializationContext->setSerializeNull((bool) $defaultSerializationContext['serialize_null']);
                    }

                    if (!empty($defaultSerializationContext['version'])) {
                        $serializationContext->setVersion((string) $defaultSerializationContext['version']);
                    }

                    if (!empty($defaultSerializationContext['attributes'])) {
                        foreach ($defaultSerializationContext['attributes'] as $key => $value) {
                            $serializationContext->setAttribute($key, $value);
                        }
                    }

                    if (isset($defaultSerializationContext['groups'])) {
                        $serializationContext->setGroups($defaultSerializationContext['groups']);
                    }

                    if (!empty($defaultSerializationContext['enable_max_depth_checks'])) {
                        $serializationContext->enableMaxDepthChecks();
                    }

                    return $serializationContext;
                }
            );
        }

        if (null !== ($defaultDeserializationContext = $options['default_context']['deserialization'])) {
            $serializerBuilder->setDeserializationContextFactory(
                function () use ($defaultDeserializationContext) {
                    $deserializationContext = DeserializationContext::create();

                    if (!empty($defaultDeserializationContext['version'])) {
                        $deserializationContext->setVersion((string) $defaultDeserializationContext['version']);
                    }

                    if (!empty($defaultDeserializationContext['attributes'])) {
                        foreach ($defaultDeserializationContext['attributes'] as $key => $value) {
                            $deserializationContext->setAttribute($key, $value);
                        }
                    }

                    if (isset($defaultDeserializationContext['groups'])) {
                        $deserializationContext->setGroups($defaultDeserializationContext['groups']);
                    }

                    if (!empty($defaultDeserializationContext['enable_max_depth_checks'])) {
                        $deserializationContext->enableMaxDepthChecks();
                    }

                    return $deserializationContext;
                }
            );
        }

        // </editor-fold>

        if (!empty($options['object_constructor'])) {
            foreach ($options['object_constructor'] as $constructor => $args) {
                switch ($constructor) {
                    case 'doctrine':
                        $serializerBuilder->setObjectConstructor(
                            new $args['id'](
                                $managerRegistry,
                                new Construction\UnserializeObjectConstructor(),
                                $args['fallback_strategy']
                            )
                        );
                        break 2;
                    default:
                }
            }
        }

        if (isset($options['accessor'])) {
            $serializerBuilder->setAccessorStrategy(self::make($options['accessor']));
        }

        $serializer = $serializerBuilder->build();

        return $serializer;
    }

    /**
     * @param array $args The arguments.
     * @param null|array $class The class name.
     *
     * @return mixed
     */
    private static function make($args, $class = null)
    {
        try {
            $classname = $class ?: array_shift($args);

            if (!class_exists($classname)) {
                $classname = __NAMESPACE__ . '\\' . $classname;
            }

            $reflectionClass = new ReflectionClass($classname);

            return null !== $args
                ? $reflectionClass->newInstanceArgs($args)
                : $reflectionClass->newInstanceWithoutConstructor();
        } catch (ReflectionException $e) {
            return null;
        }
    }
}
