<?php
if (!defined('_PS_VERSION_'))
    exit;

require_once _PS_MODULE_DIR_ . 'openaiprompts/classes/PromptManager.php';

class FrikGptClient
{
    /** Cambia si usas otra ruta/formato */
    public static function getApiKey()
    {
        $file = _PS_MODULE_DIR_ . 'frikgestiontransportista/secrets/api_openai.json';
        if (!file_exists($file))
            return '';
        $json = @file_get_contents($file);
        $cfg = @json_decode($json, true);
        return !empty($cfg['api_key']) ? $cfg['api_key'] : '';
    }

    /**
     * Llama a OpenAI con un prompt del PromptManager.
     * @param string $grupo   (ej. 'transportistas')
     * @param string $nombre  (ej. 'asignar_transporte_internacional')
     * @param string $user_context Mensaje user
     * @param array  $vars    Sustituciones {{clave}} -> valor en el system prompt
     * @return array Decodificado (array) o ['error'=>...]
     */
    public static function callWithPrompt($grupo, $nombre, $user_context, array $vars = array())
    {
        $p = PromptManager::obtenerPrompt($grupo, $nombre);
        if (!$p || empty($p['prompt'])) {
            return array('error' => "Prompt '{$nombre}' no encontrado en grupo '{$grupo}'");
        }

        $system = $p['prompt'];
        foreach ($vars as $k => $v) {
            $system = str_replace('{{' . $k . '}}', $v, $system);
        }

        $apiKey = self::getApiKey();
        if (!$apiKey)
            return array('error' => 'API key OpenAI no configurada');

        $post = array(
            'model' => $p['modelo'],
            'temperature' => (float) $p['temperature'],
            'max_tokens' => (int) $p['max_tokens'],
            'messages' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $user_context),
            ),
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $resp = curl_exec($ch);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            return array('error' => 'cURL: ' . $err, 'post' => $post);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            return array('error' => "HTTP {$code}: " . $resp, 'post' => $post);
        }

        $data = json_decode($resp, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            return array('error' => 'Respuesta sin contenido', 'post' => $post);
        }

        $content = trim($data['choices'][0]['message']['content']);

        // Si el prompt devuelve JSON dentro de ```json ... ```
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $content, $m)) {
            $content = $m[1];
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return array('error' => 'No se pudo decodificar JSON de salida', 'post' => $post, 'respuesta' => $resp);
        }

        // Normaliza strings con entidades HTML por si el prompt las usara
        array_walk_recursive($decoded, function (&$v) {
            if (is_string($v))
                $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        });

        return $decoded;
    }
}
