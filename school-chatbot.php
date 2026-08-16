<?php
/**
 * Plugin Name: School Chatbot AI
 * Plugin URI: https://www.davidebertolino.it
 * Description: Chatbot AI per siti scolastici - risponde a domande su orari, pagine e navigazione
 * Version: 1.1.0
 * Author: Davide Bertolino
 * Author URI: https://www.davidebertolino.it
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: school-chatbot
 * Domain Path: /languages
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

define('SCB_VERSION', '1.1.0');
define('SCB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SCB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SCB_MAX_HISTORY', 10); // Messaggi di contesto per conversazione

// Aggiornamenti automatici da GitHub Releases
require_once SCB_PLUGIN_DIR . 'inc/class-updater.php';
new DB_GitHub_Updater(__FILE__, 'dadebertolino', 'school-chatbot-plugin');

class School_Chatbot {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Traduzioni
        add_action('init', [$this, 'load_textdomain']);

        // Admin
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // Frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('wp_footer', [$this, 'render_chatbot_widget']);
        
        // AJAX
        add_action('wp_ajax_scb_chat', [$this, 'handle_chat_request']);
        add_action('wp_ajax_nopriv_scb_chat', [$this, 'handle_chat_request']);
        add_action('wp_ajax_scb_rebuild_index', [$this, 'rebuild_site_index']);
        add_action('wp_ajax_scb_get_history', [$this, 'get_conversation_history']);
        add_action('wp_ajax_nopriv_scb_get_history', [$this, 'get_conversation_history']);
        add_action('wp_ajax_scb_feedback', [$this, 'handle_feedback']);
        add_action('wp_ajax_nopriv_scb_feedback', [$this, 'handle_feedback']);
        
        // Shortcode
        add_shortcode('school_chatbot', [$this, 'render_inline_chatbot']);
        
        // Ricostruisci indice quando cambiano contenuti
        add_action('save_post', [$this, 'schedule_index_rebuild']);
        add_action('wp_update_nav_menu', [$this, 'schedule_index_rebuild']);
        
        // Cron per pulizia log
        add_action('scb_cleanup_logs', [$this, 'cleanup_old_logs']);
        if (!wp_next_scheduled('scb_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'scb_cleanup_logs');
        }
        
        // Attivazione/disattivazione
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }
    
    /**
     * Carica le traduzioni
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'school-chatbot',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * Attivazione plugin - crea tabelle
     */
    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabella conversazioni
        $table_conversations = $wpdb->prefix . 'scb_conversations';
        $sql1 = "CREATE TABLE IF NOT EXISTS $table_conversations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            page_url VARCHAR(500),
            INDEX idx_session (session_id),
            INDEX idx_created (created_at)
        ) $charset_collate;";
        
        // Tabella messaggi
        $table_messages = $wpdb->prefix . 'scb_messages';
        $sql2 = "CREATE TABLE IF NOT EXISTS $table_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NOT NULL,
            role ENUM('user', 'assistant') NOT NULL,
            content TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            feedback TINYINT DEFAULT NULL,
            INDEX idx_conversation (conversation_id),
            FOREIGN KEY (conversation_id) REFERENCES $table_conversations(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Tabella analytics
        $table_analytics = $wpdb->prefix . 'scb_analytics';
        $sql3 = "CREATE TABLE IF NOT EXISTS $table_analytics (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            date DATE NOT NULL,
            total_conversations INT DEFAULT 0,
            total_messages INT DEFAULT 0,
            avg_messages_per_conv FLOAT DEFAULT 0,
            positive_feedback INT DEFAULT 0,
            negative_feedback INT DEFAULT 0,
            UNIQUE KEY idx_date (date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
    }
    
    /**
     * Disattivazione plugin
     */
    public function deactivate() {
        wp_clear_scheduled_hook('scb_cleanup_logs');
    }
    
    /**
     * Menu amministrazione
     */
    public function add_admin_menu() {
        add_menu_page(
            __('School Chatbot', 'school-chatbot'),
            __('School Chatbot', 'school-chatbot'),
            'manage_options',
            'school-chatbot',
            [$this, 'render_admin_page'],
            'dashicons-format-chat',
            80
        );

        add_submenu_page(
            'school-chatbot',
            __('Impostazioni', 'school-chatbot'),
            __('Impostazioni', 'school-chatbot'),
            'manage_options',
            'school-chatbot',
            [$this, 'render_admin_page']
        );

        add_submenu_page(
            'school-chatbot',
            __('Analytics', 'school-chatbot'),
            __('Analytics', 'school-chatbot'),
            'manage_options',
            'school-chatbot-analytics',
            [$this, 'render_analytics_page']
        );

        add_submenu_page(
            'school-chatbot',
            __('Conversazioni', 'school-chatbot'),
            __('Conversazioni', 'school-chatbot'),
            'manage_options',
            'school-chatbot-conversations',
            [$this, 'render_conversations_page']
        );
    }

    /**
     * Design system admin condiviso, solo sulle pagine del plugin
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'school-chatbot') === false) {
            return;
        }

        wp_enqueue_style(
            'db-admin-ui',
            SCB_PLUGIN_URL . 'assets/css/db-admin-ui.css',
            [],
            SCB_VERSION
        );
    }

    /**
     * Registra impostazioni
     */
    public function register_settings() {
        // Stato chatbot
        register_setting('scb_settings', 'scb_enabled', ['default' => 1]);
        
        register_setting('scb_settings', 'scb_api_key');
        register_setting('scb_settings', 'scb_api_provider', ['default' => 'anthropic']);
        register_setting('scb_settings', 'scb_model_anthropic', ['default' => 'claude-opus-5']);
        register_setting('scb_settings', 'scb_model_openai', ['default' => 'gpt-4o-mini']);
        // Informativa al primo uso (L. 132/2025 art. 4 c. 4 — soglia 14 anni).
        // A differenza della disclosure IA, che si applica sempre ed è quindi non
        // rimovibile, questa è disattivabile: la soglia dei 14 anni non riguarda
        // un ITS o un CPIA, e imporla lì sarebbe attrito senza fondamento.
        register_setting('scb_settings', 'scb_age_gate_enabled', ['default' => 1]);
        register_setting('scb_settings', 'scb_privacy_url', ['default' => '']);
        register_setting('scb_settings', 'scb_store_messages', ['default' => 1]);
        register_setting('scb_settings', 'scb_retention_days', ['default' => 90]);
        register_setting('scb_settings', 'scb_cpt_meta_keys', ['default' => '']);

        register_setting('scb_settings', 'scb_custom_info');
        register_setting('scb_settings', 'scb_welcome_message', [
            'default' => __('Ciao! Sono l\'assistente virtuale della scuola. Come posso aiutarti?', 'school-chatbot')
        ]);
        register_setting('scb_settings', 'scb_primary_color', ['default' => '#2563eb']);
        register_setting('scb_settings', 'scb_position', ['default' => 'bottom-right']);
        register_setting('scb_settings', 'scb_enabled_cpt', ['default' => []]);
        register_setting('scb_settings', 'scb_rate_limit', ['default' => 20]);
        register_setting('scb_settings', 'scb_rate_window', ['default' => 60]);
        register_setting('scb_settings', 'scb_enable_feedback', ['default' => 1]);
        register_setting('scb_settings', 'scb_enable_history', ['default' => 1]);
        register_setting('scb_settings', 'scb_suggested_questions', ['default' => '']);
        register_setting('scb_settings', 'scb_offline_message', [
            'default' => __('Il chatbot è temporaneamente non disponibile. Contatta la segreteria.', 'school-chatbot')
        ]);
        
        // Blocklist
        register_setting('scb_settings', 'scb_blocklist_words', ['default' => '']);
        register_setting('scb_settings', 'scb_blocklist_action', ['default' => 'warn']);
        register_setting('scb_settings', 'scb_blocklist_message', [
            'default' => __('Il tuo messaggio contiene contenuti non appropriati. Per favore riformula la domanda.', 'school-chatbot')
        ]);
        
        // Multilingua
        register_setting('scb_settings', 'scb_default_language', ['default' => 'it']);
        register_setting('scb_settings', 'scb_enable_auto_language', ['default' => 1]);
        register_setting('scb_settings', 'scb_welcome_messages', ['default' => []]);
        
        // Webhook/Notifiche
        register_setting('scb_settings', 'scb_webhook_url', ['default' => '']);
        register_setting('scb_settings', 'scb_webhook_events', ['default' => []]);
        register_setting('scb_settings', 'scb_email_notifications', ['default' => 0]);
        register_setting('scb_settings', 'scb_notification_email', ['default' => '']);
        register_setting('scb_settings', 'scb_notify_unanswered', ['default' => 1]);
        register_setting('scb_settings', 'scb_notify_negative_feedback', ['default' => 1]);
        register_setting('scb_settings', 'scb_notify_blocklist', ['default' => 1]);
        
        // Accessibilità
        register_setting('scb_settings', 'scb_enable_tts', ['default' => 1]);
        register_setting('scb_settings', 'scb_tts_auto', ['default' => 0]);
        register_setting('scb_settings', 'scb_tts_rate', ['default' => '1']);
        register_setting('scb_settings', 'scb_enable_accessibility_panel', ['default' => 1]);
        register_setting('scb_settings', 'scb_default_font_size', ['default' => 'normal']);
        register_setting('scb_settings', 'scb_default_contrast', ['default' => 'normal']);
        register_setting('scb_settings', 'scb_enable_voice_input', ['default' => 1]);
    }
    
    /**
     * Pagina admin principale
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) return;
        
        $cpt_list = get_post_types(['public' => true, '_builtin' => false], 'objects');
        $enabled_cpt = get_option('scb_enabled_cpt', []);
        if (!is_array($enabled_cpt)) $enabled_cpt = [];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('School Chatbot - Impostazioni', 'school-chatbot'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('scb_settings'); ?>
                
                <div style="background:<?php echo get_option('scb_enabled', 1) ? '#d1fae5' : '#fee2e2'; ?>;border:1px solid <?php echo get_option('scb_enabled', 1) ? '#10b981' : '#ef4444'; ?>;border-radius:8px;padding:15px 20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <strong style="font-size:16px;"><?php echo get_option('scb_enabled', 1) ? esc_html__('✅ Chatbot ATTIVO', 'school-chatbot') : esc_html__('⏸️ Chatbot DISATTIVATO', 'school-chatbot'); ?></strong>
                        <p style="margin:5px 0 0;color:#666;"><?php echo get_option('scb_enabled', 1) ? esc_html__('Il widget è visibile ai visitatori del sito.', 'school-chatbot') : esc_html__('Il widget non è visibile ai visitatori del sito.', 'school-chatbot'); ?></p>
                    </div>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="checkbox" name="scb_enabled" value="1" <?php checked(get_option('scb_enabled', 1)); ?> style="width:20px;height:20px;">
                        <span><?php esc_html_e('Abilita chatbot', 'school-chatbot'); ?></span>
                    </label>
                </div>

                <h2 class="title"><?php esc_html_e('Configurazione API', 'school-chatbot'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Provider AI', 'school-chatbot'); ?></th>
                        <td>
                            <select name="scb_api_provider">
                                <option value="anthropic" <?php selected(get_option('scb_api_provider'), 'anthropic'); ?>>Anthropic (Claude)</option>
                                <option value="openai" <?php selected(get_option('scb_api_provider'), 'openai'); ?>>OpenAI (GPT)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('API Key', 'school-chatbot'); ?></th>
                        <td>
                            <input type="password" name="scb_api_key" value="<?php echo esc_attr(get_option('scb_api_key')); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('La chiave non viene mai mostrata per sicurezza.', 'school-chatbot'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Modello Claude', 'school-chatbot'); ?></th>
                        <td>
                            <select name="scb_model_anthropic">
                                <?php foreach ($this->get_anthropic_models() as $id => $label) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected(get_option('scb_model_anthropic', 'claude-opus-5'), $id); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Usato solo con provider Anthropic. Opus 5 è il più capace; Haiku 4.5 il più economico.', 'school-chatbot'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Modello OpenAI', 'school-chatbot'); ?></th>
                        <td>
                            <input type="text" name="scb_model_openai" value="<?php echo esc_attr(get_option('scb_model_openai', 'gpt-4o-mini')); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Usato solo con provider OpenAI. Default: gpt-4o-mini.', 'school-chatbot'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Aspetto', 'school-chatbot'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Messaggio di benvenuto', 'school-chatbot'); ?></th>
                        <td>
                            <input type="text" name="scb_welcome_message" value="<?php echo esc_attr(get_option('scb_welcome_message')); ?>" class="large-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Colore principale', 'school-chatbot'); ?></th>
                        <td>
                            <input type="color" name="scb_primary_color" value="<?php echo esc_attr(get_option('scb_primary_color', '#2563eb')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Posizione widget', 'school-chatbot'); ?></th>
                        <td>
                            <select name="scb_position">
                                <option value="bottom-right" <?php selected(get_option('scb_position'), 'bottom-right'); ?>><?php esc_html_e('Basso a destra', 'school-chatbot'); ?></option>
                                <option value="bottom-left" <?php selected(get_option('scb_position'), 'bottom-left'); ?>><?php esc_html_e('Basso a sinistra', 'school-chatbot'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Domande suggerite', 'school-chatbot'); ?></th>
                        <td>
                            <textarea name="scb_suggested_questions" rows="3" class="large-text" placeholder="<?php esc_attr_e('Una domanda per riga', 'school-chatbot'); ?>"><?php echo esc_textarea(get_option('scb_suggested_questions')); ?></textarea>
                            <p class="description"><?php esc_html_e('Domande mostrate come pulsanti cliccabili. Una per riga.', 'school-chatbot'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2 class="title"><?php esc_html_e('Contenuti indicizzati', 'school-chatbot'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Custom Post Types', 'school-chatbot'); ?></th>
                        <td>
                            <?php if (!empty($cpt_list)) : ?>
                                <?php foreach ($cpt_list as $cpt) : ?>
                                    <label style="display:block;margin-bottom:5px;">
                                        <input type="checkbox" name="scb_enabled_cpt[]" value="<?php echo esc_attr($cpt->name); ?>" 
                                            <?php checked(in_array($cpt->name, $enabled_cpt)); ?>>
                                        <?php echo esc_html($cpt->labels->name); ?> (<?php echo esc_html($cpt->name); ?>)
                                    </label>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <p class="description"><?php esc_html_e('Nessun custom post type registrato.', 'school-chatbot'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Informazioni aggiuntive', 'school-chatbot'); ?></th>
                        <td>
                            <textarea name="scb_custom_info" rows="8" class="large-text" placeholder="<?php esc_attr_e('Orari segreteria, contatti, regolamenti...', 'school-chatbot'); ?>"><?php echo esc_textarea(get_option('scb_custom_info')); ?></textarea>
                        </td>
                    </tr>
                </table>
                
                <h2 class="title"><?php esc_html_e('Rate Limiting', 'school-chatbot'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Limite messaggi', 'school-chatbot'); ?></th>
                        <td>
                            <input type="number" name="scb_rate_limit" value="<?php echo esc_attr(get_option('scb_rate_limit', 20)); ?>" min="1" max="100" style="width:80px">
                            <?php esc_html_e('messaggi ogni', 'school-chatbot'); ?>
                            <input type="number" name="scb_rate_window" value="<?php echo esc_attr(get_option('scb_rate_window', 60)); ?>" min="1" max="3600" style="width:80px">
                            <?php esc_html_e('secondi', 'school-chatbot'); ?>
                        </td>
                    </tr>
                </table>
                
                <h2 class="title"><?php esc_html_e('Funzionalità', 'school-chatbot'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Opzioni', 'school-chatbot'); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:8px;">
                                <input type="checkbox" name="scb_enable_feedback" value="1" <?php checked(get_option('scb_enable_feedback', 1)); ?>>
                                <?php esc_html_e('Abilita feedback (pollice su/giù)', 'school-chatbot'); ?>
                            </label>
                            <label style="display:block;">
                                <input type="checkbox" name="scb_enable_history" value="1" <?php checked(get_option('scb_enable_history', 1)); ?>>
                                <?php esc_html_e('Mantieni storico conversazione (migliora contesto)', 'school-chatbot'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Conservazione dei messaggi', 'school-chatbot'); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:8px;">
                                <input type="checkbox" name="scb_store_messages" value="1" <?php checked(get_option('scb_store_messages', 1)); ?>>
                                <?php esc_html_e('Salva il testo dei messaggi nel database', 'school-chatbot'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Disattivando il salvataggio restano solo i conteggi per le statistiche. Attenzione: senza storico il chatbot non ricorda i messaggi precedenti e risponde a ogni domanda isolatamente; la pagina Conversazioni e le domande frequenti restano vuote.', 'school-chatbot'); ?>
                            </p>
                            <p>
                                <label>
                                    <?php esc_html_e('Cancella le conversazioni dopo', 'school-chatbot'); ?>
                                    <input type="number" name="scb_retention_days" value="<?php echo esc_attr($this->get_retention_days()); ?>" min="1" max="3650" style="width:80px">
                                    <?php esc_html_e('giorni', 'school-chatbot'); ?>
                                </label>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Meta dei Custom Post Type', 'school-chatbot'); ?></th>
                        <td>
                            <textarea name="scb_cpt_meta_keys" rows="3" class="large-text" placeholder="<?php esc_attr_e('data_evento', 'school-chatbot'); ?>"><?php echo esc_textarea(get_option('scb_cpt_meta_keys', '')); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Nomi dei campi personalizzati da includere nell\'indice, uno per riga. Se vuoto non viene inviato nessun campo. Includi solo campi pubblici: quanto elencato qui viene trasmesso al servizio AI.', 'school-chatbot'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Informativa al primo uso', 'school-chatbot'); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:8px;">
                                <input type="checkbox" name="scb_age_gate_enabled" value="1" <?php checked(get_option('scb_age_gate_enabled', 1)); ?>>
                                <?php esc_html_e('Mostra l\'informativa e richiedi conferma prima del primo messaggio', 'school-chatbot'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('La legge 132/2025 richiede l\'autorizzazione di un genitore per l\'uso di sistemi di IA sotto i 14 anni. Disattiva questa opzione solo se la scuola non ha utenti sotto i 14 anni (es. ITS, CPIA, corsi per adulti): la responsabilità della scelta è dell\'istituto.', 'school-chatbot'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('URL informativa privacy', 'school-chatbot'); ?></th>
                        <td>
                            <input type="url" name="scb_privacy_url" value="<?php echo esc_attr(get_option('scb_privacy_url')); ?>" class="large-text" placeholder="https://www.scuola.edu.it/privacy">
                            <p class="description"><?php esc_html_e('Se compilato, viene mostrato un link all\'informativa della scuola dentro l\'avviso iniziale.', 'school-chatbot'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Messaggio offline', 'school-chatbot'); ?></th>
                        <td>
                            <input type="text" name="scb_offline_message" value="<?php echo esc_attr(get_option('scb_offline_message')); ?>" class="large-text">
                        </td>
                    </tr>
                </table>
                
                <h2 class="title"><?php esc_html_e('🚫 Blocklist Parole', 'school-chatbot'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Parole bloccate', 'school-chatbot'); ?></th>
                        <td>
                            <textarea name="scb_blocklist_words" rows="4" class="large-text" placeholder="<?php esc_attr_e('Una parola o frase per riga', 'school-chatbot'); ?>"><?php echo esc_textarea(get_option('scb_blocklist_words')); ?></textarea>
                            <p class="description"><?php esc_html_e('Messaggi contenenti queste parole verranno filtrati. Una per riga. Supporta anche frasi.', 'school-chatbot'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Azione', 'school-chatbot'); ?></th>
                        <td>
                            <select name="scb_blocklist_action">
                                <option value="warn" <?php selected(get_option('scb_blocklist_action'), 'warn'); ?>><?php esc_html_e('Mostra avviso e blocca invio', 'school-chatbot'); ?></option>
                                <option value="silent" <?php selected(get_option('scb_blocklist_action'), 'silent'); ?>><?php esc_html_e('Blocca silenziosamente', 'school-chatbot'); ?></option>
                                <option value="replace" <?php selected(get_option('scb_blocklist_action'), 'replace'); ?>><?php esc_html_e('Sostituisci con asterischi e invia', 'school-chatbot'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Messaggio blocco', 'school-chatbot'); ?></th>
                        <td>
                            <input type="text" name="scb_blocklist_message" value="<?php echo esc_attr(get_option('scb_blocklist_message')); ?>" class="large-text">
                        </td>
                    </tr>
                </table>
                
                <h2 class="title"><?php esc_html_e('🌍 Multilingua', 'school-chatbot'); ?></h2>
                <?php 
                $languages = [
                    'it' => 'Italiano',
                    'en' => 'English',
                    'es' => 'Español',
                    'fr' => 'Français',
                    'de' => 'Deutsch',
                    'pt' => 'Português',
                    'ar' => 'العربية',
                    'zh' => '中文',
                    'ro' => 'Română',
                    'uk' => 'Українська',
                    'sq' => 'Shqip',
                ];
                $welcome_messages = get_option('scb_welcome_messages', []);
                if (!is_array($welcome_messages)) $welcome_messages = [];
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Lingua predefinita', 'school-chatbot'); ?></th>
                        <td>
                            <select name="scb_default_language">
                                <?php foreach ($languages as $code => $name) : ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected(get_option('scb_default_language', 'it'), $code); ?>><?php echo esc_html($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Rilevamento automatico', 'school-chatbot'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="scb_enable_auto_language" value="1" <?php checked(get_option('scb_enable_auto_language', 1)); ?>>
                                <?php esc_html_e('Rileva lingua dal messaggio dell\'utente e rispondi nella stessa lingua', 'school-chatbot'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Messaggi di benvenuto', 'school-chatbot'); ?></th>
                        <td>
                            <?php foreach ($languages as $code => $name) : ?>
                                <div style="margin-bottom:8px;">
                                    <label style="display:inline-block;width:100px;"><?php echo esc_html($name); ?>:</label>
                                    <input type="text" name="scb_welcome_messages[<?php echo esc_attr($code); ?>]" 
                                           value="<?php echo esc_attr($welcome_messages[$code] ?? ''); ?>" 
                                           style="width:calc(100% - 110px);"
                                           placeholder="<?php echo $code === 'it' ? 'Ciao! Come posso aiutarti?' : ''; ?>">
                                </div>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e('Lascia vuoto per usare il messaggio predefinito tradotto automaticamente.', 'school-chatbot'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2 class="title"><?php esc_html_e('🔔 Webhook e Notifiche', 'school-chatbot'); ?></h2>
                <?php 
                $webhook_events = get_option('scb_webhook_events', []);
                if (!is_array($webhook_events)) $webhook_events = [];
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('URL Webhook', 'school-chatbot'); ?></th>
                        <td>
                            <input type="url" name="scb_webhook_url" value="<?php echo esc_attr(get_option('scb_webhook_url')); ?>" class="large-text" placeholder="https://hooks.slack.com/... | https://discord.com/api/webhooks/...">
                            <p class="description"><?php esc_html_e('Ricevi notifiche su Slack, Discord, Teams o qualsiasi servizio webhook.', 'school-chatbot'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Eventi webhook', 'school-chatbot'); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="scb_webhook_events[]" value="new_conversation" <?php checked(in_array('new_conversation', $webhook_events)); ?>>
                                <?php esc_html_e('Nuova conversazione', 'school-chatbot'); ?>
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="scb_webhook_events[]" value="negative_feedback" <?php checked(in_array('negative_feedback', $webhook_events)); ?>>
                                <?php esc_html_e('Feedback negativo', 'school-chatbot'); ?>
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="scb_webhook_events[]" value="blocklist_triggered" <?php checked(in_array('blocklist_triggered', $webhook_events)); ?>>
                                <?php esc_html_e('Parola bloccata rilevata', 'school-chatbot'); ?>
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="scb_webhook_events[]" value="unanswered" <?php checked(in_array('unanswered', $webhook_events)); ?>>
                                <?php esc_html_e('Domanda senza risposta (errore API)', 'school-chatbot'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Notifiche email', 'school-chatbot'); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:8px;">
                                <input type="checkbox" name="scb_email_notifications" value="1" <?php checked(get_option('scb_email_notifications')); ?>>
                                <?php esc_html_e('Abilita notifiche via email', 'school-chatbot'); ?>
                            </label>
                            <input type="email" name="scb_notification_email" value="<?php echo esc_attr(get_option('scb_notification_email', get_option('admin_email'))); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Notifica per', 'school-chatbot'); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="scb_notify_unanswered" value="1" <?php checked(get_option('scb_notify_unanswered', 1)); ?>>
                                <?php esc_html_e('Domande senza risposta', 'school-chatbot'); ?>
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="scb_notify_negative_feedback" value="1" <?php checked(get_option('scb_notify_negative_feedback', 1)); ?>>
                                <?php esc_html_e('Feedback negativi', 'school-chatbot'); ?>
                            </label>
                            <label style="display:block;">
                                <input type="checkbox" name="scb_notify_blocklist" value="1" <?php checked(get_option('scb_notify_blocklist', 1)); ?>>
                                <?php esc_html_e('Tentativi con parole bloccate', 'school-chatbot'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <h2 class="title"><?php esc_html_e('♿ Accessibilità', 'school-chatbot'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Pannello accessibilità', 'school-chatbot'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="scb_enable_accessibility_panel" value="1" <?php checked(get_option('scb_enable_accessibility_panel', 1)); ?>>
                                Mostra pulsante ⚙️ con opzioni per l'utente (font, contrasto)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Input vocale', 'school-chatbot'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="scb_enable_voice_input" value="1" <?php checked(get_option('scb_enable_voice_input', 1)); ?>>
                                <?php esc_html_e('Mostra pulsante microfono 🎤 per dettare messaggi', 'school-chatbot'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Usa il riconoscimento vocale del browser (richiede HTTPS).', 'school-chatbot'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Sintesi vocale (TTS)', 'school-chatbot'); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:8px;">
                                <input type="checkbox" name="scb_enable_tts" value="1" <?php checked(get_option('scb_enable_tts', 1)); ?>>
                                <?php esc_html_e('Mostra pulsante "Ascolta risposta" 🔊', 'school-chatbot'); ?>
                            </label>
                            <label style="display:block;">
                                <input type="checkbox" name="scb_tts_auto" value="1" <?php checked(get_option('scb_tts_auto', 0)); ?>>
                                <?php esc_html_e('Leggi automaticamente ogni risposta', 'school-chatbot'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Velocità lettura', 'school-chatbot'); ?></th>
                        <td>
                            <select name="scb_tts_rate">
                                <option value="0.7" <?php selected(get_option('scb_tts_rate', '1'), '0.7'); ?>><?php esc_html_e('Lenta', 'school-chatbot'); ?></option>
                                <option value="0.85" <?php selected(get_option('scb_tts_rate', '1'), '0.85'); ?>><?php esc_html_e('Moderata', 'school-chatbot'); ?></option>
                                <option value="1" <?php selected(get_option('scb_tts_rate', '1'), '1'); ?>><?php esc_html_e('Normale', 'school-chatbot'); ?></option>
                                <option value="1.15" <?php selected(get_option('scb_tts_rate', '1'), '1.15'); ?>><?php esc_html_e('Veloce', 'school-chatbot'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Dimensione font predefinita', 'school-chatbot'); ?></th>
                        <td>
                            <select name="scb_default_font_size">
                                <option value="normal" <?php selected(get_option('scb_default_font_size', 'normal'), 'normal'); ?>><?php esc_html_e('Normale (14px)', 'school-chatbot'); ?></option>
                                <option value="large" <?php selected(get_option('scb_default_font_size', 'normal'), 'large'); ?>><?php esc_html_e('Grande (16px)', 'school-chatbot'); ?></option>
                                <option value="xlarge" <?php selected(get_option('scb_default_font_size', 'normal'), 'xlarge'); ?>><?php esc_html_e('Molto grande (18px)', 'school-chatbot'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Contrasto predefinito', 'school-chatbot'); ?></th>
                        <td>
                            <select name="scb_default_contrast">
                                <option value="normal" <?php selected(get_option('scb_default_contrast', 'normal'), 'normal'); ?>><?php esc_html_e('Normale', 'school-chatbot'); ?></option>
                                <option value="high" <?php selected(get_option('scb_default_contrast', 'normal'), 'high'); ?>><?php esc_html_e('Alto contrasto', 'school-chatbot'); ?></option>
                                <option value="dark" <?php selected(get_option('scb_default_contrast', 'normal'), 'dark'); ?>><?php esc_html_e('Tema scuro', 'school-chatbot'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('L\'utente può comunque modificare queste impostazioni dal pannello nel widget.', 'school-chatbot'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2><?php esc_html_e('Indice del sito', 'school-chatbot'); ?></h2>
            <p><?php esc_html_e('Il chatbot indicizza automaticamente pagine, post, menu e CPT selezionati.', 'school-chatbot'); ?></p>
            <button type="button" id="scb-rebuild-index" class="button button-secondary">
                <?php esc_html_e('Ricostruisci indice ora', 'school-chatbot'); ?>
            </button>
            <span id="scb-index-status"></span>
            
            <details style="margin-top:20px;">
                <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e('Mostra contenuti indicizzati', 'school-chatbot'); ?></summary>
                <pre style="background:#f0f0f0;padding:15px;max-height:400px;overflow:auto;margin-top:10px;"><?php 
                    echo esc_html(print_r($this->get_site_index(), true)); 
                ?></pre>
            </details>
            
            <hr>
            
            <h2><?php esc_html_e('Shortcode', 'school-chatbot'); ?></h2>
            <p><?php
                printf(
                    /* translators: %s: shortcode da copiare */
                    esc_html__('Usa lo shortcode %s per incorporare il chatbot in una pagina.', 'school-chatbot'),
                    '<code>[school_chatbot]</code>'
                );
            ?></p>
            <p><?php
                printf(
                    /* translators: %s: esempio di shortcode con parametri */
                    esc_html__('Parametri opzionali: %s', 'school-chatbot'),
                    '<code>[school_chatbot height="400" title="Assistente"]</code>'
                );
            ?></p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#scb-rebuild-index').on('click', function() {
                var $btn = $(this);
                var $status = $('#scb-index-status');
                
                $btn.prop('disabled', true);
                $status.text(' <?php echo esc_js(__('Ricostruzione in corso...', 'school-chatbot')); ?>');
                
                $.post(ajaxurl, {
                    action: 'scb_rebuild_index',
                    nonce: '<?php echo wp_create_nonce('scb_rebuild_index'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $status.text(' ✓ <?php echo esc_js(__('Indice ricostruito!', 'school-chatbot')); ?>');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        $status.text(' ✗ <?php echo esc_js(__('Errore:', 'school-chatbot')); ?> ' + response.data);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Pagina Analytics
     */
    public function render_analytics_page() {
        if (!current_user_can('manage_options')) return;
        
        global $wpdb;
        $table_conv = $wpdb->prefix . 'scb_conversations';
        $table_msg = $wpdb->prefix . 'scb_messages';
        
        // Stats ultimi 30 giorni
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(DISTINCT c.id) as total_conversations,
                COUNT(m.id) as total_messages,
                SUM(CASE WHEN m.feedback = 1 THEN 1 ELSE 0 END) as positive,
                SUM(CASE WHEN m.feedback = 0 THEN 1 ELSE 0 END) as negative
            FROM $table_conv c
            LEFT JOIN $table_msg m ON m.conversation_id = c.id
            WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        // Conversazioni per giorno
        $daily = $wpdb->get_results("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM $table_conv
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        
        // Domande più frequenti (primi 10)
        $top_questions = $wpdb->get_results("
            SELECT content, COUNT(*) as count
            FROM $table_msg
            WHERE role = 'user' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY content
            ORDER BY count DESC
            LIMIT 10
        ");
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('School Chatbot - Analytics', 'school-chatbot'); ?></h1>

            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin:20px 0;">
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#2563eb;"><?php echo intval($stats->total_conversations ?? 0); ?></div>
                    <div style="color:#666;"><?php esc_html_e('Conversazioni (30gg)', 'school-chatbot'); ?></div>
                </div>
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#2563eb;"><?php echo intval($stats->total_messages ?? 0); ?></div>
                    <div style="color:#666;"><?php esc_html_e('Messaggi totali', 'school-chatbot'); ?></div>
                </div>
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#16a34a;"><?php echo intval($stats->positive ?? 0); ?></div>
                    <div style="color:#666;"><?php esc_html_e('Feedback positivi', 'school-chatbot'); ?></div>
                </div>
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#dc2626;"><?php echo intval($stats->negative ?? 0); ?></div>
                    <div style="color:#666;"><?php esc_html_e('Feedback negativi', 'school-chatbot'); ?></div>
                </div>
            </div>

            <?php
            $usage = get_option('scb_token_usage', []);
            if (is_array($usage) && !empty($usage['requests'])) :
                $cache_read = intval($usage['cache_read'] ?? 0);
                $fresh_input = intval($usage['input'] ?? 0) + intval($usage['cache_write'] ?? 0);
                $total_input = $cache_read + $fresh_input;
                $hit_rate = $total_input > 0 ? round($cache_read / $total_input * 100) : 0;
            ?>
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:20px;">
                <h3 style="margin-top:0;"><?php esc_html_e('Consumo token', 'school-chatbot'); ?></h3>
                <p style="margin:0 0 10px;color:#666;">
                    <?php
                    printf(
                        /* translators: 1: numero di richieste API, 2: token di input, 3: token di output */
                        esc_html__('%1$s richieste API · input %2$s · output %3$s', 'school-chatbot'),
                        esc_html(number_format_i18n(intval($usage['requests']))),
                        esc_html(number_format_i18n($total_input)),
                        esc_html(number_format_i18n(intval($usage['output'] ?? 0)))
                    );
                    ?>
                </p>
                <p style="margin:0;">
                    <strong style="font-size:20px;color:<?php echo $hit_rate > 50 ? '#16a34a' : '#dc2626'; ?>;"><?php echo intval($hit_rate); ?>%</strong>
                    <?php
                    printf(
                        /* translators: %s: numero di token letti dalla cache */
                        esc_html__('dell\'input servito dalla cache (%s token a costo ridotto).', 'school-chatbot'),
                        esc_html(number_format_i18n($cache_read))
                    );
                    ?>
                </p>
                <p class="description" style="margin-top:10px;">
                    <?php esc_html_e('Se dopo qualche decina di messaggi resta a 0%, la cache non sta funzionando: l\'indice del sito potrebbe essere troppo piccolo per la soglia minima, oppure cambia troppo spesso. Contatore cumulativo dall\'installazione.', 'school-chatbot'); ?>
                </p>
            </div>
            <?php endif; ?>


            <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;">
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <h3 style="margin-top:0;"><?php esc_html_e('Conversazioni per giorno', 'school-chatbot'); ?></h3>
                    <canvas id="scb-chart-daily" height="200"></canvas>
                </div>
                
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <h3 style="margin-top:0;"><?php esc_html_e('Domande frequenti', 'school-chatbot'); ?></h3>
                    <?php if ($top_questions) : ?>
                        <ol style="margin:0;padding-left:20px;">
                            <?php foreach ($top_questions as $q) : ?>
                                <li style="margin-bottom:8px;">
                                    <span style="color:#666;">(<?php echo intval($q->count); ?>)</span>
                                    <?php echo esc_html(wp_trim_words($q->content, 10)); ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else : ?>
                        <p style="color:#666;"><?php esc_html_e('Nessun dato disponibile.', 'school-chatbot'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        new Chart(document.getElementById('scb-chart-daily'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($daily, 'date')); ?>,
                datasets: [{
                    label: '<?php echo esc_js(__('Conversazioni', 'school-chatbot')); ?>',
                    data: <?php echo json_encode(array_map('intval', array_column($daily, 'count'))); ?>,
                    backgroundColor: '#2563eb'
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
        </script>
        <?php
    }
    
    /**
     * Pagina Conversazioni
     */
    public function render_conversations_page() {
        if (!current_user_can('manage_options')) return;
        
        global $wpdb;
        $table_conv = $wpdb->prefix . 'scb_conversations';
        $table_msg = $wpdb->prefix . 'scb_messages';
        
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_conv");
        $conversations = $wpdb->get_results($wpdb->prepare("
            SELECT c.*, 
                   (SELECT COUNT(*) FROM $table_msg WHERE conversation_id = c.id) as message_count,
                   (SELECT content FROM $table_msg WHERE conversation_id = c.id AND role = 'user' ORDER BY id ASC LIMIT 1) as first_message
            FROM $table_conv c
            ORDER BY c.updated_at DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset));
        
        $total_pages = ceil($total / $per_page);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('School Chatbot - Conversazioni', 'school-chatbot'); ?></h1>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:60px;"><?php esc_html_e('ID', 'school-chatbot'); ?></th>
                        <th><?php esc_html_e('Prima domanda', 'school-chatbot'); ?></th>
                        <th style="width:100px;"><?php esc_html_e('Messaggi', 'school-chatbot'); ?></th>
                        <th style="width:200px;"><?php esc_html_e('Pagina', 'school-chatbot'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Data', 'school-chatbot'); ?></th>
                        <th style="width:80px;"><?php esc_html_e('Azioni', 'school-chatbot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($conversations) : ?>
                        <?php foreach ($conversations as $conv) : ?>
                            <tr>
                                <td><?php echo intval($conv->id); ?></td>
                                <td><?php echo esc_html(wp_trim_words($conv->first_message ?? '-', 15)); ?></td>
                                <td><?php echo intval($conv->message_count); ?></td>
                                <td><small><?php echo esc_html(wp_trim_words($conv->page_url ?? '-', 5)); ?></small></td>
                                <td><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($conv->created_at))); ?></td>
                                <td>
                                    <a href="#" class="scb-view-conv" data-id="<?php echo intval($conv->id); ?>"><?php esc_html_e('Vedi', 'school-chatbot'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="6"><?php esc_html_e('Nessuna conversazione trovata.', 'school-chatbot'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'current' => $page,
                            'total' => $total_pages
                        ]);
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <div id="scb-conv-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;">
            <div style="background:#fff;max-width:600px;margin:50px auto;border-radius:8px;max-height:80vh;overflow:hidden;display:flex;flex-direction:column;">
                <div style="padding:15px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
                    <strong><?php esc_html_e('Conversazione', 'school-chatbot'); ?> #<span id="scb-modal-id"></span></strong>
                    <button id="scb-modal-close" style="background:none;border:none;font-size:24px;cursor:pointer;">&times;</button>
                </div>
                <div id="scb-modal-messages" style="flex:1;overflow-y:auto;padding:20px;"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.scb-view-conv').on('click', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                $('#scb-modal-id').text(id);
                $('#scb-modal-messages').html('<p><?php echo esc_js(__('Caricamento...', 'school-chatbot')); ?></p>');
                $('#scb-conv-modal').show();
                
                $.post(ajaxurl, {
                    action: 'scb_get_history',
                    nonce: '<?php echo wp_create_nonce('scb_chat'); ?>',
                    conversation_id: id
                }, function(response) {
                    if (response.success && response.data.messages) {
                        var html = '';
                        response.data.messages.forEach(function(m) {
                            var bg = m.role === 'user' ? '#e5e7eb' : '#f3f4f6';
                            var align = m.role === 'user' ? 'right' : 'left';
                            html += '<div style="text-align:' + align + ';margin-bottom:10px;">';
                            html += '<div style="display:inline-block;background:' + bg + ';padding:10px 14px;border-radius:12px;max-width:80%;text-align:left;">';
                            html += m.content.replace(/\n/g, '<br>');
                            html += '</div></div>';
                        });
                        $('#scb-modal-messages').html(html);
                    }
                });
            });
            
            $('#scb-modal-close, #scb-conv-modal').on('click', function(e) {
                if (e.target === this) $('#scb-conv-modal').hide();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Costruisce l'indice del sito
     */
    public function get_site_index() {
        $cached = get_transient('scb_site_index');
        if ($cached !== false) {
            return $cached;
        }
        
        $index = [
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url(),
            'pages' => [],
            'posts' => [],
            'custom_post_types' => [],
            'menus' => [],
            'categories' => []
        ];
        
        // Pagine
        $pages = get_pages(['post_status' => 'publish']);
        foreach ($pages as $page) {
            $index['pages'][] = [
                'title' => $page->post_title,
                'url' => get_permalink($page->ID),
                'excerpt' => wp_trim_words(wp_strip_all_tags($page->post_content), 30, '...'),
                'parent' => $page->post_parent ? get_the_title($page->post_parent) : null
            ];
        }
        
        // Post recenti
        $posts = get_posts([
            'numberposts' => 20,
            'post_status' => 'publish'
        ]);
        foreach ($posts as $post) {
            $index['posts'][] = [
                'title' => $post->post_title,
                'url' => get_permalink($post->ID),
                'date' => get_the_date('d/m/Y', $post->ID),
                'categories' => wp_get_post_categories($post->ID, ['fields' => 'names'])
            ];
        }
        
        // Custom Post Types
        $enabled_cpt = get_option('scb_enabled_cpt', []);
        if (!is_array($enabled_cpt)) $enabled_cpt = [];

        $allowed_meta = array_filter(array_map(
            'trim',
            explode("\n", (string) get_option('scb_cpt_meta_keys', ''))
        ));
        
        foreach ($enabled_cpt as $cpt) {
            $cpt_posts = get_posts([
                'post_type' => $cpt,
                'numberposts' => 30,
                'post_status' => 'publish'
            ]);
            
            if ($cpt_posts) {
                $cpt_obj = get_post_type_object($cpt);
                $index['custom_post_types'][$cpt] = [
                    'label' => $cpt_obj->labels->name,
                    'items' => []
                ];
                
                foreach ($cpt_posts as $p) {
                    $item = [
                        'title' => $p->post_title,
                        'url' => get_permalink($p->ID),
                        'date' => get_the_date('d/m/Y', $p->ID)
                    ];
                    
                    // Meta custom: solo quelli esplicitamente autorizzati.
                    // Prima venivano inviati tutti i campi non prefissati con
                    // "_", il che su un sito scolastico può significare spedire
                    // dati di studenti a un servizio in un paese terzo senza
                    // che nessuno l'abbia deciso. L'elenco vuoto non invia nulla.
                    if (!empty($allowed_meta)) {
                        $useful_meta = [];
                        foreach ($allowed_meta as $key) {
                            $value = get_post_meta($p->ID, $key, true);
                            if (is_scalar($value) && $value !== '') {
                                $useful_meta[$key] = (string) $value;
                            }
                        }
                        if ($useful_meta) {
                            $item['meta'] = $useful_meta;
                        }
                    }
                    
                    $index['custom_post_types'][$cpt]['items'][] = $item;
                }
            }
        }
        
        // Menu di navigazione
        $menus = wp_get_nav_menus();
        foreach ($menus as $menu) {
            $items = wp_get_nav_menu_items($menu->term_id);
            if ($items) {
                $menu_items = [];
                foreach ($items as $item) {
                    $menu_items[] = [
                        'title' => $item->title,
                        'url' => $item->url
                    ];
                }
                $index['menus'][$menu->name] = $menu_items;
            }
        }
        
        // Categorie
        $categories = get_categories(['hide_empty' => false]);
        foreach ($categories as $cat) {
            $index['categories'][] = [
                'name' => $cat->name,
                'url' => get_category_link($cat->term_id),
                'count' => $cat->count
            ];
        }
        
        // Cache per 1 ora
        set_transient('scb_site_index', $index, HOUR_IN_SECONDS);
        
        return $index;
    }
    
    /**
     * Ricostruisce indice via AJAX
     */
    public function rebuild_site_index() {
        check_ajax_referer('scb_rebuild_index', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permessi insufficienti', 'school-chatbot'));
        }
        
        delete_transient('scb_site_index');
        $this->get_site_index();
        
        wp_send_json_success();
    }
    
    /**
     * Pianifica ricostruzione indice
     */
    public function schedule_index_rebuild() {
        delete_transient('scb_site_index');
    }
    
    /**
     * Assets frontend
     */
    public function enqueue_frontend_assets() {
        // Non caricare assets se disabilitato
        if (!get_option('scb_enabled', 1)) {
            return;
        }
        
        wp_enqueue_style(
            'scb-chatbot',
            SCB_PLUGIN_URL . 'assets/chatbot.css',
            [],
            SCB_VERSION
        );
        
        wp_enqueue_script(
            'scb-chatbot',
            SCB_PLUGIN_URL . 'assets/chatbot.js',
            [],
            SCB_VERSION,
            true
        );
        
        $suggested = get_option('scb_suggested_questions', '');
        $suggestions = array_filter(array_map('trim', explode("\n", $suggested)));
        
        wp_localize_script('scb-chatbot', 'scbConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('scb_chat'),
            'welcomeMessage' => get_option('scb_welcome_message'),
            'primaryColor' => get_option('scb_primary_color', '#2563eb'),
            'position' => get_option('scb_position', 'bottom-right'),
            'enableFeedback' => (bool) get_option('scb_enable_feedback', 1),
            'enableHistory' => (bool) get_option('scb_enable_history', 1),
            'suggestedQuestions' => $suggestions,
            'offlineMessage' => get_option('scb_offline_message'),
            'currentUrl' => get_permalink(),
            'enableTts' => (bool) get_option('scb_enable_tts', 1),
            'ttsAuto' => (bool) get_option('scb_tts_auto', 0),
            'ttsRate' => floatval(get_option('scb_tts_rate', '1')),
            'enableAccessibilityPanel' => (bool) get_option('scb_enable_accessibility_panel', 1),
            'defaultFontSize' => get_option('scb_default_font_size', 'normal'),
            'defaultContrast' => get_option('scb_default_contrast', 'normal'),
            'enableVoiceInput' => (bool) get_option('scb_enable_voice_input', 1),
            'ageGateEnabled' => $this->age_gate_enabled(),
            // Cambiare versione forza una nuova presa d'atto anche a chi
            // l'aveva già data: serve quando cambia il testo dell'informativa.
            'ageGateVersion' => (string) apply_filters('scb_age_gate_version', '1'),
            'i18n' => [
                'listen'        => __('Ascolta risposta', 'school-chatbot'),
                'stopListening' => __('Ferma lettura', 'school-chatbot'),
                'speak'         => __('Parla', 'school-chatbot'),
                'listening'     => __('Sto ascoltando... (clicca per fermare)', 'school-chatbot'),
                'useful'        => __('Utile', 'school-chatbot'),
                'notUseful'     => __('Non utile', 'school-chatbot'),
            ]
        ]);
    }
    
    /**
     * Il contenuto dei messaggi viene conservato nel database?
     */
    private function store_messages_enabled() {
        return (bool) get_option('scb_store_messages', 1);
    }

    /**
     * Giorni di conservazione delle conversazioni (minimo 1)
     */
    private function get_retention_days() {
        return max(1, intval(get_option('scb_retention_days', 90)));
    }

    /**
     * L'informativa al primo uso è attiva?
     */
    private function age_gate_enabled() {
        return (bool) get_option('scb_age_gate_enabled', 1);
    }

    /**
     * Markup dell'informativa al primo uso.
     *
     * L. 132/2025 art. 4 c. 4: sotto i 14 anni serve l'autorizzazione di chi
     * esercita la responsabilità genitoriale. La legge non definisce procedure
     * di verifica dell'età, e nessuna verifica tecnica è possibile lato browser:
     * questo è un atto informativo con presa d'atto esplicita, non un controllo
     * dell'identità. Serve a documentare che l'utente è stato informato prima di
     * scrivere, non a impedire l'accesso a chi mente sull'età.
     */
    private function render_age_gate() {
        if (!$this->age_gate_enabled()) {
            return;
        }

        $privacy_url = get_option('scb_privacy_url', '');
        ?>
        <div class="scb-age-gate" role="dialog" aria-modal="true" aria-labelledby="scb-age-gate-title">
            <div class="scb-age-gate-inner">
                <h3 class="scb-age-gate-title" id="scb-age-gate-title"><?php esc_html_e('Prima di iniziare', 'school-chatbot'); ?></h3>
                <p><?php echo esc_html($this->get_ai_disclosure()); ?></p>
                <p><?php esc_html_e('Non scrivere dati personali tuoi o di altri: nomi, indirizzi, numeri di telefono, informazioni su salute o famiglia.', 'school-chatbot'); ?></p>
                <p><?php esc_html_e('Se hai meno di 14 anni puoi usare questo assistente solo con l\'autorizzazione di un genitore o di chi ne fa le veci.', 'school-chatbot'); ?></p>
                <?php if (!empty($privacy_url)) : ?>
                    <p>
                        <a href="<?php echo esc_url($privacy_url); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Leggi l\'informativa privacy', 'school-chatbot'); ?>
                        </a>
                    </p>
                <?php endif; ?>
                <button type="button" class="scb-age-gate-accept">
                    <?php esc_html_e('Ho capito, continua', 'school-chatbot'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Testo dell'informativa sulla natura artificiale dell'assistente.
     *
     * AI Act art. 50 comma 1: chi interagisce con un sistema di IA deve esserne
     * informato fin dal primo contatto. È un obbligo di legge, quindi il testo
     * è personalizzabile (traduzioni, tono della scuola) ma NON eliminabile:
     * un filtro che restituisce una stringa vuota viene ignorato.
     */
    private function get_ai_disclosure() {
        $default = __('Stai scrivendo a un assistente basato su intelligenza artificiale, non a un operatore umano. Le risposte possono contenere errori: per informazioni ufficiali rivolgiti alla segreteria.', 'school-chatbot');

        $text = apply_filters('scb_ai_disclosure', $default);

        if (!is_string($text) || trim(wp_strip_all_tags($text)) === '') {
            return $default;
        }

        return $text;
    }

    /**
     * Etichetta breve mostrata accanto al nome dell'assistente
     */
    private function get_ai_badge_label() {
        $default = __('IA', 'school-chatbot');

        $label = apply_filters('scb_ai_badge_label', $default);

        if (!is_string($label) || trim(wp_strip_all_tags($label)) === '') {
            return $default;
        }

        return $label;
    }

    /**
     * Markup dell'informativa IA, condiviso tra widget flottante e shortcode
     */
    private function render_ai_notice() {
        ?>
        <div class="scb-ai-notice" role="note">
            <span class="scb-ai-notice-icon" aria-hidden="true">✨</span>
            <span><?php echo esc_html($this->get_ai_disclosure()); ?></span>
        </div>
        <?php
    }

    /**
     * Render widget chatbot
     */
    public function render_chatbot_widget() {
        // Non mostrare se disabilitato
        if (!get_option('scb_enabled', 1)) {
            return;
        }
        
        $color = esc_attr(get_option('scb_primary_color', '#2563eb'));
        $position = get_option('scb_position', 'bottom-right');
        $pos_style = $position === 'bottom-left' ? 'left: 20px; right: auto;' : 'right: 20px;';
        ?>
        <div id="scb-chatbot-container" style="<?php echo $pos_style; ?>">
            <button id="scb-toggle" aria-label="<?php esc_attr_e('Apri la chat con l\'assistente basato su intelligenza artificiale', 'school-chatbot'); ?>" style="background-color: <?php echo $color; ?>">
                <svg id="scb-icon-chat" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
                <svg id="scb-icon-close" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
            
            <div id="scb-chat-window" class="scb-hidden">
                <div id="scb-header" style="background-color: <?php echo $color; ?>">
                    <span class="scb-header-title">
                        <?php esc_html_e('Assistente Scolastico', 'school-chatbot'); ?>
                        <span class="scb-ai-badge" title="<?php esc_attr_e('Assistente basato su intelligenza artificiale', 'school-chatbot'); ?>"><?php echo esc_html($this->get_ai_badge_label()); ?></span>
                    </span>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <button id="scb-accessibility-toggle" aria-label="<?php esc_attr_e('Opzioni accessibilità', 'school-chatbot'); ?>" title="<?php esc_attr_e('Accessibilità', 'school-chatbot'); ?>">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="3"></circle>
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                            </svg>
                        </button>
                        <button id="scb-close" aria-label="<?php esc_attr_e('Chiudi chat', 'school-chatbot'); ?>">&times;</button>
                    </div>
                </div>
                <div id="scb-accessibility-panel" class="scb-hidden">
                    <div class="scb-a11y-section">
                        <label><?php esc_html_e('Dimensione testo:', 'school-chatbot'); ?></label>
                        <div class="scb-a11y-buttons">
                            <button data-fontsize="normal" title="<?php esc_attr_e('Normale', 'school-chatbot'); ?>">A</button>
                            <button data-fontsize="large" title="<?php esc_attr_e('Grande', 'school-chatbot'); ?>">A+</button>
                            <button data-fontsize="xlarge" title="<?php esc_attr_e('Molto grande', 'school-chatbot'); ?>">A++</button>
                        </div>
                    </div>
                    <div class="scb-a11y-section">
                        <label><?php esc_html_e('Contrasto:', 'school-chatbot'); ?></label>
                        <div class="scb-a11y-buttons">
                            <button data-contrast="normal" title="<?php esc_attr_e('Normale', 'school-chatbot'); ?>">☀️</button>
                            <button data-contrast="high" title="<?php esc_attr_e('Alto contrasto', 'school-chatbot'); ?>">◐</button>
                            <button data-contrast="dark" title="<?php esc_attr_e('Tema scuro', 'school-chatbot'); ?>">🌙</button>
                        </div>
                    </div>
                </div>
                <?php $this->render_ai_notice(); ?>
                <?php $this->render_age_gate(); ?>
                <div id="scb-messages"></div>
                <div id="scb-suggestions"></div>
                <form id="scb-input-form">
                    <button type="button" id="scb-voice-btn" aria-label="<?php esc_attr_e('Input vocale', 'school-chatbot'); ?>" title="<?php esc_attr_e('Parla', 'school-chatbot'); ?>">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path>
                            <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
                            <line x1="12" y1="19" x2="12" y2="23"></line>
                            <line x1="8" y1="23" x2="16" y2="23"></line>
                        </svg>
                    </button>
                    <input type="text" id="scb-input" placeholder="<?php esc_attr_e('Scrivi un messaggio...', 'school-chatbot'); ?>" autocomplete="off">
                    <button type="submit" style="background-color: <?php echo $color; ?>">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Shortcode per chatbot inline
     */
    public function render_inline_chatbot($atts) {
        // Non mostrare se disabilitato
        if (!get_option('scb_enabled', 1)) {
            return '<p style="color:#666;font-style:italic;">'
                . esc_html__('Chatbot temporaneamente non disponibile.', 'school-chatbot')
                . '</p>';
        }

        $atts = shortcode_atts([
            'height' => '500',
            'title' => __('Assistente Scolastico', 'school-chatbot')
        ], $atts);
        
        $color = esc_attr(get_option('scb_primary_color', '#2563eb'));
        
        ob_start();
        ?>
        <div class="scb-inline-chatbot" style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;height:<?php echo intval($atts['height']); ?>px;display:flex;flex-direction:column;">
            <div style="background-color:<?php echo $color; ?>;color:#fff;padding:12px 16px;font-weight:600;">
                <?php echo esc_html($atts['title']); ?>
                <span class="scb-ai-badge" title="<?php esc_attr_e('Assistente basato su intelligenza artificiale', 'school-chatbot'); ?>"><?php echo esc_html($this->get_ai_badge_label()); ?></span>
            </div>
            <?php $this->render_ai_notice(); ?>
            <?php $this->render_age_gate(); ?>
            <div class="scb-inline-messages" style="flex:1;overflow-y:auto;padding:16px;"></div>
            <div class="scb-inline-suggestions" style="padding:0 16px;"></div>
            <form class="scb-inline-form" style="display:flex;padding:12px;border-top:1px solid #e5e7eb;gap:8px;">
                <input type="text" class="scb-inline-input" placeholder="<?php esc_attr_e('Scrivi un messaggio...', 'school-chatbot'); ?>" style="flex:1;padding:10px 14px;border:1px solid #d1d5db;border-radius:20px;outline:none;">
                <button type="submit" style="background-color:<?php echo $color; ?>;color:#fff;border:none;width:40px;height:40px;border-radius:50%;cursor:pointer;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                    </svg>
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Gestisce richiesta chat
     */
    public function handle_chat_request() {
        check_ajax_referer('scb_chat', 'nonce');
        
        $message = sanitize_text_field($_POST['message'] ?? '');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $page_url = esc_url_raw($_POST['page_url'] ?? '');
        $user_lang = sanitize_text_field($_POST['user_lang'] ?? '');
        
        if (empty($message)) {
            wp_send_json_error(__('Messaggio vuoto', 'school-chatbot'));
        }

        // L'informativa dev'essere stata presa in carico prima di scrivere.
        // Non è una verifica di identità né una barriera di sicurezza — il
        // client può sempre inviare il flag — ma impedisce che una richiesta
        // arrivi senza che l'informativa sia mai stata mostrata.
        if ($this->age_gate_enabled() && empty($_POST['notice_ack'])) {
            wp_send_json_error(__('Prima di scrivere devi prendere visione dell\'informativa.', 'school-chatbot'));
        }
        
        // Blocklist check
        $blocklist_result = $this->check_blocklist($message);
        if ($blocklist_result['blocked']) {
            $action = get_option('scb_blocklist_action', 'warn');
            
            // Notifica blocklist
            $this->trigger_notification('blocklist_triggered', [
                'message' => $message,
                'matched_words' => $blocklist_result['matched'],
                'session_id' => $session_id,
                'page_url' => $page_url
            ]);
            
            if ($action === 'warn') {
                wp_send_json_error([
                    'type' => 'blocklist',
                    'message' => get_option('scb_blocklist_message')
                ]);
            } elseif ($action === 'silent') {
                wp_send_json_success([
                    'reply' => get_option('scb_blocklist_message'),
                    'blocked' => true
                ]);
            } elseif ($action === 'replace') {
                $message = $blocklist_result['filtered_message'];
            }
        }
        
        // Rate limiting
        if (!$this->check_rate_limit($session_id)) {
            wp_send_json_error(__('Hai inviato troppi messaggi. Riprova tra poco.', 'school-chatbot'));
        }
        
        $api_key = get_option('scb_api_key');
        $provider = get_option('scb_api_provider', 'anthropic');
        
        if (empty($api_key)) {
            wp_send_json_success(['reply' => get_option('scb_offline_message')]);
            return;
        }
        
        // Rileva lingua
        $detected_lang = $this->detect_language($message);
        $response_lang = get_option('scb_enable_auto_language', 1) ? $detected_lang : get_option('scb_default_language', 'it');
        
        // Gestione conversazione
        global $wpdb;
        $table_conv = $wpdb->prefix . 'scb_conversations';
        $table_msg = $wpdb->prefix . 'scb_messages';
        
        $conversation_id = null;
        $history = [];
        $is_new_conversation = false;
        
        // Senza conservazione dei messaggi non c'è storico da rileggere: il
        // contesto conversazionale è per forza disattivato.
        if (get_option('scb_enable_history', 1) && $this->store_messages_enabled() && $session_id) {
            // Cerca conversazione esistente
            $conversation = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $table_conv WHERE session_id = %s AND updated_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE) ORDER BY id DESC LIMIT 1",
                $session_id
            ));
            
            if ($conversation) {
                $conversation_id = $conversation->id;
                
                // Carica storico
                $history_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT role, content FROM $table_msg WHERE conversation_id = %d ORDER BY id DESC LIMIT %d",
                    $conversation_id,
                    SCB_MAX_HISTORY
                ));
                
                $history = array_reverse(array_map(function($row) {
                    return ['role' => $row->role, 'content' => $row->content];
                }, $history_rows));
            }
        }
        
        // Crea nuova conversazione se necessario
        if (!$conversation_id) {
            $is_new_conversation = true;
            $wpdb->insert($table_conv, [
                'session_id' => $session_id ?: wp_generate_uuid4(),
                'user_id' => get_current_user_id() ?: null,
                'page_url' => $page_url
            ]);
            $conversation_id = $wpdb->insert_id;
            
            // Webhook nuova conversazione
            $this->trigger_notification('new_conversation', [
                'conversation_id' => $conversation_id,
                'first_message' => $message,
                'page_url' => $page_url
            ]);
        } else {
            // Aggiorna timestamp
            $wpdb->update($table_conv, ['updated_at' => current_time('mysql')], ['id' => $conversation_id]);
        }
        
        // Salva messaggio utente. Con la conservazione disattivata la riga viene
        // comunque creata, con contenuto vuoto: le statistiche continuano a
        // funzionare, il testo scritto dagli utenti non viene conservato.
        $store_messages = $this->store_messages_enabled();
        $wpdb->insert($table_msg, [
            'conversation_id' => $conversation_id,
            'role' => 'user',
            'content' => $store_messages ? $message : ''
        ]);
        
        // Costruisci contesto
        $site_index = $this->get_site_index();
        $custom_info = get_option('scb_custom_info', '');
        $system_base = $this->build_system_prompt($site_index, $custom_info);
        $system_suffix = $this->build_language_instruction($response_lang);

        // Chiama API
        $response = $this->call_ai_api($provider, $api_key, $system_base, $system_suffix, $message, $history);
        
        if (is_wp_error($response)) {
            // Notifica errore/domanda senza risposta
            $this->trigger_notification('unanswered', [
                'conversation_id' => $conversation_id,
                'message' => $message,
                'error' => $response->get_error_message(),
                'page_url' => $page_url
            ]);
            wp_send_json_error($response->get_error_message());
        }
        
        // Salva risposta
        $wpdb->insert($table_msg, [
            'conversation_id' => $conversation_id,
            'role' => 'assistant',
            'content' => $store_messages ? $response : ''
        ]);
        
        $message_id = $wpdb->insert_id;
        
        wp_send_json_success([
            'reply' => $response,
            'conversation_id' => $conversation_id,
            'message_id' => $message_id,
            'lang' => $response_lang
        ]);
    }
    
    /**
     * Rate limiting
     */
    private function check_rate_limit($session_id) {
        $limit = intval(get_option('scb_rate_limit', 20));
        $window = intval(get_option('scb_rate_window', 60));

        // Tetto per IP: il session_id arriva dal client e può essere rigenerato
        // a piacere, quindi da solo non limita nulla. L'IP è più permissivo
        // perché una scuola sta spesso dietro un unico NAT.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $ip_multiplier = max(1, intval(apply_filters('scb_ip_rate_multiplier', 5)));

        // wp_hash() invece di md5(): lo spazio IPv4 è abbastanza piccolo da
        // rendere un hash non salato invertibile per forza bruta, e la chiave
        // finisce nella tabella delle opzioni. wp_hash() usa i sali del sito.
        if ($ip !== '' && !$this->consume_rate_bucket('ip_' . wp_hash($ip), $limit * $ip_multiplier, $window)) {
            return false;
        }

        // Limite per sessione: equità tra utenti dietro lo stesso IP
        if ($session_id !== '' && !$this->consume_rate_bucket('sess_' . wp_hash($session_id), $limit, $window)) {
            return false;
        }

        return true;
    }

    /**
     * Incrementa un contatore a finestra scorrevole; false se il limite è superato
     */
    private function consume_rate_bucket($suffix, $limit, $window) {
        $key = 'scb_rate_' . $suffix;
        $data = get_transient($key);

        if ($data === false || !isset($data['start'], $data['count']) || time() - $data['start'] > $window) {
            set_transient($key, ['count' => 1, 'start' => time()], $window);
            return true;
        }

        if ($data['count'] >= $limit) {
            return false;
        }

        $data['count']++;
        set_transient($key, $data, $window);
        return true;
    }
    
    /**
     * Controlla blocklist parole
     */
    private function check_blocklist($message) {
        $blocklist = get_option('scb_blocklist_words', '');
        if (empty($blocklist)) {
            return ['blocked' => false];
        }
        
        $words = array_filter(array_map('trim', explode("\n", strtolower($blocklist))));
        $message_lower = strtolower($message);
        $matched = [];
        $filtered_message = $message;
        
        foreach ($words as $word) {
            if (empty($word)) continue;
            
            // Supporta parole intere o frasi
            $pattern = '/\b' . preg_quote($word, '/') . '\b/iu';
            if (preg_match($pattern, $message_lower)) {
                $matched[] = $word;
                // Sostituisci con asterischi per modalità 'replace'
                $filtered_message = preg_replace($pattern, str_repeat('*', mb_strlen($word)), $filtered_message);
            }
        }
        
        return [
            'blocked' => !empty($matched),
            'matched' => $matched,
            'filtered_message' => $filtered_message
        ];
    }
    
    /**
     * Rileva lingua dal messaggio
     */
    private function detect_language($text) {
        $text = strtolower(substr($text, 0, 200));
        
        $patterns = [
            'it' => '/\b(ciao|buongiorno|buonasera|grazie|prego|scuola|dove|quando|come|cosa|perché|orario|segreteria|iscrizione)\b/iu',
            'en' => '/\b(hello|hi|thanks|please|school|where|when|how|what|why|schedule|office|enrollment|the|is|are)\b/iu',
            'es' => '/\b(hola|buenos|gracias|por favor|escuela|dónde|cuándo|cómo|qué|horario|secretaría|inscripción)\b/iu',
            'fr' => '/\b(bonjour|bonsoir|merci|s\'il vous plaît|école|où|quand|comment|quoi|pourquoi|horaire|secrétariat)\b/iu',
            'de' => '/\b(hallo|guten|danke|bitte|schule|wo|wann|wie|was|warum|stundenplan|sekretariat)\b/iu',
            'pt' => '/\b(olá|bom dia|obrigado|por favor|escola|onde|quando|como|que|porquê|horário|secretaria)\b/iu',
            'ar' => '/[\x{0600}-\x{06FF}]/u',
            'zh' => '/[\x{4e00}-\x{9fff}]/u',
            'ro' => '/\b(bună|salut|mulțumesc|vă rog|școală|unde|când|cum|ce|orar|secretariat)\b/iu',
            'uk' => '/[\x{0400}-\x{04FF}]/u',
            'sq' => '/\b(përshëndetje|mirëdita|faleminderit|ju lutem|shkollë|ku|kur|si|çfarë|orar|sekretari)\b/iu',
        ];
        
        $scores = [];
        foreach ($patterns as $lang => $pattern) {
            preg_match_all($pattern, $text, $matches);
            $scores[$lang] = count($matches[0]);
        }
        
        arsort($scores);
        $detected = key($scores);
        
        // Ritorna default se nessun match significativo
        return ($scores[$detected] > 0) ? $detected : get_option('scb_default_language', 'it');
    }
    
    /**
     * Invia notifica (webhook o email)
     */
    private function trigger_notification($event, $data) {
        // Webhook
        $webhook_url = get_option('scb_webhook_url');
        $webhook_events = get_option('scb_webhook_events', []);
        if (!is_array($webhook_events)) $webhook_events = [];
        
        if ($webhook_url && in_array($event, $webhook_events)) {
            $payload = [
                'event' => $event,
                'timestamp' => current_time('c'),
                'site' => get_bloginfo('name'),
                'site_url' => home_url(),
                'data' => $data
            ];
            
            wp_remote_post($webhook_url, [
                'timeout' => 5,
                'blocking' => false,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($payload)
            ]);
        }
        
        // Email
        if (!get_option('scb_email_notifications')) return;
        
        $send_email = false;
        $subject = '';
        $message = '';
        
        switch ($event) {
            case 'unanswered':
                if (get_option('scb_notify_unanswered', 1)) {
                    $send_email = true;
                    $subject = '[School Chatbot] ' . __('Domanda senza risposta', 'school-chatbot');
                    $message = __('Una domanda non ha ricevuto risposta.', 'school-chatbot') . "\n\n";
                    $message .= __('Domanda:', 'school-chatbot') . " {$data['message']}\n";
                    $message .= __('Errore:', 'school-chatbot') . " {$data['error']}\n";
                    $message .= __('Pagina:', 'school-chatbot') . " {$data['page_url']}\n";
                }
                break;

            case 'negative_feedback':
                if (get_option('scb_notify_negative_feedback', 1)) {
                    $send_email = true;
                    $subject = '[School Chatbot] ' . __('Feedback negativo ricevuto', 'school-chatbot');
                    $message = __('Un utente ha dato feedback negativo.', 'school-chatbot') . "\n\n";
                    $message .= __('Domanda:', 'school-chatbot') . " {$data['user_message']}\n";
                    $message .= __('Risposta:', 'school-chatbot') . " {$data['bot_response']}\n";
                }
                break;

            case 'blocklist_triggered':
                if (get_option('scb_notify_blocklist', 1)) {
                    $send_email = true;
                    $subject = '[School Chatbot] ' . __('Parola bloccata rilevata', 'school-chatbot');
                    $message = __('Un messaggio è stato bloccato dalla blocklist.', 'school-chatbot') . "\n\n";
                    $message .= __('Messaggio:', 'school-chatbot') . " {$data['message']}\n";
                    $message .= __('Parole rilevate:', 'school-chatbot') . ' ' . implode(', ', $data['matched_words']) . "\n";
                    $message .= __('Pagina:', 'school-chatbot') . " {$data['page_url']}\n";
                }
                break;
        }
        
        if ($send_email) {
            $to = get_option('scb_notification_email', get_option('admin_email'));
            wp_mail($to, $subject, $message);
        }
    }
    
    /**
     * Ottieni storico conversazione (per admin)
     */
    public function get_conversation_history() {
        check_ajax_referer('scb_chat', 'nonce');

        $conversation_id = intval($_POST['conversation_id'] ?? 0);
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');

        if (!$conversation_id) {
            wp_send_json_error(__('Parametri non validi', 'school-chatbot'));
        }

        // Gli amministratori leggono qualsiasi conversazione (pagina Conversazioni);
        // i visitatori solo la propria.
        if (!current_user_can('manage_options')) {
            if (empty($session_id) || !$this->session_owns_conversation($session_id, $conversation_id)) {
                wp_send_json_error(__('Conversazione non trovata', 'school-chatbot'));
            }
        }

        global $wpdb;
        $table_msg = $wpdb->prefix . 'scb_messages';

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT role, content, created_at, feedback FROM $table_msg WHERE conversation_id = %d ORDER BY id ASC",
            $conversation_id
        ));

        wp_send_json_success(['messages' => $messages]);
    }

    /**
     * Verifica che una conversazione appartenga alla sessione indicata
     */
    private function session_owns_conversation($session_id, $conversation_id) {
        global $wpdb;
        $table_conv = $wpdb->prefix . 'scb_conversations';

        $owner = $wpdb->get_var($wpdb->prepare(
            "SELECT session_id FROM $table_conv WHERE id = %d",
            $conversation_id
        ));

        return $owner !== null && hash_equals((string) $owner, (string) $session_id);
    }

    /**
     * Verifica che un messaggio appartenga a una conversazione della sessione indicata
     */
    private function session_owns_message($session_id, $message_id) {
        global $wpdb;
        $table_conv = $wpdb->prefix . 'scb_conversations';
        $table_msg = $wpdb->prefix . 'scb_messages';

        $owner = $wpdb->get_var($wpdb->prepare(
            "SELECT c.session_id FROM $table_msg m
             INNER JOIN $table_conv c ON c.id = m.conversation_id
             WHERE m.id = %d",
            $message_id
        ));

        return $owner !== null && hash_equals((string) $owner, (string) $session_id);
    }
    
    /**
     * Gestisce feedback
     */
    public function handle_feedback() {
        check_ajax_referer('scb_chat', 'nonce');
        
        $message_id = intval($_POST['message_id'] ?? 0);
        $feedback = intval($_POST['feedback'] ?? -1);
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');

        if (!$message_id || !in_array($feedback, [0, 1]) || empty($session_id)) {
            wp_send_json_error(__('Parametri non validi', 'school-chatbot'));
        }

        // Si può votare solo un messaggio della propria conversazione
        if (!$this->session_owns_message($session_id, $message_id)) {
            wp_send_json_error(__('Messaggio non trovato', 'school-chatbot'));
        }

        global $wpdb;
        $table_msg = $wpdb->prefix . 'scb_messages';

        $wpdb->update(
            $table_msg,
            ['feedback' => $feedback],
            ['id' => $message_id]
        );
        
        // Notifica feedback negativo
        if ($feedback === 0) {
            // Recupera messaggio utente precedente
            $msg = $wpdb->get_row($wpdb->prepare(
                "SELECT m.content as bot_response, 
                        (SELECT content FROM $table_msg WHERE id < m.id AND conversation_id = m.conversation_id AND role = 'user' ORDER BY id DESC LIMIT 1) as user_message
                 FROM $table_msg m WHERE m.id = %d",
                $message_id
            ));
            
            if ($msg) {
                $this->trigger_notification('negative_feedback', [
                    'message_id' => $message_id,
                    'user_message' => $msg->user_message,
                    'bot_response' => $msg->bot_response
                ]);
            }
        }
        
        wp_send_json_success();
    }
    
    /**
     * Pulizia log vecchi
     */
    public function cleanup_old_logs() {
        global $wpdb;
        $table_conv = $wpdb->prefix . 'scb_conversations';
        
        $table_msg = $wpdb->prefix . 'scb_messages';

        // Elimina conversazioni oltre il periodo di conservazione configurato
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_conv WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $this->get_retention_days()
        ));

        // I messaggi hanno una FK ON DELETE CASCADE, ma le foreign key sono
        // ignorate su MyISAM e dbDelta non sempre le crea: senza questa pulizia
        // esplicita i messaggi resterebbero a vita su alcuni hosting.
        $wpdb->query(
            "DELETE m FROM $table_msg m
             LEFT JOIN $table_conv c ON c.id = m.conversation_id
             WHERE c.id IS NULL"
        );
    }
    
    /**
     * Costruisce system prompt
     */
    private function build_system_prompt($site_index, $custom_info) {
        $prompt = "Sei l'assistente virtuale del sito scolastico \"{$site_index['site_name']}\".
Il tuo compito è aiutare gli utenti a trovare informazioni e navigare il sito.

REGOLE:
- Sei un sistema di intelligenza artificiale, non una persona. Se ti viene
  chiesto chi sei, se sei umano, o con chi si sta parlando, dichiaralo sempre
  in modo chiaro e diretto, senza ambiguità e senza fingere di essere un
  operatore della segreteria. Non attribuirti un nome proprio di persona.
- Rispondi in modo cordiale e conciso
- Quando indichi una pagina, fornisci sempre il link cliccabile
- Se non conosci la risposta, suggerisci di contattare la segreteria
- Non inventare informazioni non presenti nei dati forniti
- Usa un linguaggio semplice adatto a studenti, genitori e personale

STRUTTURA DEL SITO:

PAGINE DISPONIBILI:\n";

        foreach ($site_index['pages'] as $page) {
            $prompt .= "- {$page['title']}: {$page['url']}\n";
            if (!empty($page['excerpt'])) {
                $prompt .= "  Contenuto: {$page['excerpt']}\n";
            }
        }
        
        $prompt .= "\nARTICOLI/CIRCOLARI RECENTI:\n";
        foreach ($site_index['posts'] as $post) {
            $cats = implode(', ', $post['categories']);
            $prompt .= "- {$post['title']} ({$post['date']}): {$post['url']}" . ($cats ? " [{$cats}]" : "") . "\n";
        }
        
        // Custom Post Types
        if (!empty($site_index['custom_post_types'])) {
            foreach ($site_index['custom_post_types'] as $cpt_slug => $cpt_data) {
                $prompt .= "\n{$cpt_data['label']}:\n";
                foreach ($cpt_data['items'] as $item) {
                    $prompt .= "- {$item['title']} ({$item['date']}): {$item['url']}\n";
                    if (!empty($item['meta'])) {
                        foreach ($item['meta'] as $key => $value) {
                            $prompt .= "  {$key}: {$value}\n";
                        }
                    }
                }
            }
        }
        
        if (!empty($site_index['menus'])) {
            $prompt .= "\nMENU DI NAVIGAZIONE:\n";
            foreach ($site_index['menus'] as $menu_name => $items) {
                $prompt .= "Menu '$menu_name':\n";
                foreach ($items as $item) {
                    $prompt .= "  - {$item['title']}: {$item['url']}\n";
                }
            }
        }
        
        if (!empty($site_index['categories'])) {
            $prompt .= "\nCATEGORIE:\n";
            foreach ($site_index['categories'] as $cat) {
                if ($cat['count'] > 0) {
                    $prompt .= "- {$cat['name']} ({$cat['count']} articoli): {$cat['url']}\n";
                }
            }
        }
        
        if (!empty($custom_info)) {
            $prompt .= "\nINFORMAZIONI IMPORTANTI:\n{$custom_info}\n";
        }

        return $prompt;
    }

    /**
     * Istruzione di lingua: tenuta fuori dal prompt stabile perché cambia
     * a ogni messaggio e invaliderebbe la cache dell'indice del sito.
     */
    private function build_language_instruction($lang = 'it') {
        $lang_names = [
            'it' => 'italiano',
            'en' => 'English',
            'es' => 'español',
            'fr' => 'français',
            'de' => 'Deutsch',
            'pt' => 'português',
            'ar' => 'العربية',
            'zh' => '中文',
            'ro' => 'română',
            'uk' => 'українська',
            'sq' => 'shqip',
        ];

        $lang_name = $lang_names[$lang] ?? 'italiano';

        return "LINGUA DELLA RISPOSTA: rispondi sempre in {$lang_name}.";
    }
    
    /**
     * Chiama API AI
     */
    private function call_ai_api($provider, $api_key, $system_base, $system_suffix, $user_message, $history = []) {
        if ($provider === 'anthropic') {
            return $this->call_anthropic($api_key, $system_base, $system_suffix, $user_message, $history);
        } else {
            return $this->call_openai($api_key, $system_base, $system_suffix, $user_message, $history);
        }
    }

    /**
     * Modelli Claude selezionabili
     */
    private function get_anthropic_models() {
        return [
            'claude-opus-5'    => 'Claude Opus 5 (più capace)',
            'claude-sonnet-5'  => 'Claude Sonnet 5 (equilibrato)',
            'claude-haiku-4-5' => 'Claude Haiku 4.5 (più economico)',
        ];
    }

    /**
     * Chiama Anthropic Claude
     *
     * Il prompt è diviso in due blocchi: il primo (identità + indice del sito)
     * è identico per tutte le richieste e viene marcato con cache_control, così
     * viene riprocessato una sola volta invece che a ogni messaggio. Il secondo
     * blocco contiene la lingua, che cambia, e resta fuori dalla cache.
     */
    private function call_anthropic($api_key, $system_base, $system_suffix, $user_message, $history = []) {
        $messages = [];

        // Aggiungi storico
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }

        // Aggiungi messaggio corrente
        $messages[] = ['role' => 'user', 'content' => $user_message];

        $cache_control = ['type' => 'ephemeral'];
        $ttl = apply_filters('scb_cache_ttl', '1h');
        if ($ttl !== '5m') {
            $cache_control['ttl'] = $ttl;
        }

        $system = [
            [
                'type' => 'text',
                'text' => $system_base,
                'cache_control' => $cache_control,
            ],
        ];

        if (!empty($system_suffix)) {
            $system[] = ['type' => 'text', 'text' => $system_suffix];
        }

        $model = get_option('scb_model_anthropic', 'claude-opus-5');

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01'
            ],
            'body' => json_encode([
                'model' => $model,
                'max_tokens' => intval(apply_filters('scb_max_tokens', 1024)),
                'system' => $system,
                'messages' => $messages
            ])
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }

        $this->record_usage([
            'input'       => $body['usage']['input_tokens'] ?? 0,
            'output'      => $body['usage']['output_tokens'] ?? 0,
            'cache_write' => $body['usage']['cache_creation_input_tokens'] ?? 0,
            'cache_read'  => $body['usage']['cache_read_input_tokens'] ?? 0,
        ]);

        return $body['content'][0]['text'] ?? __('Errore nella risposta', 'school-chatbot');
    }

    /**
     * Accumula il consumo di token per la dashboard Analytics
     */
    private function record_usage($tokens) {
        $usage = get_option('scb_token_usage', []);
        if (!is_array($usage)) {
            $usage = [];
        }

        foreach (['input', 'output', 'cache_write', 'cache_read'] as $field) {
            $usage[$field] = intval($usage[$field] ?? 0) + intval($tokens[$field] ?? 0);
        }
        $usage['requests'] = intval($usage['requests'] ?? 0) + 1;
        $usage['updated_at'] = current_time('mysql');

        update_option('scb_token_usage', $usage, false);
    }
    
    /**
     * Chiama OpenAI
     */
    private function call_openai($api_key, $system_base, $system_suffix, $user_message, $history = []) {
        // Parte stabile per prima: OpenAI applica il caching automatico sul
        // prefisso, quindi l'ordine conta anche qui.
        $system_prompt = $system_base;
        if (!empty($system_suffix)) {
            $system_prompt .= "\n\n" . $system_suffix;
        }

        $messages = [
            ['role' => 'system', 'content' => $system_prompt]
        ];

        // Aggiungi storico
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }

        // Aggiungi messaggio corrente
        $messages[] = ['role' => 'user', 'content' => $user_message];

        $model = get_option('scb_model_openai', 'gpt-4o-mini');

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => json_encode([
                'model' => $model,
                'max_tokens' => intval(apply_filters('scb_max_tokens', 1024)),
                'messages' => $messages
            ])
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }

        $this->record_usage([
            'input'      => $body['usage']['prompt_tokens'] ?? 0,
            'output'     => $body['usage']['completion_tokens'] ?? 0,
            'cache_read' => $body['usage']['prompt_tokens_details']['cached_tokens'] ?? 0,
        ]);

        return $body['choices'][0]['message']['content'] ?? __('Errore nella risposta', 'school-chatbot');
    }
}

// Inizializza plugin
School_Chatbot::get_instance();
