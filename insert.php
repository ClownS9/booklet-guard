<?php

/*
 * Guard: a "prove you're human" check on the site's forms (comments, the
 * contact form, new posts by email, and sign-in if you like), with
 * Cloudflare Turnstile, Google reCAPTCHA or hCaptcha; words and addresses
 * to turn away; and a log of what it stopped. Uses Booklet's 'guard' hook
 * (see inserts/README.md › Guarding forms).
 */

use App\Inserts\Insert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/** The checks it can use: name, where to get keys, the script, the address that answers. */
$providers = [
    'turnstile' => ['Cloudflare Turnstile', 'https://dash.cloudflare.com/?to=/:account/turnstile', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'],
    'recaptcha' => ['Google reCAPTCHA v2 (the tick box)', 'https://www.google.com/recaptcha/admin/create', 'https://www.google.com/recaptcha/api/siteverify'],
    'recaptcha3' => ['Google reCAPTCHA v3 (invisible, scored)', 'https://www.google.com/recaptcha/admin/create', 'https://www.google.com/recaptcha/api/siteverify'],
    'hcaptcha' => ['hCaptcha', 'https://dashboard.hcaptcha.com/sites', 'https://api.hcaptcha.com/siteverify'],
];

/** Which check, and whether both keys are there. */
$provider = function (Insert $insert) use ($providers): ?string {
    $p = (string) $insert->setting('provider', 'none');

    return isset($providers[$p]) && trim((string) $insert->setting('site_key')) !== '' && (string) $insert->setting('secret_key') !== '' ? $p : null;
};

/** The keys in use, as a fingerprint: sign-in protection waits until these have been tried. */
$keysPrint = fn (Insert $insert) => sha1($insert->setting('provider').'|'.$insert->setting('site_key').'|'.$insert->setting('secret_key'));
$tried = fn (Insert $insert) => ($s = $insert->records('state')->findBySlug('tried')) && $s->field('keys') === $keysPrint($insert);

/** Lines of a textarea, tidied. */
$lines = fn (?string $text) => array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $text) ?: [])));

/** Ask the check's own server whether an answer is good. */
$verify = function (Insert $insert, string $token, ?string $ip, ?string $action = null) use ($providers, $provider): array {
    $p = $provider($insert);
    if (! $p) {
        return ['ok' => true, 'why' => null];
    }
    try {
        $response = Http::asForm()->timeout(6)->connectTimeout(4)->post($providers[$p][2], array_filter([
            'secret' => (string) $insert->setting('secret_key'),
            'response' => $token,
            'remoteip' => $ip,
            'sitekey' => $p === 'hcaptcha' ? (string) $insert->setting('site_key') : null,
        ]));
    } catch (Throwable) {
        return ['ok' => null, 'why' => 'unreachable'];
    }
    if (! $response->successful()) {
        return ['ok' => null, 'why' => 'unreachable'];
    }
    $answer = (array) $response->json();
    $codes = (array) ($answer['error-codes'] ?? []);
    if (array_intersect($codes, ['invalid-input-secret', 'missing-input-secret', 'sitekey-secret-mismatch', 'invalid-sitekey'])) {
        return ['ok' => false, 'why' => 'keys', 'codes' => $codes];
    }
    if (empty($answer['success'])) {
        return ['ok' => false, 'why' => 'failed', 'codes' => $codes];
    }
    if ($p === 'recaptcha3') {
        $score = (float) ($answer['score'] ?? 0);
        if ($score < (float) $insert->setting('min_score', 0.5)) {
            return ['ok' => false, 'why' => 'score', 'score' => $score];
        }
        if ($action && isset($answer['action']) && $answer['action'] !== $action) {
            return ['ok' => false, 'why' => 'failed'];
        }
    }

    return ['ok' => true, 'why' => null, 'score' => $answer['score'] ?? null];
};

/** A refusal, written down (with only part of the address, so nobody is identified by it). */
$note = function (Insert $insert, string $form, string $why, Request $request): void {
    $ip = (string) $request->ip();
    $net = str_contains($ip, ':') ? implode(':', array_slice(explode(':', $ip), 0, 3)).'::/48' : preg_replace('/\.\d+$/', '.0/24', $ip);
    $insert->records('stops')->create(['form' => $form, 'why' => $why, 'net' => $net]);
};

$formNames = ['comment' => 'Comments', 'contact' => 'Contact form', 'subscribe' => 'New posts by email', 'login' => 'Sign in', 'reset' => 'Forgotten password', 'test' => 'The test on this page'];

return [
    'name' => 'Guard',
    'about' => 'Keeps robots out of your forms: a “prove you’re human” check (Cloudflare Turnstile, Google reCAPTCHA or hCaptcha), words and addresses to turn away, and a log of what it stopped.',
    'version' => '1.0',
    'author' => 'Booklet',
    'sticker' => 'ink',
    'needs' => '1.1', // Booklet's form hook ('guard') and encrypted 'secret' settings

    'settings' => [
        'provider' => ['type' => 'select', 'label' => 'The check', 'default' => 'none', 'options' => [
            'none' => 'None yet (only the lists below)',
            'turnstile' => 'Cloudflare Turnstile (free, private, often no puzzle)',
            'recaptcha' => 'Google reCAPTCHA v2 (the “I’m not a robot” box)',
            'recaptcha3' => 'Google reCAPTCHA v3 (invisible, gives a score)',
            'hcaptcha' => 'hCaptcha',
        ], 'hint' => 'Each one gives you two keys for your site’s address. Where to get them is on the Guard page.'],
        'site_key' => ['type' => 'text', 'label' => 'Site key', 'hint' => 'The public one: it goes in the page.'],
        'secret_key' => ['type' => 'secret', 'label' => 'Secret key', 'hint' => 'Kept encrypted, and never put in a backup.'],
        'min_score' => ['type' => 'number', 'label' => 'Lowest score let through (reCAPTCHA v3)', 'default' => 0.5, 'min' => 0.1, 'max' => 0.9, 'hint' => '0.1 lets almost everyone through, 0.9 is strict. 0.5 suits most sites.'],
        'on_comments' => ['type' => 'toggle', 'label' => 'On comments', 'default' => true],
        'on_contact' => ['type' => 'toggle', 'label' => 'On the contact form', 'default' => true],
        'on_subscribe' => ['type' => 'toggle', 'label' => 'On new posts by email', 'default' => true],
        'on_login' => ['type' => 'toggle', 'label' => 'On sign-in and forgotten password', 'default' => false, 'hint' => 'Waits until you’ve tried the keys on the Guard page, so a wrong key can’t lock you out.'],
        'unreachable' => ['type' => 'select', 'label' => 'If the check can’t be reached', 'default' => 'let', 'options' => [
            'let' => 'Let it through, and note it',
            'stop' => 'Ask them to try again in a minute',
        ], 'hint' => 'Signing in is always let through then (the usual limits on tries still apply).'],
        'blocked_words' => ['type' => 'textarea', 'label' => 'Words to turn away', 'hint' => 'One per line. Comments and messages with any of them aren’t sent.'],
        'blocked_addresses' => ['type' => 'textarea', 'label' => 'Addresses to turn away', 'hint' => 'IP addresses or ranges (203.0.113.7, 198.51.100.0/24), one per line. Not used on sign-in, so you can’t lock yourself out.'],
        'keep_days' => ['type' => 'number', 'label' => 'Keep the log for (days)', 'default' => 30, 'min' => 1, 'max' => 365],
    ],

    // The hook in Booklet's forms: which, what to show, and the check.
    'guard' => [
        'forms' => function (Insert $insert) use ($provider, $tried, $lines) {
            $lists = $lines($insert->setting('blocked_words')) !== [] || $lines($insert->setting('blocked_addresses')) !== [];
            $check = $provider($insert) !== null;
            $forms = [];
            foreach (['comment' => 'on_comments', 'contact' => 'on_contact', 'subscribe' => 'on_subscribe'] as $form => $setting) {
                if ($lists || ($check && $insert->setting($setting))) {
                    $forms[] = $form;
                }
            }
            if ($check && $insert->setting('on_login') && $tried($insert)) {
                array_push($forms, 'login', 'reset');
            }

            return $forms;
        },

        'widget' => function (string $form, Insert $insert) use ($provider) {
            $p = $provider($insert);
            $on = ['comment' => 'on_comments', 'contact' => 'on_contact', 'subscribe' => 'on_subscribe', 'login' => 'on_login', 'reset' => 'on_login'][$form] ?? null;
            if (! $p || ($on && ! $insert->setting($on))) {
                return '';
            }

            return '<div class="guard-box" data-provider="'.e($p).'" data-sitekey="'.e((string) $insert->setting('site_key')).'" data-form="'.e($form).'"></div>'
                .($p === 'recaptcha3' ? '<p class="guard-note">Protected by reCAPTCHA: Google’s <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">privacy policy</a> and <a href="https://policies.google.com/terms" target="_blank" rel="noopener">terms</a> apply.</p>' : '')
                .'<script src="'.e($insert->asset('guard.js')).'" defer></script>';
        },

        'check' => function (string $form, array $answers, Request $request, string $token, Insert $insert) use ($provider, $verify, $note, $lines) {
            // Addresses (never on sign-in: you can't lock yourself out).
            if (! in_array($form, ['login', 'reset'], true)) {
                $ranges = array_values(array_filter($lines($insert->setting('blocked_addresses')), fn ($r) => (bool) preg_match('#^[0-9a-f:.]+(/\d{1,3})?$#i', $r)));
                if ($ranges !== [] && IpUtils::checkIp((string) $request->ip(), $ranges)) {
                    $note($insert, $form, 'address', $request);

                    return 'Sorry, this can’t be sent from your connection.';
                }
            }

            // Words.
            if (in_array($form, ['comment', 'contact'], true)) {
                $text = ' '.Str::lower(implode(' ', $answers)).' ';
                foreach ($lines($insert->setting('blocked_words')) as $word) {
                    $word = Str::lower($word);
                    if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $text)) {
                        $note($insert, $form, 'words', $request);

                        return 'Sorry, this site doesn’t take messages with some of those words.';
                    }
                }
            }

            // The "prove you're human" check.
            $p = $provider($insert);
            $on = ['comment' => 'on_comments', 'contact' => 'on_contact', 'subscribe' => 'on_subscribe', 'login' => 'on_login', 'reset' => 'on_login'][$form] ?? null;
            if (! $p || ($on && ! $insert->setting($on))) {
                return null;
            }
            if ($token === '') {
                $note($insert, $form, 'no-answer', $request);

                return $p === 'recaptcha' || $p === 'hcaptcha' ? 'Please tick the box to show you’re not a robot, then try again.' : 'One moment: the check that you’re not a robot hasn’t finished. Try again in a second.';
            }
            $result = $verify($insert, $token, $request->ip(), 'booklet_'.$form);
            if ($result['ok'] === null) {
                $note($insert, $form, 'unreachable', $request);
                if (in_array($form, ['login', 'reset'], true) || $insert->setting('unreachable') !== 'stop') {
                    return null; // let through: the check's own server didn't answer
                }

                return 'The check that you’re not a robot can’t be reached just now. Please try again in a minute.';
            }
            if ($result['ok'] === false) {
                $note($insert, $form, $result['why'], $request);
                if ($result['why'] === 'keys' && in_array($form, ['login', 'reset'], true)) {
                    return null; // never lock anyone out over a wrong key: the Guard page says so
                }

                return $result['why'] === 'score'
                    ? 'Sorry, that looked a little like a robot. Please try again.'
                    : 'The check that you’re not a robot didn’t pass. Please try it again.';
            }

            return null;
        },
    ],

    'page' => [
        'title' => 'Guard',
        'chapter' => 'Comments',
        'who' => ['admin'],
        'left' => 'admin-left',
        'form' => ['token' => ''],

        'data' => function (Insert $insert) use ($providers, $provider, $tried, $formNames) {
            $stops = $insert->records('stops')->all()->sortByDesc('id')->values();
            $week = $stops->filter(fn ($s) => $s->created_at?->gt(now()->subWeek()));
            $p = (string) $insert->setting('provider', 'none');

            return [
                'providers' => $providers,
                'chosen' => $p,
                'ready' => $provider($insert),
                'tried' => $tried($insert),
                'onLogin' => (bool) $insert->setting('on_login'),
                'triedAt' => $insert->records('state')->findBySlug('tried')?->field('at'),
                'stops' => $stops->take(25),
                'total' => $stops->count(),
                'week' => $week->count(),
                'byForm' => $week->countBy(fn ($s) => $s->field('form')),
                'formNames' => $formNames,
                'forms' => array_values(array_filter(['comment', 'contact', 'subscribe', 'login'], fn ($f) => app(\App\Inserts\InsertManager::class)->guards($f)->has($insert->key))),
                'testWidget' => $provider($insert)
                    ? '<div class="guard-box" data-provider="'.e((string) $provider($insert)).'" data-sitekey="'.e((string) $insert->setting('site_key')).'" data-form="test" data-model="form.token"></div><script src="'.e($insert->asset('guard.js')).'" defer></script>'
                    : '',
            ];
        },

        'actions' => [
            // Try the keys for real: the widget's answer, checked by the provider's server.
            'test' => function (Insert $insert, array $form) use ($provider, $verify, $keysPrint) {
                if (! $provider($insert)) {
                    return 'Choose a check and save both keys on the Inserts shelf first.';
                }
                $token = (string) ($form['token'] ?? '');
                if ($token === '') {
                    return 'Do the check in the box first (it may need a moment, or a tick).';
                }
                $result = $verify($insert, $token, request()->ip(), 'booklet_test');
                if ($result['ok'] === true) {
                    $state = $insert->records('state');
                    $data = ['keys' => $keysPrint($insert), 'at' => now()->toIso8601String()];
                    ($s = $state->findBySlug('tried')) ? $state->update($s, $data) : $state->create($data, 'tried');

                    return ['form' => ['token' => ''], 'stamp' => 'The keys work.'.($insert->setting('on_login') ? ' Sign-in is guarded now too.' : ' You can switch on sign-in protection if you like.')];
                }

                return ['form' => ['token' => ''], 'stamp' => match ($result['why']) {
                    'keys' => 'The secret key isn’t right for this site key. Copy both again from the provider.',
                    'unreachable' => 'The provider didn’t answer. Check the server can reach it, and try again.',
                    'score' => 'The keys work, but this try scored low. Try again, or lower the lowest score.',
                    default => 'That didn’t pass ('.implode(', ', (array) ($result['codes'] ?? [])).'). The box may have expired: do it again.',
                }];
            },
            'clear' => function (Insert $insert) {
                $insert->records('stops')->all()->each(fn ($s) => $insert->records('stops')->delete($s));

                return 'The log is empty.';
            },
        ],
    ],

    'contents' => [
        'guard' => [
            'title' => 'Robots turned away',
            'who' => ['admin'],
            'show' => function (Insert $insert) use ($formNames) {
                $week = $insert->records('stops')->all()->filter(fn ($s) => $s->created_at?->gt(now()->subWeek()));
                if ($week->count() < 3) {
                    return null; // a couple is just the internet
                }
                $by = $week->countBy(fn ($s) => $s->field('form'))->sortDesc();

                return [
                    'count' => $week->count(),
                    'text' => 'This week: '.$by->map(fn ($n, $f) => ($formNames[$f] ?? $f).' '.$n)->implode(', ').'.',
                    'link' => ['label' => 'The guard’s log', 'href' => route('admin.insert', $insert->key)],
                ];
            },
        ],
    ],

    'stacy' => [
        'stops' => [
            'about' => 'How many tries the Guard insert turned away from the site’s forms in the last week and month, by form and reason.',
            'label' => 'Tells you how many robots were turned away',
            'look' => function (array $args, Insert $insert) use ($formNames) {
                $all = $insert->records('stops')->all();
                $since = fn ($days) => $all->filter(fn ($s) => $s->created_at?->gt(now()->subDays($days)));

                return [
                    'last_7_days' => $since(7)->count(),
                    'last_30_days' => $since(30)->count(),
                    'by_form' => $since(30)->countBy(fn ($s) => $formNames[$s->field('form')] ?? $s->field('form'))->all(),
                    'by_reason' => $since(30)->countBy(fn ($s) => $s->field('why'))->all(),
                    'check' => (string) $insert->setting('provider', 'none'),
                    'page' => route('admin.insert', $insert->key),
                ];
            },
        ],
    ],

    'every' => [
        'daily' => function (Insert $insert) {
            $days = max(1, (int) $insert->setting('keep_days', 30));
            $insert->records('stops')->all()
                ->filter(fn ($s) => $s->created_at?->lt(now()->subDays($days)))
                ->each(fn ($s) => $insert->records('stops')->delete($s));
        },
    ],
];
