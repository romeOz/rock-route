<?php

namespace rockunit;


use rock\base\Alias;
use rock\request\Request;
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
        $this->expectOutputString('success');
    }

    /**
     * @dataProvider providerGroupPatternAsArraySuccess
     */
    public function testGroupPatternAsArraySuccess($host, $path, $get, $filters, $customPath, $rule, $params, $alias)
    {
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = $host;
        $_SERVER['REQUEST_URI'] = $path;
        $_GET = $get;

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
        $this->expectOutputString('success');
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
        $this->expectOutputString('success');
    }

    public function testPatternAsArraySuccess()
    {
        $_SERVER['REQUEST_URI'] = '/bar/test';
        $_GET['query'] = '';
        $_GET['view'] = 'all-views';

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
        $this->expectOutputString('success');
    }


    /**
     * @dataProvider providerPatternAsArrayFail
     */
    public function testPatternAsArrayFail($path, $query, $getView, $method, $output = null)
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_GET['query'] = $query;
        $_GET['view'] = $getView;

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
                    echo 'success' . $route->getErrors();
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
                    echo 'success' . $route->getErrors();
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
        $this->assertTrue($cache->exists(Route::className()));
        Alias::$aliases = [];
        $route->run();
        $this->assertSame('/ajax/news/{id}/', Alias::getAlias('@foo'));
        $this->expectOutputString('successsuccess');
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
        Alias::$aliases = [];
        $route->run();
        $this->assertSame('/news/15/', Alias::getAlias('@foo', ['id' => 15]));
        $this->expectOutputString('successsuccess');
    }
}
 