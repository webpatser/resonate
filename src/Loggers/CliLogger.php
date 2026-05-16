<?php

namespace Webpatser\Resonate\Loggers;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Str;
use Webpatser\Resonate\Console\Components\Message;
use Webpatser\Resonate\Contracts\Logger;

class CliLogger implements Logger
{
    /**
     * The components factory instance.
     *
     * @var Factory
     */
    protected $components;

    /**
     * Create a new CLI logger instance.
     */
    public function __construct(protected OutputStyle $output)
    {
        $this->components = new Factory($output);
    }

    /**
     * Log an informational message.
     */
    public function info(string $title, ?string $message = null): void
    {
        $this->components->twoColumnDetail($title, $message);
    }

    /**
     * Log an error message.
     */
    public function error(string $message): void
    {
        $this->output->error($message);
    }

    /**
     * Log a message sent to the server.
     */
    public function message(string $message): void
    {
        $decoded = json_decode($message, true);

        if (! is_array($decoded)) {
            return;
        }

        if (isset($decoded['data']) && is_string($decoded['data'])) {
            $decoded['data'] = json_decode($decoded['data'], true);
        }

        $decoded = Sanitizer::redact($decoded);

        (new Message($this->output))->render(
            Str::limit(json_encode($decoded, JSON_PRETTY_PRINT), 200)
        );
    }

    /**
     * Append a new line to the log.
     */
    public function line(int $lines = 1): void
    {
        $this->output->newLine($lines);
    }
}
