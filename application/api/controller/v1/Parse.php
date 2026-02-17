<?php
namespace app\api\controller\v1;

use app\api\controller\Base;
use think\Cache;

/**
 * /api/v1/parse (通过 api.php 入口路由到这里)
 *
 * 目标：把任意输入数据“尽量解析”为 JSON 结构，并返回标准化响应。
 * - 低成本：无外部依赖、纯字符串解析
 * - 高并发：O(n) 单次扫描为主，限制输入大小
 * - 可控安全：可选 API Key（仅当环境变量配置时启用）
 */
class Parse extends Base
{
    // 256KiB：足够解析常见 payload，同时避免被大包拖垮
    const MAX_INPUT_BYTES = 262144;
    const DEEPSEEK_DEFAULT_BASE_URL = 'https://api.deepseek.com';
    const DEEPSEEK_DEFAULT_MODEL = 'deepseek-chat';

    public function index()
    {
        $this->handleCors();
        $t0 = microtime(true);

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method === 'OPTIONS') {
            $this->respondRaw('', 204, 'text/plain; charset=utf-8');
        }

        if ($method !== 'GET' && $method !== 'POST') {
            $this->respondJson([
                'ok' => false,
                'error' => ['code' => 'method_not_allowed', 'message' => 'Only GET/POST supported'],
            ], 405);
        }

        // Phase 2 -> Phase Free-Pool: keep public, but rate-limit by IP
        $clientIp = $this->getClientIp();
        $this->enforceRateLimit($clientIp);

        $this->enforceOptionalApiKey();

        $mode = $this->getParam('mode', 'auto');
        $mode = strtolower(trim((string)$mode));
        $targetLang = $this->getParam('target_lang', '');
        if (!is_string($targetLang)) {
            $targetLang = '';
        }
        $targetLang = $this->normalizeTargetLang($targetLang);
        if ($targetLang === '__invalid__') {
            $this->respondJson([
                'ok' => false,
                'error' => ['code' => 'invalid_target_lang', 'message' => 'target_lang only supports: zh (optional)'],
            ], 400);
        }
        $instruction = $this->getParam('instruction', '');
        if (!is_string($instruction)) {
            $instruction = '';
        }
        $instruction = trim($instruction);
        if ($instruction === '') {
            $instruction = $this->defaultDeepSeekInstruction($targetLang);
        }
        // 内置 Prompt 预设（强制执行，不允许被 instruction 覆盖）
        // 要求：switch-case 逻辑（便于后续不断扩展预设）
        $contractType = 'core';
        switch ($mode) {
            case 'ecom':
                $instruction = $this->promptEcomStandardizer($targetLang);
                $contractType = 'ecom';
                break;
            case 'news':
                $instruction = $this->promptNewsExtractor($targetLang);
                $contractType = 'news';
                break;
            case 'social':
                $instruction = $this->promptSocialAnalyzer($targetLang);
                $contractType = 'social';
                break;
            case 'auto':
                // 门面模式：DeepSeek 自行判断类型并生成最规整结构
                $instruction = $this->promptAutoSmart($targetLang);
                $contractType = 'auto';
                break;
            default:
                // 其他模式：按参数 instruction（或默认 core 指令）执行
                $contractType = 'core';
                break;
        }
        // 无论是内置 prompt 还是用户自定义 instruction，都强制注入语言对齐协议
        $instruction = $this->injectLangAlignment($instruction, $targetLang);

        $data = $this->getParam('data', null);
        $url = $this->getParam('url', null);
        $raw = $this->readRawBody();

        // 兼容：优先 data，其次 raw；url 单独字段保留在 meta 里（不自动抓取远程内容）
        $payload = null;
        if ($data !== null && $data !== '') {
            $payload = (string)$data;
        } elseif ($raw !== '') {
            $payload = (string)$raw;
        } else {
            $payload = '';
        }

        if (strlen($payload) > self::MAX_INPUT_BYTES) {
            $this->respondJson([
                'ok' => false,
                'error' => ['code' => 'payload_too_large', 'message' => 'Payload exceeds limit'],
                'meta' => ['max_bytes' => self::MAX_INPUT_BYTES],
            ], 413);
        }

        $requestId = $this->makeRequestId();
        $ts = time();

        $result = $this->parseByMode($payload, $mode, $instruction, $contractType);
        if (!$result['ok']) {
            $status = isset($result['http_status']) ? (int)$result['http_status'] : 400;
            $this->writeMonetizationLog([
                'ok' => false,
                'request_id' => $requestId,
                'ts' => $ts,
                'mode' => $mode,
                'resolved_mode' => isset($result['mode']) ? $result['mode'] : null,
                'client_ip' => $clientIp,
                'input_bytes' => strlen($payload),
                'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
                'deepseek' => isset($result['deepseek']) ? $result['deepseek'] : null,
                'error_code' => $result['error_code'],
            ]);
            $this->respondJson([
                'ok' => false,
                'request_id' => $requestId,
                'ts' => $ts,
                'error' => [
                    'code' => $result['error_code'],
                    'message' => $result['error_message'],
                ],
                'meta' => [
                    'mode' => $mode,
                    'input_bytes' => strlen($payload),
                    'url' => $url,
                ],
            ], $status);
        }

        $this->writeMonetizationLog([
            'ok' => true,
            'request_id' => $requestId,
            'ts' => $ts,
            'mode' => $mode,
            'resolved_mode' => $result['mode'],
            'client_ip' => $clientIp,
            'input_bytes' => strlen($payload),
            'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
            'deepseek' => isset($result['deepseek']) ? $result['deepseek'] : null,
        ]);
        $this->respondJson([
            'ok' => true,
            'request_id' => $requestId,
            'ts' => $ts,
            'data' => $result['data'],
            'meta' => [
                'mode' => $result['mode'],
                'input_bytes' => strlen($payload),
                'url' => $url,
                'deepseek' => isset($result['deepseek']) ? $result['deepseek'] : null,
                'target_lang' => $targetLang === '' ? null : $targetLang,
            ],
        ], 200);
    }

    /**
     * 轻量健康检查：用于探活、监控、CDN warmup。
     */
    public function health()
    {
        $this->handleCors();
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method === 'OPTIONS') {
            $this->respondRaw('', 204, 'text/plain; charset=utf-8');
        }
        $this->respondJson([
            'ok' => true,
            'service' => 'api.v1.parse',
            'ts' => time(),
        ], 200);
    }

    /**
     * Free-Pool self-check (for Gateway Live Status).
     * URL: /api.php/v1.parse/pool_status
     */
    public function pool_status()
    {
        $this->handleCors();
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method === 'OPTIONS') {
            $this->respondRaw('', 204, 'text/plain; charset=utf-8');
        }
        if ($method !== 'GET') {
            $this->respondJson([
                'ok' => false,
                'error' => ['code' => 'method_not_allowed', 'message' => 'Only GET supported'],
            ], 405);
        }

        $sf = $this->getSiliconFlowApiKey() !== '';
        $gq = $this->getGroqApiKey() !== '';
        $premium = $this->getDeepSeekApiKey() !== '';

        $this->respondJson([
            'ok' => true,
            'data' => [
                'free_pool_ready' => ($sf || $gq),
                'siliconflow_ready' => $sf,
                'groq_ready' => $gq,
                'premium_ready' => $premium,
            ],
        ], 200);
    }

    /**
     * Free-Pool Armor: Rate limit by IP (default: 10/min).
     */
    private function enforceRateLimit($clientIp)
    {
        $limit = 10;
        if (function_exists('config')) {
            $tmp = config('ps_shell.rate_limit_per_minute');
            if (is_numeric($tmp) && (int)$tmp > 0) {
                $limit = (int)$tmp;
            }
        }

        $ip = trim((string)$clientIp);
        if ($ip === '') {
            $ip = 'unknown';
        }
        $bucket = (int)floor(time() / 60);
        $key = 'ps_rl:' . md5($ip) . ':' . $bucket;

        $count = Cache::get($key);
        $count = is_numeric($count) ? (int)$count : 0;
        $count++;
        // TTL 覆盖 1 分钟窗口，稍微放宽避免边界抖动
        Cache::set($key, $count, 70);

        if ($count > $limit) {
            $this->respondJson([
                'ok' => false,
                'error' => [
                    'code' => 'rate_limited',
                    'message' => 'Please wait or upgrade to VIP (USDT)',
                ],
                'meta' => [
                    'limit_per_minute' => $limit,
                ],
            ], 429);
        }
    }

    private function getApiKeyWhitelist()
    {
        $keys = [];
        if (function_exists('config')) {
            $tmp = config('ps_shell.api_keys');
            if (is_array($tmp)) {
                $keys = array_merge($keys, $tmp);
            }
        }
        $env = getenv('PS_API_KEYS');
        if (is_string($env) && trim($env) !== '') {
            foreach (explode(',', $env) as $k) {
                $k = trim($k);
                if ($k !== '') {
                    $keys[] = $k;
                }
            }
        }
        $keys = array_values(array_unique(array_filter($keys, function ($v) {
            return is_string($v) && trim($v) !== '';
        })));
        return $keys;
    }

    private function extractBearerToken($authorizationHeader)
    {
        $h = trim((string)$authorizationHeader);
        if ($h === '' || stripos($h, 'bearer ') !== 0) {
            return '';
        }
        return trim(substr($h, 7));
    }

    /**
     * Optional Authorization:
     * - 无 Key：Free-Pool (SiliconFlow/Groq)
     * - 有 Key 且在白名单：Premium-Core (DeepSeek 官方)
     */
    private function isPremiumRequest()
    {
        $token = $this->extractBearerToken($this->getHeader('authorization'));
        if ($token === '') {
            return false;
        }
        $allow = $this->getApiKeyWhitelist();
        if (empty($allow)) {
            return false;
        }
        return in_array($token, $allow, true);
    }

    private function parseByMode($payload, $mode, $instruction, $contractType)
    {
        $payload = (string)$payload;
        $instruction = (string)$instruction;
        $contractType = (string)$contractType;

        $try = [];
        if ($mode === 'auto') {
            // auto：先直通 JSON；否则 DeepSeek 智能自判结构；若未配置 DeepSeek 再走本地兜底
            $try = ['json', 'deepseek_auto', 'query', 'kv', 'csv'];
        } elseif (in_array($mode, ['json', 'query', 'kv', 'csv', 'deepseek', 'ecom', 'news', 'social'], true)) {
            $try = [$mode];
        } else {
            return [
                'ok' => false,
                'error_code' => 'invalid_mode',
                'error_message' => 'mode must be one of: auto, json, query, kv, csv, deepseek, ecom, news, social',
                'mode' => 'invalid',
            ];
        }

        foreach ($try as $m) {
            $parsed = null;
            $ok = false;
            $deepseekMeta = null;

            if ($m === 'json') {
                $ok = $this->tryParseJson($payload, $parsed);
            } elseif ($m === 'query') {
                $ok = $this->tryParseQueryString($payload, $parsed);
            } elseif ($m === 'kv') {
                $ok = $this->tryParseKeyValueLines($payload, $parsed);
            } elseif ($m === 'csv') {
                $ok = $this->tryParseCsv($payload, $parsed);
            } elseif ($m === 'deepseek_auto') {
                // auto：DeepSeek 未配置时要允许继续走本地兜底
                $r = $this->tryParseDeepSeek($payload, $instruction, $contractType);
                if ($r['ok']) {
                    $ok = true;
                    $parsed = $r['data'];
                    $deepseekMeta = $r['deepseek'];
                } else {
                    if (isset($r['error_code']) && $r['error_code'] === 'deepseek_not_configured') {
                        $ok = false;
                        // 继续尝试 query/kv/csv
                    } else {
                        return $r;
                    }
                }
            } elseif (in_array($m, ['deepseek', 'ecom', 'news', 'social'], true)) {
                // 这些模式明确要求 DeepSeek：失败直接返回（含合同不合格 422）
                $ct = $m;
                $r = $this->tryParseDeepSeek($payload, $instruction, $ct);
                $ok = $r['ok'];
                if ($ok) {
                    $parsed = $r['data'];
                    $deepseekMeta = $r['deepseek'];
                } else {
                    return $r;
                }
            }

            if ($ok) {
                // 返回 mode：deepseek_auto 实际对外仍是 auto
                $resMode = ($m === 'deepseek_auto') ? 'auto' : $m;
                $res = ['ok' => true, 'mode' => $resMode, 'data' => $parsed];
                if ($deepseekMeta !== null) {
                    $res['deepseek'] = $deepseekMeta;
                }
                return $res;
            }
        }

        return [
            'ok' => false,
            'error_code' => 'parse_failed',
            'error_message' => 'Unable to parse payload with selected mode',
            'mode' => $mode,
        ];
    }

    private function tryParseDeepSeek($rawText, $instruction, $contractType = 'core')
    {
        $rawText = trim((string)$rawText);
        $contractType = strtolower(trim((string)$contractType));
        if ($rawText === '') {
            return [
                'ok' => false,
                'error_code' => 'empty_payload',
                'error_message' => 'Payload is empty',
                'http_status' => 400,
                'mode' => 'deepseek',
            ];
        }

        $resp = $this->callAiByTier($rawText, $instruction);
        if (!$resp['ok']) {
            return [
                'ok' => false,
                'error_code' => 'ai_call_failed',
                'error_message' => $resp['error_message'],
                'http_status' => 502,
                'mode' => 'deepseek',
                'deepseek' => [
                    'provider' => $resp['provider'],
                    'tier' => $resp['tier'],
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'model' => $resp['model'],
                ],
            ];
        }

        $obj = $resp['json_object'];
        $missing = [];
        $contractOk = false;
        if ($contractType === 'ecom') {
            $contractOk = $this->validateEcomContract($obj, $missing);
        } elseif ($contractType === 'news') {
            $contractOk = $this->validateNewsContract($obj, $missing);
        } elseif ($contractType === 'social') {
            $contractOk = $this->validateSocialContract($obj, $missing);
        } elseif ($contractType === 'auto') {
            $contractOk = $this->validateAutoContract($obj, $missing);
        } else {
            $contractOk = $this->validateDeepSeekContract($obj, $missing);
        }
        if (!$contractOk) {
            return [
                'ok' => false,
                'error_code' => 'contract_violation',
                'error_message' => 'DeepSeek JSON missing required fields: ' . join(',', $missing),
                'http_status' => 422,
                'mode' => 'deepseek',
                'deepseek' => [
                    'provider' => $resp['provider'],
                    'tier' => $resp['tier'],
                    'input_tokens' => (int)$resp['usage']['prompt_tokens'],
                    'output_tokens' => (int)$resp['usage']['completion_tokens'],
                    'model' => $resp['model'],
                ],
            ];
        }

        return [
            'ok' => true,
            'mode' => 'deepseek',
            'data' => $obj,
            'deepseek' => [
                'provider' => $resp['provider'],
                'tier' => $resp['tier'],
                'input_tokens' => (int)$resp['usage']['prompt_tokens'],
                'output_tokens' => (int)$resp['usage']['completion_tokens'],
                'model' => $resp['model'],
            ],
        ];
    }

    private function tryParseJson($payload, &$out)
    {
        $s = trim((string)$payload);
        if ($s === '') {
            return false;
        }
        $first = $s[0];
        if ($first !== '{' && $first !== '[') {
            return false;
        }
        $decoded = json_decode($s, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        $out = $decoded;
        return true;
    }

    private function tryParseQueryString($payload, &$out)
    {
        $s = trim((string)$payload);
        if ($s === '') {
            return false;
        }
        // 粗判：包含 '=' 或 '&' 才像 querystring
        if (strpos($s, '=') === false) {
            return false;
        }
        $arr = [];
        // parse_str 会把点号转下划线，这是 PHP 行为；属于可接受的“安全归一化”
        @parse_str($s, $arr);
        if (!is_array($arr) || empty($arr)) {
            return false;
        }
        $out = $arr;
        return true;
    }

    private function tryParseKeyValueLines($payload, &$out)
    {
        $s = trim((string)$payload);
        if ($s === '') {
            return false;
        }
        // 要求至少一行有分隔符，否则容易误判
        if (strpos($s, '=') === false && strpos($s, ':') === false) {
            return false;
        }

        $lines = preg_split("/\\r\\n|\\n|\\r/", $s);
        if (!$lines) {
            return false;
        }
        $res = [];
        $hit = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strlen($line) >= 2 && $line[0] === '/' && $line[1] === '/') {
                continue;
            }

            $pos = strpos($line, '=');
            $sep = '=';
            if ($pos === false) {
                $pos = strpos($line, ':');
                $sep = ':';
            }
            if ($pos === false) {
                continue;
            }

            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if ($k === '') {
                continue;
            }
            $k = $this->sanitizeKey($k);
            $res[$k] = $v;
            $hit++;
        }

        if ($hit <= 0) {
            return false;
        }
        $out = $res;
        return true;
    }

    private function tryParseCsv($payload, &$out)
    {
        $s = trim((string)$payload);
        if ($s === '') {
            return false;
        }
        // 粗判：至少包含一个逗号和一行换行，避免把普通文本当 CSV
        if (strpos($s, ',') === false || (strpos($s, "\n") === false && strpos($s, "\r") === false)) {
            return false;
        }

        $lines = preg_split("/\\r\\n|\\n|\\r/", $s);
        $lines = array_values(array_filter($lines, function ($l) {
            return trim($l) !== '';
        }));
        if (count($lines) < 2) {
            return false;
        }

        $header = str_getcsv($lines[0]);
        if (empty($header)) {
            return false;
        }

        $rows = [];
        $max = min(count($lines), 1000); // 防止超长 CSV 造成大内存占用
        for ($i = 1; $i < $max; $i++) {
            $cols = str_getcsv($lines[$i]);
            $row = [];
            $n = min(count($header), count($cols));
            for ($j = 0; $j < $n; $j++) {
                $k = $this->sanitizeKey((string)$header[$j]);
                $row[$k] = $cols[$j];
            }
            $rows[] = $row;
        }

        $out = $rows;
        return true;
    }

    private function sanitizeKey($key)
    {
        $key = trim((string)$key);
        // 只保留常见安全字符，避免输出的 key 包含控制字符/奇怪分隔符
        $key = preg_replace('/[^a-zA-Z0-9_\\-\\.]/', '_', $key);
        $key = preg_replace('/_+/', '_', $key);
        $key = trim($key, '_');
        if ($key === '') {
            $key = 'key';
        }
        return $key;
    }

    private function readRawBody()
    {
        $raw = @file_get_contents('php://input');
        if (!is_string($raw)) {
            return '';
        }
        // 早期截断：避免读取超大 body 导致内存暴涨
        if (strlen($raw) > self::MAX_INPUT_BYTES) {
            return substr($raw, 0, self::MAX_INPUT_BYTES + 1);
        }
        return $raw;
    }

    private function getDeepSeekApiKey()
    {
        // 统一优先：Env::get('DEEPSEEK_API_KEY') / config('deepseek.api_key')
        $key = '';
        if (class_exists('\\think\\Env')) {
            $tmp = \think\Env::get('DEEPSEEK_API_KEY');
            if (is_string($tmp)) {
                $key = trim($tmp);
            }
        }
        if ($key === '' && function_exists('config')) {
            $tmp = config('deepseek.api_key');
            if (is_string($tmp)) {
                $key = trim($tmp);
            }
        }
        if ($key === '') {
            $tmp = getenv('DEEPSEEK_API_KEY');
            if (is_string($tmp)) {
                $key = trim($tmp);
            }
        }
        return $key;
    }

    private function getDeepSeekBaseUrl()
    {
        $base = '';
        if (function_exists('config')) {
            $tmp = config('deepseek.base_url');
            if (is_string($tmp)) {
                $base = trim($tmp);
            }
        }
        if ($base === '') {
            $tmp = getenv('DEEPSEEK_BASE_URL');
            if (is_string($tmp)) {
                $base = trim($tmp);
            }
        }
        if ($base === '') {
            $base = self::DEEPSEEK_DEFAULT_BASE_URL;
        }
        return rtrim($base, '/');
    }

    private function getDeepSeekModel()
    {
        $model = '';
        if (function_exists('config')) {
            $tmp = config('deepseek.model');
            if (is_string($tmp)) {
                $model = trim($tmp);
            }
        }
        if ($model === '') {
            $tmp = getenv('DEEPSEEK_MODEL');
            if (is_string($tmp)) {
                $model = trim($tmp);
            }
        }
        if ($model === '') {
            $model = self::DEEPSEEK_DEFAULT_MODEL;
        }
        return $model;
    }

    /**
     * OpenAI-compatible Chat Completions (curl).
     * 强制：response_format => {type: json_object}，并对 message.content 二次 json_decode 确保纯净 JSON。
     */
    private function callOpenAICompatChat($endpoint, $apiKey, $model, $raw_text, $instruction)
    {
        $payload = [
            'model' => $model,
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => (string)$instruction],
                ['role' => 'user', 'content' => (string)$raw_text],
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            return ['ok' => false, 'error_message' => 'json_encode request failed', 'model' => $model];
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error_message' => 'curl_not_available', 'model' => $model];
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'error_message' => 'curl_error:' . $curlErr, 'model' => $model];
        }
        $raw = (string)$raw;

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error_message' => 'invalid_json_response', 'model' => $model];
        }
        if ($status < 200 || $status >= 300) {
            $msg = 'http_' . $status;
            if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $msg .= ':' . $decoded['error']['message'];
            }
            return ['ok' => false, 'error_message' => $msg, 'model' => $model];
        }

        $content = '';
        if (isset($decoded['choices'][0]['message']['content']) && is_string($decoded['choices'][0]['message']['content'])) {
            $content = $decoded['choices'][0]['message']['content'];
        }
        $content = trim($content);
        if ($content === '') {
            return ['ok' => false, 'error_message' => 'empty_model_content', 'model' => $model];
        }

        // 强制“纯净 JSON”：再 decode 一次，确保是 object/array
        $jsonObj = json_decode($content, true);
        if (!is_array($jsonObj)) {
            return ['ok' => false, 'error_message' => 'model_content_not_json_object', 'model' => $model];
        }

        $usage = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ];
        if (isset($decoded['usage']) && is_array($decoded['usage'])) {
            if (isset($decoded['usage']['prompt_tokens'])) {
                $usage['prompt_tokens'] = (int)$decoded['usage']['prompt_tokens'];
            }
            if (isset($decoded['usage']['completion_tokens'])) {
                $usage['completion_tokens'] = (int)$decoded['usage']['completion_tokens'];
            }
            if (isset($decoded['usage']['total_tokens'])) {
                $usage['total_tokens'] = (int)$decoded['usage']['total_tokens'];
            }
        }

        return [
            'ok' => true,
            'model' => $model,
            'usage' => $usage,
            'json_object' => $jsonObj,
        ];
    }

    private function callDeepSeekPremium($raw_text, $instruction)
    {
        $apiKey = $this->getDeepSeekApiKey();
        $model = $this->getDeepSeekModel();
        if ($apiKey === '') {
            return ['ok' => false, 'error_message' => 'deepseek_not_configured', 'model' => $model];
        }
        $endpoint = rtrim($this->getDeepSeekBaseUrl(), '/') . '/v1/chat/completions';
        return $this->callOpenAICompatChat($endpoint, $apiKey, $model, $raw_text, $instruction);
    }

    private function getSiliconFlowApiKey()
    {
        $key = '';
        if (class_exists('\\think\\Env')) {
            $tmp = \think\Env::get('SILICONFLOW_API_KEY');
            if (is_string($tmp)) {
                $key = trim($tmp);
            }
        }
        if ($key === '' && function_exists('config')) {
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
        if ($this->isPlaceholderKey($key)) {
            return '';
        }
        return $key;
    }

    private function getGroqApiKey()
    {
        $key = '';
        if (class_exists('\\think\\Env')) {
            $tmp = \think\Env::get('GROQ_API_KEY');
            if (is_string($tmp)) {
                $key = trim($tmp);
            }
        }
        if ($key === '' && function_exists('config')) {
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
        if ($this->isPlaceholderKey($key)) {
            return '';
        }
        return $key;
    }

    private function isPlaceholderKey($key)
    {
        $k = trim((string)$key);
        if ($k === '') {
            return false;
        }
        // Treat placeholder strings as "not configured" so UI doesn't show false green.
        return strpos($k, 'REPLACE_WITH_') === 0;
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
            $tmp = getenv('SILICONFLOW_BASE_URL');
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
            $tmp = getenv('GROQ_BASE_URL');
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
            $tmp = getenv('SILICONFLOW_MODEL');
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
            $tmp = getenv('GROQ_MODEL');
            if (is_string($tmp)) {
                $model = trim($tmp);
            }
        }
        if ($model === '') {
            // Groq 常见命名是 llama-3.1-70b-versatile；允许通过 GROQ_MODEL 覆盖
            $model = 'llama-3.1-70b-versatile';
        }
        return $model;
    }

    private function callSiliconFlow($raw_text, $instruction)
    {
        $apiKey = $this->getSiliconFlowApiKey();
        $model = $this->getSiliconFlowModel();
        if ($apiKey === '') {
            return ['ok' => false, 'error_message' => 'siliconflow_not_configured', 'model' => $model];
        }
        $endpoint = $this->getSiliconFlowBaseUrl() . '/chat/completions';
        return $this->callOpenAICompatChat($endpoint, $apiKey, $model, $raw_text, $instruction);
    }

    private function callGroq($raw_text, $instruction)
    {
        $apiKey = $this->getGroqApiKey();
        $model = $this->getGroqModel();
        if ($apiKey === '') {
            return ['ok' => false, 'error_message' => 'groq_not_configured', 'model' => $model];
        }
        $endpoint = $this->getGroqBaseUrl() . '/chat/completions';
        return $this->callOpenAICompatChat($endpoint, $apiKey, $model, $raw_text, $instruction);
    }

    private function containsChinese($text)
    {
        return preg_match('/[\\x{4E00}-\\x{9FFF}]/u', (string)$text) === 1;
    }

    /**
     * 双引擎切换：
     * - Premium (Bearer Key): DeepSeek 官方
     * - Free-Pool: 输入含中文 => SiliconFlow deepseek-v3；否则 => Groq llama-3.1-70b
     */
    private function callAiByTier($raw_text, $instruction)
    {
        if ($this->isPremiumRequest()) {
            $r = $this->callDeepSeekPremium($raw_text, $instruction);
            if ($r['ok']) {
                $r['provider'] = 'deepseek';
                $r['tier'] = 'premium';
                return $r;
            }
            // 永不宕机：Premium 不可用时降级到 Free-Pool
        }

        $useSiliconFlow = $this->containsChinese($raw_text);
        if ($useSiliconFlow) {
            $r = $this->callSiliconFlow($raw_text, $instruction);
            if ($r['ok']) {
                $r['provider'] = 'siliconflow';
                $r['tier'] = 'free';
                return $r;
            }
            // fallback to groq if available
            $r2 = $this->callGroq($raw_text, $instruction);
            if ($r2['ok']) {
                $r2['provider'] = 'groq';
                $r2['tier'] = 'free';
                return $r2;
            }
            if (
                isset($r['error_message'], $r2['error_message']) &&
                strpos((string)$r['error_message'], 'not_configured') !== false &&
                strpos((string)$r2['error_message'], 'not_configured') !== false
            ) {
                return [
                    'ok' => false,
                    'error_message' => 'free_pool_not_configured',
                    'model' => '',
                    'provider' => 'free_pool',
                    'tier' => 'free',
                ];
            }
            $r2['provider'] = 'groq';
            $r2['tier'] = 'free';
            return $r2;
        }

        $r = $this->callGroq($raw_text, $instruction);
        if ($r['ok']) {
            $r['provider'] = 'groq';
            $r['tier'] = 'free';
            return $r;
        }
        // fallback to siliconflow if available
        $r2 = $this->callSiliconFlow($raw_text, $instruction);
        if ($r2['ok']) {
            $r2['provider'] = 'siliconflow';
            $r2['tier'] = 'free';
            return $r2;
        }
        if (
            isset($r['error_message'], $r2['error_message']) &&
            strpos((string)$r['error_message'], 'not_configured') !== false &&
            strpos((string)$r2['error_message'], 'not_configured') !== false
        ) {
            return [
                'ok' => false,
                'error_message' => 'free_pool_not_configured',
                'model' => '',
                'provider' => 'free_pool',
                'tier' => 'free',
            ];
        }
        $r2['provider'] = 'siliconflow';
        $r2['tier'] = 'free';
        return $r2;
    }

    /**
     * Schema Simulation: 严格字段约束（缺字段 => 422）
     * 合同最小集：schema_version, extracted, confidence
     */
    private function validateDeepSeekContract($obj, &$missing)
    {
        $missing = [];
        if (!is_array($obj)) {
            $missing[] = 'json_object';
            return false;
        }
        if (!isset($obj['schema_version']) || !is_string($obj['schema_version']) || trim($obj['schema_version']) === '') {
            $missing[] = 'schema_version';
        }
        if (!isset($obj['extracted']) || !is_array($obj['extracted'])) {
            $missing[] = 'extracted';
        }
        if (!isset($obj['confidence']) || (!is_int($obj['confidence']) && !is_float($obj['confidence']))) {
            $missing[] = 'confidence';
        }
        return empty($missing);
    }

    /**
     * ecom_standardizer 合同（Shopify 入库友好）
     * 必填：title, price, currency, spec, skus, bullet_points
     */
    private function validateEcomContract($obj, &$missing)
    {
        $missing = [];
        if (!is_array($obj)) {
            $missing[] = 'json_object';
            return false;
        }
        if (!isset($obj['title']) || !is_string($obj['title']) || trim($obj['title']) === '') {
            $missing[] = 'title';
        }
        if (!isset($obj['price']) || !$this->isNumericLike($obj['price'])) {
            $missing[] = 'price';
        }
        if (!isset($obj['currency']) || !is_string($obj['currency']) || strlen(trim($obj['currency'])) < 3) {
            $missing[] = 'currency';
        }
        if (!isset($obj['spec']) || !is_array($obj['spec'])) {
            $missing[] = 'spec';
        }
        if (!isset($obj['skus']) || !is_array($obj['skus'])) {
            $missing[] = 'skus';
        }
        if (!isset($obj['bullet_points']) || !is_array($obj['bullet_points'])) {
            $missing[] = 'bullet_points';
        }
        return empty($missing);
    }

    /**
     * news 合同
     * 必填：title, author, published_at, summary, viewpoints, entities
     */
    private function validateNewsContract($obj, &$missing)
    {
        $missing = [];
        if (!is_array($obj)) {
            $missing[] = 'json_object';
            return false;
        }
        if (!isset($obj['title']) || !is_string($obj['title']) || trim($obj['title']) === '') {
            $missing[] = 'title';
        }
        if (!array_key_exists('author', $obj) || (!is_string($obj['author']) && !is_null($obj['author']))) {
            $missing[] = 'author';
        }
        if (!array_key_exists('published_at', $obj) || (!is_string($obj['published_at']) && !is_null($obj['published_at']))) {
            $missing[] = 'published_at';
        }
        if (!isset($obj['summary']) || !is_string($obj['summary'])) {
            $missing[] = 'summary';
        }
        if (!isset($obj['viewpoints']) || !is_array($obj['viewpoints'])) {
            $missing[] = 'viewpoints';
        }
        if (!isset($obj['entities']) || !is_array($obj['entities'])) {
            $missing[] = 'entities';
        }
        return empty($missing);
    }

    /**
     * social 合同
     * 必填：sentiment, core_demand, brands, purchase_intent
     */
    private function validateSocialContract($obj, &$missing)
    {
        $missing = [];
        if (!is_array($obj)) {
            $missing[] = 'json_object';
            return false;
        }
        // 语言自适应：sentiment 不再硬编码枚举，只要求“细粒度、非空”
        if (!isset($obj['sentiment']) || !is_string($obj['sentiment']) || trim($obj['sentiment']) === '') {
            $missing[] = 'sentiment';
        }
        if (!isset($obj['core_demand']) || !is_string($obj['core_demand'])) {
            $missing[] = 'core_demand';
        }
        if (!isset($obj['brands']) || !is_array($obj['brands'])) {
            $missing[] = 'brands';
        }
        if (!array_key_exists('purchase_intent', $obj) || !is_bool($obj['purchase_intent'])) {
            $missing[] = 'purchase_intent';
        }
        // 必须给出判断逻辑（用自然语言简述，不泄露隐私）
        if (!isset($obj['purchase_intent_reason']) || !is_string($obj['purchase_intent_reason']) || trim($obj['purchase_intent_reason']) === '') {
            $missing[] = 'purchase_intent_reason';
        }
        return empty($missing);
    }

    /**
     * auto 智能结构合同（最小稳定结构）
     * 必填：schema_version, type, data, confidence
     */
    private function validateAutoContract($obj, &$missing)
    {
        $missing = [];
        if (!is_array($obj)) {
            $missing[] = 'json_object';
            return false;
        }
        if (!isset($obj['schema_version']) || !is_string($obj['schema_version']) || trim($obj['schema_version']) === '') {
            $missing[] = 'schema_version';
        }
        if (!isset($obj['type']) || !is_string($obj['type']) || trim($obj['type']) === '') {
            $missing[] = 'type';
        } else {
            // type 作为结构分类，固定英文 snake_case（便于全球开发者无缝解析）
            $t = trim($obj['type']);
            if (!preg_match('/^[a-z0-9_]+$/', $t)) {
                $missing[] = 'type_snake_case';
            }
        }
        if (!isset($obj['data']) || !is_array($obj['data'])) {
            $missing[] = 'data';
        }
        if (!isset($obj['confidence']) || (!is_int($obj['confidence']) && !is_float($obj['confidence']))) {
            $missing[] = 'confidence';
        }
        return empty($missing);
    }

    private function isNumericLike($v)
    {
        if (is_int($v) || is_float($v)) {
            return true;
        }
        if (is_string($v)) {
            $s = trim($v);
            if ($s === '') {
                return false;
            }
            // 允许 "199.90" / "199" / "199,90"（先做简单归一）
            $s = str_replace(',', '.', $s);
            return is_numeric($s);
        }
        return false;
    }

    /**
     * Output Language Sync:
     * - 默认：内容值（summary/viewpoints/sentiment 等）跟随原文主体语言
     * - 审计：target_lang=zh 时，内容值统一输出为简体中文
     * Structural Integrity:
     * - JSON keys 永久固定英文 snake_case（由各 schema 明确要求）
     */
    private function languageSyncDirective($targetLang)
    {
        // [INTELLIGENT-LANG-ALIGNMENT] (必须原文注入到 System Prompt)
        // JSON keys 永久固定英文 snake_case；内容值默认跟随原文语言，target_lang=zh 则翻译为中文。
        return $this->langAlignmentSentence() . "\n";
    }

    private function langAlignmentSentence()
    {
        return "JSON keys must always be in English snake_case. Values must match the source language unless target_lang is specified. If target_lang=zh, translate all extracted values to Chinese.";
    }

    private function injectLangAlignment($instruction, $targetLang)
    {
        $instruction = (string)$instruction;
        $sent = $this->langAlignmentSentence();
        if (strpos($instruction, $sent) !== false) {
            return $instruction;
        }
        // 以前置方式注入，确保落入 system role 的第一段高优先级约束
        return $sent . "\n" . $instruction;
    }

    private function normalizeTargetLang($targetLang)
    {
        $t = strtolower(trim((string)$targetLang));
        if ($t === '') {
            return '';
        }
        $t = str_replace('_', '-', $t);
        if ($t === 'zh' || $t === 'zh-cn' || $t === 'zh-hans' || $t === 'cn') {
            return 'zh';
        }
        return '__invalid__';
    }

    private function defaultDeepSeekInstruction($targetLang)
    {
        $lang = $this->languageSyncDirective($targetLang);
        // 注意：必须输出“单个 JSON object”，不能带 markdown/code fence。
        return "You are a strict JSON generator. Output ONLY a valid JSON object (no markdown, no code fences).\n"
            . $lang
            . "Return schema:\n"
            . "{\n"
            . "  \"schema_version\": \"ps.parse.v1\",\n"
            . "  \"extracted\": {\"fields\": {}, \"entities\": [], \"numbers\": [], \"dates\": [], \"urls\": []},\n"
            . "  \"summary\": \"\",\n"
            . "  \"confidence\": 0.0\n"
            . "}\n"
            . "Rules:\n"
            . "- schema_version must be a non-empty string.\n"
            . "- extracted must be an object.\n"
            . "- confidence must be a number between 0 and 1.\n"
            . "- If input is ambiguous, still return the object with best-effort extraction.\n";
    }

    /**
     * 内置 Prompt: ecom_standardizer
     * 目标：从杂乱 HTML/文本提取 Shopify 可入库字段，且只输出纯净 JSON。
     */
    private function promptEcomStandardizer($targetLang)
    {
        $lang = $this->languageSyncDirective($targetLang);
        return "You are ecom_standardizer. Your job is to convert messy HTML/text into a Shopify-ready JSON object.\n"
            . "Output ONLY a valid JSON object (no markdown, no code fences, no extra text).\n"
            . $lang
            . "Structural Integrity:\n"
            . "- ALL JSON KEYS MUST ALWAYS BE ENGLISH snake_case exactly as the schema below. Never translate keys.\n"
            . "Required fields:\n"
            . "{\n"
            . "  \"schema_version\": \"ps.ecom.v1\",\n"
            . "  \"title\": \"\",\n"
            . "  \"price\": 0,\n"
            . "  \"currency\": \"USD\",\n"
            . "  \"spec\": {\"key\": \"value\"},\n"
            . "  \"skus\": [\n"
            . "    {\"sku\": \"\", \"variant\": \"\", \"price\": 0, \"currency\": \"USD\", \"available\": true}\n"
            . "  ],\n"
            . "  \"bullet_points\": [\"\"],\n"
            . "  \"summary\": \"\",\n"
            . "  \"confidence\": 0.0\n"
            . "}\n"
            . "Rules:\n"
            . "- Extract title/price/currency/spec/SKU list/bullet points from input.\n"
            . "- If price/currency appear multiple times, choose the most likely product price.\n"
            . "- currency must be ISO 4217 code if possible (e.g. USD, EUR, GBP, CNY, JPY).\n"
            . "- spec must be an object; normalize keys (no emojis).\n"
            . "- skus must be an array. If no explicit SKUs, create one default SKU with variant info if available.\n"
            . "- bullet_points must be an array of strings (core selling points).\n";
    }

    private function promptNewsExtractor($targetLang)
    {
        $lang = $this->languageSyncDirective($targetLang);
        return "You are news_extractor (denoise-first). Convert messy news webpage text/HTML into a clean JSON object.\n"
            . "Output ONLY a valid JSON object (no markdown, no code fences).\n"
            . $lang
            . "Structural Integrity:\n"
            . "- ALL JSON KEYS MUST ALWAYS BE ENGLISH snake_case exactly as the schema below. Never translate keys.\n"
            . "Required JSON schema:\n"
            . "{\n"
            . "  \"schema_version\": \"ps.news.v1\",\n"
            . "  \"title\": \"\",\n"
            . "  \"author\": null,\n"
            . "  \"published_at\": null,\n"
            . "  \"summary\": \"\",\n"
            . "  \"viewpoints\": [\"\"],\n"
            . "  \"entities\": [\"\"],\n"
            . "  \"confidence\": 0.0\n"
            . "}\n"
            . "Rules:\n"
            . "- DENOISE HARD: remove page scaffolding, navigation, footer, ads, promo slogans, unrelated recommendations.\n"
            . "- Keep only the core narrative and facts.\n"
            . "- author/published_at can be null if not found.\n"
            . "- summary should be concise (<= 5 sentences).\n"
            . "- viewpoints must be sharp, non-redundant, and aggressively distilled (no fluff). Prefer 3-7 items.\n"
            . "- viewpoints must not repeat the same idea with different words.\n"
            . "- entities is an array of important named entities/keywords.\n";
    }

    private function promptSocialAnalyzer($targetLang)
    {
        $lang = $this->languageSyncDirective($targetLang);
        return "You are social_analyzer. Analyze a tweet/comment/review and output a clean JSON object.\n"
            . "Output ONLY a valid JSON object (no markdown, no code fences).\n"
            . $lang
            . "Structural Integrity:\n"
            . "- ALL JSON KEYS MUST ALWAYS BE ENGLISH snake_case exactly as the schema below. Never translate keys.\n"
            . "Required JSON schema:\n"
            . "{\n"
            . "  \"schema_version\": \"ps.social.v1\",\n"
            . "  \"sentiment\": \"\",\n"
            . "  \"core_demand\": \"\",\n"
            . "  \"brands\": [\"\"],\n"
            . "  \"purchase_intent\": false,\n"
            . "  \"purchase_intent_reason\": \"\",\n"
            . "  \"confidence\": 0.0\n"
            . "}\n"
            . "Rules:\n"
            . "- sentiment MUST be fine-grained (e.g. extremely angry, hopeful, neutral, fanatic) in the SAME language as the input (or target_lang override).\n"
            . "- brands: extract brand/product names mentioned; empty array if none.\n"
            . "- purchase_intent: true only if user shows clear intent to buy/subscribe.\n"
            . "- purchase_intent_reason: explain the decision in 1-2 sentences using evidence from the text (e.g. mentions of price, link, where to buy, '我要下单', '链接在哪').\n";
    }

    private function promptAutoSmart($targetLang)
    {
        $lang = $this->languageSyncDirective($targetLang);
        return "You are auto_parser (flagship). Decide the best structure for the given input text/HTML.\n"
            . "Output ONLY a valid JSON object (no markdown, no code fences).\n"
            . $lang
            . "Structural Integrity:\n"
            . "- ALL JSON KEYS MUST ALWAYS BE ENGLISH snake_case. Never translate keys.\n"
            . "- type MUST ALWAYS be an ENGLISH snake_case token (e.g. restaurant_menu, medical_report).\n"
            . "Required minimal schema:\n"
            . "{\n"
            . "  \"schema_version\": \"ps.auto.v1\",\n"
            . "  \"type\": \"resume|medical_report|legal_notice|restaurant_menu|news|social|product|invoice|contact|other\",\n"
            . "  \"data\": {},\n"
            . "  \"confidence\": 0.0\n"
            . "}\n"
            . "Rules:\n"
            . "- FIRST decide and output the type field (use the closest type).\n"
            . "- Then generate the most regular, normalized JSON structure in data for that type.\n"
            . "- If type=restaurant_menu: data should include {\"restaurant\":?,\"items\":[{\"name\",\"price\",\"currency\",\"tags\":[],\"description\"}],\"notes\":?}.\n"
            . "- If type=news: include {title, author, published_at, summary, viewpoints[], entities[]} inside data.\n"
            . "- If type=social: include {sentiment, core_demand, brands[], purchase_intent, purchase_intent_reason} inside data.\n"
            . "- If type=resume: include {name, contacts{}, skills[], experiences[]}.\n"
            . "- If type=medical_report: include {patient{}, findings[], diagnosis?, medications[]}.\n"
            . "- If type=legal_notice: include {parties[], dates[], claims[], jurisdiction?}.\n"
            . "- Always return a JSON object.\n";
    }

    private function getClientIp()
    {
        // 优先使用系统内置/框架方法（若存在）
        if (function_exists('mac_get_client_ip')) {
            $ip = mac_get_client_ip();
            if (is_string($ip) && $ip !== '') {
                return $ip;
            }
        }
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
            // XFF 取第一个
            if ($k === 'HTTP_X_FORWARDED_FOR' && strpos($v, ',') !== false) {
                $v = trim(explode(',', $v)[0]);
            }
            return $v;
        }
        return '';
    }

    /**
     * Monetization Logs: 记录 tokens + client_ip（JSONL）
     * 文件：runtime/log/ps_parse.log
     */
    private function writeMonetizationLog($row)
    {
        $row = is_array($row) ? $row : [];
        $deepseek = isset($row['deepseek']) && is_array($row['deepseek']) ? $row['deepseek'] : [];
        $record = [
            'ts' => isset($row['ts']) ? (int)$row['ts'] : time(),
            'request_id' => isset($row['request_id']) ? (string)$row['request_id'] : '',
            'ok' => !empty($row['ok']),
            'mode' => isset($row['mode']) ? (string)$row['mode'] : '',
            'resolved_mode' => isset($row['resolved_mode']) ? (string)$row['resolved_mode'] : '',
            'client_ip' => isset($row['client_ip']) ? (string)$row['client_ip'] : '',
            'input_bytes' => isset($row['input_bytes']) ? (int)$row['input_bytes'] : 0,
            'input_tokens' => isset($deepseek['input_tokens']) ? (int)$deepseek['input_tokens'] : 0,
            'output_tokens' => isset($deepseek['output_tokens']) ? (int)$deepseek['output_tokens'] : 0,
            'provider' => isset($deepseek['provider']) ? (string)$deepseek['provider'] : '',
            'tier' => isset($deepseek['tier']) ? (string)$deepseek['tier'] : '',
            'model' => isset($deepseek['model']) ? (string)$deepseek['model'] : '',
            'duration_ms' => isset($row['duration_ms']) ? (int)$row['duration_ms'] : 0,
            'error_code' => isset($row['error_code']) ? (string)$row['error_code'] : '',
        ];

        $dir = ROOT_PATH . 'runtime/log';
        @mkdir($dir, 0755, true);
        $path = $dir . '/ps_parse.log';
        $line = json_encode($record, JSON_UNESCAPED_UNICODE);
        if (!is_string($line)) {
            return;
        }
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function getParam($name, $default = null)
    {
        // ThinkPHP input() helper：兼容 GET/POST/PUT 等
        if (function_exists('input')) {
            $val = input($name, $default, 'trim');
            return $val;
        }

        if (isset($_REQUEST[$name])) {
            $v = $_REQUEST[$name];
            if (is_string($v)) {
                return trim($v);
            }
            return $v;
        }
        return $default;
    }

    private function makeRequestId()
    {
        // 优先 openssl 随机；否则退化到 uniqid（依然可用于追踪）
        if (function_exists('openssl_random_pseudo_bytes')) {
            $b = openssl_random_pseudo_bytes(8);
            if ($b !== false) {
                return 'ps_' . bin2hex($b);
            }
        }
        return 'ps_' . str_replace('.', '', uniqid('', true));
    }

    private function enforceOptionalApiKey()
    {
        $expected = getenv('PS_PARSE_KEY');
        if ($expected === false || $expected === '') {
            $expected = getenv('PARSE_API_KEY');
        }
        if ($expected === false || $expected === '') {
            return;
        }
        $expected = (string)$expected;

        $provided = $this->getHeader('x-parse-key');
        if ($provided === '') {
            $provided = $this->getHeader('x-api-key');
        }
        if ($provided === '') {
            $provided = (string)$this->getParam('key', '');
        }

        if ($provided === '' || !$this->hashEqualsCompat($expected, $provided)) {
            $this->respondJson([
                'ok' => false,
                'error' => ['code' => 'unauthorized', 'message' => 'Invalid API key'],
            ], 401);
        }
    }

    private function getHeader($name)
    {
        $name = strtoupper(str_replace('-', '_', (string)$name));
        $key = 'HTTP_' . $name;
        if (isset($_SERVER[$key])) {
            return trim((string)$_SERVER[$key]);
        }
        return '';
    }

    private function hashEqualsCompat($a, $b)
    {
        $a = (string)$a;
        $b = (string)$b;
        if (function_exists('hash_equals')) {
            return hash_equals($a, $b);
        }
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        $res = 0;
        $len = strlen($a);
        for ($i = 0; $i < $len; $i++) {
            $res |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $res === 0;
    }

    private function handleCors()
    {
        // 默认允许跨域：该接口定位为中间件/边缘消费，前端直连更常见
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Parse-Key, Authorization');
        header('Access-Control-Max-Age: 86400');
    }

    private function respondJson($payload, $statusCode)
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{"ok":false,"error":{"code":"json_encode_failed","message":"JSON encode failed"}}';
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
}

