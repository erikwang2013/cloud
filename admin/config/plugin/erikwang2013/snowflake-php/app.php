<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */


/**
 * Snowflake ID Generator configuration.
 *
 * Uses Twitter Snowflake algorithm to generate globally unique 64-bit IDs.
 * IDs are composed of: timestamp (41+ bits) | datacenter (5 bits) | worker (5 bits) | sequence (12 bits)
 *
 * @see https://github.com/erikwang2013/snowflake-php
 */
return [
    /**
     * Enable/disable the snowflake plugin.
     */
    'enable' => true,

    /**
     * Epoch in milliseconds — the starting point for timestamp offset calculation.
     * Default: 2024-01-01 00:00:00 UTC = 1704067200000 ms.
     * A recent epoch maximizes generator lifespan (~69 years from epoch with 41 timestamp bits).
     */
    'epoch' => (int) env('SNOWFLAKE_EPOCH', 1704067200000),

    /**
     * Worker ID — unique identifier for this process/node.
     * Must be different per concurrent process to avoid ID collisions.
     * Range: 0 - (2^worker_bits - 1).
     */
    'worker_id' => (int) env('SNOWFLAKE_WORKER_ID', 0),

    /**
     * Datacenter ID — combined with worker_id to form a globally unique node identifier.
     * Range: 0 - (2^datacenter_bits - 1).
     */
    'datacenter_id' => (int) env('SNOWFLAKE_DATACENTER_ID', 0),

    /**
     * Bit allocation — control how the 63 data bits are distributed.
     * Total must not exceed 63: worker_bits + datacenter_bits + sequence_bits <= 63.
     */
    'worker_bits' => 5,
    'datacenter_bits' => 5,
    'sequence_bits' => 12,

    /**
     * Sequence resolver — FQCN implementing Snowflake\Contracts\SequenceResolver.
     * SequentialSequenceResolver: 0,1,2... per millisecond (predictable).
     * RandomSequenceResolver: random start per millisecond (unpredictable).
     */
    'sequence_resolver' => \Snowflake\Resolvers\SequentialSequenceResolver::class,

    /**
     * Clock drift tolerance in milliseconds.
     * 0 = strict mode: any backward drift throws ClockDriftException.
     * Increase if your infrastructure has minor NTP jitter.
     */
    'clock_tolerance_ms' => (int) env('SNOWFLAKE_CLOCK_TOLERANCE_MS', 0),
];
