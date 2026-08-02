<?php

namespace Webpatser\Resonate\Server;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\Server\Request;
use Fledge\Async\Http\Server\Response;
use Fledge\Async\Stream\ResourceStream;
use Fledge\Async\Stream\Socket;
use Fledge\Async\WebSocket\Compression\WebsocketCompressionContext;
use Fledge\Async\WebSocket\ConstantRateLimit;
use Fledge\Async\WebSocket\Parser\Rfc6455ParserFactory;
use Fledge\Async\WebSocket\PeriodicHeartbeatQueue;
use Fledge\Async\WebSocket\Rfc6455Client;
use Fledge\Async\WebSocket\Server\WebsocketClientFactory;
use Fledge\Async\WebSocket\WebsocketClient;
use Fledge\Async\WebSocket\WebsocketHeartbeatQueue;
use Fledge\Async\WebSocket\WebsocketRateLimit;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Exceptions\InvalidApplication;
use Webpatser\Resonate\Server\Concerns\ResolvesApplicationKey;

/**
 * Builds each upgraded WebSocket client with its own application's frame limit.
 *
 * `max_message_size` is configured per application, but the RFC 6455 parser
 * takes its limit once, from the factory it is built with. The stock
 * `Rfc6455ClientFactory` holds a single parser factory and hands it to every
 * client, so the transport limit had to be one number for the whole process,
 * and it was set to the largest configured application limit. That made the
 * per-application check in `Pusher\Server::message()` useless as a defence:
 * it runs on `$message->buffer()`, after the parser has already assembled the
 * whole message in memory. A client of an app limited to 10KB could force the
 * server to buffer up to the largest app's limit (10MB, say) before being told
 * off, then do it again. Two hundred such connections pinned gigabytes on a
 * process serving every tenant.
 *
 * The application is known at upgrade time: the route is `/app/{appKey}` and
 * the same `Request` the router matched is handed to `createClient()`. So the
 * connection's parser is sized from that application's own limit, and a client
 * is now disconnected with close code 1009 while its oversized message is
 * still being buffered rather than after.
 *
 * An unresolvable app key falls back to `$fallbackMessageSize`, which
 * `StartServer` sets to the *smallest* configured application limit. Such a
 * connection is closed with pusher code 4001 by
 * {@see WebSocketHandler::handleClient()} as soon as it is handed over, so the
 * only thing the fallback governs is how much it can buffer in between.
 *
 * The heartbeat queue and rate limit are deliberately shared across clients,
 * matching the stock factory; only the parser factory varies. Parser factories
 * are memoized per limit because they are stateless and every client of an
 * application can share one.
 */
class ApplicationClientFactory implements WebsocketClientFactory
{
    use ForbidCloning;
    use ForbidSerialization;
    use ResolvesApplicationKey;

    /**
     * The parser factories built so far, keyed by message size limit.
     *
     * @var array<int, Rfc6455ParserFactory>
     */
    protected array $parserFactories = [];

    /**
     * Create a new client factory instance.
     *
     * @param  int  $fallbackMessageSize  Limit applied when the app key does not resolve.
     */
    public function __construct(
        protected ApplicationProvider $applications,
        protected int $fallbackMessageSize = 10_000,
        protected ?WebsocketHeartbeatQueue $heartbeatQueue = new PeriodicHeartbeatQueue,
        protected ?WebsocketRateLimit $rateLimit = new ConstantRateLimit,
        protected int $frameSplitThreshold = Rfc6455Client::DEFAULT_FRAME_SPLIT_THRESHOLD,
        protected float $closePeriod = Rfc6455Client::DEFAULT_CLOSE_PERIOD,
    ) {
        //
    }

    /**
     * Create the WebSocket client for a freshly upgraded connection.
     */
    public function createClient(
        Request $request,
        Response $response,
        Socket $socket,
        ?WebsocketCompressionContext $compressionContext,
    ): WebsocketClient {
        $this->enableNoDelay($socket);

        return new Rfc6455Client(
            socket: $socket,
            masked: false,
            parserFactory: $this->parserFactoryFor($request),
            compressionContext: $compressionContext,
            heartbeatQueue: $this->heartbeatQueue,
            rateLimit: $this->rateLimit,
            frameSplitThreshold: $this->frameSplitThreshold,
            closePeriod: $this->closePeriod,
        );
    }

    /**
     * Resolve the message size limit that applies to the given request.
     */
    protected function messageSizeFor(Request $request): int
    {
        $appKey = $this->appKey($request);

        if ($appKey === null) {
            return $this->fallbackMessageSize;
        }

        try {
            return $this->applications->findByKey($appKey)->maxMessageSize();
        } catch (InvalidApplication) {
            return $this->fallbackMessageSize;
        }
    }

    /**
     * Get the parser factory a connection upgrading with this request gets.
     */
    protected function parserFactoryFor(Request $request): Rfc6455ParserFactory
    {
        $messageSizeLimit = $this->messageSizeFor($request);

        return $this->parserFactories[$messageSizeLimit] ??= new Rfc6455ParserFactory(
            messageSizeLimit: $messageSizeLimit,
        );
    }

    /**
     * Disable Nagle's algorithm on the upgraded socket.
     *
     * Copied from fledge-fiber's `Rfc6455ClientFactory`, which is final and so
     * cannot be extended to inherit it. Without it every small frame waits on
     * the kernel's coalescing timer, which is exactly wrong for a protocol of
     * small, latency-sensitive messages.
     */
    protected function enableNoDelay(Socket $socket): void
    {
        if (! $socket instanceof ResourceStream) {
            return;
        }

        $resource = $socket->getResource();

        // Setting this via the stream API does not work, and the option is not
        // supported once TLS is enabled on the stream.
        $supported = is_resource($resource)
            && ! isset(stream_get_meta_data($resource)['crypto'])
            && extension_loaded('sockets')
            && defined('TCP_NODELAY');

        if (! $supported || ! ($sock = socket_import_stream($resource))) {
            return;
        }

        set_error_handler(static fn () => true);

        try {
            socket_set_option($sock, SOL_TCP, TCP_NODELAY, 1);
        } finally {
            restore_error_handler();
        }
    }
}
