<?php

namespace Webpatser\Resonate\Tests\Fakes;

use Webpatser\Resonate\Contracts\Logger;

/**
 * A logger that records every call, so a test can assert what was logged.
 */
class RecordingLogger implements Logger
{
    /** @var array<int, array{title: string, message: ?string}> */
    public array $info = [];

    /** @var array<int, string> */
    public array $errors = [];

    /** @var array<int, string> */
    public array $messages = [];

    public function info(string $title, ?string $message = null): void
    {
        $this->info[] = ['title' => $title, 'message' => $message];
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function message(string $message): void
    {
        $this->messages[] = $message;
    }

    public function line(int $lines = 1): void
    {
        //
    }
}
