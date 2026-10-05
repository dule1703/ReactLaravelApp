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
- Rute po ulozi: `->middleware(['auth', 'role:admin'])` (`auth` uvek prvi); pristup tuđim podacima samo kroz Policy.
- Svaka funkcionalnost koja upisuje podatke mora beležiti aktivnost: modeli preko traita `LogsActivity`, ostalo preko `ActivityLogger`. Osetljiva polja dodaj u `config/activity-log.php` (u log ide samo naziv polja, nikad vrednost). Log je samo za dopisivanje.
- Svaka promena šeme je migracija, unazad kompatibilna (expand/contract): rollback samo
  pomera symlink, baza se NE vraća.
- Novac: celobrojni iznosi u centima, nikad float. Stavke ponude čuvaju snimak cena.
- Katalog: cene su NETO (bez PDV-a); katalog se ne briše nego deaktivira (`is_active`, FK restrict); šta se sme ponuditi odlučuje samo `Version::available()`.
  Cena opreme paketa (`TrimEquipment`): standard => bez cene, optional => cena >= 0; menja se samo kroz model (ne `attach`/`sync`/query builder), da pravilo i dnevnik važe. Nazivi polja u dnevniku: pravilo samo u `resources/js/lib/activity.js`.
  Seederi kataloga: samo `firstOrCreate` po prirodnom ključu (nikad `updateOrCreate` nad cenom/`is_active`), kroz modele; masovni upis u konzoli kroz `ActivityLogger::withoutLogging()` + jedan zbirni zapis.
  Katalog admin (3.5+): red se ne briše dok ima zavisnih redova (provera brojeva pre brisanja, `QueryException` kao rezerva), nego se deaktivira; "šta je dostupno" računa samo `Version::available()` (i za brojače u UI); unikatnost naziva bez razlike u velikim/malim slovima ide kroz `lower()` u bazi (isto na MySQL i SQLite); LIKE pretraga kroz `Support\Like` + `escape '!'`.
  Zaštićen sadržaj: ništa se ne skrejpuje niti preuzima sa skoda-auto sajtova (podaci i slike su zaštićeni, repo je JAVAN); u repo idu samo naši originalni materijali (bez Škoda logotipa, slika i zaštićenih oznaka). Realne podatke dostavlja vlasnik (3.7). Slike modela se otpremaju isključivo kroz admin (disk `public`, `catalog/models/<nasumično>.<ext>`, ekstenzija iz stvarno prepoznatog MIME tipa, nikad iz imena klijenta; jpg/jpeg/png/webp, max 2 MB, 400x250 do 4000x4000) i žive u `storage`, nikad u gitu. Stari fajl se briše tek posle commit-a.
  Kategorije modela su many-to-many (`car_model_category`); veza se menja SAMO kroz `CarModelCategories::sync()` (`sync()` na relaciji zaobilazi model događaje, servis piše jedan zapis `car_model.categories_changed`).
  Realni katalog: podaci dolaze iz `database/seeders/data/real_catalog.php` (ručno unosi vlasnik); cene u fajlu su BRUTO u centima, neto izvodi `RealCatalogSeeder` (`Vat::netFromGross`, stopa iz `meta` fajla, ne iz podešavanja); fajl se proverava sa `php artisan catalog:validate-real` (isti test u CI). `firstOrCreate` po prirodnom ključu: obrisan ili preimenovan red se VRAĆA pri ponovnom seedu, pogrešan podatak se ispravlja kroz admin, ne ponovnim seedom (redovi se ne brišu nego deaktiviraju). `catalog:purge-demo` je jednokratna, traži `CATALOG_PURGE=demo|all` (podrazumevano `off`, nikad na produkciji) i `--confirm`, odbija uz ponude i uz marker `catalog_real_seeded_at`.
  Grupe opcija (boje, točkovi, enterijer = „jedno od više“): `option_groups` + `equipment_items.group_id`; stavka bez grupe je nezavisna dodatna oprema. Za grupu `single` svaka linija (trim) koja je nudi ima TAČNO JEDNU standardnu stavku (bez cene), a ostale su dodatne sa cenom >= 0 koja je DOPLATA u odnosu na standardnu stavku grupe, NE ukupna cena (faza 4 mora da je doda na cenu linije, nikad da njome zameni cenu). Dodatna oprema mora biti kompletna po liniji (klijent bira po ukusu). „Tačno jedna“ proverava `OptionGroupRule` nad konačnim stanjem (validacija seed fajla, servis matrice 3.11); hook `TrimEquipment` samo „najviše jedna“. Stavke jedne grupe na liniji se NE menjaju direktno kroz model jednu po jednu: menja ih samo servis matrice (3.11), u jednoj transakciji, uz proveru konačnog stanja. Kategorija stavke mora biti ista kao kategorija grupe; `swatch_hex` (#RRGGBB) samo za stavke grupe sa `uses_swatch`. Slika stavke: isto kao slika modela (`CatalogImages`, `EquipmentItemImages`, `ImageRules`). Pravila zavisnosti i paketi opreme nisu u prvoj verziji.
  Privatni fajl realnog kataloga: repo je JAVAN, a izvor podataka dozvoljava samo ličnu nekomercijalnu upotrebu, pa realni podaci NIKAD ne idu u git. Fajl je u `database/seeders/data/private/real_catalog.php` (ignorisano) ili van repoa (server: `deploy/<env>/shared/real_catalog.php`), bira se sa `CATALOG_REAL_PATH`; `database/seeders/data/real_catalog.php` u repou MORA ostati prazan okvir (test), a `RealCatalogSeeder` odbija nepraznu putanju unutar repoa van `private/`. Uputstvo: `docs/real-catalog.md`. Prikazivanje realnih podataka trećim licima traži pismeno odobrenje izdavača.
  PDV i novac: samo kroz `App/Support/Vat` / `Money` i `resources/js/lib/vat.js` / `money.js` (zajednički fixture-i u `tests/fixtures`, PHP i JS moraju davati isto); iznose i procente parsirati kao string, bez float-a. Server sam izvodi neto iz unosa i nikad ne veruje klijentskom neto; stopa i cene se snimaju u ponudu pri kreiranju (4.x).
- Svaka funkcionalnost ide sa PHPUnit Feature testom. Kalkulacije cena imaju i vitest testove;
  PHP i JS implementacija moraju davati identičan rezultat.
- Seederi za demo podatke. Podaci su lažni: repo je JAVAN.
- NIKAD ne commituj niti ispisuj tajne (.env, ključeve, lozinke, tokene).
- JMBG: `encrypted` cast + `jmbg_hash` (HMAC-SHA256 nad normalizovanom vrednošću, ključ `JMBG_HASH_KEY`,
  min 32 znaka, fail-fast ako fali). `APP_KEY` i `JMBG_HASH_KEY` se NE menjaju bez plana (šifrovani JMBG
  i hash postaju neupotrebljivi). `JMBG_HASH_KEY` mora biti u `.env` na svakom serveru pre deploy-a.
  JMBG se menja samo kroz model (ne query builder), da hash ostane usklađen. Ne vraćati ga preko
  `toArray()`/Inertia propsa; vlasniku ga vraća eksplicitno 2.3. Seederi ne koriste `WithoutModelEvents`.
  Profil klijenta se uzima kroz `User::profile()` (firstOrCreate; admin nema profil). Dozvoljene države: `config/countries.php`, nazivi `country.<ISO>` u `lang/sr_Latn.json`.
  Pun JMBG/PIB samo kroz admin `clients.reveal` (POST, JSON, `no-store`, throttle, zapis `client_profile.sensitive_viewed` bez vrednosti); admin ne briše sebe ni druge admine.
  Forma profila nikad ne prikazuje JMBG kao `value`: samo maska (`ClientProfile::maskedJmbg()`) kao tekst uz polje; prazan unos znači „ne menjaj“.
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

Urađeno · Kako proveriti · Gde se vidi (za svaki deo podfaze: URL u aplikaciji, artisan/tinker komanda ili tabela u bazi i šta tamo treba da se vidi; ako nema UI, napisati to i kako se proverava inače) · Link ka PR-u · Predlog sledeće podfaze.
Plan i izveštaj: najviše ~20 linija, bez prepričavanja nepromenjenog stanja.
