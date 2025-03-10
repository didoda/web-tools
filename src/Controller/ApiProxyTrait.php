<?php
declare(strict_types=1);

/**
 * BEdita, API-first content management framework
 * Copyright 2020 ChannelWeb Srl, Chialab Srl
 *
 * This file is part of BEdita: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * See LICENSE.LGPL or <http://gnu.org/licenses/lgpl-3.0.html> for more details.
 */
namespace BEdita\WebTools\Controller;

use BEdita\SDK\BEditaClient;
use BEdita\SDK\BEditaClientException;
use BEdita\WebTools\ApiClientProvider;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\MethodNotAllowedException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\Utility\Hash;
use Cake\View\ViewVarsTrait;
use Throwable;

/**
 * Use this Trait in a controller to directly proxy requests to BE4 API.
 * The response will be the same of the API itself with links masked.
 *
 * You need also to define routing rules configured as (for ApiController)
 *
 * ```
 * $builder->scope('/api', ['_namePrefix' => 'api:'], function (RouteBuilder $builder) {
 *     $builder->get('/**', ['controller' => 'Api', 'action' => 'get'], 'get');
 *     $builder->post('/**', ['controller' => 'Api', 'action' => 'post'], 'post');
 *     // and so on for patch, delete if you want to use it
 * });
 * ```
 */
trait ApiProxyTrait
{
    use ViewVarsTrait;

    /**
     * An instance of a \Cake\Http\ServerRequest object that contains information about the current request.
     *
     * @var \Cake\Http\ServerRequest
     */
    protected ServerRequest $request;

    /**
     * An instance of a Response object that contains information about the impending response.
     *
     * @var \Cake\Http\Response
     */
    protected Response $response;

    /**
     * BEdita API client
     *
     * @var \BEdita\SDK\BEditaClient|null
     */
    protected ?BEditaClient $apiClient = null;

    /**
     * Base URL used for mask links.
     *
     * @var string
     */
    protected string $baseUrl = '';

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        parent::initialize();

        if ($this->apiClient === null) {
            $this->apiClient = ApiClientProvider::getApiClient();
        }

        $this->viewBuilder()->setClassName('Json');
    }

    /**
     * Set base URL used for mask links removing trailing slashes.
     *
     * @param string $path The path on which build base URL
     * @return void
     */
    protected function setBaseUrl(string $path): void
    {
        $requestPath = $this->request->getPath();
        $pos = strpos(rawurldecode($requestPath), $path);
        if ($pos === false) {
            throw new BadRequestException('Path not found in request');
        }

        $basePath = substr($requestPath, 0, $pos);
        $this->baseUrl = Router::url(rtrim($basePath, '/'), true);
    }

    /**
     * Proxy for GET requests to BEdita API
     *
     * @param string $path The path for API request
     * @return void
     */
    public function get(string $path = ''): void
    {
        $this->apiRequest([
            'method' => 'get',
            'path' => $path,
            'query' => $this->request->getQueryParams(),
        ]);
    }

    /**
     * Proxy for POST requests to BEdita API
     *
     * @param string $path The path for API request
     * @return void
     */
    public function post(string $path = ''): void
    {
        $this->apiRequest([
            'method' => 'post',
            'path' => $path,
            'body' => $this->request->getData(),
        ]);
    }

    /**
     * Proxy for PATCH requests to BEdita API
     *
     * @param string $path The path for API request
     * @return void
     */
    public function patch(string $path = ''): void
    {
        $this->apiRequest([
            'method' => 'patch',
            'path' => $path,
            'body' => $this->request->getData(),
        ]);
    }

    /**
     * Proxy for DELETE requests to BEdita API
     *
     * @param string $path The path for API request
     * @return void
     */
    public function delete(string $path = ''): void
    {
        $this->apiRequest([
            'method' => 'delete',
            'path' => $path,
            'body' => $this->request->getData(),
        ]);
    }

    /**
     * Routes a request to the API handling response and errors.
     *
     * `$options` are:
     * - method => the HTTP request method
     * - path => a string representing the complete endpoint path
     * - query => an array of query strings
     * - body => the body sent
     * - headers => an array of headers
     *
     * @param array $options The request options
     * @return void
     */
    protected function apiRequest(array $options): void
    {
        $options += [
            'method' => '',
            'path' => '',
            'query' => null,
            'body' => null,
            'headers' => null,
        ];

        if (empty($options['body'])) {
            $options['body'] = null;
        }
        if (is_array($options['body'])) {
            $options['body'] = json_encode($options['body']);
        }

        try {
            $this->setBaseUrl($options['path']);
            $method = strtolower($options['method']);
            if (!in_array($method, ['get', 'post', 'patch', 'delete'])) {
                throw new MethodNotAllowedException();
            }

            if ($method === 'get') {
                $response = $this->apiClient->get($options['path'], $options['query'], $options['headers']);
            } else {
                $response = call_user_func_array(
                    [$this->apiClient, $method], // call 'post', 'patch' or 'delete'
                    [$options['path'], $options['body'], $options['headers']]
                );
            }

            if ($response === null) {
                $this->autoRender = false;
                $this->response = $this->response->withStringBody(null);

                return;
            }

            $response = $this->maskResponseLinks($response);
            $this->set($response);
            $this->viewBuilder()->setOption('serialize', array_keys($response));
        } catch (Throwable $e) {
            $this->handleError($e);
        }
    }

    /**
     * Handle error.
     * Set error var for view.
     *
     * @param \Throwable $error The error thrown.
     * @return void
     */
    protected function handleError(Throwable $error): void
    {
        $status = $error->getCode();
        if ($status < 100 || $status > 599) {
            $status = 500;
        }
        $this->response = $this->response->withStatus($status);
        $errorData = [
            'status' => (string)$status,
            'title' => $error->getMessage(),
        ];
        $this->set('error', $errorData);
        $this->viewBuilder()->setOption('serialize', ['error']);

        if (!$error instanceof BEditaClientException) {
            return;
        }

        $errorAttributes = $error->getAttributes();
        if (!empty($errorAttributes)) {
            $this->set('error', $errorAttributes);
        }
    }

    /**
     * Mask links of response to not expose API URL.
     *
     * @param array $response The response from API
     * @return array
     */
    protected function maskResponseLinks(array $response): array
    {
        $response = $this->maskLinks($response, '$id');
        $response = $this->maskLinks($response, 'links');
        $response = $this->maskLinks($response, 'meta.schema');

        if (!empty($response['meta']['resources'])) {
            $response = $this->maskMultiLinks($response, 'meta.resources', 'href');
        }

        $data = (array)Hash::get($response, 'data');
        if (empty($data)) {
            return $response;
        }

        if (Hash::numeric(array_keys($data))) {
            foreach ($data as &$item) {
                $item = $this->maskLinks($item, 'links');
                $item = $this->maskMultiLinks($item);
            }
            $response['data'] = $data;
        } else {
            $response['data'] = $this->maskMultiLinks($data);
        }

        return (array)$response;
    }

    /**
     * Mask links across multidimensional array.
     * By default search for `relationships` and mask their `links`.
     *
     * @param array $data The data with links to mask
     * @param string $path The path to search for
     * @param string $key The key on which are the links
     * @return array
     */
    protected function maskMultiLinks(array $data, string $path = 'relationships', string $key = 'links'): array
    {
        $relationships = Hash::get($data, $path, []);
        foreach ($relationships as &$rel) {
            $rel = $this->maskLinks($rel, $key);
        }

        return Hash::insert($data, $path, $relationships);
    }

    /**
     * Mask links found in `$path`
     *
     * @param array $data The data with links to mask
     * @param string $path The path to search for
     * @return array
     */
    protected function maskLinks(array $data, string $path): array
    {
        $links = Hash::get($data, $path, []);
        if (empty($links)) {
            return $data;
        }

        if (is_string($links)) {
            $links = str_replace($this->apiClient->getApiBaseUrl(), $this->baseUrl, $links);

            return Hash::insert($data, $path, $links);
        }

        foreach ($links as &$link) {
            if (is_string($link)) {
                $link = str_replace($this->apiClient->getApiBaseUrl(), $this->baseUrl, $link);
            }
        }

        return Hash::insert($data, $path, $links);
    }
}
