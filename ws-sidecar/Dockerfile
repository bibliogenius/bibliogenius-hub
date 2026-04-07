FROM rust:1.87-slim AS builder

WORKDIR /app
COPY Cargo.toml Cargo.toml
COPY src/ src/

RUN cargo build --release --bin ws-sidecar

FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y ca-certificates curl && rm -rf /var/lib/apt/lists/*

COPY --from=builder /app/target/release/ws-sidecar /usr/local/bin/ws-sidecar

ENV RUST_LOG=info
ENV PUBLIC_PORT=9091
ENV INTERNAL_PORT=9090

EXPOSE 9091 9090

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -f http://localhost:9091/health || exit 1

ENTRYPOINT ["ws-sidecar"]
