<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

/**
 * Webman-Scout search engine configuration.
 *
 * Automatically syncs Eloquent model changes to Elasticsearch / OpenSearch /
 * Meilisearch / Typesense / Algolia for full-text search.
 *
 * Models using the Searchable trait are observed for create/update/delete events
 * and synced to the configured search engine.
 *
 * @see https://github.com/erikwang2013/webman-scout
 */
return [

    /**
     * Enable/disable the scout plugin.
     */
    'enable' => true,

    /**
     * Default search driver.
     * Supported: "algolia", "meilisearch", "typesense", "elasticsearch",
     *            "database", "collection", "null", "opensearch", "xunsearch"
     */
    'driver' => getenv('SCOUT_DRIVER') ?: 'elasticsearch',

    /**
     * Index name prefix — useful when sharing infrastructure across applications.
     */
    'prefix' => getenv('SCOUT_PREFIX', ''),

    /**
     * When true, sync operations are queued for async processing.
     */
    'queue' => getenv('SCOUT_QUEUE', false),

    /**
     * Only sync after open database transactions have been committed.
     */
    'after_commit' => false,

    /**
     * Chunk sizes for mass import/delete operations.
     */
    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    /**
     * Keep soft-deleted records in the search index.
     */
    'soft_delete' => false,

    /**
     * Notify the search engine of the user performing searches (Algolia only).
     */
    'identify' => getenv('SCOUT_IDENTIFY', false),

    /**
     * Elasticsearch engine configuration.
     */
    'elasticsearch' => [
        'hosts' => [getenv('ELASTICSEARCH_HOST', 'http://127.0.0.1:9200')],
        'auth' => [
            'user' => getenv('ELASTICSEARCH_USER'),
            'pass' => getenv('ELASTICSEARCH_PASS'),
            'api_id' => getenv('ELASTICSEARCH_API_ID'),
            'api_key' => getenv('ELASTICSEARCH_API_KEY'),
            'cloud_id' => getenv('ELASTICSEARCH_CLOUD_ID'),
        ],
    ],

    /**
     * OpenSearch engine configuration.
     */
    'opensearch' => [
        'host' => getenv('OPENSEARCH_HTTP_HOST', 'https://127.0.0.1:6205'),
        'username' => getenv('OPENSEARCH_USERNAME', 'admin'),
        'password' => getenv('OPENSEARCH_PASSWORD', 'admin'),
        'prefix' => getenv('OPENSEARCH_INDEX_PREFIX'),
        'ssl_verification' => (bool) getenv('OPENSEARCH_SSL_VERIFICATION', false),
        'ssl_cert' => getenv('OPENSEARCH_SSL_CERT', ''),
        'ssl_key' => getenv('OPENSEARCH_SSL_KEY', ''),
        'retries' => getenv('OPENSEARCH_RETRIES', 2),
        'connection_timeout' => getenv('OPENSEARCH_CONNECTION_TIMEOUT', 10),
        'timeout' => getenv('OPENSEARCH_TIMEOUT', 30),
    ],

    /**
     * Meilisearch engine configuration.
     */
    'meilisearch' => [
        'host' => getenv('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => getenv('MEILISEARCH_KEY'),
    ],

    /**
     * Typesense engine configuration.
     */
    'typesense' => [
        'client-settings' => [
            'api_key' => getenv('TYPESENSE_API_KEY', 'xyz'),
            'nodes' => [
                [
                    'host' => getenv('TYPESENSE_HOST', 'localhost'),
                    'port' => getenv('TYPESENSE_PORT', '8108'),
                    'path' => getenv('TYPESENSE_PATH', ''),
                    'protocol' => getenv('TYPESENSE_PROTOCOL', 'http'),
                ],
            ],
            'nearest_node' => [
                'host' => getenv('TYPESENSE_HOST', 'localhost'),
                'port' => getenv('TYPESENSE_PORT', '8108'),
                'path' => getenv('TYPESENSE_PATH', ''),
                'protocol' => getenv('TYPESENSE_PROTOCOL', 'http'),
            ],
            'connection_timeout_seconds' => getenv('TYPESENSE_CONNECTION_TIMEOUT_SECONDS', 2),
            'healthcheck_interval_seconds' => getenv('TYPESENSE_HEALTHCHECK_INTERVAL_SECONDS', 30),
            'num_retries' => getenv('TYPESENSE_NUM_RETRIES', 3),
            'retry_interval_seconds' => getenv('TYPESENSE_RETRY_INTERVAL_SECONDS', 1),
        ],
    ],

    /**
     * Algolia engine configuration.
     */
    'algolia' => [
        'id' => getenv('ALGOLIA_APP_ID', ''),
        'secret' => getenv('ALGOLIA_SECRET', ''),
    ],

];
