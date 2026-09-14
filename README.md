# GenericPOS

A customizable **single-seller online shop** with a **point of sale** for the
physical store. The shop and the till share one catalogue, one stock ledger
and one list of customers. The API is CodeIgniter 3 (PHP) and returns JSON.
The front end is a dependency-free single-page app that installs as a PWA,
and the same front end builds an Android app with Capacitor.

## What it does

**Storefront.** Categories, and products with up to three variant options.
Search, cart and checkout, with delivery zones and rates or store pickup, and
coupons. Guests can check out and get a private order link. Customer accounts
hold orders, addresses, store credit, notifications and support threads. The
content pages are about, shipping, returns, terms and privacy.

**Payments.**
- Cash, store credit, cash on delivery and pay on pickup.
- Manual transfers with a reference number (GCash, Maya, bank, card terminal).
- **USDT (BEP-20) per order.** Each payment leases an address from a pool and
  shows a QR code with the amount at a locked rate. The cron scanner applies
  whatever arrives, including partial, late and over-payments; any extra
  becomes store credit.
- Customers can also top up their store credit with USDT.

**Point of sale** (`/pos`).
- Registers are paired to devices, and staff sign in with a PIN.
- Shifts keep a cash drawer ledger, with X and Z reports.
- Barcode scanning.
- Discounts, including SC/PWD VAT exemption (the card holder's details are recorded).
- Split tenders with change, and USDT at the till.
- Printed receipts, and digital receipts behind a QR link.
- Voids, refunds by line, and manager approvals.
- Offline sales, which sync when the connection returns.

**Back office** (`/admin`).
- Dashboard with work queues.
- Products, categories and inventory (an append-only stock ledger).
- Orders: fulfilment steps, mark as paid, cancel, and refunds.
- Customers, including store credit adjustments.
- Staff and roles; registers and shifts.
- Shipping and promotions.
- Reports with CSV export.
- Settings: branding, currency, tax, payments, checkout, POS and features.
- USDT: the rate, the address pool, and parked transfers.
- Support inbox and an audit log.

**PWA and Android.** The PWA has an offline shell, an install prompt,
optional web push, search-engine tags and a sitemap. The Android app runs on
Capacitor 7 and takes over-the-air updates.

Money is always a whole number of minor units (centavos). The default VAT is
the Philippine 12%, prices inclusive; the rate and treatment are configurable.

## Requirements

- **PHP** 8.1 or 8.2 (developed on 8.2), with mysqli, curl, openssl, mbstring,
  bcmath and json. GD is optional. With GD, uploads are resized and
  re-encoded. Without it, uploads keep their size and have their metadata
  stripped.
- **Database:** MariaDB 10.2+ or MySQL 5.7+ (utf8mb4, InnoDB).
- **Web server:** Apache with mod_rewrite; mod_headers and mod_expires are
  recommended. The project root is the docroot. `.htaccess` blocks
  `application/`, `system/`, `capacitor/` and `tests/`, plus every
  file type the web should not serve.
- **HTTPS in production.** Service workers, web push and the POS camera
  scanner all need it.

## Install

1. **Files.** Upload the project as the site's web root, or into a sub-directory.
2. **Database.** Create an empty utf8mb4 database and import `SCHEMA.sql`.
3. **Secrets.** Copy `application/config/secrets.example.php` to `secrets.php`.
   Fill in at least `GP_JWT_SECRET`, `GP_ENCRYPTION_KEY`, `GP_SETUP_KEY` and
   the database credentials. The file explains each key. An environment
   variable with the same name overrides the file.
4. **Address.** In `application/config/config.php`, add your host to
   `$_gp_origins`, for example `'shop.example.com' => 'https://shop.example.com/'`.
   Then point the fallback at it, or set `GP_BASE_URL` in the environment.
5. **First administrator.** Open `/setup`, enter `GP_SETUP_KEY`, and create
   the owner's account. Then clear `GP_SETUP_KEY`. From a shell you can run
   `php index.php tools create_admin <email>` instead.
6. **Cron.** Run `php /path/to/index.php chaincli cron` every five minutes. It
   prints nothing unless something is wrong, so give cron a real email
   address. Each run:
   - closes expired USDT payment windows;
   - cancels online orders left unpaid past `unpaid_order_cancel_hours`;
   - scans for USDT deposits while the address pool is in use;
   - does housekeeping.
7. **Make it yours** in *Back office → Settings*: store name, logo, colours,
   contact details, currency and tax, payment methods, checkout, POS and
   receipt text. Then add categories, products, shipping zones and a register.

Optional:

- **Email.** Fill in the SMTP settings in `app.php` (section M) and set
  `GP_SMTP_PASSWORD`. Settings has a button that sends a test email.
- **USDT.** In *Back office → USDT*, set the rate and add receiving addresses
  you control. The chain facts live in `application/config/chains.php`; the
  default is BSC mainnet, and `CHAIN_RPC_URL` overrides the RPC endpoint.
  Sending USDT refunds from the back office is not switched on yet.
- **Web push.** Put VAPID keys in `secrets.php`, then turn on
  *Settings → Web push notifications*.
- **Android app.** See `capacitor/` and `HANDOVER.md`.

## Command line

| Command | |
|---|---|
| `php index.php chaincli cron` | the scheduled job (see Install) |
| `php index.php chaincli health` | exits 1 if the USDT scan has gone stale |
| `php index.php chaincli status` | chain, RPC, cursor and address pool at a glance |
| `php index.php chaincli shop` | cron's shop jobs on their own, with no chain access |
| `php index.php tools create_admin <email>` | create an administrator, or promote an existing user |
| `php index.php tools set_pin <email> <pin>` | set a staff member's register PIN |
| `php index.php tools cache` | rebuild the shell's branding cache |
| `php index.php tools seed_demo` | load a demo catalogue (development only) |

## Layout

    index.html, sw.js   the app shell and the service worker
                        (bump CACHE_VERSION in sw.js on every front-end change)
    css/app.css         the design system
    js/                 ES modules: router, api, store, cart, pricing (mirrors Pricing_lib), POS, admin
    pages/              one HTML fragment per screen; data-module → js/<name>.js
    icons/              app icons (capacitor/scripts/make-icons.mjs redraws them)
    application/        CodeIgniter: controllers/ (the API), models/, libraries/, helpers/, config/
    SCHEMA.sql          the whole database
    capacitor/          the Android app (see HANDOVER.md)
    tests/api/          end-to-end API suites, for local development

More detail is in `HANDOVER.md` (running it, and what is and is not done) and
in `PLAN.md` (the decisions and the design).
