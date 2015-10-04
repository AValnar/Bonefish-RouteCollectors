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
 * @date       04.10.2015
 */

namespace Bonefish\RouteCollectors;


use AValnar\FileToClassMapper\Mapper;
use Bonefish\Injection\Container\ContainerInterface;
use Bonefish\Reflection\Meta\MethodMeta;
use Bonefish\Reflection\ReflectionService;
use Bonefish\RouteCollectors\Annotations\Delete;
use Bonefish\RouteCollectors\Annotations\Get;
use Bonefish\RouteCollectors\Annotations\Patch;
use Bonefish\RouteCollectors\Annotations\Post;
use Bonefish\RouteCollectors\Annotations\Put;
use Bonefish\RouteCollectors\Annotations\Resource;
use Bonefish\Router\Collectors\RouteCollector;
use Bonefish\Router\LazyDTOCallback;
use Bonefish\Router\Route\Route;
use Bonefish\Router\Route\RouteCallbackDTO;
use Bonefish\Router\Route\RouteCallbackDTOInterface;
use Bonefish\Router\Route\RouteInterface;

final class RestRouteCollector implements RouteCollector
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
            $routePath = $this->getBaseRouteForDTO($dtoArray['resource'], $dtoArray['action']->getName());
            $parameters = $dto->getParameters();

            $methodType = $this->getMethodType($dtoArray['action']);

            // No parameters so just generate default route and continue
            if (count($parameters) == 0 || ($methodType !== 'GET' && $methodType !== 'HEAD')) {
                $routes[] = new Route([$methodType], $dto, $routePath);
                continue;
            }

            // Only for GET & HEAD
            // Loop parameters and add new route if optional parameter
            foreach($dto->getParameters() as $parameterName => $isParameterOptional)
            {
                if ($isParameterOptional) {
                    $routes[] = new Route([$methodType], $dto, $routePath);
                }

                $routePath .= '/{' . $parameterName . '}';
            }

            // Add possible last route ( all required parameters or last optional one )
            $routes[] = new Route([$methodType], $dto, $routePath);
        }

        return $routes;
    }

    protected function collectResources()
    {
        $packagesPath = $this->packagesPath;
        $vendorPath = $this->vendorPath;

        $this->mapper->configure(['/resource/i'], ['/tests/i'], ['*Resource.php']);

        return $this->mapper->createMap($packagesPath, $vendorPath);
    }

    /**
     * @param string $resource
     * @return MethodMeta
     */
    protected function collectActions($resource)
    {
        $actions = [];

        $classMeta = $this->reflectionService->getClassMetaReflection($resource);

        if ($classMeta->getAnnotation(Resource::class) === false) {
            return [];
        }

        foreach ($classMeta->getMethods() as $methodMeta) {

            // skip if method is an inherited action
            if ($methodMeta->getDeclaringClass() !== $classMeta) {
                continue;
            }

            $type = $this->getMethodType($methodMeta);

            if (!$type) {
                continue;
            }

            $actions[] = $methodMeta;
        }

        return $actions;
    }

    protected function getMethodType(MethodMeta $methodMeta)
    {
        $annotations = [
            Delete::class => 'DELETE',
            Get::class => 'GET',
            Patch::class => 'PATCH',
            Post::class => 'POST',
            Put::class => 'PUT'
        ];

        $type = false;

        foreach($annotations as $annotation => $annotationType)
        {
            if ($methodMeta->getAnnotation($annotation)) {
                $type = $annotationType;
            }
        }

        return $type;
    }

    /**
     * @return array
     */
    protected function buildDTOs()
    {
        $dtos = [];

        $resources = $this->collectResources();

        foreach($resources as $resource)
        {
            $actions = $this->collectActions($resource);

            /** @var MethodMeta $action */
            foreach($actions as $action)
            {
                $parameters = $this->getParametersFromAction($action);
                $dtos[] = [
                    'resource' => $resource,
                    'action' => $action,
                    'dto' => new RouteCallbackDTO(
                        new LazyDTOCallback($resource, $action->getName()),
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
     * @param string $resource
     * @param string $action
     * @return string
     */
    protected function getBaseRouteForDTO($resource, $action)
    {
        $classMeta = $this->reflectionService->getClassMetaReflection($resource);
        return '/' . $classMeta->getAnnotation(Resource::class)->getPath() . '/' . $action;
    }

}