# Tests

Two harnesses. Neither ships in the release zip (`package.ps1` excludes `tests/`, and `phpcs.xml` excludes it from the gate).

## Logic tests (PHP only)

`tests/logic-tests.php` stubs the little WordPress the pure classes touch (`WP_Error`, `is_wp_error()`, `get_option()`) and drives the real code under `includes/logic/` and the Stripe HTTP client. It needs PHP only: no WordPress install, no database, no network. A transport stub throws if anything tries to reach `wp_remote_request()`.

From the plugin folder:

```sh
php tests/run.php          # both runs: the WooCommerce Stripe gateway present, then absent
php tests/logic-tests.php  # one run
```

The first assertion is a deliberate failure, so a run that reports zero failures is a run that cannot count. Each run prints one line per assertion and a `RESULT:` line; `run.php` exits non-zero if anything but that self-test fails. Covered:

- `Hpve_Request_State`: every transition in the design table and every forbidden move; every card state; the menu words, pill modifiers and when the form opens.
- `Hpve_Document_Types`: absent, `''` and array options; sanitised keys, dropped formats, clamped limits, disabled rows, defaults.
- `Hpve_Payment_Rules`: order status pairs, subscription events, renewals.
- `Hpve_Path`: traversal, prefix collisions, separators, Windows case folding.
- `Hpve_Stripe_Signature`: every case in `tests/fixtures/stripe-signature-cases.json`, signed at run time with the dummy secret `whsec_test_dummy`.
- `Hpve_Stripe_Mapper`: every status and error code, the attempt limit boundary, sign-off, event parsing.
- `Hpve_Stripe_Http`: the request shape, JSON and HTTP errors, and that no returned value ever carries the key.
- Copy and identity: author credit, version parity, no em-dash, no business or person named, email subjects free of apostrophes, every `%token%` in an email present in its token list, the POT stripped of identity entries.

## Early renewal check (a real HivePress install, WP-CLI, 2.1.0)

`tests/renewal-check.php` seeds its own users and Vendors, checks and cleans up in one run, restoring every
option it touched: `wp eval-file tests/renewal-check.php` (38 assertions). It covers the renewal window, the
badge staying on, the new period running from the old date, the old date passing mid-renewal, the admin
tick adding one period not two, the retention marker cleared on resubmission, the reminder email link, and
the card the Vendor sees.

## Runtime check (a real HivePress install, WP-CLI)

`tests/runtime-check.php` runs against a local HivePress install through `wp eval-file`, in phases, because the account page renders only for a user who was signed in before WordPress loaded:

```sh
HPVE_PHASE=seed  wp eval-file tests/runtime-check.php                       # prints APPLICANT_ID etc.
HPVE_PHASE=check wp eval-file tests/runtime-check.php --user=<applicant id> # 214 assertions
HPVE_PHASE=clean wp eval-file tests/runtime-check.php                       # removes everything it made
```

No email is sent (`pre_wp_mail` records what would have gone) and nothing reaches the network (`pre_http_request` blocks and records every outbound call; the Stripe provider is given a fake transport answering from `tests/fixtures/stripe-session-*.json`). Phases: activation and capabilities; storage in both modes with the real upload route; submit and the emails; the admin-post review handler, hand-tick and expiry hand-offs, bulk approve, the columns and boxes; WooCommerce orders and subscriptions; Stripe start, poll, webhook, sync, replay, live-mode mismatch, sign-off; retention and the privacy tools; the settings config and templates.

The HTTP checks (the gate as guest, owner, another user and a reviewer; wp-admin refusals; the settings tab; the REST routes as a guest) are a shell script kept in the session scratchpad, driven with `curl` and a temporary login helper that is deleted afterwards.
