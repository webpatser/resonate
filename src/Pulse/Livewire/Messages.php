<?php

namespace Webpatser\Resonate\Pulse\Livewire;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Laravel\Pulse\Livewire\Card;
use Laravel\Pulse\Livewire\Concerns\HasPeriod;
use Laravel\Pulse\Livewire\Concerns\RemembersQueries;
use Livewire\Attributes\Lazy;
use Webpatser\Resonate\Pulse\Recorders\ResonateMessages;

class Messages extends Card
{
    use Concerns\HasRate,
        HasPeriod,
        RemembersQueries;

    /**
     * The graph colors.
     */
    public array $colors = [
        'received' => '#10b981',
        'received:per_rate' => '#78d7b3',
        'sent' => '#9333ea',
        'sent:per_rate' => '#bc81f1',
    ];

    /**
     * Render the component.
     */
    #[Lazy]
    public function render()
    {
        [$all, $time, $runAt] = $this->remember(fn () => [
            $readings = $this->graph(['reverb_message:sent', 'reverb_message:received'], 'count'),
            $readings->map->map(fn ($values) => $values->map($this->rate(...))),
        ]);

        [$messages, $messagesRate] = $all;

        if (Request::hasHeader('X-Livewire')) {
            $this->dispatch('reverb-messages-chart-update', messages: $messages, messagesRate: $messagesRate);
        }

        return View::make('reverb::livewire.messages', [
            'messages' => $messages,
            'messagesRate' => $messagesRate,
            'time' => $time,
            'runAt' => $runAt,
            'config' => Config::get('pulse.recorders.'.ResonateMessages::class),
        ]);
    }

    /**
     * Define any CSS that should be loaded for the component.
     *
     * @return string|Htmlable|array<int, string|Htmlable>|null
     */
    protected function css(): HtmlString
    {
        return new HtmlString(
            '<style>'.
            collect($this->colors)->map(fn ($color) => '.bg-\\[\\'.$color.'\\]{background-color:'.$color.'}')->join('').
            '</style>'
        );
    }
}
