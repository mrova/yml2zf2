yml2zf2
=======

Generate Zend Framework 2 structure, router and more from .yml config


### remember

Add this to Application/config/module.config.php

It's make possible to extend the Zend\View\Helper\Url 

```php
'view_helpers' => array(
    'initializers' => array(
        function ($instance, $sm) {
            if ($instance instanceof \Zend\View\Helper\Url) {
                $serviceLocator = $sm->getServiceLocator();
                
                $router = \Zend\Console\Console::isConsole() ? 'HttpRouter' : 'Router';
                $instance->setRouter($serviceLocator->get($router));

                $match = $serviceLocator->get('application')
                    ->getMvcEvent()
                    ->getRouteMatch();

                if ($match instanceof RouteMatch) {
                    $instance->setRouteMatch($match);
                }
            }
        }
    )
),
```
