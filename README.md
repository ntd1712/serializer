# Factory for creating Serializer objects

[Serializer](https://github.com/schmittjoh/serializer) is a library for (de-)serializing data of any complexity
(supports JSON, and XML).

## Installation

#### Library

```bash
git clone https://github.com/ntd1712/serializer.git
```

#### Composer

This can be installed with [Composer](https://getcomposer.org/doc/00-intro.md)

Define the following requirement in your `composer.json` file.

```json
{
    "require": {
        "chaos/serializer": "*"
    },

    "repositories": [
      {
        "type": "vcs",
        "url": "https://github.com/ntd1712/serializer"
      }
    ]
}
```

#### Usage

```php
<?php // For example, in Laravel

use Chaos\Support\Serializer\SerializerFactory;

$serializer = (new SerializerFactory())($container, null, config('serializer'));
$container['serializer'] = $serializer;
```

#### Sample Configuration

In Laravel you can create your own configuration in config\serializer.php, for example:

```php
<?php

return [
    'handlers' => [
        'datetime' => [
            'id' => 'JMS\\Serializer\\Handler\\DateHandler',
            'default_format' => 'Y-m-d\\TH:i:sP',
            'default_timezone' => 'UTC',
            'xml_cdata' => true,
        ],
        'object' => [
            'id' => 'JMS\\Serializer\\Handler\\StdClassHandler',
        ],
        'array_collection' => [
            'id' => 'JMS\\Serializer\\Handler\\ArrayCollectionHandler',
            'initialize_excluded' => true,
        ],
        'iterator' => [
            'id' => 'JMS\\Serializer\\Handler\\IteratorHandler',
        ],
    ],
    'subscribers' => [
        'doctrine_proxy' => [
            'id' => 'JMS\\Serializer\\EventDispatcher\\Subscriber\\DoctrineProxySubscriber',
            'skip_virtual_type_init' => true,
            'initialize_excluded' => false,
        ],
    ],
    'object_constructor' => [
        'doctrine' => [
            'id' => 'JMS\\Serializer\\Construction\\DoctrineObjectConstructor',
            'fallback_strategy' => 'null',
        ],
    ],
    'property_naming' => [
        'id' => 'JMS\\Serializer\\Naming\\IdenticalPropertyNamingStrategy',
    ],
    'metadata' => [
        'cache' => 'doctrine',
        'debug' => true,
        'file_cache' => [
            'dir' => base_path('storage/framework/cache'),
        ],
        'auto_detection' => true,
        'directories' => null,
    ],
    'manager_registry' => null,
    'default_context' => [
        'serialization' => [
            'serialize_null' => true,
            'enable_max_depth_checks' => true,
        ],
        'deserialization' => null,
    ],
    'visitors' => null,
];
```
