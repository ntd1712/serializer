<?php

declare(strict_types=1);

namespace Chaos\Support\Serializer\EventDispatcher\Subscriber;

use Doctrine\Common\Persistence\Proxy as LegacyProxy;
use Doctrine\ODM\MongoDB\PersistentCollection as MongoDBPersistentCollection;
use Doctrine\ODM\PHPCR\PersistentCollection as PHPCRPersistentCollection;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\Proxy;
use JMS\Serializer\EventDispatcher\EventDispatcherInterface;
use JMS\Serializer\EventDispatcher\EventSubscriberInterface;
use JMS\Serializer\EventDispatcher\PreSerializeEvent;
use ProxyManager\Proxy\LazyLoadingInterface;

/**
 * Class DoctrineProxySubscriber.
 */
class DoctrineProxySubscriber implements EventSubscriberInterface
{
    /**
     * @var bool
     */
    private $skipVirtualTypeInit = true;

    /**
     * @var bool
     */
    private $initializeExcluded = false;

    /**
     * Constructor.
     *
     * @param bool $skipVirtualTypeInit Optional.
     * @param bool $initializeExcluded Optional.
     */
    public function __construct($skipVirtualTypeInit = true, $initializeExcluded = false)
    {
        $this->skipVirtualTypeInit = (bool) $skipVirtualTypeInit;
        $this->initializeExcluded = (bool) $initializeExcluded;
    }

    /**
     * {@inheritDoc}
     *
     * @param PreSerializeEvent $event The PreSerializeEvent instance.
     *
     * @return void
     */
    public function onPreSerialize(PreSerializeEvent $event)
    {
        $object = $event->getObject();
        $type = $event->getType();

        // If the set type name is not an actual class, but a faked type for which a custom handler exists, we do not
        // modify it with this subscriber, so it must be loaded if its a real class.
        $virtualType = !class_exists($type['name'], false);

        if (
            $object instanceof PersistentCollection
            || $object instanceof MongoDBPersistentCollection
            || $object instanceof PHPCRPersistentCollection
        ) {
            if (!$virtualType) {
                $event->setType('ArrayCollection');
            }

            return;
        }

        if (
            ($this->skipVirtualTypeInit && $virtualType)
            || (!$object instanceof Proxy && !$object instanceof LazyLoadingInterface)
        ) {
            return;
        }

        // do not initialize the proxy if is going to be excluded by-class by some exclusion strategy
        if (false === $this->initializeExcluded && !$virtualType) {
            $context = $event->getContext();
            $exclusionStrategy = $context->getExclusionStrategy();
            /* @var mixed $metadata */
            $metadata = $context->getMetadataFactory()->getMetadataForClass(get_parent_class($object));

            if (
                null !== $metadata
                && null !== $exclusionStrategy
                && $exclusionStrategy->shouldSkipClass($metadata, $context)
            ) {
                return;
            }
        }

        if ($object instanceof LazyLoadingInterface) {
            $object->initializeProxy();
        } else {
            $object->__load();
        }

        if (!$virtualType) {
            $event->setType(get_parent_class($object), $type['params']);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param PreSerializeEvent $event The PreSerializeEvent instance.
     * @param string $eventName Event name.
     * @param string $class Class name.
     * @param string $format Format.
     * @param EventDispatcherInterface $dispatcher The EventDispatcher instance.
     *
     * @return void
     */
    public function onPreSerializeTypedProxy(
        PreSerializeEvent $event,
        $eventName,
        $class,
        $format,
        EventDispatcherInterface $dispatcher
    ) {
        $type = $event->getType();

        // is a virtual type? then there is no need to change the event name
        if (!class_exists($type['name'], false)) {
            return;
        }

        $object = $event->getObject();

        if ($object instanceof Proxy) {
            $parentClassName = get_parent_class($object);

            // check if this is already a re-dispatch
            if (strtolower($class) !== strtolower($parentClassName)) {
                $event->stopPropagation();
                $newEvent = new PreSerializeEvent(
                    $event->getContext(),
                    $object,
                    ['name' => $parentClassName, 'params' => $type['params']]
                );
                $dispatcher->dispatch($eventName, $parentClassName, $format, $newEvent);

                // update the type in case some listener changed it
                $newType = $newEvent->getType();
                $event->setType($newType['name'], $newType['params']);
            }
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            [
                'event' => 'serializer.pre_serialize',
                'method' => 'onPreSerializeTypedProxy',
                'interface' => Proxy::class
            ],
            [
                'event' => 'serializer.pre_serialize',
                'method' => 'onPreSerializeTypedProxy',
                'interface' => LegacyProxy::class
            ],
            [
                'event' => 'serializer.pre_serialize',
                'method' => 'onPreSerialize',
                'interface' => PersistentCollection::class
            ],
            [
                'event' => 'serializer.pre_serialize',
                'method' => 'onPreSerialize',
                'interface' => MongoDBPersistentCollection::class
            ],
            [
                'event' => 'serializer.pre_serialize',
                'method' => 'onPreSerialize',
                'interface' => PHPCRPersistentCollection::class
            ],
            [
                'event' => 'serializer.pre_serialize',
                'method' => 'onPreSerialize',
                'interface' => Proxy::class
            ],
            [
                'event' => 'serializer.pre_serialize',
                'method' => 'onPreSerialize',
                'interface' => LegacyProxy::class
            ],
            [
                'event' => 'serializer.pre_serialize',
                'method' => 'onPreSerialize',
                'interface' => LazyLoadingInterface::class
            ]
        ];
    }
}
