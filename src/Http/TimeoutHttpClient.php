<?php

namespace Drupal\autotix\Http;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 view of Drupal's Guzzle client with a per-request timeout.
 *
 * The SDK talks PSR-18, whose sendRequest() has no options argument, so
 * the module's "HTTP timeout" setting was saved but never applied. This
 * adapter forwards to Guzzle's send() with that timeout and the same
 * semantics Guzzle uses for sendRequest(): no exception on 4xx/5xx (the
 * SDK inspects the status itself) and no redirects. Guzzle 7's network
 * exceptions already implement the PSR-18 exception interfaces.
 * REQ 15.8
 */
class TimeoutHttpClient implements ClientInterface {

  public function __construct(
    private readonly GuzzleClientInterface $guzzle,
    private readonly int $timeout,
  ) {}

  public function sendRequest(RequestInterface $request): ResponseInterface {
    return $this->guzzle->send($request, [
      'timeout' => max(1, min(60, $this->timeout)),
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
    ]);
  }

}
