<?php

namespace Jadob\Core;

use ReflectionMethod;
use Jadob\Container\Container;
use Jadob\Core\Exception\DispatcherException;
use Jadob\EventListener\Event\AfterControllerEvent;
use Jadob\EventListener\Event\AfterRouterEvent;
use Jadob\EventListener\EventListener;
use Jadob\Router\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zend\Config\Config;

/**
 * Class Dispatcher
 * @package Jadob\Core
 * @author pizzaminded <miki@appvende.net>
 * @license MIT
 */
class Dispatcher
{

    /**
     * @var string
     */
    private $env;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Container
     */
    private $container;

    /**
     * Dispatcher constructor.
     * @param string $env
     * @param Config $config
     * @param Container $container
     */
    public function __construct($env, \Zend\Config\Config $config, Container $container)
    {
        $this->env = $env;
        $this->config = $config;
        $this->container = $container;
    }

    /**
     * @return EventListener
     * @throws \Jadob\Container\Exception\ServiceNotFoundException
     */
    protected function getEventDispatcher()
    {
        return $this->container->get('event.listener');
    }

    /**
     * @param Request $request
     * @return Response
     * @throws \RuntimeException
     * @throws \ReflectionException
     * @throws DispatcherException
     * @throws \Jadob\Container\Exception\ServiceNotFoundException
     */
    public function execute(Request $request): Response
    {
        /** @var Route $route */
        $route = $this->container->get('router')->matchRoute($request);

        $afterRouterObject = new AfterRouterEvent($route, null);

        $this->getEventDispatcher()->dispatchAfterRouterAction($afterRouterObject);

        $route = $afterRouterObject->getRoute();

        if (($afterRouterResponse = $afterRouterObject->getResponse()) !== null) {
            return $afterRouterResponse;
        }

        $controllerClassName = $route->getController();

        if (!class_exists($controllerClassName)) {
            throw new DispatcherException('Class "' . $controllerClassName . '" '
                . 'does not exists or it cannot be used as a controller.');
        }

        $controller = $this->autowireControllerClass($controllerClassName);

        $action = $route->getAction();

        if ($action === null && !method_exists($controller, '__invoke')) {
            throw new \RuntimeException('Class "' . \get_class($controller) . '" has neither action nor __invoke() method defined.');
        }

        $action = ($action === null) ? '__invoke' : $action . 'Action';

        if (!method_exists($controller, $action)) {
            throw new DispatcherException('Action "' . $action . '" does not exists in ' . \get_class($controller));
        }

        $params = $this->getOrderedParamsForAction($controller, $action, $route);

        /** @var Response $response */
        $response = \call_user_func_array([$controller, $action], $params);

        $afterControllerEvent = new AfterControllerEvent($response);

        $response = $this->getEventDispatcher()->dispatchAfterControllerAction($afterControllerEvent);

//        if ($afterControllerListener !== null) {
//            $response = $afterControllerListener;
//        }

        $response->prepare($request);
        //TODO: Response validation
//        if (!in_array(Response::class, class_parents($response), true)) {
//            throw new DispatcherException('Invalid response type');
//        }


        //enable pretty print for JsonResponse objects in dev environment
        if ($response instanceof JsonResponse && $this->env === 'dev') {
            $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRETTY_PRINT);
        }

        return $response;
    }

    /**
     * @param $controller
     * @param $action
     * @param Route $route
     * @return array
     * @throws \ReflectionException
     */
    private function getOrderedParamsForAction($controller, $action, Route $route): array
    {
        $reflection = new ReflectionMethod($controller, $action);

        $params = $route->getParams();

        $output = [];
        foreach ($reflection->getParameters() as $parameter) {
            $routeName = $parameter->getName();
            $output[$routeName] = $params[$routeName];
        }

        return $output;
    }

    /**
     * @param $className
     * @return array
     * @throws \RuntimeException
     * @throws \ReflectionException
     */
    private function getControllerConstructorArguments($className)
    {

        $reflection = new \ReflectionClass($className);

        $controllerConstructor = $reflection->getConstructor();

        if ($controllerConstructor === null) {
            return [];
        }

        $controllerParameters = $controllerConstructor->getParameters();

        $controllerConstructorArgs = [];

        foreach ($controllerParameters as $parameter) {

            if ($parameter->getType() === null) {
                throw new \RuntimeException('Constructor argument "' . $parameter->getName() . '" has no type.');
            }

            $argumentType = (string)$parameter->getType();
            if ($argumentType === Container::class) {
                $controllerConstructorArgs[] = $this->container;
                break;
            }

            $controllerConstructorArgs[] = $this->container->findServiceByClassName($argumentType);
        }

        return $controllerConstructorArgs;

    }

    /**
     * Finds depedencies for controller object and instatiate it.
     * @param string $controllerClassName
     * @return mixed
     * @throws \ReflectionException
     */
    public function autowireControllerClass($controllerClassName) {
        $controllerConstructorArgs = $this->getControllerConstructorArguments($controllerClassName);

        return new $controllerClassName(...$controllerConstructorArgs);
    }

}
