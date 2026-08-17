// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use opentelemetry::KeyValue;
use opentelemetry::trace::TracerProvider as _;
use opentelemetry_otlp::{SpanExporter, WithExportConfig};
use opentelemetry_sdk::Resource;
use opentelemetry_sdk::runtime;
use opentelemetry_sdk::trace::{BatchSpanProcessor, TracerProvider};
use tracing_subscriber::EnvFilter;
use tracing_subscriber::layer::SubscriberExt;
use tracing_subscriber::util::SubscriberInitExt;

/// Install a tracing subscriber that logs to stderr and exports spans to an
/// OTLP/gRPC collector. Returns the provider — keep it alive for the process
/// lifetime so spans keep being exported.
pub fn init(service_name: &str, endpoint: &str) -> Result<TracerProvider, String> {
    let exporter = SpanExporter::builder()
        .with_tonic()
        .with_endpoint(endpoint)
        .build()
        .map_err(|e| format!("otlp exporter: {e}"))?;
    let resource =
        Resource::new_with_defaults([KeyValue::new("service.name", service_name.to_string())]);
    let provider = TracerProvider::builder()
        .with_resource(resource)
        .with_span_processor(BatchSpanProcessor::builder(exporter, runtime::Tokio).build())
        .build();
    let tracer = provider.tracer("ecat");
    tracing_subscriber::registry()
        .with(EnvFilter::from_default_env())
        .with(tracing_subscriber::fmt::layer())
        .with(tracing_opentelemetry::layer().with_tracer(tracer))
        .try_init()
        .map_err(|e| format!("tracing init: {e}"))?;
    Ok(provider)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn init_returns_provider() {
        let provider = init("test-service", "http://127.0.0.1:4317").unwrap();
        let _ = provider;
    }
}
