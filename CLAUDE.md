# Skoda Configurator (ReactLaravelApp)

Demo web app for a Skoda dealer in Serbia: clients register, build car offers with a
configurator, admin manages clients, offers, catalog and prices. Demo project, but written
as production code. Built from scratch; the old "Digitalna kancelarija" app is only a
functional reference.

## Language

- Talk to me in Serbian (Latin script).
- Code, code comments, commit messages and docs in the repo: English.
- Explain the "why", not only the "how". I am learning on a real project.
- If data is missing or there are several valid solutions, ask before assuming.

## Stack

Laravel 12 (PHP 8.2+), Inertia 2, React 18, Breeze, Sanctum, Ziggy (`@routes` + global
`route()`), Tailwind 3, Vite 7. MySQL on the server, SQLite for tests.

## Commands

- Setup: `composer run setup`
- Dev (Windows): `php artisan serve` and `npm run dev` in two terminals
  (`composer run dev` fails on Windows because `artisan pail` needs `pcntl`)
- PHP tests: `composer test`
- JS tests: `npm run test` (vitest, to be added in Phase 0.6)
- Format PHP: `./vendor/bin/pint`
- Build: `npm run build`

## Rules

- Authorization with Policies (admin vs client). Validation with Form Requests.
- Every schema change is a migration. Migrations must be backward compatible
  (expand/contract): rollback only moves a symlink, the database is NOT rolled back.
- Money: integer amounts in cents, never float. Offer items store a snapshot of prices.
- Every feature ships with a PHPUnit Feature test. Price calculation logic also gets
  vitest tests, and the PHP and JS implementations must give identical results.
- Seeders for demo data. Demo data is fake: this repo is PUBLIC.
- NEVER commit secrets (.env, keys, passwords, tokens) and never print them.
- Frontend: functional components + hooks, Tailwind, Ziggy `route()` for URLs.

## Shared hosting limits (cPanel)

No Node and no supervisor on the server. No headless Chrome, so PDF uses dompdf.
Queue uses the `database` driver, run by cron. The build happens in GitHub Actions and the
finished build is uploaded over SSH.

## Git workflow

`feature/*` or `fix/*` -> PR into `develop` (staging deploy) -> PR into `main` (production
deploy). Conventional Commits: feat, fix, chore, refactor, test, ci, docs.
Never push directly to `main`. Never force push.

## Deploy (ask me before touching)

`.github/workflows/*.yml` and `deploy/*.sh` control production. Propose changes and wait for
my confirmation before editing them. Release model: `releases/<timestamp>`, shared
`storage` + `.env`, atomic `current` symlink. Server paths:
`~/projects/react-laravel-app/deploy/{production,staging}/`.

## Working method

Work follows `docs/ROADMAP.md`. One sub-phase at a time, small verifiable steps.
After each step tell me exactly how to verify it works. Do not start the next
sub-phase until I confirm. Before writing code for a sub-phase, show a short plan.
