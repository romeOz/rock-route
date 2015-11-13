<?php
namespace rock\route\providers;

use rock\base\ObjectInterface;
use rock\base\ObjectTrait;
use rock\helpers\Instance;
use rock\request\Request;
use rock\route\Route;
use rock\route\RouteException;

class Local implements ObjectInterface
{
    use ObjectTrait;

    /**
     * Instance Rock Route.
     * @var Route
     */
    public $route;
    /**
     * Instance Rock Request.
     * @var Request
     */
    public $request = 'request';

    public function init()
    {
        if (!$this->route instanceof Route) {
            throw new RouteException(RouteException::UNKNOWN_PROPERTY, ['name' => 'route']);
        }
        $this->request = Instance::ensure($this->request, Request::className());
    }

    /**
     * Sends a http-query.
     * @param string $url
     * @param array $params
     * @return null|\rock\response\Response
     * @throws RouteException
     * @throws \rock\helpers\InstanceException
     */
    public function send($url, array $params = [])
    {
        $route = $this->route;
        /** @var Route $route */
        $route = Instance::ensure([
            'class' =>  $route::className(),
            'request' =>  $this->getRequest($url, $params)
        ]);
        if (!empty($this->route->groups)) {
            $check = $route->checkGroups($this->route->getRawGroups());
        } else {
            $check = $route->checkRules($this->route->getRawRules());
        }

        if ($check) {
            return $route->response;
        }
        return null;
    }

    protected function getRequest($url, array $params)
    {
        $config = array_merge(parse_url($url), $params);
        $config['method'] = isset($config['method']) ? $config['method'] : Route::GET;
        $config['url'] = isset($config['path']) ? $config['path'] : null;
        unset($config['path']);
        if ($config['method'] !== Route::GET) {
            if (isset($config['query']) && is_array($config['query'])) {
                $config['bodyParams'] = $config['query'];
            }
        } elseif (isset($config['query'])) {

            if (is_array($config['query'])) {
                $config['query'] = http_build_query($config['query']);
            }
            $config['queryString'] = $config['query'];
            if (isset($config['url'])) {
                $config['url'] .=  "?{$config['query']}";
            }
        }
        unset($config['query']);
        Instance::configure($this->request, $config);
        return $this->request;
    }
}