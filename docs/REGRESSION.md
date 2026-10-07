# Ručna regresija (kontrolna lista)

Kontrolna lista za završnu proveru pred izlazak i posle većih izmena. Vlasnik je ispunjava na svom primerku (kopira u
beleške); rezultati se **ne upisuju u repozitorijum**. Oznake: **[S]** staging, **[P]** production, **[S+P]** oba
okruženja. Stavke koje prave podatke radite na stagingu ili sa svojim test nalogom, a na produkciji samo ono što je
označeno sa [P] ili [S+P]. Ne unosite prave lične podatke na stagingu i ne upisujte tajne u beleške.

## Gost

1. [S+P] Otvori `/` -> stranica se učitava, vide se Prijava i Registracija.
2. [S+P] Prijava sa pogrešnom lozinkom -> poruka o neispravnim podacima na srpskom, bez tehničkih detalja.
3. [S+P] Prijava ispravnim podacima klijenta -> klijentska početna; Odjava -> povratak na prijavu.
4. [S] Registracija sa praznim imenom, lošom email adresom i kratkom lozinkom -> poruke na srpskom uz svako polje
   (npr. "Polje ime i prezime je obavezno").
5. [S] Registracija sa ispravnim podacima -> nalog je napravljen i prijavljen, početna prikazuje upozorenje o
   nepotpunom profilu.
6. [S+P] Zaboravljena lozinka sa svojom adresom -> poruka da je link poslat i mejl stiže (pravi SMTP).
7. [S+P] Link iz mejla otvara formu; nova lozinka -> prijava novom lozinkom radi, a stari link više ne važi.
8. [S] Na `/forgot-password` pošalji formu 6 puta zaredom -> iznad forme "Previše zahteva. Pokušajte ponovo za nekoliko
   trenutaka."
9. [S+P] Neprijavljen otvara `/admin`, `/offers` i `/dashboard` -> preusmerava se na prijavu.
10. [S+P] Nepostojeća adresa (npr. `/nema-stranice`) -> stranica 404 bez putanja i stack trace-a.

## Klijent

11. [S+P] Početna -> pozdrav sa imenom, dugme "Nova ponuda", poslednje ponude ili poruka da ih nema.
12. [S+P] Moj profil -> JMBG se vidi samo kao maska uz polje, a samo polje je prazno.
13. [S] Sačuvaj profil sa nevažećim poštanskim brojem ili JMBG-om -> poruke uz polja; sa ispravnim -> "Profil je
    sačuvan".
14. [S] Profil firme traži PIB; fizičko lice traži JMBG dok ga nema sačuvanog.
15. [S] Nova ponuda: izaberi model, verziju i opremu -> cena i ukupno se menjaju odmah, količina radi.
16. [S] Grupa "jedno od više" (npr. boja) -> standardna stavka je izabrana, a dodatna se računa kao doplata na cenu
    linije.
17. [S] Dodaj dve stavke i snimi -> otvara se prikaz ponude sa dodeljenim brojem.
18. [S] Sa nepotpunim profilom snimanje je blokirano uz link "Dopunite profil".
19. [S+P] Lista ponuda -> pretraga po broju ili napomeni, filter statusa; dok pretraga traje, lista se prigušuje.
20. [S+P] Prikaz ponude -> podaci klijenta, stavke i opcije, iznos bez PDV-a, PDV i ukupno.
21. [S] Izmeni napomenu -> sačuvano i vidljivo; povučena ponuda ne dozvoljava izmenu napomene.
22. [S] Povuci ponudu -> oznaka "Povučena" u listi i prikazu; ponovno povlačenje ne daje grešku.
23. [S+P] PDF "Štampaj" otvara se u novoj kartici, a "PDF" preuzima fajl.
24. [S] PDF povučene ponude -> baner "POVUČENA".
25. [S+P] Tuđa ponuda: `/offers/<tuđi broj>` i `/offers/<tuđi broj>/pdf` -> 404 (ne 403).
26. [S+P] Klijent otvara `/admin` i `/admin/clients` -> 403 "Nemate pravo pristupa ovoj stranici."
27. [S] Klijent na svojoj ponudi nema dugmad za brisanje i vraćanje povlačenja.

## Admin

28. [S+P] Dashboard `/admin` -> 4 broja, poslednje ponude, poslednje aktivnosti i prečice.
29. [S+P] Klijenti -> lista, pretraga, Reset; pretraga bez rezultata daje poruku, a ne praznu tabelu.
30. [S] Novi klijent -> dupli email ili JMBG blokira, dupli PIB traži potvrdu; klijent dobija mejl za postavljanje
    lozinke.
31. [S] Izmena profila klijenta u adminu; dugme za otkrivanje JMBG-a/PIB-a radi, a u dnevniku piše samo naziv polja.
32. [S] Brisanje klijenta bez ponuda radi; klijent sa ponudama se ne briše (poruka).
33. [S+P] Cene -> izmena cene verzije (neto ili bruto) pokazuje i drugi iznos; PDV stopa se menja; masovna izmena
    prvo prikazuje pregled, pa primenu.
34. [S] Katalog -> svaka kartica se otvara; dodaj, izmeni i deaktiviraj red; red sa zavisnostima se ne briše.
35. [S] Matrica opreme -> promena ćelije (standardno, dodatno, nedostupno) se čuva; istovremena izmena u dva taba daje
    poruku o konfliktu.
36. [S+P] Izdavalac `/admin/issuer` -> unos podataka; nove ponude imaju ta zaglavlja na PDF-u, a stare ostaju sa
    starim.
37. [S+P] Dnevnik aktivnosti -> filteri (korisnik, radnja, datum, IP) i pretraga rade; `%` i `_` su obični znakovi;
    adresa i JMBG su prikazani samo kao naziv polja.
38. [S] Nova ponuda na ime klijenta -> admin bira klijenta pretragom; vlasnik je klijent; nepotpun profil blokira uz
    link na profil.
39. [S] Admin vraća povlačenje ponude, briše je i vraća (filter "Obrisane ponude"); obrisana nestaje iz liste.
40. [S] Admin ne može da povuče tuđu ponudu (nema dugmeta; direktan zahtev daje 403).
41. [S] Admin ne može da obriše sebe ni drugog admina.
42. [S+P] Izgled PDF-a -> zaglavlje dilera, tabela stavki; na ponudi sa 2+ strane zaglavlje kolona se ponavlja, a
    "Strana X/Y" je na dnu; srpska slova su ispravna.

## Sistem

43. [S+P] `php artisan schedule:list` iz `current` -> `activitylog:prune`, `backup:database` i `backup:files`.
44. [S+P] Posle ponoći u dnevniku aktivnosti piše "Čišćenje dnevnika".
45. [S+P] Sutradan u 02:30 zapis "Napravljen backup baze", a nedeljom u 03:00 i "Napravljen backup fajlova".
46. [S+P] `ls -l shared/storage/app/backups` -> fajlovi `-rw-------`, direktorijum `drwx------`.
47. [S+P] `https://<domen>/storage/app/backups/<fajl>` -> 404.
48. [S] Vežba oporavka: `gunzip -c <fajl> | mysql` u probnu bazu, pa broj redova `users`, `offers`, `activity_logs`.
49. [S] Privremeno pogrešan `BACKUP_MYSQLDUMP` -> `backup:database` ne uspeva, `backup.failed` je u dnevniku i na
    dashboardu; vrati vrednost i `php artisan config:cache`.
50. [S+P] `cron-errors.log` i `laravel-*.log` nemaju nove greške.
51. [S+P] Poslednji deploy je zelen, mejl kaže `SUCCESS`, a `readlink current` pokazuje na najnoviji release.
52. [S+P] `/up` vraća 200.
53. [S] Pročitaj upozorenje u README sekciji "Rollback" (`deleted_at`) i potvrdi da je poznato; rollback se na
    produkciji ne vežba.

## Responzivnost (360, 768 i 1280 px)

54. [S] 360 px: prijava, registracija i zaboravljena lozinka -> bez horizontalnog skrola.
55. [S] 360 px: navigacija je meni (burger), a Odjava je uvek vidljiva.
56. [S] 768 px: admin navigacija (7 stavki) je i dalje meni i ništa se ne preklapa; na 1024 px stane u red.
57. [S] 360 i 768 px: Ponude i Klijenti -> polja pretrage i dugmad se prelamaju, tabela se skroluje unutar okvira.
58. [S] 360 px: konfigurator (Nova ponuda) i ukupno se vide bez iskakanja.
59. [S] 360 px: prikaz ponude, klijentska početna i admin dashboard.
60. [S] 360 i 768 px: Dnevnik aktivnosti i Matrica opreme -> padajuće liste ne prelaze širinu ekrana.
61. [S] 1280 px: svi ekrani iz stavki 54 do 60 izgledaju uredno.

## Bezbednost

62. [S+P] `curl -I https://<domen>/login` -> `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
    `Permissions-Policy`.
63. [S+P] Stranice 403 i 404 ne prikazuju stack trace ni putanje (debug je isključen).
64. [S+P] DevTools -> Application -> Cookies -> sesijski kolačić ima Secure, HttpOnly i SameSite=Lax.
65. [S+P] `http://<domen>` preusmerava na `https://<domen>`.
66. [S+P] Više pogrešnih prijava zaredom -> blokada sa porukom o previše pokušaja.
67. [S+P] U dnevniku, neuspela prijava beleži samo email, nikad lozinku.
68. [P] Na produkciji nema demo naloga (`client@example.com`, `firma@example.com`), a admin ima pravi email.
69. [S+P] `grep -E '^(APP_ENV|APP_DEBUG|SESSION_SECURE_COOKIE|CACHE_STORE)=' shared/.env` -> `production` (staging
    `staging`), `false`, `true`, `database` (bez ispisa ostalih linija).
70. [S+P] Proba sa pravim SMTP-om: reset lozinke svog naloga stiže na email.
