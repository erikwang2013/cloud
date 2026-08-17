// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use bytes::Bytes;
use ecat_mq::{MessageQueue, MessageStream, MqError};
use rumqttc::{AsyncClient, Event, EventLoop, MqttOptions, Packet, QoS};
use serde::Deserialize;
use std::sync::atomic::{AtomicU32, Ordering};
use std::task::{Context, Poll};
use std::time::Duration;
use tokio::sync::mpsc;

#[derive(Debug, Clone, Deserialize)]
pub struct MqttConfig {
    pub url: String,
    #[serde(default)]
    pub client_id: Option<String>,
    #[serde(default)]
    pub username: Option<String>,
    #[serde(default)]
    pub password: Option<String>,
}

pub struct MqttMq {
    client: AsyncClient,
    config: MqttConfig,
    sub_counter: AtomicU32,
}

impl MqttMq {
    pub async fn connect(url: &str) -> Result<Self, MqError> {
        Self::from_config(MqttConfig {
            url: url.to_string(),
            client_id: None,
            username: None,
            password: None,
        })
        .await
    }

    pub async fn from_config(cfg: MqttConfig) -> Result<Self, MqError> {
        let (host, port) = parse_url(&cfg.url);
        let client_id = cfg.client_id.clone().unwrap_or_else(|| "ecat-mqtt".into());
        let (client, eventloop) = AsyncClient::new(client_options(&cfg, host, port, client_id), 10);
        tokio::spawn(pump(eventloop));
        Ok(Self {
            client,
            config: cfg,
            sub_counter: AtomicU32::new(0),
        })
    }
}

fn client_options(cfg: &MqttConfig, host: String, port: u16, client_id: String) -> MqttOptions {
    let mut opts = MqttOptions::new(client_id, host, port);
    opts.set_keep_alive(Duration::from_secs(10));
    if let (Some(u), Some(p)) = (&cfg.username, &cfg.password) {
        opts.set_credentials(u, p);
    }
    opts
}

/// Keeps the publisher connection alive; retries after transient errors.
async fn pump(mut eventloop: EventLoop) {
    loop {
        if eventloop.poll().await.is_err() {
            tokio::time::sleep(Duration::from_secs(1)).await;
        }
    }
}

fn parse_url(url: &str) -> (String, u16) {
    let trimmed = url
        .strip_prefix("tcp://")
        .or_else(|| url.strip_prefix("mqtt://"))
        .unwrap_or(url);
    match trimmed.rsplit_once(':') {
        Some((host, port)) => (host.to_string(), port.parse().unwrap_or(1883)),
        None => (trimmed.to_string(), 1883),
    }
}

#[async_trait]
impl MessageQueue for MqttMq {
    async fn publish(&self, topic: &str, payload: &[u8]) -> Result<(), MqError> {
        self.client
            .publish(topic, QoS::AtMostOnce, false, payload.to_vec())
            .await
            .map_err(|e| MqError::Other(format!("mqtt publish: {e}")))?;
        Ok(())
    }

    async fn subscribe(&self, topic: &str) -> Result<Box<dyn MessageStream>, MqError> {
        let base_id = self
            .config
            .client_id
            .clone()
            .unwrap_or_else(|| "ecat-mqtt".into());
        let n = self.sub_counter.fetch_add(1, Ordering::SeqCst);
        let (host, port) = parse_url(&self.config.url);
        // Dedicated connection per subscription so one slow consumer
        // never stalls another (and the broker never kicks the publisher).
        let (client, eventloop) = AsyncClient::new(
            client_options(&self.config, host, port, format!("{base_id}-sub{n}")),
            10,
        );
        client
            .subscribe(topic, QoS::AtMostOnce)
            .await
            .map_err(|e| MqError::Other(format!("mqtt subscribe: {e}")))?;

        let (tx, rx) = mpsc::channel::<Bytes>(256);
        tokio::spawn(async move {
            let mut eventloop = eventloop;
            loop {
                match eventloop.poll().await {
                    Ok(Event::Incoming(Packet::Publish(msg))) => {
                        // msg.payload 是 bytes::Bytes，直接透传，零拷贝。
                        if tx.send(msg.payload).await.is_err() {
                            break;
                        }
                    }
                    Ok(_) => {}
                    Err(_) => tokio::time::sleep(Duration::from_secs(1)).await,
                }
            }
        });
        Ok(Box::new(MqttStream { rx }))
    }
}

struct MqttStream {
    rx: mpsc::Receiver<Bytes>,
}

impl MessageStream for MqttStream {
    fn poll_recv(&mut self, cx: &mut Context<'_>) -> Poll<Option<Result<Bytes, MqError>>> {
        match self.rx.poll_recv(cx) {
            Poll::Ready(Some(data)) => Poll::Ready(Some(Ok(data))),
            Poll::Ready(None) => Poll::Ready(None),
            Poll::Pending => Poll::Pending,
        }
    }
}

impl Unpin for MqttStream {}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn config_deserializes() {
        let cfg: MqttConfig = serde_json::from_value(serde_json::json!({
            "url": "tcp://localhost:1883",
            "client_id": "sensor-1",
            "username": "user",
            "password": "pass",
        }))
        .unwrap();
        assert_eq!(cfg.client_id.as_deref(), Some("sensor-1"));
    }

    #[test]
    fn url_parses_host_and_port() {
        assert_eq!(
            parse_url("tcp://mqtt.local:2883"),
            ("mqtt.local".into(), 2883)
        );
        assert_eq!(parse_url("mqtt://broker"), ("broker".into(), 1883));
    }
}
