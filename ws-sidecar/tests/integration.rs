//! Integration tests for the ws-sidecar.
//!
//! These tests start real Axum servers and connect via tokio-tungstenite
//! to verify the full nudge flow end-to-end.

use std::sync::Arc;
use std::time::Duration;

use axum::Router;
use axum::routing::{get, post};
use futures::StreamExt;
use http::Uri;
use tokio::net::TcpListener;
use tokio_tungstenite::tungstenite;

use ws_sidecar::notify;
use ws_sidecar::state::SharedState;
use ws_sidecar::ws_handler;

/// Helper: start a mock Hub PHP that always returns 200 for token verification.
async fn start_mock_hub() -> u16 {
    let app = Router::new().route(
        "/api/relay/mailbox/{uuid}/verify",
        get(|| async { axum::Json(serde_json::json!({"status": "ok"})) }),
    );
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    tokio::spawn(axum::serve(listener, app).into_future());
    port
}

/// Helper: start a mock Hub PHP that always returns 401.
async fn start_mock_hub_reject() -> u16 {
    let app = Router::new().route(
        "/api/relay/mailbox/{uuid}/verify",
        get(|| async { axum::http::StatusCode::UNAUTHORIZED }),
    );
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    tokio::spawn(axum::serve(listener, app).into_future());
    port
}

/// Helper: start the sidecar public + internal listeners.
/// Returns (public_port, internal_port).
async fn start_sidecar(state: Arc<SharedState>) -> (u16, u16) {
    let public_app = Router::new()
        .route("/ws", get(ws_handler::handle_ws))
        .with_state(state.clone());

    let internal_app = Router::new()
        .route("/internal/notify/{mailbox_id}", post(notify::handle_notify))
        .with_state(state);

    let public_listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let internal_listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let public_port = public_listener.local_addr().unwrap().port();
    let internal_port = internal_listener.local_addr().unwrap().port();

    tokio::spawn(axum::serve(public_listener, public_app).into_future());
    tokio::spawn(axum::serve(internal_listener, internal_app).into_future());

    (public_port, internal_port)
}

const TEST_MAILBOX: &str = "550e8400-e29b-41d4-a716-446655440000";
const TEST_TOKEN: &str = "aabbccdd00112233aabbccdd00112233aabbccdd00112233aabbccdd00112233";

#[tokio::test]
async fn nudge_delivered_to_ws_client() {
    let hub_port = start_mock_hub().await;
    let hub_url = format!("http://127.0.0.1:{hub_port}");

    let state = Arc::new(SharedState::new(hub_url));
    let (public_port, internal_port) = start_sidecar(state).await;

    // Connect a WS client.
    let ws_url =
        format!("ws://127.0.0.1:{public_port}/ws?mailbox_id={TEST_MAILBOX}&token={TEST_TOKEN}");
    let (mut ws, _) = tokio_tungstenite::connect_async(&ws_url)
        .await
        .expect("WS connect failed");

    // Give the connection a moment to register.
    tokio::time::sleep(Duration::from_millis(50)).await;

    // PHP notifies the sidecar.
    let client = reqwest::Client::new();
    let resp = client
        .post(format!(
            "http://127.0.0.1:{internal_port}/internal/notify/{TEST_MAILBOX}"
        ))
        .send()
        .await
        .expect("notify failed");
    assert_eq!(resp.status(), 200);

    // Read the nudge from the WS.
    let msg = tokio::time::timeout(Duration::from_secs(2), ws.next())
        .await
        .expect("timeout waiting for nudge")
        .expect("stream ended")
        .expect("ws error");

    let text = match msg {
        tungstenite::Message::Text(t) => t,
        other => panic!("expected text, got {other:?}"),
    };
    let json: serde_json::Value = serde_json::from_str(&text).unwrap();
    assert_eq!(json["type"], "mailbox_nudge");
    assert_eq!(json["mailbox_id"], TEST_MAILBOX);

    // Clean up.
    ws.close(None).await.ok();
}

#[tokio::test]
async fn auth_rejected_with_invalid_token() {
    let hub_port = start_mock_hub_reject().await;
    let hub_url = format!("http://127.0.0.1:{hub_port}");

    let state = Arc::new(SharedState::new(hub_url));
    let (public_port, _) = start_sidecar(state).await;

    // Try to connect -- the sidecar should reject before upgrading.
    let ws_url =
        format!("ws://127.0.0.1:{public_port}/ws?mailbox_id={TEST_MAILBOX}&token={TEST_TOKEN}");
    let result = tokio_tungstenite::connect_async(&ws_url).await;

    // The server returns 401 before upgrading, so connect_async should fail.
    assert!(result.is_err(), "connection should be rejected");
}

#[tokio::test]
async fn notify_no_clients_returns_204() {
    let state = Arc::new(SharedState::new("http://unused".to_string()));
    let (_, internal_port) = start_sidecar(state).await;

    let client = reqwest::Client::new();
    let resp = client
        .post(format!(
            "http://127.0.0.1:{internal_port}/internal/notify/{TEST_MAILBOX}"
        ))
        .send()
        .await
        .expect("notify failed");
    assert_eq!(resp.status(), 204);
}

#[tokio::test]
async fn auth_via_authorization_header() {
    let hub_port = start_mock_hub().await;
    let hub_url = format!("http://127.0.0.1:{hub_port}");

    let state = Arc::new(SharedState::new(hub_url));
    let (public_port, internal_port) = start_sidecar(state).await;

    // Connect using Authorization header (no token in query string).
    let uri: Uri = format!("ws://127.0.0.1:{public_port}/ws?mailbox_id={TEST_MAILBOX}")
        .parse()
        .unwrap();
    let request = tungstenite::http::Request::builder()
        .uri(uri.to_string())
        .header("Authorization", format!("Bearer {TEST_TOKEN}"))
        .header("Host", "localhost")
        .header("Connection", "Upgrade")
        .header("Upgrade", "websocket")
        .header("Sec-WebSocket-Version", "13")
        .header(
            "Sec-WebSocket-Key",
            tungstenite::handshake::client::generate_key(),
        )
        .body(())
        .unwrap();

    let (mut ws, _) = tokio_tungstenite::connect_async(request)
        .await
        .expect("WS connect with Authorization header failed");

    // Give the connection a moment to register.
    tokio::time::sleep(Duration::from_millis(50)).await;

    // Nudge and verify.
    let client = reqwest::Client::new();
    let resp = client
        .post(format!(
            "http://127.0.0.1:{internal_port}/internal/notify/{TEST_MAILBOX}"
        ))
        .send()
        .await
        .expect("notify failed");
    assert_eq!(resp.status(), 200);

    let msg = tokio::time::timeout(Duration::from_secs(2), ws.next())
        .await
        .expect("timeout waiting for nudge")
        .expect("stream ended")
        .expect("ws error");

    let text = match msg {
        tungstenite::Message::Text(t) => t,
        other => panic!("expected text, got {other:?}"),
    };
    let json: serde_json::Value = serde_json::from_str(&text).unwrap();
    assert_eq!(json["type"], "mailbox_nudge");

    ws.close(None).await.ok();
}

#[tokio::test]
async fn invalid_mailbox_id_rejected() {
    let hub_port = start_mock_hub().await;
    let hub_url = format!("http://127.0.0.1:{hub_port}");

    let state = Arc::new(SharedState::new(hub_url));
    let (public_port, _) = start_sidecar(state).await;

    // Invalid mailbox_id (not UUID format).
    let ws_url =
        format!("ws://127.0.0.1:{public_port}/ws?mailbox_id=not-a-uuid&token={TEST_TOKEN}");
    let result = tokio_tungstenite::connect_async(&ws_url).await;
    assert!(result.is_err(), "invalid mailbox_id should be rejected");
}
