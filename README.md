# Nella & Vale Laravel API

Laravel 13 backend for the supplied Nella & Vale implementation. The API uses USD, Sanctum cookie sessions, Spatie permissions, and hosted checkout through PayPal Orders v2, Stripe Checkout, and Triple-A. The local database is MySQL `elaf_store`.

## Local setup

```bash
composer install
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
```

The API and Postman use `http://127.0.0.1:8000`; the connected Next.js storefront runs at `http://localhost:3000`. Laravel `APP_URL` is the public storefront origin so local payment and email links open the site. `PUBLIC_STORAGE_URL` points to `http://127.0.0.1:8000/storage`. Keep the existing `APP_KEY`. Configure credentials only in `.env`; `.env.example` contains sandbox/live payment configuration templates.

For the Vercel storefront and Render API, set these environment variables on Render:

```dotenv
APP_URL=https://elaf-store.vercel.app
FRONTEND_URL=https://elaf-store.vercel.app
FRONTEND_ORIGINS=https://elaf-store.vercel.app
SANCTUM_STATEFUL_DOMAINS=elaf-store.vercel.app
SESSION_COOKIE=nv_session
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

`SANCTUM_STATEFUL_DOMAINS` trusts frontend hosts with optional ports; `FRONTEND_ORIGINS` uses full origins. The configuration normalizes accidental schemes/trailing slashes and surrounding whitespace without adding trusted hosts. An incorrect stateful domain can allow login and `/api/v1/auth/me` while protected `/api/v1/admin/*` and `/api/v1/account/*` return `401 Unauthenticated`. The Next.js BFF must use `LARAVEL_API_URL=https://elaf-store.onrender.com` and the same `nv_session` cookie name. Keep `PUBLIC_STORAGE_URL` pointed at the Render API's `/storage` URL.

After changing Render environment variables, redeploy to rebuild Laravel's configuration cache. For an existing running instance, use `php artisan config:cache --no-interaction` after its new environment is available, then restart long-running workers. Sign out and sign in again after changing cookie settings.

For the local sandbox catalog and checkout configuration:

```bash
php artisan db:seed --class=LocalSandboxSeeder
```

This local-only seeder requires an available sandbox gateway in .env, creates three sample products, sets a $5 test shipping method for NY, and uses `tax_mode=none`. These are development fixtures. Returns remain disabled until configured through the settings API. The regular seeder does not enable checkout or insert test shipping/tax rules.

A local administrator was created as `admin@nella.test`. Its generated password is in `storage/app/private/local-admin.txt`. To create another administrator interactively:

```bash
php artisan store:create-admin your-email@example.com
```

## Postman

Import `Nella-and-Vale.postman_collection.json`. An optional local environment, including the generated administrator password and the current sandbox test order, is saved privately at `storage/app/private/Nella-and-Vale.local.postman_environment.json`.

Use `checkout_coupon_code` only when you want an active coupon at checkout; `coupon_code` is used by coupon administration requests.

Start with **CSRF Cookie**, then **Admin Login** or **Customer Login**. Enable the cookie jar and allowlist `localhost`. API authentication uses session cookies and `X-XSRF-TOKEN`. The collection contains alternative, state-dependent requests; use the relevant workflow folders and IDs returned by the API.

The collection includes 159 requests, including local PayPal callbacks and order email links. Automated tests resolve every request to its implemented route/controller, use the supplied Postman bodies for resource CRUD, and test authentication, ownership, checkout pricing/idempotency, inventory, payment reconciliation, refunds, receipts, shipments, returns, exchanges, media, and permissions.

## Queue and scheduled reconciliation

Run in separate terminals:

```bash
php artisan queue:work --tries=3 --timeout=120
php artisan schedule:work
```

`store:maintain` runs every five minutes. Queue retry time is 180 seconds. Email uses the local log mailer until SMTP is configured; queued mail links appear in `storage/logs/laravel.log` after the worker runs.

## Payment return, cancellation, and verification

All return/cancel and email verification links point to this Laravel application:

```dotenv
PAYPAL_RETURN_URL="${APP_URL}/api/paypal/return"
PAYPAL_CANCEL_URL="${APP_URL}/api/paypal/cancel"
```

PayPal returns the browser to `GET /api/paypal/return`. The frontend callback forwards a fixed request to this Laravel callback, and this server validates an encrypted callback state and the saved PayPal order ID, checks PayPal's amount/currency/merchant/order identity, captures approved payments, and returns the verified local order status. It works for guests without depending on a frontend session. Pending or ambiguous provider results return HTTP 202; confirmed payment states return HTTP 200. Repeating a return cannot capture or deduct inventory twice.

`GET /api/paypal/cancel` checks provider status without requesting a capture. A browser cancellation alone does not prove that payment failed; confirmed payments remain paid and unresolved results remain pending until reconciled.

The Start PayPal response includes `provider_order_id`, `return_url`, and `cancel_url`. The collection saves the protected callback states for its Return/Cancel requests. Direct JSON API callbacks include an expiring `order_token` for the normal protected order API. The Next.js callback stores this grant in an encrypted HttpOnly cookie and redirects to a view of the verified order; it never puts the grant in a browser URL.

Verification/change-email links use the local GET variants of `/api/v1/auth/consume/{purpose}`. Password reset opens the local `/api/v1/auth/reset-password` form and submits to the existing local reset API. Order/payment/shipment/return notification links open `GET /api/v1/guest-orders/consume`, which validates a single-use token and returns the order and an expiring access token.

## PayPal sandbox and local webhook tunnel

```bash
php artisan store:paypal-check
php -S 127.0.0.1:8001 resources/scripts/paypal-webhook-proxy.php
ngrok http 8001
```

The proxy accepts only `POST /api/v1/webhooks/paypal/sandbox`, forwards the original body and PayPal signature headers to the local API, and hides upstream error details. All other routes return 404. The tunnel does not expose the store or administrator endpoints.

Register the ngrok HTTPS URL plus `/api/v1/webhooks/paypal/sandbox` as a sandbox webhook. Set `PAYPAL_WEBHOOK_BASE_URL` to the tunnel origin and `PAYPAL_SANDBOX_WEBHOOK_ID` to that registration's ID. The current temporary registration is saved in `storage/app/private/sandbox-webhook.json`; the previous Emtdad sandbox webhook was removed at the user's request, so this sandbox app only delivers events to the current store. A changed tunnel URL requires a matching registration/configuration update.

Subscribe to `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.PENDING`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED`, and `PAYMENT.CAPTURE.REVERSED`.

Actual sandbox OAuth, order creation, buyer approval, capture, a $1 partial refund, and the remaining $38 refund all succeeded. A second real sandbox purchase returned the browser directly to http://localhost:8000/api/paypal/return, where this Laravel project verified and captured it automatically; the browser received Payment confirmed. Repeating its return and cancel callbacks preserved one capture and one stock deduction. Its test payment was also fully refunded. Repeating the partial refund with its original idempotency key did not charge it twice. Genuine approval/capture/refund webhook events arrived through ngrok, passed signature verification, and were processed. A PDF receipt was downloaded to `storage/app/private/Sandbox-test-receipt.pdf`. The sample order is now fully refunded; refunding does not automatically restock it. Automated tests also cover ambiguous provider failures and rejected webhook signatures.

## Verification

```bash
php artisan test --compact
composer validate --strict
composer check-platform-reqs
php artisan migrate:status
php artisan schedule:list
php artisan queue:failed
```

The current SQLite suite passed with 54 tests and 810 assertions. All 54 tests also passed on an isolated local MySQL test database. The MySQL run used `DB_CONNECTION=mysql DB_DATABASE=elaf_store_testing_20261013 php artisan test --compact`; use a dedicated test database because `RefreshDatabase` rebuilds its schema.

During integration, refund requests were updated to ask PayPal for `Prefer: return=representation`. A persisted `capture_requested_at` now prevents an ambiguous capture timeout from releasing reserved stock. Fully refunded orders are excluded from the awaiting-fulfillment dashboard count. Credentials and gateway snapshots remain encrypted in the database and hidden from API serialization.

## Payment configuration

Laravel `.env` is the only source for new payment availability. Database gateway flags/credentials no longer enable methods. The storefront reads `GET /api/v1/payment-methods`; incomplete or disabled active environments are hidden, and the backend rejects starting them. Existing attempts retain their encrypted configuration snapshot and cannot switch providers while unresolved.

Set `STRIPE_MODE=sandbox` or `live`. For the chosen environment, supply `STRIPE_SANDBOX_SECRET_KEY` and `STRIPE_SANDBOX_WEBHOOK_SECRET`, or the corresponding `STRIPE_LIVE_*` values. Hosted Checkout needs no publishable key or frontend SDK. Secret keys must match their mode (`sk_test_`/`sk_live_`); webhook secrets begin `whsec_`. The REST client pins Stripe API `2025-06-30.basil`.

Register separate **snapshot** Stripe webhooks at `${PAYMENT_WEBHOOK_BASE_URL}/api/v1/webhooks/stripe/sandbox` and `/live`. Subscribe to `checkout.session.completed`, `checkout.session.expired`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `payment_intent.succeeded`, `payment_intent.processing`, `payment_intent.payment_failed`, `charge.refunded`, `charge.dispute.created`, `refund.created`, `refund.updated`, and `refund.failed`. Copy each endpoint's signing secret into the matching environment variable. The public webhook URL must use HTTPS; local testing requires a public tunnel or Stripe CLI forwarding. Next.js forwards exact signed bytes to Laravel without session cookies.

Set `TRIPLEA_MODE=sandbox` or `live`, then fill that environment's `CLIENT_ID`, `CLIENT_SECRET`, and `MERCHANT_KEY` values. `TRIPLEA_SANDBOX_NOTIFY_SECRET` and `TRIPLEA_LIVE_NOTIFY_SECRET` are optional overrides of up to 64 characters; otherwise signing secrets are derived privately from Laravel `APP_KEY`. Requests supply their own webhook URL and signing secret automatically. Triple-A's default sandbox uses **testBTC**; live currencies including **USDT** depend on merchant account activation. Hosted checkout displays the available networks/currencies and exact amount. `none`, `short`, and `hold` do not confirm payment; only verified `good` with sufficient USD received does. Default crypto refunds email the recipient to confirm their wallet, and remain pending until Triple-A reports `done`.

Callbacks are generated on the public storefront at `/api/payments/{stripe|triple-a}/{return|cancel}`, bound to an attempt with encrypted expiring state. Browser claims about success or amounts are ignored. Refunds, stock movements, webhook processing, and provider retries are idempotent. Unknown create/refund results require reconciliation, so they never issue another request blindly.

After changing `.env`:

```bash
php artisan config:clear
php artisan queue:restart
php artisan store:payments-check --gateway=stripe --environment=sandbox
php artisan store:payments-check --gateway=triple-a --environment=sandbox
# Optional read-only provider authentication (no payment created):
php artisan store:payments-check --gateway=stripe --environment=sandbox --remote
```

Run `php artisan queue:work` and the scheduler (`php artisan schedule:work` locally, `schedule:run` every minute in deployment). `store:maintain` checks pending payments/refunds every five minutes. `*_ENABLED=false` hides a configured method; mode changes do not migrate existing attempts. PayPal remains supported with its local callbacks and sandbox/live credentials. Bank of Palestine, BTCPay, and 2Checkout remain unavailable under the current scope.
