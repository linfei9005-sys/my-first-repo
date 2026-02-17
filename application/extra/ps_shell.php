<?php
/**
 * Project Shell V2 config (Phase 2: Vault).
 *
 * 用法：
 * - 白名单 Key：在 api_keys 中填入允许的 Bearer Token
 * - 也可用环境变量 PS_API_KEYS="k1,k2,k3" 追加（便于快速部署）
 */
return [
    // Authorization: Bearer <API_KEY>
    'api_keys' => [
        // 'REPLACE_WITH_REAL_KEY_1',
        // 'REPLACE_WITH_REAL_KEY_2',
    ],
    'supported_modes' => ['auto', 'ecom', 'news', 'social', 'deepseek', 'json', 'query', 'kv', 'csv'],
    // Free-Pool Armor
    'rate_limit_per_minute' => 10,

    // Free-Pool engines (can also be provided via env vars)
    // 填写 CEO 提供的 Key；占位符会被视为“未配置”
    'siliconflow_api_key' => 'REPLACE_WITH_SILICONFLOW_API_KEY',
    'siliconflow_base_url' => 'https://api.siliconflow.com/v1',
    'siliconflow_model' => 'deepseek-ai/DeepSeek-V3',
    'groq_api_key' => 'REPLACE_WITH_GROQ_API_KEY',
    'groq_base_url' => 'https://api.groq.com/openai/v1',
    'groq_model' => 'llama-3.1-70b-versatile',
];

