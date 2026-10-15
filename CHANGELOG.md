# Changelog

All notable changes to this package are recorded here, newest first.

## 0.6.0 - 2026-10-15

### Added

- `OrganizationInactiveException`, thrown for a 402 with the code `organization_unpaid`, which the API answers to every operation except reading the organization while the organization is inactive. It is never retried, since only choosing a plan fixes it. Its message says that the organization is inactive and points to the Billing page of the IngestVault console, and like every other exception from the API it carries the `requestId` and ends its message with it.
- `state`, `plan` and `unpaidReason` on `Organization`, as the API returns them. `state` is `active`, or `unpaid` while the organization is inactive. `plan` is `null` while the organization is inactive, and `unpaidReason` is `null` while it is active.

### Changed

- The constructor of `Organization` takes the state, the plan and the reason as new parameters before the request id. Only code that constructs it itself is affected.
- A 402 with the code `organization_unpaid` was a plain `ApiException` in earlier versions.

## 0.5.0 - 2026-10-08

### Added

- `requestId` on every exception from the API, the id the API gave the failed request, taken from the `Request-Id` header or, when that is missing, from the problem document. The exception's message ends with it, as in `(request req_...)`. It is `null`, and the message carries no id, when the answer came from outside the API, such as a proxy's error page.
- `requestId` on every result object and on `Page`, from the `Request-Id` header of the answer. Items in a list carry the id of the page they came from.

### Changed

- The constructors of the result objects, of `Page` and of `ValidationException`, `RateLimitedException` and `QuotaExceededException` take the request id as a new parameter. Only code that constructs these objects itself is affected.

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
