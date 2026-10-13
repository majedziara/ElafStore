# Nella & Vale Laravel API

Laravel 13 backend for the supplied Nella & Vale implementation. The API uses USD, Sanctum cookie sessions, Spatie permissions, and PayPal Orders v2. The local database is MySQL `elaf_store`.

## Local setup

```bash
composer install
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
```

Use `http://localhost:8000` consistently in the browser and Postman. Keep the existing `APP_KEY`. Configure credentials only in `.env`; `.env.example` contains empty PayPal credentials.

For the local sandbox catalog and checkout configuration:

```bash
php artisan db:seed --class=LocalSandboxSeeder
```

This local-only seeder enables PayPal sandbox, creates three sample products, sets a $5 test shipping method for NY, and uses `tax_mode=none`. These are development fixtures. Returns remain disabled until configured through the settings API. The regular seeder does not enable checkout or insert test shipping/tax rules.

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

PayPal returns the browser to `GET /api/paypal/return`. The server validates an encrypted callback state and the saved PayPal order ID, checks PayPal's amount/currency/merchant/order identity, captures approved payments, and returns the verified local order status. It works for guests without depending on a frontend session. Pending or ambiguous provider results return HTTP 202; confirmed payment states return HTTP 200. Repeating a return cannot capture or deduct inventory twice.

`GET /api/paypal/cancel` checks provider status without requesting a capture. A browser cancellation alone does not prove that payment failed; confirmed payments remain paid and unresolved results remain pending until reconciled.

The Start PayPal response includes `provider_order_id`, `return_url`, and `cancel_url`. The collection saves the protected callback states for its Return/Cancel requests. Callbacks include an expiring `order_token` for the normal protected order API.

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
