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
use futures::{Sink, SinkExt, Stream, StreamExt};
use serde::Deserialize;
use tokio::sync::mpsc;
use tokio::time::MissedTickBehavior;

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

/// Pure liveness state machine for the keep-alive ping/pong handshake.
///
/// Kept free of any async or socket plumbing so the timeout logic can be
/// tested exhaustively without a runtime. The rule is simple: every ping
/// interval we expect the peer to have proven itself alive (via *any* inbound
/// frame) since the previous ping. If it has not, the connection is dead.
#[derive(Debug, Default, PartialEq, Eq)]
struct Liveness {
    /// True once a ping has been sent and no inbound frame has arrived since.
    awaiting_pong: bool,
}

/// What the ping-interval tick should do, decided by [`Liveness::on_tick`].
#[derive(Debug, PartialEq, Eq)]
enum TickAction {
    /// The peer answered since the last ping: send a fresh ping.
    SendPing,
    /// The peer never answered the previous ping: the connection is dead.
    Timeout,
}

impl Liveness {
    /// Record an inbound frame. Any frame (a pong, or any other message)
    /// proves the peer is alive, so the pending ping is considered answered.
    fn on_inbound(&mut self) {
        self.awaiting_pong = false;
    }

    /// Advance one ping interval. If we were already awaiting a pong, the peer
    /// missed a full interval and the connection has timed out. Otherwise we
    /// send a new ping and start waiting for the answer.
    fn on_tick(&mut self) -> TickAction {
        if self.awaiting_pong {
            TickAction::Timeout
        } else {
            self.awaiting_pong = true;
            TickAction::SendPing
        }
    }
}

/// Why a connection loop terminated. Surfaced for structured logging and to
/// make [`run_connection`] outcomes assertable in tests.
#[derive(Debug, PartialEq, Eq)]
enum CloseReason {
    /// The peer closed the stream, or it ended/errored (normal disconnect).
    Disconnected,
    /// The peer stopped answering pings within the timeout window.
    PongTimeout,
    /// Writing to the peer failed (socket send error).
    SendFailed,
    /// The nudge channel closed, i.e. the sidecar is shutting down.
    NudgeChannelClosed,
}

/// Run the WebSocket connection: forward nudges from the channel, send pings.
///
/// On return, `nudge_rx` is dropped, which marks the matching sender as closed
/// in `SharedState`. The cleanup loop or next `notify()`/`register()` call then
/// prunes it, freeing the mailbox slot.
async fn handle_socket(
    socket: WebSocket,
    nudge_rx: mpsc::UnboundedReceiver<Message>,
    mailbox_id: String,
) {
    let (sink, stream) = socket.split();
    let reason = run_connection(sink, stream, nudge_rx).await;

    tracing::info!(
        mailbox_id = %mailbox_id,
        reason = ?reason,
        "WebSocket client disconnected"
    );
}

/// Drive a single WebSocket connection until it closes.
///
/// One loop owns both halves of the socket so there is no second task to abort:
/// - any inbound frame proves the peer is alive and resets the liveness clock;
///   a `Close`/error/end of stream is a normal disconnect.
/// - nudges are forwarded to the peer; a closed nudge channel means shutdown.
/// - each ping interval either sends a keep-alive ping or, if the previous one
///   was never answered, declares a pong timeout and frees the slot.
///
/// Generic over the sink/stream halves so it can be exercised with in-memory
/// channels under a paused clock, independent of a real socket.
async fn run_connection<S, R, E>(
    mut sink: S,
    mut stream: R,
    mut nudge_rx: mpsc::UnboundedReceiver<Message>,
) -> CloseReason
where
    S: Sink<Message> + Unpin,
    R: Stream<Item = Result<Message, E>> + Unpin,
{
    let mut liveness = Liveness::default();

    let mut ping_interval = tokio::time::interval(PING_INTERVAL);
    // A slow send must not trigger a burst of catch-up pings afterwards.
    ping_interval.set_missed_tick_behavior(MissedTickBehavior::Delay);
    ping_interval.tick().await; // Skip the immediate first tick.

    loop {
        tokio::select! {
            // Peer -> us: any frame proves liveness; Close/error/end disconnects.
            inbound = stream.next() => match inbound {
                Some(Ok(Message::Close(_))) | Some(Err(_)) | None => {
                    return CloseReason::Disconnected;
                }
                Some(Ok(_)) => liveness.on_inbound(),
            },
            // Server -> peer: forward a nudge, or shut down if the channel closed.
            nudge = nudge_rx.recv() => match nudge {
                Some(msg) => {
                    if sink.send(msg).await.is_err() {
                        return CloseReason::SendFailed;
                    }
                }
                None => return CloseReason::NudgeChannelClosed,
            },
            // Keep-alive timer.
            _ = ping_interval.tick() => match liveness.on_tick() {
                TickAction::Timeout => return CloseReason::PongTimeout,
                TickAction::SendPing => {
                    if sink.send(Message::Ping(Vec::new().into())).await.is_err() {
                        return CloseReason::SendFailed;
                    }
                }
            },
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    // --- Pure liveness state machine ------------------------------------

    #[test]
    fn liveness_starts_not_awaiting_pong() {
        assert!(!Liveness::default().awaiting_pong);
    }

    #[test]
    fn tick_while_idle_sends_ping_and_starts_waiting() {
        let mut liveness = Liveness::default();
        assert_eq!(liveness.on_tick(), TickAction::SendPing);
        assert!(liveness.awaiting_pong);
    }

    #[test]
    fn tick_while_awaiting_pong_times_out() {
        let mut liveness = Liveness::default();
        liveness.on_tick(); // ping sent, now awaiting a pong
        assert_eq!(liveness.on_tick(), TickAction::Timeout);
    }

    #[test]
    fn inbound_frame_defers_timeout_by_one_interval() {
        let mut liveness = Liveness::default();

        // Interval 1: send a ping.
        assert_eq!(liveness.on_tick(), TickAction::SendPing);
        // Peer answers (any inbound frame counts).
        liveness.on_inbound();
        assert!(!liveness.awaiting_pong);
        // Interval 2: because the peer answered, we ping again instead of
        // timing out -- the answer deferred the timeout by a full interval.
        assert_eq!(liveness.on_tick(), TickAction::SendPing);
    }

    #[test]
    fn sustained_pong_responses_never_time_out() {
        let mut liveness = Liveness::default();
        for _ in 0..100 {
            assert_eq!(liveness.on_tick(), TickAction::SendPing);
            liveness.on_inbound(); // peer keeps answering
        }
    }

    // --- Connection loop ------------------------------------------------
    //
    // Driven with in-memory channels: a futures mpsc as the sink (so we can
    // inspect or fail sends) and a futures stream as the inbound half. The
    // paused clock auto-advances to the next ping deadline.

    use futures::channel::mpsc as fmpsc;

    /// Build an inbound stream that never yields (a peer that is silent).
    fn silent_stream() -> impl Stream<Item = Result<Message, ()>> + Unpin {
        futures::stream::pending()
    }

    #[tokio::test(start_paused = true)]
    async fn silent_peer_times_out_after_exactly_one_ping() {
        let (sink_tx, sink_rx) = fmpsc::unbounded::<Message>();
        // Keep the nudge sender alive so the channel never closes on its own.
        let (_nudge_tx, nudge_rx) = mpsc::unbounded_channel::<Message>();

        let reason = run_connection(sink_tx, silent_stream(), nudge_rx).await;
        assert_eq!(reason, CloseReason::PongTimeout);

        // Exactly one keep-alive ping should have been sent before the timeout.
        let sent: Vec<Message> = sink_rx.collect().await;
        assert_eq!(sent.len(), 1, "expected a single ping before timeout");
        assert!(matches!(sent[0], Message::Ping(_)));
    }

    #[tokio::test(start_paused = true)]
    async fn close_frame_is_a_normal_disconnect() {
        let (sink_tx, _sink_rx) = fmpsc::unbounded::<Message>();
        let (_nudge_tx, nudge_rx) = mpsc::unbounded_channel::<Message>();
        let stream = futures::stream::iter(vec![Ok::<Message, ()>(Message::Close(None))]);

        let reason = run_connection(sink_tx, stream, nudge_rx).await;
        assert_eq!(reason, CloseReason::Disconnected);
    }

    #[tokio::test(start_paused = true)]
    async fn stream_error_is_a_disconnect() {
        let (sink_tx, _sink_rx) = fmpsc::unbounded::<Message>();
        let (_nudge_tx, nudge_rx) = mpsc::unbounded_channel::<Message>();
        let stream = futures::stream::iter(vec![Err::<Message, ()>(())]);

        let reason = run_connection(sink_tx, stream, nudge_rx).await;
        assert_eq!(reason, CloseReason::Disconnected);
    }

    #[tokio::test(start_paused = true)]
    async fn preloaded_nudge_is_forwarded_then_channel_close_shuts_down() {
        let (sink_tx, sink_rx) = fmpsc::unbounded::<Message>();
        let (nudge_tx, nudge_rx) = mpsc::unbounded_channel::<Message>();

        // Queue one nudge, then close the channel: the loop must forward the
        // nudge and then exit on `None` (no busy-loop).
        nudge_tx
            .send(Message::Text("queued-nudge".into()))
            .expect("send nudge");
        drop(nudge_tx);

        let reason = run_connection(sink_tx, silent_stream(), nudge_rx).await;
        assert_eq!(reason, CloseReason::NudgeChannelClosed);

        let sent: Vec<Message> = sink_rx.collect().await;
        assert_eq!(sent.len(), 1);
        assert!(matches!(&sent[0], Message::Text(t) if t.as_str() == "queued-nudge"));
    }

    #[tokio::test(start_paused = true)]
    async fn send_failure_is_reported() {
        // Drop the sink receiver so every send fails.
        let (sink_tx, sink_rx) = fmpsc::unbounded::<Message>();
        drop(sink_rx);
        let (_nudge_tx, nudge_rx) = mpsc::unbounded_channel::<Message>();

        // The first ping (at one interval) fails to send.
        let reason = run_connection(sink_tx, silent_stream(), nudge_rx).await;
        assert_eq!(reason, CloseReason::SendFailed);
    }
}
