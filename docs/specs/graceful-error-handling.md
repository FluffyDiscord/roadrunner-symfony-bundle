# Graceful Worker Error Handling (Implementation)

**Source pinned to:** commit `d0afcea` (branch `symfony81`), 2026-05-29.
**Component:** `FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker` error pipeline + new `ErrorHandler\MinimalErrorPage`.
**Scope decision (user, 2026-05-29):** full redesign of the HTTP worker error path; custom page for uncatchable termination, Symfony renderer for catchable exceptions, bare 500 in prod; validate with PHPUnit tests *and* a real RoadRunner run.
**Revision:** rev2 — incorporates Gate 3 adversarial findings (3 CRITICAL / 4 HIGH / 5 MEDIUM / 2 LOW). Material changes: `responseStarted` flag for the streamed path, A-1 reframed as a validation-blocking hypothesis, one-shot shutdown registration, re-entrancy & boot-time rules, NULL-`error_get_last` handling, Sentry-on-fatal, cgroup caveat.

---

## 1. Problem & current behavior (reverse-engineered, cited)

| # | Observation | Evidence |
|---|-------------|----------|
| O1 | Catchable `\Throwable` from `$kernel->handle()` is caught; in debug it renders Symfony's `HtmlErrorRenderer(true)->render($t)->getAsString()`, in prod a bare 500 with no body. | `src/Worker/HttpWorker.php:137-159` |
| O2 | After sending that response, the worker **also** calls `$worker->getWorker()->error((string)$throwable)`, emitting a second goridge frame in the same request cycle. | `src/Worker/HttpWorker.php:162` |
| O3 | `Worker::respond()` and `Worker::error()` both reach `send()`/`sendFrame()` with **no "already responded" guard** — two calls emit two frames. | `vendor/spiral/roadrunner-worker/src/Worker.php:136-147` (respond/error), `:239-273` (send/sendFrame) |
| O4 | The default relay is `pipes`, created as `new StreamRelay(STDIN, STDOUT)` — STDOUT is the protocol channel; STDERR is free and captured by RR as worker logs. | `vendor/spiral/roadrunner-worker/src/Environment.php:44`, `vendor/spiral/goridge/src/Relay.php:29` |
| O5 | `die()` / `exit()` / true fatals never throw, so they bypass `try/catch (\Throwable)`. There is **no `register_shutdown_function`** in the worker. The process dies mid-request; RR sees the relay close with no response frame and returns its own internal error. | `src/Worker/HttpWorker.php:95-202` (no shutdown hook in file) |
| O6 | The renderer's only fallback, if `HtmlErrorRenderer` throws, is a 500 whose body is the raw `(string)$throwable`. | `src/Worker/HttpWorker.php:154-156` |
| O7 | The worker `debug` flag is wired from `param('kernel.debug')`. | `config/services.php:78` |
| O8 | Streamed responses (`StreamedResponse`/`StreamedJsonResponse`) are wrapped and passed to `getHttpWorker()->respond()`; a `\Generator` body triggers `respondStream()`, which emits **one frame per chunk** (N frames — the per-chunk `respond()` is at `:141`). | `src/Worker/HttpWorker.php:120-133`; `vendor/spiral/roadrunner-http/src/HttpWorker.php:105-151` |
| O9 | RoadRunner's PHP worker installs `StdoutHandler` (via `Worker.php:52`), which `ob_start()`s and re-streams output-buffer writes to `php://stderr`. There is **no explicit shutdown teardown** in that class — PHP flushes the output buffer implicitly at script end, so the OB callback can still fire (writing to STDERR) *during* shutdown, concurrently with our STDOUT frame write. | `vendor/spiral/roadrunner-worker/src/Internal/StdoutHandler.php:20,69-74` |

**Conclusions:**
- The *catchable* path already produces a Symfony debug page (O1) — that part exists.
- The genuinely-missing, crude case is the *uncatchable* path (O5): `die`/`exit`/fatal → RR returns "some random error."
- The `respond()`+`error()` double-frame (O2+O3) is a latent protocol defect (Open Question OQ-1).
- Whether a frame can even be delivered from a shutdown context under `pipes` (esp. for fatals, given O9's buffer teardown) is **unproven** — see A-1; it is the central hypothesis the real validation must settle.

---

## 2. Failure taxonomy

| Bucket | Trigger | Reaches `catch`? | Handler |
|--------|---------|------------------|---------|
| **A — Catchable** | `\Exception` / `\Error` from `$kernel->handle()` or response wrapping | Yes | redesigned `catch (\Throwable)` block |
| **B — Uncatchable, *candidate*-recoverable** | `die()`, `exit()`, fatal `E_ERROR` (real OOM, `max_execution_time`, undefined symbol) **during a request, before the FINAL response frame has started**. A `103` early-hint may already have been sent — that is informational and does **not** count as the final response, so the rescue still validly sends a `500` (the `103`+`500` sequence is the same one the catch path uses; see O8/early-hints test). | No | `register_shutdown_function` (new) — **best-effort, gated by real validation (A-1)** |
| **B′ — Uncatchable, mid FINAL response** | same triggers but **after** the *final* response framing started — a streamed `200` already has chunks on the wire (`responseStarted=true`) | No | shutdown handler **must NOT** emit a frame (a `500` after a `200` stream is corruption); process exits, client gets a truncated response. *(A bare `103` early-hint does NOT trigger this — only a streamed final response does.)* |
| **C — Unrecoverable** | `SIGKILL`, segfault, stack overflow, fatal *inside* the shutdown handler, OS/cgroup OOM-kill | No (shutdown functions do not run / cannot complete) | **Out of scope** — RR respawns; client gets RR's error |

---

## 3. Target behavior matrix

| Bucket / mode | Response to client | Relay frames | RR-side log + Sentry | Worker lifecycle |
|---------------|--------------------|--------------|----------------------|------------------|
| A soft `\Exception`, debug | Symfony `HtmlErrorRenderer` page (FlattenException status) | 1 (`respond`) | `(string)$t`→STDERR; Sentry capture (existing) | reboot + reset; keep alive |
| A soft `\Exception`, prod | bare 500, empty body | 1 | STDERR; Sentry | reboot + reset; keep alive |
| A hard `\Error`, debug/prod | Symfony page / bare 500 | 1 | STDERR; Sentry | reboot + reset, then `stop()` + leave loop |
| A renderer itself throws, debug | `MinimalErrorPage` (500, `text/html`) | 1 (`respond`); if `respond` throws → 1 (`error`) | STDERR; Sentry | as above |
| B (no frame started), debug | `MinimalErrorPage` (500, `text/html`), **best-effort** | ≤1 (`respond`; if it throws → `error`) | fatal details (or generic, for bare die/exit)→STDERR; **best-effort Sentry** | process exits; RR respawns |
| B (no frame started), prod | bare 500, best-effort | ≤1 | STDERR; best-effort Sentry | exits; RR respawns |
| B′ mid-stream | (truncated stream) | **0 added** | STDERR; best-effort Sentry | exits; RR respawns |
| boot / dummy early-router request death | (RR's error — no client) | 0 | STDERR if reachable | exits; RR respawns |
| C | (RR's internal error) | 0 | nothing from us | RR respawns |

**Invariant I-1 (corrected):** the worker emits **one terminal frame on the non-streamed path**; **streamed responses emit one frame per chunk by design** (O8). The shutdown rescue emits a frame **only when no frame for the current request has started** (`handlingRequest && !responseStarted`) — it never appends to an in-progress or completed response.

**Invariant I-2:** `error()` is used **only** as a fallback when `respond()` itself throws. RR-side visibility uses **STDERR**, never a second relay frame. *(Replaces O2.)*

**Invariant I-3:** the shutdown function is registered **at most once per worker instance** (a `private bool` instance flag), which in production means once per process — `start()` runs once per process.

**Invariant I-4:** the shutdown handler performs **no operation that can re-fatal** (no container/kernel access, bounded allocation only). A fatal inside the handler is Bucket C.

---

## 4. Design

### 4.1 Loop-local state (three flags), captured by reference

`start()` declares the flags **before** the loop and the shutdown closure captures them **by reference** (verified PHP semantics — see A-2):

```php
$handlingRequest = false;   // a real client request is in flight
$responseStarted = false;   // the FINAL response has begun (incl. first stream chunk); a 103 early-hint does NOT count
$responseSent    = false;   // we finished a normal response

if (!$this->shutdownRegistered) {                 // Invariant I-3 — instance flag, not static
    $this->shutdownRegistered = true;
    register_shutdown_function(function () use ($worker, &$handlingRequest, &$responseStarted): void {
        $this->handleShutdown($worker, $handlingRequest, $responseStarted, error_get_last());
    });
}
```

Registration happens **after** `boot()` / the dummy early-router request (`HttpWorker.php:78-93`) and immediately before the loop, so boot-time death is naturally a no-op (`$handlingRequest === false`). The dummy request runs in `boot()` outside the loop and never sets `$handlingRequest` (boot/dummy row in §3).

Per iteration: top → all three `false`; after non-null `waitRequest()` → `$handlingRequest = true`; **immediately before** `getHttpWorker()->respond(...)` (success path) and before `respond()` (catch path) → `$responseStarted = true`; after a successful normal `respond()` → `$responseSent = true`.

`$this->shutdownRegistered` is a **`private bool` instance** flag (not static): each worker instance registers at most once, so the PHPUnit harness — which builds a fresh `TestableHttpWorker` per test — gets no cross-test contamination and needs no reset seam. A worker's `start()` runs once per process in production, so this also satisfies "at most once per process." The closures tests do register are harmless: at PHPUnit shutdown each fires against its captured flags, which are `false` once `start()` returned, so `handleShutdown` early-returns (Invariant I-2).

### 4.2 `handleShutdown()` — Buckets B / B′ (new; pure, unit-testable)

```
protected function handleShutdown(
    PSR7Worker $worker, bool $handlingRequest, bool $responseStarted, ?array $error
): void
```

1. If `!$handlingRequest || $responseStarted` → `return;` (Invariants I-1, I-2; covers B′ and already-answered/boot cases).
2. If `$error` is OOM (`message` contains `Allowed memory size`) → `@ini_set('memory_limit', '-1')` (best-effort; see A-4 / MEDIUM-cgroup caveat).
3. Build a **single** response frame, bypassing PSR7Worker's chunk routing to guarantee one frame regardless of global `chunkSize`:
   - debug: `$html = MinimalErrorPage::render(500, $error);` then `$worker->getHttpWorker()->respond(500, $html, ['Content-Type' => ['text/html; charset=utf-8']], true);`
   - prod: `$worker->getHttpWorker()->respond(500, '', [], true);`
   - wrap in `try { … } catch (\Throwable) { try { $worker->getWorker()->error($error['message'] ?? 'Worker terminated during request'); } catch (\Throwable) {} }` (Invariant I-2).
4. `$this->logError($error !== null ? sprintf('fatal: %s in %s:%d', $error['message'], $error['file'], $error['line']) : 'worker terminated via die/exit during request');` (STDERR).
5. Best-effort Sentry: `try { $this->sentryHubInterface?->captureMessage(...); $this->sentryHubInterface?->getClient()?->flush(); } catch (\Throwable) {}` (may not fire under OOM — documented, not guaranteed).

**NULL-error note (MEDIUM):** bare `die()`/`exit()` (and `die("text")`) leave `error_get_last() === null`. Bucket B is therefore distinguished **solely by the flags**, never by the presence of an `$error` array. With `$error === null`, render the generic page and log the generic message (step 4).

### 4.3 `sendThrowableResponse()` — Bucket A (extracted from the current catch)

```
protected function sendThrowableResponse(PSR7Worker $worker, \Throwable $throwable): void
```

Called from `catch` **only when `!$responseStarted`**. Bucket A uses `PSR7Worker::respond(new Psr7\Response(...))` — since `$chunkSize === 0` by default (`PSR7Worker.php:31`) this is one frame, and it cleanly accepts `FlattenException::getHeaders()`' string-valued headers (mirrors the proven path at `HttpWorker.php:148-153`). *(The raw `getHttpWorker()->respond()` chunkSize-bypass is reserved for the Bucket B rescue §4.2, where avoiding the Response/generator machinery in a shutdown context is the goal.)*
- debug: `try { $fe = (new HtmlErrorRenderer(true))->render($throwable); $worker->respond(new Psr7\Response($fe->getStatusCode(), $fe->getHeaders(), $fe->getAsString())); } catch (\Throwable) { $worker->respond(new Psr7\Response(500, ['Content-Type' => 'text/html; charset=utf-8'], MinimalErrorPage::render(500, null, (string)$throwable))); }` (upgrades O6's raw-string fallback to the minimal page).
- prod: `$worker->respond(new Psr7\Response(500));`
- On the outer `respond()` throwing → fall back to `$worker->getWorker()->error((string)$throwable)` (Invariant I-2).

The catch block then: `$this->logError((string)$throwable)` (STDERR, replacing O2's `error()` frame), Sentry capture stays (`HttpWorker.php:141`), and the existing `\Error → stop()` rule is preserved.

### 4.4 `logError()` — STDERR sink (new, overridable test seam)

```
protected function logError(string $message): void   // @fwrite(STDERR, '[roadrunner-symfony] ' . $message . "\n");
```

`TestableHttpWorker` overrides it to capture messages. Three `protected` seams exist purely for isolation in tests — `registerShutdown(callable)` (intercept registration instead of polluting the PHPUnit process), `logError(string)` (capture instead of STDERR), and `renderHtmlError(\Throwable): FlattenException` (simulate the Symfony renderer failing → exercises the MinimalErrorPage fallback for TC-08). **STDERR-interleaving note (MEDIUM):** STDERR is shared with `StdoutHandler`'s re-streamed app output (O9); under fatals, lines may interleave — hence the fixed `[roadrunner-symfony]` prefix. Non-corrupting (STDERR ≠ relay), but interleaving is possible.

### 4.5 `ErrorHandler\MinimalErrorPage` — self-contained renderer (new component)

```
final class MinimalErrorPage {
    public const int MESSAGE_MAX = 2048;   // bound for OOM/re-entrancy safety (HIGH/LOW)
    public static function render(int $statusCode, ?array $error, ?string $detail = null): string
}
```

- **Zero dependencies**: no container, no Symfony services, no autoload beyond this class (Invariant I-4 — must render with a broken kernel and must not re-fatal).
- Allocates a **bounded** string: inline CSS + status + fixed title + (when present) `message`/`file`/`line` from `$error`, or `$detail`, each **truncated to `MESSAGE_MAX`** and HTML-escaped via `htmlspecialchars`. No loops over unbounded input.
- Used **only** for debug bodies; prod sends an empty 500 and never calls it.

---

## 5. Relay constraint & limitations (the honesty section)

From O4 (`pipes` = `StreamRelay(STDIN, STDOUT)`) and O9 (`StdoutHandler` ob→STDERR teardown on shutdown):

| Bucket B sub-case | `pipes` relay (default) | socket relay (`tcp://`/`unix://`) |
|-------------------|-------------------------|------------------------------------|
| bare `die()` / `exit()`, no prior output | **✅ PROVEN** (IT-REAL-2/3): client gets the `MinimalErrorPage` | ✅ |
| `die("text")` / `echo` / dump then die | **✅ PROVEN** (IT-REAL-3): clean page — `StdoutHandler` (O9) `ob_start`-captures the dumped text to STDERR, so the raw-STDOUT relay stays clean. *(Better than originally predicted.)* | ✅ |
| true fatal (OOM/timeout), no prior output | **❌ DISPROVEN** (IT-REAL-6): NOT cleanly rescued. Symfony's own `ErrorHandler` (reserved-memory fatal handler) renders + writes its exception page during the OOM fatal; that lands on the goridge STDOUT relay → **stdout-crc** validation failure → RR returns its error page. Our handler fires (logs to STDERR) but cannot guarantee STDOUT once Symfony's fatal handler has written. Best-effort; documented limitation. | ✅ (subject to A-4) |
| after a `103` early-hint, final response not yet started | **✅** — rescue sends `103`+`500` (same sequence the catch path uses; IT verified in `HttpWorkerEarlyHintsTest`) | ✅ |
| B′ mid FINAL stream (`200` chunks already sent) | ❌ by design — no added frame (would corrupt the `200` stream) | ❌ by design |
| C (segfault/SIGKILL/handler re-fatal/cgroup-kill) | ❌ shutdown never completes | ❌ |

**Documented recommendation** (README addition): for the richest dev experience use a socket relay (`RR_RELAY`), or accept the pipe-mode coverage above. `pool.debug: true` (one request per worker — the dev default in `install/.rr.yaml`) additionally removes any cross-request desync risk.

---

## Validation results (real RoadRunner)

Run 2026-05-29 against **RoadRunner v2025.1.14** (linux/amd64) + a minimal Symfony app (`FrameworkBundle` + this bundle), `pipes` relay, harness in `/tmp/rr-validation`.

| Gate | Scenario | Result |
|------|----------|--------|
| IT-REAL-1 | `/boom` (catchable), debug | ✅ HTTP 500, full Symfony exception page (rendered by Symfony's ErrorListener; worker forwards it) |
| IT-REAL-2 | `/exit` (bare `exit`), debug | ✅ HTTP 500, **`MinimalErrorPage`** (732 B) — shutdown rescue delivered a page; **A-1 PROVEN** |
| IT-REAL-3 | `/die('…output…')`, debug | ✅ HTTP 500, `MinimalErrorPage` — `StdoutHandler` shields the relay from the dumped output |
| IT-REAL-4 / OQ-1 | persistent worker (`pool.debug:false`): `/boom` then `/ok` | ✅ **same worker pid** across the error → no desync; **OQ-1 resolved** |
| — | recovery after worker death (`/exit` then `/ok`) | ✅ `/ok` → 200 on a **fresh** pid (RR respawned) |
| IT-REAL-5 | `/exit` + `/boom`, **prod** (`APP_DEBUG=0`) | ✅ `/exit` → HTTP 500, **0-byte body** (no info disclosure); `/boom` → Symfony generic prod page |
| IT-REAL-6 | `/oom` (true OOM), debug | ❌ **DISPROVEN** — not cleanly rescued: Symfony's fatal handler writes its page to the STDOUT relay → goridge **stdout-crc** error → RR's error page. Recovery still works. Documented limitation; `die`/`exit` (the primary ask) are unaffected. |
| — | STDERR logging | ✅ `[roadrunner-symfony] worker terminated via die/exit during request` / `fatal: …` captured by RR as worker logs |

**Net:** the user's core ask — a nicer error page on `die`/`exit` (and a forwarded Symfony page on catchable failures) — works in a real RoadRunner deployment in both dev and prod. The one case that is *not* cleanly handled is a true OOM, for a Symfony-side reason (its ErrorHandler corrupts the relay during the fatal); this was pre-registered as best-effort.

---

## Assumptions

| # | Assumption | Status / If wrong, then… |
|---|------------|--------------------------|
| A-1 | A `register_shutdown_function` callback can deliver **one** goridge frame that RoadRunner accepts, under `pipes`, for bare `die`/`exit` and for true fatals. | **RESOLVED (real validation, 2026-05-29).** ✅ TRUE for `die`/`exit` (incl. `die("text")`, thanks to `StdoutHandler`). ❌ FALSE for true OOM — Symfony's fatal handler corrupts the STDOUT relay first (stdout-crc); best-effort only, documented. See *Validation results*. |
| A-2 | `use (&$x)` makes a loop-local's latest value visible to a closure registered before the loop. | **CONFIRMED** (Gate 3 verified empirically). |
| A-3 | `error()` after `respond()` desyncs a persistent worker ("random error"). | **OQ-1 resolved (real validation):** a persistent worker (`pool.debug:false`) serves `/boom` then `/ok` on the **same pid** with no desync under the one-frame design. (In a full-stack app Symfony handles the exception before the worker's catch, so the old double-frame rarely fired — but the one-frame design is confirmed healthy regardless.) |
| A-4 | `ini_set('memory_limit','-1')` in the handler frees headroom for the ~≤2KB page. | Best-effort. **In containers the cgroup/OS limit still applies — `-1` lifts only PHP's internal cap, not the OS ceiling**, so OOM rescue may be a no-op → Bucket C. |
| A-5 | `kernel.debug` (O7) is the correct "show verbose page" signal. | Verbose-in-prod would be a separate knob — out of scope. |

## Open Questions

| # | Question | Why it matters | Blocks | Status |
|---|----------|----------------|--------|--------|
| OQ-1 | Does `respond()`+`error()` actually desync a persistent (`pool.debug:false`) worker? | Bug-fix vs neutral cleanup. | Nothing (one frame regardless). | **RESOLVED** — real validation: persistent worker stays on the same pid across `/boom`, no desync (see *Validation results*, A-3). |
| OQ-2 | Should the Centrifugo worker get equivalent shutdown handling? | `die`/`exit` in an RPC handler dies silently too, but RPC has no HTML page. | Nothing in HTTP scope. | **Deferred** — out of scope; logged. |
| OQ-3 | `MinimalErrorPage` under `src/ErrorHandler/` vs `src/Worker/`? | Cosmetic. | Nothing. | Default `src/ErrorHandler/`. Reversible. |

*No user-blocking unknown remains (the three forks were resolved 2026-05-29). A-1 is blocking for the **claim** that Bucket B works, and is resolved by the real-validation gates the user asked for — not by guessing.*

---

## N-3. Anti-Patterns (DO NOT)

| Don't | Do Instead | Why |
|-------|-----------|-----|
| Send a response frame **and** an `error()` frame in one cycle | One `respond()`; `error()` only if `respond()` throws | Two frames desync goridge on a persistent worker (O2+O3) |
| Emit a rescue frame after the FINAL response stream began | Guard on `!responseStarted`; emit nothing once the final response started | Appending a `500` to an in-progress `200` stream corrupts it (B′, O8). *(A `103` early-hint is informational and does NOT set `responseStarted` — the rescue still validly sends the final `500`.)* |
| `echo`/`print`/`die("text")`/dump to STDOUT in the worker | Write diagnostics to **STDERR** | In `pipes` mode STDOUT *is* the protocol channel (O4) |
| Build the Bucket-B page via the container/kernel | Use dependency-free `MinimalErrorPage` | After die/exit/fatal the kernel may be half-destroyed (Invariant I-4) |
| Do anything that can re-fatal inside the shutdown handler | Bounded allocation, `htmlspecialchars`/`sprintf` only, length-capped input | A fatal inside a shutdown fn is terminal — no re-run (Invariant I-4) |
| Register the shutdown function more than once | Guard with `self::$shutdownRegistered` (Invariant I-3) | `register_shutdown_function` is append-only; stacked closures multiply frames |
| Run the rescue unconditionally on every shutdown | Guard on `handlingRequest && !responseStarted` | Else normal exit / answered / boot emits a spurious 500 |
| Route the rescue through `PSR7Worker::respond()` (chunkSize) | Call `getHttpWorker()->respond(…, endOfStream: true)` directly | Guarantees a single frame regardless of global `chunkSize` |
| Allocate large buffers in the OOM path | Tiny page; `memory_limit=-1` first (process exiting) | Little headroom at the OOM ceiling (A-4) |
| Leak exception internals to the client in prod | Verbose body only when `kernel.debug` | Information disclosure; matches O1 |

## N-2. Test Case Specifications

### Unit tests (PHPUnit; harness `tests/Worker/AbstractHttpWorkerTestCase.php`)

| Test ID | Component | Input | Expected | Edge |
|---------|-----------|-------|----------|------|
| TC-01 | `handleShutdown` | `handlingRequest=true, responseStarted=false, debug=true, error={message,file,line}` | `getHttpWorker()->respond` once, status 500, `text/html`, body contains escaped message; **`error()` NOT called** | message with HTML chars escaped |
| TC-02 | `handleShutdown` | bare die/exit: `error=null`, debug=true | `respond` once, 500, generic page, no notices; `logError` got the generic message | — |
| TC-03 | `handleShutdown` | debug=false, error set | `respond` once, empty body, 500 | — |
| TC-04 | `handleShutdown` | `responseStarted=true` (mid-stream / answered) | **no** `respond`, **no** `error` | also `handlingRequest=false` → same no-op |
| TC-05 | `handleShutdown` | `respond()` throws (relay corrupt), debug=true | falls back to `error()` once; nothing escapes | `error()` also throws → still nothing escapes |
| TC-06 | `handleShutdown` | OOM error array | `memory_limit` set to `-1` before render; `respond` once | — |
| TC-07 | `sendThrowableResponse` | `\RuntimeException`, debug=true | `respond` once with Symfony page (class+message); `error()` not called | — |
| TC-08 | `sendThrowableResponse` | debug=true, `HtmlErrorRenderer` forced to throw | `respond` once with `MinimalErrorPage` (500, `text/html`) | — |
| TC-09 | `sendThrowableResponse` | debug=false | `respond` once, empty body, 500 | — |
| TC-10 | catch path | `\RuntimeException` from `handle()` | `logError` got `(string)$t`; **`error()` not called** on the respond-succeeds path | — |
| TC-11 | `MinimalErrorPage::render` | `(500, {message:"<b>x</b>",file,line})` | valid HTML; contains `&lt;b&gt;x&lt;/b&gt;` and `500` | `error=null` → generic page; message > `MESSAGE_MAX` → truncated |
| TC-12 | shutdown registration | call `start()` twice on the **same instance** (loop returns immediately) | the instance registers exactly once (`$this->shutdownRegistered` guard); a fresh instance registers again | spy via a `protected registerShutdown()` seam overridden in `TestableHttpWorker` to count calls |

### Integration tests

| Test ID | Flow | Setup | Verification | Type |
|---------|------|-------|--------------|------|
| IT-01 | **Modified** existing exception tests | `HttpWorkerExceptionTest` rewritten to the one-frame contract | prod: `respond` 500 empty, **`error()` never**, `logError` got `(string)$t`; debug: `respond` body has class+message, `error()` never; `\Error`→`stop()`; `\Exception`↛`stop()` | mock |
| IT-02 | Single-frame on catch | kernel throws, debug | `respond` exactly once, `error` zero | mock |
| IT-03 | die-mid-stream is a no-op | streamed response whose generator "dies" (simulate via `responseStarted=true`) | shutdown handler adds **0** frames | mock |
| IT-REAL-1 | Real RR — catchable | Docker: minimal Symfony app + `rr serve`, `GET /boom`, debug | HTTP 500 body = Symfony exception page (class+message) | real |
| IT-REAL-2 | Real RR — `exit()` **(A-1 gate)** | `GET /exit` (bare `exit;`), debug, pipes | client gets `MinimalErrorPage` markers, **not** RR's raw error | real, **blocking** |
| IT-REAL-3 | Real RR — `die()` **(A-1 gate)** | `GET /die` (bare `die;`), debug, pipes | client gets minimal page | real, **blocking** |
| IT-REAL-4 | Real RR — persistence after error (OQ-1) | `pool.debug:false`; `GET /boom` then `GET /ok` same worker | second request returns 200 `ok` (no desync) | real |
| IT-REAL-5 | Real RR — prod | `APP_ENV=prod`, `GET /boom` and `/exit` | HTTP 500, **empty/secret-free** body | real |
| IT-REAL-6 | Real RR — true OOM under pipes **(A-1 gate)** | `GET /oom` (allocate past `memory_limit`), debug | record actual client output; **if not the page → mark "true fatal/pipes" ❌ in §5** | real, **blocking, outcome-recorded** |

*Floors: ≥5 unit (12) and ≥3 integration (9) — met. IT-REAL-2/3/6 are A-1 acceptance gates; IT-REAL-4 doubles as OQ-1.*

## N-1. Error Handling Matrix

### Internal failures
| Error type | Detection | Response | Fallback | Logging | Worker action |
|------------|-----------|----------|----------|---------|---------------|
| Catchable `\Exception` | `catch`, not `\Error` | debug page / prod 500 (§4.3) | minimal page if renderer throws | `(string)$t`→STDERR; Sentry | reboot+reset; keep alive |
| Catchable `\Error` | `catch`, `instanceof \Error` | debug page / prod 500 | minimal page | STDERR; Sentry | reboot+reset; `stop()`; leave loop |
| `respond()` throws sending an error | inner `try` | — | `getWorker()->error(...)` (1 frame) | STDERR | continue cleanup |
| die/exit/fatal, no frame started | shutdown fn + `handlingRequest && !responseStarted` | debug minimal page / prod 500 (best-effort, A-1) | `error()` if `respond` throws | details or generic→STDERR; best-effort Sentry | exits; RR respawns |
| die/exit/fatal **mid-stream** (B′) | shutdown fn + `responseStarted` | none (no added frame) | — | STDERR; best-effort Sentry | exits; RR respawns |
| OOM during render in handler | `message` ~ `Allowed memory size` | `memory_limit=-1`, then minimal page | RR's error (give up) | STDERR | exits |
| fatal during boot / dummy request | shutdown fn + `handlingRequest===false` | none (no client) | — | STDERR if reachable | exits; RR respawns |
| Cleanup (`terminate`/`reset`) throws | existing `finally` nested try/catch (`HttpWorker.php:179-189`) | — | — | STDERR (was `error()`) | `stop()` |
| `waitRequest()` throws | existing `catch` (`HttpWorker.php:107-110`) | 418 teapot, `continue` | — | — | keep alive (unchanged) |

### User-facing
| Error type | debug | prod | Code |
|------------|-------|------|------|
| Any worker failure (frame can be sent) | Symfony page (A) or minimal page (B) with class/message/file/line | empty body | 500 (or FlattenException status for A) |
| die/exit/fatal where frame cannot be delivered (A-1 fails / B′ / C) | RR's internal error | RR's internal error | RR-defined |

## N. References

| Topic | Location | Anchor |
|-------|----------|--------|
| Current worker loop & catch | [`src/Worker/HttpWorker.php`](../../src/Worker/HttpWorker.php) | `start() :95-202` |
| RR frame send (no guard) | [`vendor/spiral/roadrunner-worker/src/Worker.php`](../../vendor/spiral/roadrunner-worker/src/Worker.php) | `respond/error :136-147`, `send/sendFrame :239-273` |
| Relay = STDIN/STDOUT | [`vendor/spiral/goridge/src/Relay.php`](../../vendor/spiral/goridge/src/Relay.php) | `:29` |
| Default relay = pipes | [`vendor/spiral/roadrunner-worker/src/Environment.php`](../../vendor/spiral/roadrunner-worker/src/Environment.php) | `:44` |
| Streamed N-frame respond | [`vendor/spiral/roadrunner-http/src/HttpWorker.php`](../../vendor/spiral/roadrunner-http/src/HttpWorker.php) | `respondStream :105-151` (per-chunk `:141`) |
| StdoutHandler ob→STDERR | [`vendor/spiral/roadrunner-worker/src/Internal/StdoutHandler.php`](../../vendor/spiral/roadrunner-worker/src/Internal/StdoutHandler.php) | `:20,69-74`; installed via `Worker.php:52` |
| Test harness | [`tests/Worker/AbstractHttpWorkerTestCase.php`](../../tests/Worker/AbstractHttpWorkerTestCase.php) | `makeWorker()` |
| Existing exception tests (to modify) | [`tests/Worker/HttpWorkerExceptionTest.php`](../../tests/Worker/HttpWorkerExceptionTest.php) | `:30` (`error()` once) |
| DI wiring (debug flag) | [`config/services.php`](../../config/services.php) | `:71-83`, `:78` |
| README debugging note | [`README.md`](../../README.md) | "Debugging (recommendations)" `:465` |

---

## Centrifugo worker (delta)

**Scope decision (user, 2026-05-29):** apply the same hardening to `Worker/CentrifugoWorker.php` — Tier 1 (one-frame + STDERR/Sentry) **and** a shutdown handler, plus an `error()`/`disconnect()` mapping per request type.

**Why it's different.** Centrifugo is an RPC/proxy worker — there is **no HTML page anyone sees**. A request is answered with one Centrifugo payload (`respond()` / `error()` / `disconnect()`), and failures surface as a dropped websocket / a failed RPC that nobody watches. So the priority **inverts**: observability (STDERR + Sentry) is the payoff; a "page" is meaningless. `MinimalErrorPage` is NOT used here.

**Same two issues as HTTP (verified):**
- Double-frame: `$request->disconnect(...)` (one `worker->respond()` frame, `AbstractRequest:82-87`) **then** `$this->worker->getWorker()->error(...)` (a 2nd goridge ERROR frame) — `CentrifugoWorker.php:105+108`, ditto cleanup `:120/:126`.
- No `register_shutdown_function`; `die`/`exit`/fatal kills the worker silently.
- Bonus bug: debug sent `(string)$throwable` (full trace) as the client-facing disconnect reason (`:104`) — trace disclosure over the wire.

**Design (mirrors HTTP, adapted):**
- **One frame:** replace every `getWorker()->error(...)` with `logError()` (STDERR); the single response frame is `error()` or `disconnect()`. `getWorker()->error()` survives only as the can't-respond fallback.
- **No trace to clients:** `clientMessage()` → debug = `class: message` (one line, capped, **no trace**); prod = `"Unexpected system error"`. Full detail → STDERR/Sentry.
- **error()/disconnect() mapping** (`chooseFailureAction()`):

  | Request type | Action | Rationale |
  |---|---|---|
  | Connect, Subscribe | `disconnect()` | connection/subscription can't be established → drop |
  | RPC, Publish, Refresh, SubRefresh | `error()` | in-band op failed; keep the connection, return an error |
  | Invalid | none | malformed request, has no worker to respond through |

- **Shutdown handler** `handleShutdown(handlingRequest, responded, request, error)`: guard `handlingRequest && !responded && request !== null`; OOM `memory_limit=-1`; **log to STDERR + Sentry (the point)**; best-effort `respondToFailedRequest($request, 'Unexpected system error')`.
- **`readonly class` → plain class** with `private readonly` promoted props + one mutable `private bool $shutdownRegistered`, so the once-guard and a non-readonly test subclass are possible (mirrors `HttpWorker`).

**Testing reality (honest).** `RoadRunner\Centrifugo\CentrifugoWorker` and most `Request\*` classes are **`final`** (and `respond/error/disconnect` are `final`), so they can't be mocked. Tests therefore: construct **real** `Request\*` fixtures (their ctors take a mockable goridge `WorkerInterface`), drive the loop through a `waitRequest()` seam, assert `getWorker()->error()`/`stop()` on the injected goridge mock, and unit-test `chooseFailureAction()` / `clientMessage()` directly. A **live** Centrifugo validation (real Centrifugo server + websocket clients) is out of scope — the surface is invisible by nature; the worker instead inherits the HTTP design's already-proven shutdown mechanics, with the Centrifugo-specific logic unit-tested in isolation.

### Centrifugo references
| Topic | Location | Anchor |
|-------|----------|--------|
| Worker (to redesign) | [`src/Worker/CentrifugoWorker.php`](../../src/Worker/CentrifugoWorker.php) | `start()` |
| Frame send (respond/error/disconnect) | [`vendor/roadrunner-php/centrifugo/src/Request/AbstractRequest.php`](../../vendor/roadrunner-php/centrifugo/src/Request/AbstractRequest.php) | `:53-87` (all `final`) |
| DI wiring | [`config/services.php`](../../config/services.php) | `:118-130` |
