# School Chatbot AI - Plugin WordPress

**Versione:** 1.2.0  
**Autore:** Davide Bertolino  
**Sito:** [www.davidebertolino.it](https://www.davidebertolino.it)

Chatbot AI per siti scolastici che risponde a domande su orari, pagine e navigazione del sito.

## Funzionalità

- ✅ Widget chat flottante (posizione configurabile)
- ✅ Shortcode per incorporamento in pagine
- ✅ Storico conversazioni con database
- ✅ Rate limiting configurabile
- ✅ Feedback utenti (pollice su/giù)
- ✅ Domande suggerite cliccabili
- ✅ Supporto Custom Post Types
- ✅ Multi-provider AI (Anthropic Claude / OpenAI GPT)
- ✅ **Blocklist parole** con azioni configurabili
- ✅ **Multilingua** con rilevamento automatico (11 lingue)
- ✅ **Webhook** per Slack, Discord, Teams, ecc.
- ✅ **Notifiche email** per eventi importanti
- ✅ Dashboard Analytics con consumo token
- ✅ Sintesi vocale (TTS) e input vocale (STT)
- ✅ Pannello accessibilità: alto contrasto, tema scuro, dimensioni testo
- ✅ Interfaccia completamente traducibile
- ✅ **Informativa IA** non disattivabile (AI Act art. 50)
- ✅ **Avviso al primo uso** con soglia 14 anni (L. 132/2025)
- ✅ Conservazione dei messaggi configurabile, o disattivabile del tutto

## Installazione

1. Carica la cartella `school-chatbot-plugin` in `/wp-content/plugins/`
2. Attiva il plugin da WordPress Admin → Plugin
3. Vai in **School Chatbot → Impostazioni**
4. Inserisci la tua API key (Anthropic o OpenAI)
5. Configura le opzioni desiderate

## Configurazione

### API Key

Il plugin supporta due provider:

| Provider | Modello di default | Ottieni API Key |
|----------|--------------------|-----------------|
| Anthropic (Claude) | claude-opus-5 | [console.anthropic.com](https://console.anthropic.com/) |
| OpenAI (GPT) | gpt-4o-mini | [platform.openai.com](https://platform.openai.com/) |

Il modello è selezionabile dalle impostazioni. Per Claude sono disponibili:

| Modello | Quando usarlo | Costo (input / output per milione di token) |
|---------|---------------|---------------------------------------------|
| Claude Opus 5 | Massima qualità delle risposte | $5 / $25 |
| Claude Sonnet 5 | Equilibrio qualità/costo | $3 / $15 |
| Claude Haiku 4.5 | Massimo risparmio | $1 / $5 |

### Blocklist Parole

Filtra messaggi contenenti parole inappropriate:

1. Inserisci le parole da bloccare (una per riga)
2. Scegli l'azione:
   - **Mostra avviso**: blocca l'invio e mostra messaggio
   - **Blocca silenziosamente**: risponde con messaggio generico
   - **Sostituisci**: censura le parole con asterischi e invia

### Multilingua

Il chatbot supporta 11 lingue:
- 🇮🇹 Italiano
- 🇬🇧 English
- 🇪🇸 Español
- 🇫🇷 Français
- 🇩🇪 Deutsch
- 🇵🇹 Português
- 🇸🇦 العربية (Arabo)
- 🇨🇳 中文 (Cinese)
- 🇷🇴 Română
- 🇺🇦 Українська (Ucraino)
- 🇦🇱 Shqip (Albanese)

**Rilevamento automatico**: Il chatbot rileva la lingua del messaggio e risponde nella stessa lingua.

Puoi anche configurare messaggi di benvenuto personalizzati per ogni lingua.

### Webhook e Notifiche

#### Webhook

Ricevi notifiche in tempo reale su:
- Slack
- Discord
- Microsoft Teams
- Qualsiasi servizio che supporta webhook

**Eventi disponibili:**
- Nuova conversazione
- Feedback negativo
- Parola bloccata rilevata
- Domanda senza risposta (errore API)

**Esempio payload:**
```json
{
  "event": "negative_feedback",
  "timestamp": "2024-01-15T10:30:00+01:00",
  "site": "Scuola Example",
  "site_url": "https://scuola.example.it",
  "data": {
    "message_id": 123,
    "user_message": "Dove trovo gli orari?",
    "bot_response": "..."
  }
}
```

#### Notifiche Email

Ricevi email per:
- Domande senza risposta
- Feedback negativi
- Tentativi con parole bloccate

### Informazioni Aggiuntive

Nel campo dedicato puoi inserire informazioni che il chatbot deve sempre conoscere:

```
ORARI SEGRETERIA:
Lunedì-Venerdì: 8:00-13:00
Martedì e Giovedì: anche 15:00-17:00

CONTATTI:
Tel: 02 1234567
Email: segreteria@scuola.edu.it

CODICE MECCANOGRAFICO: MIIC12345X
```

## Shortcode

Incorpora il chatbot in qualsiasi pagina:

```
[school_chatbot]
```

Con parametri:
```
[school_chatbot height="400" title="Assistente Virtuale"]
```

## Pagine Admin

### Impostazioni
Configurazione completa di tutte le funzionalità.

### Analytics
- Conversazioni totali (ultimi 30 giorni)
- Messaggi totali
- Feedback positivi/negativi
- Consumo token e percentuale di input servita dalla cache
- Grafico conversazioni per giorno
- Domande più frequenti

### Conversazioni
- Lista di tutte le conversazioni
- Visualizzatore dettagliato per ogni conversazione
- Paginazione

## Requisiti

- WordPress 5.8+
- PHP 7.4+
- MySQL 5.7+
- Connessione HTTPS (consigliata)

## Aggiornamenti

Il plugin si aggiorna automaticamente da GitHub Releases: i nuovi rilasci
compaiono nella schermata Plugin di WordPress come qualsiasi altro aggiornamento.
Nessuna registrazione e nessuna licenza da attivare; il controllo periodico
interroga `api.github.com` e non trasmette dati del sito.

## Trasparenza AI Act

Dal 2 agosto 2026 l'articolo 50 del Regolamento UE 2024/1689 (AI Act) impone che
chi interagisce con un sistema di intelligenza artificiale ne sia informato fin
dal primo contatto. Il plugin lo assolve con tre elementi:

1. Un'informativa mostrata sopra la conversazione, sia nel widget flottante sia
   nello shortcode.
2. Un badge **IA** accanto al nome dell'assistente.
3. Una regola nel prompt che obbliga il chatbot a dichiararsi sistema di IA se
   gli viene chiesto, e a non presentarsi con un nome proprio di persona.

Il testo dell'informativa è personalizzabile per adattarlo al tono della scuola
o per tradurlo:

```php
add_filter('scb_ai_disclosure', function ($testo) {
    return 'Questo assistente è un sistema di intelligenza artificiale.';
});
```

**Non è però eliminabile**: un filtro che restituisce una stringa vuota viene
ignorato e l'informativa predefinita viene ripristinata. È una scelta
deliberata — è un obbligo di legge, non una preferenza estetica.

## Informativa al primo uso e soglia dei 14 anni

La legge 132/2025, all'articolo 4 comma 4, fissa a **14 anni** l'età minima per
accedere autonomamente a sistemi di intelligenza artificiale: sotto tale soglia
serve l'autorizzazione di chi esercita la responsabilità genitoriale.

Prima del primo messaggio il plugin mostra quindi un avviso che ricorda la natura
artificiale dell'assistente, invita a non scrivere dati personali, richiama la
soglia dei 14 anni e — se configurato — rimanda all'informativa privacy della
scuola. L'utente deve confermare esplicitamente per poter scrivere; la conferma
viene ricordata nel browser.

**Cosa questa misura è, e cosa non è.** La legge non definisce procedure di
verifica dell'età, e nessuna verifica anagrafica è tecnicamente possibile in un
browser: l'autodichiarazione è l'unico strumento praticabile. Questo avviso
documenta che l'utente è stato informato prima di scrivere — non impedisce
l'accesso a chi dichiara il falso. **L'autorizzazione dei genitori va raccolta
dalla scuola per via ordinaria**, tipicamente insieme alle liberatorie
dell'iscrizione: il plugin non la sostituisce.

L'avviso è disattivabile dalle impostazioni, a differenza della disclosure IA che
è sempre presente. La ragione della differenza: l'obbligo di trasparenza dell'AI
Act vale per chiunque, mentre la soglia dei 14 anni non riguarda un ITS, un CPIA
o un corso per adulti, dove l'avviso sarebbe attrito privo di fondamento. La
responsabilità della disattivazione è dell'istituto.

Se cambi il testo dell'avviso e vuoi che venga riproposto anche a chi l'aveva
già confermato, incrementa la versione:

```php
add_filter('scb_age_gate_version', function () {
    return '2';
});
```

**Ambito di applicazione.** Un assistente che aiuta a navigare il sito non
rientra fra i sistemi ad alto rischio dell'Allegato III dell'AI Act, che
riguardano l'istruzione quando decidono ammissioni, valutano risultati di
apprendimento o sorvegliano gli esami. Se il chatbot venisse esteso a rispondere
su voti, valutazioni o carriera scolastica degli studenti, cambierebbe categoria
e con essa il regime normativo applicabile.

## Privacy e protezione dei dati

> **Questo plugin non è una delle app educational "senza raccolta dati".**
> Le applicazioni educational e assistive dello stesso autore (Agenda Visiva,
> MisoTest e le altre) funzionano senza account e senza inviare nulla in rete: i
> dati restano sul dispositivo. Questo plugin **non può offrire la stessa
> garanzia**, perché la sua funzione stessa è mandare il messaggio dell'utente a
> un servizio di intelligenza artificiale di terze parti. È uno strumento
> infrastrutturale per un ente che ha un proprio titolare del trattamento e un
> proprio DPO, non un'app di consumo. Chi cerca la garanzia "nessuna raccolta
> dati" deve guardare a quelle app, non a questa.

### Che cosa esce dal sito, e verso chi

A ogni messaggio vengono trasmessi al provider AI configurato (Anthropic oppure
OpenAI):

- il testo scritto dall'utente;
- i messaggi precedenti della stessa conversazione, se lo storico è attivo;
- l'indice del sito: titoli, URL ed estratti di pagine e articoli **pubblici**;
- i campi dei Custom Post Type che hai esplicitamente elencato nelle
  impostazioni (per impostazione predefinita: nessuno).

**Il testo scritto dall'utente è un dato personale ogniqualvolta l'utente ci
metta dentro qualcosa di personale**, e questo succede: "mio figlio Marco della
2B ha un PDP, a chi mi rivolgo?" è una domanda del tutto normale per un genitore.
Non si può escludere per contratto, si può solo ridurre — l'avviso iniziale
invita esplicitamente a non farlo, ma non lo impedisce.

Entrambi i provider hanno sede negli Stati Uniti: si tratta di un trasferimento
verso un paese terzo ai sensi del Capo V del GDPR.

**Se attivi i webhook, c'è un secondo destinatario.** Il payload inviato a Slack,
Discord, Teams o a qualunque altro endpoint configurato contiene il testo della
domanda dell'utente e la risposta del bot (vedi l'esempio più sopra). Vale quindi
tutto quanto detto finora, con in più il fatto che quel destinatario lo scegli tu:
se è un servizio extra-UE, è un ulteriore trasferimento da valutare. Le notifiche
email contengono gli stessi contenuti e viaggiano verso la casella indicata nelle
impostazioni.

### Cosa deve fare la scuola prima di attivarlo

1. **Stipulare un accordo sul trattamento dei dati (DPA)** con il provider AI
   scelto e verificare la base giuridica del trasferimento extra-UE. Il plugin
   non può farlo al posto vostro: chi apre l'account API è la scuola.
2. **Aggiornare l'informativa privacy** del sito indicando il servizio AI usato,
   le finalità, i tempi di conservazione e i diritti dell'interessato; poi
   inserirne l'URL nelle impostazioni del plugin.
3. **Coinvolgere il DPO** prima della pubblicazione, non dopo.
4. **Valutare una DPIA.** Un assistente di navigazione su contenuti pubblici,
   usato anche da minori, non richiede automaticamente una valutazione d'impatto,
   ma la platea di minorenni è un elemento che il DPO deve pesare.

### Cosa fa il plugin per ridurre il problema

- Non usa cookie di tracciamento né SDK di analytics di terze parti.
- Le conversazioni sono archiviate nel database del sito, non su servizi esterni
  (i webhook, se li attivi, sono l'eccezione descritta sopra).
- Il salvataggio del testo dei messaggi **si può disattivare** del tutto: restano
  i soli conteggi per le statistiche (al prezzo di perdere il contesto
  conversazionale e le domande frequenti).
- Il periodo di conservazione è configurabile; la pulizia è automatica e
  giornaliera.
- I campi personalizzati dei CPT non vengono trasmessi se non li elenchi
  esplicitamente.
- Il rate limiting usa hash con i sali di WordPress, non identificatori in
  chiaro, e i contatori vivono pochi secondi.
- La disinstallazione rimuove tabelle, opzioni e transient senza lasciare
  residui.
- Le conversazioni collegate a un account o a un indirizzo email possono essere
  esportate e cancellate con gli strumenti privacy di WordPress (vedi sotto).

**Input vocale.** La dettatura (pulsante microfono) è **disattivata per
impostazione predefinita** dalla 1.2.0. Usa la Web Speech API del browser: il
plugin riceve solo il testo trascritto, ma l'audio è elaborato dal fornitore del
browser — in Google Chrome viene inviato ai server di Google, anche fuori dallo
SEE. Su un sito frequentato da minori valuta con attenzione prima di attivarla
(Impostazioni → Accessibilità → Input vocale). Sui siti aggiornati da una
versione precedente che non avevano mai salvato questa impostazione, la 1.2.0
mantiene il vecchio comportamento (attiva) e mostra una sola volta un avviso
all'amministratore che invita a rivederla.

Non troverai qui la frase "conforme al GDPR": la conformità di un trattamento
dipende da come lo configura e lo documenta chi lo mette in esercizio, non da
un'affermazione del software. Quello che il plugin può offrire è di rendere
verificabile cosa fa — il codice è pubblico e questa sezione descrive
esattamente i dati che escono.

## Integrazione ecosistema DB privacy

Quando **DB Privacy Hub** (o, in modalità legacy, DB SEO Manager 1.2.x) è
installato, School Chatbot AI vi si integra automaticamente, senza
configurazione. Senza quei plugin non succede nulla di diverso, e l'esportazione
e cancellazione dei dati funziona comunque tramite gli strumenti core di
WordPress.

### Dichiarazione trattamenti al registro privacy unificato

Il plugin dichiara nel pannello *Privacy → Registro trattamenti* (filtri
`dbph_processing_register` e legacy `dbseo_processing_register`, deduplicati per
`id`) solo i trattamenti effettivamente attivi, leggendo le impostazioni reali:

| ID | Quando appare |
|---|---|
| `scb_conversations` | chatbot attivo con API key, oppure conversazioni ancora in archivio |
| `scb_ai_provider` | chatbot attivo con API key: descrive il provider selezionato (Anthropic o OpenAI) e il trasferimento extra-UE |
| `scb_notifications` | webhook configurato con almeno un evento, o notifiche email attive |
| `scb_voice_input` | input vocale attivo |

Ogni voce compila gli 8 campi del registro (id, label, status, purpose,
legal_basis, data_collected, retention, transfers). La conservazione riportata è
quella configurata in *Cancella le conversazioni dopo N giorni*.

**Base giuridica: la sceglie il titolare.** Il plugin non sa se l'istituto è
statale o paritario, quindi il testo presenta entrambe le ipotesi come *da
confermare*: compito di interesse pubblico (art. 6.1.e GDPR, art. 2-ter Codice
privacy) per le scuole statali — che non possono invocare il legittimo interesse
nell'esecuzione dei propri compiti — e legittimo interesse (art. 6.1.f), con
bilanciamento che tenga conto dei minori, per paritarie ed enti privati. Una
volta deciso con il DPO, fissa il testo definitivo:

```php
add_filter('scb_privacy_legal_basis', function () {
    return 'Esecuzione di un compito di interesse pubblico (art. 6.1.e GDPR) ...';
});
```

**Trasferimento extra-UE: descritto con prudenza.** Le voci indicano la società
statunitense (Anthropic PBC / OpenAI, L.L.C.) e l'entità contraente che di norma
serve i clienti dello SEE (Anthropic Ireland, Limited / OpenAI Ireland Ltd),
precisando che il trattamento può comunque avvenire negli USA. Le garanzie (SCC
nel DPA del fornitore e/o EU-U.S. Data Privacy Framework) sono riportate come
*da verificare nel contratto con il fornitore*: il plugin non può sapere quale
accordo la scuola ha effettivamente sottoscritto.

### Destinatari nella Privacy Policy generata

Sul filtro `dbph_policy_destinatari` il plugin aggiunge il provider AI
configurato (`name` / `description` / `country`), qualificato come responsabile
del trattamento (art. 28) in base ai termini commerciali API e al DPA, con paese
*Stati Uniti (extra-UE)*; se è attivo un webhook, anche il suo host. Se dichiari
il fornitore anche tra i *Responsabili* dell'Hub (consigliato, con gli estremi del
DPA), usa come nome **Anthropic** oppure **OpenAI**: l'Hub riconosce il nome e non
lo ripete due volte.

### DSAR routing via Privacy Hub

*Strumenti → Esporta / Cancella dati personali* trovano le conversazioni
riconducibili all'indirizzo email richiesto:

- avviate da un utente **loggato** con l'account WordPress che ha quell'email
  (`user_id`);
- contenenti **quell'indirizzo email** in un messaggio scritto dall'utente
  (pre-filtro SQL, verifica esatta in PHP).

L'esportazione include metadati e messaggi di ogni conversazione; la
cancellazione elimina l'intera conversazione (anche le risposte del bot, che
possono riprendere i dati dell'utente) e avvisa che le copie già trasmesse al
provider AI o inviate via webhook/email restano fuori dalla portata del sito.
Le conversazioni **anonime** (solo identificativo di sessione del browser) non
sono riconducibili a un'email: per quelle vale la cancellazione automatica a
scadenza.

**Cancellazione dell'account.** Quando un utente WordPress viene eliminato
(`deleted_user`) — o, su multisite, rimosso da un sito (`remove_user_from_blog`,
eseguito anche da `wpmu_delete_user` su ogni sito) — le sue conversazioni e i
relativi messaggi vengono cancellati subito, a lotti, senza attendere la
scadenza. L'eventuale riassegnazione dei contenuti a un altro utente è ignorata
di proposito: le conversazioni sono dati personali, non contenuti del sito.

Doppio canale: `dbph_user_data_exporters` / `dbph_user_data_erasers` quando
l'Hub è presente (che le rilancia sui filtri core e registra lo storico DSAR),
altrimenti direttamente `wp_privacy_personal_data_exporters` / `_erasers`.

### Marker `SCB_DSAR_AVAILABLE`

La costante `SCB_DSAR_AVAILABLE` documenta che il plugin implementa il DSAR; il
filtro `dbph_dsar_available` (restituisce `true`) fa sì che la Privacy Policy
generata dall'Hub menzioni la procedura di accesso e cancellazione.

## Costi

Il costo dipende quasi interamente da **quanto è grande il sito**, non da quante
domande riceve: a ogni messaggio il chatbot invia al modello l'indice completo di
pagine, articoli, menu e categorie.

Per questo il plugin usa il **prompt caching**: la parte stabile del prompt
(identità + indice del sito) viene marcata come cacheabile e riprocessata una
volta sola invece che a ogni messaggio. Le riletture dalla cache costano circa un
decimo del prezzo pieno, quindi su un sito con molte pagine il risparmio è di un
ordine di grandezza.

La dashboard **Analytics** mostra il consumo reale di token e la percentuale di
input servita dalla cache: è il modo più affidabile per stimare la spesa sul
proprio sito, molto più di una tabella di previsione.

Per ridurre ulteriormente i costi: scegliere Claude Haiku 4.5 nelle impostazioni,
oppure deselezionare i Custom Post Type che il chatbot non deve conoscere. Pagine
e articoli sono indicizzati integralmente e non c'è un tetto: è una scelta
deliberata, perché un limite numerico farebbe sparire silenziosamente delle
pagine dalla conoscenza del chatbot senza che nessuno se ne accorga.

## Changelog

### 1.2.0 — Integrazione ecosistema DB privacy (registro trattamenti, destinatari, DSAR)

**Registro trattamenti (DB Privacy Hub / DB SEO Manager legacy):**
- Nuova classe `SCB_Privacy_Declarations` (`inc/class-privacy-declarations.php`)
  agganciata a `dbph_processing_register` e `dbseo_processing_register`.
- Dichiarazioni dinamiche: `scb_conversations` (chatbot operativo o dati ancora
  in archivio), `scb_ai_provider` (solo con API key; testo specifico per Anthropic
  o OpenAI), `scb_notifications` (webhook/email), `scb_voice_input` (dettatura).
  Voci inattive non generate: nessuna dichiarazione di trattamenti inesistenti.
- Inventario dichiarato: testo dei messaggi (o sua assenza con
  `scb_store_messages` off), `session_id` pseudonimo, `user_id` se loggato,
  `page_url`, timestamp, feedback; IP mai salvato (solo hash salato nei transient
  di rate limiting); al provider AI vanno testo, fino a 10 messaggi di storico,
  indice pubblico del sito e informazioni aggiuntive — non IP, sessione, utente.
- Conservazione dichiarata = `scb_retention_days` (default 90) con pulizia
  giornaliera `scb_cleanup_logs`, già presente dalla 1.1.0.
- Base giuridica presentata come scelta del titolare: art. 6.1.e per le scuole
  statali, art. 6.1.f con bilanciamento per paritarie/private; nuovo filtro
  `scb_privacy_legal_basis` per fissare il testo deciso con il DPO.
- Trasferimento extra-UE descritto con prudenza: Anthropic PBC / Anthropic
  Ireland, Limited e OpenAI, L.L.C. / OpenAI Ireland Ltd, garanzie SCC/DPF
  "da verificare nel contratto con il fornitore".

**Destinatari della Privacy Policy:**
- Filtro `dbph_policy_destinatari`: provider AI configurato come responsabile
  (art. 28) con paese Stati Uniti (extra-UE); host del webhook se configurato.

**DSAR (art. 15 e 17 GDPR):**
- Nuova classe `SCB_Privacy_DSAR` (`inc/class-privacy-dsar.php`), doppio canale
  `dbph_user_data_exporters`/`_erasers` (chiave `label`) e fallback
  `wp_privacy_personal_data_exporters`/`_erasers` saltato se esiste `DBPH_DSAR`.
- Match: conversazioni dell'account WordPress con quell'email (`user_id`) e
  conversazioni in cui l'utente ha scritto l'email (LIKE + verifica esatta).
  Anonime escluse per costruzione.
- Export paginato (50 conversazioni per chiamata, `done` sulla finestra SQL);
  erase a cursore per id con transient `scb_dsar_erase_*`, terminazione
  garantita anche con falsi positivi; cancella messaggi prima delle
  conversazioni (FK non garantita su MyISAM). Messaggi all'admin sulle copie
  presso il provider AI e sui canali di notifica.
- Cancellazione immediata di conversazioni e messaggi dell'utente alla
  cancellazione dell'account (`deleted_user`) e, su multisite, alla rimozione
  da un sito (`remove_user_from_blog`, con `switch_to_blog` sul sito giusto):
  lotti da 200, messaggi prima delle conversazioni, `$reassign` ignorato
  (dati personali, non contenuti). Niente più righe orfane fino alla retention.
- Costante `SCB_DSAR_AVAILABLE` e filtro `dbph_dsar_available` per la menzione
  della procedura nella Privacy Policy generata dall'Hub.

**Documentazione e manutenzione:**
- Blocco "Privacy capabilities" nell'header del file principale.
- README: sezione "Integrazione ecosistema DB privacy"; nota sull'input vocale
  (Web Speech API: alcuni browser inviano l'audio al proprio fornitore).
- `uninstall.php` rimuove anche i transient `scb_dsar_*`; `bin/make-pot.php`
  scansiona i nuovi file; il workflow di rilascio verifica la loro presenza nello
  ZIP.

**Input vocale disattivato per impostazione predefinita (privacy by design):**
- `scb_enable_voice_input` passa da default 1 a default 0: le nuove
  installazioni non mostrano il pulsante microfono finché l'amministratore non
  lo abilita (la Web Speech API in Chrome invia l'audio ai server di Google).
- Siti esistenti: chi aveva salvato l'impostazione mantiene il proprio valore;
  chi non l'aveva mai salvata riceve, all'aggiornamento, il vecchio default
  (attiva) memorizzato esplicitamente, così il comportamento non cambia in
  silenzio, più un avviso una tantum (solo `manage_options`, bacheca e pagine
  del plugin) con link all'impostazione e raccomandazione di rivederla se il
  chatbot è usato da minori. L'avviso sparisce se si chiude o se l'input vocale
  viene disattivato.
- Nuova opzione `scb_version` per le migrazioni tra versioni (eseguite su `init`
  e all'attivazione, quindi anche con l'aggiornamento dall'updater GitHub);
  `uninstall.php` rimuove `scb_version` e `scb_voice_notice_pending`.
- Descrizione dell'impostazione e dichiarazione `scb_voice_input` aggiornate
  (audio al fornitore del browser, disattivata di default).

**Nessun breaking change**: nessuna modifica allo schema; l'unico default
cambiato (input vocale) non altera i siti esistenti. Senza DB Privacy Hub le
dichiarazioni restano inerti.

### 1.1.0

**Conformità AI Act**
- Aggiunta informativa sulla natura artificiale dell'assistente, mostrata al
  primo contatto nel widget e nello shortcode (art. 50 comma 1, applicabile dal
  2 agosto 2026)
- Aggiunto badge "IA" nell'intestazione, non rimuovibile
- Il chatbot è ora istruito a dichiararsi sistema di IA se interrogato, e a non
  attribuirsi un nome proprio di persona
- I messaggi generati dall'IA sono marcati con `data-ai-generated="true"` nel DOM
- Testo dell'informativa personalizzabile via filtro `scb_ai_disclosure`, ma non
  eliminabile: un filtro che restituisce un valore vuoto viene ignorato

**Protezione dei dati**
- Il periodo di conservazione delle conversazioni è ora configurabile (era fisso
  a 90 giorni nel codice, mentre il README lasciava intendere il contrario)
- Aggiunta l'opzione per non salvare affatto il testo dei messaggi
- I campi dei Custom Post Type non vengono più trasmessi al servizio AI se non
  elencati esplicitamente: prima venivano inviati tutti quelli non prefissati con
  `_`, il che poteva includere dati non destinati a uscire dal sito
- Le chiavi del rate limiting usano `wp_hash()` invece di `md5()`: un hash non
  salato di un indirizzo IPv4 è invertibile per forza bruta
- Riscritta la sezione privacy del README, che dichiarava di non trasmettere dati
  personali al servizio AI — cosa non vera, dato che il messaggio dell'utente può
  contenerne e viene trasmesso
- Aggiunta la distinzione esplicita rispetto alle app educational "senza raccolta
  dati", che seguono un modello opposto

**Conformità legge 132/2025**
- Aggiunto avviso al primo uso con conferma esplicita, richiesta prima di poter
  scrivere il primo messaggio (art. 4 c. 4, soglia dei 14 anni)
- Aggiunta impostazione per l'URL dell'informativa privacy della scuola,
  richiamata nell'avviso
- L'avviso è disattivabile per le scuole senza utenti sotto i 14 anni; il testo
  può essere riproposto a tutti incrementando `scb_age_gate_version`

**Sicurezza**
- Corretto accesso non autorizzato allo storico conversazioni: ogni richiesta deve
  ora appartenere alla sessione che la effettua
- Corretto voto di feedback su messaggi di altri utenti (che generava anche
  webhook ed email indesiderate)
- Rate limiting non più aggirabile rigenerando il `session_id` lato client:
  aggiunto un tetto per indirizzo IP, più permissivo per non penalizzare le reti
  scolastiche sotto NAT (filtro `scb_ip_rate_multiplier`)

**Costi e modelli**
- Aggiunto prompt caching: la parte stabile del prompt viene riprocessata una
  volta sola invece che a ogni messaggio (filtro `scb_cache_ttl`)
- Il prompt è stato riorganizzato perché la cache fosse effettivamente
  utilizzabile anche cambiando lingua
- Modello Claude selezionabile dalle impostazioni (Opus 5 / Sonnet 5 / Haiku 4.5),
  modello OpenAI configurabile
- Nuovo riquadro "Consumo token" in Analytics con percentuale di cache utilizzata
- Limite token per risposta configurabile via filtro `scb_max_tokens`

**Manutenzione**
- Internazionalizzazione completa: tutte le stringhe dell'interfaccia sono ora
  traducibili, con catalogo `languages/school-chatbot.pot` (182 stringhe)
- Corretto il visualizzatore conversazioni in admin, che il controllo di
  proprietà delle sessioni aveva bloccato
- La pulizia automatica rimuove ora anche i messaggi rimasti senza conversazione
- Aggiunto aggiornamento automatico da GitHub Releases
- Aggiunto `uninstall.php`: alla disinstallazione non restano tabelle, opzioni,
  transient o cron
- Adottato il design system admin condiviso `db-admin-ui.css`
- Aggiunto workflow di rilascio: lo ZIP installabile viene costruito e allegato
  alla Release GitHub al push di un tag `v*`

### 1.0.0
- Primo rilascio pubblico, con i contenuti della 0.10.0

### 0.10.0
- Aggiunto input vocale (Speech-to-Text) con pulsante microfono
- Aggiunto pannello accessibilità utente (⚙️)
- Aggiunta modalità alto contrasto
- Aggiunto tema scuro
- Aggiunte dimensioni font configurabili (normale/grande/molto grande)
- Aggiunto toggle abilita/disabilita chatbot senza disattivare plugin
- Sintesi vocale (TTS) per le risposte
- Preferenze accessibilità salvate nel browser

### 0.9.0
- Aggiunta blocklist parole con 3 modalità
- Supporto multilingua (11 lingue) con rilevamento automatico
- Webhook per notifiche esterne (Slack, Discord, Teams)
- Notifiche email per eventi importanti
- Messaggi di benvenuto personalizzati per lingua

### 0.8.0 (interno)
- Widget chat flottante responsive
- Indicizzazione automatica pagine/post/menu/CPT
- Storico conversazioni su database
- Rate limiting configurabile
- Feedback pollice su/giù
- Domande suggerite
- Shortcode per embedding
- Dashboard Analytics
- Supporto Anthropic + OpenAI

## Supporto

Per problemi o suggerimenti:
- Sito: [www.davidebertolino.it](https://www.davidebertolino.it)

## Licenza

GPL v2 o successiva
