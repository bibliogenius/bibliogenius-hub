//! BiblioGenius WebSocket Sidecar
//!
//! Lightweight Axum server that holds WebSocket connections and pushes
//! "nudge" signals when the Hub PHP deposits a message in a relay mailbox.
//!
//! Two listeners:
//! - Public (default :9091): WebSocket endpoint, proxied by Caddy
//! - Internal (default 127.0.0.1:9090): Notify endpoint, called by Hub PHP only
//!
//! See ADR-017 for architecture details.

mod notify;
mod state;
mod validation;
mod ws_handler;

use std::sync::Arc;

use axum::Router;
use axum::routing::{get, post};
use tower_http::trace::TraceLayer;
use tracing_subscriber::EnvFilter;

use crate::state::SharedState;

#[tokio::main]
async fn main() {
    // Initialize tracing (RUST_LOG env var, default: info).
    tracing_subscriber::fmt()
        .with_env_filter(
            EnvFilter::try_from_default_env().unwrap_or_else(|_| EnvFilter::new("info")),
        )
        .init();

    let hub_internal_url =
        std::env::var("HUB_INTERNAL_URL").unwrap_or_else(|_| "http://127.0.0.1:8080".to_string());

    let state = Arc::new(SharedState::new(hub_internal_url));

    // Public router: WebSocket connections + health check.
    let public_app = Router::new()
        .route("/ws", get(ws_handler::handle_ws))
        .route("/health", get(health))
        .layer(TraceLayer::new_for_http())
        .with_state(state.clone());

    // Internal router: PHP notification endpoint.
    let internal_app = Router::new()
        .route("/internal/notify/{mailbox_id}", post(notify::handle_notify))
        .with_state(state.clone());

    // Spawn periodic cleanup of dead WebSocket senders.
    tokio::spawn(state.clone().cleanup_loop());

    // Read bind addresses from environment (with safe defaults).
    // INTERNAL_BIND: use 0.0.0.0 in Docker (inter-container networking),
    // 127.0.0.1 on bare-metal (prevent external access to notify endpoint).
    let public_port: u16 = std::env::var("PUBLIC_PORT")
        .ok()
        .and_then(|s| s.parse().ok())
        .unwrap_or(9091);
    let internal_port: u16 = std::env::var("INTERNAL_PORT")
        .ok()
        .and_then(|s| s.parse().ok())
        .unwrap_or(9090);
    let internal_bind = std::env::var("INTERNAL_BIND").unwrap_or_else(|_| "127.0.0.1".to_string());

    let public_addr = format!("0.0.0.0:{public_port}");
    let internal_addr = format!("{internal_bind}:{internal_port}");

    tracing::info!("ws-sidecar starting");
    tracing::info!("  Public (WS):   {public_addr}");
    tracing::info!("  Internal:      {internal_addr}");

    let public_listener = tokio::net::TcpListener::bind(&public_addr)
        .await
        .expect("Failed to bind public listener");
    let internal_listener = tokio::net::TcpListener::bind(&internal_addr)
        .await
        .expect("Failed to bind internal listener");

    // Run both listeners concurrently, with graceful shutdown on SIGTERM/SIGINT.
    let shutdown_public = shutdown_signal();
    let shutdown_internal = shutdown_signal();

    let (r1, r2) = tokio::join!(
        axum::serve(public_listener, public_app)
            .with_graceful_shutdown(shutdown_public)
            .into_future(),
        axum::serve(internal_listener, internal_app)
            .with_graceful_shutdown(shutdown_internal)
            .into_future(),
    );

    if let Err(e) = r1 {
        tracing::error!("Public listener error: {e}");
    }
    if let Err(e) = r2 {
        tracing::error!("Internal listener error: {e}");
    }

    tracing::info!("ws-sidecar stopped");
}

/// Create a shutdown future that resolves on SIGTERM or SIGINT.
async fn shutdown_signal() {
    use tokio::signal::unix::{SignalKind, signal};

    let mut sigterm = signal(SignalKind::terminate()).expect("Failed to install SIGTERM handler");
    let ctrl_c = tokio::signal::ctrl_c();

    tokio::select! {
        _ = sigterm.recv() => { tracing::info!("SIGTERM received"); }
        _ = ctrl_c => { tracing::info!("SIGINT received"); }
    }
}

async fn health() -> &'static str {
    "OK"
}
