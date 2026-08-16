<?php
/**
 * Genera languages/school-chatbot.pot dal codice sorgente.
 *
 * Usa il tokenizer PHP anziché espressioni regolari, così i riferimenti
 * file:riga sono esatti e i commenti "translators:" vengono raccolti.
 * Sostituisce `wp i18n make-pot` dove wp-cli non è disponibile.
 *
 * Uso, dalla radice del plugin:
 *   php bin/make-pot.php
 *
 * @author Davide Bertolino
 */

$root = dirname(__DIR__);
chdir($root);

$domain  = 'school-chatbot';
$sources = ['school-chatbot.php', 'uninstall.php'];
$out     = 'languages/school-chatbot.pot';

$gettext = [
    '__' => 1, '_e' => 1, 'esc_html__' => 1, 'esc_html_e' => 1,
    'esc_attr__' => 1, 'esc_attr_e' => 1, '_x' => 1, 'esc_html_x' => 1,
];

$strings = []; // msgid => ['refs' => [], 'comment' => string]

foreach ($sources as $src) {
    if (!file_exists($src)) {
        fwrite(STDERR, "Sorgente mancante: $src\n");
        continue;
    }

    $tokens = token_get_all(file_get_contents($src));
    $n = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING || !isset($gettext[$t[1]])) continue;

        // Deve essere seguito da '('
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if ($j >= $n || $tokens[$j] !== '(') continue;

        // Primo argomento: stringa costante
        $k = $j + 1;
        while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k++;
        if ($k >= $n || !is_array($tokens[$k]) || $tokens[$k][0] !== T_CONSTANT_ENCAPSED_STRING) continue;

        $raw = $tokens[$k][1];
        $quote = $raw[0];
        $text = substr($raw, 1, -1);
        $text = $quote === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $text)
            : stripcslashes($text);

        // Secondo argomento: deve essere il nostro text domain
        $found_domain = false;
        $depth = 0;
        for ($m = $k + 1; $m < $n; $m++) {
            if ($tokens[$m] === '(') { $depth++; continue; }
            if ($tokens[$m] === ')') { if ($depth === 0) break; $depth--; continue; }
            if ($depth === 0 && is_array($tokens[$m]) && $tokens[$m][0] === T_CONSTANT_ENCAPSED_STRING) {
                if (trim($tokens[$m][1], "'\"") === $domain) $found_domain = true;
            }
        }
        if (!$found_domain) continue;

        // Commento per traduttori immediatamente precedente
        $comment = '';
        for ($c = $i - 1; $c >= 0 && $c > $i - 6; $c--) {
            if (!is_array($tokens[$c])) continue;
            if ($tokens[$c][0] === T_WHITESPACE) continue;
            if ($tokens[$c][0] === T_COMMENT && stripos($tokens[$c][1], 'translators:') !== false) {
                $comment = trim(preg_replace('#^/\*+|\*+/$#', '', $tokens[$c][1]));
            }
            break;
        }

        if (!isset($strings[$text])) {
            $strings[$text] = ['refs' => [], 'comment' => ''];
        }
        $strings[$text]['refs'][] = $src . ':' . $t[2];
        if ($comment !== '') $strings[$text]['comment'] = $comment;
    }
}

ksort($strings);

// La versione è letta dall'header del plugin, così il .pot non va aggiornato a mano
$plugin_src = file_get_contents('school-chatbot.php');
preg_match('/\* Version:\s*([0-9.]+)/', $plugin_src, $vm);
$version = $vm[1] ?? '0.0.0';

$pot  = "# Copyright (C) " . date('Y') . " Davide Bertolino\n";
$pot .= "# This file is distributed under the GPL v2 or later.\n";
$pot .= "msgid \"\"\nmsgstr \"\"\n";
$pot .= "\"Project-Id-Version: School Chatbot AI $version\\n\"\n";
$pot .= "\"Report-Msgid-Bugs-To: https://www.davidebertolino.it\\n\"\n";
$pot .= "\"MIME-Version: 1.0\\n\"\n";
$pot .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$pot .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$pot .= "\"Language-Team: Davide Bertolino\\n\"\n";
$pot .= "\"X-Domain: $domain\\n\"\n\n";

foreach ($strings as $msgid => $meta) {
    if ($meta['comment'] !== '') {
        $pot .= '#. ' . $meta['comment'] . "\n";
    }
    foreach ($meta['refs'] as $ref) {
        $pot .= '#: ' . $ref . "\n";
    }
    $pot .= 'msgid "' . addcslashes($msgid, "\"\\\n\t") . "\"\n";
    $pot .= "msgstr \"\"\n\n";
}

if (!is_dir('languages')) mkdir('languages', 0755, true);
file_put_contents($out, $pot);

echo 'Stringhe estratte: ' . count($strings) . " -> $out\n";
