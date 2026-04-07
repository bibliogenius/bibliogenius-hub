//! Shared state for the WebSocket sidecar.
//!
//! Tracks connected WebSocket clients per mailbox and dispatches nudge signals.

use std::sync::Arc;
use std::time::Duration;

use axum::extract::ws::Message;
use dashmap::DashMap;
use tokio::sync::mpsc;

/// Maximum concurrent WebSocket connections per mailbox.
const MAX_CONNECTIONS_PER_MAILBOX: usize = 2;

/// Interval between dead-sender cleanup sweeps.
const CLEANUP_INTERVAL: Duration = Duration::from_secs(60);

/// A sender handle for a connected WebSocket client.
type ClientSender = mpsc::UnboundedSender<Message>;

/// Central registry of connected WebSocket clients, keyed by mailbox UUID.
#[derive(Debug)]
pub struct SharedState {
    clients: DashMap<String, Vec<ClientSender>>,
    /// URL of the Hub PHP for token verification (e.g. "http://127.0.0.1:8080").
    pub hub_internal_url: String,
    /// Shared HTTP client for Hub PHP verification calls.
    pub http_client: reqwest::Client,
}

impl SharedState {
    pub fn new(hub_internal_url: String) -> Self {
        let http_client = reqwest::Client::builder()
            .timeout(Duration::from_secs(5))
            .pool_max_idle_per_host(2)
            .build()
            .expect("failed to build HTTP client");

        Self {
            clients: DashMap::new(),
            hub_internal_url,
            http_client,
        }
    }

    /// Register a new WebSocket client for a mailbox.
    ///
    /// Returns `None` if the mailbox already has `MAX_CONNECTIONS_PER_MAILBOX` active connections.
    /// Returns `Some(UnboundedReceiver)` on success -- the caller reads from this to forward
    /// messages to the WebSocket.
    pub fn register(&self, mailbox_id: &str) -> Option<mpsc::UnboundedReceiver<Message>> {
        let mut entry = self.clients.entry(mailbox_id.to_owned()).or_default();
        let senders = entry.value_mut();

        // Prune dead senders first (receiver dropped = client disconnected).
        senders.retain(|s| !s.is_closed());

        if senders.len() >= MAX_CONNECTIONS_PER_MAILBOX {
            return None;
        }

        let (tx, rx) = mpsc::unbounded_channel();
        senders.push(tx);
        Some(rx)
    }

    /// Send a nudge to all connected clients for a mailbox.
    ///
    /// Returns the number of clients that were successfully notified.
    pub fn notify(&self, mailbox_id: &str) -> usize {
        let Some(mut entry) = self.clients.get_mut(mailbox_id) else {
            return 0;
        };

        let senders = entry.value_mut();
        let nudge = serde_json::json!({
            "type": "mailbox_nudge",
            "mailbox_id": mailbox_id,
        });
        let msg = Message::Text(nudge.to_string().into());

        // Send to all, track failures for cleanup.
        let mut notified = 0;
        senders.retain(|tx| {
            if tx.send(msg.clone()).is_ok() {
                notified += 1;
                true
            } else {
                false // Remove dead sender.
            }
        });

        // Remove the entry entirely if no senders remain.
        if senders.is_empty() {
            drop(entry);
            self.clients.remove(mailbox_id);
        }

        notified
    }

    /// Periodic cleanup: remove dead senders from all mailboxes.
    pub async fn cleanup_loop(self: Arc<Self>) {
        loop {
            tokio::time::sleep(CLEANUP_INTERVAL).await;

            let mut empty_keys = Vec::new();
            for mut entry in self.clients.iter_mut() {
                entry.value_mut().retain(|s| !s.is_closed());
                if entry.value().is_empty() {
                    empty_keys.push(entry.key().clone());
                }
            }
            for key in empty_keys {
                self.clients.remove(&key);
            }

            tracing::debug!(
                "Cleanup: {} mailboxes with active connections",
                self.clients.len()
            );
        }
    }

    /// Number of mailboxes with at least one connected client.
    #[cfg(test)]
    pub fn mailbox_count(&self) -> usize {
        self.clients.len()
    }

    /// Total number of connected clients across all mailboxes.
    #[cfg(test)]
    pub fn client_count(&self) -> usize {
        self.clients.iter().map(|e| e.value().len()).sum()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn register_and_notify() {
        let state = SharedState::new("http://unused".to_string());
        let mut rx = state.register("mailbox-1").expect("should register");

        let notified = state.notify("mailbox-1");
        assert_eq!(notified, 1);

        let msg = rx.try_recv().expect("should have message");
        let text = match msg {
            Message::Text(t) => t.to_string(),
            _ => panic!("expected text message"),
        };
        let json: serde_json::Value = serde_json::from_str(&text).unwrap();
        assert_eq!(json["type"], "mailbox_nudge");
        assert_eq!(json["mailbox_id"], "mailbox-1");
    }

    #[test]
    fn max_connections_enforced() {
        let state = SharedState::new("http://unused".to_string());
        let _rx1 = state.register("mailbox-1").expect("first");
        let _rx2 = state.register("mailbox-1").expect("second");
        assert!(
            state.register("mailbox-1").is_none(),
            "third should be rejected"
        );
    }

    #[test]
    fn dead_sender_pruned_on_register() {
        let state = SharedState::new("http://unused".to_string());
        let _rx1 = state.register("mailbox-1").expect("first");
        let rx2 = state.register("mailbox-1").expect("second");

        // Drop rx2 -- its sender becomes dead.
        drop(rx2);

        // Should succeed because dead sender is pruned.
        let _rx3 = state.register("mailbox-1").expect("should reclaim slot");
    }

    #[test]
    fn notify_unknown_mailbox_returns_zero() {
        let state = SharedState::new("http://unused".to_string());
        assert_eq!(state.notify("nonexistent"), 0);
    }

    #[test]
    fn notify_prunes_dead_and_removes_empty() {
        let state = SharedState::new("http://unused".to_string());
        let rx = state.register("mailbox-1").expect("register");
        drop(rx);

        let notified = state.notify("mailbox-1");
        assert_eq!(notified, 0);
        assert_eq!(state.mailbox_count(), 0);
    }
}
