<?php
/**
 * Uninstall — School Chatbot AI
 * Eseguito da WordPress alla disinstallazione del plugin.
 * Rimuove tabelle, opzioni, transient e cron.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

// Rimuovi tabelle (i messaggi hanno una FK verso le conversazioni:
// si eliminano prima le figlie per non dipendere dal motore di storage)
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}scb_messages");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}scb_conversations");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}scb_analytics");

// Rimuovi opzioni
$options = [
    // Stato e API
    'scb_enabled',
    'scb_api_key',
    'scb_api_provider',
    'scb_model_anthropic',
    'scb_model_openai',
    // Contenuti e aspetto
    'scb_custom_info',
    'scb_welcome_message',
    'scb_welcome_messages',
    'scb_offline_message',
    'scb_suggested_questions',
    'scb_primary_color',
    'scb_position',
    'scb_enabled_cpt',
    // Conformità e conservazione dati
    'scb_age_gate_enabled',
    'scb_privacy_url',
    'scb_store_messages',
    'scb_retention_days',
    'scb_cpt_meta_keys',
    // Comportamento
    'scb_rate_limit',
    'scb_rate_window',
    'scb_enable_feedback',
    'scb_enable_history',
    // Blocklist
    'scb_blocklist_words',
    'scb_blocklist_action',
    'scb_blocklist_message',
    // Lingua
    'scb_default_language',
    'scb_enable_auto_language',
    // Notifiche
    'scb_webhook_url',
    'scb_webhook_events',
    'scb_email_notifications',
    'scb_notification_email',
    'scb_notify_unanswered',
    'scb_notify_negative_feedback',
    'scb_notify_blocklist',
    // Accessibilità
    'scb_enable_tts',
    'scb_tts_auto',
    'scb_tts_rate',
    'scb_enable_accessibility_panel',
    'scb_default_font_size',
    'scb_default_contrast',
    'scb_enable_voice_input',
    // Statistiche
    'scb_token_usage',
];

foreach ($options as $option) {
    delete_option($option);
}

// Rimuovi indice del sito e contatori rate-limit (chiavi dinamiche)
delete_transient('scb_site_index');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_scb_rate_%' OR option_name LIKE '_transient_timeout_scb_rate_%'");

// Rimuovi transient updater
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dbgu_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_dbgu_%'");

// Rimuovi cron
wp_clear_scheduled_hook('scb_cleanup_logs');
