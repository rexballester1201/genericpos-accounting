# GenericPOS — handover

Where things stand, what to do before opening, and what to know before
changing the code. `README.md` covers installation. `PLAN.md` records the
decisions, the design and the build checklist.

## Status — 2026-09-13

| Area | State |
|---|---|
| Storefront, cart, checkout, orders, customer accounts | done |
| Payments: cash, store credit, cash on delivery, pay on pickup, manual with a reference, USDT per order, USDT top-up | done |
| POS: pairing, PINs, shifts, sales, tenders, receipts, voids, refunds, approvals, offline sales | done |
| Back office: catalogue, stock, orders, customers, staff, registers, shipping, promotions, reports, settings, USDT, inbox, audit | done |
| PWA: offline shell, web push, SEO tags, sitemap | done |
| Android app (Capacitor) | rebranded and scripted, but no APK built yet |

The API suites in `tests/api` run 509 checks, and all of them passed on this
date. The storefront, the USDT payment panel, the unpaired till and a digital
receipt were also checked in a browser.

### Not done, or needs a person

- **Click through the signed-in screens.** The POS after sign-in and every
  back-office screen were tested through their APIs, but nobody has clicked
  through them in a browser. Pairing a till needs a manager's password typed
  on that device. Walk through both with a real manager account before
  opening.
- **The store never sends USDT.** Customers cannot cash out store credit,
  and a USDT order is refunded to store credit, in cash at a register, or by
  hand.
- **The APK is not built.** It needs Node, `npm install` in `capacitor/`, and
  the Android SDK. See *Android app* below.
- **The app has no native push.** Web push works in browsers. The Android app
  would need `@capacitor/push-notifications` and a Firebase project;
  `WebPush_lib` already has an FCM path.
- **Receipts and the BIR.** In the Philippines, issuing receipts from a POS
  needs BIR registration of the software and the machine (a Permit to Use).
  This build prints a configurable disclaimer, but it has not been through
  accreditation. Check what the store's registration requires.

## Before opening

1. `secrets.php` exists on the server only. It holds `GP_JWT_SECRET`,
   `GP_ENCRYPTION_KEY`, the database and the SMTP password. `GP_SETUP_KEY` is
   cleared once `/setup` has run.
2. `config.php` lists the production host in `$_gp_origins` and in the
   fallback. Password-reset and verification links are built from it.
3. The site is served over HTTPS. Then consider HSTS: `.htaccess` has a
   commented-out line, so start with a small max-age.
4. Cron runs `chaincli cron` every five minutes and mails a real address.
   `chaincli health` is added to whatever monitoring exists.
5. Settings are filled in:
   - store identity, TIN, receipt text and tax;
   - payment methods and shipping zones;
   - `unpaid_order_cancel_hours`, which is how long an unpaid online order
     keeps its stock.
6. If USDT will be used:
   - Set the rate, and add pool addresses whose keys you control and keep offline.
   - Check `chains.php` (BSC mainnet, 15 confirmations) and `CHAIN_RPC_URL`.
   - Run `chaincli verifyfilter` and `chaincli status`.
7. Staff accounts exist (*Back office → Staff*) and have PINs. Each register
   device is paired: a manager signs in on it once.
8. The demo catalogue is gone if `tools seed_demo` was used, and no
   `example.test` account exists.
9. The test email from Settings arrives.
10. The database is backed up daily. The stock ledger and the order history
    are the store's books.

## Running the store

- **Cron** closes USDT payment windows, cancels online orders left unpaid,
  scans for USDT while the address pool is in use, and does housekeeping. It
  is silent when all is well.
- **The USDT rail's health** shows on the dashboard's deposit-rail card, and
  `php index.php chaincli health` reports it from a shell.
- **Parked transfers.** A USDT transfer that could not be matched lands in
  *Back office → USDT*. That happens when it reached an address nobody holds,
  arrived late, or matched no payment. Assign it to a customer (as store
  credit) or to an order's payment.
- **Registers.** Sales made offline are queued on the device and sync by
  themselves. Receipt numbers never have gaps: each is taken inside its
  sale's transaction. A shift closes with a counted drawer, and the Z report
  shows the variance.
- **Orders.** *Back office → Orders* puts the work in queues: to fulfil,
  transfers to confirm, unpaid, and today. An order holding money cannot be
  cancelled until it is refunded.

## Customising

- **Look and words.** *Settings* holds the store's name, logo, banner,
  colours, corner radius, font and theme, the content pages (about, shipping,
  returns, terms, privacy), and the receipt text.
- **Features.** *Settings* also switches features. For example, turn the
  online shop off for a till-only store, or turn off guest checkout, pickup,
  delivery, top-up, the POS, or web push.
- **Icons.** Run `node capacitor/scripts/make-icons.mjs --color "#rrggbb"` to
  redraw every icon and splash screen in the store's colour. Or replace
  `icons/*.png` with the store's own art, keeping the same names and sizes.
- **What is editable where.** Anything `app.php` marks as admin-editable
  appears in *Settings*. Everything else in `app.php` is code configuration,
  so review a change to it like any other code change. Chain facts
  (`chains.php`) are never settings.

## Android app

1. **Change the package name.** It is `com.genericpos.app`, and it becomes
   permanent with the first Google Play upload, so change it to a reverse
   domain the store owns. It appears in four places:
   - `appId` in `capacitor.config.json`;
   - `namespace` and `applicationId` in `android/app/build.gradle`;
   - `res/values/strings.xml`;
   - `MainActivity`'s package and folder.
2. Run `npm install` in `capacitor/`.
3. Build the web assets with the server address, then the debug APK:
   - PowerShell: `$env:GP_SERVER_URL = 'https://your-shop'; npm run sync; npm run apk:debug`
   - or `npm run open:android` to work in Android Studio.

   `build-www.mjs` copies an allowlist of files, never the whole project, and
   refuses to build if a secret or an unfilled placeholder would ship.
4. **Release.** Keep the keystore outside the repository. Create
   `android/keystore.properties` from the example, then run
   `npm run aab:release`. Back the keystore up in two places: losing it means
   the app can never be updated again.
5. **Over-the-air updates.** Bump `version` in `package.json` and run
   `npm run bundle` (with `GP_SERVER_URL` set). Upload
   `updates/bundles/genericpos-x.y.z.zip` and `updates/manifest.json` to the
   server's `updates/` folder. The app checks `/api/v1/app/updates` and reports
   ready after its first page; a bundle that never reports is rolled back.

## Working on the code

- **Money** is an integer of minor units. USDT amounts use bcmath on
  strings. `Pricing_lib` and `js/pricing.js` must stay identical; `t_pricing`
  checks them.
- **Stock** changes only through `Inventory_model::move()`, which writes the
  ledger. `stock_qty` is a cache of it.
- **Idempotency.** Every call that creates an order carries an idempotency
  key.
- **Routes and guards.** `routes.php` lists every endpoint explicitly. Each
  controller method starts with its own guard: `auth_check`, `manager_check`,
  `admin_check` or `pos_check`.
- **Front end.** Bump `CACHE_VERSION` in `sw.js` with any change to
  `index.html`, `css/`, `js/` or `pages/`, or visitors keep the old code.
- **The shell** (`serve_spa_shell`) never needs the database. The one
  exception is the SEO lookup for product and category links, which falls
  back when the database is down.
- **Time.** The database stores UTC. Convert a store-local day with
  `store_day_utc()`.
- **The chain config points at BSC MAINNET.** On a development machine:
  - never run `chaincli cron` or `chaincli scan` while pool addresses are active;
  - never use "check payment" or "scan now" against test data;
  - simulate transfers with `php index.php tools usdt_simulate` instead.
- **Tests** live in `tests/api`; its README explains them.

## Where things are

    application/controllers/        public and customer API: Auth, Setup, Store, Catalog, Checkout, Orders,
                                    Payments, Profile, Addresses, Wallet, Deposit (top-up), Support, Push, Updates
    application/controllers/Pos.php the till's API
    application/controllers/admin/  Dashboard, Products, Categories, Inventory, Orders, Customers, Staff,
                                    Registers, Shipping, Promotions, Reports, Settings, Usdt, Inbox
    application/controllers/        command line: Chaincli (cron and the rail), Tools, Maildiag
    application/models/             Order, Payment, Sale (POS), Refund, Pos, Product, Category, Inventory,
                                    Wallet, Deposit, Shipping, Coupon, Address, User, Settings, Store,
                                    Notification, OrderNotify, Inquiry, Push
    application/libraries/          Pricing_lib, DepositWatcher_lib and the chain clients, QRCode_lib,
                                    Imaging_lib, Mailer_lib, WebPush_lib, Jwt_lib
    application/config/app.php      every setting and its default, by section
    js/ + pages/                    one module and one fragment per screen (pos*.js for the till,
                                    admin-*.js for the back office)
