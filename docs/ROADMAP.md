# Roadmap

Pravilo: jedna podfaza u jednom trenutku, jedna feature grana i jedan PR po podfazi.
Podfaza je GOTOVA tek kad prođu testovi, radi ručna provera i PR je merge-ovan u `develop`.

Legenda: `[x]` gotovo, `[ ]` na redu.

## Faza 0 - Temelji i pipeline

- [x] 0.1 Sveža Laravel 12 + React + Inertia instalacija, repo na GitHubu (`main`, `develop`)
- [ ] 0.2 Lokalno pokretanje (`composer run setup`, `php artisan serve` + `npm run dev`)
- [ ] 0.3 GitHub: rulesets za `main` i `develop`, squash merge, automatsko brisanje grana
- [ ] 0.4 Deploy SSH ključ + svih 9 repository secrets
- [ ] 0.5 Priprema staging servera: `shared/.env`, MySQL baza, PHP >= 8.2 za domen
- [x] 0.6 vitest + prvi test (`npm install -D vitest`, commit i `package-lock.json`)
- [ ] 0.7 CI/CD fajlovi + CLAUDE.md + ROADMAP.md kroz PR u `develop` -> CI zelen -> prvi staging deploy
- [ ] 0.8 Test Rollback workflow-a na stagingu
- [ ] 0.9 Priprema produkcije, PR `develop` -> `main`, prvi production deploy
- [ ] 0.10 Cron: `schedule:run` svaki minut, `queue:work --stop-when-empty`
      Provera: `php artisan schedule:list`. MORA biti gotovo pre produkcije (`activitylog:prune` je
      zakazan dnevno); do tada se `php artisan activitylog:prune` pokreće ručno.
- [ ] 0.11 Reset opcache-a posle deploy-a (`OPCACHE_RESET_URL` u `finish-release.sh`): staging je posle
      deploy-a prikazivao stari kod. Menja `deploy/*.sh`, pa samo uz potvrdu.

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
- [ ] 3.9 Realni podaci kataloga: 4 modela, ručno ih priprema i dostavlja vlasnik (ništa se ne preuzima sa
      skoda-auto sajtova); redosled: `catalog:validate-real`, `catalog:purge-demo` (samo lokalno/staging),
      `db:seed --class=RealCatalogSeeder`.
- [ ] 3.10 Kartice Verzije i Oprema (stavke opreme) sa grupama opcija: admin CRUD, uključuje otpremanje
      slike stavke (servis `EquipmentItemImages` već postoji).
- [ ] 3.11 Matrica opreme po paketu (standardno / dodatno sa cenom / nedostupno): jedini način da se
      menjaju stavke grupe na liniji (servis u jednoj transakciji, provera konačnog stanja kroz
      `OptionGroupRule`).

## Faza 4 - Ponude i konfigurator

- [ ] 4.1 Šema: ponude + stavke ponude sa snimkom cena
      Stavke ponude snimaju NAZIVE i CENE opreme (tekst i iznosi u centima), ne reference na `trim_equipment`:
      kasnija izmena ili brisanje kataloga ne sme da promeni postojeću ponudu.
      `catalog:purge-demo` odbija da radi dok ponude imaju redove: spisak tabela ponuda je u
      `config/catalog.php` (`offer_tables`, podrazumevano `offers` i `offer_items`) i MORA se uskladiti sa stvarnim
      nazivima tabela koje faza 4 napravi.
      Zaštita klijenata sa ponudama: `offers.user_id` sa `restrictOnDelete`; brisanje klijenta (2.4) se
      blokira porukom ako ima ponude, a kasnije opciono anonimizacija (ponude čuvaju snimak podataka).
- [ ] 4.2 Broj ponude NNN/GGGG, resetuje se svake godine, bezbedno pri istovremenim zahtevima
- [ ] 4.3 PDV stopa: skladište podešavanja je stiglo u 3.4 (`settings`, `vat_rate_bp`, podrazumevano 2000). Ovde:
      ponuda samo SNIMA stopu (i cene stavki) pri kreiranju; kasnija promena stope ne menja postojeće ponude.
- [ ] 4.4 Kalkulacija cena: PHP servis + JS util sa identičnim rezultatom (PHPUnit + vitest)
- [ ] 4.5 UI konfiguratora: model -> paket -> motor, serijska/dodatna oprema, broj vozila,
      "Snimi model" dodaje stavku, zbirovi bez i sa PDV-om uživo
- [ ] 4.6 Lista ponuda: pretraga, broj po strani, paginacija, izmena, brisanje
- [ ] 4.7 Policy: klijent vidi samo svoje ponude, admin sve; Feature testovi

## Faza 5 - PDF i štampa

- [ ] 5.1 dompdf + Blade šablon sa fontom koji podržava srpska slova
- [ ] 5.2 PDF preuzimanje i prikaz za štampu ponude, testovi autorizacije
      Beleže se PDF preuzimanje i štampa ponude kroz `ActivityLogger`.
- [ ] 5.3 Doterivanje PDF izgleda (zaglavlje, tabela stavki, zbirovi, napomena)

## Faza 6 - Admin dashboard i poliranje

- [ ] 6.1 Dashboard: broj klijenata/ponuda, poslednje aktivnosti, prečice do izmene cena
- [ ] 6.2 Prazna i učitavajuća stanja, poruke validacije, responzivnost
- [ ] 6.3 Bezbednosni pregled: rate limiting, pokrivenost Policy-ja, bez osetljivih podataka u logovima

## Faza 7 - Završnica

- [ ] 7.1 README: podešavanje, deploy i rollback
- [ ] 7.2 Rutina za backup baze na serveru
- [ ] 7.3 Završna regresija: testovi zeleni, prolazak kroz staging, production release

## Otvorene odluke

- D1 (ODLUČENO): svaki klijent je registrovan korisnik (`users` + `client_profiles`, 1:1).
- D2 (ODLUČENO): JMBG šifrovan (`encrypted` cast) + `jmbg_hash` (HMAC, `JMBG_HASH_KEY`) za tačnu pretragu
  i unique; PIB nije šifrovan (javan podatak), ali se u dnevniku beleži samo naziv polja.
- D3 (ODLUČENO): podrazumevana stopa PDV-a je 20%; admin podešavanje je od 3.4 (`settings`).
- D4 (ODLUČENO): UI je samo na srpskom.
- D5 (ODLUČENO): valuta je EUR. Stara aplikacija prikazuje €, pa `resources/js/lib/money.js` formatira EUR (iznosi u
  centima), a valuta je na jednom mestu.
