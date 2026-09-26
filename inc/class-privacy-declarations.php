<?php
/**
 * SCB_Privacy_Declarations — dichiarazione dei trattamenti al registro
 * privacy unificato dell'ecosistema DB (DB Privacy Hub).
 *
 * Aggancia:
 *  - `dbph_processing_register`  — registro trattamenti del DB Privacy Hub;
 *  - `dbseo_processing_register` — canale legacy (DB SEO Manager 1.2.x),
 *    stessa callback: l'Hub deduplica per `id`;
 *  - `dbph_policy_destinatari`   — destinatari mostrati nella Privacy Policy
 *    generata dall'Hub (fornitore AI configurato, host del webhook).
 *
 * Le dichiarazioni sono DINAMICHE: vengono lette le impostazioni reali del
 * plugin e si dichiara solo ciò che è effettivamente attivo (provider AI
 * configurato, conservazione del testo sì/no, storico, webhook, email,
 * input vocale). Senza Hub installato i filtri non vengono mai applicati e
 * questa classe non ha alcun effetto.
 *
 * Nota di metodo: i testi descrivono cosa fa il SOFTWARE. Le valutazioni
 * giuridiche che spettano al titolare (base giuridica, garanzie del
 * trasferimento extra-UE, qualifica del fornitore) sono formulate come
 * indicazioni da confermare, non come affermazioni: il plugin non conosce
 * né il tipo di istituto (statale o paritario) né il contratto stipulato
 * con il fornitore.
 *
 * @package SCB
 * @since   1.2.0
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('SCB_Privacy_Declarations')) {

    class SCB_Privacy_Declarations {

        /**
         * Aggancia i filtri dell'ecosistema privacy.
         */
        public static function init() {
            add_filter('dbph_processing_register', [__CLASS__, 'declare_processing'], 10, 1);
            add_filter('dbseo_processing_register', [__CLASS__, 'declare_processing'], 10, 1);
            add_filter('dbph_policy_destinatari', [__CLASS__, 'declare_destinatari'], 20, 1);
        }

        /* =================================================================
         * Registro trattamenti
         * ============================================================== */

        /**
         * Aggiunge al registro i trattamenti effettivamente attivi.
         *
         * @param array $register
         * @return array
         */
        public static function declare_processing($register) {
            if (!is_array($register)) {
                $register = [];
            }

            $f = self::analyze();

            // Nulla da dichiarare: chatbot disattivato e mai usato.
            if (!$f['enabled'] && !$f['has_stored_data']) {
                return $register;
            }

            // Senza API key il chatbot risponde col messaggio offline e non
            // crea conversazioni: la conservazione si dichiara solo se è
            // operativo o se in archivio restano dati.
            if ($f['provider_active'] || $f['has_stored_data']) {
                $register[] = self::build_storage_entry($f);
            }

            if ($f['provider_active']) {
                $register[] = self::build_ai_entry($f);
            }
            if ($f['has_notifications']) {
                $register[] = self::build_notifications_entry($f);
            }
            if ($f['voice_input']) {
                $register[] = self::build_voice_entry();
            }

            return $register;
        }

        /**
         * Conversazioni e messaggi nel database del sito.
         */
        private static function build_storage_entry($f) {
            if ($f['store_messages']) {
                $content = __('Testo delle domande scritte dall\'utente e delle risposte generate, che può contenere dati personali se l\'utente li inserisce (l\'avviso iniziale invita a non farlo).', 'school-chatbot');
            } else {
                $content = __('Il testo dei messaggi NON viene conservato (impostazione "Salva il testo dei messaggi" disattivata): restano righe vuote usate solo per i conteggi statistici.', 'school-chatbot');
            }

            $data = implode(' ', [
                $content,
                __('Metadati della conversazione: identificativo di sessione pseudonimo generato dal browser (salvato in localStorage come scb_session_id), ID dell\'account WordPress se l\'utente ha effettuato l\'accesso, URL della pagina di avvio, data e ora, eventuale feedback (pollice su/giù).', 'school-chatbot'),
                __('L\'indirizzo IP non viene salvato: è usato solo, sotto forma di hash con i sali di WordPress, in contatori di rate limiting che scadono dopo pochi secondi o minuti.', 'school-chatbot'),
                __('Nel browser restano inoltre la presa visione dell\'avviso iniziale e le preferenze di accessibilità (localStorage, strettamente funzionali).', 'school-chatbot'),
            ]);

            return [
                'id'             => 'scb_conversations',
                'label'          => __('Conversazioni con l\'assistente AI (School Chatbot AI)', 'school-chatbot'),
                'status'         => 'active',
                'purpose'        => __('Rispondere alle domande dei visitatori sul sito e sui servizi della scuola, mantenere il contesto della conversazione in corso, consentire alla scuola di verificare la qualità delle risposte (pagina Conversazioni, domande frequenti, feedback) e prevenire abusi del servizio.', 'school-chatbot'),
                'legal_basis'    => self::legal_basis_text(),
                'data_collected' => $data,
                'retention'      => sprintf(
                    /* translators: %d: giorni di conservazione configurati */
                    __('Conversazioni e messaggi cancellati automaticamente dopo %d giorni dall\'avvio (valore configurabile nelle impostazioni del plugin; pulizia giornaliera via WP-Cron, evento scb_cleanup_logs), e comunque cancellati alla cancellazione dell\'account WordPress dell\'utente, se la conversazione era collegata a un account. Alla disinstallazione del plugin le tabelle vengono eliminate.', 'school-chatbot'),
                    $f['retention_days']
                ),
                'transfers'      => __('Nessuno per la conservazione in sé: tabelle wp_scb_conversations e wp_scb_messages nel database WordPress del sito. L\'invio al fornitore AI è dichiarato come trattamento separato.', 'school-chatbot'),
            ];
        }

        /**
         * Invio dei messaggi al fornitore AI configurato (trasferimento extra-UE).
         */
        private static function build_ai_entry($f) {
            $p = self::provider_info($f['provider']);

            $sent = [
                __('Testo del messaggio scritto dall\'utente (dato personale ogni volta che l\'utente vi inserisce informazioni personali, proprie o di terzi, potenzialmente anche di minori).', 'school-chatbot'),
            ];
            if ($f['history_sent']) {
                $sent[] = sprintf(
                    /* translators: %d: numero massimo di messaggi precedenti inviati come contesto */
                    __('Fino a %d messaggi precedenti della stessa conversazione, come contesto.', 'school-chatbot'),
                    (int) SCB_MAX_HISTORY
                );
            }
            $sent[] = __('Istruzioni di sistema con l\'indice dei contenuti pubblici del sito (titoli, URL, estratti), le "Informazioni aggiuntive" inserite dalla scuola e i soli campi dei Custom Post Type elencati esplicitamente nelle impostazioni.', 'school-chatbot');
            $sent[] = __('NON vengono inviati: indirizzo IP del visitatore (la chiamata parte dal server del sito), identificativo di sessione, ID utente, URL della pagina.', 'school-chatbot');

            return [
                'id'             => 'scb_ai_provider',
                'label'          => sprintf(
                    /* translators: %s: nome del fornitore AI */
                    __('Generazione delle risposte tramite %s (School Chatbot AI)', 'school-chatbot'),
                    $p['brand']
                ),
                'status'         => 'active',
                'purpose'        => sprintf(
                    /* translators: %s: nome del fornitore AI */
                    __('Elaborare la domanda dell\'utente con il modello di intelligenza artificiale di %s per generare la risposta mostrata nel chatbot.', 'school-chatbot'),
                    $p['brand']
                ),
                'legal_basis'    => self::legal_basis_text(),
                'data_collected' => implode(' ', $sent),
                'retention'      => sprintf(
                    /* translators: %s: nome del fornitore AI */
                    __('Il plugin non controlla la conservazione presso il fornitore: i dati trasmessi via API sono conservati da %s secondo i propri termini commerciali e la documentazione sulla gestione dei dati API (di norma per un periodo limitato, ad esempio per il monitoraggio degli abusi). Periodo effettivo, eventuale esclusione dall\'addestramento dei modelli e opzioni di conservazione ridotta sono da verificare nel contratto con il fornitore.', 'school-chatbot'),
                    $p['brand']
                ),
                'transfers'      => $p['transfers'],
            ];
        }

        /**
         * Notifiche webhook ed email agli amministratori.
         */
        private static function build_notifications_entry($f) {
            $channels = [];
            if ($f['webhook_host'] !== '') {
                $channels[] = sprintf(
                    /* translators: %s: host dell'endpoint webhook */
                    __('webhook verso %s', 'school-chatbot'),
                    $f['webhook_host']
                );
            }
            if ($f['email_notifications']) {
                $channels[] = __('email alla casella indicata nelle impostazioni', 'school-chatbot');
            }

            $transfers = __('Casella email configurata (tramite il sistema di invio email del sito).', 'school-chatbot');
            if ($f['webhook_host'] !== '') {
                $transfers = sprintf(
                    /* translators: %s: host dell'endpoint webhook */
                    __('Gestore dell\'endpoint webhook %s (es. Slack, Discord, Microsoft Teams): se ha sede o server fuori dallo SEE si tratta di un trasferimento extra-UE da valutare a cura del titolare. Eventuali email transitano dal provider di posta del sito.', 'school-chatbot'),
                    $f['webhook_host']
                );
            }

            return [
                'id'             => 'scb_notifications',
                'label'          => __('Notifiche al personale su conversazioni del chatbot (School Chatbot AI)', 'school-chatbot'),
                'status'         => 'active',
                'purpose'        => sprintf(
                    /* translators: %s: canali di notifica attivi */
                    __('Avvisare il personale della scuola di nuove conversazioni, domande senza risposta, feedback negativi o messaggi bloccati, per migliorare il servizio. Canali attivi: %s.', 'school-chatbot'),
                    implode(', ', $channels)
                ),
                'legal_basis'    => self::legal_basis_text(),
                'data_collected' => __('Testo della domanda dell\'utente e, per i feedback negativi, della risposta del bot; URL della pagina; identificativo di conversazione (e di sessione per gli eventi blocklist inviati via webhook).', 'school-chatbot'),
                'retention'      => __('Fuori dal controllo del plugin: i messaggi restano nella casella email o nel canale di destinazione secondo le regole di conservazione di quel servizio, da definire a cura del titolare.', 'school-chatbot'),
                'transfers'      => $transfers,
            ];
        }

        /**
         * Input vocale tramite Web Speech API del browser.
         */
        private static function build_voice_entry() {
            return [
                'id'             => 'scb_voice_input',
                'label'          => __('Dettatura vocale dei messaggi (School Chatbot AI)', 'school-chatbot'),
                'status'         => 'active',
                'purpose'        => __('Consentire all\'utente, se lo sceglie premendo il pulsante microfono, di dettare la domanda invece di digitarla (funzione di accessibilità).', 'school-chatbot'),
                'legal_basis'    => self::legal_basis_text(),
                'data_collected' => __('Voce dell\'utente, trascritta in testo dal browser. Il plugin non riceve né conserva l\'audio: al sito arriva solo il testo trascritto, trattato come un normale messaggio.', 'school-chatbot'),
                'retention'      => __('Il plugin non conserva l\'audio. La conservazione presso il servizio di riconoscimento vocale dipende dal browser usato.', 'school-chatbot'),
                'transfers'      => __('Il riconoscimento vocale è svolto dal browser tramite la Web Speech API: alcuni browser (ad esempio Google Chrome) inviano l\'audio ai server del proprio fornitore, anche fuori dallo SEE. Il servizio è scelto dall\'utente tramite il proprio browser, non dal sito. La funzione è disattivata per impostazione predefinita (dalla versione 1.2.0) ed è attiva solo se abilitata nelle impostazioni del plugin.', 'school-chatbot'),
            ];
        }

        /* =================================================================
         * Destinatari (Privacy Policy generata dall'Hub)
         * ============================================================== */

        /**
         * Aggiunge il fornitore AI configurato e l'eventuale host webhook
         * all'elenco destinatari della Privacy Policy dell'Hub.
         *
         * Formato descrittore dell'Hub: name / description / country.
         *
         * @param array $destinatari
         * @return array
         */
        public static function declare_destinatari($destinatari) {
            if (!is_array($destinatari)) {
                $destinatari = [];
            }

            $f = self::analyze();

            if ($f['provider_active']) {
                $p = self::provider_info($f['provider']);
                $destinatari[] = [
                    'name'        => $p['brand'],
                    'description' => $p['destinatario'],
                    'country'     => $p['country'],
                ];
            }

            if ($f['enabled'] && $f['webhook_host'] !== '') {
                $destinatari[] = [
                    'name'        => $f['webhook_host'],
                    'description' => sprintf(
                        /* translators: %s: host dell'endpoint webhook */
                        __('Endpoint webhook configurato in School Chatbot AI: riceve il testo delle domande degli utenti per gli eventi di notifica selezionati (%s).', 'school-chatbot'),
                        $f['webhook_host']
                    ),
                    'country'     => __('Da verificare in base alla giurisdizione del provider.', 'school-chatbot'),
                ];
            }

            return $destinatari;
        }

        /* =================================================================
         * Helper
         * ============================================================== */

        /**
         * Legge la configurazione reale del plugin.
         *
         * @return array
         */
        private static function analyze() {
            $enabled   = (bool) get_option('scb_enabled', 1);
            $provider  = get_option('scb_api_provider', 'anthropic') === 'openai' ? 'openai' : 'anthropic';
            $has_key   = (string) get_option('scb_api_key', '') !== '';
            $store     = (bool) get_option('scb_store_messages', 1);

            $webhook_url    = (string) get_option('scb_webhook_url', '');
            $webhook_events = get_option('scb_webhook_events', []);
            $webhook_host   = '';
            if ($webhook_url !== '' && is_array($webhook_events) && !empty($webhook_events)) {
                $webhook_host = (string) wp_parse_url($webhook_url, PHP_URL_HOST);
            }
            $email_notifications = (bool) get_option('scb_email_notifications', 0);

            return [
                'enabled'             => $enabled,
                'provider'            => $provider,
                'provider_active'     => $enabled && $has_key,
                'store_messages'      => $store,
                'history_sent'        => $store && (bool) get_option('scb_enable_history', 1),
                'retention_days'      => max(1, intval(get_option('scb_retention_days', 90))),
                'webhook_host'        => $webhook_host,
                'email_notifications' => $email_notifications,
                'has_notifications'   => $enabled && ($webhook_host !== '' || $email_notifications),
                'voice_input'         => $enabled && (bool) get_option('scb_enable_voice_input', 0),
                'has_stored_data'     => self::has_stored_conversations(),
            ];
        }

        /**
         * Esistono conversazioni ancora nel periodo di conservazione?
         * Con il chatbot disattivato la conservazione prosegue finché la
         * pulizia automatica non le elimina: va quindi ancora dichiarata.
         */
        private static function has_stored_conversations() {
            global $wpdb;
            $table = $wpdb->prefix . 'scb_conversations';

            $suppress = $wpdb->suppress_errors(true);
            $found    = $wpdb->get_var("SELECT id FROM $table LIMIT 1");
            $wpdb->suppress_errors($suppress);

            return !empty($found);
        }

        /**
         * Base giuridica: dipende dalla natura dell'istituto, che il plugin
         * non conosce. Presentiamo le due ipotesi corrette e lasciamo la
         * scelta al titolare. Personalizzabile via filtro `scb_privacy_legal_basis`.
         *
         * @return string
         */
        private static function legal_basis_text() {
            $default = __('Da confermare a cura del titolare, sentito il DPO. Per le istituzioni scolastiche statali: esecuzione di un compito di interesse pubblico (art. 6.1.e GDPR e art. 2-ter D.Lgs. 196/2003), nell\'ambito dell\'informazione e comunicazione istituzionale verso studenti e famiglie; le autorità pubbliche non possono fondare sul legittimo interesse i trattamenti svolti nell\'esecuzione dei propri compiti (art. 6.1, secondo comma). Per le scuole paritarie e gli enti privati: legittimo interesse del titolare a offrire un servizio informativo richiesto dall\'utente (art. 6.1.f GDPR), previa valutazione di bilanciamento che tenga conto della possibile presenza di minori. L\'uso è volontario: chi non vuole può rivolgersi alla segreteria per i canali ordinari.', 'school-chatbot');

            $text = apply_filters('scb_privacy_legal_basis', $default);

            return (is_string($text) && trim($text) !== '') ? $text : $default;
        }

        /**
         * Descrizione del fornitore AI. Formulazioni prudenti: entità
         * contraente e garanzie vanno verificate nel contratto effettivo.
         *
         * @param string $provider 'anthropic' | 'openai'
         * @return array
         */
        private static function provider_info($provider) {
            $guarantees = __('Garanzie per il trasferimento (Clausole Contrattuali Standard incluse nel DPA del fornitore e/o adesione al EU-U.S. Data Privacy Framework, verificabile su dataprivacyframework.gov): da verificare nel contratto con il fornitore.', 'school-chatbot');

            if ($provider === 'openai') {
                return [
                    'brand'        => 'OpenAI',
                    'country'      => __('Stati Uniti (extra-UE); per i clienti dello SEE il contraente è di norma nell\'Unione (Irlanda), ma il trattamento può avvenire negli USA — da verificare nel contratto', 'school-chatbot'),
                    'transfers'    => __('OpenAI, L.L.C. (Stati Uniti). Per i clienti con sede nello SEE il contraente dei servizi API è di norma OpenAI Ireland Ltd (Irlanda); i dati possono comunque essere trattati negli Stati Uniti, salvo eventuale opzione di residenza dei dati in Europa, ove disponibile e attivata sul progetto API. Trasferimento verso paese terzo ai sensi del Capo V GDPR.', 'school-chatbot') . ' ' . $guarantees,
                    'destinatario' => __('Fornitore del modello di intelligenza artificiale usato dal chatbot (OpenAI, L.L.C.; per i clienti dello SEE di norma OpenAI Ireland Ltd). Riceve il testo delle domande degli utenti e il contesto necessario a generare la risposta, trattandoli per conto del titolare come responsabile del trattamento (art. 28 GDPR) in base ai termini commerciali API e al DPA del fornitore, da sottoscrivere e verificare a cura del titolare. Garanzie per il trasferimento extra-UE (SCC e/o Data Privacy Framework) da verificare nel contratto.', 'school-chatbot'),
                ];
            }

            return [
                'brand'        => 'Anthropic',
                'country'      => __('Stati Uniti (extra-UE); per i clienti dello SEE il contraente è di norma nell\'Unione (Irlanda), ma il trattamento può avvenire negli USA — da verificare nel contratto', 'school-chatbot'),
                'transfers'    => __('Anthropic PBC (Stati Uniti). Per i clienti con sede nello SEE il contraente dei servizi commerciali/API è di norma Anthropic Ireland, Limited (Irlanda); i dati sono comunque trattati anche negli Stati Uniti. Trasferimento verso paese terzo ai sensi del Capo V GDPR.', 'school-chatbot') . ' ' . $guarantees,
                'destinatario' => __('Fornitore del modello di intelligenza artificiale usato dal chatbot (Anthropic PBC; per i clienti dello SEE di norma Anthropic Ireland, Limited). Riceve il testo delle domande degli utenti e il contesto necessario a generare la risposta, trattandoli per conto del titolare come responsabile del trattamento (art. 28 GDPR) in base ai termini commerciali API e al DPA del fornitore, da sottoscrivere e verificare a cura del titolare. Garanzie per il trasferimento extra-UE (SCC e/o Data Privacy Framework) da verificare nel contratto.', 'school-chatbot'),
            ];
        }
    }
}
