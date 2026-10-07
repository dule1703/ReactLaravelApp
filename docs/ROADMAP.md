# Roadmap

Pravilo: jedna podfaza u jednom trenutku, jedna feature grana i jedan PR po podfazi.
Podfaza je GOTOVA tek kad prođu testovi, radi ručna provera i PR je merge-ovan u `develop`.

Legenda: `[x]` gotovo, `[ ]` na redu.

## Faza 0 - Temelji i pipeline

- [x] 0.1 Sveža Laravel 12 + React + Inertia instalacija, repo na GitHubu (`main`, `develop`)
- [x] 0.2 Lokalno pokretanje (`composer run setup`, `php artisan serve` + `npm run dev`)
- [x] 0.3 GitHub: rulesets za `main` i `develop`, squash merge, automatsko brisanje grana
- [x] 0.4 Deploy SSH ključ + svih 9 repository secrets
- [x] 0.5 Priprema staging servera: `shared/.env`, MySQL baza, PHP >= 8.2 za domen
- [x] 0.6 vitest + prvi test (`npm install -D vitest`, commit i `package-lock.json`)
- [x] 0.7 CI/CD fajlovi + CLAUDE.md + ROADMAP.md kroz PR u `develop` -> CI zelen -> prvi staging deploy
- [x] 0.8 Test Rollback workflow-a na stagingu
- [x] 0.9 Priprema produkcije, PR `develop` -> `main`, prvi production deploy
- [x] 0.10 Cron: `schedule:run` svaki minut (red se ne zakazuje dok ne postoji prvi ShouldQueue posao)
      Provera: `php artisan schedule:list`. MORA biti gotovo pre produkcije (`activitylog:prune` je
      zakazan dnevno); do tada se `php artisan activitylog:prune` pokreće ručno.
      cPanel > Cron Jobs, jednom u minuti (`* * * * *`), po jedna linija za svako okruženje (`<env>` = `production` ili `staging`):
      `cd /home/ddweba/projects/react-laravel-app/deploy/<env>/current && /usr/local/bin/php artisan schedule:run >/dev/null 2>>/home/ddweba/projects/react-laravel-app/deploy/<env>/shared/storage/logs/cron-errors.log`
      Izlaz ide u `/dev/null` (`schedule:run` svaki minut piše "No scheduled commands are ready"), a greške u `cron-errors.log`.
      `queue:work` se ne zakazuje dok ne postoji prvi `ShouldQueue` posao (danas red ne nosi ništa; mejl iz 4.5c je sinhron).
      Štiklira se kad se potvrdi da se u dnevniku aktivnosti pojavio "Čišćenje dnevnika" posle ponoći.
      Potvrđeno 07.10.2026: u dnevniku aktivnosti se pojavilo 'Čišćenje dnevnika' u 00:00 (staging i production).
- [x] 0.11 Reset opcache-a posle deploy-a (`OPCACHE_RESET_URL` ostaje prazan, `deploy/*.sh` se ne menja).
      Zatvoreno bez rute: izmereno na stagingu, nova verzija vidljiva nekoliko sekundi posle deploy-a;
      ako se simptom ponovi, ponovo otvoriti.

## Faza 1 - Autentifikacija i uloge

- [x] 1.1 Expand migracija: `role` na korisnicima (admin/client), seeder za admina, Factory stanja
      admin/client, test da `role` nije mass-assignable (nije u `$fillable`)
- [x] 1.2 Ekrani: prijava, registracija (ime i prezime, email, lozinka) sa Form Request-om umesto
      inline validacije, reset lozinke mejlom. Klijent ne briše sopstveni nalog (uklonjeno).
      Verifikacija mejla (`MustVerifyEmail`) se uključuje tek kad SMTP radi (posle 0.5).
- [x] 1.3 Srpski tekstovi na jednom mestu (`lang/sr_Latn.json` (locale `sr_Latn`)); `APP_TIMEZONE=Europe/Belgrade` i locale;
      guest layout sa dizajn tokenima u duhu Škode (Tailwind); logo (`public/images/logo.svg` +
      `logo.png` za PDF, favicon, izmena `ApplicationLogo.jsx`, naslov i `APP_NAME`)
- [x] 1.4 Middleware za uloge + Policy skelet, Feature testovi pristupa
- [x] 1.5 Dnevnik aktivnosti SVIH korisnika (i admina), samo za dopisivanje: tabela `activity_logs`
      (snimak korisnika, akcija, predmet + oznaka, izmene stara/nova vrednost, IP, uređaj),
      `ActivityLogger` + trait `LogsActivity`, događaji prijave/odjave/neuspele prijave/reseta/
      promene lozinke i profila; osetljiva polja (lozinka, JMBG, PIB) samo kao naziv polja;
      admin stranica `/admin/activity-log` (filteri, pretraga, detalji); retencija 365 dana
      (`ACTIVITY_LOG_RETENTION_DAYS`, `activitylog:prune`). Klijent ne vidi istoriju.
- [x] 1.6 Shell aplikacije: navigacija po ulozi iz deljenih podataka (stavke bez rute su onemogućene
      sa oznakom „uskoro“ i same se aktiviraju), vidljiva odjava (desktop i mobilni), flash poruke

## Faza 2 - Profili klijenata

- [x] 2.1 Migracija profila (`client_profiles`, 1:1 sa korisnikom): tip klijenta (fizičko/pravno lice), ime i
      prezime / naziv firme, JMBG (šifrovan + hash), PIB (9 cifara), adresa, poštanski broj (5 cifara),
      grad, zemlja; prazan profil pri registraciji + backfill postojećih klijenata. Obaveznost polja po
      tipu radi 2.2.
- [x] 2.2 Form Request validacija (`UpdateClientProfileRequest`, obaveznost po tipu) + `ClientProfilePolicy`
      (klijent: samo svoj profil, admin: svi) + `User::profile()` + `config/countries.php` + testovi
- [x] 2.3 Klijent menja svoj profil (`/client-profile`, bez id-a u ruti; JMBG samo kao maska, prazan unos ga ne briše)
- [x] 2.4 Admin: tabela klijenata sa pretragom, brojem redova po strani, paginacijom, izmenom, brisanjem
      Beleži se pregled osetljivih podataka klijenta (JMBG, PIB) kroz `ActivityLogger`.

## Faza 3 - Katalog (admin)

- [x] 3.1 Šema (migracija `car_models`, `trims`, `engines`, `transmissions`, `versions`, svi FK restrict,
      `is_active` umesto brisanja), modeli, factory-ji, `Version::available()`, `AdminOnlyPolicy`.
      Verzija = paket + motor + menjač (sa `drive` fwd/awd) + osnovna cena u centima, NETO bez PDV-a.
- [x] 3.2 Oprema po paketu (`equipment_items`, `trim_equipment`): serijska (bez cene) / dodatna (cena >= 0,
      neto) / nedostupna (nema reda); pravilo cene u `TrimEquipment` modelu; prevodi dnevnika za ceo katalog.
      Kasnije (van obima demoa): izuzeci opreme po motoru i izbori „jedno od više“ (boja, felne).
- [x] 3.3 Seederi kataloga (`CatalogSeeder`, podaci u `database/seeders/data/catalog.php`): 4 modela, 12 paketa,
      9 motora, 6 menjača (uklj. `rwd`), 30 verzija, 30 stavki opreme; idempotentno (`firstOrCreate`), jedan
      zbirni zapis `catalog.seeded`. Na serveru ručno: `php artisan db:seed --class=CatalogSeeder --force`.
- [x] 3.4 Admin ekran `/admin/prices`: izmena cena verzija i dodatne opreme (unos neto ILI bruto, server sam
      preračunava), PDV stopa (`settings`, `vat_rate_bp`, podrazumevano 2000), grupna izmena (pregled, token,
      limiti, jedna transakcija). Cene se čuvaju NETO; `Support/Vat` / `lib/vat.js` sa zajedničkim fixture-ima
      (`tests/fixtures`); bruto se zaokružuje na ceo evro (pola naviše) i iz njega se izvodi neto.
- [x] 3.5 Admin CRUD kataloga, prvi deo: modeli, paketi, motori, menjači (`/admin/catalog/*`, kartice,
      izmena u modalu; deaktivacija umesto brisanja, brisanje samo bez zavisnih redova; slug se pravi na
      serveru i ne menja; nazivi jedinstveni bez razlike u velikim/malim slovima). Motori i menjači nemaju
      `sort_order` (sortirani po nazivu).
- [x] 3.6 Osnova kataloga: kategorije modela (`categories`, `car_model_category`, many-to-many; kartica
      „Kategorije“, višestruki izbor u formi modela, filter liste; promena kroz `CarModelCategories`, jedan
      zapis u dnevniku) i slika modela (`car_models.image_path`, otpremanje kroz admin u storage, nikad u git).
- [x] 3.7 Mehanizam i format za realne podatke kataloga: `database/seeders/data/real_catalog.php` (prazan okvir
      sa opisom formata; cene BRUTO u centima, neto izvodi seeder stopom iz fajla), `RealCatalogSeeder`
      (validacija sve-ili-ništa, idempotentno, zbirni zapis `catalog.real_seeded`, marker
      `catalog_real_seeded_at`), `catalog:validate-real` i `catalog:purge-demo` (nivo `CATALOG_PURGE`,
      samo dok nema ponuda). `DatabaseSeeder` učitava realni fajl; demo seederi ostaju za razvoj.
- [x] 3.8 Grupe opcija i slika stavke opreme (šema, modeli, format): boje, točkovi i enterijer su izbori
      „jedno od više“. `option_groups` (`selection` single|multiple, `category`, `uses_swatch`),
      `equipment_items.group_id/image_path/swatch_hex`. Za `single` grupu svaka linija koja je nudi ima TAČNO
      JEDNU standardnu stavku, a ostale su dodatne sa DOPLATOM (razlika), ne ukupnom cenom
      (`OptionGroupRule`; hook `TrimEquipment` garantuje samo „najviše jedna“). Format `real_catalog.php`
      proširen (`groups`, `group`, `swatch_hex`); servis i validacija slike stavke bez ekrana.
      Kasnije (van prve verzije): pravila zavisnosti između opcija i paketi opreme.
- [x] 3.9 Realni podaci kataloga u PRIVATNOM fajlu van gita (mehanizam): izvor dozvoljava samo ličnu,
      nekomercijalnu upotrebu, a repo je JAVAN. Fajl živi u `database/seeders/data/private/` (u `.gitignore`)
      ili van repoa (server: `deploy/<env>/shared/real_catalog.php`), bira se preko `CATALOG_REAL_PATH`; u repou
      ostaju prazan okvir i izmišljen uzorak. Zaštite: test da je praćeni fajl prazan okvir, `git ls-files`
      test za `private/`, seeder odbija nepraznu putanju unutar repoa van `private/`, upozorenje u
      `catalog:validate-real`. Uputstvo: `docs/real-catalog.md`. Podatke učitava vlasnik ručno.
- [x] 3.10 Kartice Verzije, Oprema i Grupe opcija (`/admin/catalog/versions|equipment|option-groups`): lista sa
      filterima, dodavanje (verzija sa cenom neto ILI bruto, kombinacija se ne menja), stavka opreme sa grupom,
      uzorkom boje i slikom, grupa sa slugom iz naziva; promena grupe stavke koja je u linijama se odbija,
      standardna stavka `single` grupe se ne deaktivira, promena kategorije grupe (uz potvrdu) menja i stavke u
      jednoj transakciji; `EquipmentItem::scopeOfferable()` je jedino mesto šta se nudi (neaktivna grupa sklanja
      stavke iz novih ponuda). Provera zavisnosti verzije (`VersionController::dependencies()`) se proširuje
      ponudama u fazi 4.
- [x] 3.11 Matrica opreme po liniji (`/admin/catalog/matrix`): stavke x linije modela, ćelija nedostupno / serijska /
      dodatna sa cenom; jedino mesto izmene je `EquipmentMatrix` (transakcija, `lockForUpdate`, provera stanja ćelije
      => 409, konačno stanje kroz `OptionGroupRule`). FAZA 3 JE ZAVRŠENA.
- [ ] 3.12 (kasnije) „Kopiraj opremu iz linije ...“ sa pregledom promena pre primene (korisno za „Plus“ linije);
      mora poštovati pravila grupa nad ciljnom linijom (kroz `EquipmentMatrix`).

## Faza 4 - Ponude i konfigurator

- [x] 4.1 Šema: ponude + stavke ponude sa snimkom cena
      Stavke ponude snimaju NAZIVE i CENE opreme (tekst i iznosi u centima), ne reference na `trim_equipment`:
      kasnija izmena ili brisanje kataloga ne sme da promeni postojeću ponudu. Matrica (3.11) BRIŠE red kad ćelija
      postane „nedostupno“, pa ponuda nikad ne sme da referencira `trim_equipment`; doplata stavke `single` grupe
      se u ponudi ZBRAJA na cenu linije.
      `catalog:purge-demo` odbija da radi dok ponude imaju redove: spisak tabela ponuda je u
      `config/catalog.php` (`offer_tables`, podrazumevano `offers` i `offer_items`) i MORA se uskladiti sa stvarnim
      nazivima tabela koje faza 4 napravi.
      Zaštita klijenata sa ponudama: `offers.user_id` sa `restrictOnDelete`; brisanje klijenta (2.4) se
      blokira porukom ako ima ponude, a kasnije opciono anonimizacija (ponude čuvaju snimak podataka).
      Urađeno u 4.1: `offers` (`year`+`seq` unikatno, `number` unikatno, `vat_rate_bp`, snimak klijenta BEZ JMBG-a, ukupni iznosi
      nullable), `offer_items` (snimak teksta + `version_price_cents`, `line_net_cents` nullable), `offer_item_options`
      (`is_surcharge` + `group_name`). Nema FK ka katalogu pa `VersionController::dependencies()` ostaje prazan.
      Za kasnije: status ponude (draft/final) dolazi kad se zna šta znači; serijska oprema u snimku po potrebi u fazi 5.
      4.2 popunjava `year`/`seq`/`number`, 4.3 `vat_rate_bp` i snimak klijenta, 4.4 `total_*`, `vat_cents`, `line_net_cents`.
- [x] 4.2 Broj ponude NNN/GGGG, resetuje se svake godine, bezbedno pri istovremenim zahtevima
      `OfferNumber::assign()` (tabela `offer_counters`, godina iz `offer_date`); brisanje ponude ostavlja rupu, broj se ne koristi ponovo.
      Istovremenost se ne može testirati na SQLite (nema lockova): zaštita je atomski UPDATE reda godine + `unique` na `offers`.
- [x] 4.3 PDV stopa: skladište podešavanja je stiglo u 3.4 (`settings`, `vat_rate_bp`, podrazumevano 2000). Ovde:
      ponuda samo SNIMA stopu (i cene stavki) pri kreiranju; kasnija promena stope ne menja postojeće ponude.
      Urađeno u 4.3: `OfferCreator::create(User $client, ?string $note)` (zaglavlje; 4.5 dodaje `array $items = []` u istu transakciju), `VatRate::current()` (strogo čitanje), `ClientSnapshot`, `OfferClientRules::missing()`.
- [x] 4.4 Kalkulacija cena: PHP servis + JS util sa identičnim rezultatom (PHPUnit + vitest)
      `App\Support\OfferCalculator` + `lib/offer.js`, fixture `tests/fixtures/offer-calc-cases.json` (ručno izračunat). PDV jednom na ukupno neto.
      Granice: cena <= 1e9 centi, quantity 1..999, opcija <= 200, ukupno neto <= 1e11. Neslaganje totala klijenta i servera rešeno u 4.5a.
- [x] 4.5a Server: stavke ponude. `OfferItemResolver` (izbor klijenta: samo ID-evi + quantity -> snimak iz kataloga), `Version::offerableExtras()`,
      `OfferCreator::create($client, $note, $items, $expected)` (stopa -> razrešavanje -> kalkulator -> poređenje `expected_total_*` -> broj -> upis).
      Greške izbora: `OfferItemsException` (422, ključ po stavci); neslaganje totala: `OfferTotalMismatchException` (409 u 4.5b, sa serverskim iznosima).
      Granice: 20 stavki, 500 opcija ukupno. Dnevnik: jedan zapis `offer.items_created`, stavke/opcije mutirane samo pri `created` (`MutesCreationLog`).
- [x] 4.5b UI konfiguratora za KLIJENTA: `/offers/new` (`offers.create`), JSON `offers/catalog/*` (verzije modela, detalji verzije), `POST /offers` (`offers.store`, throttle 10/min, 201 ili 409/422 JSON).
      Stranica `Offers/Create` + `lib/configurator.js` (reducer, izbor `single` grupe, payload samo ID-evi, expected_*). 409 = eksplicitna potvrda novih iznosa (bez ponavljanja).
      Redirect posle snimanja: od 4.6 na `offers.show`. Nav: "Nova ponuda" za klijenta. Slike stavki kasnije (uzorci boja već tu).
- [x] 4.5c Admin kreira klijenta (salonski tok): `Admin/Clients` "Novi klijent" (`clients.create` / `clients.store`), `ClientCreator` (user role=client postavljen na serveru + profil, jedna transakcija sa proverom duplikata), nasumična lozinka koju niko ne zna,
      mejl `ClientAccountCreated` sa linkom standardnog password brokera (rok `auth.passwords.users.expire`, bez ShouldQueue dok 0.10 nije gotov), JMBG opcioni; duplikat email (bez razlike u slovima) i `jmbg_hash` = tvrda blokada, PIB = upozorenje sa potvrdom; jedan zapis `client.created_by_admin`.
      Napomena: `email_verified_at` ostaje `null`; kad se uključi `MustVerifyEmail`, uspešno postavljanje lozinke preko ovog mejla je prirodno mesto da se polje popuni. Izmena profila u adminu (2.4) više ne traži JMBG.
- [x] 4.5d Admin bira klijenta (pretraga po imenu/emailu, `ClientPicker`) i pravi ponudu na njegovo ime: vlasnik je klijent, snimak iz njegovog profila; `OfferPolicy::create` za admina i klijenta, `chooseClient` samo admin; nepotpun profil = 422 (UI blokira unapred); klijentov tok nepromenjen.
      Admin ima i "Nova ponuda" u navigaciji. Dnevnik: isti zapisi, akter admin.
- [x] 4.6 Lista ponuda i prikaz jedne ponude (samo čitanje): `/offers` (`offers.index`) i `/offers/{offer}` (`offers.show`, `whereNumber`), pretraga (broj, naziv klijenta iz snimka, napomena; PIB samo admin), broj po strani 10/25/50.
      `OfferPolicy::viewAny/view` (admin sve, klijent svoje; tuđa ponuda = 404 jer su brojevi uzastopni), lista sužena upitom po ulozi; sastavljanje props-a samo kroz `App\Support\OfferPresenter` (whitelist). Posle snimanja redirect na `offers.show`. "Ponude" u navigaciji je aktivna.
- [x] 4.6b Povlačenje i brisanje ponude: klijent povlači svoju ponudu (`withdrawn_at`, oznaka u listi, žig "POVUČENA" u PDF-u, povratak samo admin); admin soft-delete i restore (`deleted_at`, 404 za sve, filter "Obrisane" u admin listi, broj se ne koristi ponovo).
      Servis `OfferStatus`, `OfferPolicy` (`withdraw`, `revertWithdrawal`, `delete`, `restore`), dnevnik `offer.withdrawn|withdrawal_reverted|deleted|restored`. Zavisnosti broje i obrisane (`withTrashed`): blokada brisanja klijenta i `catalog:purge-demo`.
- [x] 4.6c Izmena ponude: samo napomena (`OfferNoteUpdater`, `OfferPolicy::update`, `PATCH /offers/{offer}/note`, zapis `offer.note_updated` bez teksta); povučena se ne menja (403). Van obima: stavke se ne menjaju, druga konfiguracija = nova ponuda (pojedinačno logovanje izmena stavki otpada).
- [x] 4.7 Kompletna matrica `OfferPolicy` (`OfferPolicyMatrixTest`: sve sposobnosti x gost/vlasnik/drugi klijent/admin x normalna/povučena/obrisana).

## Faza 5 - PDF i štampa

- [x] 5.1 PDF ponude: `GET /offers/{offer}/pdf` (`offers.pdf`, inline, throttle 30/min) preko `App\Services\OfferPdf` + `resources/views/pdf/offer.blade.php`; podaci samo iz `OfferPresenter` (snimak), font DejaVu Sans (ugrađen u dompdf, ima ć č đ š ž €), logo kao data URI. Zahteva PHP `gd` (logo.png je RGBA; `ext-gd` u composer.json + provera u `OfferPdf`).
      Bez serijske opreme (nije u snimku), bez dugmadi/preuzimanja/logovanja (5.2), logo i izgled 5.3 (SVG logo bolje rezolucije).
- [x] 5.2 Dugmad "Štampaj" i "PDF" (lista ponuda i prikaz ponude), preuzimanje (`?download=1`, attachment) i beleženje u dnevnik
      Beleži se samo ono što server vidi, posle uspešnog renderovanja: `offer.pdf_opened` (inline, "otvoreno za štampu", ne dokaz štampanja) i `offer.pdf_downloaded`;
      subjekt ponuda, bez ličnih podataka u zapisu. Svaki zahtev je jedan zapis (bez deduplikacije).
- [x] 5.3 Izdavalac ponude u PDF-u i doterivanje izgleda: admin ekran `/admin/issuer` (`IssuerProfile`, jedan red; naziv obavezan), podaci se SNIMAJU u ponudu (`offers.issuer_*`, `IssuerSnapshot` u `OfferCreator`), PDF ih čita iz `OfferPresenter::issuer()` (stara ponuda bez snimka ima zaglavlje "Škoda konfigurator").
      Izgled: stavka je zasebna tabela (`page-break-inside: avoid`), "Strana X/Y" u podnožju (canvas), naslov dokumenta, bez "+ -" i " kW" za nepoznate vrednosti. Demo izdavalac samo za local i staging (`IssuerProfileSeeder`). Izgled se proverava ručno (nema rasterizatora u razvoju).
- [ ] 5.4 ODLOŽENO: koristi se postojeći `logo.png` (nije urađeno). Otpremanje loga dilera (do tada se štampa naš `public/images/logo.png`; isto pravilo kao slike modela: samo kroz admin, disk `public`, nikad u git).

## Faza 6 - Admin dashboard i poliranje

- [x] 6.1 Dashboard `/admin`: brojevi (klijenti, aktivne, povučene, ponude u 30 dana), poslednjih 5 ponuda i 8 aktivnosti (bez `auth.*` i PDF zapisa), prečice; `App\Support\AdminDashboard` (whitelist, 4 upita).
- [x] 6.2 Prazna i učitavajuća stanja, poruke validacije, responzivnost: klijentska početna (`App\Support\ClientDashboard`, admin na `/dashboard` ide na `/admin`), prazna stanja sa sledećim korakom, prigušivanje lista dok traje pretraga (`BusyRegion`), srpske poruke i nazivi polja uz test za svaki Form Request, `aria-invalid`/`aria-describedby` uz greške, navigacija se skuplja do `lg`.
- [x] 6.3 Bezbednosni pregled: imenovani limiteri (zaseban brojač po imenu; stari numerički `throttle:N,1` je delio jedan brojač po korisniku), 429 kao poruka na formi, `RouteAccessMatrixTest` (sve rute, tuđa ponuda = 404), zaključani shared props, adresa redaktovana u dnevniku, bezbednosna zaglavlja (bez CSP/HSTS), `Support/Like` u dnevniku.

## Faza 7 - Završnica

- [x] 7.1 README: podešavanje, deploy i rollback (lokalno pokretanje, testovi, git tok, CI/CD, server i prvo podešavanje, deploy, rollback sa upozorenjem o `deleted_at`, tajne, rešavanje problema)
      Mora da sadrži ROLLBACK UPOZORENJE iz CLAUDE.md (4.6b): stari release ne zna za `deleted_at`, pa posle rollback-a prikazuje obrisane ponude do sledećeg deploy-a (baza se ne vraća).
- [ ] 7.2 Rutina za backup baze i fajlova na serveru: `backup:database` (dnevno 02:30, čuva 14) i `backup:files` (nedeljom 03:00, čuva 4) kroz postojeći `schedule:run`, uspeh/neuspeh u dnevniku, README "Backup i oporavak".
      Štiklira se kad vlasnik potvrdi VEŽBU OPORAVKA na stagingu (`gunzip -c backup | mysql` u probnu bazu, provera broja redova) i da je cron napravio backup u 02:30 (nedeljom i fajlove).
- [ ] 7.3 Završna regresija: testovi zeleni, prolazak kroz staging, production release

## Stanje projekta

Na dan 07.10.2026:

- Faze 0 do 6 i 7.1 su gotove. `main` i `develop` imaju identična stabla (PR #103 je poslednji `develop` -> `main`).
- 7.2 (backup) je u kodu i na oba okruženja. Na production-u je ručno potvrđeno: prvi backup 07.10.2026 u 15:37, fajl
  `0600`, direktorijum `0700`, zapis u dnevniku aktivnosti. Čeka se vežba oporavka na stagingu i potvrda noćnog cron-a
  u 02:30; zato je 7.2 `[ ]`.
- 7.3: audit stanja i regresija u čistoj kopiji su urađeni (PHP: 1047 testova, 1 preskočen na Windowsu zbog `umask`,
  JS: 239 testova; `pint --test`, `composer audit` i `npm audit --omit=dev` bez nalaza). Ručna regresija po
  [REGRESSION.md](REGRESSION.md) čeka vlasnika, pa je 7.3 `[ ]`. Posle potvrde sledi anotirani tag `v1.0.0` na `main`.

## Poznata ograničenja i kasnije

Svaka stavka: zašto je tako i šta bi trebalo.

- **3.12 kopiranje opreme iz linije:** ručni unos u matrici je spor za "Plus" linije. Trebalo bi "kopiraj iz linije" sa
  pregledom promena pre primene.
- **5.4 logo dilera:** štampa se naš `logo.png`. Trebalo bi otpremanje kroz admin (isto pravilo kao slike modela).
- **`MustVerifyEmail` je isključen:** SMTP je došao kasnije. Trebalo bi ga uključiti i popuniti `email_verified_at` pri
  postavljanju lozinke preko mejla iz 4.5c.
- **`ShouldQueue` / `queue:work`:** mejlovi su sinhroni, red je prazan. Kad se pojavi prvi posao u redu, treba dodati
  `queue:work --stop-when-empty` u cron.
- **CSP i HSTS nisu uključeni:** HSTS se teško povlači, a CSP traži podešavanje za Vite i Inertia. Trebalo bi prvo CSP
  samo za izveštaje, a HSTS tek posle perioda stabilnog HTTPS-a.
- **`npm audit` (ceo, bez `--omit=dev`) ima 9 nalaza** u razvojnim alatima (`tailwindcss` 3 i `concurrently`); u produkcijski
  build ne ulaze. Ispravka traži major `tailwindcss` 4, pa bi trebalo planirati nadogradnju.
- **Neuspeh backup-a se vidi samo kao `backup.failed`** u dnevniku i na dashboardu, bez obaveštenja. Trebalo bi kartica
  "Poslednji backup" na dashboardu i mejl pri neuspehu.
- **Backup je na istom serveru:** gubitak servera znači gubitak i backup-a. Trebalo bi redovno preuzimanje (README) ili
  odredište van servera.
- **Lozinka demo klijenata iz `DatabaseSeeder`-a je poznata (samo staging):** dolazi iz `UserFactory`. Ne sme na
  production; pre prikaza trećim licima na stagingu treba je promeniti ili ukloniti naloge.
- **Ziggy izlaže imena admin ruta u browseru** (odluka 6.3: ostavljeno): nisu tajna, pristup štiti Policy. Trebalo bi
  razmotriti filtriranje ruta po ulozi.
- **Filter korisnika u dnevniku učitava sve korisnike** (odluka 6.3: ostavljeno): u redu za demo. Trebalo bi pretraga ili
  straničenje kad korisnika bude hiljade.
- **Rollback i `deleted_at`:** stari release ne zna za meko brisanje, pa prikazuje obrisane ponude do sledećeg deploy-a.
  Trebalo bi izbegavati rollback preko te granice ili ga pratiti novim deploy-om.
- **Realni podaci kataloga:** izvor dozvoljava samo ličnu nekomercijalnu upotrebu; prikaz trećim licima traži pismeno
  odobrenje izdavača (v. [real-catalog.md](real-catalog.md)).
- **Rate limiting** pokriva auth rute i ključne akcije klijenta i admina, ali ne i ostale admin rute (katalog, cene).
  Trebalo bi proširiti ako admin nalog postane meta.
- **Lokalni disk ima `serve => true`** (ruta `storage/{path}` radi samo uz potpisan URL, aplikacija je ne koristi).
  Trebalo bi je isključiti malim zasebnim PR-om.
- **Deploy skripte u javnom repou sadrže korisničko ime cPanel naloga.** Trebalo bi ga izdvojiti u fajl na serveru
  (izmena `deploy/*.sh` traži posebno odobrenje).
## Otvorene odluke

- D1 (ODLUČENO): svaki klijent je registrovan korisnik (`users` + `client_profiles`, 1:1).
- D2 (ODLUČENO): JMBG šifrovan (`encrypted` cast) + `jmbg_hash` (HMAC, `JMBG_HASH_KEY`) za tačnu pretragu
  i unique; PIB nije šifrovan (javan podatak), ali se u dnevniku beleži samo naziv polja.
- D3 (ODLUČENO): podrazumevana stopa PDV-a je 20%; admin podešavanje je od 3.4 (`settings`).
- D4 (ODLUČENO): UI je samo na srpskom.
- D5 (ODLUČENO): valuta je EUR. Stara aplikacija prikazuje €, pa `resources/js/lib/money.js` formatira EUR (iznosi u
  centima), a valuta je na jednom mestu.
