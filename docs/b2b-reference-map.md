# B2B reference map (cheap scan)

B2C product/tech plan: [b2c-backend-plan.md](b2c-backend-plan.md) (use that instead of the PDF).

Local `B2B/` is a **read-only** copy of the existing Laravel 8 app. It is listed in `.gitignore` — do not commit it.

Use this allowlist when grepping or reading B2B. Do not walk `vendor/`, `sqlupdates/`, CMS addons, or the full `ZohoController` / SalesZing tree.

Reuse **patterns and field names** only. Do not fork B2B into B2C.

Paths below are relative to `B2B/`.

---

## MUST — OTP / Sanctum

| Path | Why |
|------|-----|
| `app/Http/Controllers/Api/V2/AuthController.php` | API `login_otp`, Sanctum `createToken` |
| `app/Http/Controllers/OTPVerificationController.php` | Send / verify OTP (`send_code`) |
| `routes/api.php` | `v2/auth/*`, `auth:sanctum` (auth section only) |
| `routes/otp.php` | OTP web/admin routes |
| `app/Models/User.php` | `HasApiTokens` |
| `config/sanctum.php` | Sanctum config |
| `app/Http/Kernel.php` | Sanctum middleware |
| `app/Utility/SendSMSUtility.php` | SMS send helper |
| `app/Utility/SmsUtility.php` | SMS helper |
| `database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php` | Token table |

Key endpoints to mirror in spirit: `POST v2/auth/login_otp`, `POST v2/auth/login`.

---

## MUST — Zoho Payments (not Zoho Books)

`ZohoController.php` is huge and mixed with Books. Read **only** payment methods: `generatePaymentUrl`, `createPaymentLink`, `startZohoPaymentAuth`, `paymentCallback`, `afterPaymentRedirect`. Ignore `webHookUrl()` (stub) and Books/item code.

| Path | Why |
|------|-----|
| `app/Http/Controllers/ZohoController.php` | Payment link + return/status update |
| `app/Models/ZohoPayment.php` | Payment record |
| `app/Models/ZohoPaymentToken.php` | OAuth token |
| `routes/admin.php` | `/zoho/payment-auth`, `/zoho/payment-link`, `/zoho/payment-callback`, `/zoho/after-payment-redirect` (~2439–2442) |
| `resources/views/frontend/zoho_payment/` | Success/return views |
| `app/Http/Controllers/RewardReminderController.php` | Clean caller of `generatePaymentUrl` |

Env keys exist on B2B (do not copy secrets): `ZOHO_PAYMENT_ACCOUNT_ID`, `ZOHO_PAYMENT_CLIENT_ID`, `ZOHO_PAYMENT_CLIENT_SECRET`.

**Not Zoho Payments:** `POST v2/payment/generate-url` / `Api/V2/PaymentController@generateUrl` (legacy URLs + SalesZing). `POST payment/webhook` is Active eCommerce, not Zoho.

---

## MUST — product fields

Business key for B2C sync: **`part_no`**. HSN column is **`hsncode`**. Images: `thumbnail_img`, `photos`.

| Path | Why |
|------|-----|
| `app/Models/Product.php` | `part_no`, taxes, images |
| `app/Models/ProductTax.php` | Tax pivot |
| `app/Models/Tax.php` | Tax |
| `app/Models/ProductApi.php` | Stock/API by `part_no` |
| `app/Http/Controllers/ProductController.php` | Field names: `part_no`, `hsncode`, `tax`, `purchase_tax`, `thumbnail_img`, `photos` |
| `app/Http/Resources/V2/ProductDetailCollection.php` | API image/shape (`photos`, `thumbnail_image`) |
| `app/Http/Resources/V2/ProductMiniCollection.php` | List shape |
| `app/Services/ProductTaxService.php` | Tax attach pattern |

---

## MUST — queues / jobs (shape only)

| Path | Why |
|------|-----|
| `config/queue.php` | Queue config (B2B default is often `sync`) |
| `app/Jobs/` | Job class shape |
| `database/migrations/` jobs + failed_jobs tables | Queue schema |

Safe pattern examples: `UpdateProductStockJob.php`, `SendWhatsAppMessagesJob.php`. **Skip** `SyncSalzing*`, statement PDFs, Zoho Books payment sync jobs.

---

## OPTIONAL — order ingest later

Thin ingest on B2B must be a **new** API, not a rewrite of these. Read only to understand fields and checkout shape.

| Path | Why |
|------|-----|
| `app/Http/Controllers/Api/V2/OrderController.php` | `POST v2/order/store` |
| `app/Http/Controllers/Api/V2/CheckoutController.php` | Checkout API |
| `app/Http/Controllers/CheckoutController.php` | Web checkout |
| `app/Models/Order.php` | Order |
| `app/Models/OrderDetail.php` | Lines |
| `app/Models/CombinedOrder.php` | Combined order |
| `app/Models/Cart.php` | Cart |
| `app/Services/OrderService.php` | Status helper |
| `app/Services/ProductService.php` | Product service |
| `app/Http/Controllers/Api/V2/ProductController.php` | Product list/detail API |
| `config/auth.php`, `config/cors.php`, `config/services.php` | Auth/CORS/services |
| `app/Providers/RouteServiceProvider.php` | Route loading |

---

## IGNORE — do not scan

- `vendor/`
- `cgi-bin/`
- `sqlupdates/`
- `api/` (nested SalesZing PHP)
- Zip backups under `app/` (`Models.zip`, `Jobs.zip`, …)
- `routes/seller.php`, `routes/api_seller.php`, `routes/affiliate.php`, `routes/auction.php`, `routes/paytm.php`, `routes/pos.php`, `routes/wholesale.php`, unused gateway route files
- `app/Http/Controllers/Payment/` and unused gateway configs (`paystack`, `flutterwave`, …)
- `app/Http/Controllers/Seller/`, `app/Http/Controllers/Api/V2/Seller/`
- `app/Http/Controllers/Api/V2/SaleszingController.php` and all `SyncSalzing*` jobs
- `HatimZohoController.php`, `BmiZohoController.php` (Zoho Books/items)
- Statements, challans, POs, packing, Manager GST / 41 Manager, split-order controllers

B2B Laravel version (vendor): **8.83.29**. B2C is Laravel 11/12 — do not copy framework files.
