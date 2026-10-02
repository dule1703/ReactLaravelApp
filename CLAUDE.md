# Škoda konfigurator (ReactLaravelApp)

Demo web aplikacija za Škoda dilera u Srbiji: klijenti se registruju i prave ponude kroz
konfigurator; admin upravlja klijentima, ponudama, katalogom i cenama. Demo projekat koji se
piše kao produkcijski. Pravi se od nule; stara aplikacija "Digitalna kancelarija" je samo
funkcionalni uzor.

## Jezik

- Sa mnom i u izveštajima: srpski (latinica), kratko i konkretno.
- Kod, komentari u kodu, commit poruke i naslovi PR-ova: engleski.
- Dokumentacija u repou (CLAUDE.md, ROADMAP.md): srpski (latinica).
- Objasni "zašto", ne samo "kako". Ako podatak nedostaje ili postoji više rešenja, pitaj.

## Stack

Laravel 12 (PHP 8.2+), Inertia 2, React 18, Breeze, Sanctum, Ziggy (`@routes` + globalni
`route()`), Tailwind 3, Vite 7. MySQL na serveru, SQLite u testovima.

## Komande

- Setup: `composer run setup`
- Dev (Windows): `php artisan serve` i `npm run dev` u dva terminala
  (`composer run dev` pada na Windowsu jer `artisan pail` traži `pcntl`)
- PHP testovi: `composer test` · JS testovi: `npm run test` (vitest)
- Format: `./vendor/bin/pint` · Build: `npm run build`

## Pravila

- Autorizacija preko Policy-ja (admin vs klijent), validacija preko Form Request-a.
- Svaka promena šeme je migracija, unazad kompatibilna (expand/contract): rollback samo
  pomera symlink, baza se NE vraća.
- Novac: celobrojni iznosi u centima, nikad float. Stavke ponude čuvaju snimak cena.
- Svaka funkcionalnost ide sa PHPUnit Feature testom. Kalkulacije cena imaju i vitest testove;
  PHP i JS implementacija moraju davati identičan rezultat.
- Seederi za demo podatke. Podaci su lažni: repo je JAVAN.
- NIKAD ne commituj niti ispisuj tajne (.env, ključeve, lozinke, tokene).
- Testove uvek proveri i sa `CI=true` (CI okruženje se ponaša drugačije od lokalnog).
- Frontend: funkcionalne komponente + hooks, Tailwind, Ziggy `route()`.
- UI tekstovi samo kroz `t()` (`resources/js/lib/i18n.js`) i `lang/sr_Latn.json`; boje samo preko tokena iz `tailwind.config.js`.

## Shared hosting (cPanel)

Nema Node-a ni supervisora na serveru, nema headless Chrome-a (PDF preko dompdf). Red čekanja:
`database` drajver + cron. Build se radi u GitHub Actions i šalje se na server preko SSH.

## Git tok rada (obavezno, za SVAKU podfazu)

1. `git checkout develop && git pull`, pa nova grana `feature/<podfaza>-<opis>`
   (npr. `feature/1-1-user-roles`). Jedna podfaza = jedna grana = jedan PR.
2. Radi u malim koracima. Pre koda pokaži kratak plan i sačekaj potvrdu.
3. Pre commita: `composer test`, `npm run build` (i `npm run test` kad postoji), `pint`.
4. Commit (Conventional Commits: feat, fix, chore, refactor, test, ci, docs) i
   `git push -u origin <grana>`.
5. Napravi Pull request prema `develop` (`gh pr create`). Naslov na engleskom; opis na
   srpskom: šta je urađeno, zašto, kako da proverim.
6. **Merge radim ja.** Nikad ne radi merge, ne pushuj direktno na `develop` ni `main`, nikad
   force push.
7. Kad javim "merge-ovano": uvek prvo `git checkout develop` + `git pull`, obriši lokalnu i
   udaljenu feature granu, `git fetch --prune`, pa tek onda nova grana. Sledeću podfazu
   počni tek posle toga.

## Deploy (pitaj me pre izmene)

`.github/workflows/*.yml` i `deploy/*.sh` upravljaju produkcijom: predloži izmenu i sačekaj
potvrdu. Release model: `releases/<timestamp>`, deljeni `storage` + `.env`, atomski symlink
`current`. Putanje: `~/projects/react-laravel-app/deploy/{production,staging}/`.

## Održavanje ovog fajla i ROADMAP-a

Posle svake podfaze sam ažuriraš CLAUDE.md (nova pravila, komande, odluke, otkrivena
ograničenja) i `docs/ROADMAP.md` (štikliraj završenu podfazu, ažuriraj otvorene odluke) samo
ako ima šta da se promeni. Izmene idu u ISTI commit kao i sama podfaza, ne u poseban.
Drži oba fajla kratkim: oni se učitavaju u svaku sesiju.

## Izveštaj posle podfaze (kratko)

Urađeno · Kako proveriti · Link ka PR-u · Predlog sledeće podfaze.
Plan i izveštaj: najviše ~20 linija, bez prepričavanja nepromenjenog stanja.
