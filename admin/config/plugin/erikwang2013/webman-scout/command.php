<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

/**
 * Webman-Scout console commands for managing search indices.
 *
 * Available commands:
 *   php webman scout:import <model>      — Import all records from a model into the search index
 *   php webman scout:flush <model>       — Remove all records for a model from the search index
 *   php webman scout:index:create        — Create search indices for all searchable models
 *   php webman scout:index:drop <model>  — Delete the search index for a specific model
 *   php webman scout:index:drop-all      — Delete all search indices
 *   php webman scout:sync-index-settings — Sync index settings from config to the search engine
 */
return [
    \Erikwang2013\WebmanScout\Command\ImportCommand::class,
    \Erikwang2013\WebmanScout\Command\FlushCommand::class,
    \Erikwang2013\WebmanScout\Command\IndexCommand::class,
    \Erikwang2013\WebmanScout\Command\DeleteIndexCommand::class,
    \Erikwang2013\WebmanScout\Command\DeleteAllIndexesCommand::class,
    \Erikwang2013\WebmanScout\Command\SyncIndexSettingsCommand::class,
];
