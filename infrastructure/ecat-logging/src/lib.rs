// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use tracing_subscriber::layer::SubscriberExt;
use tracing_subscriber::util::SubscriberInitExt;

pub fn init() {
    let fmt_layer = tracing_subscriber::fmt::layer()
        .with_target(true)
        .with_level(true)
        .compact();

    let env_layer = tracing_subscriber::EnvFilter::try_from_default_env()
        .unwrap_or_else(|_| tracing_subscriber::EnvFilter::new("info"));

    tracing_subscriber::registry()
        .with(env_layer)
        .with(fmt_layer)
        .init();
}

#[cfg(test)]
mod tests {
    #[test]
    fn init_does_not_panic() {
        // init may fail if called more than once per process, but it must not panic
        // We use try_init to be safe in test runners with multiple tests
        let result = std::panic::catch_unwind(|| {
            let _ = tracing_subscriber::fmt()
                .with_target(false)
                .with_level(false)
                .compact()
                .try_init();
        });
        assert!(result.is_ok());
    }
}
