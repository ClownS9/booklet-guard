<h2 class="heading">Try the keys</h2>

@if ($ready)
    <p style="margin: 0; color: var(--muted)">Do the check below, then press <b>Check it</b>. Booklet asks {{ $providers[$ready][0] }} whether it passed, which proves both keys are right for this address.</p>
    <div class="guard-try" wire:ignore>{!! $testWidget !!}</div>
    <button type="button" class="btn btn--ink" style="align-self: flex-start" wire:click="act('test')">Check it</button>
    @if ($tried)
        <p class="field__hint" style="margin: 0">These keys worked {{ \Illuminate\Support\Carbon::parse($triedAt)->diffForHumans() }}.</p>
    @elseif ($onLogin)
        <p class="vc-note vc-note--warn" style="margin: 0">Not tried yet, so sign-in isn’t guarded: it waits until these keys have worked here once, so a wrong key can’t lock you out.</p>
    @else
        <p class="field__hint" style="margin: 0">Not tried yet. Worth doing once: a wrong key would turn everyone away from the forms.</p>
    @endif
@else
    <p style="margin: 0; color: var(--muted)">Choose a check and save its two keys in <a href="{{ route('admin.settings.inserts', ['insert' => 'guard']) }}" wire:navigate>Settings</a>, then try them here.</p>
@endif

<div class="guard-help">
    <h3 class="eyebrow section-label">Getting keys</h3>
    <ul>
        <li><b>Cloudflare Turnstile</b>: free, no tracking, and most people never see a puzzle. In <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">Cloudflare › Turnstile ↗</a>, add a widget for your site’s domain (Managed). To try it out first, the site key <code>1x00000000000000000000AA</code> and secret key <code>1x0000000000000000000000000000000AA</code> always pass.</li>
        <li><b>Google reCAPTCHA</b>: in <a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener">the reCAPTCHA admin ↗</a>, pick v2 “I’m not a robot” or v3, and add your domain. Choose the same version here.</li>
        <li><b>hCaptcha</b>: in <a href="https://dashboard.hcaptcha.com/sites" target="_blank" rel="noopener">the hCaptcha dashboard ↗</a>, add a site; the secret is under your account settings.</li>
    </ul>
    <p class="field__hint" style="margin: 0">Locked out? <code>BOOKLET_SAFE_MODE=true</code> in <code>.env</code> switches every insert off until you take it out.</p>
</div>

<style>
    .guard-try { min-height: 70px; padding: 14px; border: 1px dashed var(--rule-2); border-radius: 12px; background: var(--paper-3); }
    .guard-help ul { margin: 0 0 10px; padding-left: 18px; display: flex; flex-direction: column; gap: 10px; font-size: 14px; line-height: 1.55; }
    .guard-help code { font-size: 12px; overflow-wrap: anywhere; }
</style>
