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
      Provera: `php artisan schedule:list`.

## Faza 1 - Autentifikacija i uloge

- [x] 1.1 Expand migracija: `role` na korisnicima (admin/client), seeder za admina, Factory stanja
      admin/client, test da `role` nije mass-assignable (nije u `$fillable`)
- [x] 1.2 Ekrani: prijava, registracija (ime i prezime, email, lozinka) sa Form Request-om umesto
      inline validacije, reset lozinke mejlom. Klijent ne briše sopstveni nalog (uklonjeno).
      Verifikacija mejla (`MustVerifyEmail`) se uključuje tek kad SMTP radi (posle 0.5).
- [x] 1.3 Srpski tekstovi na jednom mestu (`lang/sr_Latn.json` (locale `sr_Latn`)); `APP_TIMEZONE=Europe/Belgrade` i locale;
      guest layout sa dizajn tokenima u duhu Škode (Tailwind); logo (`public/images/logo.svg` +
      `logo.png` za PDF, favicon, izmena `ApplicationLogo.jsx`, naslov i `APP_NAME`)
- [ ] 1.4 Middleware za uloge + Policy skelet, Feature testovi pristupa
- [ ] 1.5 Log pristupa: beleži se svaka prijava klijenta (admin vidi kada je ko pristupio)
- [ ] 1.6 Shell aplikacije: navigacija (Home, Klijenti, Ponude), odjava, flash poruke

## Faza 2 - Profili klijenata

- [ ] 2.1 Migracija profila: ime i prezime / naziv firme, JMBG (13 cifara + kontrolna cifra),
      PIB (9 cifara), adresa, poštanski broj (5 cifara), grad, zemlja
- [ ] 2.2 Form Request validacija + Policy (klijent: samo svoj profil, admin: svi) + testovi
- [ ] 2.3 Klijent menja svoj profil
- [ ] 2.4 Admin: tabela klijenata sa pretragom, brojem redova po strani, paginacijom, izmenom, brisanjem

## Faza 3 - Katalog (admin)

- [ ] 3.1 Šema: modeli, paketi opreme, motori, menjači, verzije
      (verzija = paket + motor + menjač + osnovna cena u centima)
- [ ] 3.2 Oprema po paketu: serijska / dodatna (sa cenom) / nedostupna
- [ ] 3.3 Seederi: 3-4 modela sa realnim paketima, motorima i opremom
- [ ] 3.4 Admin dashboard: izmena svih cena (pojedinačno i grupno), sa testovima
- [ ] 3.5 Admin CRUD za modele, pakete, motore, verzije i opremu

## Faza 4 - Ponude i konfigurator

- [ ] 4.1 Šema: ponude + stavke ponude sa snimkom cena
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
- D2: JMBG je osetljiv. Šifrovati u bazi (Laravel `encrypted` cast) ili ostaviti pretraživ?
  Šifrovanje onemogućava pretragu po JMBG-u.
- D3 (ODLUČENO): podrazumevana stopa PDV-a je 20%; ostaje admin podešavanje (4.3).
- D4: Da li je UI samo na srpskom ili i na engleskom?
- D5: Valuta. Stara aplikacija prikazuje €, pa `resources/js/lib/money.js` formatira EUR (iznosi u
  centima), a valuta je na jednom mestu. Potvrditi pre faze 4 da je EUR (a ne RSD) konačan.
