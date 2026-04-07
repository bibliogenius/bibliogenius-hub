//! Internal notification endpoint.
//!
//! Called by the Hub PHP after a message is deposited in a relay mailbox.
//! Sends a nudge to all connected WebSocket clients for that mailbox.

use std::sync::Arc;

use axum::Json;
use axum::extract::{Path, State};
use axum::http::StatusCode;
use axum::response::IntoResponse;
use serde::Serialize;

use crate::state::SharedState;
use crate::validation::is_valid_uuid;

#[derive(Serialize)]
struct NotifyResponse {
    notified: usize,
}

/// POST /internal/notify/{mailbox_id}
///
/// Sends a nudge signal to all WebSocket clients connected to the given mailbox.
/// Returns 200 with the number of notified clients, or 204 if no clients are connected.
/// Returns 400 if mailbox_id is malformed.
pub async fn handle_notify(
    Path(mailbox_id): Path<String>,
    State(state): State<Arc<SharedState>>,
) -> impl IntoResponse {
    if !is_valid_uuid(&mailbox_id) {
        return (StatusCode::BAD_REQUEST, "Invalid mailbox_id format").into_response();
    }

    let notified = state.notify(&mailbox_id);

    if notified == 0 {
        tracing::debug!(mailbox_id = %mailbox_id, "No connected clients to nudge");
        StatusCode::NO_CONTENT.into_response()
    } else {
        tracing::info!(mailbox_id = %mailbox_id, notified, "Nudge sent");
        (StatusCode::OK, Json(NotifyResponse { notified })).into_response()
    }
}
