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
- [ ] 3.3 Seederi: 3-4 modela sa realnim paketima, motorima i opremom
- [ ] 3.4 Admin dashboard: izmena svih cena (pojedinačno i grupno), sa testovima. Cene se čuvaju NETO; unos
      preko bruto polja sa preračunom: neto = intdiv(bruto_cents * 10000 + intdiv(10000 + rate_bp, 2),
      10000 + rate_bp), rate_bp iz admin podešavanja (D3, 2000 = 20%). PHP i vitest testovi: za sve cene
      u celim evrima bruto -> neto -> bruto daje istu vrednost; za 20%: 1 cent -> 1, 3000000 -> 2500000,
      a polovina se zaokružuje naviše (3 -> 3, jer je 3 / 1,2 = 2,5).
- [ ] 3.5 Admin CRUD za modele, pakete, motore, verzije i opremu

## Faza 4 - Ponude i konfigurator

- [ ] 4.1 Šema: ponude + stavke ponude sa snimkom cena
      Zaštita klijenata sa ponudama: `offers.user_id` sa `restrictOnDelete`; brisanje klijenta (2.4) se
      blokira porukom ako ima ponude, a kasnije opciono anonimizacija (ponude čuvaju snimak podataka).
- [ ] 4.2 Broj ponude NNN/GGGG, resetuje se svake godine, bezbedno pri istovremenim zahtevima
- [ ] 4.3 PDV stopa kao admin podešavanje (podrazumevana vrednost: odluka D3)
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
- D3 (ODLUČENO): podrazumevana stopa PDV-a je 20%; ostaje admin podešavanje (4.3).
- D4 (ODLUČENO): UI je samo na srpskom.
- D5 (ODLUČENO): valuta je EUR. Stara aplikacija prikazuje €, pa `resources/js/lib/money.js` formatira EUR (iznosi u
  centima), a valuta je na jednom mestu.
