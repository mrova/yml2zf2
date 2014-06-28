<?php

/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/ZendSkeletonApplication for the canonical source repository
 * @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Application\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Zend\Console\Request as ConsoleRequest;

class IndexController extends AbstractActionController {

    const OUTPUT_PATH = 'output/';

    public function indexAction() {
        return new ViewModel();
    }

    public function genAction() {
        $request = $this->getRequest();
        if (!$request instanceof ConsoleRequest) {
            throw new \RuntimeException('You can only use this action from a console!');
        }
        $filename = $request->getParam('config');
        if (!file_exists($filename)) {
            return 'File ' . $filename . ' does not exist!';
        }
        $reader = new \Zend\Config\Reader\Yaml();
        $data = $reader->fromFile($filename);

        $module = '';
        if ($module = $request->getParam('module')) {
            if (!isset($data[$module])) {
                return 'No configuration for module ' . $module;
            }
            $data = array($module => $data[$module]);
        }

        foreach ($data as $module => $config) {
            $this->_dirStructure($module, array_keys($config['controllers']));
            $this->_module($module);
            $this->_config($module, $config['controllers']);
            $this->_controllers($config['controllers'], $module);
            $this->_views($config['controllers'], $module);
        }

        return 'done!';
    }

    /**
     * Generate Module.php
     * 
     * @param type $module
     */
    protected function _module($module) {
        $class = $this->_getDefaultModuleClass($module);
        
        $file = $this->_newFile();
        $file->setClass($class);

        file_put_contents(
                $this->_getModulePath($module) . '/'
                . 'Module.php', $file->generate()
        );
    }
    
    /**
     * Get default module class
     * 
     * @param type $module
     * @return \Zend\Code\Generator\ClassGenerator
     */
    protected function _getDefaultModuleClass($module) {
        $class = \Zend\Code\Generator\ClassGenerator::fromReflection(new \Zend\Code\Reflection\ClassReflection('\Application\Data\Module'));
        $class->setNamespaceName($this->_getModuleName($module));
        
        return $class;
    }
    
    /**
     * Generate module config
     * 
     * @param array $config
     * @param string $controllers
     */
    protected function _config($module, array $controllers) {
        $router = $this->_router($module, $controllers);
        $controllers = $this->_configControllers($module, $controllers);
        $file = $this->_newFile();

        $body = 'return ' . new \Zend\Code\Generator\ValueGenerator($router + $controllers) . ';';
        $file->setBody($body);

        file_put_contents(
                $this->_getConfigPath($module) . '/'
                . 'module.config.php', $file->generate()
        );
    }
    
    protected function _configControllers($module, array $controllers) {
        $result = [];
        foreach (array_keys($controllers) as $controller) {
            $name = $this->_getControllerFullName($module, $controller);
            $result[$name] = $name . 'Controller';
        }
        return [
            'controllers' => array(
                'invokables' => $result
            )
        ];
    }
            

    /**
     * Extract router from configuration
     * 
     * @param array $module config for given module
     * @param string $controllers
     */
    protected function _router($module, array $controllers) {
        return [
            'router' => [
                'routes' => $this->_prepareRouter(
                        $this->_extractRouter($controllers), $module, null
                )
            ]
        ];
    }

    /**
     * Prepare router array recursively 
     * 
     * @param array $routes
     * @param string $module
     * @param string|null $parent
     * @return null|array
     */
    protected function _prepareRouter(array $routes, $module, $parent = null) {
        $result = [];
        foreach ($routes as $routeName => $route) {
            $routeParent = isset($route['parent']) ? $route['parent'] : null;
            if ($routeParent === $parent) {
                $newRoute = [
                    'type' => $route['type'],
                    'options' => $this->_getRouteOptions($route, $module)
                ];
                if ($childRoutes = $this->_prepareRouter($routes, $module, $routeName)) {
                    $newRoute['may_terminate'] = true;
                    $newRoute['child_routes'] = $childRoutes;
                }
                $result[$this->_getRouteKey($routeName)] = $newRoute;
            }
        }
        if (count($result) > 0) {
            return $result;
        }
        return null;
    }

    /**
     * Extract router from confuration
     * 
     * @param array $controllers
     * @return array
     */
    protected function _extractRouter(array $controllers) {
        $router = [];
        foreach ($controllers as $controller => $actions) {
            foreach ($actions as $action => $config) {
                if (isset($config['router'])) {
                    $route = $config['router'];
                    $route['controller'] = $controller;
                    $route['action'] = $action;
                    $router[$controller . '/' . $action] = $route;
                }
            }
        }

        return $router;
    }

    /**
     * Get route options
     * 
     * @param array $route
     * @param string $module
     * @return array
     */
    protected function _getRouteOptions(array $route, $module) {
        $controller = $route['controller'];
        $action = $route['action'];
        $type = $route['type'];
        $routeWithParamsType = $route['route'];

        return [
            'route' => $this->_getRouteWithoutParamsType($routeWithParamsType),
            'defaults' => $this->_getRouteDefaults(
                    $module, $controller, $action
            ),
            'constraints' => $this->_getConstraints($routeWithParamsType, $type)
        ];
    }

    /**
     * Get route key
     * 
     * @param string $routeName
     * @return string
     */
    protected function _getRouteKey($routeName) {
        return implode(
                '', \array_map(function($v) {
                    return ucfirst($v);
                }, explode('/', $routeName)
                )
        );
    }

    /**
     * Get contraints for given route
     * 
     * @param string $routeWithParamsType
     * @param string $routeType
     * @return array
     */
    protected function _getConstraints($routeWithParamsType, $routeType) {
        $constraints = [];
        if ($routeType === 'Segment' /* #TODO: || isSegment($route) */) {
            $regexp = '/(?::([a-zA-Z0-9]+)(?:\|(int|text))?)+/s';
            $match = [];
            if (preg_match_all($regexp, $routeWithParamsType, $match)) {
                foreach ($match[1] as $key => $param) {
                    #TODO:  maybe exception would be better 
                    #       instead of setting default value?
                    $constraintType = 'text';
                    if (isset($match[2][$key]) && !empty($match[2][$key])) {
                        $constraintType = $match[2][$key];
                    }
                    $constraints[$param] = $this->_getConstraintByCode($constraintType);
                }
            }
        }

        return $constraints;
    }

    /**
     * Get constraint by code
     * 
     * @param string $type
     * @return string
     * @throws \InvalidArgumentException
     */
    protected function _getConstraintByCode($type) {
        switch ($type) {
            case 'text':
                return '[a-zA-Z0-9-_]+';
            case 'int':
                return '[0-9]+';
            default:
                $msg = 'Constraint type "' . $type . '" is not suported';
                throw new \InvalidArgumentException($msg);
        }
    }

    /**
     * Removes params type from route
     * 
     * @param string $routeWithParams
     * @return string
     */
    protected function _getRouteWithoutParamsType($routeWithParams) {
        return preg_replace('/\|(text|int)/', '', $routeWithParams);
    }

    /**
     * Get route defaults
     * 
     * @param string $module
     * @param string $controller
     * @param string $action
     * @return array
     */
    protected function _getRouteDefaults($module, $controller, $action) {
        return [
            'controller' => $this->_getControllerFullName($module, $controller),
            'action' => $this->_getActionName($action)
        ];
    }

    /**
     * Generate controllers
     * 
     * @param array $controllers
     * @param string $module
     */
    protected function _controllers(array $controllers, $module) {
        foreach ($controllers as $controller => $config) {
            $class = \Zend\Code\Generator\ClassGenerator::fromArray([
                        'name' => $this->_getControllerName($controller) . 'Controller',
                        'namespacename' => $this->_getModuleName($module) . '\Controller',
                        'extendedclass' => '\Zend\Mvc\Controller\AbstractActionController',
                        'methods' => array_map(
                                function($val) {
                            return $val . 'Action';
                        }, array_keys($config)
                        )
            ]);

            $file = $this->_newFile();
            $file->setClass($class);

            file_put_contents(
                    $this->_getControllerPath($module) . '/'
                    . $this->_getControllerName($controller)
                    . 'Controller.php', $file->generate()
            );
        }
    }

    /**
     * Generate empty view files
     * 
     * @param array $controllers
     * @param string $module
     */
    protected function _views(array $controllers, $module) {
        foreach ($controllers as $controller => $config) {
            foreach (array_keys($config) as $action) {
                file_put_contents(
                        $this->_getViewPath($module, $controller) . '/'
                        . $this->_getViewName($action)
                        . '.phtml', $config[$action]['template']
                );
            }
        }
    }

    /**
     * Generuje strukturę katalogów
     * 
     * @param string $module
     * @param array $controllers
     */
    protected function _dirStructure($module, $controllers = array()) {
        $module = $this->_getModuleName($module);
        $modulePath = $this->_getModulePath($module);
        if (file_exists($modulePath)) {
            return;
        }
        mkdir($modulePath);
        mkdir($this->_getConfigPath($module));
        mkdir($modulePath . '/src');
        mkdir($modulePath . '/src/' . $module);
        mkdir($this->_getControllerPath($module));
        mkdir($modulePath . '/src/' . $module . '/Entity');
        mkdir($modulePath . '/src/' . $module . '/Factory');
        mkdir($modulePath . '/src/' . $module . '/Form');
        mkdir($modulePath . '/src/' . $module . '/Mapper');
        mkdir($modulePath . '/src/' . $module . '/Model');
        mkdir($modulePath . '/src/' . $module . '/Options');
        mkdir($modulePath . '/src/' . $module . '/Sevice');
        mkdir($modulePath . '/src/' . $module . '/View');
        mkdir($modulePath . '/src/' . $module . '/View/Helper');
        mkdir($modulePath . '/view');
        mkdir($this->_getViewPath($module));
        foreach ($controllers as $controller) {
            mkdir($this->_getViewPath($module, $controller));
        }
    }

    /**
     * Returns path to config
     * 
     * @param string $module
     * @return string
     */
    protected function _getConfigPath($module) {
        return $this->_getModulePath($module) . '/config';
    }

    /**
     * Returns path to controllers
     * 
     * @param string $module
     * @return string
     */
    protected function _getControllerPath($module) {
        return $this->_getModulePath($module) . '/src/' . $module . '/Controller';
    }

    /**
     * Returns module name 
     * 
     * @param string $module
     * @return string
     */
    protected function _getModuleName($module) {
        return ucfirst($module);
    }

    /**
     * Returns controller name 
     * 
     * @param string $controller
     * @return string
     */
    protected function _getControllerName($controller) {
        return ucfirst($controller);
    }

    /**
     * Returns full controller name 
     * 
     * @param string $module
     * @param string $controller
     * @return string
     */
    protected function _getControllerFullName($module, $controller) {
        return $this->_getModuleName($module) . '\Controller\\' . $this->_getControllerName($controller);
    }

    /**
     * Returns action name 
     * 
     * @param string $action
     * @return string
     */
    protected function _getActionName($action) {
        return ucfirst($action);
    }

    /**
     * Returns view file name 
     * 
     * @param string $action
     * @return string
     */
    protected function _getViewName($action) {
        return strtolower($action);
    }

    /**
     * Returns path to views for given module
     * 
     * @param string $module
     * @return string
     */
    protected function _getViewPath($module, $controller = null) {
        return $this->_getModulePath($module) . '/view/' . strtolower($module)
                . ($controller ? '/' . strtolower($controller) : '');
    }

    /**
     * Returns module path
     * 
     * @param string $module
     * @return string
     */
    protected function _getModulePath($module) {
        return self::OUTPUT_PATH . $this->_getModuleName($module);
    }
    
    /**
     * New file
     * 
     * @return \Zend\Code\Generator\FileGenerator
     */
    protected function _newFile() {
        $file = new \Zend\Code\Generator\FileGenerator();
        $file->setDocBlock($this->_getFileDocBlock());
        
        return $file;
    }
            
    /**
     * Returns file DocBlock from sample file
     * 
     * @return \Zend\Code\Generator\DocBlockGenerator
     */
    protected function _getFileDocBlock() {
        require_once __DIR__ . '/../Data/FileDocBlock.php';
        $docBlockFile = \Zend\Code\Generator\FileGenerator::fromReflection(new \Zend\Code\Reflection\FileReflection(__DIR__ . '/../Data/FileDocBlock.php'));
        
        return $docBlockFile->getDocBlock();
    }

}
