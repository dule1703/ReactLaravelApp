# Roadmap

Rule: one sub-phase at a time. A sub-phase is DONE only when its tests pass, the manual
check works, and it is merged into `develop` and deployed to staging.

Legend: `[x]` done, `[ ]` to do.

## Phase 0 - Foundation and pipeline

- [x] 0.1 Fresh Laravel 12 + React + Inertia install, repo on GitHub with `main` and `develop`
- [ ] 0.2 Run locally (`composer run setup`, `php artisan serve` + `npm run dev`)
- [ ] 0.3 GitHub: rulesets for `main` and `develop`, squash merge, auto-delete branches
- [ ] 0.4 Deploy SSH key pair + all 9 repository secrets
- [ ] 0.5 Staging server prep: `shared/.env`, MySQL database, PHP >= 8.2 for the domain
- [ ] 0.6 vitest + first test (`npm install -D vitest`, commit `package-lock.json` too)
- [ ] 0.7 CI/CD files via PR into `develop` -> CI green -> first staging deploy
- [ ] 0.8 Test the Rollback workflow on staging
- [ ] 0.9 Production server prep, PR `develop` -> `main`, first production deploy
- [ ] 0.10 Cron jobs: `schedule:run` every minute, `queue:work --stop-when-empty`

## Phase 1 - Auth and roles

- [ ] 1.1 Expand migration: `role` on users (admin/client), admin seeder
- [ ] 1.2 Auth screens: login, register (name, email, password), password reset by email
- [ ] 1.3 Serbian UI texts; guest layout with Skoda-inspired design tokens (Tailwind)
- [ ] 1.4 Role middleware + Policy skeleton, Feature tests for access rules
- [ ] 1.5 Access log: record every client login (admin can see when each client visited)
- [ ] 1.6 App shell: navbar (Home, Clients, Offers), logout, flash messages

## Phase 2 - Client profiles

- [ ] 2.1 Client profile migration: name/company, JMBG (13 digits + checksum), PIB (9 digits),
      address, postal code (5 digits), city, country
- [ ] 2.2 Form Request validation + Policy (client: own profile only, admin: all) + tests
- [ ] 2.3 Client edits own profile
- [ ] 2.4 Admin: clients table with search, per-page selector, pagination, edit, delete

## Phase 3 - Catalog (admin)

- [ ] 3.1 Schema: models, equipment packages, engines, transmissions, versions
      (version = package + engine + transmission + base price in cents)
- [ ] 3.2 Equipment per package: standard / optional (with price) / unavailable
- [ ] 3.3 Seeders: 3-4 models with realistic packages, engines and equipment
- [ ] 3.4 Admin dashboard: edit all prices (single and bulk), with tests
- [ ] 3.5 Admin CRUD for models, packages, engines, versions and equipment

## Phase 4 - Offers and configurator

- [ ] 4.1 Schema: offers + offer items with price snapshot
- [ ] 4.2 Offer number NNN/YYYY, resets every year, safe under concurrent requests
- [ ] 4.3 VAT rate as an admin setting (default decided in D3)
- [ ] 4.4 Price calculation: PHP service + JS util with identical results (PHPUnit + vitest)
- [ ] 4.5 Configurator UI: model -> package -> engine, standard/optional equipment, number of cars,
      "save model" adds an item, live totals without and with VAT
- [ ] 4.6 Offers list: search, per-page, pagination, edit, delete
- [ ] 4.7 Policy: client sees only own offers, admin sees all; Feature tests

## Phase 5 - PDF and print

- [ ] 5.1 dompdf + Blade template with a font that supports Serbian letters
- [ ] 5.2 PDF download and print view for an offer, authorization tests
- [ ] 5.3 PDF layout polish (header, items table, totals, notes)

## Phase 6 - Admin dashboard and polish

- [ ] 6.1 Dashboard: counts of clients/offers, recent activity, quick links to price editing
- [ ] 6.2 Empty states, loading states, validation messages, responsive layout
- [ ] 6.3 Security pass: rate limiting, policy coverage review, no sensitive data in logs

## Phase 7 - Release hardening

- [ ] 7.1 README with setup, deploy and rollback instructions
- [ ] 7.2 Database backup routine on the server
- [ ] 7.3 Final regression: tests green, staging walkthrough, production release

## Open decisions

- D1: Are "clients" separate from user accounts? (admin creates clients without logins, as in
  the old app, or is every client a registered user?)
- D2: JMBG is sensitive. Encrypt at rest (Laravel `encrypted` cast) or keep it searchable?
  Encryption makes searching by JMBG impossible.
- D3: Default VAT rate. The old app used 18%; the general rate in Serbia is 20%.
- D4: Is the UI Serbian only, or also English?
