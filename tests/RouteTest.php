<?php

namespace rockunit;


use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use rock\base\Alias;
use rock\request\Request;
use rock\route\filters\RateLimiter;
use rock\route\providers\Local;
use rock\route\Route;

/**
 * @group base
 * @group route
 */
class RouteTest extends RouteConfigTest
{
    public function testGroupPattern()
    {
        $_SERVER['REQUEST_URI'] = '/ajax/news/7/';

        $handler = function(Route $route) {
            $handler = function(Route $route){
                $this->assertEquals(7, $route['id']);
                $this->assertSame('news/7/', $route['url']);
                return 'success';
            };
            $route->get('/news/{id:\d+}/', $handler, ['as' => 'foo']);
            return $route;
        };
        $route = new Route();
        $route->group(Route::ANY, '/ajax/{url:.+}', $handler, ['path' => '/ajax/']);
        $route->run();

        $this->assertSame('/ajax/news/{id}/', Alias::getAlias('@foo'));
        $this->assertSame(0, $route->getErrors());
        $this->assertSame('success', $route->response->data);
    }

    /**
     * @dataProvider providerGroupPatternAsArraySuccess
     */
    public function testGroupPatternAsArraySuccess($host, $path, $get, $filters, $customPath, $rule, $params, $alias)
    {
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = $host;
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['QUERY_STRING'] = $get;

        $handler = function(Route $route) use ($rule, $params) {
            $handler = function(Route $route) use ($params){
                $this->assertEquals($params, $route->getParams());
                return 'success';
            };
            $route->get($rule, $handler, ['as' => 'foo']);
            return $route;
        };

        $route = new Route();
        $route->group(Route::ANY, $filters, $handler, ['path' => $customPath]);
        $route->run();

        $this->assertSame($alias, Alias::getAlias('@foo'));
        $this->assertSame(0, $route->getErrors());
        $this->assertSame('success', $route->response->data);
    }

    /**
     * @dataProvider providerGroupPatternAsArrayFail
     */
    public function testGroupPatternAsArrayFail($host, $path, $method, $filters, $errors)
    {
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = $host;
        $_SERVER['REQUEST_URI'] = $path;

        $handler = function(Route $route){
            $handler = function(){
                return 'success';
            };
            $route->get('/news/{id:\d+}/', $handler, ['as' => 'foo']);
            return $route;
        };

        $route = new Route();
        $route->group($method, $filters, $handler);
        $route->run();

        $this->assertSame($errors, $route->getErrors());
    }

    public function testPatternSuccess()
    {
        $_SERVER['REQUEST_URI'] = '/news/7/';

        $handler = function(Route $route){
            $this->assertEquals(7, $route['id']);
            return 'success';
        };
        $route = new Route();
        $route->get('/news/{id:\d+}/', $handler, ['as' => 'foo']);
        $route->run();

        $this->assertSame('/news/15/', Alias::getAlias('@foo', ['id' => 15]));
        $this->assertSame('success', $route->response->data);
    }

    public function testPatternAsArraySuccess()
    {
        $_SERVER['REQUEST_URI'] = '/bar/test';
        $_SERVER['QUERY_STRING'] = 'query=&view=all-views';

        $handler = function(Route $route){
            $this->assertEquals(['name' => 'test', 'order' => 'all'], $route->getParams());
            return 'success';
        };
        $route = new Route();
        $route->get(
            [
                Route::FILTER_PATH => '/bar/{name}',
                Route::FILTER_GET => ['query' => true, 'view' => '{order}-views'],

            ],
            $handler,
            ['as' => 'bar']
        );
        $route->run();

        $this->assertSame('/bar/text?view={order}-views', Alias::getAlias('@bar', ['name' => 'text']));
        $this->assertSame('success', $route->response->data);
    }


    /**
     * @dataProvider providerPatternAsArrayFail
     */
    public function testPatternAsArrayFail($path, $query, $getView, $method, $output = null)
    {
        $_SERVER['REQUEST_URI'] = $path;
        $get = [];
        if (isset($getView)) {
            $get['view'] = $getView;
        }
        if (isset($query)) {
            $get['query'] = $query;
        }
        $_SERVER['QUERY_STRING'] = http_build_query($get);

        $route = new Route();
        $route->addRoute(
            $method,
            [
                Route::FILTER_PATH => '/bar/{name:\w+}',
                Route::FILTER_GET => ['query' => true, 'view' => '{order}-views'],

            ],
            function(){
                return 'success';
            },
            ['as' => 'bar']
        );
        $route->fail(function (Route $route) {
            echo 'fail' . $route->getErrors();
        });
        $route->run();

        $this->expectOutputString($output);
    }


    public function testInjectArgs()
    {
        $_POST['_method'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        (new Route)->get('/', [FooController::className(), 'actionIndex'])->run();
        $this->expectOutputString(Request::className());
    }

    /**
     * @dataProvider providerSuccess
     */
    public function testSuccess($request, $pattern, $httpMethods, $filters = null, $output)
    {
        call_user_func($request);
        $route = (new Route())
            ->addRoute(
                $httpMethods,
                $pattern,
                function (Route $route) {
                    echo $route['controller'] . 'action';
                },
                ['filters' => $filters]
            )
            ->success(
                function (Route $route) {
                    $this->assertSame(0, $route->getErrors());
                    echo 'success';
                }
            )
            ->fail(
                function (Route $route) {
                    echo 'fail' . $route->getErrors();
                }
            );
        $route->run();
        $this->assertSame(0, $route->getErrors());
        $this->expectOutputString($output);
    }

    /**
     * @dataProvider providerFail
     */
    public function testFail($request, $pattern, $verb, $filters = null, $output, $errors)
    {
        call_user_func($request);
        $route = (new Route())
            ->success(
                function (Route $route) {
                    echo 'success' . $route->getErrors();
                }
            )
            ->fail(
                function (Route $route) {
                    echo 'fail' . $route->getErrors();
                }
            )
            ->addRoute(
                $verb,
                $pattern,
                function (Route $route) {
                    echo $route['controller'] . 'action';
                },
                ['filters' => $filters]
            );
        $route->run();
        $this->assertSame($errors, $route->getErrors());
        $this->expectOutputString($output);
    }

    /**
     * @dataProvider providerRESTSuccess
     */
    public function testRESTSuccess($url, $httpMethods, $alias, $path, $output)
    {
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
        $_SERVER['REQUEST_URI'] = $url;
        $_POST['_method'] = $httpMethods;
        $route = (new Route)
            ->success(
                function (Route $route) {
                    $this->assertSame(0, $route->getErrors());
                    echo 'success';
                }
            )->fail(
                function (Route $route) {
                    echo 'fail' . $route->getErrors();
                }
            )
            ->REST(
                'orders',
                OrdersController::className(),
                ['only' => ['index', 'show', 'update', 'create', 'delete']]
            );

        $route->run();

        $this->assertSame($route->getErrors(), 0);
        $this->assertSame($path, Alias::getAlias($alias));
        $this->expectOutputString($output);
    }

    /**
     * @dataProvider providerRESTFail
     */
    public function testRESTFail($url, $verb, $errors, $output)
    {
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'site.com';
        $_SERVER['REQUEST_URI'] = $url;
        $_POST['_method'] = $verb;
        $route = (new Route)
            ->success(
                function (Route $route) {
                    echo 'success' . $route->getErrors();
                }
            )
            ->fail(
                function (Route $route) {
                    echo 'fail' . $route->getErrors();
                }
            )
            ->REST(
                'orders',
                OrdersController::className(),
                ['only' => ['index', 'show', 'create']]
            );
        $route->run();

        $this->assertSame($route->getErrors(), $errors);
        $this->expectOutputString($output);
    }

    public function testRouteLocalRulesSuccess()
    {
        $_SERVER['REQUEST_URI'] = '/news/7/';

        $handler = function(Route $route){
            $this->assertEquals(7, $route['id']);
            return 'success';
        };
        $route = new Route();
        $route->get('/news/{id:\d+}/', $handler, ['as' => 'foo']);
        $filters = [
            Route::FILTER_PATH =>  '/bar/{id:\d+}/',
            Route::FILTER_GET => [
                'view' => 'all',
                'query' => ''
            ]
        ];
        $handler = function (Route $route) {
            $this->assertEquals(2, $route['id']);
            return 'barsuccess';
        };
        $route->get($filters, $handler, ['as' => 'bar']);
        $route->run();

        $this->assertSame('/news/15/', Alias::getAlias('@foo', ['id' => 15]));
        $this->assertSame('success', $route->response->data);

        $response = (new Local(['route' => $route]))->send('/bar/2/?view=all&query=');
        $this->assertSame('barsuccess', $response->data);
        $this->assertSame('view=all&query=', $response->request->getQueryString());
        $this->assertSame('http://site.com/bar/2/?view=all&query=', $response->request->getAbsoluteUrl());

        $response = (new Local(['route' => $route]))->send('/bar/2/?view=none', ['query' => ['view' => 'all', 'query' => '']]);
        $this->assertSame('barsuccess', $response->data);
        $this->assertSame('view=all&query=', $response->request->getQueryString());
        $this->assertSame('http://site.com/bar/2/?view=all&query=', $response->request->getAbsoluteUrl());
    }

    public function testRouteLocalRulesFail()
    {
        $_SERVER['REQUEST_URI'] = '/news/7/';

        $handler = function(Route $route){
            $this->assertEquals(7, $route['id']);
            return 'success';
        };
        $route = new Route();
        $route->get('/news/{id:\d+}/', $handler, ['as' => 'foo']);
        $filters = [
            Route::FILTER_PATH =>  '/bar/{id:\d+}/',
            Route::FILTER_GET => [
                'view' => 'all',
                'query' => ''
            ]
        ];
        $handler = function (Route $route) {
            $this->assertEquals(2, $route['id']);
            return 'barsuccess';
        };
        $route->get($filters, $handler, ['as' => 'bar']);
        $route->run();

        $this->assertSame('/news/15/', Alias::getAlias('@foo', ['id' => 15]));
        $this->assertSame('success', $route->response->data);

        // null
        $response = (new Local(['route' => $route]))->send('/bar/2/?view=all');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('/bar/2/?view=none&query=');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('/bar/2/');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('/bar/2/?view=none', ['query' => ['view' => 'all']]);
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('/bar/2/?view=none', ['query' => ['view' => 'none', 'query' => '']]);
        $this->assertNull($response);
    }

    public function testRouteLocalGroupsSuccess()
    {
        $_SERVER['REQUEST_URI'] = '/ajax/news/7/';

        $handler = function(Route $route) {
            $handler = function(Route $route){
                $this->assertEquals(7, $route['id']);
                $this->assertSame('news/7/', $route['url']);
                return 'success';
            };
            $route->get('/news/{id:\d+}/', $handler, ['as' => 'foo']);
            return $route;
        };
        $route = new Route();
        $route->group(Route::ANY, '/ajax/{url:.+}', $handler, ['path' => '/ajax/']);

        $handler = function(Route $route) {
            $handler = function(Route $route){
                $this->assertEquals(2, $route['id']);
                return 'barsuccess';
            };
            $filters = [
                Route::FILTER_PATH =>  '/bar/{id:\d+}/',
                Route::FILTER_GET => [
                    'view' => 'all',
                    'query' => ''
                ]
            ];
            $route->get($filters, $handler, ['bar' => 'foo']);
            return $route;
        };
        $route->group(Route::GET, [Route::FILTER_HOST =>  'admin.site.com',], $handler);
        $route->run();

        $this->assertSame('/ajax/news/{id}/', Alias::getAlias('@foo'));
        $this->assertSame(0, $route->getErrors());
        $this->assertSame('success', $route->response->data);

        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/?view=all&query=');
        $this->assertSame('barsuccess', $response->data);
        $this->assertSame('view=all&query=', $response->request->getQueryString());
        $this->assertSame('http://admin.site.com/bar/2/?view=all&query=', $response->request->getAbsoluteUrl());

        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/?view=none', ['query' => ['view' => 'all', 'query' => '']]);
        $this->assertSame('barsuccess', $response->data);
        $this->assertSame('view=all&query=', $response->request->getQueryString());
        $this->assertSame('http://admin.site.com/bar/2/?view=all&query=', $response->request->getAbsoluteUrl());
    }

    public function testRouteLocalGroupsFail()
    {
        $_SERVER['REQUEST_URI'] = '/ajax/news/7/';

        $handler = function(Route $route) {
            $handler = function(Route $route){
                $this->assertEquals(7, $route['id']);
                $this->assertSame('news/7/', $route['url']);
                return 'success';
            };
            $route->get('/news/{id:\d+}/', $handler, ['as' => 'foo']);
            return $route;
        };
        $route = new Route();
        $route->group(Route::ANY, '/ajax/{url:.+}', $handler, ['path' => '/ajax/']);

        $handler = function(Route $route) {
            $handler = function(Route $route){
                $this->assertEquals(2, $route['id']);
                return 'barsuccess';
            };
            $filters = [
                Route::FILTER_PATH =>  '/bar/{id:\d+}/',
                Route::FILTER_GET => [
                    'view' => 'all',
                    'query' => ''
                ]
            ];
            $route->get($filters, $handler, ['bar' => 'foo']);
            return $route;
        };
        $route->group(Route::GET, [Route::FILTER_HOST =>  'admin.site.com',], $handler);
        $route->run();

        $this->assertSame('/ajax/news/{id}/', Alias::getAlias('@foo'));
        $this->assertSame(0, $route->getErrors());
        $this->assertSame('success', $route->response->data);

        // null
        $response = (new Local(['route' => $route]))->send('/bar/2/?view=all');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/?view=all');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/?view=none&query=');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/');
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/?view=none', ['query' => ['view' => 'all']]);
        $this->assertNull($response);
        $response = (new Local(['route' => $route]))->send('http://admin.site.com/bar/2/?view=none', ['query' => ['view' => 'none', 'query' => '']]);
        $this->assertNull($response);
    }

    public function testRouteRemote()
    {
        $mock = new MockHandler([
            new Response(200, [], 'foo')
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $response = (new \rock\route\providers\Remote(['client' => $client]))
            ->send('http://ajax.site.com/bar/2/?view=all&query=');
        $this->assertSame('ajax.site.com', $response->request->getHost());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('foo', $response->getContent());

        $mock = new MockHandler([
            new ClientException(
                "Error Communicating with Server",
                new \GuzzleHttp\Psr7\Request('GET', 'test'),
                new Response(404, [], 'bar')
            )
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $response = (new \rock\route\providers\Remote(['client' => $client]))
            ->send('http://ajax.site.com/bar/2/?view=all&query=');
        $this->assertSame('ajax.site.com', $response->request->getHost());
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('bar', $response->getContent());

        // null
        $mock = new MockHandler([
            new ClientException(
                "Error Communicating with Server",
                new \GuzzleHttp\Psr7\Request('GET', 'test')
            )
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $response = (new \rock\route\providers\Remote(['client' => $client]))
            ->send('http://ajax.site.com/bar/2/?view=all&query=');
        $this->assertNull($response);
    }

    public function testCacheGroup()
    {
        $cache = static::getCache();
        $this->assertFalse($cache->exists(Route::className()));

        $_SERVER['REQUEST_URI'] = '/ajax/news/7/';

        $handler = function(Route $route) {
            $handler = function(Route $route){
                $this->assertEquals(7, $route['id']);
                $this->assertSame('news/7/', $route['url']);
                return 'success';
            };
            $route->get('/news/{id:\d+}/', $handler, ['as' => 'foo']);
            return $route;
        };
        $route = new Route([
            'cache' => $cache,
            'enableCache' => true
        ]);
        $route->group(Route::ANY, '/ajax/{url:.+}', $handler, ['path' => '/ajax/']);
        $route->run();

        $this->assertSame('/ajax/news/{id}/', Alias::getAlias('@foo'));
        $this->assertSame(0, $route->getErrors());
        $this->assertCount(2, $cache->get(Route::className()));
        $this->assertSame('success', $route->response->data);

        Alias::$aliases = [];

        $route = new Route([
            'cache' => $cache,
            'enableCache' => true
        ]);
        $route->group(Route::ANY, '/ajax/{url:.+}', $handler, ['path' => '/ajax/']);
        $route->run();
        $this->assertSame('/ajax/news/{id}/', Alias::getAlias('@foo'));
        $this->assertSame('success', $route->response->data);
    }

    /**
     * @depends testCacheGroup
     */
    public function testFlushCache()
    {
        $this->assertTrue($this->flushCache());
    }

    /**
     * @depends testFlushCache
     */
    public function testCacheRules()
    {
        $_SERVER['REQUEST_URI'] = '/news/7/';
        $cache = static::getCache();

        $this->assertFalse($cache->exists(Route::className()));

        $handler = function(Route $route){
            $this->assertEquals(7, $route['id']);
            return 'success';
        };
        $route = new Route([
            'cache' => $cache,
            'enableCache' => true
        ]);
        $route->get('/news/{id:\d+}/', $handler, ['as' => 'foo']);
        $route->run();
        $this->assertSame('/news/15/', Alias::getAlias('@foo', ['id' => 15]));
        $this->assertTrue($cache->exists(Route::className()));
        $this->assertSame('success', $route->response->data);

        Alias::$aliases = [];

        $route = new Route([
            'cache' => $cache,
            'enableCache' => true
        ]);
        $route->get('/news/{id:\d+}/', $handler, ['as' => 'foo']);
        $route->run();
        $this->assertSame('/news/15/', Alias::getAlias('@foo', ['id' => 15]));
        $this->assertSame('success', $route->response->data);
    }

    public function testRateLimiterFilter()
    {
        $_SERVER['REQUEST_URI'] = '/';
        $_SESSION = [];

        $filters = [
            'rate' => [
                'class' => RateLimiter::className(),
                'limit' => 2,
                'period' => 2,
                'sendHeaders' => true
            ]
        ];
        $route = new Route();
        $route->any('/', function () {echo 'foo';}, ['filters' => $filters]);
        $route->success(function (Route $route) {
            $this->assertSame(0, $route->getErrors());
            echo 'success';
        });
        $route->fail(function (Route $route) {
            $this->assertSame(Route::E_RATE_LIMIT, $route->getErrors());
            echo 'fail';
        });
        $route->run();

        $this->assertSame($_SESSION['_allowance'][Route::className()]["maxRequests"], 1);
        $route->run();
        $this->assertSame($_SESSION['_allowance'][Route::className()]["maxRequests"], 0);
        $route->run();
        $this->assertSame(2, $route->response->getHeaders()->get('x-rate-limit-limit'));
        $this->assertSame(429, $route->response->getStatusCode());
        sleep(4);
        $route->run();
        $this->assertSame($_SESSION['_allowance'][Route::className()]["maxRequests"], 1);

        $_SESSION = [];
        $this->expectOutputString('successfoosuccessfoofailsuccessfoo');
    }
}
 