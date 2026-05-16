<?php

use Fledge\Async\Http\HttpStatus;
use Fledge\Async\Http\Server\Response as FledgeResponse;
use Webpatser\Resonate\Server\Response;

/*
 * Server\Response is a thin builder that unwraps into a fledge-fiber HTTP
 * server response. No event loop or container is involved.
 */
uses()->beforeEach(fn () => null)->in(__DIR__);

it('builds a basic response', function () {
    $response = new Response('hello', HttpStatus::OK, ['x-test' => 'yes']);

    expect($response->getBody())->toBe('hello');
    expect($response->getStatus())->toBe(200);
    expect($response->getHeaders())->toBe(['x-test' => 'yes']);
});

it('builds a json response', function () {
    $response = Response::json(['health' => 'OK']);

    expect($response->getStatus())->toBe(200);
    expect($response->getBody())->toBe('{"health":"OK"}');
    expect($response->getHeaders()['content-type'])->toBe('application/json');
});

it('builds a json response from an object', function () {
    $response = Response::json((object) ['health' => 'OK'], HttpStatus::ACCEPTED);

    expect($response->getStatus())->toBe(202);
    expect($response->getBody())->toBe('{"health":"OK"}');
});

it('builds a plain text response', function () {
    $response = Response::text('Not found.', HttpStatus::NOT_FOUND);

    expect($response->getStatus())->toBe(404);
    expect($response->getBody())->toBe('Not found.');
    expect($response->getHeaders()['content-type'])->toBe('text/plain; charset=utf-8');
});

it('adds headers fluently', function () {
    $response = (new Response('body'))->withHeader('x-powered-by', 'Resonate');

    expect($response->getHeaders())->toBe(['x-powered-by' => 'Resonate']);
});

it('converts into a fledge-fiber response', function () {
    $response = Response::json(['ok' => true], HttpStatus::CREATED)->toFledgeResponse();

    expect($response)->toBeInstanceOf(FledgeResponse::class);
    expect($response->getStatus())->toBe(201);
    expect($response->getHeader('content-type'))->toBe('application/json');
    expect($response->getBody()->read())->toBe('{"ok":true}');
});
