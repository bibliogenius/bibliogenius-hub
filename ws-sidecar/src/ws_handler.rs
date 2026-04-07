//! WebSocket upgrade handler.
//!
//! Authenticates clients via read_token (Authorization header preferred,
//! query param as fallback), upgrades to WebSocket, and forwards nudge
//! signals from SharedState.

use std::sync::Arc;
use std::time::Duration;

use axum::extract::ws::{Message, WebSocket};
use axum::extract::{Query, State, WebSocketUpgrade};
use axum::http::{HeaderMap, StatusCode};
use axum::response::{IntoResponse, Response};
use futures::stream::SplitSink;
use futures::{SinkExt, StreamExt};
use serde::Deserialize;
use tokio::sync::mpsc;

use crate::state::SharedState;
use crate::validation::{is_valid_token, is_valid_uuid};

/// Ping interval to keep the WebSocket alive through proxies and firewalls.
const PING_INTERVAL: Duration = Duration::from_secs(30);

/// Query parameters for the WebSocket handshake.
#[derive(Debug, Deserialize)]
pub struct WsParams {
    pub mailbox_id: String,
    /// Token via query param (fallback for debug tools like websocat).
    /// Prefer Authorization header in production clients.
    pub token: Option<String>,
}

/// Extract Bearer token from Authorization header.
fn extract_bearer_token(headers: &HeaderMap) -> Option<String> {
    let value = headers.get("authorization")?.to_str().ok()?;
    let token = value.strip_prefix("Bearer ")?;
    Some(token.to_string())
}

/// GET /ws?mailbox_id={uuid}
/// Authorization: Bearer {read_token}
///
/// Validates the token against the Hub PHP, then upgrades to WebSocket.
/// Token source priority: Authorization header > query param (debug fallback).
pub async fn handle_ws(
    ws: WebSocketUpgrade,
    headers: HeaderMap,
    Query(params): Query<WsParams>,
    State(state): State<Arc<SharedState>>,
) -> Response {
    // Validate mailbox_id format.
    if !is_valid_uuid(&params.mailbox_id) {
        return (StatusCode::BAD_REQUEST, "Invalid mailbox_id format").into_response();
    }

    // Extract token: prefer Authorization header, fall back to query param.
    let token = extract_bearer_token(&headers)
        .or(params.token)
        .unwrap_or_default();

    if !is_valid_token(&token) {
        return (StatusCode::BAD_REQUEST, "Invalid or missing token").into_response();
    }

    // Verify token against Hub PHP (lightweight endpoint, no DB side effects).
    let verify_url = format!(
        "{}/api/relay/mailbox/{}/verify",
        state.hub_internal_url, params.mailbox_id
    );

    let verify_result = state
        .http_client
        .get(&verify_url)
        .header("Authorization", format!("Bearer {token}"))
        .send()
        .await;

    match verify_result {
        Ok(resp) if resp.status().is_success() => {
            // Token verified by Hub PHP.
        }
        Ok(resp) => {
            tracing::warn!(
                mailbox_id = %params.mailbox_id,
                status = %resp.status(),
                "WS auth rejected by Hub"
            );
            return (StatusCode::UNAUTHORIZED, "Invalid token").into_response();
        }
        Err(e) => {
            tracing::error!("Hub auth verification failed: {e}");
            return (StatusCode::SERVICE_UNAVAILABLE, "Auth service unavailable").into_response();
        }
    }

    // Register in shared state (enforces max connections per mailbox).
    let rx = match state.register(&params.mailbox_id) {
        Some(rx) => rx,
        None => {
            tracing::warn!(
                mailbox_id = %params.mailbox_id,
                "Max connections reached for mailbox"
            );
            return (
                StatusCode::TOO_MANY_REQUESTS,
                "Too many connections for this mailbox",
            )
                .into_response();
        }
    };

    let mailbox_id = params.mailbox_id.clone();
    tracing::info!(mailbox_id = %mailbox_id, "WebSocket client connected");

    ws.on_upgrade(move |socket| handle_socket(socket, rx, mailbox_id))
}

/// Run the WebSocket connection: forward nudges from the channel, send pings.
async fn handle_socket(
    socket: WebSocket,
    mut nudge_rx: mpsc::UnboundedReceiver<Message>,
    mailbox_id: String,
) {
    let (mut sender, mut receiver) = socket.split();

    // Spawn a task to forward nudges and pings to the client.
    let mut send_task = tokio::spawn(async move {
        send_loop(&mut sender, &mut nudge_rx).await;
    });

    // Read loop: we don't expect client messages, but we need to consume
    // the stream to detect disconnection and handle pong responses.
    let mut recv_task = tokio::spawn(async move {
        while let Some(msg) = receiver.next().await {
            match msg {
                Ok(Message::Close(_)) | Err(_) => break,
                _ => {} // Ignore client messages (pong handled by axum).
            }
        }
    });

    // Wait for either task to finish (client disconnect or send error).
    // Abort the other to prevent orphaned tasks.
    tokio::select! {
        _ = &mut send_task => { recv_task.abort(); }
        _ = &mut recv_task => { send_task.abort(); }
    }

    tracing::info!(mailbox_id = %mailbox_id, "WebSocket client disconnected");
    // Sender is dropped here, which marks it as closed in SharedState.
    // The cleanup loop or next notify() call will prune it.
}

/// Forward nudge messages and send periodic pings.
async fn send_loop(
    sender: &mut SplitSink<WebSocket, Message>,
    nudge_rx: &mut mpsc::UnboundedReceiver<Message>,
) {
    let mut ping_interval = tokio::time::interval(PING_INTERVAL);
    ping_interval.tick().await; // Skip the immediate first tick.

    loop {
        tokio::select! {
            Some(msg) = nudge_rx.recv() => {
                if sender.send(msg).await.is_err() {
                    break;
                }
            }
            _ = ping_interval.tick() => {
                if sender.send(Message::Ping(vec![].into())).await.is_err() {
                    break;
                }
            }
        }
    }
}
