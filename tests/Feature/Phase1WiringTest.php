<?php

use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Managers\ArrayChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Server\Factory;
use Webpatser\Resonate\Server\HttpServer;
use Webpatser\Resonate\Server\WebSocketHandler;

it('registers the resonate:start, resonate:restart, and resonate:reload commands', function () {
    $commands = array_keys(app('Illuminate\Contracts\Console\Kernel')->all());

    expect($commands)->toContain('resonate:start')
        ->and($commands)->toContain('resonate:restart')
        ->and($commands)->toContain('resonate:reload');
});

it('resolves the pusher protocol stack from the container', function () {
    expect(app(ApplicationProvider::class))->toBeInstanceOf(ApplicationProvider::class)
        ->and(app(ChannelManager::class))->toBeInstanceOf(ArrayChannelManager::class)
        ->and(app(ChannelConnectionManager::class))->toBeInstanceOf(ChannelConnectionManager::class)
        ->and(app(Server::class))->toBeInstanceOf(Server::class)
        ->and(app(WebSocketHandler::class))->toBeInstanceOf(WebSocketHandler::class);
});

it('shares a single channel manager across resolutions', function () {
    expect(app(ChannelManager::class))->toBe(app(ChannelManager::class));
});

it('builds an HTTP server from the reverb server config', function () {
    $config = config('reverb.servers.reverb');

    $server = Factory::make(
        host: $config['host'],
        port: $config['port'],
        path: $config['path'],
        hostname: $config['hostname'],
        maxRequestSize: $config['max_request_size'],
        options: $config['options'],
    );

    expect($server)->toBeInstanceOf(HttpServer::class);
});
