// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_nats::{Client, Message};
use async_trait::async_trait;
use bytes::Bytes;
use ecat_mq::{MessageQueue, MessageStream, MqError};
use futures_core::Stream;
use serde::Deserialize;
use std::pin::Pin;
use std::task::{Context, Poll};

#[derive(Debug, Clone, Deserialize)]
pub struct NatsConfig {
    pub url: String,
}

pub struct NatsMq {
    client: Client,
}

impl NatsMq {
    pub async fn connect(url: &str) -> Result<Self, MqError> {
        let client = async_nats::connect(url)
            .await
            .map_err(|e| MqError::Other(format!("nats connect: {e}")))?;
        Ok(Self { client })
    }

    pub async fn from_config(cfg: NatsConfig) -> Result<Self, MqError> {
        Self::connect(&cfg.url).await
    }
}

#[async_trait]
impl MessageQueue for NatsMq {
    async fn publish(&self, topic: &str, payload: &[u8]) -> Result<(), MqError> {
        // async-nats requires a 'static subject; copy at the boundary.
        self.client
            .publish(topic.to_owned(), payload.to_vec().into())
            .await
            .map_err(|e| MqError::Other(format!("nats publish: {e}")))?;
        Ok(())
    }

    async fn subscribe(&self, topic: &str) -> Result<Box<dyn MessageStream>, MqError> {
        // The concrete Subscription type is private; erase it behind the Stream trait.
        let sub: Box<dyn Stream<Item = Message> + Send + Unpin> = Box::new(
            self.client
                .subscribe(topic.to_owned())
                .await
                .map_err(|e| MqError::Other(format!("nats subscribe: {e}")))?,
        );
        Ok(Box::new(NatsStream { sub }))
    }
}

struct NatsStream {
    sub: Box<dyn Stream<Item = Message> + Send + Unpin>,
}

impl MessageStream for NatsStream {
    fn poll_recv(&mut self, cx: &mut Context<'_>) -> Poll<Option<Result<Bytes, MqError>>> {
        match Pin::new(&mut *self.sub).poll_next(cx) {
            Poll::Ready(Some(msg)) => Poll::Ready(Some(Ok(msg.payload))),
            Poll::Ready(None) => Poll::Ready(None),
            Poll::Pending => Poll::Pending,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn config_deserializes() {
        let cfg: NatsConfig = serde_json::from_value(serde_json::json!({
            "url": "nats://localhost:4222",
        }))
        .unwrap();
        assert_eq!(cfg.url, "nats://localhost:4222");
    }

    #[tokio::test]
    async fn connect_fails_bad_url() {
        let result = NatsMq::connect("nats://127.0.0.1:1").await;
        assert!(result.is_err());
    }
}
