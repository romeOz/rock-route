<?php
namespace rock\route\providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Message\ResponseInterface;
use rock\base\BaseException;
use rock\base\ObjectInterface;
use rock\base\ObjectTrait;
use rock\helpers\Instance;
use rock\request\Request;
use rock\response\Response;
use rock\route\Route;

class Remote implements ObjectInterface
{
    use ObjectTrait;

    /**
     * Instance Rock Response.
     * @var Response
     */
    public $response = 'response';
    /**
     * Instance Rock Request.
     * @var Request
     */
    public $request = 'request';

    /**
     * @var Client
     */
    public $client;

    public function init()
    {
        $this->response = Instance::ensure($this->response, '\rock\response\Response');
        $this->request = Instance::ensure($this->request, Request::className());
        if (!isset($this->client)) {
            $this->client = new Client();
        }
    }

    /**
     * Sends a http-query.
     * @param string $url
     * @param array $params
     * @return null|Response
     */
    public function send($url, array $params = [])
    {   $params = array_merge(parse_url($url), $params);
        $request = $this->getRequest($params);
        try {
            $response = $this->getResponse($url, $params);
        } catch (BadResponseException $e) {
            if ($e->hasResponse()) {
                return $this->convertResponse($e->getResponse(), $request);
            }
            return null;
        } catch (\Exception $e) {
            if (class_exists('\rock\log\Log')) {
                Log::warn(BaseException::convertExceptionToString($e));
            }
            return null;
        }

        return $this->convertResponse($response, $request);
    }

    protected function getResponse($url, array $params)
    {
        $method = isset($params['method']) ? $params['method'] : Route::GET;
        unset($params['method']);
        if ($method !== Route::GET) {
            if (isset($params['query']) && is_array($params['query'])) {
                $params['form_params'] = $params['query'];
            }
            unset($params['query']);
        } elseif (isset($params['query'])) {
            if (is_string($params['query'])) {
                parse_str($params['query'], $params['query']);
            }
        }
        return $this->client->request($method, $url, $this->convertOptions($params));
    }

    protected function getRequest(array $config)
    {
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

    protected function convertOptions(array $options)
    {
        if (!isset($options['headers'])) {
            $options['headers'] = [];
        }
        if (isset($options['isAjax']) && $options['isAjax'] === true) {
            unset($options['isAjax']);
            $options['headers']['X-Requested-With'] = 'XMLHttpRequest';
        }
        if (isset($options['isPjax']) && $options['isPjax'] === true) {
            unset($options['isPjax']);
            $options['headers']['X-Pjax'] = true;
        }

        if (isset($options['userIP'])) {
            $options['headers']['Remote-Addr'] = $options['userIP'];
            unset($options['userIP']);
        }

        if (isset($options['userAgent']))  {
            $options['headers']['User-Agent'] = $options['userAgent'];
            unset($options['userAgent']);
        }

        if (isset($options['isFlash'])) {
            unset($options['isFlash']);
            $options['headers']['User-Agent'] = 'Shockwave';
        }
        if (isset($options['userHost'])) {
            $options['headers']['Remote-Host'] = $options['userHost'];
            unset($options['userHost']);
        }

        if (isset($options['referrer'])) {
            $options['headers']['Referrer'] = $options['referrer'];
            unset($options['referrer']);
        }
        return $options;
    }

    protected function convertResponse(ResponseInterface $psrResponse, Request $request)
    {
        $request->setContentType($psrResponse->getHeaderLine('Content-Type'));
        $this->response->request = $request;
        $this->response->version = $psrResponse->getProtocolVersion();
        $this->response->setStatusCode($psrResponse->getStatusCode(), $psrResponse->getReasonPhrase());
        foreach ($psrResponse->getHeaders() as $name => $value) {
            $this->response->getHeaders()->setDefault($name, $value);
        }
        $this->response->content = $psrResponse->getBody()->getContents();

        return $this->response;
    }
}