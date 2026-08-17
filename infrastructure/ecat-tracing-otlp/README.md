# ecat-tracing-otlp

[OpenTelemetry](https://opentelemetry.io) OTLP tracing export for the e-cat ecosystem.

```rust
let _provider = ecat_tracing_otlp::init("my-service", "http://localhost:4317")?;

tracing::info!("hello");
// spans are exported to the collector in batches
```

**Notes:** exports over OTLP/gRPC with a batch span processor on the tokio runtime; `RUST_LOG` controls the local log filter; keep the returned provider alive for the process lifetime.
