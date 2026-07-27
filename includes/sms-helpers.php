<?php
/**
 * Envio de OTP por SMS/WhatsApp — modo grátis (links) ou gateway opcional (pago).
 */
require_once __DIR__ . '/../config/sms.php';

/**
 * Normaliza telefone moçambicano para E.164 (+258...).
 */
function sms_normalizar_telefone(string $telefone): ?string
{
    $digits = preg_replace('/\D+/', '', $telefone);
    if ($digits === null || $digits === '') {
        return null;
    }

    // Remove zeros à esquerda do prefixo internacional
    if (str_starts_with($digits, '00258')) {
        $digits = substr($digits, 2); // 258...
    }

    if (str_starts_with($digits, '258') && strlen($digits) >= 12) {
        return '+' . substr($digits, 0, 12);
    }

    // Moçambique: 9 dígitos começados por 8x / 2x (móvel/fix)
    if (strlen($digits) === 9 && in_array($digits[0], ['2', '3', '4', '5', '6', '7', '8'], true)) {
        return '+258' . $digits;
    }

    // Já com código do país (10–15 dígitos)
    if (strlen($digits) >= 10 && strlen($digits) <= 15) {
        return '+' . $digits;
    }

    return null;
}

function sms_link_whatsapp(string $telefoneE164, string $mensagem): string
{
    $num = preg_replace('/\D+/', '', $telefoneE164);
    return 'https://wa.me/' . $num . '?text=' . rawurlencode($mensagem);
}

function sms_link_app(string $telefoneE164, string $mensagem): string
{
    // iOS usa &body= ; Android aceita ?body=
    $num = preg_replace('/\D+/', '', $telefoneE164);
    return 'sms:+' . $num . '?&body=' . rawurlencode($mensagem);
}

function sms_mensagem_otp(string $codigo, string $tituloMissao, string $expiraEm): string
{
    $expiraFmt = date('d/m/Y H:i', strtotime($expiraEm));
    $titulo = trim($tituloMissao) !== '' ? $tituloMissao : 'TrackMoz';

    return "TrackMoz — Código de entrega: {$codigo}\n"
        . "Missão: {$titulo}\n"
        . "Válido até {$expiraFmt}.\n"
        . 'Apresente este código ao motorista na recepção da carga.';
}

function sms_gateway_configurado(): bool
{
    $modo = SMS_MODO;
    if ($modo === 'twilio') {
        return TWILIO_ACCOUNT_SID !== '' && TWILIO_AUTH_TOKEN !== '' && TWILIO_FROM_NUMBER !== '';
    }
    if ($modo === 'africastalking') {
        return AT_USERNAME !== '' && AT_API_KEY !== '';
    }
    return false;
}

/**
 * @return array{ok: bool, enviado_automatico?: bool, metodo?: string, whatsapp_url?: string, sms_url?: string, mensagem?: string, error?: string}
 */
function sms_enviar(string $telefone, string $mensagem): array
{
    $e164 = sms_normalizar_telefone($telefone);
    if ($e164 === null) {
        return ['ok' => false, 'error' => 'Telefone inválido. Use formato moçambicano (ex: 84 123 4567).'];
    }

    $modo = SMS_MODO;
    $links = [
        'whatsapp_url' => sms_link_whatsapp($e164, $mensagem),
        'sms_url'      => sms_link_app($e164, $mensagem),
        'mensagem'     => $mensagem,
        'telefone'     => $e164,
    ];

    if ($modo === 'off') {
        return array_merge(['ok' => false, 'error' => 'Envio SMS desactivado'], $links);
    }

    if ($modo === 'log') {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $line = date('Y-m-d H:i:s') . " | {$e164} | " . str_replace("\n", ' ', $mensagem) . PHP_EOL;
        @file_put_contents($logDir . '/sms.log', $line, FILE_APPEND | LOCK_EX);
        return array_merge([
            'ok'               => true,
            'enviado_automatico' => true,
            'metodo'           => 'log',
        ], $links);
    }

    if ($modo === 'twilio' && sms_gateway_configurado()) {
        $api = sms_enviar_twilio($e164, $mensagem);
        if ($api['ok']) {
            return array_merge($api, $links);
        }
        return array_merge($api, $links);
    }

    if ($modo === 'africastalking' && sms_gateway_configurado()) {
        $api = sms_enviar_africas_talking($e164, $mensagem);
        if ($api['ok']) {
            return array_merge($api, $links);
        }
        return array_merge($api, $links);
    }

    // Modo grátis (default): links para WhatsApp / app SMS
    return array_merge([
        'ok'                 => true,
        'enviado_automatico' => false,
        'metodo'             => 'link',
        'instrucao'          => 'Abra WhatsApp ou SMS e envie a mensagem ao destinatário.',
    ], $links);
}

/**
 * @return array{ok: bool, error?: string, sid?: string}
 */
function sms_enviar_twilio(string $toE164, string $body): array
{
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_ACCOUNT_SID . '/Messages.json';
    $post = http_build_query([
        'To'   => $toE164,
        'From' => TWILIO_FROM_NUMBER,
        'Body' => $body,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        $data = json_decode((string)$response, true);
        return [
            'ok'               => true,
            'enviado_automatico' => true,
            'metodo'           => 'twilio',
            'sid'              => $data['sid'] ?? null,
        ];
    }

    error_log('Twilio SMS falhou (' . $httpCode . '): ' . $response);
    return ['ok' => false, 'error' => 'Falha ao enviar SMS via Twilio. Verifique credenciais ou use WhatsApp.'];
}

/**
 * @return array{ok: bool, error?: string}
 */
function sms_enviar_africas_talking(string $toE164, string $body): array
{
    $to = ltrim($toE164, '+');
    $url = 'https://api.africastalking.com/version1/messaging';
    $post = http_build_query([
        'username' => AT_USERNAME,
        'to'       => $to,
        'message'  => $body,
        'from'     => AT_SENDER_ID,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apiKey: ' . AT_API_KEY,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'ok'                 => true,
            'enviado_automatico' => true,
            'metodo'             => 'africastalking',
        ];
    }

    error_log('Africa\'s Talking SMS falhou (' . $httpCode . '): ' . $response);
    return ['ok' => false, 'error' => 'Falha ao enviar SMS via Africa\'s Talking.'];
}

/**
 * Envia (ou prepara links) OTP para o destinatário e grava telefone na BD.
 *
 * @return array<string, mixed>
 */
function otp_notificar_destinatario(
    PDO $conn,
    int $missao_id,
    string $telefone,
    string $codigo,
    string $expiraEm,
    string $tituloMissao = ''
): array {
    $mensagem = sms_mensagem_otp($codigo, $tituloMissao, $expiraEm);
    $result = sms_enviar($telefone, $mensagem);

    if ($result['ok'] || !empty($result['whatsapp_url'])) {
        try {
            $e164 = sms_normalizar_telefone($telefone);
            $conn->prepare(
                'UPDATE otp_codes SET destinatario_telefone = ? WHERE missao_id = ?'
            )->execute([$e164 ?? $telefone, $missao_id]);
        } catch (Throwable $e) {
            error_log('otp_notificar_destinatario: ' . $e->getMessage());
        }
    }

    return $result;
}

/**
 * Telefone do destinatário associado à missão (otp_codes ou destinatarios).
 */
function otp_telefone_missao(PDO $conn, int $missao_id): ?string
{
    try {
        $stmt = $conn->prepare(
            "SELECT o.destinatario_telefone, d.telefone AS dest_tel
             FROM missoes m
             LEFT JOIN otp_codes o ON o.missao_id = m.id
             LEFT JOIN destinatarios d ON d.id = m.destinatario_id
             WHERE m.id = ?"
        );
        $stmt->execute([$missao_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $tel = trim((string)($row['destinatario_telefone'] ?? ''));
        if ($tel !== '') {
            return $tel;
        }
        $tel = trim((string)($row['dest_tel'] ?? ''));
        return $tel !== '' ? $tel : null;
    } catch (Throwable $e) {
        return null;
    }
}

function sms_modo_descricao(): string
{
    return match (SMS_MODO) {
        'twilio'          => sms_gateway_configurado()
            ? 'SMS automático (Twilio — API paga)'
            : 'Links grátis (Twilio não configurado)',
        'africastalking'  => sms_gateway_configurado()
            ? 'SMS automático (Africa\'s Talking — API paga)'
            : 'Links grátis (Africa\'s Talking não configurado)',
        'log'             => 'Modo teste (registo em logs/sms.log)',
        'off'             => 'Envio desactivado',
        default           => 'Grátis — WhatsApp ou app SMS (sem API paga)',
    };
}
