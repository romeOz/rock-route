Simple router for PHP
=================

[![Latest Stable Version](https://poser.pugx.org/romeOz/rock-route/v/stable.svg)](https://packagist.org/packages/romeOz/rock-route)
[![Total Downloads](https://poser.pugx.org/romeOz/rock-route/downloads.svg)](https://packagist.org/packages/romeOz/rock-route)
[![Build Status](https://travis-ci.org/romeOz/rock-route.svg?branch=master)](https://travis-ci.org/romeOz/rock-route)
[![HHVM Status](http://hhvm.h4cc.de/badge/romeoz/rock-route.svg)](http://hhvm.h4cc.de/package/romeoz/rock-route)
[![Coverage Status](https://coveralls.io/repos/romeOz/rock-route/badge.svg?branch=master)](https://coveralls.io/r/romeOz/rock-route?branch=master)
[![License](https://poser.pugx.org/romeOz/rock-route/license.svg)](https://packagist.org/packages/romeOz/rock-route)

Features
-------------------

 * Filters
 * Support REST
 * Groups
 * Inject arguments to action
 * Caching rules
 * Standalone module/component for [Rock Framework](https://github.com/romeOz/rock)

Table of Contents
-------------------

 * [Installation](#installation)
 * [Quick Start](#quick-start)
 * [Pattern](#pattern)
 * [Configurable](#configurable)
 * [Filters](#filters) 
    - [Custom filter](#custom-filter-as-callable)
 * [REST](#rest)
 * [Using groups](#using-groups)
    - [Route prefixing](#route-prefixing)
    - [Sub-Domain routing](#sub-domain-routing)
 * [Alias for route](#alias-for-route)
 * [Using response](#using-response)
 * [Inject arguments](#inject-arguments)
 * [HMVC](#hmvc)
    - [Local](#local)
    - [Remote](#remote)
 * [Caching rules](#caching-rules)
 * [Requirements](#requirements)

Installation
-------------------

From the Command Line:

```
composer require romeoz/rock-route
```

or in your composer.json:

```json
{
    "require": {
        "romeoz/rock-route": "*"
    }
}
``` 
 
Quick Start
-------------------

```php
// url: http://site.com/items/7/        

$route = new Route();
 
$handler = function(Route $route){
    return 'id: ' . $route->getParam('id');
};
       
$route->get('/items/{id:\d+}/', $handler, ['as' => 'item']);
$route->post('/items/', ['\namespace\SomeController', 'actionCreate']);
$route->run();

// output: 'id: 7'

echo Alias::getAlias('@item', ['id' => 15]); 

// output: '/items/15/'
```

Pattern
-------------------

You can use string or array as pattern.

```php
$pattern = [
    Route::FILTER_HOST => '{sub:[a-z]+}.site.com',
    Route::FILTER_PATH => '/items/{id:\d+}/',
    Route::FILTER_GET => [
        'query' => true, 
        'view' => 'all', 
        'order' => 'sort-{order:(asc|desc)}'
    ]
]
$route->post($pattern, $handler);
```

`'query' => true` indicates that the URL-param `query` was mandatory.

>Pattern as string `/items/{id:\d+}/`  is equivalent to `[ Route::FILTER_PATH => '/items/{id:\d+}/' ]`.

Configurable
-------------------

Set a rules you can as configurable. Can be useful for using inside your framework.

```php
$config = [
    'rules' => [
        'item' => [Route::GET, '/items/{id:\d+}/', $handler]
    ]
]

$route = new Route($config);
$route->run();
```

For [groups](#using-groups):

```php
$config = [
    'groups' => [
        'api' => [
            [Route::GET, Route::POST],
            [ Route::FILTER_HOST => 'api.site.com' ],
            'rules' => [
                'item' => [Route::GET, '/items/{id:\d+}/', $handler]
            ]
        ]
    ]
]

$route = new Route($config);
$route->run();
```

Filters
-------------------

For using filters you must be installed [Rock Filters](https://github.com/romeOz/rock-filters):  `composer require romeoz/rock-filters`.

An example of disallow by IP (uses `$_SERVER['REMOTE_ADDR']`):

```php
$route = new Route();
 
$handler = function(){
    return 'Hello world!';
};

$filters = [
    'access' => [
        'class' => '\rock\route\filters\AccessFilter',
        'rules' => [
            'allow' => true,
            'ips' => ['10.1.2.3']
        ]
    ]
]
       
$route->get('/items/{id:\d+}/', $handler, ['filters' => $filters]);
$route->run();
```

####Custom filter (as callable)

```php
$filters = function(Route $route){
    return $route->request->isAjax();
};
```

Must returns true/false (boolean).

REST
-------------------

```php
$route = new Route();
      
$route->REST('items', 'ItemsController');
$route->run();

class ItemsController
{
    // GET /items/
    public function actionIndex()
    {
        return 'index';
    }

    // GET /items/7/
    public function actionShow()
    {
        return 'show';
    }

    // POST /items/
    public function actionCreate()
    {
        return 'create';
    }

    // PUT /items/7/
    public function actionUpdate()
    {
        return 'update';
    }

    // DELETE /items/7/
    public function actionDelete()
    {
        return 'delete';
    }
}
``` 

You can specify what actions to use (`only` or `exclude`):

```php
$route->REST('items', 'ItemsController', ['only' => ['show', 'create']]);
```

Also you can specify custom REST scenario:

```php
$config = [
    'RESTHandlers' => [
            'all' => [
                Route::GET,
                '/{url}/',
                ['{controller}', 'actionAll']
            ],
            'one' => [
                Route::GET,
                '/{url}/{id}/',
                ['{controller}', 'actionOne']
            ],
            'create' => [
                [Route::POST, Route::OPTIONS],
                '/{url}/',
                ['{controller}', 'actionCreate']  
            ],
            'update' => [
                [Route::PUT, Route::PATCH, Route::OPTIONS],
                '/{url}/{id}/',
                ['{controller}', 'actionUpdate']
            ],
            'delete' => [
                [Route::DELETE, Route::OPTIONS],
                '/{url}/{id}/',
                ['{controller}', 'actionDelete'] 
            ]    
    ]
];

$route = new Route($config);
```

Using groups
-------------------

####Route prefixing

```php
// url: http://site.com/api/items/7/  

$route = new Route();

$handler = function(Route $route) {
    $handler = function(Route $route){      
        return 'id: ' . $route['id'];
    };
    $route->get('/items/{id:\d+}/', $handler, ['as' => 'item']);
    return $route;
};

$route->group(Route::ANY, '/api/{url:.+}', $handler, ['path' => '/api/', 'as' => 'api']);
$route->run();

// output: 'id: 7'

echo Alias::getAlias('@api.item', ['id' => 15]); 

// output: '/api/items/15/'        
```

Here, the `'path' => '/api/'` is the prefix for the rules of this group.

####Sub-Domain routing

```php
// url: http://api.site.com/items/7/  

$route = new Route();

$handler = function(Route $route) {
    $handler = function(Route $route){      
        return 'id: ' . $route['id'];
    };
    $route->get('/items/{id:\d+}/', $handler, ['as' => 'item']);
    return $route;
};

$route->group(Route::ANY, [ Route::FILTER_HOST => 'api.site.com' ], $handler, ['as' => 'api']);
$route->run();

// output: 'id: 7'

echo Alias::getAlias('@api.item', ['id' => 15]); 

// output: 'api.site.com/items/15/'        
```

Alias for route
-------------------

Using the `as` index of our route array you can assign a alias to a route:

```php
$route->get('/items/{id:\d+}/', $handler, ['as' => 'item']);

echo Alias::getAlias('@item', ['id' => 15]); 

// output: '/items/15/'
```

Extended example:

```php
$pattern = [
    Route::FILTER_HOST => '{sub:[a-z]+}.site.com',
    Route::FILTER_PATH => '/items/{id:\d+}/',
    Route::FILTER_GET => [
        'query' => true, 
        'view' => 'all', 
        'order' => 'sort-{order:(asc|desc)}'
    ]
]
$route->get($pattern, $handler, ['as' => 'item']);

echo Alias::getAlias('@item');

// output: {sub}.site.com/items/{id}/?view=all&order=sort-{order}
```

Also you can set a alias to [group](#using-groups):

```php
$route->group(
    Route::ANY, 
    [ Route::FILTER_HOST => 'api.site.com' ], 
    $handler, ['as' => 'api']
);
```

All rules belonging to this group will inherit this alias.

For configurable approach you can use the index:

```php
$config = [
    'rules' => [
        'item' => [Route::GET, '/items/{id:\d+}/', $handler]
    ]
]

$route = new Route($config);

echo Alias::getAlias('@item'); 

// output: '/items/{id}/'
```

Using response
------------------

For using response you must be installed [Rock Response](https://github.com/romeOz/rock-response):  `composer require romeoz/rock-response`.

```php
$response = new \rock\response\Response;
$route = new Route(['response' => $response]);

$handler = function(Route $route){
    $route->response->format = \rock\response\Response::FORMAT_JSON;
    return ['id' => $route->getParam('id')];
};

$route->get('/items/{id:\d+}/', $handler, ['as' => 'item']);
$route->run();
$response->send();

// output: {"id":7}
```

More details [see docs](https://github.com/romeOz/rock-response)

Inject arguments
------------------

```php
$route = new Route;

$route->get('/', ['ItemsController', 'actionIndex'])
$route->run();

class ItemsController
{
    public function actionIndex(\rock\request\Request $request)
    {
        return $request::className();
    }
}

// output: 'rock\request\Request'
```

More flexible use is possible using the library [Rock DI](https://github.com/romeOz/rock-di): `composer require romeoz/rock-di`.

[HMVC (Hierarchical model–view–controller)](https://en.wikipedia.org/wiki/Hierarchical_model-view-controller)
------------------

For using you must be installed [Rock Response](https://github.com/romeOz/rock-response):  `composer require romeoz/rock-response`.

####Local

```php
// url: http://site.com/news/7/        

$route = new Route();
 
$handler = function(Route $route){
    return 'hello';
};
       
$route->get('/foo/{id:\d+}/', $handler);
$route->post('/bar/{id:\d+}', ['\namespace\BarController', 'actionIndex']);
$route->run();

class BarController
{
    public function actionIndex(\rock\route\Route $route)
    {        
        $response = (new \rock\route\providers\Local(['route' => $route]))->send('http://site.com/foo/11/');
        return $response->getContent() . ' world!';
    }
}

// output: 'hello world!'
```

####Remote

For using you must be installed [Guzzle](https://github.com/guzzle/guzzle): `composer require guzzlehttp/guzzle:6.1.*`.

>Required PHP 5.5+

```php
$response = (new \rock\route\providers\Remote())->send('http://site.com/foo/11/');
```

Caching rules
------------------

For caching rules/groups you must be installed [Rock Cache](https://github.com/romeOz/rock-cache):  `composer require romeoz/rock-cache`.

```php
$cache = new \rock\cache\Memcached;

$route = new Route([
    'cache' => $cache,
    'enableCache' => true
]);
```

For reset the cache using `flushCache()`:

```php
$route->flushCache();
```

Requirements
-------------------
 * **PHP 5.4+**
 * For using response required [Rock Response](https://github.com/romeOz/rock-response): `composer require romeoz/rock-response`
 * For using filters required [Rock Filters](https://github.com/romeOz/rock-filters): `composer require romeoz/rock-filters`
 * For using Rate Limiter filter required [Rock Session](https://github.com/romeOz/rock-session): `composer require romeoz/rock-session`
 * For caching rules required [Rock Cache](https://github.com/romeOz/rock-cache): `composer require romeoz/rock-cache`
 * For using HMVC remote required [Guzzle](https://github.com/guzzle/guzzle): `composer require guzzlehttp/guzzle:6.1.*`.

>All unbolded dependencies is optional.

License
-------------------

Router is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT).