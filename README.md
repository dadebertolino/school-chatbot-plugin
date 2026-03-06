# School Chatbot AI - Plugin WordPress

**Versione:** 0.10.0  
**Autore:** Davide "the Prof." Bertolino  
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
- ✅ Dashboard Analytics

## Installazione

1. Carica la cartella `school-chatbot-plugin` in `/wp-content/plugins/`
2. Attiva il plugin da WordPress Admin → Plugin
3. Vai in **School Chatbot → Impostazioni**
4. Inserisci la tua API key (Anthropic o OpenAI)
5. Configura le opzioni desiderate

## Configurazione

### API Key

Il plugin supporta due provider:

| Provider | Modello | Ottieni API Key |
|----------|---------|-----------------|
| Anthropic (Claude) | claude-sonnet-4 | [console.anthropic.com](https://console.anthropic.com/) |
| OpenAI (GPT) | gpt-4o-mini | [platform.openai.com](https://platform.openai.com/) |

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
- Grafico conversazioni per giorno
- Domande più frequenti

### Conversazioni
- Lista di tutte le conversazioni
- Visualizzatore dettagliato per ogni conversazione
- Paginazione

## Requisiti

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.7+
- Connessione HTTPS (consigliata)

## Privacy e GDPR

Il plugin:
- Non trasmette dati personali alle API AI (solo il messaggio)
- Salva le conversazioni localmente nel database WordPress
- Permette la cancellazione automatica dopo 90 giorni
- Non utilizza cookie di tracciamento

**Raccomandazioni:**
1. Aggiungi informativa privacy nel widget
2. Non richiedere dati personali via chat
3. Verifica compliance con il DPO della scuola

## Costi Stimati

Con utilizzo moderato (100 conversazioni/giorno, 3 messaggi/conversazione):

| Provider | Modello | Costo mensile stimato |
|----------|---------|----------------------|
| Anthropic | Claude Sonnet | ~$5-10 |
| OpenAI | GPT-4o-mini | ~$3-5 |

## Changelog

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
