<?php
/**
 * SCB_Privacy_DSAR — diritto di accesso (art. 15 GDPR) e di cancellazione
 * (art. 17 GDPR) sulle conversazioni del chatbot, tramite gli strumenti
 * nativi di WordPress (Strumenti → Esporta / Cancella dati personali).
 *
 * Quando una conversazione è riconducibile a un indirizzo email:
 *  1. Collegamento all'account — la conversazione è stata avviata da un
 *     utente che aveva effettuato l'accesso: `user_id` coincide con
 *     l'account WordPress che ha quell'email. È il caso tipico dei siti
 *     scolastici con area riservata (studenti, famiglie, personale).
 *  2. Email citata nel testo — un messaggio scritto dall'utente (role
 *     'user') contiene esattamente l'indirizzo richiesto. Pre-filtro SQL
 *     con LIKE, verifica esatta in PHP (confini dell'indirizzo) per
 *     evitare falsi positivi come "mario@x.it" dentro "altro.mario@x.it".
 *
 * Le conversazioni anonime (solo `session_id` generato dal browser, nessun
 * account e nessuna email nel testo) NON sono riconducibili a un'email e
 * restano fuori: per esse vale la cancellazione automatica a scadenza.
 *
 * Alla cancellazione dell'account WordPress (`deleted_user`; su multisite
 * anche `remove_user_from_blog`) le conversazioni di quell'utente vengono
 * eliminate subito, senza attendere la scadenza della conservazione.
 *
 * Doppio canale, come da contratto dell'ecosistema DB privacy:
 *  - `dbph_user_data_exporters` / `dbph_user_data_erasers` (chiave `label`)
 *    quando è installato DB Privacy Hub, che le rilancia sui filtri core;
 *  - `wp_privacy_personal_data_exporters` / `_erasers` (chiavi
 *    `*_friendly_name`) come fallback, saltato se esiste `DBPH_DSAR` per
 *    evitare la doppia registrazione.
 *
 * @package SCB
 * @since   1.2.0
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('SCB_Privacy_DSAR')) {

    class SCB_Privacy_DSAR {

        /** Chiave di registrazione dell'exporter/eraser. */
        const KEY = 'school-chatbot';

        /** Conversazioni esaminate per ogni chiamata. */
        const BATCH_SIZE = 50;

        /** Tetto di batch per singola chiamata dell'eraser (50 x 50 = 2500 candidati). */
        const ERASER_MAX_BATCHES = 50;

        /** Conversazioni eliminate per lotto alla cancellazione di un account. */
        const DELETE_BATCH_SIZE = 200;

        /**
         * Aggancia i due canali e segnala all'Hub che il plugin gestisce DSAR.
         */
        public static function init() {
            // Canale primario: filtri dell'Hub.
            add_filter('dbph_user_data_exporters', [__CLASS__, 'register_exporter_via_hub']);
            add_filter('dbph_user_data_erasers', [__CLASS__, 'register_eraser_via_hub']);

            // Fallback: filtri core di WordPress, attivi solo senza Hub.
            add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'register_exporter']);
            add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'register_eraser']);

            // Il prefisso SCB non è tra i marker noti all'Hub: la Privacy
            // Policy generata menziona la procedura DSAR grazie a questo filtro.
            add_filter('dbph_dsar_available', '__return_true');

            // Cancellazione dell'account: le conversazioni sono dati personali,
            // non contenuti da riassegnare, quindi $reassign è ignorato.
            add_action('deleted_user', [__CLASS__, 'on_deleted_user'], 10, 1);
            // Multisite: rimozione dell'utente da un sito (anche per effetto
            // di wpmu_delete_user, che la esegue su ogni sito dell'utente).
            add_action('remove_user_from_blog', [__CLASS__, 'on_remove_user_from_blog'], 10, 2);
        }

        /* =================================================================
         * Cancellazione account
         * ============================================================== */

        /**
         * Hook `deleted_user` ($id, $reassign, $user): cancella le
         * conversazioni dell'utente sul sito corrente. $reassign ignorato.
         *
         * @param int $user_id
         */
        public static function on_deleted_user($user_id) {
            self::delete_user_conversations((int) $user_id);
        }

        /**
         * Hook `remove_user_from_blog` ($user_id, $blog_id, $reassign).
         * WordPress lo esegue PRIMA di switch_to_blog(): il sito corrente può
         * non essere $blog_id, quindi si passa esplicitamente al sito giusto.
         *
         * @param int $user_id
         * @param int $blog_id
         */
        public static function on_remove_user_from_blog($user_id, $blog_id = 0) {
            $blog_id  = (int) $blog_id;
            $switched = false;

            if (is_multisite() && $blog_id > 0 && $blog_id !== get_current_blog_id()) {
                switch_to_blog($blog_id);
                $switched = true;
            }

            self::delete_user_conversations((int) $user_id);

            if ($switched) {
                restore_current_blog();
            }
        }

        /**
         * Elimina a lotti conversazioni e messaggi di un user_id sul sito
         * corrente: prima i messaggi (la FK ON DELETE CASCADE non è garantita
         * su MyISAM), poi le conversazioni.
         *
         * @param int $user_id
         * @return int conversazioni eliminate
         */
        public static function delete_user_conversations($user_id) {
            $user_id = (int) $user_id;
            if ($user_id <= 0) {
                return 0;
            }

            global $wpdb;
            $table_conv = $wpdb->prefix . 'scb_conversations';
            $table_msg  = $wpdb->prefix . 'scb_messages';

            // Tabelle assenti (plugin mai attivato su questo sito della rete):
            // nessun errore a video, il ciclo termina al primo giro.
            $suppress = $wpdb->suppress_errors(true);
            $removed  = 0;

            do {
                $ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM $table_conv WHERE user_id = %d ORDER BY id ASC LIMIT %d",
                    $user_id,
                    self::DELETE_BATCH_SIZE
                ));
                $ids = array_map('intval', (array) $ids);

                if (empty($ids)) {
                    break;
                }

                $in = implode(',', array_fill(0, count($ids), '%d'));
                $wpdb->query($wpdb->prepare("DELETE FROM $table_msg WHERE conversation_id IN ($in)", $ids));
                $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table_conv WHERE id IN ($in)", $ids));

                if (!$deleted) {
                    break; // Nessun progresso: evita un ciclo infinito.
                }
                $removed += (int) $deleted;
            } while (count($ids) === self::DELETE_BATCH_SIZE);

            $wpdb->suppress_errors($suppress);

            return $removed;
        }

        private static function label() {
            return __('School Chatbot AI — Conversazioni', 'school-chatbot');
        }

        /* =================================================================
         * Registrazione — canale Hub
         * ============================================================== */

        public static function register_exporter_via_hub($exporters) {
            if (!is_array($exporters)) {
                $exporters = [];
            }
            $exporters[self::KEY] = [
                'label'    => self::label(),
                'callback' => [__CLASS__, 'exporter_callback'],
            ];
            return $exporters;
        }

        public static function register_eraser_via_hub($erasers) {
            if (!is_array($erasers)) {
                $erasers = [];
            }
            $erasers[self::KEY] = [
                'label'    => self::label(),
                'callback' => [__CLASS__, 'eraser_callback'],
            ];
            return $erasers;
        }

        /* =================================================================
         * Registrazione — fallback core WordPress
         * ============================================================== */

        public static function register_exporter($exporters) {
            if (!is_array($exporters)) {
                $exporters = [];
            }
            if (class_exists('DBPH_DSAR')) {
                return $exporters; // L'Hub registra già tramite dbph_*.
            }
            $exporters[self::KEY] = [
                'exporter_friendly_name' => self::label(),
                'callback'               => [__CLASS__, 'exporter_callback'],
            ];
            return $exporters;
        }

        public static function register_eraser($erasers) {
            if (!is_array($erasers)) {
                $erasers = [];
            }
            if (class_exists('DBPH_DSAR')) {
                return $erasers;
            }
            $erasers[self::KEY] = [
                'eraser_friendly_name' => self::label(),
                'callback'             => [__CLASS__, 'eraser_callback'],
            ];
            return $erasers;
        }

        /* =================================================================
         * Callback (condivise dai due canali)
         * ============================================================== */

        /**
         * Exporter: una voce per conversazione, con metadati e messaggi.
         *
         * @param string $email_address
         * @param int    $page
         * @return array { data: array, done: bool }
         */
        public static function exporter_callback($email_address, $page = 1) {
            $email = strtolower(trim((string) $email_address));
            $page  = max(1, (int) $page);

            if (!is_email($email)) {
                return ['data' => [], 'done' => true];
            }

            $user_id    = self::user_id_for_email($email);
            $candidates = self::fetch_candidates($email, $user_id, 0, self::BATCH_SIZE, ($page - 1) * self::BATCH_SIZE);

            $data = [];
            foreach ($candidates as $conv) {
                $messages = self::get_messages((int) $conv->id);
                $link     = self::match_type($conv, $messages, $email, $user_id);
                if ($link === '') {
                    continue; // Falso positivo del pre-filtro LIKE.
                }
                $data[] = [
                    'group_id'    => 'school-chatbot-conversations',
                    'group_label' => self::label(),
                    'item_id'     => 'scb-conversation-' . (int) $conv->id,
                    'data'        => self::format_conversation($conv, $messages, $link),
                ];
            }

            return [
                'data' => $data,
                // Paginazione sulla finestra SQL, non sulle righe filtrate.
                'done' => count($candidates) < self::BATCH_SIZE,
            ];
        }

        /**
         * Eraser: elimina per intero le conversazioni riconducibili all'email
         * (metadati e tutti i messaggi, anche le risposte del bot, che possono
         * riprendere i dati scritti dall'utente).
         *
         * I falsi positivi del pre-filtro restano nella tabella: per garantire
         * la terminazione si scorre per id con un cursore, conservato tra una
         * chiamata e l'altra in un transient legato alla richiesta.
         *
         * @param string $email_address
         * @param int    $page
         * @return array { items_removed: int, items_retained: int, messages: array, done: bool }
         */
        public static function eraser_callback($email_address, $page = 1) {
            $email = strtolower(trim((string) $email_address));
            $page  = max(1, (int) $page);

            if (!is_email($email)) {
                return ['items_removed' => 0, 'items_retained' => 0, 'messages' => [], 'done' => true];
            }

            global $wpdb;
            $table_conv = $wpdb->prefix . 'scb_conversations';
            $table_msg  = $wpdb->prefix . 'scb_messages';

            $cursor_key = 'scb_dsar_erase_' . wp_hash($email);
            $cursor     = ($page === 1) ? 0 : (int) get_transient($cursor_key);

            $user_id = self::user_id_for_email($email);
            $removed = 0;
            $done    = false;

            for ($batch = 0; $batch < self::ERASER_MAX_BATCHES; $batch++) {
                $candidates = self::fetch_candidates($email, $user_id, $cursor, self::BATCH_SIZE, 0);

                foreach ($candidates as $conv) {
                    $cursor = max($cursor, (int) $conv->id);
                    if (self::match_type($conv, self::get_messages((int) $conv->id), $email, $user_id) === '') {
                        continue;
                    }
                    // Prima i messaggi: la FK ON DELETE CASCADE non è garantita (MyISAM).
                    $wpdb->delete($table_msg, ['conversation_id' => (int) $conv->id], ['%d']);
                    if ($wpdb->delete($table_conv, ['id' => (int) $conv->id], ['%d'])) {
                        $removed++;
                    }
                }

                if (count($candidates) < self::BATCH_SIZE) {
                    $done = true;
                    break;
                }
            }

            if ($done) {
                delete_transient($cursor_key);
            } else {
                set_transient($cursor_key, $cursor, HOUR_IN_SECONDS);
            }

            $messages = [];
            if ($removed > 0) {
                $messages[] = __('School Chatbot AI: le copie dei messaggi già trasmesse al fornitore del servizio AI sono soggette ai termini di conservazione del fornitore e non possono essere cancellate dal sito.', 'school-chatbot');
                if ((string) get_option('scb_webhook_url', '') !== '' || get_option('scb_email_notifications', 0)) {
                    $messages[] = __('School Chatbot AI: eventuali notifiche già inviate via webhook o email contenenti questi messaggi vanno rimosse manualmente dai rispettivi canali.', 'school-chatbot');
                }
            }

            return [
                'items_removed'  => $removed,
                'items_retained' => 0,
                'messages'       => $messages,
                'done'           => $done,
            ];
        }

        /* =================================================================
         * Helper
         * ============================================================== */

        /**
         * ID dell'account WordPress con quell'email (0 se non esiste).
         */
        private static function user_id_for_email($email) {
            $user = get_user_by('email', $email);
            return $user ? (int) $user->ID : 0;
        }

        /**
         * Conversazioni candidate: dell'account (user_id) o con un messaggio
         * utente che contiene l'email (pre-filtro LIKE, case-insensitive).
         *
         * @param string $email
         * @param int    $user_id  0 = nessun account: il ramo user_id non trova nulla
         *                         (le conversazioni anonime hanno user_id NULL).
         * @param int    $after_id solo id maggiori (cursore dell'eraser)
         * @param int    $limit
         * @param int    $offset
         * @return array
         */
        private static function fetch_candidates($email, $user_id, $after_id, $limit, $offset) {
            global $wpdb;
            $table_conv = $wpdb->prefix . 'scb_conversations';
            $table_msg  = $wpdb->prefix . 'scb_messages';

            $suppress = $wpdb->suppress_errors(true);
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT c.id, c.session_id, c.user_id, c.created_at, c.updated_at, c.page_url
                 FROM $table_conv c
                 WHERE c.id > %d
                   AND (
                        c.user_id = %d
                        OR c.id IN (
                            SELECT m.conversation_id FROM $table_msg m
                            WHERE m.role = 'user' AND LOWER(m.content) LIKE %s
                        )
                   )
                 ORDER BY c.id ASC
                 LIMIT %d OFFSET %d",
                (int) $after_id,
                (int) $user_id,
                '%' . $wpdb->esc_like($email) . '%',
                (int) $limit,
                (int) $offset
            ));
            $wpdb->suppress_errors($suppress);

            return is_array($rows) ? $rows : [];
        }

        private static function get_messages($conversation_id) {
            global $wpdb;
            $table_msg = $wpdb->prefix . 'scb_messages';

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, role, content, created_at, feedback FROM $table_msg WHERE conversation_id = %d ORDER BY id ASC",
                $conversation_id
            ));

            return is_array($rows) ? $rows : [];
        }

        /**
         * Perché la conversazione appartiene all'interessato:
         * 'account', 'email' oppure '' (nessun collegamento reale).
         */
        private static function match_type($conv, $messages, $email, $user_id) {
            if ($user_id > 0 && (int) $conv->user_id === $user_id) {
                return 'account';
            }

            // Confini dell'indirizzo: né caratteri di local-part prima, né
            // caratteri di dominio dopo (un punto finale di frase è ammesso).
            $pattern = '/(?<![a-z0-9._%+\-])' . preg_quote($email, '/') . '(?![a-z0-9\-]|\.[a-z0-9])/i';
            foreach ($messages as $m) {
                if ($m->role === 'user' && preg_match($pattern, (string) $m->content)) {
                    return 'email';
                }
            }

            return '';
        }

        /**
         * Coppie name/value per il file di esportazione di WordPress.
         */
        private static function format_conversation($conv, $messages, $link) {
            $out = [
                ['name' => __('Conversazione', 'school-chatbot'), 'value' => '#' . (int) $conv->id],
                [
                    'name'  => __('Collegamento all\'interessato', 'school-chatbot'),
                    'value' => $link === 'account'
                        ? __('Avviata con l\'account utente associato a questo indirizzo email', 'school-chatbot')
                        : __('L\'indirizzo email compare nel testo di un messaggio', 'school-chatbot'),
                ],
                ['name' => __('Identificativo di sessione', 'school-chatbot'), 'value' => (string) $conv->session_id],
                ['name' => __('Avviata il', 'school-chatbot'), 'value' => (string) $conv->created_at],
                ['name' => __('Ultima attività', 'school-chatbot'), 'value' => (string) $conv->updated_at],
                ['name' => __('Pagina di avvio', 'school-chatbot'), 'value' => (string) $conv->page_url],
            ];

            foreach ($messages as $m) {
                $role    = $m->role === 'user' ? __('Utente', 'school-chatbot') : __('Assistente AI', 'school-chatbot');
                $content = (string) $m->content !== '' ? (string) $m->content : __('(testo non conservato)', 'school-chatbot');
                if ($m->feedback !== null) {
                    $content .= ' [' . ((int) $m->feedback === 1 ? __('feedback: utile', 'school-chatbot') : __('feedback: non utile', 'school-chatbot')) . ']';
                }
                $out[] = [
                    'name'  => $role . ' — ' . $m->created_at,
                    'value' => $content,
                ];
            }

            return $out;
        }
    }
}
