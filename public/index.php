<?php

require __DIR__ . '/../vendor/autoload.php';

use Proxy\Proxy;
use Proxy\Adapter\Guzzle\GuzzleAdapter;
use Proxy\Filter\RemoveEncodingFilter;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;

$pathMappingFile = '../mapping.json';

if (!file_exists($pathMappingFile)) {
    http_response_code(404);

    die('Not Found');
}

try {
    function removeId(string $input, string $id): string
    {
        return str_replace("/$id", '', $input);
    }

    function makeCollection(array $input = [], array $keys = [], array $base = []): array
    {
        foreach ($keys as $key) {
            if (!isset($base[$key])) {
                continue;
            }

            if (isset($input[$key])) {
                continue;
            }

            $input[$key] = $base[$key];
        }

        return $input;
    }

    $uri = $_SERVER['REQUEST_URI'];
    $uriParts = explode('/', $uri, 3);

    $requestId = $uriParts[1];

    $mapping = json_decode(file_get_contents($pathMappingFile), true);

    if (!isset($mapping[$requestId])) {
        http_response_code(404);

        die('Not Found');
    }

    $proxyUrl = $mapping[$requestId];

    // Based on: https://github.com/jenssegers/php-proxy

    // Create a PSR7 request based on the current browser request.
    $request = ServerRequestFactory::fromGlobals(
        makeCollection(
            [
                'REQUEST_URI' => removeId($_SERVER['REQUEST_URI'], $requestId),
                'PATH_INFO' => removeId($_SERVER['PATH_INFO'], $requestId),
                'PHP_SELF' => removeId($_SERVER['PHP_SELF'], $requestId),
            ],
            array_keys($_SERVER),
            $_SERVER
        )
    );

    // Create a guzzle client
    $guzzle = new GuzzleHttp\Client([
        'timeout' => 10,
    ]);

    // Create the proxy instance
    $proxy = new Proxy(new GuzzleAdapter($guzzle));

    // Add a response filter that removes the encoding headers.
    $proxy->filter(new RemoveEncodingFilter());

    $emitter = new SapiEmitter();

    try {
        // Forward the request and get the response.
        $response = $proxy->forward($request)->to($proxyUrl);

        // Output response to the browser.
        $emitter->emit($response);
    } catch (BadResponseException $e) {
        // Correct way to handle bad responses
        $emitter->emit($e->getResponse());
    } catch (RequestException $e) {
        $eres = $e->getResponse();

        if ($eres) {
            $emitter->emit($eres);
        } else {
            http_response_code(502);

            die('Bad Gateway');
        }
    }
} catch (Exception $ex) {
    http_response_code(500);

    die('Internal Server Error');
}
