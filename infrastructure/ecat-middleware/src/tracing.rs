// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use std::future::Future;
use std::pin::Pin;
use tower::{Layer, Service};
use tracing::Instrument;

/// Request span layer (no subscriber installed here).
///
/// NOTE: this layer only creates spans; it does not install a
/// tracing_subscriber. Exactly one subscriber must be initialized at app
/// startup (e.g. via `ecat_tracing::init` or `ecat_tracing_otlp::init`) —
/// installing two subscribers in one process conflicts ("global default
/// trace dispatcher has already been set").
#[derive(Clone)]
pub struct TracingLayer;

impl<S> Layer<S> for TracingLayer {
    type Service = TracingService<S>;
    fn layer(&self, inner: S) -> Self::Service {
        TracingService { inner }
    }
}

#[derive(Clone)]
pub struct TracingService<S> {
    inner: S,
}

impl<S, Req> Service<Req> for TracingService<S>
where
    S: Service<Req> + Send + 'static,
    S::Future: Send + 'static,
    Req: Send + 'static,
{
    type Response = S::Response;
    type Error = S::Error;
    type Future = Pin<Box<dyn Future<Output = Result<Self::Response, Self::Error>> + Send>>;

    fn poll_ready(
        &mut self,
        cx: &mut std::task::Context<'_>,
    ) -> std::task::Poll<Result<(), Self::Error>> {
        self.inner.poll_ready(cx)
    }

    fn call(&mut self, req: Req) -> Self::Future {
        let span = tracing::info_span!("request");
        let fut = self.inner.call(req);
        Box::pin(fut.instrument(span))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tower::Service;

    #[derive(Clone)]
    struct EchoService;

    impl Service<String> for EchoService {
        type Response = String;
        type Error = std::io::Error;
        type Future = Pin<Box<dyn Future<Output = Result<String, std::io::Error>> + Send>>;

        fn poll_ready(
            &mut self,
            _cx: &mut std::task::Context<'_>,
        ) -> std::task::Poll<Result<(), Self::Error>> {
            std::task::Poll::Ready(Ok(()))
        }

        fn call(&mut self, req: String) -> Self::Future {
            Box::pin(async move { Ok(req) })
        }
    }

    #[test]
    fn layer_constructs() {
        let _layer = TracingLayer;
    }

    #[test]
    fn layer_wraps_service() {
        let layer = TracingLayer;
        let _svc = layer.layer(EchoService);
    }

    #[tokio::test]
    async fn calls_inner_service() {
        let layer = TracingLayer;
        let mut svc = layer.layer(EchoService);
        let result = svc.call("hello".into()).await.unwrap();
        assert_eq!(result, "hello");
    }
}
