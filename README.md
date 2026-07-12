# AlmaCasa

Applicazione web per la ricerca di alloggi riservata agli studenti del **Campus di Cesena**
dell'Università di Bologna — dipartimento di **Scienze Informatiche e Architettura**
(`@studio.unibo.it`). Gli studenti cercano stanze e appartamenti verificati, li salvano,
inviano richieste di visita o contatto e possono affittarli pagando dal portafoglio; un
amministratore gestisce annunci, studenti, richieste, affitti e pagamenti.

## Stack tecnologico

- **Server:** PHP (procedurale) + MySQL/MariaDB tramite `mysqli` con prepared statement
- **Client:** Bootstrap 5.3, Font Awesome, JavaScript vanilla, Leaflet (mappe)
- Nessun framework lato server o JS, come da specifiche del progetto.

## Avvio in locale

1. Avvia uno stack PHP + MySQL (XAMPP, MAMP o simili) e copia la cartella nella document root
   (es. `htdocs/`).
2. Configura le credenziali del database in [`db/db_config.php`](db/db_config.php) se diverse
   da `root` / password vuota.
3. Apri `db/seed.php` nel browser e premi **Esegui seed**: lo script crea le tabelle
   (`db/schema.sql`) e l'account amministratore.
4. Vai su `index.php`.

## Credenziali demo

| Ruolo | Email                 | Password   |
|-------|-----------------------|------------|
| Admin | `admin@almacasa.it`   | `admin123` |

> Il seed crea solo l'amministratore; gli studenti si registrano da soli.
> In registrazione sono accettati solo indirizzi `@studio.unibo.it`. In login si può
> digitare la sola parte locale (es. `nome.cognome`): il dominio viene aggiunto in automatico.

## Funzionalità

- **Registrazione e login** con verifica del dominio istituzionale, password gestite con
  `password_hash()` / `password_verify()` (bcrypt), blocco account.
- **Lato studente:** ricerca con filtri (tipo, prezzo, distanza, ordinamento), pagina di
  dettaglio, salvataggio annunci (con aggiornamento AJAX), invio di richieste di visita o
  contatto, profilo con storico richieste, modifica dati ed eliminazione account.
- **Riepilogo costi** dell'alloggio: canone mensile e caparra.
- **Portafoglio e pagamenti simulati:** ogni studente ha un saldo (parte da 0 €), lo
  ricarica inserendo i dati di una carta (numero di 16 cifre e scadenza valida; si salvano
  solo le ultime 4 cifre) e affitta un alloggio (caparra + canone) scalando dal saldo. Può
  inoltre **prorogare** un affitto attivo pagando i mesi aggiuntivi. Ogni movimento è
  tracciato nella tabella `transactions`.
- **Mappa** della posizione dell'alloggio rispetto al Campus di Cesena (Leaflet/OpenStreetMap).
- **Lato admin:** pannello con barra laterale (Dashboard, Annunci, Studenti, Richieste,
  Affitti, Pagamenti, Impostazioni), operazioni CRUD complete sugli annunci, gestione utenti
  e richieste, riepilogo degli affitti, contabilità dei pagamenti (canoni e caparre distinti)
  e registro delle attività (change log).

## Struttura del progetto

```
index.php          Landing page (hero, annunci in evidenza)
search.php         Ricerca alloggi con filtri
listing.php        Dettaglio alloggio + calcolatore + mappa + richieste
favorites.php      Annunci salvati ("Salvati")
wallet.php         Portafoglio: saldo, ricarica con carta, storico movimenti
profile.php        Profilo studente (richieste, dati, eliminazione account)
admin.php          Pannello amministratore (CRUD, gestione)
login.php / register.php / logout.php
api/favorite.php   Endpoint salva/rimuovi preferito (AJAX + fallback)
includes/          auth.php, header.php, footer.php, listings.php, wallet.php, modal.php (helper condivisi)
assets/            img/ (immagini degli alloggi per tipo), js/card-format.js
CSS/               theme.css, style.css
db/                db_config.php, schema.sql, seed.php
```

## Progettazione

Il design segue un approccio **mobile first**, **user centered** e **accessibile**
(skip link, etichette associate ai campi, `aria-*`, contrasto, focus visibile).

## Autori

**Luca Dellasantina** e **Nicola Mazzotti**.

Progetto per il corso di Tecnologie Web — Università di Bologna, 2026.
