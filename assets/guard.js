/*
 * Guard: draws the "prove you're human" box in each form that has one
 * (.guard-box), with Cloudflare Turnstile, Google reCAPTCHA or hCaptcha,
 * and hands its answer to the form (Livewire: guardToken, or the property
 * in data-model). Boxes that appear later (a form that switches) are
 * drawn too, and each starts again after a try (booklet-guard-reset).
 */
(function () {
  if (window.bookletGuard) { window.bookletGuard.scan(); return; }

  var G = window.bookletGuard = {
    ready: false,
    loading: false,

    load: function (box) {
      if (this.loading) return;
      this.loading = true;
      var p = box.dataset.provider, key = encodeURIComponent(box.dataset.sitekey || '');
      var src = {
        turnstile: 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=bookletGuardReady',
        recaptcha: 'https://www.google.com/recaptcha/api.js?render=explicit&onload=bookletGuardReady',
        recaptcha3: 'https://www.google.com/recaptcha/api.js?render=' + key + '&onload=bookletGuardReady',
        hcaptcha: 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=bookletGuardReady&recaptchacompat=off'
      }[p];
      if (!src) return;
      var s = document.createElement('script');
      s.src = src; s.async = true; s.defer = true;
      document.head.appendChild(s);
    },

    /** Give the answer to the Livewire form the box sits in. */
    set: function (box, token) {
      var host = box.closest('[wire\\:id]');
      if (!host || !window.Livewire) return;
      var form = window.Livewire.find(host.getAttribute('wire:id'));
      if (form) form.set(box.dataset.model || 'guardToken', token || '', false);
    },

    render: function (box) {
      if (box.dataset.done) return;
      var p = box.dataset.provider, key = box.dataset.sitekey, self = this;
      box.dataset.done = '1';
      var dark = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';

      if (p === 'recaptcha3') {
        // Invisible: an answer now, and a fresh one before it runs out (two minutes).
        var run = function () {
          window.grecaptcha.execute(key, { action: 'booklet_' + (box.dataset.form || 'form') }).then(function (t) { self.set(box, t); });
        };
        window.grecaptcha.ready(run);
        box._timer = setInterval(run, 90000);
        box._reset = run;
        return;
      }

      var api = p === 'turnstile' ? window.turnstile : p === 'hcaptcha' ? window.hcaptcha : window.grecaptcha;
      if (!api) { delete box.dataset.done; return; }
      var id = api.render(box, {
        sitekey: key,
        theme: dark,
        callback: function (t) { self.set(box, t); },
        'expired-callback': function () { self.set(box, ''); },
        'error-callback': function () { self.set(box, ''); }
      });
      box._reset = function () { try { api.reset(id); } catch (e) {} self.set(box, ''); };
    },

    scan: function () {
      var boxes = document.querySelectorAll('.guard-box:not([data-done])');
      if (!boxes.length) return;
      if (!this.ready) { this.load(boxes[0]); return; }
      for (var i = 0; i < boxes.length; i++) this.render(boxes[i]);
    }
  };

  window.bookletGuardReady = function () { G.ready = true; G.scan(); };

  // After each try, the box asks again (an answer counts once).
  window.addEventListener('booklet-guard-reset', function (e) {
    var form = e.detail && e.detail.form;
    document.querySelectorAll('.guard-box[data-done]').forEach(function (b) {
      if ((!form || b.dataset.form === form || b.dataset.form === 'test') && b._reset) b._reset();
    });
  });

  new MutationObserver(function () { G.scan(); }).observe(document.documentElement, { childList: true, subtree: true });
  G.scan();
})();
