# Changelog

All notable changes to this package are recorded here, newest first.

## 0.4.0 - 2026-10-07

### Changed

- A send without a payload argument now sends an event without a payload. Earlier versions sent the payload `null`; pass `null` to keep doing that.

### Added

- `RawJson`, for sending a payload as exact JSON text, byte for byte.
- The `none` payload state on events and deliveries, for an event sent without a payload. Both payload forms are `null` under it.

## 0.3.0 - 2026-10-07

### Added

- Reading, listing and replaying events and deliveries, with the payload as decoded values and as the exact JSON text, and reading the organization.

## 0.2.0 - 2026-10-07

### Added

- Endpoints, signing secrets, subscriptions and event types, with paginated lists, and the client grouped by resource.

## 0.1.0 - 2026-10-06

### Added

- Sending events, with idempotency keys, retries and prepared sends.
