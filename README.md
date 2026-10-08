# Škoda konfigurator (demo)

> **Napomena o sadržaju.** Ovaj projekat je demo aplikacija i nije povezan sa Škoda Auto a.s. niti sa njenim
> zastupnicima. Nazivi modela, paketa i motora u demo podacima služe samo kao ilustracija, a cene su
> izmišljene i neslužbene. Logotipi, fotografije i zvanični cenovnici nisu deo repozitorijuma. Realni podaci se
> ne čuvaju u gitu (vidi [docs/real-catalog.md](docs/real-catalog.md)).

Veb aplikacija za dilera automobila: klijenti se registruju i prave ponude kroz konfigurator, a admin upravlja
klijentima, ponudama, katalogom i cenama. Piše se kao produkcijska aplikacija, ali je demo projekat.

Komande i nazivi fajlova su na engleskom, tekst dokumentacije na srpskom (latinica). Repozitorijum je **javan**:
u njega nikad ne idu tajne, `.env`, ključevi ni realni podaci kataloga.

## Sadržaj

- [Šta je aplikacija](#šta-je-aplikacija)
- [Stack i verzije](#stack-i-verzije)
- [Lokalno pokretanje](#lokalno-pokretanje)
- [Testovi i kvalitet](#testovi-i-kvalitet)
- [Git tok](#git-tok)
- [CI i CD (GitHub Actions)](#ci-i-cd-github-actions)
- [Server (cPanel shared hosting)](#server-cpanel-shared-hosting)
- [Deploy](#deploy)
- [Rollback](#rollback)
- [Backup i oporavak](#backup-i-oporavak)
- [Provera pred izlazak](#provera-pred-izlazak)
- [Tajne i ključevi](#tajne-i-ključevi)
- [Rešavanje problema](#rešavanje-problema)
- [Bezbednost u kratkim crtama](#bezbednost-u-kratkim-crtama)
- [Licenca](#licenca)

## Šta je aplikacija

Dve uloge:

- **Klijent** se registruje, dopunjuje profil, kroz konfigurator bira model, verziju i opremu, snima ponudu,
  vidi svoje ponude, menja im napomenu, povlači ih i otvara PDF.
- **Admin** upravlja klijentima (i pravi ih), katalogom (modeli, linije, motori, menjači, verzije, oprema,
  grupe opcija, matrica opreme), cenama i PDV-om, podacima izdavaoca ponude i pregleda dnevnik aktivnosti.
  Admin može da napravi ponudu na ime klijenta.

Dalje čitanje:

- [docs/ROADMAP.md](docs/ROADMAP.md): istorija i stanje po fazama (šta je gotovo, šta je na redu).
- [CLAUDE.md](CLAUDE.md): pravila arhitekture i bezbednosti (novac u centima, katalog, ponude, dnevnik, git tok).
- [docs/real-catalog.md](docs/real-catalog.md): kako se učitavaju realni podaci kataloga (privatni fajl).

## Stack i verzije

Verzije su iz `composer.json` i `package.json`.

| Deo | Verzija |
| --- | --- |
| PHP | `^8.2` (potreban `ext-gd`); CI i deploy build koriste 8.4 |
| Laravel | `^12.0` (Breeze, Sanctum) |
| Inertia | `inertiajs/inertia-laravel ^2.0`, `@inertiajs/react ^2.0` |
| Ziggy | `tightenco/ziggy ^2.0` (`route()` u JavaScript-u) |
| PDF | `dompdf/dompdf ^3.1` |
| React | `^18.2` |
| Tailwind CSS | `^3.2` (+ `@tailwindcss/forms`) |
| Vite | `^7.0` (+ `laravel-vite-plugin ^2.0`) |
| Testovi | PHPUnit `^11.5`, paratest `^7.8`, vitest `^5.0` |
| Format | Laravel Pint `^1.24` |
| Baza | MySQL na serveru, SQLite lokalno i u testovima |
| Node | 22 (CI) |

## Lokalno pokretanje

Preduslovi: PHP 8.2+ sa ekstenzijama `gd`, `mbstring`, `openssl`, `pdo_sqlite` (i `pdo_mysql` ako koristite MySQL),
Composer i Node.js 22 sa npm-om.

```bash
composer run setup
```

Skript `setup` (iz `composer.json`) radi: `composer install`, kopira `.env.example` u `.env` ako ga nema,
`php artisan key:generate`, `php artisan migrate --force` (pravi SQLite fajl ako ne postoji), `npm install`,
`npm run build`. On **ne pravi** `JMBG_HASH_KEY` i **ne pokreće** seedere, pa su potrebna dva ručna koraka.

**1. `JMBG_HASH_KEY`** je HMAC ključ za pretragu i jedinstvenost JMBG-a. Mora imati najmanje 32 znaka, a bez njega
aplikacija odbija da sačuva JMBG (greška `JMBG_HASH_KEY must be set to at least 32 characters`). Generiše se
komandom iz komentara u `.env.example`, pa se rezultat upisuje u `.env`:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

```dotenv
JMBG_HASH_KEY=<ispis komande>
```

**2. Demo admin.** `AdminUserSeeder` pravi admina (`admin@example.com`) samo ako je u `.env` postavljen
`SEED_ADMIN_PASSWORD`; lozinku birate sami i ne upisuje se nigde u repozitorijum.

Zatim seederi:

```bash
php artisan db:seed
```

`DatabaseSeeder` redom poziva:

1. `AdminUserSeeder`: admin (preskače se bez `SEED_ADMIN_PASSWORD`).
2. Dva demo klijenta (`client@example.com`, `firma@example.com`) preko `UserFactory`; njihova lozinka je podrazumevana
   lozinka fabrike (vidi `database/factories/UserFactory.php`). **Samo lokalno i na stagingu, nikad na produkciji.**
3. `ClientProfileSeeder`: izmišljeni podaci profila demo klijenata.
4. `IssuerProfileSeeder`: izmišljeni izdavalac ponude, **samo kad je `APP_ENV` `local` ili `staging`**.
5. `RealCatalogSeeder`: učitava realni katalog iz privatnog fajla; sa praznim okvirom iz repoa ne radi ništa.

Demo katalog (izmišljeni modeli, cene i kategorije) nije u `DatabaseSeeder`; pokreće se posebno, tim redom:

```bash
php artisan db:seed --class=CatalogSeeder
php artisan db:seed --class=CategorySeeder
```

Pokretanje (dva terminala):

```bash
php artisan serve
npm run dev
```

`composer run dev` na Windowsu pada (`php artisan pail` traži `pcntl`), zato se koriste dve komande gore.

Lokalni mejlovi idu u log (`MAIL_MAILER=log`), a red čekanja je u bazi (`QUEUE_CONNECTION=database`).

## Testovi i kvalitet

| Komanda | Šta radi |
| --- | --- |
| `composer test` | Svi PHP testovi sekvencijalno (to pokreće CI) |
| `composer test:parallel` | Isti PHP testovi paralelno (paratest), mnogo brže |
| `npm run test` | JavaScript testovi (vitest) |
| `npm run build` | Produkcijski build (Vite) |
| `./vendor/bin/pint --test` | Provera formata PHP koda (bez `--test` formatira) |

Napomene:

- Tokom rada pokrećite samo pogođene testove (`php artisan test putanja/Fajl.php` ili `--filter`), a ceo skup
  jednom pre commita. Ako test pada samo u paralelnom režimu, ponovite ga sekvencijalno: testovi dele
  `storage/app/pdf`, a konačni sudija je CI (`composer test`).
- Testovi koriste SQLite u memoriji. Ceo skup traje oko 45 s paralelno i oko 2 minuta sekvencijalno (CI, koji ide sekvencijalno, oko 2 minuta).
  Xdebug (ako je uključen) ga znatno usporava, pa ga isključite osim kad vam treba.
- Testove proverite i sa `CI=true` (CI okruženje se ponaša drugačije od lokalnog): `CI=true composer test`.
- Kalkulacije novca i PDV-a postoje u PHP-u i JavaScript-u i moraju davati isti rezultat (zajednički fixture-i u
  `tests/fixtures`).

## Git tok

- Dve glavne grane: `develop` (staging) i `main` (production). Direktan push na njih nije dozvoljen (rulesets).
- Jedna podfaza (vidi [docs/ROADMAP.md](docs/ROADMAP.md)) = jedna grana = jedan Pull request. Grane su
  `feature/<podfaza>-<opis>` ili `fix/<opis>`, uvek iz `develop`-a (npr. `feature/7-1-readme`).
- Commit poruke i naslovi PR-ova su na engleskom, po Conventional Commits: `feat`, `fix`, `chore`, `refactor`,
  `test`, `ci`, `docs`. Opis PR-a je na srpskom: šta je urađeno, zašto, kako da se proveri.
- PR ide prema `develop`; CI mora biti zelen, a merge je **squash**. Merge radi vlasnik projekta. Merge u `develop`
  pokreće deploy na staging.
- Na produkciju se ide PR-om `develop` → `main`; merge pokreće production deploy.
- Posle merge-a: `git checkout develop && git pull`, brisanje lokalne grane, `git fetch --prune`; tek onda nova grana.
- Izmene `.github/workflows/*.yml` i `deploy/*.sh` upravljaju produkcijom i prave se samo uz posebnu potvrdu.

## CI i CD (GitHub Actions)

Tri workflow-a u `.github/workflows/`, svi na `ubuntu-24.04` (verzija je zakucana namerno, podiže se svesno u sva
tri odjednom):

**`ci.yml`** (CI) radi samo na **Pull request-u** prema `main` ili `develop`. Jedan job `tests`: PHP 8.4, Node 22,
kopira `.env.example`, `composer install`, `npm ci --legacy-peer-deps`, `npm run build` (Vite manifest treba
testovima koji renderuju Inertia stranice), `npm run test`, `php artisan key:generate`, `composer test`.
Novi push na isti PR otkazuje prethodni run.

**`deploy.yml`** (Build & Deploy) radi na **push-u** na `main` (production) i `develop` (staging):

1. `tests`: isti koraci kao CI. Znači da se ceo skup pokreće ponovo i pri push-u (merge-u), a ne samo na PR-u.
2. `build-and-deploy` (tek ako `tests` prođe): `npm ci` i `npm run build`, SSH agent, upload paketa kao `tar` preko
   SSH-a u `releases/<timestamp>` (bez `.git`, `.github`, `node_modules`, `vendor`, `tests`, `storage`, `.env`,
   `deploy`), kopiranje `deploy/finish-release.sh` na server i njegovo pokretanje. Timestamp je `YYYYMMDDHHMMSS` po
   vremenu GitHub runnera (UTC). Isti deploy se ne pokreće dvaput istovremeno i nikad se ne otkazuje započet deploy.
3. `notify` (uvek, i na grešci): šalje email sa statusom `SUCCESS` ili `FAILED`, okruženjem, granom, commit-om i
   linkom ka logu run-a.

**`rollback.yml`** (Rollback) se pokreće ručno (v. [Rollback](#rollback)).

Repository secrets (samo imena; vrednosti su u GitHub-u i nikad u repou):
`DEPLOY_SSH_PRIVATE_KEY`, `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `NOTIFY_SMTP_HOST`, `NOTIFY_SMTP_PORT`,
`NOTIFY_SMTP_USER`, `NOTIFY_SMTP_PASSWORD`, `NOTIFY_EMAIL_TO`.

## Server (cPanel shared hosting)

Na serveru nema Node-a ni supervisora i nema headless Chrome-a (PDF pravi dompdf). Build se radi u GitHub Actions,
a red čekanja je u bazi uz cron.

### Struktura

Svako okruženje (`<env>` je `production` ili `staging`) ima svoj direktorijum:

```text
~/projects/react-laravel-app/deploy/<env>/
├── releases/<timestamp>/     jedan direktorijum po deploy-u (čuva se 5 na production, 3 na staging)
├── shared/
│   ├── .env                  konfiguracija okruženja (van gita)
│   ├── storage/              deljeni storage (logovi, sesije, PDF keš, otpremljene slike)
│   └── real_catalog.php      (opciono) privatni realni katalog, v. docs/real-catalog.md
├── current -> releases/<timestamp>   atomski symlink na aktivni release
└── finish-release.sh, rollback.sh    kopiraju se iz repoa pri svakom deploy-u / rollback-u
```

Svaki release dobija `storage` i `.env` kao symlinkove na `shared/` (nisu deo upload-a).

**Document Root.** Domen svakog okruženja mora da pokazuje na
`~/projects/react-laravel-app/deploy/<env>/current/public`. Podešeno tako (potvrdio vlasnik) za production i staging.
Razlog: deploy samo menja symlink `current`, pa Document Root ostaje isti, a novi release se aktivira jednim atomskim
potezom bez prekida rada.

### PHP

- PHP **8.2 ili noviji** za domen (cPanel → Select PHP Version). Server je CloudLinux sa PHP Selector-om, bez
  MultiPHP INI Editor-a, pa se `php.ini` vrednosti ne menjaju direktno nego kroz PHP Selector.
- Ekstenzije: `gd` (obavezno: dompdf ugrađuje logo), `mbstring`, `openssl`, `pdo_mysql`, `fileinfo`, `tokenizer`, `xml`,
  `curl`, `ctype`, `opcache`. Bez `gd` PDF ponude ne može da se napravi.
- Composer na serveru radi `finish-release.sh`, pa i **CLI** PHP verzija koju skripta koristi mora imati iste
  ekstenzije (posebno `gd`). Putanje `PHP_BIN` i `COMPOSER_BIN` su na vrhu skripte.
- Preporuka za PHP Selector: povećati `opcache.max_accelerated_files` sa 5000 na 10000 (Laravel + `vendor/`
  imaju hiljade fajlova).

### Prvo podešavanje okruženja (jednom, korak po korak)

1. **Baza.** U cPanel-u (MySQL Databases) napravite bazu i korisnika sa svim pravima nad njom.
2. **Direktorijumi.** Napravite `~/projects/react-laravel-app/deploy/<env>/releases` i `.../shared`.
3. **Storage.** U `shared/storage` napravite skelet (isti kao `storage/` u repou) i dajte pravo pisanja:

   ```bash
   cd ~/projects/react-laravel-app/deploy/<env>/shared
   mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
            storage/framework/sessions storage/framework/views storage/logs
   ```

   Direktorijum `storage/app/pdf` pravi sam kod pri prvom PDF-u.
4. **`shared/.env`.** Napravite ga ručno (deploy ga ne pravi i prekida se bez njega), prema tabeli ispod.
5. **Document Root** domena na `current/public` (v. gore). Dok prvi deploy ne napravi `current`, domen ne radi.
6. **SSH i GitHub secrets** su podešeni (v. [CI i CD](#ci-i-cd-github-actions)).
7. **Prvi deploy:** push na `develop` (staging) ili `main` (production), v. [Deploy](#deploy). Migracije se pokreću
   same.
8. **Admin nalog (ručno, jednom).** Upišite u `shared/.env` `SEED_ADMIN_PASSWORD=<izabrana lozinka>`, pa iz `current`:

   ```bash
   php artisan config:cache
   php artisan db:seed --class=AdminUserSeeder --force
   ```

   Zatim **obrišite** `SEED_ADMIN_PASSWORD` iz `shared/.env` i ponovo `php artisan config:cache`. Prijavite se kao
   `admin@example.com` i promenite lozinku. Ne pokrećete ceo `db:seed` na produkciji (pravi demo klijente).
9. **Katalog.** Na produkciji realni katalog po [docs/real-catalog.md](docs/real-catalog.md). Na stagingu može demo:
   `php artisan db:seed --class=CatalogSeeder --force`, pa `CategorySeeder` (v. [Lokalno pokretanje](#lokalno-pokretanje)),
   i `IssuerProfileSeeder` (radi samo kad je `APP_ENV` `local` ili `staging`). Izdavalac se na produkciji unosi kroz
   admin (`/admin/issuer`).
10. **Cron** (v. ispod).
11. **Provera:** `https://<domen>/up` vraća 200, prijava admina radi, a PDF jedne ponude se otvara.

Nakon svake izmene `shared/.env` pokrenite `php artisan config:cache` iz `current`: deploy kešira konfiguraciju, pa se
`.env` ne čita sam od sebe.

### `shared/.env`: obavezne promenljive i očekivane vrednosti

Uzorak je `.env.example` (lokalne vrednosti). Za server su **očekivane** ove vrednosti (usklađuje ih vlasnik na
oba servera; ovde su samo imena i očekivanja, nikad prave tajne):

| Promenljiva | Očekivana vrednost |
| --- | --- |
| `APP_ENV` | `production` (staging sme `staging`) |
| `APP_DEBUG` | `false` |
| `APP_KEY` | jednom generisan (`php artisan key:generate --show`), v. [Tajne i ključevi](#tajne-i-ključevi) |
| `JMBG_HASH_KEY` | najmanje 32 znaka, v. [Tajne i ključevi](#tajne-i-ključevi); **mora postojati pre prvog deploy-a** |
| `APP_URL` | `https://<domen>` (staging: `https://<staging-domen>`), uvek https |
| `APP_LOCALE`, `APP_TIMEZONE` | `sr_Latn`, `Europe/Belgrade` |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `mysql` i podaci baze iz cPanel-a |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `CACHE_STORE` | `database` (keš tabele `cache` i `cache_locks` prave migracije; limiteri zahteva koriste keš) |
| `QUEUE_CONNECTION` | `database` |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | `stack`, `daily`, `warning` (rotacija 14 dana je podrazumevana, `LOG_DAILY_DAYS`) |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | pravi SMTP; bez njega admin ne može da pošalje klijentu mejl za postavljanje lozinke |
| `ACTIVITY_LOG_RETENTION_DAYS` | `365` (podrazumevano) |
| `BACKUP_MYSQLDUMP` | opciono; na serveru `/bin/mysqldump` (cron ima minimalan `PATH`); podrazumevano `mysqldump` |
| `BACKUP_ENABLED` | opciono; podrazumevano `true` za MySQL/MariaDB, `false` inače |
| `BACKUP_PATH` | opciono; podrazumevano `storage/app/backups` (na serveru `shared/storage/app/backups`), nikad unutar javnog direktorijuma |
| `BACKUP_DB_KEEP`, `BACKUP_FILES_KEEP` | opciono; koliko backup-a se čuva, podrazumevano `14` i `4` |
| `CATALOG_REAL_PATH` | apsolutna putanja do privatnog fajla, samo ako se učitava realni katalog |
| `CATALOG_PURGE` | `off` (na produkciji nikad drugačije) |
| `SEED_ADMIN_PASSWORD` | samo privremeno pri pravljenju admina (v. korak 8) |

### Cron

Jedna linija po okruženju, jednom u minuti (`* * * * *`), u cPanel → Cron Jobs (`<cpanel-user>` je korisnik hostinga,
`<env>` je `production` ili `staging`):

```text
cd /home/<cpanel-user>/projects/react-laravel-app/deploy/<env>/current && /usr/local/bin/php artisan schedule:run >/dev/null 2>>/home/<cpanel-user>/projects/react-laravel-app/deploy/<env>/shared/storage/logs/cron-errors.log
```

Izlaz ide u `/dev/null` (`schedule:run` svaki minut piše da nema spremnih komandi), a greške u `cron-errors.log`.
Zakazano je čišćenje dnevnika aktivnosti (`activitylog:prune`, dnevno u 00:00) i backup-i (`backup:database` svaki dan u
02:30 i `backup:files` nedeljom u 03:00, v. [Backup i oporavak](#backup-i-oporavak)); nema posebnih cron linija. Red čekanja se ne pokreće dok ne postoji prvi
`ShouldQueue` posao (danas red ne nosi ništa). Provera: `php artisan schedule:list`, a posle ponoći u dnevniku
aktivnosti mora da se pojavi "Čišćenje dnevnika".

## Deploy

Deploy radi `deploy.yml` (v. gore), a na serveru ga završava `deploy/finish-release.sh <env> <timestamp>`:

1. Testovi (PHP i JS) → build frontenda → upload paketa u `releases/<timestamp>` preko SSH-a.
2. Zaključavanje (`.deploy.lock`): dva deploy-a ili deploy i rollback istog okruženja ne rade istovremeno.
3. Provera da release i `shared/.env` postoje (bez `shared/.env` deploy staje).
4. `storage` i `.env` u novom release-u postaju symlinkovi na `shared/`.
5. `composer install --no-dev --optimize-autoloader` (sa PHP verzijom servera).
6. `php artisan storage:link` (javni disk, ponavlja se u svakom release-u).
7. `php artisan migrate --force` (bez maintenance moda).
8. `config:cache`, `route:cache`, `view:cache`.
9. **Atomski zamena symlinka** `current` → novi release.
10. Reset opcache-a samo ako je `OPCACHE_RESET_URL` u skripti postavljen (trenutno prazan, preskače se).
11. Brisanje starih release-ova (čuva se 5 na production, 3 na staging).

**Migracije su unazad kompatibilne (expand/contract).** Rollback samo pomera symlink, a baza se ne vraća, pa stari
kod mora da radi sa novom šemom. Zato nova kolona ili tabela dolazi prvo (expand), a uklanjanje starog tek u kasnijem
deploy-u kad ga nijedan release više ne koristi (contract). Novi migracioni fajl uvek ima timestamp veći od najvećeg
postojećeg.

**Provera da je deploy prošao:** mejl `SUCCESS` (ili `FAILED`) sa linkom ka run-u, zeleni job-ovi u Actions, `/up`
vraća 200, a `readlink ~/projects/react-laravel-app/deploy/<env>/current` pokazuje na najnoviji `releases/<timestamp>`.

## Rollback

Pokreće se ručno: GitHub → **Actions** → **Rollback** → **Run workflow**, izaberite okruženje (`production` ili
`staging`) i, opciono, `release_timestamp` (14 cifara, `YYYYMMDDHHMMSS`). **Prazan timestamp = prethodni release**
(prvi stariji od aktivnog); navedeni timestamp mora postojati među release-ovima na serveru.

Šta radi: `deploy/rollback.sh` pod istim zaključavanjem kao deploy samo atomski pomera symlink `current` na raniji
release. Ne pravi build, ne pokreće `composer install`, ne dira keš konfiguracije.

Šta NE radi: **ne vraća bazu** i ne poništava migracije. Ako je novi release dodao kolonu ili tabelu, ona ostaje
(bezbedno zahvaljujući expand/contract pravilu). Moguć je samo rollback na release koji još nije obrisan (poslednjih
5 na production, 3 na staging).

> **UPOZORENJE (4.6b, `deleted_at`).** Ponude se brišu "meko" (kolona `deleted_at`). Stari release (pre te izmene) ne
> zna za `deleted_at`, pa posle rollback-a **prikazuje obrisane ponude** u listi, prikazu i PDF-u sve do sledećeg
> deploy-a. Baza se ne vraća. Za demo je prihvatljivo; ako se rollback radi na release iz tog vremena, obavestite
> korisnike ili što pre uradite novi deploy.

## Backup i oporavak

Backup rade dve Artisan komande, zakazane kroz postojeći `schedule:run` (cron iz [Server](#server-cpanel-shared-hosting)), u
vremenu `APP_TIMEZONE`:

| Komanda | Kada | Šta | Čuva se |
| --- | --- | --- | --- |
| `php artisan backup:database` | svaki dan u 02:30 | `mysqldump` cele baze, kompresovan (`<APP_ENV>-db-YYYYmmdd-HHMMSS.sql.gz`) | 14 najnovijih |
| `php artisan backup:files` | nedeljom u 03:00 | arhiva otpremljenih slika (`storage/app/public`) i privatnog kataloga iz `CATALOG_REAL_PATH`, ako postoji (`<APP_ENV>-files-YYYYmmdd-HHMMSS.tar.gz`) | 4 najnovija |

Raspored se registruje samo kad je baza MySQL ili MariaDB (`BACKUP_ENABLED`), pa lokalno sa SQLite-om nema backup-a.
Komande se mogu pokrenuti i ručno iz `current` (npr. pre rizične izmene).

**Gde su fajlovi.** U `shared/storage/app/backups` (`BACKUP_PATH`): preživljava release-ove i nije dostupno sa veba
(direktorijum `0700`, fajlovi `0600`). Putanja unutar `storage/app/public` ili `public/` se odbija.

**Šta se NE kopira.** `shared/.env` ima tajne, pa ga ne dira nijedan backup: vlasnik ga čuva ručno van servera.
Za **pun oporavak** su potrebni: dump baze, **`APP_KEY`** i **`JMBG_HASH_KEY`** iz `shared/.env` (bez prvog su
šifrovani JMBG-ovi nečitljivi, bez drugog ne radi provera duplikata po JMBG-u), fajlovi iz arhive i privatni katalog.

**Kako rade (i šta se dešava pri grešci).**
- `mysqldump` se pokreće bez shell-a, a lozinka baze ide samo kroz privremeni fajl sa opcijama (`0600`, briše se
  odmah); nikad kao argument komande. Dump mora završiti linijom "Dump completed" i imati razumnu veličinu.
- Tek posle uspešnog backup-a brišu se stariji preko ograničenja, i to samo fajlovi sa tačnim obrascem imena te
  komande i tog okruženja. Neuspeh ne ostavlja nepotpun fajl i ne briše nijedan stariji backup.
- Ostaci prekinutog rada (`.tmp-*`) brišu se na početku sledećeg pokretanja.
- Uspeh i neuspeh idu u dnevnik aktivnosti kao akter "Sistem" (`backup.database_created`, `backup.files_created`,
  `backup.failed`); uspešni se ne prikazuju na početnoj stranici admina, a neuspeh se vidi. Poruka greške ima samo
  razlog, nikad putanju, lozinku ni izlaz alata.

**Preuzimanje.** Fajlove povucite na svoj računar najmanje jednom mesečno (cPanel File Manager ili SFTP, direktorijum
`shared/storage/app/backups`); backup na istom serveru ne štiti od gubitka servera. Hosting možda već pravi svoje
bekape, ali na njih se ne oslanjajte bez provere.

**Prostor i kvota hostinga.** 14 dnevnih dump-ova i 4 arhive slika zauzimaju prostor na istom nalogu. Proverite
`du -sh ~/projects/react-laravel-app/deploy/<env>/shared/storage/app/backups` i `df -h`; ako je kvota mala, smanjite
`BACKUP_DB_KEEP` i `BACKUP_FILES_KEEP` u `shared/.env` (pa `php artisan config:cache`).

**Backup sadrži lične podatke** (email, adrese, hash i šifrovani JMBG, dnevnik sa IP adresama). Čuva se kao produkcija:
nikad u git, nikad mejlom, samo na uređaju i mestu koje štitite.

### Ručni oporavak (nema `restore` komande: previše je opasno)

Oporavak se uvek radi u **praznu ili probnu bazu**, nikad preko žive baze bez plana povratka. Na serveru
(klijent `mysql`, MariaDB), korak po korak:

1. U cPanel-u (MySQL Databases) napravite probnu bazu i dodajte joj korisnika.
2. Učitajte dump (traži lozinku probnog korisnika; ne upisujte je u komandu):

   ```bash
   gunzip -c ~/projects/react-laravel-app/deploy/<env>/shared/storage/app/backups/<fajl>.sql.gz | mysql -u <korisnik> -p <probna-baza>
   ```

3. Proverite sadržaj: broj redova ključnih tabela mora da liči na živu bazu.

   ```sql
   SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM offers; SELECT COUNT(*) FROM activity_logs;
   ```

4. Za pravi oporavak: vratite `shared/.env` (sa istim `APP_KEY` i `JMBG_HASH_KEY`), uperite `DB_DATABASE` na bazu
   iz koraka 2 (ili isti dump učitajte u praznu pravu bazu), pa `php artisan config:cache`. Slike i privatni katalog
   vratite iz arhive: `tar -xzf <fajl>.tar.gz -C <odredište>` (u arhivi su `public/...` i `private/real_catalog.php`).
5. Prijavite se, otvorite ponudu i njen PDF, proverite JMBG jednog klijenta (admin → Klijenti).

**Vežba oporavka** (korak 1 do 3) radi se na stagingu pre nego što se backup smatra proverenim (ROADMAP 7.2).

## Provera pred izlazak

Pre izlaska na production (i posle većih izmena) vlasnik prolazi ručnu kontrolnu listu iz
[docs/REGRESSION.md](docs/REGRESSION.md): gost, klijent, admin, sistem (cron, backup, deploy), responzivnost i
bezbednost, posebno za staging i production. Automatski deo (testovi, build, `pint --test`, audit) radi CI.

## Tajne i ključevi

Samo imena, nikad vrednosti:

- **`APP_KEY`** šifruje JMBG-ove (`encrypted` cast). **Nikad se ne menja kad već postoje podaci**: posle promene
  svi sačuvani JMBG-ovi postaju nečitljivi.
- **`JMBG_HASH_KEY`** je ključ HMAC-a za pretragu i jedinstvenost JMBG-a. **Nikad se ne menja kad već postoje podaci**:
  promena ruši proveru duplikata po JMBG-u (stari hash-evi se više ne poklapaju). Mora postojati u `shared/.env`
  pre prvog deploy-a.
- **`shared/.env`** se čuva van gita i van servera na bezbednom mestu, kao deo plana backup-a (v. [Backup i oporavak](#backup-i-oporavak)).
  Bez `APP_KEY` i `JMBG_HASH_KEY` backup baze nije upotrebljiv.
- GitHub secrets (v. [CI i CD](#ci-i-cd-github-actions)) žive samo u GitHub-u.
- Ništa od ovoga se ne ispisuje u PR-ovima, logovima ni dokumentaciji.

## Rešavanje problema

- **Posle deploy-a se vidi stari kod.** CLI i veb PHP imaju odvojen opcache. Na stagingu je merenjem nova verzija
  postajala vidljiva nekoliko sekundi posle deploy-a, pa `OPCACHE_RESET_URL` ostaje prazan (stavka 0.11 u ROADMAP-u).
  Ako se simptom ponovi, otvorite stavku ponovo; do tada sačekajte i osvežite stranicu.
- **Logovi.** Aplikacija: `shared/storage/logs/laravel-<datum>.log` (kanal `daily`, 14 dana). Cron greške:
  `shared/storage/logs/cron-errors.log`. Greške deploy-a su u logu run-a u GitHub Actions i u mejlu `FAILED`.
- **500 posle izmene `.env`.** Pokrenite `php artisan config:cache` iz `current` (ili `config:clear` dok ispravljate).
- **"Previše zahteva. Pokušajte ponovo za nekoliko trenutaka." (429).** Limiteri zahteva su imenovani u
  `app/Providers/AppServiceProvider.php` (`LIMITERS`, broj zahteva u minuti po korisniku, a gosti po IP adresi).
  Poruka se vidi na formi, a odgovor ima `Retry-After`. Ako se pojavi bez razloga, proverite da keš tabele postoje
  (`CACHE_STORE=database`).
- **Dnevnik aktivnosti** (`/admin/activity-log`) je samo za dopisivanje (nema izmene ni brisanja kroz aplikaciju). Čisti
  se komandom `php artisan activitylog:prune` (opcija `--days=`), koju cron pokreće dnevno; retencija je
  `ACTIVITY_LOG_RETENTION_DAYS` (365). IP adresa i uređaj su lični podaci, zato retencija postoji.
- **Greška `JMBG_HASH_KEY` pri čuvanju JMBG-a**: ključ nedostaje ili je kraći od 32 znaka (v. [Tajne i ključevi](#tajne-i-ključevi)).
- **PDF ne može da se napravi**: nedostaje PHP ekstenzija `gd` (web ili CLI).
- **Backup nije napravljen.** Pogledajte dnevnik aktivnosti (`backup.failed` navodi razlog), pa ručno pokrenite
  `php artisan backup:database`. Najčešće: pogrešna putanja do `mysqldump` (`BACKUP_MYSQLDUMP`), nedostaje `proc_open` u CLI PHP-u
  ili `phar`/`zlib` ekstenzija (arhiva fajlova).
- **Deploy staje na "shared/.env does not exist"**: napravite `shared/.env` (v. [Server](#server-cpanel-shared-hosting)).

## Bezbednost u kratkim crtama

Detalji i pravila su u [CLAUDE.md](CLAUDE.md).

- JMBG je šifrovan (`encrypted` cast) uz poseban HMAC hash; pun JMBG i PIB vidi samo admin kroz posebnu akciju koja se
  beleži. Nikad se ne šalje u stranicama kao običan podatak.
- Dnevnik aktivnosti za osetljiva polja (JMBG, PIB, napomena, adresa) čuva samo naziv polja, nikad vrednost.
- Pristup ide preko Policy-ja i uloga; tuđa ponuda je 404. Sve rute pokriva test matrice pristupa
  (`RouteAccessMatrixTest`).
- Rate limiting preko imenovanih limitera, a prijava ima i ograničenje po email adresi i IP adresi.
- Bezbednosna HTTP zaglavlja na svakom odgovoru (`nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`,
  `Permissions-Policy`); CSP i HSTS za sada nisu uključeni.
- Novac je u celim brojevima (centi), a ponuda čuva snimak cena i podataka klijenta.
- Repo je **javan**: nikad tajne (`.env`, ključevi, lozinke, tokeni) u git. Realni podaci kataloga se **ne commituju**
  (izvor dozvoljava samo ličnu nekomercijalnu upotrebu); v. [docs/real-catalog.md](docs/real-catalog.md).

## Licenca

Licenca nije određena.
