<?php
/**
 * Configuração SMS / WhatsApp para OTP de entrega.
 *
 * SMS_MODO (default: link):
 *   link            — Grátis: gera links WhatsApp + app SMS (utilizador envia manualmente)
 *   log             — Dev: regista mensagem em logs/sms.log
 *   twilio          — API paga Twilio (trial gratuito limitado)
 *   africastalking  — API paga Africa's Talking (sandbox gratuito para testes)
 *   off             — Desactivado
 */
if (!function_exists('envValue')) {
    require_once __DIR__ . '/database.php';
}

if (!defined('SMS_MODO')) {
    define('SMS_MODO', strtolower((string)envValue('SMS_MODO', 'link')));
}

if (!defined('TWILIO_ACCOUNT_SID')) {
    define('TWILIO_ACCOUNT_SID', (string)envValue('TWILIO_ACCOUNT_SID', ''));
}
if (!defined('TWILIO_AUTH_TOKEN')) {
    define('TWILIO_AUTH_TOKEN', (string)envValue('TWILIO_AUTH_TOKEN', ''));
}
if (!defined('TWILIO_FROM_NUMBER')) {
    define('TWILIO_FROM_NUMBER', (string)envValue('TWILIO_FROM_NUMBER', ''));
}
if (!defined('AT_USERNAME')) {
    define('AT_USERNAME', (string)envValue('AT_USERNAME', ''));
}
if (!defined('AT_API_KEY')) {
    define('AT_API_KEY', (string)envValue('AT_API_KEY', ''));
}
if (!defined('AT_SENDER_ID')) {
    define('AT_SENDER_ID', (string)envValue('AT_SENDER_ID', 'TrackMoz'));
}
