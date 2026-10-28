# InvoiceShelf

Laravel 11 API (`routes/api.php`, prefix `/api/v1`) with a Vue 3 single-page frontend in `resources/scripts`. Follow the conventions below; new code should look like the code next to it.

## Backend

- **Controllers** live in `app/Http/Controllers/V1/<Area>/<Domain>/` (areas: `Admin`, `Customer`, `PDF`, ...) and extend `App\Http\Controllers\Controller`. A single action is an invokable controller named after the action, e.g. `Invoice/CloneInvoiceController`.
- **Authorize every action** with the model policy: `$this->authorize(view, $invoice)`, `$this->authorize(create, Invoice::class)`. Policies live in `app/Policies` and check Bouncer abilities.
- **Validate with a FormRequest** in `app/Http/Requests`. When the request builds the data to save, add a `get<Model>Payload()` method like the existing requests.
- **Return API Resources** from `app/Http/Resources` (`new InvoiceResource($invoice)`, `InvoiceResource::collection(...)`), never raw models or arrays.
- **Business logic belongs on the model**, like `Invoice::createInvoice($request)` and `$invoice->send(...)`. Controllers stay thin and never use the `DB` facade.
- **Everything is scoped to a company.** The current company comes from the `company` request header; query with the `whereCompany()` scope and set `company_id` on new records.
- **List endpoints** use the model scopes `applyFilters($request->all())` and `paginateData($limit)`.
- **Money is stored as integer cents** (`unsignedBigInteger`). Never store or calculate amounts as floats.
- **Mail** goes through a `Mailable` in `app/Mail`; reuse the existing invoice mail setup. Long-running work goes in a queued job in `app/Jobs`.
- **Config, not env:** read settings with `config()`; `env()` belongs in `config/*.php` only. Company-level settings use `CompanySetting::getSetting($key, $companyId)`.
- **Secrets** such as API keys are read from config backed by `.env`. Only add a placeholder to `.env.example`; never commit a real key.
- **Migrations** are new files; never edit an existing migration.

## Frontend

- Admin pages live in `resources/scripts/admin/views/<domain>/`, routes in `resources/scripts/admin/admin-router.js`, and API calls go through the Pinia stores in `resources/scripts/admin/stores/`.
- Reuse the existing `Base*` components (`BaseInputGroup`, `BaseMoney`, `BaseButton`, ...) instead of plain HTML elements.
- All user-facing text goes through `$t(...)` with the keys added to `lang/en.json`.
- Run `npm run test` (ESLint) on changed frontend code.

## Tests

- Pest feature tests in `tests/Feature/Admin/<Domain>Test.php`. Follow the existing setup: seed `DatabaseSeeder` and `DemoSeeder`, `Sanctum::actingAs(User::find(1), [*])` and send the `company` header.
- Use model factories, and fake external services (`Http::fake()`, `Mail::fake()`, `Queue::fake()`); tests never call the network.
- `tests/ArchTest.php` enforces the structural rules above. Do not add exceptions to it for new code.
