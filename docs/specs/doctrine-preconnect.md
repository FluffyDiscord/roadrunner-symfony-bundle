# Doctrine PostgreSQL Preconnect (Implementation)

**Source pinned to:** bundle commit `a1dad74` (branch `db-preconnect`, 2026-06-24).
`doctrine/dbal` 4.4.3 and `doctrine/persistence` 4.2.0 are **vendored and verified
locally** (added to `require-dev`). `doctrine/doctrine-bundle` 3.2.x is **not vendored**
(it cannot resolve against this repo's Symfony 8 lock) and its claims here are **verified
via GitHub source** at the 3.2.x branch — see [Assumptions](#6-assumptions).

## 1. Problem & scope

**Problem, for which actor:** A Symfony app running under RoadRunner with a PostgreSQL
Doctrine connection pays the PostgreSQL connection-establishment cost (TCP + TLS + auth
handshake) on the **first request each worker handles**, because Doctrine DBAL connections
are lazy — the socket is opened on first use, not at construction (`connect()` is only
reached from query / `getNativeConnection()` paths — `vendor/doctrine/dbal/src/Connection.php:215`).
RoadRunner workers are long-lived, so the cost is paid once per worker boot, but it lands
as a latency spike on a real user request.

**Why a process-local preconnect, not pooling:** PgBouncer-style pooling is external to
PHP and irrelevant inside a long-lived worker. Two further driver facts make boot-time
preconnect the right per-worker fix:
- The **PDO** PG driver honours `persistent` → `PDO::ATTR_PERSISTENT`
  (`Driver/PDO/PgSQL/Driver.php:34`), so a PDO connection *can* be reused across worker
  spawns — but only the first spawn's first request warms it.
- The **native `pgsql`** driver calls `pg_connect(..., PGSQL_CONNECT_FORCE_NEW)`
  (`Driver/PgSQL/Driver.php:41`) and has **no `persistent` support at all** — every worker
  gets a fresh process-local socket regardless. Preconnect's value here is
  persistence-independent and highest, and it makes the README's existing line
  *"PostgreSQL — nothing to do, as long as you did not disable persistent connections"*
  **driver-blind**: there is no persistence to disable on the native driver.

**Outcome:** Open the PostgreSQL connection during worker boot — after the kernel is
booted, before the worker accepts its first request — so the first request finds a warm
socket. Because the bundle never closes the DBAL connection between requests (it only
clears EM identity maps — `Registry::reset()`, §References), one boot-time connect benefits
**every** request that worker serves.

**In scope:** PostgreSQL DBAL connections, all RoadRunner worker types (HTTP / Centrifugo /
Jobs / Temporal), opt-out config, **and a correction to the README "Database connections"
section** so its PG guidance is driver-aware and references this feature.

**Out of scope (NOT doing, with rationale):**
- **MySQL / MariaDB / other drivers** — explicitly skipped. The bundle already instructs
  users to *reset* MySQL connections per request (stale-socket handling, README "Database
  connections"); preconnecting a connection that is reset every request is pointless, and
  MySQL `wait_timeout` can kill an idle boot-time socket before the first request.
- **Per-query / per-request reconnect or stale-connection healing** — a different concern,
  already covered by `WorkerRequestReceivedEvent` in the README.
- **Connection pooling / PgBouncer replacement** — preconnect warms one socket per worker,
  it does not pool.
- **Per-connection enable/disable granularity** — the contract is a single boolean
  (`doctrine.preconnect`); all PG connections are preconnected when on. Deferred until a
  concrete multi-connection need appears.

## 2. Architecture decisions

### ADR-1 — Hook the existing `WorkerBootingEvent`, wired as a `kernel.event_listener`
`WorkerBootingEvent` is already dispatched once per worker, after `kernel->boot()` and
before the serve loop, by **all four** workers (`HttpWorker.php:90`, `CentrifugoWorker.php:56`,
`JobsWorker.php:41`, `TemporalWorker.php:33`). A single `kernel.event_listener` therefore
covers every worker type with no per-worker wiring.
**Container availability:** `Runner::run()` calls `kernel->boot()` (`Runtime/Runner.php:23`)
*before* resolving and starting the worker; `Kernel::boot()` is idempotent. A per-worker
`lazy_boot: true` only governs whether the **worker** re-boots (`HttpWorker.php:78`) — it
does **not** tear down the container the Runner already built. So the container, and the
`doctrine` service, are live when `WorkerBootingEvent` fires in every worker, in both boot
modes. *Trade-off:* the listener cannot tell which worker type dispatched the event, so it
cannot honour a per-worker `lazy_boot` (ADR-6); accepted.

### ADR-2 — Detect PostgreSQL via the configured **driver name** (`getParams()['driver']`)
Detection must not open a socket (we must skip MySQL without connecting just to learn it is
MySQL) **and** must survive driver middleware. The signal is the connection's configured
driver name: `$connection->getParams()['driver']` ∈ {`pdo_pgsql`, `pgsql`}. It lives in the
Connection's params (config), so it is socket-free and untouched by driver middleware.
**Rejected:** `getDriver() instanceof AbstractPostgreSQLDriver` — *appears* clean and
socket-free, but Symfony's doctrine-bridge and doctrine-bundle wrap the DBAL driver in
middleware (`Symfony\Bridge\Doctrine\Middleware\IdleConnection\Driver`, doctrine-bundle's
logging middleware, …), so `getDriver()` returns the outermost `AbstractDriverMiddleware`,
never `AbstractPostgreSQLDriver`. `AbstractDriverMiddleware` keeps its `$wrappedDriver`
**private with no getter** (`vendor/doctrine/dbal/src/Driver/Middleware/AbstractDriverMiddleware.php`),
so there is no clean unwrap. **This was caught by the live E2E harness (§9); the listener
unit tests with mocked drivers and a raw-`DriverManager` probe — neither of which has a
middleware stack — both passed against the broken version.**
**Rejected:** `getDatabasePlatform() instanceof PostgreSQLPlatform` — in DBAL 4 this
*connects* when `serverVersion` is unset (`Connection.php:185-199` → `$versionProvider = $this`
→ `getServerVersion()` → `Connection.php:241` → `connect()`), opening the very sockets we
avoid for non-PG connections.
**Accepted caveat:** `getParams()` is annotated `@internal` (`Connection.php:137`); it is the
only socket-free, middleware-immune signal and is stable in practice (the alternative is a
feature that silently no-ops under doctrine-bundle). Connections configured via a custom
`driverClass` (no `driver` name) are not auto-detected and stay lazy — documented limitation (§10).

### ADR-3 — Force the connection via `getNativeConnection()`, discard the handle
`Connection::connect()` is `protected` in DBAL 4 (`Connection.php:215`) — not callable from
a listener. The public way to force the socket is `getNativeConnection()`
(`Connection.php:1223` = `$this->connect()->getNativeConnection()`); it opens the connection
and returns the native handle.
**Discarding the handle is safe** (the whole feature would be null otherwise): the driver
connections return the *same* object they keep — `PDO\Connection::getNativeConnection()`
returns `$this->connection` (`Driver/PDO/Connection.php:129-131`), likewise
`PgSQL\Connection` (`Driver/PgSQL/Connection.php:128-131`) — and that object is held by the
DBAL `Connection::$_conn`. Dropping our local copy does not drop the refcount to zero, so
the socket stays open.
**Rejected vs. `executeQuery('SELECT 1')`:** `getNativeConnection()` does only the connect
handshake (no extra query round-trip) and is driver-agnostic.

### ADR-4 — Default **on** (PG-aware), opt out with `preconnect: false`
`doctrine.preconnect` defaults to `true` (explicit maintainer decision, 2026-06-24, with the
upgrade-behavior trade-off shown and accepted). When `doctrine/dbal` is installed and the
flag is on, the listener is registered and PG connections are warmed at boot automatically.
Apps that want no boot-time DB I/O set `doctrine.preconnect: false`, which registers **no**
listener (zero runtime overhead). When `doctrine/dbal` is absent the flag is inert.
*(Gate 3 recommended default-**off** on architecture-fit grounds — see §Open Questions; kept
on per maintainer sign-off, recorded as visible debt for a future flip, a one-line change.)*

### ADR-5 — Failures are logged and swallowed; the worker still boots
A boot-time connect can fail (DB not ready, transient network). The listener catches
`\Throwable` per connection, logs at WARNING via the optional `logger` service, and
continues. Rationale: (a) matches the bundle's graceful-error-handling philosophy
(`docs/specs/graceful-error-handling.md`); (b) a failed `connect()` leaves
`Connection::$_conn === null` (`Connection.php:221-225` — the assignment is inside the
`try`, the throw happens before it), so the connection stays lazy and **retries on the
first real query** with full framework error handling; (c) throwing at boot would crash the
worker → RoadRunner restart/crash-loop while the DB is briefly down.
**Log cardinality (not a per-request flood):** the listener runs **only at worker boot**, so
it emits at most one WARNING per PG connection per worker boot — bounded by worker-restart
frequency, never by request volume (the lazy retry on first request does not re-log). During
an outage with `K` workers and `M` PG connections this is `K×M` lines per restart cycle, not
per request. This is the *intended* signal; an app that prefers silence sets
`doctrine.preconnect: false`.

### ADR-6 — `lazy_boot` and `preconnect` are deliberately orthogonal
`WorkerBootingEvent` fires regardless of any worker's `lazy_boot`. `lazy_boot` governs
kernel-boot *timing*; `preconnect` governs DB-socket *timing*. They are independent knobs:
the listener is global (ADR-1) and cannot read a per-worker `lazy_boot`, so it does **not**
auto-disable under `lazy_boot`. A worker set `lazy_boot: true` for instant readiness that
also wants zero boot-time DB I/O sets `doctrine.preconnect: false` — documented in the
README. This is a deliberate decision, not an unresolved conflict.

### ADR-7 — Enumerate via `ConnectionRegistry::getConnections()`; accept boot-time service instantiation
Detection is **socket-free** (ADR-2) but not **service-free**:
`AbstractManagerRegistry::getConnections()` resolves every registered connection *service*
(`vendor/doctrine/persistence/src/AbstractManagerRegistry.php:85-92` → `getService($id)` per
connection), so at boot the bundle instantiates all DBAL connection **objects** (driver +
configuration + middleware wrappers), including non-PG ones it then skips. This is accepted:
constructing a DBAL `Connection` opens **no socket** and the objects are cheap; under the
default eager boot they are built on the first request anyway. **Rejected:** a compiler pass
collecting `doctrine.connection`-tagged services into a `ServiceLocator`/iterable so only
touched connections realize — disproportionate DI complexity for a feature whose connections
are used regardless, and harder to test than the one injected `doctrine` reference. The cost
is stated here so it is a decided trade-off, not a hidden one.

## 3. Behavior specification

On every worker boot, when `doctrine/dbal` is installed **and** `doctrine.preconnect` is
`true` (the default):

1. The bundle registers `DoctrinePreconnectListener` as a `kernel.event_listener` for
   `WorkerBootingEvent` (method `__invoke`), with the `doctrine` registry and the `logger`
   service injected as **optional** references (`NULL_ON_INVALID_REFERENCE`).
2. When `WorkerBootingEvent` is dispatched, the listener:
   a. If the injected registry is `null` (no `doctrine` service — e.g. DBAL present but
      doctrine-bundle absent), returns immediately (no-op).
   b. Calls `$registry->getConnections()` **inside a `try`**; if that throws (a misconfigured
      connection-service factory can throw on instantiation — ADR-7), logs one **driver-neutral**
      WARNING (no "PostgreSQL" — this step runs before any driver is known, so a non-PG app must
      not see a PostgreSQL error) and returns (does not crash boot).
   c. For each `name => connection`: skip anything not `instanceof \Doctrine\DBAL\Connection`
      **and** anything whose configured driver name (`getParams()['driver']`) is not `pdo_pgsql`
      or `pgsql`. Non-PostgreSQL connections are skipped here, before any work — so only
      PostgreSQL connections can reach the connect call or the PostgreSQL-specific warning.
   d. For each remaining (PostgreSQL) connection, **inside a per-connection `try`**, call
      `$connection->getNativeConnection()` (handle discarded — ADR-3). On `\Throwable`, log a
      WARNING with the connection name + exception, then continue to the next connection.
      (No `isConnected()` guard: `connect()` early-returns when `$_conn !== null` —
      `Connection.php:217-219`.)

When `doctrine.preconnect` is `false`, or `doctrine/dbal` is not installed, **no listener is
registered** and nothing runs at boot.

**Observable acceptance criteria:**
- AC-1: With a PG connection + flag on, the listener calls `getNativeConnection()` exactly
  once on that connection (which DBAL guarantees opens the socket — `Connection.php:1223`).
  *Verified by TC-D1 (the call); the live socket open is DBAL's contract, not re-asserted in
  the unit suite — §9.*
- AC-2: A non-PG (MySQL/SQLite) connection's `getNativeConnection()` is **never** called.
- AC-3: A PG connect that throws does not propagate out of the listener; other connections
  are still processed; one WARNING is logged.
- AC-4: With the flag off (or DBAL absent), no `kernel.event_listener` for
  `WorkerBootingEvent` pointing at `DoctrinePreconnectListener` exists in the container.
- AC-5: Only PG connections get `getNativeConnection()` called, regardless of how many
  connections exist.
- AC-6: If `getConnections()` itself throws, `__invoke` does not propagate it (one WARNING,
  return).

## 4. Configuration contract (public API — irreversible, Clarity check 7)

Added to the bundle config tree (`Configuration.php`), top-level, alongside
`http`/`kv`/`centrifugo`/`jobs`:

```yaml
fluffy_discord_road_runner:
  doctrine:
    # Open PostgreSQL Doctrine connections at worker boot (after the kernel
    # boots, before the first request) so the first request skips the
    # PostgreSQL connection handshake. Only PostgreSQL connections are
    # touched; other drivers are ignored. Requires doctrine/dbal; inert
    # without it. Runs on every worker boot regardless of `lazy_boot`.
    # Set false to opt out (no listener is registered).
    preconnect: true   # boolean, default true
```

- Node: `doctrine` → `addDefaultsIfNotSet()`; child `preconnect` → `booleanNode`,
  `defaultTrue()`. Always present in the tree (matches `http`/`kv`/`jobs`, defined
  unconditionally even when their optional package is absent).
- The extension's processed-config phpdoc type gains `doctrine: array{preconnect: bool}`.

## 5. Component design

### 5.1 `src/Doctrine/DoctrinePreconnectListener.php` (new)
```php
namespace FluffyDiscord\RoadRunnerBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use Psr\Log\LoggerInterface;

readonly class DoctrinePreconnectListener
{
    public function __construct(
        private ?ConnectionRegistry $registry,
        private ?LoggerInterface    $logger = null,
    ) {}

    public function __invoke(WorkerBootingEvent $event): void
    {
        if ($this->registry === null) {
            return;
        }

        try {
            $connections = $this->registry->getConnections();
        } catch (\Throwable $throwable) {
            $this->logger?->warning(
                'RoadRunner: unable to enumerate Doctrine connections for boot-time preconnect; skipping.',
                ['exception' => $throwable],
            );
            return;
        }

        foreach ($connections as $name => $connection) {
            if (!$connection instanceof Connection || !$this->isPostgres($connection)) {
                continue;
            }

            try {
                // Forces the lazy connection open; the returned handle is intentionally unused.
                $connection->getNativeConnection();
            } catch (\Throwable $throwable) {
                $this->logger?->warning(
                    'RoadRunner: PostgreSQL preconnect failed for Doctrine connection "{connection}"; it will be retried lazily on first use.',
                    // (string) guards numeric connection names: PHP coerces int-like array keys to int.
                    ['connection' => (string) $name, 'exception' => $throwable],
                );
            }
        }
    }

    private function isPostgres(Connection $connection): bool
    {
        // Driver NAME, not getDriver(): doctrine-bridge/doctrine-bundle wrap the driver in
        // middleware, so getDriver() is never AbstractPostgreSQLDriver. The name is socket-free.
        $driver = $connection->getParams()['driver'] ?? null;

        return $driver === 'pdo_pgsql' || $driver === 'pgsql';
    }
}
```
- `readonly`, no `final`, no `static` (rule G19); constructor injection only (G16); accepts
  the smallest interface (`ConnectionRegistry`, not `ManagerRegistry`) at the consumer.
- Comments are limited to the WHY ones whose fact the local code can't reveal (the
  `getNativeConnection` side-effect, the middleware/driver-name detection, the `(string)`
  int-key cast); no WHAT narration.

### 5.2 Wiring — `FluffyDiscordRoadRunnerExtension::load()` (modify)
Mirror the conditional-listener precedent `registerTemporalTracing()`
(`FluffyDiscordRoadRunnerExtension.php:279-291`). Add a private
`registerDoctrinePreconnect(ContainerBuilder $container): void` and call it from `load()`:

```php
if (class_exists(\Doctrine\DBAL\Connection::class) && $config['doctrine']['preconnect'] === true) {
    $this->registerDoctrinePreconnect($container);
}
```
`registerDoctrinePreconnect` builds a `Definition(DoctrinePreconnectListener::class, [...])`
with `new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE)` and
`new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)`, tags it
`kernel.event_listener` `['event' => WorkerBootingEvent::class, 'method' => '__invoke']`, and
`setDefinition(...)`. A short comment notes the gate is on **DBAL** (the feature's hard dep)
while the `doctrine` registry is referenced optionally (DBAL-without-doctrine-bundle is a
rare but valid setup → null-check no-op). No `config/doctrine.php` file: registration is
config-flag-gated, so it belongs in the extension next to `registerTemporalTracing()`, not in
the `class_exists`-only `config/*.php` group.

### 5.3 `composer.json` (verify only — already done)
- `require-dev`: `doctrine/dbal: ^4` and `doctrine/persistence: ^4` are **already present**
  (lines 67-68; locked at dbal 4.4.3 / persistence 4.2.0, vendored). No change needed —
  verify only; do not re-add (avoid a duplicate key).
- `suggest`: add `doctrine/dbal` → "Enables PostgreSQL connection preconnect at worker boot
  (doctrine.preconnect). The connection registry comes from doctrine/doctrine-bundle."

### 5.4 `README.md` (modify)
Correct the existing "Database connections" bullets to be driver-aware and reference the new
feature: native `pgsql` has no persistence (every worker reconnects), PDO honours
`persistent`; either way `doctrine.preconnect: true` (default) warms the socket at boot. Add
the `doctrine.preconnect` option to the Configuration block, noting it runs regardless of
`lazy_boot` and is opt-out.

## 6. Assumptions
| Assumption | If wrong, then… |
|------------|-----------------|
| The Doctrine registry service id is `doctrine` and implements `ConnectionRegistry` (`ManagerRegistry extends ConnectionRegistry` — verified in vendored persistence 4.2.0; service id + `reset()` behavior verified against doctrine-bundle 3.2.x `Registry` on GitHub, since doctrine-bundle is **not** vendored — it cannot resolve against this repo's Symfony 8 lock). | The `'doctrine'` reference is `NULL_ON_INVALID_REFERENCE`, so a wrong/absent id degrades to a null registry → no-op, never an error. |
| doctrine-bundle's per-request reset (`Registry::reset()`) clears EM identity maps only, never closing the DBAL socket (verified on GitHub 3.2.x). | If a future doctrine-bundle closed connections per request, preconnect would only benefit request #1 — still correct, just less valuable. |
| `getNativeConnection()` / `getDriver()` behave as specified on `doctrine/dbal` **^4** (tested against 4.4.3). DBAL 3.3+ has the same public methods but is **not** tested here. | On an untested DBAL 3.x, an API difference throws inside the per-connection `try` → logged + swallowed → lazy fallback; worst case preconnect silently no-ops. |
| Connection names from `getConnections()` are typed `array<string, object>` but PHP coerces int-like string keys to `int` at runtime; the `(string) $name` cast in the log context is **intentional** (runtime correctness), not a PHPStan-quieting cast, and is exempt from the project's no-useless-cast rule. | Without the cast a connection literally named `"0"` logs an `int`; harmless but type-inconsistent. |
| doctrine-bundle resolves every normal connection (a `url` or explicit `driver:`) to a `params['driver']` name (`pdo_pgsql` for `postgresql://`), so `getParams()['driver']` reliably identifies PG. Verified by the live E2E (it reports the name). | A connection with no `driver` name (custom `driverClass`) is not detected and stays lazy — documented in §10. |
| `getParams()` is `@internal` in DBAL but public and stable; using it is the accepted trade-off (ADR-2) over a feature that silently no-ops under driver middleware. | If DBAL changed/removed `getParams()`, detection breaks; the per-connection `try` + lazy fallback keep that non-fatal. |

## 7. Open Questions
| Question | Why it matters | Status |
|----------|----------------|--------|
| Default on vs. off / config shape. | Public config contract (irreversible). | **Resolved 2026-06-24:** default **on**, single boolean `doctrine.preconnect` (maintainer decision). Gate 3 dissents (recommends off on architecture-fit grounds); kept on per sign-off, flagged as a one-line reversible change in the final report. |

No blocking open questions remain.

## 8. Anti-Patterns (DO NOT)
| Don't | Do Instead | Why |
|-------|-----------|-----|
| Detect PG with `getDatabasePlatform()`/`getServerVersion()`. | Check `getParams()['driver']`. | Platform/version detection **opens a socket** when `serverVersion` is unset (`Connection.php:185-199` + `AbstractPostgreSQLDriver:26`), defeating "detect without connecting" and connecting non-PG too. |
| Detect PG with `getDriver() instanceof AbstractPostgreSQLDriver`. | Check `getParams()['driver']` ∈ {`pdo_pgsql`,`pgsql`}. | doctrine-bridge/doctrine-bundle wrap the DBAL driver in middleware, so `getDriver()` returns an `AbstractDriverMiddleware` (no public unwrap), never the PG driver → the listener silently no-ops. Caught only by the live E2E (§9). |
| Call `$connection->connect()` to preconnect. | `$connection->getNativeConnection()`. | `connect()` is `protected` in DBAL 4 (`Connection.php:215`) — calling it does not compile. |
| Wrap only `getNativeConnection()` in the try, leaving `getConnections()` unguarded. | Guard `getConnections()` in its own `try` **and** each connection in a per-connection `try`. | `getConnections()` can throw while instantiating a misconfigured connection service (ADR-7); an uncaught throw crashes worker boot → RoadRunner crash-loop. |
| Let any preconnect failure propagate out of the listener. | Catch `\Throwable`, log WARNING, continue / return. | The connection retries lazily anyway (ADR-5); crashing boot is strictly worse. |
| Unwrap driver middleware by reflection to reach the PG driver. | Read `getParams()['driver']`. | `AbstractDriverMiddleware::$wrappedDriver` is private with no getter; reflection on it is fragile. The configured driver name needs no unwrap. |
| Preconnect MySQL/MariaDB (or "all" connections) at boot. | PG-only via the driver check. | MySQL connections are reset per request and can die on `wait_timeout`; preconnecting them wastes work and contradicts the bundle's MySQL guidance. |
| Add an `isConnected()` guard before `getNativeConnection()`. | Just call `getNativeConnection()`. | `connect()` already early-returns when `$_conn !== null` (`Connection.php:217-219`); the guard is dead code. |
| Delete the `(string) $name` cast as a "useless cast". | Keep it (with its WHY comment). | PHP coerces int-like array keys to `int`; the cast is runtime-correctness, not analyser-quieting (§6). |

## 9. Test Case Specifications

### Unit tests — `tests/Doctrine/DoctrinePreconnectListenerTest.php`
Connections are PHPUnit mocks of `Doctrine\DBAL\Connection` (not final — `Connection.php:63`)
whose `getParams()` returns a configured `['driver' => …]`; the registry is a mock
`ConnectionRegistry`; the logger a mock `LoggerInterface`. **TC-D9** additionally wraps a real
PG driver in an `AbstractDriverMiddleware` and sets it as `getDriver()`, proving detection does
NOT use `getDriver()` (the live-E2E regression). A connection with no `driver` name asserts the
documented driverClass limitation (stays lazy).

| Test ID | Component | Input | Expected output | Edge cases |
|---------|-----------|-------|-----------------|------------|
| TC-D1 | `__invoke` | registry with 1 PG connection | `getNativeConnection()` called exactly once (origin: AC-1) | — |
| TC-D2 | `__invoke` | registry with 1 MySQL connection | `getNativeConnection()` **never** called (origin: AC-2) | also SQLite driver |
| TC-D3 | `__invoke` | PG + MySQL + SQLite | `getNativeConnection()` called only on the PG one (origin: AC-5) | mixed registry |
| TC-D4 | `__invoke` | PG connection whose `getNativeConnection()` throws | no exception propagates; logger `warning` called once with the connection name (origin: AC-3) | logger `null` → still no throw |
| TC-D5 | `__invoke` | injected registry `null` | returns immediately, nothing called | — |
| TC-D6 | `__invoke` | `getConnections()` returns a non-`Connection` object alongside a PG `Connection` | non-`Connection` skipped, PG still preconnected | — |
| TC-D7 | `__invoke` | two PG connections, the first throws | second still preconnected; one warning logged (origin: AC-3) | failure isolation |
| TC-D8 | `__invoke` | registry whose `getConnections()` throws | no exception propagates; one warning; returns (origin: AC-6) | — |

### Integration / wiring tests — `tests/Doctrine/DoctrinePreconnectWiringTest.php`
Build a `ContainerBuilder` (params `kernel.debug=false`, `kernel.project_dir`), run
`FluffyDiscordRoadRunnerExtension::load()` with explicit config; assert on definitions/tags.
(`doctrine`/`logger` need not exist — references are `NULL_ON_INVALID_REFERENCE`.)

| Test ID | Flow | Setup | Verification | Teardown |
|---------|------|-------|--------------|----------|
| IT-D1 | default-on registration | `load([], $c)` (no `doctrine` key → default true); DBAL installed (dev) | `DoctrinePreconnectListener` definition exists, tagged `kernel.event_listener` `event = WorkerBootingEvent::class`, `method = __invoke` (origin: AC-1/AC-4) | none |
| IT-D2 | explicit opt-out | `load([['doctrine' => ['preconnect' => false]]], $c)` | **no** `DoctrinePreconnectListener` definition (origin: AC-4) | none |
| IT-D3 | references optional | inspect IT-D1's definition arguments | both args are `Reference`s with `NULL_ON_INVALID_REFERENCE` to `doctrine` and `logger` | none |
| IT-D4 | config default | `processConfiguration(Configuration, [])` | `doctrine.preconnect === true` (origin: §4) | none |

### Live E2E — `tests/docker-validate-doctrine-preconnect.sh` + `tests/Doctrine/Live/DoctrinePreconnectLiveTest.php`
The real boot-time socket open is asserted end-to-end (needs a live PG + a real RR worker, so
it is `#[Group('doctrine-preconnect-live')]`, env-gated on `DOCTRINE_PRECONNECT_LIVE`, and
skipped in the normal `phpunit tests` run). The harness scaffolds a Symfony app with
doctrine-bundle + two DBAL connections (PostgreSQL `default`, sqlite `secondary`), provisions a
real PostgreSQL inside the container, runs `rr serve` (1 worker, eager boot), then GETs
`/db-status` — which runs **no query**, only reporting `isConnected()` + `getParams()['driver']`:
- `default` (pgsql) MUST be `connected=true` → preconnected at boot, before the first request;
- `secondary` (sqlite) MUST be `connected=false` → non-PG left untouched.
This harness caught the middleware-detection defect (ADR-2) that the mocked unit tests missed.
Run: `./tests/docker-validate-doctrine-preconnect.sh 8.4`.

## 10. Error Handling Matrix

### External (database) errors
| Error type | Detection | Response | Fallback | Logging | Alert |
|------------|-----------|----------|----------|---------|-------|
| PG connect fails at boot (DB down / refused / timeout / auth) | `\Throwable` from `getNativeConnection()` | catch per connection, continue | connection stays lazy (`$_conn === null`), retries on first real query | `logger?->warning` w/ connection name + exception (once per PG conn per **boot**, ADR-5) | ops may alert on the WARNING |
| `getConnections()` itself throws (misconfigured connection-service factory) | `\Throwable` from `getConnections()` | catch, return early (no preconnect this boot) | all connections stay lazy | `logger?->warning` once | ops may alert |
| `doctrine` service absent (DBAL without doctrine-bundle) | injected registry is `null` | return immediately | nothing to preconnect | none | none |
| A registry entry is not a DBAL `Connection` | `!instanceof Connection` | skip that entry | — | none | none |
| `doctrine/dbal` not installed | `class_exists(Connection::class) === false` at compile time | listener not registered | feature inert | none | none |

### User-facing errors
None. This is a boot-time infrastructure optimization with no request/response surface. Every
failure mode degrades to the pre-existing **lazy-connect** behavior; "degrades to lazy" is the
functional guarantee — note this is not *silent*, boot still logs one WARNING per affected
connection (ADR-5), but no user-facing error path exists.

## 11. References
| Topic | Location | Anchor |
|-------|----------|--------|
| Boot event dispatched by all workers | `src/Worker/HttpWorker.php` | line 90 |
| Same, other workers | `src/Worker/{CentrifugoWorker,JobsWorker,TemporalWorker}.php` | 56 / 41 / 33 |
| Kernel booted before worker starts | `src/Runtime/Runner.php` | line 23 |
| Conditional listener wiring precedent | `src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php` | `registerTemporalTracing` 279–291 |
| Config tree (where `doctrine` node goes) | `src/DependencyInjection/Configuration.php` | 33–190 |
| `getNativeConnection()` forces connect | `vendor/doctrine/dbal/src/Connection.php` | 1223 |
| Native handle is the held object (PDO / PgSQL) | `vendor/doctrine/dbal/src/Driver/PDO/Connection.php` / `PgSQL/Connection.php` | 129 / 128 |
| `getDriver()` (socket-free) | `vendor/doctrine/dbal/src/Connection.php` | 167 |
| `connect()` is protected; failed connect leaves `_conn` null | `vendor/doctrine/dbal/src/Connection.php` | 215 / 221-225 |
| `getDatabasePlatform()` may connect | `vendor/doctrine/dbal/src/Connection.php` | 185–199 |
| PG drivers extend `AbstractPostgreSQLDriver`; native uses `PGSQL_CONNECT_FORCE_NEW` | `vendor/doctrine/dbal/src/Driver/PgSQL/Driver.php` | 27 / 41 |
| PDO PG driver honours `persistent` | `vendor/doctrine/dbal/src/Driver/PDO/PgSQL/Driver.php` | 34 |
| `getConnections()` instantiates each connection service | `vendor/doctrine/persistence/src/AbstractManagerRegistry.php` | 85–92 |
| Driver middleware wraps the driver, private `$wrappedDriver`, no getter (breaks `getDriver()` detection) | `vendor/doctrine/dbal/src/Driver/Middleware/AbstractDriverMiddleware.php` | 16 |
| `getParams()` — configured driver name, `@internal` but stable | `vendor/doctrine/dbal/src/Connection.php` | 137 |
| Live E2E harness + assertion | `tests/docker-validate-doctrine-preconnect.sh` · `tests/Doctrine/Live/DoctrinePreconnectLiveTest.php` | `#[Group('doctrine-preconnect-live')]` |
| `ConnectionRegistry` interface | `vendor/doctrine/persistence/src/ConnectionRegistry.php` | interface |
| doctrine-bundle reset clears EMs only | doctrine/DoctrineBundle `src/Registry.php` (3.2.x) `reset()` | GitHub |
| Bundle reset timing & PG/MySQL guidance | `README.md` "Service reset" / "Database connections" | §Usage |
| Graceful-error-handling philosophy | `docs/specs/graceful-error-handling.md` | — |

## Divergence Log
*(none yet)*
