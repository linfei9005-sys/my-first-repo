<?php
namespace app\api\controller\v1;

use app\api\controller\Base;
use think\Cache;

/**
 * OpenAI-compatible Gateway (Phase 3)
 *
 * Endpoint:
 *   POST /api.php/v1.chat/completions
 *
 * Features:
 * - Transparent SSE stream proxy (透传成功 chunk，不重解析)
 * - Smart routing + failover (cached pool_status)
 * - Basic logging: runtime/log/api_access.log (first_byte_ms, total_latency_ms)
 */
class Chat extends Base
{
    public function completions()
    {
        $this->handleCors();

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'POST';
        if ($method === 'OPTIONS') {
            $this->respondRaw('', 204, 'text/plain; charset=utf-8');
        }
        if ($method !== 'POST') {
            $this->respondJson([
                'error' => [
                    'message' => 'Only POST supported',
                    'type' => 'invalid_request_error',
                    'code' => 'method_not_allowed',
                ],
            ], 405);
        }

        $t0 = microtime(true);
        $firstByteMs = null;

        $raw = @file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            $this->respondJson([
                'error' => [
                    'message' => 'Empty request body',
                    'type' => 'invalid_request_error',
                    'code' => 'empty_body',
                ],
            ], 400);
        }

        $req = json_decode($raw, true);
        if (!is_array($req)) {
            $this->respondJson([
                'error' => [
                    'message' => 'Invalid JSON body',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_json',
                ],
            ], 400);
        }

        $stream = !empty($req['stream']);
        $modelIn = isset($req['model']) ? (string)$req['model'] : '';

        $route = $this->decideRoute($modelIn, $req);
        if (!$route['ok']) {
            $this->respondServiceUnavailable($stream, $t0, $firstByteMs, $route['error']);
        }

        $provider = $route['provider'];       // siliconflow|groq
        $endpoint = $route['endpoint'];       // full URL
        $apiKey = $route['api_key'];
        $modelMapped = $route['model_mapped'];

        // Apply model mapping
        $req['model'] = $modelMapped;

        if ($stream) {
            // If request asked for stream, force upstream stream
            $req['stream'] = true;
            $this->startSseResponse(200);

            $upstreamStatus = 0;
            $errorBody = '';
            $this->curlStreamProxy(
                $endpoint,
                $apiKey,
                $req,
                $upstreamStatus,
                $errorBody,
                function () use (&$firstByteMs, $t0) {
                    if ($firstByteMs === null) {
                        $firstByteMs = (int)round((microtime(true) - $t0) * 1000);
                    }
                }
            );

            // If upstream returned error, emit a single SSE error chunk + [DONE]
            if ($upstreamStatus >= 400) {
                $msg = 'Upstream error';
                $decoded = json_decode($errorBody, true);
                if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                    $msg = $decoded['error']['message'];
                }

                $this->sseData(json_encode([
                    'error' => [
                        'message' => $msg,
                        'type' => 'server_error',
                        'code' => 'upstream_error',
                    ],
                ], JSON_UNESCAPED_UNICODE));
                $this->sseData('[DONE]');
            }

            $totalMs = (int)round((microtime(true) - $t0) * 1000);
            $this->logAccess([
                'provider' => $provider,
                'status_code' => $upstreamStatus > 0 ? $upstreamStatus : 200,
                'first_byte_ms' => $firstByteMs,
                'total_latency_ms' => $totalMs,
                'path' => '/api.php/v1.chat/completions',
                'stream' => true,
                'note' => '',
            ]);
            exit;
        }

        $resp = $this->curlJsonProxy($endpoint, $apiKey, $req);
        $status = (int)$resp['status_code'];
        $totalMs = (int)round((microtime(true) - $t0) * 1000);

        $this->logAccess([
            'provider' => $provider,
            'status_code' => $status,
            'first_byte_ms' => null,
            'total_latency_ms' => $totalMs,
            'path' => '/api.php/v1.chat/completions',
            'stream' => false,
            'note' => '',
        ]);

        $this->respondRaw($resp['body'], $status, $resp['content_type']);
    }

    private function respondServiceUnavailable($stream, $t0, $firstByteMs, $note)
    {
        $totalMs = (int)round((microtime(true) - $t0) * 1000);
        $this->logAccess([
            'provider' => '',
            'status_code' => 503,
            'first_byte_ms' => $firstByteMs,
            'total_latency_ms' => $totalMs,
            'path' => '/api.php/v1.chat/completions',
            'stream' => !empty($stream),
            'note' => (string)$note,
        ]);

        if (!empty($stream)) {
            // In stream mode, respond as SSE even when unavailable (so clients can still parse)
            $this->startSseResponse(503);
            $this->sseData(json_encode([
                'error' => [
                    'message' => 'Service Unavailable',
                    'type' => 'server_error',
                    'code' => 'service_unavailable',
                ],
            ], JSON_UNESCAPED_UNICODE));
            $this->sseData('[DONE]');
            exit;
        }

        $this->respondJson([
            'error' => [
                'message' => 'Service Unavailable',
                'type' => 'server_error',
                'code' => 'service_unavailable',
            ],
        ], 503);
    }

    /**
     * Smart routing (cached pool_status_v2 from Parse::pool_status).
     * - Model mapping:
     *   - "deepseek" => SiliconFlow
     *   - "llama"    => Groq
     * - Failover: if preferred is down, route to the other if available (maps model to provider default).
     */
    private function decideRoute($modelIn, $req)
    {
        $modelLower = strtolower(trim((string)$modelIn));

        $st = Cache::get('ps_pool_status_v2');
        $sfReady = is_array($st) && isset($st['siliconflow']['ready']) ? (bool)$st['siliconflow']['ready'] : null;
        $gqReady = is_array($st) && isset($st['groq']['ready']) ? (bool)$st['groq']['ready'] : null;

        if ($sfReady === null) {
            $sfReady = ($this->getSiliconFlowApiKey() !== '');
        }
        if ($gqReady === null) {
            $gqReady = ($this->getGroqApiKey() !== '');
        }

        $prefer = '';
        if ($modelLower === 'deepseek' || strpos($modelLower, 'deepseek') !== false) {
            $prefer = 'siliconflow';
        } elseif ($modelLower === 'llama' || strpos($modelLower, 'llama') !== false) {
            $prefer = 'groq';
        } else {
            $prefer = $this->containsChinese($this->messagesToText($req)) ? 'siliconflow' : 'groq';
        }

        $chosen = $prefer;
        if ($chosen === 'siliconflow' && !$sfReady && $gqReady) {
            $chosen = 'groq';
        } elseif ($chosen === 'groq' && !$gqReady && $sfReady) {
            $chosen = 'siliconflow';
        }

        if ($chosen === 'siliconflow' && !$sfReady) {
            return ['ok' => false, 'error' => 'all_providers_down'];
        }
        if ($chosen === 'groq' && !$gqReady) {
            return ['ok' => false, 'error' => 'all_providers_down'];
        }

        if ($chosen === 'siliconflow') {
            $key = $this->getSiliconFlowApiKey();
            $endpoint = rtrim($this->getSiliconFlowBaseUrl(), '/') . '/chat/completions';
            $modelMapped = $this->mapModelForProvider($modelIn, 'siliconflow');
            return [
                'ok' => true,
                'provider' => 'siliconflow',
                'endpoint' => $endpoint,
                'api_key' => $key,
                'model_mapped' => $modelMapped,
            ];
        }

        $key = $this->getGroqApiKey();
        $endpoint = rtrim($this->getGroqBaseUrl(), '/') . '/chat/completions';
        $modelMapped = $this->mapModelForProvider($modelIn, 'groq');
        return [
            'ok' => true,
            'provider' => 'groq',
            'endpoint' => $endpoint,
            'api_key' => $key,
            'model_mapped' => $modelMapped,
        ];
    }

    private function mapModelForProvider($modelIn, $provider)
    {
        $m = strtolower(trim((string)$modelIn));
        if ($provider === 'siliconflow') {
            if ($m === 'deepseek' || strpos($m, 'deepseek') !== false) {
                return $this->getSiliconFlowModel();
            }
            return ((string)$modelIn !== '') ? (string)$modelIn : $this->getSiliconFlowModel();
        }
        // groq
        if ($m === 'llama' || strpos($m, 'llama') !== false) {
            return $this->getGroqModel();
        }
        return ((string)$modelIn !== '') ? (string)$modelIn : $this->getGroqModel();
    }

    private function curlJsonProxy($endpoint, $apiKey, $payloadArr)
    {
        $body = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            return [
                'status_code' => 500,
                'body' => '{"error":{"message":"json_encode_failed"}}',
                'content_type' => 'application/json; charset=utf-8',
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'status_code' => 500,
                'body' => '{"error":{"message":"curl_not_available"}}',
                'content_type' => 'application/json; charset=utf-8',
            ];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $respBody = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($respBody === false) {
            return [
                'status_code' => 502,
                'body' => json_encode(['error' => ['message' => 'curl_error:' . $err]], JSON_UNESCAPED_UNICODE),
                'content_type' => 'application/json; charset=utf-8',
            ];
        }
        if ($ct === '') {
            $ct = 'application/json; charset=utf-8';
        }

        return [
            'status_code' => $status ?: 200,
            'body' => (string)$respBody,
            'content_type' => $ct,
        ];
    }

    /**
     * Transparent stream proxy:
     * - Success (2xx): echo chunks as-is + flush
     * - Error (>=400): buffer body; caller will emit a single SSE error chunk
     */
    private function curlStreamProxy($endpoint, $apiKey, $payloadArr, &$upstreamStatus, &$errorBody, $onFirstByte)
    {
        $body = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            $upstreamStatus = 500;
            $errorBody = '{"error":{"message":"json_encode_failed"}}';
            return;
        }

        if (!function_exists('curl_init')) {
            $upstreamStatus = 500;
            $errorBody = '{"error":{"message":"curl_not_available"}}';
            return;
        }

        $upstreamStatus = 0;
        $errorBody = '';

        $this->disableOutputBuffering();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 0);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$upstreamStatus) {
            $len = strlen($headerLine);
            if (stripos($headerLine, 'HTTP/') === 0) {
                $parts = explode(' ', trim($headerLine));
                if (isset($parts[1])) {
                    $upstreamStatus = (int)$parts[1];
                }
            }
            return $len;
        });

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$upstreamStatus, &$errorBody, $onFirstByte) {
            $len = strlen($chunk);
            if ($len <= 0) {
                return 0;
            }
            if ($onFirstByte) {
                $onFirstByte();
            }

            if ($upstreamStatus >= 400) {
                $errorBody .= $chunk;
                return $len;
            }

            echo $chunk;
            @flush();
            return $len;
        });

        curl_exec($ch);
        if ($upstreamStatus <= 0) {
            $upstreamStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }
        curl_close($ch);
    }

    private function startSseResponse($statusCode)
    {
        if (!headers_sent()) {
            http_response_code((int)$statusCode);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-transform');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }
        @flush();
    }

    private function sseData($data)
    {
        echo "data: " . $data . "\n\n";
        @flush();
    }

    private function disableOutputBuffering()
    {
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(1);
    }

    private function logAccess($row)
    {
        $dir = ROOT_PATH . 'runtime/log';
        @mkdir($dir, 0755, true);
        $path = $dir . '/api_access.log';

        $record = [
            'ts' => time(),
            'provider' => isset($row['provider']) ? (string)$row['provider'] : '',
            'status_code' => isset($row['status_code']) ? (int)$row['status_code'] : 0,
            'first_byte_ms' => array_key_exists('first_byte_ms', $row) ? $row['first_byte_ms'] : null,
            'total_latency_ms' => isset($row['total_latency_ms']) ? (int)$row['total_latency_ms'] : 0,
            'path' => isset($row['path']) ? (string)$row['path'] : '',
            'stream' => !empty($row['stream']),
            'ip' => $this->getClientIp(),
            'note' => isset($row['note']) ? (string)$row['note'] : '',
        ];

        $line = json_encode($record, JSON_UNESCAPED_UNICODE);
        if (is_string($line)) {
            @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function messagesToText($req)
    {
        $msgs = isset($req['messages']) && is_array($req['messages']) ? $req['messages'] : [];
        $out = '';
        foreach ($msgs as $m) {
            if (is_array($m) && isset($m['content']) && is_string($m['content'])) {
                $out .= $m['content'] . "\n";
            }
        }
        return $out;
    }

    private function containsChinese($text)
    {
        return preg_match('/[\\x{4E00}-\\x{9FFF}]/u', (string)$text) === 1;
    }

    private function getSiliconFlowApiKey()
    {
        $key = '';
        if (function_exists('config')) {
            $tmp = config('ps_shell.siliconflow_api_key');
            if (is_string($tmp)) {
                $key = trim($tmp);
            }
        }
        if ($key === '') {
            $tmp = getenv('SILICONFLOW_API_KEY');
            if (is_string($tmp)) {
                $key = trim($tmp);
            }
        }
        return $this->isPlaceholderKey($key) ? '' : $key;
    }

    private function getGroqApiKey()
    {
        $key = '';
        if (function_exists('config')) {
            $tmp = config('ps_shell.groq_api_key');
            if (is_string($tmp)) {
                $key = trim($tmp);
            }
        }
        if ($key === '') {
            $tmp = getenv('GROQ_API_KEY');
            if (is_string($tmp)) {
                $key = trim($tmp);
            }
        }
        return $this->isPlaceholderKey($key) ? '' : $key;
    }

    private function getSiliconFlowBaseUrl()
    {
        $base = '';
        if (function_exists('config')) {
            $tmp = config('ps_shell.siliconflow_base_url');
            if (is_string($tmp)) {
                $base = trim($tmp);
            }
        }
        if ($base === '') {
            $base = 'https://api.siliconflow.com/v1';
        }
        return rtrim($base, '/');
    }

    private function getGroqBaseUrl()
    {
        $base = '';
        if (function_exists('config')) {
            $tmp = config('ps_shell.groq_base_url');
            if (is_string($tmp)) {
                $base = trim($tmp);
            }
        }
        if ($base === '') {
            $base = 'https://api.groq.com/openai/v1';
        }
        return rtrim($base, '/');
    }

    private function getSiliconFlowModel()
    {
        $model = '';
        if (function_exists('config')) {
            $tmp = config('ps_shell.siliconflow_model');
            if (is_string($tmp)) {
                $model = trim($tmp);
            }
        }
        if ($model === '') {
            $model = 'deepseek-ai/DeepSeek-V3';
        }
        return $model;
    }

    private function getGroqModel()
    {
        $model = '';
        if (function_exists('config')) {
            $tmp = config('ps_shell.groq_model');
            if (is_string($tmp)) {
                $model = trim($tmp);
            }
        }
        if ($model === '') {
            $model = 'llama-3.1-70b-versatile';
        }
        return $model;
    }

    private function isPlaceholderKey($key)
    {
        $k = trim((string)$key);
        return $k !== '' && strpos($k, 'REPLACE_WITH_') === 0;
    }

    private function handleCors()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST,OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
    }

    private function respondJson($payload, $statusCode)
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{"error":{"message":"JSON encode failed","type":"server_error","code":"json_encode_failed"}}';
            $statusCode = 500;
        }
        $this->respondRaw($json, $statusCode, 'application/json; charset=utf-8');
    }

    private function respondRaw($body, $statusCode, $contentType)
    {
        if (!headers_sent()) {
            header('Content-Type: ' . $contentType);
            http_response_code((int)$statusCode);
        }
        echo (string)$body;
        exit;
    }

    private function getClientIp()
    {
        $candidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];
        foreach ($candidates as $k) {
            if (!isset($_SERVER[$k])) {
                continue;
            }
            $v = trim((string)$_SERVER[$k]);
            if ($v === '') {
                continue;
            }
            if ($k === 'HTTP_X_FORWARDED_FOR' && strpos($v, ',') !== false) {
                $v = trim(explode(',', $v)[0]);
            }
            return $v;
        }
        return '';
    }
}

