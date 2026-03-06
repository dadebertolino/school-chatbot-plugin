<?php
/**
 * Plugin Name: School Chatbot AI
 * Plugin URI: https://www.davidebertolino.it
 * Description: Chatbot AI per siti scolastici - risponde a domande su orari, pagine e navigazione
 * Version: 0.10.0
 * Author: Davide "the Prof." Bertolino
 * Author URI: https://www.davidebertolino.it
 * Text Domain: school-chatbot
 * Domain Path: /languages
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

define('SCB_VERSION', '0.10.0');
define('SCB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SCB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SCB_MAX_HISTORY', 10); // Messaggi di contesto per conversazione

class School_Chatbot {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Admin
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        
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
            'School Chatbot',
            'School Chatbot',
            'manage_options',
            'school-chatbot',
            [$this, 'render_admin_page'],
            'dashicons-format-chat',
            80
        );
        
        add_submenu_page(
            'school-chatbot',
            'Impostazioni',
            'Impostazioni',
            'manage_options',
            'school-chatbot',
            [$this, 'render_admin_page']
        );
        
        add_submenu_page(
            'school-chatbot',
            'Analytics',
            'Analytics',
            'manage_options',
            'school-chatbot-analytics',
            [$this, 'render_analytics_page']
        );
        
        add_submenu_page(
            'school-chatbot',
            'Conversazioni',
            'Conversazioni',
            'manage_options',
            'school-chatbot-conversations',
            [$this, 'render_conversations_page']
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
        register_setting('scb_settings', 'scb_custom_info');
        register_setting('scb_settings', 'scb_welcome_message', [
            'default' => 'Ciao! Sono l\'assistente virtuale della scuola. Come posso aiutarti?'
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
            'default' => 'Il chatbot è temporaneamente non disponibile. Contatta la segreteria.'
        ]);
        
        // Blocklist
        register_setting('scb_settings', 'scb_blocklist_words', ['default' => '']);
        register_setting('scb_settings', 'scb_blocklist_action', ['default' => 'warn']);
        register_setting('scb_settings', 'scb_blocklist_message', [
            'default' => 'Il tuo messaggio contiene contenuti non appropriati. Per favore riformula la domanda.'
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
            <h1>School Chatbot - Impostazioni</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('scb_settings'); ?>
                
                <div style="background:<?php echo get_option('scb_enabled', 1) ? '#d1fae5' : '#fee2e2'; ?>;border:1px solid <?php echo get_option('scb_enabled', 1) ? '#10b981' : '#ef4444'; ?>;border-radius:8px;padding:15px 20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <strong style="font-size:16px;"><?php echo get_option('scb_enabled', 1) ? '✅ Chatbot ATTIVO' : '⏸️ Chatbot DISATTIVATO'; ?></strong>
                        <p style="margin:5px 0 0;color:#666;">Il widget <?php echo get_option('scb_enabled', 1) ? 'è visibile' : 'non è visibile'; ?> ai visitatori del sito.</p>
                    </div>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="checkbox" name="scb_enabled" value="1" <?php checked(get_option('scb_enabled', 1)); ?> style="width:20px;height:20px;">
                        <span>Abilita chatbot</span>
                    </label>
                </div>
                
                <h2 class="title">Configurazione API</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Provider AI</th>
                        <td>
                            <select name="scb_api_provider">
                                <option value="anthropic" <?php selected(get_option('scb_api_provider'), 'anthropic'); ?>>Anthropic (Claude)</option>
                                <option value="openai" <?php selected(get_option('scb_api_provider'), 'openai'); ?>>OpenAI (GPT)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="password" name="scb_api_key" value="<?php echo esc_attr(get_option('scb_api_key')); ?>" class="regular-text">
                            <p class="description">La chiave non viene mai mostrata per sicurezza.</p>
                        </td>
                    </tr>
                </table>
                
                <h2 class="title">Aspetto</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Messaggio di benvenuto</th>
                        <td>
                            <input type="text" name="scb_welcome_message" value="<?php echo esc_attr(get_option('scb_welcome_message')); ?>" class="large-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Colore principale</th>
                        <td>
                            <input type="color" name="scb_primary_color" value="<?php echo esc_attr(get_option('scb_primary_color', '#2563eb')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Posizione widget</th>
                        <td>
                            <select name="scb_position">
                                <option value="bottom-right" <?php selected(get_option('scb_position'), 'bottom-right'); ?>>Basso a destra</option>
                                <option value="bottom-left" <?php selected(get_option('scb_position'), 'bottom-left'); ?>>Basso a sinistra</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Domande suggerite</th>
                        <td>
                            <textarea name="scb_suggested_questions" rows="3" class="large-text" placeholder="Una domanda per riga"><?php echo esc_textarea(get_option('scb_suggested_questions')); ?></textarea>
                            <p class="description">Domande mostrate come pulsanti cliccabili. Una per riga.</p>
                        </td>
                    </tr>
                </table>
                
                <h2 class="title">Contenuti indicizzati</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Custom Post Types</th>
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
                                <p class="description">Nessun custom post type registrato.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Informazioni aggiuntive</th>
                        <td>
                            <textarea name="scb_custom_info" rows="8" class="large-text" placeholder="Orari segreteria, contatti, regolamenti..."><?php echo esc_textarea(get_option('scb_custom_info')); ?></textarea>
                        </td>
                    </tr>
                </table>
                
                <h2 class="title">Rate Limiting</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Limite messaggi</th>
                        <td>
                            <input type="number" name="scb_rate_limit" value="<?php echo esc_attr(get_option('scb_rate_limit', 20)); ?>" min="1" max="100" style="width:80px">
                            messaggi ogni
                            <input type="number" name="scb_rate_window" value="<?php echo esc_attr(get_option('scb_rate_window', 60)); ?>" min="1" max="3600" style="width:80px">
                            secondi
                        </td>
                    </tr>
                </table>
                
                <h2 class="title">Funzionalità</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Opzioni</th>
                        <td>
                            <label style="display:block;margin-bottom:8px;">
                                <input type="checkbox" name="scb_enable_feedback" value="1" <?php checked(get_option('scb_enable_feedback', 1)); ?>>
                                Abilita feedback (pollice su/giù)
                            </label>
                            <label style="display:block;">
                                <input type="checkbox" name="scb_enable_history" value="1" <?php checked(get_option('scb_enable_history', 1)); ?>>
                                Mantieni storico conversazione (migliora contesto)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Messaggio offline</th>
                        <td>
                            <input type="text" name="scb_offline_message" value="<?php echo esc_attr(get_option('scb_offline_message')); ?>" class="large-text">
                        </td>
                    </tr>
                </table>
                
                <h2 class="title">🚫 Blocklist Parole</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Parole bloccate</th>
                        <td>
                            <textarea name="scb_blocklist_words" rows="4" class="large-text" placeholder="Una parola o frase per riga"><?php echo esc_textarea(get_option('scb_blocklist_words')); ?></textarea>
                            <p class="description">Messaggi contenenti queste parole verranno filtrati. Una per riga. Supporta anche frasi.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Azione</th>
                        <td>
                            <select name="scb_blocklist_action">
                                <option value="warn" <?php selected(get_option('scb_blocklist_action'), 'warn'); ?>>Mostra avviso e blocca invio</option>
                                <option value="silent" <?php selected(get_option('scb_blocklist_action'), 'silent'); ?>>Blocca silenziosamente</option>
                                <option value="replace" <?php selected(get_option('scb_blocklist_action'), 'replace'); ?>>Sostituisci con asterischi e invia</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Messaggio blocco</th>
                        <td>
                            <input type="text" name="scb_blocklist_message" value="<?php echo esc_attr(get_option('scb_blocklist_message')); ?>" class="large-text">
                        </td>
                    </tr>
                </table>
                
                <h2 class="title">🌍 Multilingua</h2>
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
                        <th scope="row">Lingua predefinita</th>
                        <td>
                            <select name="scb_default_language">
                                <?php foreach ($languages as $code => $name) : ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected(get_option('scb_default_language', 'it'), $code); ?>><?php echo esc_html($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Rilevamento automatico</th>
                        <td>
                            <label>
                                <input type="checkbox" name="scb_enable_auto_language" value="1" <?php checked(get_option('scb_enable_auto_language', 1)); ?>>
                                Rileva lingua dal messaggio dell'utente e rispondi nella stessa lingua
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Messaggi di benvenuto</th>
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
                            <p class="description">Lascia vuoto per usare il messaggio predefinito tradotto automaticamente.</p>
                        </td>
                    </tr>
                </table>
                
                <h2 class="title">🔔 Webhook e Notifiche</h2>
                <?php 
                $webhook_events = get_option('scb_webhook_events', []);
                if (!is_array($webhook_events)) $webhook_events = [];
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">URL Webhook</th>
                        <td>
                            <input type="url" name="scb_webhook_url" value="<?php echo esc_attr(get_option('scb_webhook_url')); ?>" class="large-text" placeholder="https://hooks.slack.com/... o https://discord.com/api/webhooks/...">
                            <p class="description">Ricevi notifiche su Slack, Discord, Teams o qualsiasi servizio webhook.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Eventi webhook</th>
                        <td>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="scb_webhook_events[]" value="new_conversation" <?php checked(in_array('new_conversation', $webhook_events)); ?>>
                                Nuova conversazione
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="scb_webhook_events[]" value="negative_feedback" <?php checked(in_array('negative_feedback', $webhook_events)); ?>>
                                Feedback negativo
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="scb_webhook_events[]" value="blocklist_triggered" <?php checked(in_array('blocklist_triggered', $webhook_events)); ?>>
                                Parola bloccata rilevata
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="scb_webhook_events[]" value="unanswered" <?php checked(in_array('unanswered', $webhook_events)); ?>>
                                Domanda senza risposta (errore API)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Notifiche email</th>
                        <td>
                            <label style="display:block;margin-bottom:8px;">
                                <input type="checkbox" name="scb_email_notifications" value="1" <?php checked(get_option('scb_email_notifications')); ?>>
                                Abilita notifiche via email
                            </label>
                            <input type="email" name="scb_notification_email" value="<?php echo esc_attr(get_option('scb_notification_email', get_option('admin_email'))); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Notifica per</th>
                        <td>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="scb_notify_unanswered" value="1" <?php checked(get_option('scb_notify_unanswered', 1)); ?>>
                                Domande senza risposta
                            </label>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="scb_notify_negative_feedback" value="1" <?php checked(get_option('scb_notify_negative_feedback', 1)); ?>>
                                Feedback negativi
                            </label>
                            <label style="display:block;">
                                <input type="checkbox" name="scb_notify_blocklist" value="1" <?php checked(get_option('scb_notify_blocklist', 1)); ?>>
                                Tentativi con parole bloccate
                            </label>
                        </td>
                    </tr>
                </table>
                
                <h2 class="title">♿ Accessibilità</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Pannello accessibilità</th>
                        <td>
                            <label>
                                <input type="checkbox" name="scb_enable_accessibility_panel" value="1" <?php checked(get_option('scb_enable_accessibility_panel', 1)); ?>>
                                Mostra pulsante ⚙️ con opzioni per l'utente (font, contrasto)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Input vocale</th>
                        <td>
                            <label>
                                <input type="checkbox" name="scb_enable_voice_input" value="1" <?php checked(get_option('scb_enable_voice_input', 1)); ?>>
                                Mostra pulsante microfono 🎤 per dettare messaggi
                            </label>
                            <p class="description">Usa il riconoscimento vocale del browser (richiede HTTPS).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sintesi vocale (TTS)</th>
                        <td>
                            <label style="display:block;margin-bottom:8px;">
                                <input type="checkbox" name="scb_enable_tts" value="1" <?php checked(get_option('scb_enable_tts', 1)); ?>>
                                Mostra pulsante "Ascolta risposta" 🔊
                            </label>
                            <label style="display:block;">
                                <input type="checkbox" name="scb_tts_auto" value="1" <?php checked(get_option('scb_tts_auto', 0)); ?>>
                                Leggi automaticamente ogni risposta
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Velocità lettura</th>
                        <td>
                            <select name="scb_tts_rate">
                                <option value="0.7" <?php selected(get_option('scb_tts_rate', '1'), '0.7'); ?>>Lenta</option>
                                <option value="0.85" <?php selected(get_option('scb_tts_rate', '1'), '0.85'); ?>>Moderata</option>
                                <option value="1" <?php selected(get_option('scb_tts_rate', '1'), '1'); ?>>Normale</option>
                                <option value="1.15" <?php selected(get_option('scb_tts_rate', '1'), '1.15'); ?>>Veloce</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Dimensione font predefinita</th>
                        <td>
                            <select name="scb_default_font_size">
                                <option value="normal" <?php selected(get_option('scb_default_font_size', 'normal'), 'normal'); ?>>Normale (14px)</option>
                                <option value="large" <?php selected(get_option('scb_default_font_size', 'normal'), 'large'); ?>>Grande (16px)</option>
                                <option value="xlarge" <?php selected(get_option('scb_default_font_size', 'normal'), 'xlarge'); ?>>Molto grande (18px)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Contrasto predefinito</th>
                        <td>
                            <select name="scb_default_contrast">
                                <option value="normal" <?php selected(get_option('scb_default_contrast', 'normal'), 'normal'); ?>>Normale</option>
                                <option value="high" <?php selected(get_option('scb_default_contrast', 'normal'), 'high'); ?>>Alto contrasto</option>
                                <option value="dark" <?php selected(get_option('scb_default_contrast', 'normal'), 'dark'); ?>>Tema scuro</option>
                            </select>
                            <p class="description">L'utente può comunque modificare queste impostazioni dal pannello nel widget.</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>Indice del sito</h2>
            <p>Il chatbot indicizza automaticamente pagine, post, menu e CPT selezionati.</p>
            <button type="button" id="scb-rebuild-index" class="button button-secondary">
                Ricostruisci indice ora
            </button>
            <span id="scb-index-status"></span>
            
            <details style="margin-top:20px;">
                <summary style="cursor:pointer;font-weight:600;">Mostra contenuti indicizzati</summary>
                <pre style="background:#f0f0f0;padding:15px;max-height:400px;overflow:auto;margin-top:10px;"><?php 
                    echo esc_html(print_r($this->get_site_index(), true)); 
                ?></pre>
            </details>
            
            <hr>
            
            <h2>Shortcode</h2>
            <p>Usa lo shortcode <code>[school_chatbot]</code> per incorporare il chatbot in una pagina.</p>
            <p>Parametri opzionali: <code>[school_chatbot height="400" title="Assistente"]</code></p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#scb-rebuild-index').on('click', function() {
                var $btn = $(this);
                var $status = $('#scb-index-status');
                
                $btn.prop('disabled', true);
                $status.text(' Ricostruzione in corso...');
                
                $.post(ajaxurl, {
                    action: 'scb_rebuild_index',
                    nonce: '<?php echo wp_create_nonce('scb_rebuild_index'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $status.text(' ✓ Indice ricostruito!');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        $status.text(' ✗ Errore: ' + response.data);
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
            <h1>School Chatbot - Analytics</h1>
            
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin:20px 0;">
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#2563eb;"><?php echo intval($stats->total_conversations ?? 0); ?></div>
                    <div style="color:#666;">Conversazioni (30gg)</div>
                </div>
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#2563eb;"><?php echo intval($stats->total_messages ?? 0); ?></div>
                    <div style="color:#666;">Messaggi totali</div>
                </div>
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#16a34a;"><?php echo intval($stats->positive ?? 0); ?></div>
                    <div style="color:#666;">Feedback positivi</div>
                </div>
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#dc2626;"><?php echo intval($stats->negative ?? 0); ?></div>
                    <div style="color:#666;">Feedback negativi</div>
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;">
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <h3 style="margin-top:0;">Conversazioni per giorno</h3>
                    <canvas id="scb-chart-daily" height="200"></canvas>
                </div>
                
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <h3 style="margin-top:0;">Domande frequenti</h3>
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
                        <p style="color:#666;">Nessun dato disponibile.</p>
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
                    label: 'Conversazioni',
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
            <h1>School Chatbot - Conversazioni</h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>Prima domanda</th>
                        <th style="width:100px;">Messaggi</th>
                        <th style="width:200px;">Pagina</th>
                        <th style="width:150px;">Data</th>
                        <th style="width:80px;">Azioni</th>
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
                                    <a href="#" class="scb-view-conv" data-id="<?php echo intval($conv->id); ?>">Vedi</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="6">Nessuna conversazione trovata.</td></tr>
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
                    <strong>Conversazione #<span id="scb-modal-id"></span></strong>
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
                $('#scb-modal-messages').html('<p>Caricamento...</p>');
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
                    
                    // Aggiungi meta custom se esistono (date eventi, ecc.)
                    $meta = get_post_meta($p->ID);
                    $useful_meta = [];
                    foreach ($meta as $key => $value) {
                        if (strpos($key, '_') !== 0 && !empty($value[0])) {
                            $useful_meta[$key] = $value[0];
                        }
                    }
                    if ($useful_meta) {
                        $item['meta'] = $useful_meta;
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
            wp_send_json_error('Permessi insufficienti');
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
            'enableVoiceInput' => (bool) get_option('scb_enable_voice_input', 1)
        ]);
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
            <button id="scb-toggle" aria-label="Apri chat" style="background-color: <?php echo $color; ?>">
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
                    <span>Assistente Scolastico</span>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <button id="scb-accessibility-toggle" aria-label="Opzioni accessibilità" title="Accessibilità">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="3"></circle>
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                            </svg>
                        </button>
                        <button id="scb-close" aria-label="Chiudi chat">&times;</button>
                    </div>
                </div>
                <div id="scb-accessibility-panel" class="scb-hidden">
                    <div class="scb-a11y-section">
                        <label>Dimensione testo:</label>
                        <div class="scb-a11y-buttons">
                            <button data-fontsize="normal" title="Normale">A</button>
                            <button data-fontsize="large" title="Grande">A+</button>
                            <button data-fontsize="xlarge" title="Molto grande">A++</button>
                        </div>
                    </div>
                    <div class="scb-a11y-section">
                        <label>Contrasto:</label>
                        <div class="scb-a11y-buttons">
                            <button data-contrast="normal" title="Normale">☀️</button>
                            <button data-contrast="high" title="Alto contrasto">◐</button>
                            <button data-contrast="dark" title="Tema scuro">🌙</button>
                        </div>
                    </div>
                </div>
                <div id="scb-messages"></div>
                <div id="scb-suggestions"></div>
                <form id="scb-input-form">
                    <button type="button" id="scb-voice-btn" aria-label="Input vocale" title="Parla">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path>
                            <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
                            <line x1="12" y1="19" x2="12" y2="23"></line>
                            <line x1="8" y1="23" x2="16" y2="23"></line>
                        </svg>
                    </button>
                    <input type="text" id="scb-input" placeholder="Scrivi un messaggio..." autocomplete="off">
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
            return '<p style="color:#666;font-style:italic;">Chatbot temporaneamente non disponibile.</p>';
        }
        
        $atts = shortcode_atts([
            'height' => '500',
            'title' => 'Assistente Scolastico'
        ], $atts);
        
        $color = esc_attr(get_option('scb_primary_color', '#2563eb'));
        
        ob_start();
        ?>
        <div class="scb-inline-chatbot" style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;height:<?php echo intval($atts['height']); ?>px;display:flex;flex-direction:column;">
            <div style="background-color:<?php echo $color; ?>;color:#fff;padding:12px 16px;font-weight:600;">
                <?php echo esc_html($atts['title']); ?>
            </div>
            <div class="scb-inline-messages" style="flex:1;overflow-y:auto;padding:16px;"></div>
            <div class="scb-inline-suggestions" style="padding:0 16px;"></div>
            <form class="scb-inline-form" style="display:flex;padding:12px;border-top:1px solid #e5e7eb;gap:8px;">
                <input type="text" class="scb-inline-input" placeholder="Scrivi un messaggio..." style="flex:1;padding:10px 14px;border:1px solid #d1d5db;border-radius:20px;outline:none;">
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
            wp_send_json_error('Messaggio vuoto');
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
            wp_send_json_error('Hai inviato troppi messaggi. Riprova tra poco.');
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
        
        if (get_option('scb_enable_history', 1) && $session_id) {
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
        
        // Salva messaggio utente
        $wpdb->insert($table_msg, [
            'conversation_id' => $conversation_id,
            'role' => 'user',
            'content' => $message
        ]);
        
        // Costruisci contesto
        $site_index = $this->get_site_index();
        $custom_info = get_option('scb_custom_info', '');
        $system_prompt = $this->build_system_prompt($site_index, $custom_info, $response_lang);
        
        // Chiama API
        $response = $this->call_ai_api($provider, $api_key, $system_prompt, $message, $history);
        
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
            'content' => $response
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
        
        $key = 'scb_rate_' . md5($session_id . $_SERVER['REMOTE_ADDR']);
        $data = get_transient($key);
        
        if ($data === false) {
            set_transient($key, ['count' => 1, 'start' => time()], $window);
            return true;
        }
        
        if (time() - $data['start'] > $window) {
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
                    $subject = '[School Chatbot] Domanda senza risposta';
                    $message = "Una domanda non ha ricevuto risposta.\n\n";
                    $message .= "Domanda: {$data['message']}\n";
                    $message .= "Errore: {$data['error']}\n";
                    $message .= "Pagina: {$data['page_url']}\n";
                }
                break;
                
            case 'negative_feedback':
                if (get_option('scb_notify_negative_feedback', 1)) {
                    $send_email = true;
                    $subject = '[School Chatbot] Feedback negativo ricevuto';
                    $message = "Un utente ha dato feedback negativo.\n\n";
                    $message .= "Domanda: {$data['user_message']}\n";
                    $message .= "Risposta: {$data['bot_response']}\n";
                }
                break;
                
            case 'blocklist_triggered':
                if (get_option('scb_notify_blocklist', 1)) {
                    $send_email = true;
                    $subject = '[School Chatbot] Parola bloccata rilevata';
                    $message = "Un messaggio è stato bloccato dalla blocklist.\n\n";
                    $message .= "Messaggio: {$data['message']}\n";
                    $message .= "Parole rilevate: " . implode(', ', $data['matched_words']) . "\n";
                    $message .= "Pagina: {$data['page_url']}\n";
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
        
        if (!$conversation_id) {
            wp_send_json_error('ID conversazione mancante');
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
     * Gestisce feedback
     */
    public function handle_feedback() {
        check_ajax_referer('scb_chat', 'nonce');
        
        $message_id = intval($_POST['message_id'] ?? 0);
        $feedback = intval($_POST['feedback'] ?? -1);
        
        if (!$message_id || !in_array($feedback, [0, 1])) {
            wp_send_json_error('Parametri non validi');
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
        
        // Elimina conversazioni più vecchie di 90 giorni
        $wpdb->query("DELETE FROM $table_conv WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }
    
    /**
     * Costruisce system prompt
     */
    private function build_system_prompt($site_index, $custom_info, $lang = 'it') {
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
        
        $prompt = "Sei l'assistente virtuale del sito scolastico \"{$site_index['site_name']}\". 
Il tuo compito è aiutare gli utenti a trovare informazioni e navigare il sito.

REGOLE:
- Rispondi in {$lang_name}, in modo cordiale e conciso
- Quando indichi una pagina, fornisci sempre il link cliccabile
- Se non conosci la risposta, suggerisci di contattare la segreteria
- Non inventare informazioni non presenti nei dati forniti
- Usa un linguaggio semplice adatto a studenti, genitori e personale
- IMPORTANTE: Rispondi SEMPRE nella lingua richiesta ({$lang_name})

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
     * Chiama API AI
     */
    private function call_ai_api($provider, $api_key, $system_prompt, $user_message, $history = []) {
        if ($provider === 'anthropic') {
            return $this->call_anthropic($api_key, $system_prompt, $user_message, $history);
        } else {
            return $this->call_openai($api_key, $system_prompt, $user_message, $history);
        }
    }
    
    /**
     * Chiama Anthropic Claude
     */
    private function call_anthropic($api_key, $system_prompt, $user_message, $history = []) {
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
        
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01'
            ],
            'body' => json_encode([
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 1024,
                'system' => $system_prompt,
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
        
        return $body['content'][0]['text'] ?? 'Errore nella risposta';
    }
    
    /**
     * Chiama OpenAI
     */
    private function call_openai($api_key, $system_prompt, $user_message, $history = []) {
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
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => json_encode([
                'model' => 'gpt-4o-mini',
                'max_tokens' => 1024,
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
        
        return $body['choices'][0]['message']['content'] ?? 'Errore nella risposta';
    }
}

// Inizializza plugin
School_Chatbot::get_instance();
