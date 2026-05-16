<?php

namespace Webpatser\Resonate\Tests\Feature\Pulse;

use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Foundation\Application as Container;
use Illuminate\Support\Collection;
use Laravel\Pulse\Events\IsolatedBeat;
use Laravel\Pulse\Pulse;
use ReflectionProperty;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Events\MessageReceived;
use Webpatser\Resonate\Events\MessageSent;
use Webpatser\Resonate\Pulse\Livewire\Connections;
use Webpatser\Resonate\Pulse\Livewire\Messages;
use Webpatser\Resonate\Pulse\Recorders\ResonateConnections;
use Webpatser\Resonate\Pulse\Recorders\ResonateMessages;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

$skipUnlessPulse = fn () => ! class_exists(Pulse::class) ? 'Pulse not installed' : false;

/**
 * Peek at the Pulse instance's buffered entries via reflection.
 */
function pulseEntries(Pulse $pulse): Collection
{
    $reflection = new ReflectionProperty($pulse, 'entries');

    return $reflection->getValue($pulse);
}

it('resolves the Pulse Livewire components from the container', function () {
    expect(app(Connections::class))->toBeInstanceOf(Connections::class)
        ->and(app(Messages::class))->toBeInstanceOf(Messages::class);
})->skip($skipUnlessPulse);

it('records a reverb_message:sent metric when a MessageSent event is dispatched', function () {
    $pulse = app(Pulse::class);
    $pulse->startRecording();

    $recorder = new ResonateMessages($pulse, app('config'));

    $connection = new FakeConnection;

    $recorder->record(new MessageSent($connection, '{"event":"pusher:ping"}'));

    $entries = pulseEntries($pulse);

    expect($entries->isNotEmpty())->toBeTrue()
        ->and($entries->pluck('type')->all())->toContain('reverb_message:sent')
        ->and($entries->where('type', 'reverb_message:sent')->first()->key)->toBe('app-id');
})->skip($skipUnlessPulse);

it('records a reverb_message:received metric when a MessageReceived event is dispatched', function () {
    $pulse = app(Pulse::class);
    $pulse->startRecording();

    $recorder = new ResonateMessages($pulse, app('config'));

    $connection = new FakeConnection;

    $recorder->record(new MessageReceived($connection, '{"event":"pusher:ping"}'));

    $entries = pulseEntries($pulse);

    expect($entries->pluck('type')->all())->toContain('reverb_message:received')
        ->and($entries->where('type', 'reverb_message:received')->first()->key)->toBe('app-id');
})->skip($skipUnlessPulse);

it('records a reverb_connections metric per application on a 15-second beat', function () {
    $pulse = app(Pulse::class);
    $pulse->startRecording();

    // Stub the Pusher response object: ->get('/connections')->connections
    $pusherStub = new class
    {
        public function get(string $path): object
        {
            return (object) ['connections' => 42];
        }
    };

    $broadcast = \Mockery::mock(BroadcastManager::class);
    $broadcast->shouldReceive('pusher')->andReturn($pusherStub);

    $container = \Mockery::mock(Container::class);
    $container->shouldReceive('make')
        ->with(ApplicationProvider::class)
        ->andReturn(app(ApplicationProvider::class));

    $recorder = new ResonateConnections($pulse, $broadcast, $container);

    // Build an IsolatedBeat whose timestamp second is divisible by 15.
    $time = CarbonImmutable::create(2026, 1, 1, 12, 0, 15);
    $recorder->record(new IsolatedBeat($time));

    $entries = pulseEntries($pulse);

    expect($entries->pluck('type')->all())->toContain('reverb_connections')
        ->and($entries->where('type', 'reverb_connections')->first()->value)->toBe(42)
        ->and($entries->where('type', 'reverb_connections')->first()->key)->toBe('app-id');
})->skip($skipUnlessPulse);

it('skips connection sampling when the beat second is not a multiple of 15', function () {
    $pulse = app(Pulse::class);
    $pulse->startRecording();

    $broadcast = \Mockery::mock(BroadcastManager::class);
    $broadcast->shouldNotReceive('pusher');

    $container = \Mockery::mock(Container::class);
    $container->shouldNotReceive('make');

    $recorder = new ResonateConnections($pulse, $broadcast, $container);

    $time = CarbonImmutable::create(2026, 1, 1, 12, 0, 7);
    $recorder->record(new IsolatedBeat($time));

    expect(pulseEntries($pulse)->where('type', 'reverb_connections'))->toBeEmpty();
})->skip($skipUnlessPulse);
