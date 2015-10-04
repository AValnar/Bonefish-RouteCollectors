<?php
/**
 * Copyright (C) 2015  Alexander Schmidt
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * @author     Alexander Schmidt <mail@story75.com>
 * @copyright  Copyright (c) 2015, Alexander Schmidt
 * @date       12.06.2015
 */

namespace Bonefish\RouteCollectors;


use AValnar\FileToClassMapper\Mapper;
use Bonefish\Injection\Container\ContainerInterface;
use Bonefish\Reflection\Meta\MethodMeta;
use Bonefish\Reflection\ReflectionService;
use Bonefish\Router\Collectors\RouteCollector;
use Bonefish\Router\LazyDTOCallback;
use Bonefish\Router\Route\Route;
use Bonefish\Router\Route\RouteCallbackDTO;
use Bonefish\Router\Route\RouteCallbackDTOInterface;
use Bonefish\Router\Route\RouteInterface;


final class ControllerRouteCollector implements RouteCollector
{

    /**
     * @var Mapper
     */
    protected $mapper;

    /**
     * @var string
     */
    protected $packagesPath;

    /**
     * @var string
     */
    protected $vendorPath;

    /**
     * @var ReflectionService
     */
    protected $reflectionService;

    /**
     * @var ContainerInterface
     */
    protected $container;

    const ACTION_SUFFIX = 'Action';
    const DEFAULT_HTTP_METHOD = 'GET';

    /**
     * @param Mapper $mapper
     * @param ReflectionService $reflectionService
     * @param ContainerInterface $container
     * @param string $packagesPath
     * @param string $vendorPath
     */
    public function __construct(
        Mapper $mapper,
        ReflectionService $reflectionService,
        ContainerInterface $container,
        $packagesPath,
        $vendorPath
    )
    {
        $this->mapper = $mapper;
        $this->packagesPath = $packagesPath;
        $this->vendorPath = $vendorPath;
        $this->reflectionService = $reflectionService;
        $this->container = $container;
    }

    /**
     * @return RouteInterface[]
     */
    public function collectRoutes()
    {
        $routes = [];

        $dtos = $this->buildDTOs();

        /** @var array $dtoArray */
        foreach($dtos as $dtoArray)
        {
            /** @var RouteCallbackDTOInterface $dto */
            $dto = $dtoArray['dto'];
            $routePath = $this->getBaseRouteForDTO($dtoArray['controller'], $dtoArray['action']);
            $parameters = $dto->getParameters();

            // No parameters so just generate default route and continue
            if (count($parameters) == 0) {
                $routes[] = new Route([self::DEFAULT_HTTP_METHOD], $dto, $routePath);
                continue;
            }

            // Loop parameters and add new route if optional parameter
            foreach($dto->getParameters() as $parameterName => $isParameterOptional)
            {
                if ($isParameterOptional) {
                    $routes[] = new Route([self::DEFAULT_HTTP_METHOD], $dto, $routePath);
                }

                $routePath .= '/{' . $parameterName . '}';
            }

            // Add possible last route ( all required parameters or last optional one )
            $routes[] = new Route([self::DEFAULT_HTTP_METHOD], $dto, $routePath);
        }

        return $routes;
    }

    protected function collectControllers()
    {
        $packagesPath = $this->packagesPath;
        $vendorPath = $this->vendorPath;

        $this->mapper->configure(['/controller/i'], ['/tests/i'], ['*Controller.php']);

        return $this->mapper->createMap($packagesPath, $vendorPath);
    }

    /**
     * @param string $controller
     * @return MethodMeta
     */
    protected function collectActions($controller)
    {
        $actions = [];

        $classMeta = $this->reflectionService->getClassMetaReflection($controller);

        foreach ($classMeta->getMethods() as $methodMeta) {

            // skip if method is not an action or an inherited action
            if (!stristr($methodMeta->getName(), self::ACTION_SUFFIX) ||
                $methodMeta->getDeclaringClass() !== $classMeta) {
                continue;
            }

            $actions[] = $methodMeta;
        }

        return $actions;
    }

    /**
     * @return array
     */
    protected function buildDTOs()
    {
        $dtos = [];

        $controllers = $this->collectControllers();

        foreach($controllers as $controller)
        {
            $actions = $this->collectActions($controller);

            /** @var MethodMeta $action */
            foreach($actions as $action)
            {
                $parameters = $this->getParametersFromAction($action);
                $dtos[] = [
                    'controller' => $controller,
                    'action' => $action->getName(),
                    'dto' => new RouteCallbackDTO(
                            new LazyDTOCallback($controller, $action->getName()),
                            $parameters
                    )
                ];

            }
        }

        return $dtos;
    }

    /**
     * @param MethodMeta $action
     * @return array
     */
    protected function getParametersFromAction(MethodMeta $action)
    {
        $parameters = [];

        foreach($action->getParameters() as $parameter)
        {
            $parameters[$parameter->getName()] = $parameter->isOptional();
        }

        return $parameters;
    }

    /**
     * @param string $controller
     * @param string $action
     * @return string
     */
    protected function getBaseRouteForDTO($controller, $action)
    {
        $classMeta = $this->reflectionService->getClassMetaReflection($controller);
        $nameSpaceParts = explode('\\', $classMeta->getNamespace());
        // /vendor/package/controller/action
        return '/' . $nameSpaceParts[0] . '/'. $nameSpaceParts[1] . '/' . $classMeta->getShortName() . '/' . str_replace('Action', '', $action);
    }

}