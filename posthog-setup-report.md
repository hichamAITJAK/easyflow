# PostHog post-wizard report

The wizard has completed a deep integration of PostHog into the EasyFlow Laravel + Inertia.js (React) project.

**Server-side (PHP):** A dedicated `PostHogService` class was created in `app/Services/PostHogService.php`, initialised once via `config/posthog.php`, and injected into eight PHP files to capture key business events. Users are identified on signup and login using their database ID as the stable `distinct_id`, with email, name, role, and `business_id` passed as person properties.

**Client-side (React):** `posthog-js` is initialised in `resources/js/app.tsx` using `VITE_POSTHOG_KEY` / `VITE_POSTHOG_HOST`. `app-layout.tsx` calls `posthog.identify()` on every authenticated page mount (so sessions resuming mid-session are identified immediately) and `posthog.reset()` on unmount (logout).

| Event | Description | File |
|---|---|---|
| `user_signed_up` | A new user account was created via the registration form. | `app/Actions/Fortify/CreateNewUser.php` |
| `user_logged_in` | A user successfully authenticated and logged into the platform. | `app/Http/Responses/LoginResponse.php` |
| `order_created` | A manual order was created by an agent or admin. | `app/Http/Controllers/Orders/OrderController.php` |
| `order_status_updated` | An order's confirmation status was updated (e.g. confirmed or cancelled). | `app/Http/Controllers/Orders/OrderController.php` |
| `order_shipment_created` | A shipment was registered with a delivery courier for a confirmed order. | `app/Http/Controllers/Orders/OrderController.php` |
| `orders_synced` | Orders were pulled from an ecommerce platform and persisted. | `app/Http/Controllers/Orders/OrderController.php` |
| `store_connected` | An ecommerce store was successfully connected to the platform via OAuth. | `app/Http/Controllers/Stores/ShopifyConnectionController.php` |
| `store_connected` | An ecommerce store was successfully connected to the platform via OAuth. | `app/Http/Controllers/Stores/YouCanConnectionController.php` |
| `store_connected` | An ecommerce store was successfully connected to the platform via OAuth. | `app/Http/Controllers/Stores/LightfunnelsConnectionController.php` |
| `products_synced` | Products were pulled from an ecommerce platform and persisted. | `app/Http/Controllers/Products/ProductController.php` |
| `commission_invoice_generated` | A commission invoice was generated for an agent. | `app/Http/Controllers/Commissions/CommissionEntryController.php` |

## Next steps

We've built some insights and a dashboard to keep an eye on user behaviour, based on the events we just instrumented:

- **Dashboard**: [Analytics basics (wizard)](https://eu.posthog.com/project/232671/dashboard/860584)
- [Signup to first login funnel (wizard)](https://eu.posthog.com/project/232671/insights/g321KY35)
- [Orders created over time (wizard)](https://eu.posthog.com/project/232671/insights/DDSTe99h)
- [Order status updates by status (wizard)](https://eu.posthog.com/project/232671/insights/8CiSMIkJ)
- [Store connections by platform (wizard)](https://eu.posthog.com/project/232671/insights/SfLhdQiL)
- [Shipments created over time (wizard)](https://eu.posthog.com/project/232671/insights/Qaoyjyfs)

## Verify before merging

- [ ] Run a full production build (`npm run build` + `composer install --no-dev`) and fix any lint or type errors introduced by the generated code.
- [ ] Run the test suite — call sites that were rewritten or instrumented may need updated mocks or fixtures.
- [ ] Add the exact PostHog env var names to `.env.example` and any CI/CD secrets so collaborators know what to set: `POSTHOG_PROJECT_TOKEN`, `POSTHOG_HOST`, `POSTHOG_DISABLED`, `VITE_POSTHOG_KEY`, `VITE_POSTHOG_HOST`. (`.env.example` was updated in this run; verify CI has the values.)
- [ ] Confirm the returning-visitor path also calls `identify` — the current implementation identifies on every AppLayout mount, which covers returning sessions. Verify this is working correctly in production by checking the PostHog Live Events feed after a session resume.
- [ ] This project contains a Shopify data source. Run `npx @posthog/wizard warehouse` to connect it to PostHog's data warehouse for richer analysis.

### Agent skill

We've left an agent skill folder in your project at `.claude/skills/integration-laravel/`. You can use this context for further agent development when using Claude Code. This will help ensure the model provides the most up-to-date approaches for integrating PostHog.
