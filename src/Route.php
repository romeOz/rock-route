<?php

namespace rock\route;


use rock\base\Alias;
use rock\base\Responseble;
use rock\cache\CacheInterface;
use rock\components\ComponentsInterface;
use rock\components\ComponentsTrait;
use rock\events\Event;
use rock\helpers\ArrayHelper;
use rock\helpers\Helper;
use rock\helpers\Instance;
use rock\helpers\StringHelper;
use rock\request\Request;
use rock\request\Requestable;
use rock\request\RequestInterface;
use rock\response\Response;
use rock\sanitize\Sanitize;

class Route implements RequestInterface, Requestable, Responseble, ComponentsInterface, \ArrayAccess
{
    use ComponentsTrait;

    const VARIABLE_REGEX = <<<'REGEX'
\{
    \s* ([a-zA-Z][a-zA-Z0-9_]*) \s*
    (?:
        : \s* ([^{}]*(?:\{(?-1)\}[^{}]*)*)
    )?
\}
REGEX;
    const EVENT_BEGIN_ROUTER = 'beginRoute';
    const EVENT_END_ROUTER = 'endRoute';
    const EVENT_RULE_ROUTE = 'ruleRoute';

    const ANY = '*';
    const REST = 1;

    const FILTER_SCHEME = 'scheme';
    const FILTER_HOST = 'host';
    const FILTER_PORT = 'port';
    const FILTER_PATH = 'path';
    const FILTER_GET = 'query';
    const FILTER_POST = 'post';
    const FILTER_PUT = 'put';
    const FILTER_DELETE = 'delete';

    const E_IPS = 1;
    const E_USERS = 2;
    const E_ROLES = 4;
    const E_CUSTOM = 8;
    const E_VERBS = 16;
    const E_NOT_FOUND = 32;
    const E_RATE_LIMIT = 64;

    /**
     * List of rules of the route.
     * @var array
     */
    public $rules = [];
    /**
     * List of groups of the route.
     * @var array
     */
    public $groups = [];
    /**
     * List REST handlers.
     * @var array
     */
    protected $RESTHandlers = [];
    /**
     * @var callable
     */
    public $success;
    /**
     * @var callable
     */
    public $fail;
    /**
     * List sanitize rules by default.
     * @var array
     */
    public $sanitizeRules = ['removeTags', 'trim', ['call' => 'urldecode'], 'toType'];
    /**
     * Instance Rock Request.
     * @var  Request|string|array
     */
    public $request = 'request';
    /**
     * Instance Rock Response.
     * @var Response
     */
    public $response;
    /**
     * Instance Rock Cache.
     * @var CacheInterface
     */
    public $cache = 'cache';
    /**
     * Enabled caching rules.
     * @var boolean
     */
    public $enableCache = false;

    /** @var  array */
    protected $data = [];
    protected $params = [];
    protected $rawGroups = [];
    protected $rawRules = [];
    protected $errors = 0;

    public function init()
    {
        $this->request = Instance::ensure($this->request, Request::className());
        if (is_array($this->response)) {
            $this->response['request'] = $this->request;
            $this->response = Instance::ensure($this->response, '\rock\response\Response', [], false);
        } else {
            $this->response = Instance::ensure($this->response, '\rock\response\Response', [], false);
            if ($this->response instanceof Response) {
                $this->response->request = $this->request;
            }
        }
        $this->cache = Instance::ensure($this->cache, null, [], false);

        $this->data = parse_url($this->request->getAbsoluteUrl());
        $this->RESTHandlers = array_merge($this->defaultRESTHandlers(), $this->RESTHandlers);
    }

    /**
     * Sets a REST handlers.
     * @param array $handlers
     * @return $this
     */
    public function setRESTHandlers(array $handlers)
    {
        $this->RESTHandlers = array_merge($this->RESTHandlers, $handlers);
        return $this;
    }

    public function run($new = false)
    {
        $route = $this;
        if ($new) {
            $config = [
                'class' => static::className(),
                'response' => $this->response
            ];
            /** @var static $route */
            $route = Instance::ensure($config);
        }
        Event::trigger($route, self::EVENT_BEGIN_ROUTER);
        if (!empty($route->groups)) {
            $check = $route->checkGroups($route->getRawGroups());
        } else {
            $check = $route->checkRules($route->getRawRules());
        }
        if (!$check) {
            $this->callFail();
        }
        Event::trigger($route, self::EVENT_END_ROUTER);
    }

    /**
     * Add route.
     *
     * @param array|string $httpMethods one or list http-methods.
     * @param string|array $pattern
     * @param callable|array $handler
     * @param array $options
     * @return static
     */
    public function addRoute($httpMethods = self::ANY, $pattern, $handler, array $options = [])
    {
        if (isset($options['as'])) {
            $this->rules[$options['as']] = array_merge([$httpMethods, $pattern, $handler], $options);
        } else {
            $this->rules[] = array_merge([$httpMethods, $pattern, $handler], $options);
        }
        return $this;
    }

    /**
     * Add route by any http methods.
     *
     * @param string|array $pattern
     * @param callable|array $handler
     * @param array $options
     * @return static
     */
    public function any($pattern, $handler, array $options = [])
    {
        return $this->addRoute(self::ANY, $pattern, $handler, $options);
    }

    /**
     * Add route by GET.
     *
     * @param string|array $pattern
     * @param callable|array $handler
     * @param array $options
     * @return static
     */
    public function get($pattern, $handler, array $options = [])
    {
        return $this->addRoute(self::GET, $pattern, $handler, $options);
    }

    /**
     * Add route by POST.
     *
     * @param string|array $pattern
     * @param callable|array $handler
     * @param array $options
     * @return static
     */
    public function post($pattern, $handler, array $options = [])
    {
        return $this->addRoute(self::POST, $pattern, $handler, $options);
    }

    /**
     * Add route by PUT.
     *
     * @param string|array $pattern
     * @param callable|array $handler
     * @param array $options
     * @return static
     */
    public function put($pattern, $handler, array $options = [])
    {
        return $this->addRoute(self::PUT, $pattern, $handler, $options);
    }

    /**
     * Add route by DELETE.
     *
     * @param string|array $pattern
     * @param callable|array $handler
     * @param array $options
     * @return static
     */
    public function delete($pattern, $handler, array $options = [])
    {
        return $this->addRoute(self::DELETE, $pattern, $handler, $options);
    }

    /**
     * Adds REST routers
     *
     * @param string $url
     * @param string $controller name a controller
     * @param array $options
     * @return static
     */
    public function REST($url, $controller, array $options = [])
    {
        if (isset($options['as'])) {
            $this->rules[$options['as']] = array_merge([self::REST, $url, $controller], $options);

        } else {
            $this->rules[] = array_merge([self::REST, $url, $controller], $options);
        }
        return $this;
    }

    /**
     * Adds group with rules.
     * @param array|string $httpMethods one or list http-methods
     * @param $pattern
     * @param callable $handler
     * @param array $options
     * @return $this
     * @throws RouteException
     */
    public function group($httpMethods = self::ANY, $pattern, callable $handler, array $options = [])
    {
        $route = call_user_func($handler, $this);
        if (!$route instanceof Route) {
            throw new RouteException('Handler must be returns "Route" instance.');
        }
        $this->groups[] = array_merge([$httpMethods, $pattern, 'rules' => $route->rules], $options);
        $route->rules = [];
        return $this;
    }

    /**
     * @param callable $success
     * @return static
     */
    public function success(callable $success)
    {
        $this->success = $success;
        return $this;
    }

    /**
     * @param callable $fail
     * @return static
     */
    public function fail(callable $fail)
    {
        $this->fail = $fail;
        return $this;
    }

    /**
     * Returns route-param.
     * @param string $name name of param.
     * @return array
     */
    public function getParam($name)
    {
        return isset($this->params[$name]) ? $this->params[$name] : null;
    }

    /**
     * Returns list route-params.
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * Flush cache with groups/rules.
     * @return bool
     */
    public function flushCache()
    {
        if (isset($this->cache)) {
            return $this->cache->remove(static::className());
        }
        return true;
    }

    /**
     * Returns route-param.
     * @param string $name name of param.
     * @return mixed
     */
    public function offsetGet($name)
    {
        return $this->getParam($name);
    }

    /**
     * Exists route-param.
     * @param string $name name of param
     * @return bool
     */
    public function offsetExists($name)
    {
        return isset($this->params[$name]);
    }

    /**
     * Set route-param.
     * @param string $name name of param
     * @param mixed $value
     */
    public function offsetSet($name, $value)
    {
        $this->params[$name] = $value;
    }

    /**
     * Deleting route-param.
     * @param string $name name of param
     */
    public function offsetUnset($name)
    {
        unset($this->params[$name]);
    }

    /**
     * Returns list prepared groups.
     * @return array
     */
    public function getRawGroups()
    {
        if (!empty($this->rawGroups)) {
            return $this->rawGroups;
        }
        if ($this->enableCache && isset($this->cache)) {
            if (($data = $this->cache->get(static::className())) !== false) {
                list($rawGroups, $aliases) = $data;
                Alias::setAliases($this->prepareAliases($aliases), false);
                $rawGroups = $this->calculateCacheGroups($rawGroups, $this->groups);
                return $this->rawGroups = $rawGroups;
            }
        }
        list($rawGroups, $aliases) = $this->normalizeGroups($this->groups);
        if ($this->enableCache && isset($this->cache))  {
            $this->cache->set(static::className(), [$this->normalizeCacheGroups($rawGroups), $aliases]);
        }
        return $this->rawGroups = $rawGroups;
    }

    /**
     * Returns list prepared rules.
     * @return array
     */
    public function getRawRules()
    {
        if (!empty($this->rawRules)) {
            return $this->rawRules;
        }
        if ($this->enableCache && isset($this->cache)) {
            if (($data = $this->cache->get(static::className())) !== false) {
                list($rawRules, $aliases) = $data;
                Alias::setAliases($this->prepareAliases($aliases), false);
                $rawRules = $this->calculateCacheRules($rawRules, $this->rules);
                return $this->rawRules = $rawRules;
            }
        }

        $rawRules = $aliases = [];
        $this->normalizeRules($rawRules, $aliases, $this->rules);
        if ($this->enableCache && isset($this->cache))  {
            $this->cache->set(static::className(), [$this->normalizeCacheRules($rawRules), $aliases]);
        }
        return $this->rawRules = $rawRules;
    }

    /**
     * Checking prepared groups.
     * @param array $rawGroups
     * @return bool
     * @throws RouteException
     */
    public function checkGroups(array $rawGroups)
    {
        foreach ($rawGroups as $group) {
            list($verbs, $pattern) = $group;
            if ($this->check($verbs, $pattern, isset($group['filters']) ? $group['filters'] : null)) {
                if (isset($group['params'])) {
                    $this->params = array_merge($this->params, $group['params']);
                }
                $this->errors = 0;
                return $this->checkRules(isset($group['rules']) ? $group['rules'] : []);
            } else {
                $this->errors |= $this->errors;
            }
        }
        return false;
    }

    /**
     * Checking prepared rules.
     * @param array $rawRules
     * @return bool
     * @throws RouteException
     */
    public function checkRules(array $rawRules)
    {
        if (!empty($rawRules)) {

            foreach ($rawRules as $rule) {
                list($verbs, $pattern, $handler) = $rule;

                if ($this->check($verbs, $pattern, isset($rule['filters']) ? $rule['filters'] : null)) {
                    if (isset($rule['params'])) {
                        $this->params = array_merge($this->params, $rule['params']);
                    }
                    $this->errors = 0;
                    $this->callSuccess();
                    $this->handle($handler);
                    return true;
                } else {
                    $this->errors |= $this->errors;
                }
            }
            return false;
        }
        throw new RouteException(RouteException::UNKNOWN_PROPERTY, ['name' => 'rules']);
    }

    /**
     * Returns a errors.
     * @return int
     */
    public function getErrors()
    {
        return $this->errors;
    }

    public function isErrorVerbs()
    {
        return (bool)(self::E_VERBS & $this->errors);
    }

    public function isErrorUsers()
    {
        return (bool)(self::E_USERS & $this->errors);
    }

    public function isErrorRoles()
    {
        return (bool)(self::E_ROLES & $this->errors);
    }

    public function isErrorIps()
    {
        return (bool)(self::E_IPS & $this->errors);
    }

    public function isErrorCustom()
    {
        return (bool)(self::E_CUSTOM & $this->errors);
    }

    public function isErrorNotFound()
    {
        return (bool)(self::E_NOT_FOUND & $this->errors);
    }

    /**
     * Checking rule.
     * @param $httpMethods
     * @param array $pattern
     * @param callable|array|null $filters
     * @return bool
     * @throws RouteException
     */
    protected function check($httpMethods, array $pattern, $filters = null)
    {
        if (!$this->checkHttpMethods(is_string($httpMethods) ? [$httpMethods] : $httpMethods)) {
            return false;
        }
        if (!$this->checkPattern($pattern)) {
            $this->errors |= self::E_NOT_FOUND;
            return false;
        }

        if (isset($filters)) {
            if (is_callable($filters)) {
                if (!$this->checkCallable($filters)) {
                    return false;
                }
            } elseif (is_array($filters)) {
                if (!$this->checkFilters($filters)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Available http-methods.
     * @param string[] $httpMethods
     * @return bool
     */
    protected function checkHttpMethods(array $httpMethods)
    {
        if (in_array('*', $httpMethods, true)) {
            return true;
        }

        if (!$this->request->isMethods($httpMethods)) {
            $this->errors |= self::E_VERBS;
            return false;
        }
        return true;
    }

    /**
     * Checking pattern of rule.
     * @param array $pattern
     * @return bool
     * @throws RouteException
     */
    protected function checkPattern(array $pattern)
    {
        foreach ($pattern as $request => $compared) {

            if (!$inputs = $this->getDataRequest($request)) {
                return false;
            }
            if (is_int(key($compared))) {
                $compared = [$compared];
            }

            foreach ($compared as $key => $compare) {
                if (!isset($inputs[$key])) {
                    return false;
                }
                if ($compare === true/* && isset($inputs[$key])*/) {
                    continue;
                }

                if ($this->checkPatternInternal($compare, $inputs[$key]) === false) {
                    return false;
                }
            }
        }
        return true;
    }

    protected function checkPatternInternal(array $pattern, $input)
    {
        if ($pattern[0] === '*') {
            return true;
        }

        if (!$this->isRegExp($pattern)) {
            return $pattern[0] === $input;
        }
        return $this->match($this->buildRegexForRoute($pattern), $input);
    }

    /**
     * Checking callback-filter.
     * @param callable $callback
     * @return bool
     */
    protected function checkCallable(callable $callback)
    {
        $is = (bool)call_user_func($callback, $this);
        if (!$is) {
            $this->errors |= self::E_NOT_FOUND;
            return false;
        }

        return true;
    }

    /**
     * Checking filters.
     * @param array $filters
     * @return bool
     * @throws RouteException
     */
    protected function checkFilters(array $filters)
    {
        if (!interface_exists('\rock\filters\FilterInterface')) {
            throw new RouteException(RouteException::NOT_INSTALL_FILTERS);
        }
        $result = null;
        $this->attachBehaviors($filters);
        $event = new RouteEvent();
        $this->trigger(self::EVENT_RULE_ROUTE, $event);

        if (!$event->isValid) {
            $this->errors |= $event->errors;
            return false;
        }
        return true;
    }

    /**
     * Matching pattern a path.
     *
     * @param string $pattern regexp-pattern.
     * @param string $url
     * @return bool
     */
    protected function match($pattern, $url)
    {
        if (preg_match("~^{$pattern}$~", $url, $matches)) {
            $result = [];
            foreach ($matches as $key => $value) {
                if (is_int($key)) {
                    continue;
                }
                $result[$key] = Sanitize::rules($this->sanitizeRules)->sanitize($value);
            }
            $this->params = array_merge($this->params, $result);

            return true;
        }

        return false;
    }

    protected function normalizeGroups(array $groups)
    {
        $aliases = [];
        foreach ($groups as $alias => &$group) {
            if (is_string($alias)) {
                $group['as'] = $alias;
            }
            list(,$pattern) = $group;
            if (!is_array($pattern)) {
                $value = $pattern;
                $pattern = [];
                $pattern[self::FILTER_PATH] = $value;
            }
            foreach ($pattern as $key => &$data) {
                if (is_array($data)) {
                    foreach ($data as $k => $value) {
                        if (is_string($value)) {
                            $data[$k] = $this->parse($value, '.+');
                        }
                    }
                    continue;
                }
                $data = $key != self::FILTER_PATH ? $this->parse($data, '.+') : $this->parse($data);
            }

            $group[1] = $pattern;

            if (isset($pattern[self::FILTER_SCHEME]) && !isset($group[self::FILTER_SCHEME]) && !$this->isRegExp($pattern[self::FILTER_SCHEME])) {
                $group[self::FILTER_SCHEME] = $this->buildWithoutPattern($pattern[self::FILTER_SCHEME]);
            }
            if (isset($pattern[self::FILTER_HOST]) && !isset($group[self::FILTER_HOST])) {
                $group[self::FILTER_HOST] = $this->buildWithoutPattern($pattern[self::FILTER_HOST]);
            }
            if (isset($pattern[self::FILTER_PORT]) && !isset($group[self::FILTER_PORT]) && !$this->isRegExp($pattern[self::FILTER_PORT])) {
                $group[self::FILTER_PORT] = $this->buildWithoutPattern($pattern[self::FILTER_PORT]);
            }

            if (isset($pattern[self::FILTER_PATH]) && !isset($group[self::FILTER_PATH]) && !$this->isRegExp($pattern[self::FILTER_PATH])) {
                $group[self::FILTER_PATH] = $this->buildWithoutPattern($pattern[self::FILTER_PATH]);
            }
            if (!empty($pattern[self::FILTER_GET]) && empty($group[self::FILTER_GET])) {
                foreach ($pattern[self::FILTER_GET] as $name => $param) {
                    if (is_bool($param)) {
                        if ($this->request->rawGet($name) !== '') {
                            $group[self::FILTER_GET][$name] = "{{self_query_{$name}}}";
                        }
                        continue;
                    }
                    $group[self::FILTER_GET][$name] = $this->buildWithoutPattern($param);
                }
            }

            $result = [];
            $this->normalizeRules($result, $aliases, $group['rules'], [], $group);
            $group['rules'] = $result;
        }
        return [$groups, $aliases];
    }

    protected function normalizeRules(array &$result = [], array &$aliases = [], array $rules, array $params = [], array $group = [])
    {
        foreach ($rules as $alias => $rule) {

            if ($rule[0] === self::REST) {
                $this->normalizeRules(
                    $result,
                    $aliases,
                    ArrayHelper::only(
                        $this->RESTHandlers,
                        Helper::getValue($rule['only'], []),
                        Helper::getValue($rule['exclude'], [])
                    ),
                    [
                        'prefix' => $alias,
                        'replace' => $rule[1],
                        'controller' => $rule[2],
                        'filters' => isset($rule['filters']) ? $rule['filters'] : null
                    ],
                    $group
                );
                continue;
            }
            list(,$pattern) = $rule;

            if (is_string($alias)) {
                if (isset($params['replace'])) {
                    if (isset($params['prefix']) && !is_string($params['prefix'])) {
                        $params['prefix'] = $params['replace'];
                    }
                    $alias = "{$params['prefix']}.{$alias}";
                }
                if (isset($group['as'])) {
                    $alias = "{$group['as']}.{$alias}";
                }
            }

            $result[$alias] = $rule;
            if (isset($params['controller'])) {
                $result[$alias]['params']['controller'] = $params['controller'];
            }
            if (isset($params['filters']) && !isset($result[$alias][3])) {
                $result[$alias]['filters'] = $params['filters'];
            }
            if (!is_array($pattern)) {
                $value = $pattern;
                $pattern = [];
                $pattern[self::FILTER_PATH] = $value;
            }

            if (isset($pattern[self::FILTER_PATH])) {
                if (isset($params['replace'])) {
                    $pattern[self::FILTER_PATH] = is_array($params['replace']) ? strtr($pattern[self::FILTER_PATH], $params['replace']) : str_replace('{url}', $params['replace'], $pattern[self::FILTER_PATH]);
                }
                if (isset($group[self::FILTER_PATH])) {
                    $pattern[self::FILTER_PATH] = rtrim($group[self::FILTER_PATH], '/') . '/' . ltrim($pattern[self::FILTER_PATH], '/');
                }
            }

            foreach ($pattern as $key => &$data) {
                if (is_array($data)) {
                    foreach ($data as $k => $value) {
                        if (is_string($value)) {
                            $data[$k] = $this->parse($value, '.+');
                        }
                    }
                    continue;
                }
                $data = $key != self::FILTER_PATH ? $this->parse($data, '.+') : $this->parse($data);
            }

            $result[$alias][1] = $pattern;

            if (is_string($alias)) {
                $build = $this->buildAlias($pattern, $params, $group);
                $placeholders = [
                    'self_path' => $this->request->getUrlWithoutArgs(),
                    'self_scheme' => $this->request->getScheme()
                ];
                foreach ($this->request->rawGet() ? : [] as $name => $placeholder) {
                    $placeholders["self_query_{$name}"]  = $placeholder;
                }
                Alias::setAlias(
                    str_replace('/', '.', $alias),
                    StringHelper::replace($build, $placeholders, false),
                    false
                );
                $aliases[$alias] = $build;
            }
        }
    }

    protected function buildAlias(array $pattern, array $params = [], array $group = [])
    {
        $url = [];
        if (isset($pattern[self::FILTER_SCHEME])) {
            $url[self::FILTER_SCHEME] = $this->buildWithoutPattern($pattern[self::FILTER_SCHEME]);
        } elseif (isset($group[self::FILTER_SCHEME])) {
            $url[self::FILTER_SCHEME] = $group[self::FILTER_SCHEME];
        }
        if (isset($pattern[self::FILTER_HOST])) {
            $url[self::FILTER_HOST] = $this->buildWithoutPattern($pattern[self::FILTER_HOST]);
        } elseif (isset($group[self::FILTER_HOST])) {
            $url[self::FILTER_HOST] = $group[self::FILTER_HOST];
        }
        if (isset($pattern[self::FILTER_PORT])) {
            $url[self::FILTER_PORT] = $this->buildWithoutPattern($pattern[self::FILTER_PORT]);
        } elseif (isset($group[self::FILTER_PORT])) {
            $url[self::FILTER_PORT] = $group[self::FILTER_PORT];
        }
        if (isset($pattern[self::FILTER_PATH])) {
            $url[self::FILTER_PATH] = $this->buildWithoutPattern($pattern[self::FILTER_PATH]);
        } elseif (isset($group[self::FILTER_PATH])) {
            $url[self::FILTER_PATH] = $group[self::FILTER_PATH];
        }

        if (!isset($url[self::FILTER_PATH])) {
            $url[self::FILTER_PATH] = '{self_path}';
        }

        $get = [];
        if (!empty($pattern[self::FILTER_GET])) {
            foreach ($pattern[self::FILTER_GET] as $name => $param) {
                if (is_bool($param)) {
                    if ($this->request->rawGet($name) !== '') {
                        $get[$name] = "{{self_query_{$name}}}";
                    }
                    continue;
                }
                $get[$name] = $this->buildWithoutPattern($param);
            }
        }

        if (!empty($group[self::FILTER_GET])) {
            $get = array_merge($group[self::FILTER_GET], $get);
        }

        if (!empty($get)) {
            $url[self::FILTER_GET] = urldecode(http_build_query($get));
        }

        if (!isset($url[self::FILTER_HOST])) {
            unset($url[self::FILTER_SCHEME], $url[self::FILTER_PORT]);
        } elseif(!isset($url[self::FILTER_SCHEME])) {
            $url[self::FILTER_SCHEME] = '{self_scheme}';
        }

        $url = http_build_url($url);

        if (isset($params['replace'])) {
            $url = is_array($params['replace']) ? strtr($url, $params['replace']) : str_replace('{url}', $params['replace'], $url);
        }
        return $url;
    }

    protected function handle($handler)
    {
        // as array
        if (is_array($handler)) {
            list($class, $method) = $handler;
            if (is_string($class)) {
                $class = StringHelper::replace($class, $this->getParams(), false);
                $method = StringHelper::replace($method, $this->getParams(), false);
                if (!class_exists($class)) {
                    throw new RouteException(RouteException::UNKNOWN_CLASS, ['class' => $class]);
                }
                $config = ['class' => $class];
                if (is_subclass_of($class, '\rock\base\Responseble')) {
                    $config['response'] = $this->response;
                }
                if (is_subclass_of($class, '\rock\request\Requestable')) {
                    $config['request'] = $this->request;
                }
                $class = Instance::ensure($config);
            }

            if (!method_exists($class, $method)) {
                $class = get_class($class);
                throw new RouteException(RouteException::UNKNOWN_METHOD, ['method' => "{$class}::{$method}"]);
            }

            $args = $this->injectArgs($class, $method);
            if ($class instanceof \rock\core\Controller) {
                array_unshift($args, $method);
                $method = 'method';
            }
            $handler = call_user_func_array([$class, $method], $args);//$class->method($method, $this);
        } elseif ($handler instanceof \Closure) {
            $handler = call_user_func($handler, $this);
        }

        // as Response
        if ($handler instanceof Response) {
            $this->response = $handler;
            return;
        }

        if (isset($handler)) {
            if ($this->response instanceof Response) {
                $this->response->data = $handler;
                return;
            }

            if (is_array($handler)) {
                var_export($handler);
                return;
            }
            echo $handler;
        }
    }

    protected function injectArgs($class, $method)
    {
        $reflectionMethod = new \ReflectionMethod($class, $method);
        $args = [];
        $i = 1;
        foreach ($reflectionMethod->getParameters() as $param) {
            if (!$class = $param->getClass()) {
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } else {
                    throw new RouteException("Argument #{$i} must be instance");
                }

                continue;
            }

            if ($class->isInstance($this)) {
                $args[] = $this;
                continue;
            }

            $args[] = Instance::ensure($class->getName());
            ++$i;
        }
        return $args;
    }

    protected function parse($pattern, $dispatchRegex = '[^/]+')
    {
        if (!preg_match_all(
            '~' . self::VARIABLE_REGEX . '~x', $pattern, $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        )) {
            return [$pattern];
        }
        $offset = 0;
        $patternData = [];
        foreach ($matches as $set) {
            if ($set[0][1] > $offset) {
                $patternData[] = substr($pattern, $offset, $set[0][1] - $offset);
            }
            $patternData[] = [
                $set[1][0],
                isset($set[2]) ? trim($set[2][0]) : $dispatchRegex
            ];
            $offset = $set[0][1] + strlen($set[0][0]);
        }
        if ($offset != strlen($pattern)) {
            $patternData[] = substr($pattern, $offset);
        }
        return $patternData;
    }

    private function buildRegexForRoute($routeData)
    {
        $regex = '';
        $variables = [];
        foreach ($routeData as $part) {
            if (is_string($part)) {
                $regex .= preg_quote($part, '~');
                continue;
            }
            list($varName, $regexPart) = $part;
            if (isset($variables[$varName])) {
                throw new RouteException(sprintf(
                    'Cannot use the same placeholder "%s" twice', $varName
                ));
            }
            if ($this->regexHasCapturingGroups($regexPart)) {
                throw new RouteException(sprintf(
                    'Regex "%s" for parameter "%s" contains a capturing group',
                    $regexPart, $varName
                ));
            }
            //$variables[$varName] = $varName;
            $regex .= "(?P<{$varName}>" . $regexPart . ')';
        }
        return $regex;
    }

    protected function regexHasCapturingGroups($regex)
    {
        if (false === strpos($regex, '(')) {
            // Needs to have at least a ( to contain a capturing group
            return false;
        }
        // Semi-accurate detection for capturing groups
        return preg_match(
            '~
                (?:
                    \(\?\(
                  | \[ [^\]\\\\]* (?: \\\\ . [^\]\\\\]* )* \]
                  | \\\\ .
                ) (*SKIP)(*FAIL) |
                \(
                (?!
                    \? (?! <(?![!=]) | P< | \' )
                  | \*
                )
            ~x',
            $regex
        );
    }

    protected function buildWithoutPattern($pattern)
    {
        $result = '';
        foreach ($pattern as $key => $data) {
            if (is_array($data)) {
                list($placeholder) = $data;
                $result .= '{' . $placeholder . '}';
                continue;
            }
            $result .= $data;
        }
        return $result;
    }

    /**
     * Returns request.
     *
     * @param $key
     * @return array
     * @throws RouteException
     */
    protected function getDataRequest($key)
    {
        switch ($key) {
            case self::FILTER_SCHEME:
                return [$this->data['scheme']];
            case self::FILTER_HOST:
                return [$this->data['host']];
            case self::FILTER_PORT:
                return [!empty($this->data['port']) ? $this->data['port'] : 80];
            case self::FILTER_PATH:
                return [$this->data['path']];
            case self::FILTER_GET:
                return $this->request->rawGet() ?: [];
            case self::FILTER_POST:
            case self::FILTER_PUT:
            case self::FILTER_DELETE:
                return $this->request->rawPost() ?: [];
            default:
                throw new RouteException(RouteException::UNKNOWN_FORMAT, ['format' => $key]);
        }
    }

    protected function isRegExp($value)
    {
        if (count($value) === 1 && is_scalar(current($value))) {
            return false;
        }
        return true;
    }

    protected function normalizeCacheGroups(array $groups)
    {
        foreach ($groups as &$group) {
            unset($group['filters']);
            $group['rules'] = $this->normalizeCacheRules($group['rules']);
        }
        return $groups;
    }

    protected function normalizeCacheRules($rules)
    {
        foreach ($rules as &$rule) {
            unset($rule[2], $rule['filters']);
        }

        return $rules;
    }

    protected function calculateCacheGroups(array $rawGroups, array $groups = [])
    {
        foreach ($rawGroups as $key => &$group) {
            if (isset($groups[$key])) {
                if (isset($groups[$key]['filters'])) {
                    $group['filters'] = $groups[$key]['filters'];
                }

                $group['rules'] = $this->calculateCacheRules($group['rules'], $groups[$key]['rules']);
            }
        }
        return $rawGroups;
    }

    protected function calculateCacheRules(array $rawRules, $rules)
    {
        foreach ($rawRules as $key => &$rule) {
            if (isset($rules[$key][2])) {
                $rule[2] = $rules[$key][2];
                if (isset($rules[$key]['filters'])) {
                    $rule['filters'] = $rules[$key]['filters'];
                }
            }
        }
        return $rawRules;
    }

    private function prepareAliases(array $aliases)
    {
        foreach ($aliases as &$alias) {
            $placeholders = [
                'self_scheme' => $this->request->getScheme(),
                'self_path' => $this->request->getUrlWithoutArgs(),
            ];
            foreach ($this->request->rawGet() ? : [] as $name => $placeholder) {
                $placeholders["self_query_{$name}"]  = $placeholder;
            }
            $alias = StringHelper::replace($alias, $placeholders, false);
        }
        return $aliases;
    }

    protected function callSuccess()
    {
        if (!isset($this->success)) {
            return;
        }
        call_user_func($this->success, $this);
    }

    protected function callFail()
    {
        if (!isset($this->fail)) {
            return;
        }
        call_user_func($this->fail, $this);
    }

    protected function defaultRESTHandlers()
    {
        return [
            'index' => [
                self::GET,
                '/{url}/',
                ['{controller}', 'actionIndex']
            ],
            'show' => [
                self::GET,
                '/{url}/{id}/',
                ['{controller}', 'actionShow']
            ],
            'create' => [
                self::POST,
                '/{url}/',
                ['{controller}', 'actionCreate']
            ],
            'update' => [
                [self::PUT, self::PATCH],
                '/{url}/{id}/',
                ['{controller}', 'actionUpdate']
            ],
            'delete' => [
                self::DELETE,
                '/{url}/{id}/',
                ['{controller}', 'actionDelete']
            ]
        ];
    }
}