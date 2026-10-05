# Realni podaci kataloga (privatni fajl)

Repozitorijum je **JAVAN**. Realni podaci iz zvaničnog konfiguratora **nikad ne idu u git**: ni u
`real_catalog.php` iz repoa (tamo ostaje prazan okvir), ni u `tests/fixtures` (tamo je izmišljen uzorak).
Fajl živi lokalno u ignorisanom direktorijumu ili na serveru van release-a.

## Uslovi izvora podataka

Izvor (`skoda-auto.rs`) dozvoljava samo **ličnu, nekomercijalnu upotrebu**, a reprodukciju sadržaja bez
pismenog odobrenja zabranjuje. Zato: ništa se ne preuzima automatski, podaci se unose ručno, a
**prikazivanje aplikacije sa realnim podacima trećim licima (klijentima, javnom stagingu, demonstraciji)
traži pismeno odobrenje izdavača**. Slike modela se otpremaju kroz admin i žive u `storage`, nikad u gitu.

## Gde se fajl stavlja

Format je opisan u komentaru fajla `database/seeders/data/real_catalog.php` (cene BRUTO u centima, liste sa
eksplicitnim ključevima, grupe opcija). Putanja se bira promenljivom `CATALOG_REAL_PATH`; bez nje važi prazan
okvir iz repoa, koji ne radi ništa.

### Lokalno

1. Napravite `database/seeders/data/private/real_catalog.php` (direktorijum je u `.gitignore`).
2. U `.env` upišite apsolutnu putanju, bez navodnika, npr.
   `CATALOG_REAL_PATH=C:\xampp\htdocs\ReactLaravelApp\database\seeders\data\private\real_catalog.php`

### Na serveru (staging i produkcija)

1. Preko cPanel File Manager-a otpremite fajl u `~/projects/react-laravel-app/deploy/<okruženje>/shared/real_catalog.php`
   (`<okruženje>` je `staging` ili `production`). Fajl je van release-a i preživljava svaki deploy.
2. Postavite dozvole **640** (File Manager: Permissions, ili `chmod 640`).
3. U `shared/.env` upišite apsolutnu putanju, **bez navodnika**:
   `CATALOG_REAL_PATH=/home/<cpanel_korisnik>/projects/react-laravel-app/deploy/<okruženje>/shared/real_catalog.php`
4. Posle svake izmene `shared/.env` pokrenite `php artisan config:cache` iz `current` (deploy kešira konfiguraciju,
   pa se `.env` ne čita sam od sebe).

Seeder odbija nepraznu putanju unutar repozitorijuma koja nije u `database/seeders/data/private/`; fajlovi van
repoa (`shared/`) i u `private/` prolaze.

## Redosled komandi

1. `php artisan catalog:validate-real` proverava fajl i ispisuje brojeve, ništa ne upisuje. Ispravite sve greške
   (poruke imaju putanju, npr. `versions[3]: nepoznat motor 'tsi15'`).
2. Samo ako u bazi postoji demo katalog, i samo lokalno ili na stagingu: postavite `CATALOG_PURGE=demo`
   (pa `php artisan config:cache` na serveru) i pokrenite `php artisan catalog:purge-demo --confirm`.
   Komanda prvo ispisuje okruženje, bazu i broj redova, a odbija da radi uz ponude ili ako su realni podaci već
   učitani. Na **produkciji `CATALOG_PURGE` ostaje `off`**.
3. `php artisan db:seed --class=RealCatalogSeeder --force` učitava podatke (jedna transakcija, sve ili ništa).
4. Vratite `CATALOG_PURGE=off` (ili uklonite red) i, na serveru, ponovo `php artisan config:cache`.

## Ispravke

Seeder je idempotentan (`firstOrCreate` po prirodnom ključu) i ništa ne prepisuje: obrisan ili preimenovan red
se pri ponovnom seedu **vraća**. Pogrešan podatak se ispravlja kroz admin, ne ponovnim seedom. Redovi se ne
brišu, nego se deaktiviraju.
