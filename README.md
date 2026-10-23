# Guard

Keeps robots out of your forms.

- **A “prove you’re human” check** on comments, the contact form and new posts by
  email (and sign-in, if you like): Cloudflare Turnstile, Google reCAPTCHA (v2 or v3)
  or hCaptcha.
- **Words and addresses to turn away**, with or without a check.
- **A log** of what it stopped (with only part of each address), a card on Contents
  when it’s busy, and Stacy can tell you how many.

1. Switch it on in Settings › Inserts.
2. Choose a check and paste its two keys in its settings (the Guard page says where
   to get them; Turnstile has test keys that always pass).
3. Open **Guard** (in the Comments chapter) and try the keys. Sign-in protection waits
   until they’ve worked once, so a wrong key can’t lock you out.

If you’re ever locked out anyway, `BOOKLET_SAFE_MODE=true` in `.env` switches every
insert off.
