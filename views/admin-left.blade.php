@php
    $label = fn ($why) => [
        'address' => 'an address on the list', 'words' => 'words on the list', 'no-answer' => 'didn’t do the check',
        'failed' => 'failed the check', 'score' => 'scored like a robot', 'keys' => 'the keys didn’t match', 'unreachable' => 'the check couldn’t be reached (let through)',
    ][$why] ?? $why;
@endphp

<div class="guard-status">
    <span class="guard-status__light {{ $ready ? 'is-on' : '' }}" aria-hidden="true"></span>
    <span>
        <b>{{ $ready ? $providers[$ready][0] : 'No check yet' }}</b>
        <span class="field__hint" style="display: block; margin: 0">
            @if ($forms)
                Guarding: {{ implode(', ', array_map(fn ($f) => $formNames[$f], $forms)) }}.
            @else
                Not guarding any form yet.
            @endif
        </span>
    </span>
</div>

<a href="{{ route('admin.settings.inserts', ['insert' => 'guard']) }}" class="btn btn--sm" style="align-self: flex-start" wire:navigate>Settings: the check, keys and lists</a>

<div class="guard-numbers">
    <div><span>This week</span><b>{{ $week }}</b></div>
    <div><span>In the log</span><b>{{ $total }}</b></div>
</div>

@if ($stops->isNotEmpty())
    <div>
        <h2 class="eyebrow section-label">Turned away lately</h2>
        <ul class="guard-log">
            @foreach ($stops as $s)
                <li wire:key="stop-{{ $s->id }}">
                    <span class="guard-log__form">{{ $formNames[$s->field('form')] ?? $s->field('form') }}</span>
                    <span class="guard-log__why">{{ $label($s->field('why')) }}</span>
                    <span class="guard-log__when">{{ $s->created_at?->diffForHumans() }} · {{ $s->field('net') }}</span>
                </li>
            @endforeach
        </ul>
        <button type="button" class="link-button" style="margin-top: 8px" wire:click="act('clear')" wire:confirm="Empty the log?">Empty the log</button>
    </div>
@else
    <p class="empty-note">Nothing turned away yet.</p>
@endif

<style>
    .guard-status { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; border: 1px solid var(--rule); border-radius: 12px; background: var(--paper-3); }
    .guard-status__light { flex: none; width: 12px; height: 12px; margin-top: 5px; border-radius: 50%; background: var(--rule-2); }
    .guard-status__light.is-on { background: #3A9A6A; box-shadow: 0 0 0 4px color-mix(in srgb, #3A9A6A 22%, transparent); }
    .guard-numbers { display: flex; gap: 28px; }
    .guard-numbers span { display: block; font-size: 12px; color: var(--faint); }
    .guard-numbers b { font-family: var(--serif); font-size: 30px; font-weight: 400; }
    .guard-log { list-style: none; margin: 0; padding: 0; }
    .guard-log li { display: grid; grid-template-columns: auto 1fr; gap: 0 10px; padding: 8px 0; border-top: 1px solid var(--rule); font-size: 13.5px; }
    .guard-log__form { font-weight: 600; }
    .guard-log__why { color: var(--muted); }
    .guard-log__when { grid-column: 1 / -1; font-size: 12px; color: var(--faint); font-family: var(--mono); }
</style>
