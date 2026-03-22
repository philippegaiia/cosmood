<?php

use App\CopilotTools\ReadKnowledgeBaseArticleTool;
use App\CopilotTools\SearchKnowledgeBaseTool;

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    */

    'provider' => env('COPILOT_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Default AI Model
    |--------------------------------------------------------------------------
    */

    'model' => env('COPILOT_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | Agent Behavior
    |--------------------------------------------------------------------------
    */

    'agent' => [
        'timeout' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat History
    |--------------------------------------------------------------------------
    */

    'chat' => [
        'title_auto_generate' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'enabled' => false,
        'max_messages_per_hour' => 60,
        'max_messages_per_day' => 500,
        'max_tokens_per_hour' => 100000,
        'max_tokens_per_day' => 1000000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Budget
    |--------------------------------------------------------------------------
    */

    'token_budget' => [
        'enabled' => false,
        'warn_at_percentage' => 80,
        'daily_budget' => null,
        'monthly_budget' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    */

    'audit' => [
        'enabled' => true,
        'log_messages' => true,
        'log_tool_calls' => true,
        'log_record_access' => true,
        'log_navigation' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Memory
    |--------------------------------------------------------------------------
    */

    'memory' => [
        'enabled' => true,
        'max_memories_per_user' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Integration
    |--------------------------------------------------------------------------
    */

    'respect_authorization' => true,

    /*
    |--------------------------------------------------------------------------
    | Rate Limit Management UI
    |--------------------------------------------------------------------------
    */

    'management' => [
        'enabled' => false,
        'guard' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quick Actions / Canned Prompts
    |--------------------------------------------------------------------------
    */

    'quick_actions' => [
        'What is this page for?' => 'Use the knowledge base search to find documentation about this page, then explain what this page is used for in practical terms for an operator, planner, or manager.',
        'What should I do first?' => 'Search the knowledge base for setup guides and first steps, then tell me what to do first before using this screen or workflow.',
        'Common mistakes to avoid' => 'Search the knowledge base for common errors and pitfalls, then list the most common mistakes and blockers to avoid on this workflow.',
    ],

    /*
    |--------------------------------------------------------------------------
    | System Prompt
    |--------------------------------------------------------------------------
    */

    'system_prompt' => 'You are the in-panel assistant for a cosmetics production management application called Cosmood. IMPORTANT: When the user mentions "this page", "this screen", "this workflow", or "here", you MUST use the knowledge base search tool first to find relevant documentation about the current context. Always use the search_knowledge_base tool with relevant keywords from the user\'s question before answering. Answer in the user\'s current language when possible. Be practical, concrete, and operator-friendly. Distinguish clearly between planning, procurement, stock management, production execution, and quality control workflows.',

    /*
    |--------------------------------------------------------------------------
    | Global Tools
    |--------------------------------------------------------------------------
    | Tool classes available on every page across all resources.
    | Each entry should be a class name that extends BaseTool.
    */

    'global_tools' => [
        SearchKnowledgeBaseTool::class,
        ReadKnowledgeBaseArticleTool::class,
    ],

];
