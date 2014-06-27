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

        if ($module = $request->getParam('module')) {
            if (!isset($data[$module])) {
                return 'No configuration for module ' . $module;
            }
            $data = array($module => $data[$module]);
        }

        foreach ($data as $module => $config) {
            $this->_dirStructure($module, array_keys($config['controllers']));
            $this->_controllers($config['controllers'], $module);
            $this->_views($config['controllers'], $module);
        }

        return 'done!';
    }

    /**
     * Generate controllers
     * 
     * @param array $controllers
     * @param string $controllers
     */
    protected function _controllers(array $controllers, $module) {
        foreach ($controllers as $controller => $config) {
            $class = \Zend\Code\Generator\ClassGenerator::fromArray([
                'name' => $this->_getControllerName($controller) . 'Controller',
                'namespacename' => $this->_getModuleName($module) . '\Controller',
                'methods' => array_map(
                    function($val) {
                        return $val . 'Action';
                    }, 
                    array_keys($config)
                )
            ]);

            $method = new \Zend\Code\Generator\MethodGenerator();

            $file = new \Zend\Code\Generator\FileGenerator();
            $file->setClass($class);

            file_put_contents(
                    $this->_getControllerPath($module) . '/'
                    . $this->_getControllerName($controller)
                    . 'Controller', $file->generate()
            );
        }
    }

    /**
     * Generate empty view files
     * 
     * @param array $controllers
     * @param string $controllers
     */
    protected function _views(array $controllers, $module) {
        foreach ($controllers as $controller => $config) {
            foreach (array_keys($config) as $action) {
                file_put_contents(
                    $this->_getViewPath($module, $controller) . '/'
                        . $this->_getViewName($action)
                        . '.phtml', 
                    ''
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
        mkdir($modulePath . '/config');
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

}
