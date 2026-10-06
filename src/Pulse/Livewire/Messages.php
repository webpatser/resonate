<?php

namespace Webpatser\Resonate\Pulse\Livewire;

use Illuminate\Support\Collection;
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
     *
     * @var array<string, string>
     */
    public array $colors = [
        'received' => '#10b981',
        'received:per_rate' => '#78d7b3',
        'sent' => '#9333ea',
        'sent:per_rate' => '#bc81f1',
    ];

    /**
     * Render the component.
     *
     * @return \Illuminate\Contracts\View\View
     */
    #[Lazy]
    public function render()
    {
        [$all, $time, $runAt] = $this->remember(function (): array {
            $readings = $this->graph(['reverb_message:sent', 'reverb_message:received'], 'count');

            return [$readings, $this->rates($readings)];
        });

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
     * Convert the message counts into rates per rate unit.
     *
     * @param  Collection<string, Collection<string, Collection<string, int|null>>>  $readings
     * @return Collection<string, Collection<string, Collection<string, float|null>>>
     */
    protected function rates(Collection $readings): Collection
    {
        return $readings->map(
            /** @param Collection<string, Collection<string, int|null>> $byKey */
            fn (Collection $byKey): Collection => $byKey->map(
                /** @param Collection<string, int|null> $values */
                fn (Collection $values): Collection => $values->map(fn (?int $count): ?float => $this->rate($count))
            )
        );
    }

    /**
     * Define any CSS that should be loaded for the component.
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
