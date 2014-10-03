<?php
namespace Fluxoft\Rebar;

use Fluxoft\Rebar\Auth\Web;
use Fluxoft\Rebar\Exceptions\RouterException;
use Fluxoft\Rebar\Exceptions\AuthenticationException;
use Fluxoft\Rebar\Http\Request;
use Fluxoft\Rebar\Http\Response;

/**
 * Router class.
 *
 * @author Joe Hart
 *
 */
class Router {
	/**
	 * @var \Fluxoft\Rebar\Auth\Web
	 */
	protected $webAuth;
	/**
	 * @var array
	 */
	protected $config = array();

	/**
	 * @var array
	 */
	protected $routes = array();

	/**
	 * namespace is used to specify the namespace for the app's controllers
	 *
	 * methodArgs allows for the setting of a parameter list
	 * to be sent when calling the routed controller method:
	 *
	 * Custom routes that would not be handled by the default routing behavior can be passed in as a $routes array.
	 *
	 * <code>
	 * $webAuth = new \Fluxoft\Rebar\Auth\Web(...);
	 * $config = array(
	 *     'rootPath' => '/
	 *     'namespace' => 'UserFiles',
	 *     'methodArgs' => array('param1', 'param2')
	 * );
	 * $routes = array(
	 *     '
	 * );
	 * $router = new Router($webAuth, $config, $routes);
	 * </code>
	 * @param \Fluxoft\Rebar\Auth\Web
	 * @param array $config
	 * @param array $routes
	 */
	public function __construct(Web $webAuth, array $config = array(), array $routes = array()) {
		$this->webAuth = $webAuth;
		$this->config = $config;
		$this->routes = $routes;
	}

	/**
	 * Route to the appropriate controller/method combination using the requested path.
	 *
	 * Accepts an optional $routes array that should contain route arrays with path, controller, and method elements.
	 * $routes = array(
	 *	   array(
	 *         'path' => '/container/test',
	 *         'controller' => 'TestController',
	 *         'method' => 'Container'
	 *     )
	 * );
	 *
	 * If $routes is not specified, or a matching route is not found, the default routing behavior is to split the path,
	 * using the first section as the controller name, second as method, and passing the remaining in the url params.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @throws RouterException
	 * @throws AuthenticationException
	 */
	public function Route(Request $request, Response $response) {
		$route = $this->getRoute($request->PathInfo);

		if (class_exists($route['actor'])) {
			/** @var $actor \Fluxoft\Rebar\Actor */
			$actor = new $route['actor']($request, $response);
		} else {
			throw new RouterException(sprintf('"%s" was not found.', $route['actor']));
		}
		if (!method_exists($actor, $route['action'])) {
			throw new RouterException(sprintf('Could not find a method called %s in %s.', $route['action'], $route['actor']));
		}

		if (!$actor->Authenticate($this->webAuth, $route['action'])) {
			throw new AuthenticationException(sprintf('Authentication failed in %s::%s.', $route['actor'], $route['action']));
		}

		$actionParams = array();
		if (isset($this->config['methodArgs'])) {
			foreach ($this->config['methodArgs'] as $arg) {
				$actionParams[] = $arg;
			}
		}
		foreach ($route['url'] as $urlParam) {
			$actionParams[] = $urlParam;
		}
		switch (count($actionParams)) {
			case 0:
				$actor->$route['action']();
				break;
			case 1:
				$actor->$route['action'](
					$actionParams[0]
				);
				break;
			case 2:
				$actor->$route['action'](
					$actionParams[0],
					$actionParams[1]
				);
				break;
			case 3:
				$actor->$route['action'](
					$actionParams[0],
					$actionParams[1],
					$actionParams[2]
				);
				break;
			default:
				call_user_func_array($actor, $route['action'], $actionParams);
				break;
		}
		$actor->Display();
	}

	protected function getRoute($path) {
		$routeParts = array();
		if (isset($this->routes)) {
			foreach ($this->routes as $route) {
				if (!is_array($route) || !isset($route['path']) || !isset($route['actor']) || !isset($route['action'])) {
					throw new RouterException('Routes must be arrays containing path, actor, and action keys.');
				}
				$pattern = '/^'.str_replace('/', '\/', $route['path']).'(\/[A-Za-z0-9\-.]+)*\/*$/';
				if (preg_match($pattern, $path)) {
					if (isset($this->config['actorsNamespace'])) {
						$routeParts['actor'] = '\\'.$this->config['actorsNamespace'].'\\'.$route['actor'];
					} else {
						$routeParts['actor'] = $route['actor'];
					}
					$routeParts['action'] = $route['action'];
					$paramsPath = substr($path, strlen($route['path']) + 1);
					$routeParts['url'] = array_filter(explode('/',$paramsPath));
				}
			}
		}
		if (empty($routeParts)) {
			if (strlen($path) > 1) { // disregard leading slash
				$pathParts = array_filter(explode('/',$path));
				if (count($pathParts) == 1) {
					$pathParts[] = 'index';
				}
			} else {
				$pathParts = array('main','index');
			}
			if (isset($this->config['actorsNamespace'])) {
				$routeParts['actor'] = '\\'.$this->config['actorsNamespace'].'\\'.ucwords(array_shift($pathParts));
			} else {
				$routeParts['actor'] = ucwords(array_shift($pathParts));
			}
			$routeParts['action'] = ucwords(array_shift($pathParts));
			$routeParts['url'] = $pathParts;
		}

		return $routeParts;
	}
}