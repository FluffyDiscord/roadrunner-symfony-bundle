# Graceful Worker Error Handling (Implementation)

**Source pinned to:** commit `d0afcea` (branch `symfony81`), 2026-05-29.
**Component:** `FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker` error pipeline + new `ErrorHandler\MinimalErrorPage`.
**Scope decision (user, 2026-05-29):** full redesign of the HTTP worker error path; custom page for uncatchable termination, Symfony renderer for catchable exceptions, bare 500 in prod; validate with PHPUnit tests *and* a real RoadRunner run.
**Revision:** rev4 — fatal-only filtering of `error_get_last()` (`ErrorHandler\FatalError`) so a stale deprecation is never reported as the cause of a `die`/`exit`; explicit die/exit wording on the rescue page (§4.2 stale-error note).
**Revision:** rev3 — adds **Bucket D (boot-time failure)**, §6; supersedes the rev2 rows that recorded boot-time death as "no client, 0 frames".
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
| boot-time death — **superseded by rev3, see [§6 Bucket D](#6-bucket-d--boot-time-failure-rev3)** | (was: RR's error — no client) | (was: 0) | STDERR if reachable | exits; RR respawns |
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

Registration happens **after** `boot()` and immediately before the loop, so the shutdown rescue is a no-op for boot-time death (`$handlingRequest === false`); boot-time death is instead handled by [§6 Bucket D](#6-bucket-d--boot-time-failure-rev3), which never uses the shutdown path. (superseded: this used to name the dummy early-router request, removed in favor of boot warmers — worker-warmup.md ADR-10; warmup runs on `WorkerBootingEvent` outside the loop and never sets `$handlingRequest`.)

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

**Stale-error note (rev4):** `error_get_last()` returns the last error of **any** severity, so after a `die()` it usually hands back an unrelated `E_USER_DEPRECATED`/`E_WARNING` raised earlier in the request — in a Sylius app, typically a `DebugClassLoader` deprecation — and the rescue page then blames `DebugClassLoader.php:363` for a `die` in a controller. The workers therefore read the last error through `ErrorHandler\FatalError::getLastFatalError()`, which keeps only the genuinely fatal types (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`) and returns `null` otherwise. `E_RECOVERABLE_ERROR` is deliberately excluded: Symfony's `ErrorHandler` turns it into a catchable `\ErrorException`, so it too can linger as a stale last error. A `null` result renders the die/exit page, which states in plain words that PHP records **no file or line for `die()`/`exit()`** — the location genuinely cannot be recovered (a shutdown function runs on an unwound stack, so `debug_backtrace()` is empty). Live-guarded by the `/deprecated-die` route in `tests/docker-validate-error-pages.sh`.

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

`TestableHttpWorker` overrides it to capture messages. Three `protected` seams exist purely for isolation in tests (rev3: `renderHtmlError` superseded by §6.6's `getThrowableResponder()`) — `registerShutdown(callable)` (intercept registration instead of polluting the PHPUnit process), `logError(string)` (capture instead of STDERR), and `renderHtmlError(\Throwable): FlattenException` (simulate the Symfony renderer failing → exercises the MinimalErrorPage fallback for TC-08). **STDERR-interleaving note (MEDIUM):** STDERR is shared with `StdoutHandler`'s re-streamed app output (O9); under fatals, lines may interleave — hence the fixed `[roadrunner-symfony]` prefix. Non-corrupting (STDERR ≠ relay), but interleaving is possible.

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
| **catchable** throwable during boot / boot listeners | try/catch in `Runner::run()` / `HttpWorker::start()` ([§6](#6-bucket-d--boot-time-failure-rev3)) | debug: page · prod: bare 500 (kernel boot) or keep serving (listener) | `MinimalErrorPage` if the renderer throws | `[roadrunner-symfony] worker boot failed…`→STDERR; Sentry when the container survived | HTTP: answers one request, then exits; RR respawns |
| **uncatchable** fatal / `die` / `exit` / `dd()` during boot | none — shutdown fn is a no-op (`handlingRequest===false`) | none (no client) | — | STDERR if reachable | exits; RR respawns ([§6.7 limits](#67-known-limits)) |
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


---

# 6. Bucket D — boot-time failure (rev3)

**Source pinned to:** commit `c4be852` (branch `master`), 2026-08-18.
**Scope decision (user, 2026-08-18):** a boot failure must show the developer what died — the bundle's rendered error page in debug — instead of RoadRunner's raw pipe error. Prod keeps serving whatever still works and screams into STDERR/Sentry.
**Type:** Implementation.
**Supersedes in rev2:** the §3 matrix boot-time row, the §4.1 registration note, the §4.4 seam list (`renderHtmlError` → §6.6), and the §N-1 boot rows.

## 6.1 Problem & current behavior (reverse-engineered, cited)

| # | Observation | Evidence |
|---|-------------|----------|
| D-O1 | `Runner::run()` boots the kernel **before** the container lookup that builds the worker, so every container/compile/env boot failure dies there — no worker, no relay, no frame. | `src/Runtime/Runner.php:23-28` |
| D-O2 | `Kernel::boot()` early-returns once `$this->booted` is true, so `HttpWorker::start()`'s own `$this->kernel->boot()` is a second call that normally does nothing (Runner already booted). It is **not inert**: the early-return branch can run `services_resetter->reset()` and can throw. A first-failure therefore always surfaces in `Runner`, never at `HttpWorker.php:86`. | `vendor/symfony/http-kernel/Kernel.php:103-117`; `src/Worker/HttpWorker.php:85-91`; `docs/specs/worker-warmup.md` ADR-6 |
| D-O3 | The `WorkerBootingEvent` dispatch — where the warmers and `DoctrinePreconnectListener` run — is unguarded in all four workers. A throwing listener propagates out of `start()`, out of `Runner::run()`, and kills the process. Each worker's eager `kernel->boot()` call is likewise unguarded. | `src/Worker/HttpWorker.php:85-93`, `src/Worker/JobsWorker.php:37-41`, `src/Worker/CentrifugoWorker.php:56-59`, `src/Worker/TemporalWorker.php:31-33` |
| D-O4 | Measured client-visible result (real RR **2025.1.15**, Docker, `pipes` relay, `pool.debug: true`, 2026-08-18): HTTP **500** with a literal 4-byte body `EOF`, for both a throwing listener and a `ClassNotFoundError`, in debug **and** prod. RR logs `{"logger":"http","msg":"execute","error":"EOF"}` with `write_bytes: 4`. Reproduced with a harness derived from `tests/docker-validate-error-pages.sh`; that harness had no boot-failure mode and gains one in §6.9. | reproduction run 2026-08-18 |
| D-O5 | In the same run the full Symfony trace reached **STDERR** — RR captured it under `{"logger":"server", …}` alongside the warmup lines — because the runtime installs `SymfonyErrorHandler` for uncaught throwables. Catching the throwable **removes** that line, so the catcher must re-emit it. | `vendor/symfony/runtime/SymfonyRuntime.php:157`; D-O4 run log |
| D-O6 | The bundle's own boot listeners already swallow their own failures — "logged and swallowed; the worker always boots… all degrade to 'less warm', never to 'no worker'". Only a **third-party** listener can currently kill a worker. | `docs/specs/worker-warmup.md` ADR-5; `src/Warmup/WorkerWarmupRunner.php:32-64`; `src/Doctrine/DoctrinePreconnectListener.php:26-51` |
| D-O7 | The debug/prod render policy already exists in one place: Symfony page in debug → `MinimalErrorPage` if the renderer throws → bare 500 in prod → `getWorker()->error()` only if `respond()` itself throws. `PSR7Worker::$chunkSize` defaults to `0`, so that path is one frame regardless of page size. | `src/Worker/HttpWorker.php:262-289`; `vendor/spiral/roadrunner-http/src/PSR7Worker.php:31` |
| D-O8 | Several `Spiral\RoadRunner\Worker` instances **already** exist per process today whenever the optional packages are installed: `RoadRunnerWorkerInterface` is `->share(false)` and injected twice by `centrifugo.php`, and `jobs.php` declares its own; all are built eagerly because the `WorkerRegistry` definition carries `->call("registerWorker", …)` for each mode and nothing is `->lazy()`. `StdoutHandler::register()` has no idempotency guard, so their `ob_start()` handlers nest. | `config/services.php:33-34`; `config/centrifugo.php:32-45`; `config/jobs.php:36-44`; `config/jobs.php:67-70`, `config/centrifugo.php:71-74`; `vendor/spiral/roadrunner-worker/src/Internal/StdoutHandler.php:37-45` |
| D-O9 | Those nested buffers cannot corrupt the protocol: `StreamRelay::send()` writes frames with `@fwrite($this->out, …)` — a direct stream write that PHP output buffering never sees. `ob_start()` intercepts `echo`/`print` only. So extra `Worker` **objects** are inert; what must stay unique is the number of Workers that actually *use* the relay. | `vendor/spiral/goridge/src/StreamRelay.php:108-113`; `vendor/spiral/roadrunner-worker/src/Internal/StdoutHandler.php:69-74` |
| D-O10 | `Worker::stop()` sends a normal payload frame, not a control frame — so a boot-failure `respond()` followed by `stop()` would put two payload frames in one request cycle. (The existing `\Error` path at `HttpWorker.php:165-172` already does respond+stop by design, §3 line 50; that is out of scope for rev3.) | `vendor/spiral/roadrunner-worker/src/Worker.php:149-152` |

## 6.2 Failure taxonomy (extends §2)

| Bucket | Trigger | Kernel serviceable after? | Handler |
|--------|---------|---------------------------|---------|
| **D1 — kernel boot failure** | catchable throwable from `$kernel->boot()`, the `WorkerRegistry` lookup, or the mode's worker construction, all inside `Runner::run()` | **No** — nothing can serve | §6.4 Runner boundary |
| **D2 — boot listener failure** | catchable throwable from a worker's eager `kernel->boot()` or its `WorkerBootingEvent` dispatch | **Yes** — the kernel booted; only warmup/preconnect work was lost | §6.5 worker boundary |
| **D3 — uncatchable boot death** | `die()` / `exit()` / `dd()` / true fatal (`E_ERROR`, OOM) during boot or a boot listener | n/a — process is gone | **Out of scope**, §6.7 |

## 6.3 Target behavior matrix

| Bucket / mode | Debug (`kernel.debug=true`) | Prod | Relay frames | Log | Worker lifecycle |
|---------------|------------------------------|------|--------------|-----|------------------|
| D1, HTTP mode | wait for one request → Symfony `HtmlErrorRenderer` page (500) | wait for one request → bare 500, empty body | **at most 1** (`respond`); 0 if RR stops the worker before delivering a request; `error()` only if `respond()` throws | `[roadrunner-symfony] BOOT FAILURE (mode=http): <throwable>`→STDERR; Sentry per §6.4 step 6 | responds once, then **returns** — process exit closes the relay, RR respawns and retries the boot |
| D1, non-HTTP modes (jobs/centrifuge/temporal) | — (no client to render for) | — | 0 | same line with the real mode →STDERR | `return 1` |
| D2, HTTP | wait for one request → Symfony page (500) | **keep serving**: enter the normal request loop degraded | debug: at most 1; prod: 0 (the loop's own frames follow) | `[roadrunner-symfony] BOOT FAILURE: <throwable>`→STDERR + Sentry (the container survived, so the hub is available) | debug: responds once, then returns → RR respawns · prod: normal loop |
| D2, non-HTTP | **keep consuming** (no page exists to render) | keep consuming | 0 | STDERR + Sentry | normal loop |
| D3 | RR's own error (`EOF` body) | same | 0 | STDERR if the fatal handler reached it | exits; RR respawns |

**Invariant D-1:** the boot path emits **at most one** frame, and only in response to a request it actually received. It never calls `stop()` after that frame — returning is sufficient, because process exit closes the relay, and `stop()` would add a second payload frame to the cycle (D-O10).

**Invariant D-2:** at most one `Spiral\RoadRunner\Worker` **uses** the relay in a process. This holds by construction, not by a runtime check: the D1 fallback runs only inside `Runner::run()`'s catch, which returns immediately, and `$worker->start()` sits outside that boundary — so the fallback path and the live-worker path are mutually exclusive. Extra `Worker` objects built by the container are inert (D-O8, D-O9).

**Invariant D-3:** the throwable→response policy has exactly one implementation, `WorkerErrorResponder` (D-O7). The shutdown-rescue page policy in `handleShutdown()` (`HttpWorker.php:231-241`) stays separate on purpose: it must bypass the `Response`/generator machinery in a shutdown context (§4.3).

**Invariant D-4:** a caught boot throwable is always re-emitted to STDERR by the catcher, because catching it removes the runtime handler's own trace line (D-O5).

**Invariant D-5:** boot handling never runs inside a `register_shutdown_function` context and never calls `waitRequest()` from one — a shutdown handler must complete promptly, and blocking there hangs the process past `pool.allocate_timeout` while RR believes the worker is alive.

## 6.4 `Runner` — the D1 boundary

`Runner` becomes a plain class with `private readonly` promoted properties (same reason `CentrifugoWorker` did: a `readonly class` cannot have a non-readonly test subclass — see the Centrifugo delta).

```
public function run(): int
    $_SERVER['APP_RUNTIME_MODE'] = $this->runtimeMode;
    try {
        $this->kernel->boot();
        $registry = container->get(WorkerRegistry::class);
        $worker   = $registry->getWorker($this->mode);
    } catch (\Throwable $bootThrowable) {
        return $this->handleBootFailure($bootThrowable);
    }
    … null-worker branch (now via logError()), $worker->start(), return 0
```

The boundary spans boot **plus** the registry lookup and worker construction: for jobs and centrifugo those constructors build the RR/RPC connections (`config/jobs.php:36-44`, `config/centrifugo.php:32-45`), and a failure there is a boot failure to any operator. It deliberately does **not** wrap `$worker->start()` — that would catch request-loop failures with the relay already in use, breaking Invariant D-2.

**Step 0.** The existing unsupported-mode message at `Runner.php:31` moves from `error_log()` to `$this->logError(...)`, so every Runner diagnostic carries the `[roadrunner-symfony]` prefix the docker harness asserts on.

`handleBootFailure(\Throwable): int`
1. `@ini_set('display_errors', 'stderr');` — see D-A4. Cheap, reverses nothing, and keeps any later PHP notice off the protocol channel.
2. `$this->logError(sprintf('BOOT FAILURE (mode=%s): %s', $this->mode, $throwable));` (Invariant D-4). The uppercase marker is the greppable signal that this process serves error pages only (§6.7).
3. Best-effort Sentry through `SentrySdk::getCurrentHub()`, behind a `class_exists` guard. **Not** through the container: `sentry-symfony` declares `Sentry\State\HubInterface` under `_defaults: public: false` (`vendor/sentry/sentry-symfony/src/Resources/config/services.yaml:1-3`), so `$container->has(HubInterface::class)` is always `false` on a compiled container and a container lookup would be silently dead code. When the SDK never initialised, the global hub has no client and the capture is a harmless no-op.
4. `if ($this->mode !== Mode::MODE_HTTP) { return 1; }`
5. `$worker = $this->createFallbackPsr7Worker();` — `protected` seam; default `new PSR7Worker(Worker::create(), …Psr17Factory)`, mirroring `HttpWorker::createPsr7Worker()`.
6. `$request = $worker->getHttpWorker()->waitRequest();` inside `try/catch (\Throwable)`; on throw or `null`, return 1 without a frame.
7. `new WorkerErrorResponder($this->kernel->isDebug())->sendThrowableResponse($worker, $throwable);` (Invariant D-3; `KernelInterface::isDebug()` at `vendor/symfony/http-kernel/KernelInterface.php:90`).
8. `return 1;` — no `stop()` (Invariant D-1).

## 6.5 `HttpWorker` / `JobsWorker` / `CentrifugoWorker` / `TemporalWorker` — the D2 boundary

Each of the four workers wraps **both** its eager `kernel->boot()` block and its `WorkerBootingEvent` dispatch (D-O2, D-O3) in one `try/catch (\Throwable)`:

```
$bootThrowable = null;
try { … eager boot …; $this->eventDispatcher->dispatch(new WorkerBootingEvent()); }
catch (\Throwable $throwable) { $bootThrowable = $throwable; }

if ($bootThrowable !== null) {
    $this->logError('BOOT FAILURE: ' . $bootThrowable);
    try { $this->sentryHubInterface?->captureException($bootThrowable); $this->sentryHubInterface?->getClient()?->flush(); } catch (\Throwable) {}

    if ($this->shouldServeBootFailurePage()) {
        $this->serveBootFailure($worker, $bootThrowable);
        return;
    }
}
```

`HttpWorker::shouldServeBootFailurePage(): bool` returns `$this->debug`; the other three workers have no such method and always fall through to their loop — they consume tasks and RPC, not browsers, so no page exists to render (ADR-5, D-O6).

`HttpWorker::serveBootFailure(PSR7Worker, \Throwable): void` — `waitRequest()` once inside `try/catch`, then `sendThrowableResponse()`, then return (Invariants D-1, D-3). In this path `start()` returns before `registerShutdown()` (`HttpWorker.php:99-104`), so no shutdown rescue is registered at all.

`reportBootFailure()` and the `[roadrunner-symfony]` STDERR sink live in **one** place — the `ErrorHandler\BootFailureReporting` trait, composed into all four workers and into `Runner` (which overrides `getBootFailureLabel()` to add `(mode=…)` and `getBootFailureSentryHub()` to use the SDK hub). Each worker implements `getBootFailureSentryHub()` by returning its injected hub. The trait also owns the `@ini_set('display_errors', 'stderr')` pin (D-A4), so both the D1 and D2 frame paths get it. The four classes share no base class; the trait is the composition point that keeps the harness-greppable `BOOT FAILURE` string singular.

## 6.6 `ErrorHandler\WorkerErrorResponder` — the single render policy (extracted, not new)

The body of `HttpWorker::sendThrowableResponse()` (D-O7) moves verbatim into `src/ErrorHandler/WorkerErrorResponder.php`:

```
class WorkerErrorResponder
{
    public function __construct(private readonly bool $debug) {}
    public function sendThrowableResponse(PSR7Worker $worker, \Throwable $throwable): void
    protected function renderHtmlError(\Throwable $throwable): FlattenException
}
```

The extracted policy also collapses to a **single** `respond()` call: `createErrorResponse()` picks the body (Symfony page → `MinimalErrorPage` when the renderer throws → empty in prod) and the caller sends it once, so a failing send can no longer trigger a second `respond()` (Invariant D-1; tightens §N-3's one-frame rule for Bucket A too).

`HttpWorker::sendThrowableResponse()` becomes a delegation to `$this->getThrowableResponder()` — a new `protected` seam replacing the old `renderHtmlError()` one. The responder is constructed per call and holds no state, so nothing is added to the `ResetInterface` surface (G18). It stays a `protected` factory seam rather than a constructor dependency (G16) because that is this component's established test-isolation convention — `createPsr7Worker()`, `registerShutdown()` and `logError()` are the same shape (§4.4) — and because widening `HttpWorker`'s public constructor would break consumers who construct it directly.

`TestableHttpWorker::$failHtmlRenderer` is re-pointed at a responder subclass so TC-08 keeps exercising the `MinimalErrorPage` fallback. Behavior is unchanged — `HttpWorkerErrorResponseTest` and `HttpWorkerExceptionTest` must pass untouched apart from that seam.

## 6.7 Known limits

| Limit | Why | Evidence |
|-------|-----|----------|
| `die()` / `exit()` / `dd()` / true fatal in a boot listener still yields RR's `EOF` body | Uncatchable by `try/catch`; the only rescue would be a **blocking** `waitRequest()` inside a shutdown handler with no request in flight — forbidden by Invariant D-5. A `dd()` left in a custom warmer is the realistic dev-time trigger. | §5 IT-REAL-6 already disproved the shutdown rescue for true fatals *with* a request in flight |
| A boot failure slower than `pool.allocate_timeout` still yields RR's error | RR kills the worker before it reaches `waitRequest()`. A listener with a 30 s DB timeout is the realistic trigger. | RR pool config |
| **D1 makes a broken prod deployment look healthy to RR.** Today a failing boot cannot answer RR's PID probe, so a static pool fails allocation loudly at `rr serve` startup. Under §6 the fallback answers that probe inside `waitPayload()` and then blocks in `waitRequest()`, so the pool comes up green and the only signals are the `BOOT FAILURE` STDERR line per process and the 500s themselves. | Accepted consequence of the user's 2026-08-18 choice that prod boot failures answer with a clean 500 rather than dying. Mitigated only by the uppercase greppable marker (§6.4 step 2). | `vendor/spiral/roadrunner-worker/src/Worker.php:95-118` (PID answered inside `waitPayload()`) |
| **Under load, D1 can lose the page it exists to deliver.** With `pool.num_workers > 1` every D1 request costs a full kernel boot plus a process spawn; if boot is slower than the arrival rate, RR queues and then fails allocation at `pool.allocate_timeout` and returns its own error instead. | Same mechanism as the row above; the page is best-effort under saturation. | IT-REAL-D5 records the observed behavior at a small repeat count |
| **Prod D2 on the Jobs worker can amplify into a requeue loop.** If the failed listener was what the handlers depend on, every task now fails and is nacked **with requeue** at full consumption speed, with no back-off and no dead-letter — where today the worker would simply have died and consumed nothing. | `docs/specs/rr-jobs-worker.md` (nack-with-requeue on any throwable); `src/Worker/JobsWorker.php:83-86,159-169` | Accepted risk, recorded 2026-08-18: consistent with "keep serving degraded"; the `BOOT FAILURE` line names the cause |
| **`TemporalWorker` cannot honour "keep consuming" after a boot failure that broke the kernel.** Its `start()` continues into `temporalWorkerFactory->create()` and `temporalWorkerInitializer->initialize()`, which are container-dependent and unguarded; if the container is unusable those throw out of `start()` — outside `Runner`'s boundary — as an uncaught fatal. A failed *warmer* (the ADR-5 case) is unaffected. | `src/Worker/TemporalWorker.php:31-45`; the boundary excludes `$worker->start()` (Invariant D-2) | Accepted, recorded 2026-08-18 |
| Prod D2 hides the failure from the HTTP client by design | The kernel is serviceable; availability wins. Visibility is STDERR + Sentry only. | user decision 2026-08-18; ADR-5 |
| No respawn back-off | Under D1 the worker exits after each answered request, so churn is traffic-driven (one process per request). `install/.rr.yaml:17-18` ships `pool.debug: true`, where that is already the lifecycle. At idle this is strictly less churn than today's free-running respawn. | `install/.rr.yaml:17-18` |

## 6.8 Anti-Patterns (DO NOT)

| Don't | Do Instead | Why |
|-------|-----------|-----|
| Call `stop()` after the boot-failure response | `respond()` once, then `return` — process exit closes the relay | `stop()` is a normal payload frame, so this puts two payload frames in one cycle (D-O10) |
| Re-implement the debug/prod render policy in a boot-specific class | Delegate to `WorkerErrorResponder` | Two copies of the policy, the `MinimalErrorPage` fallback and the frame invariant drift apart (D-O7, Invariant D-3) |
| Guard the fallback with a runtime "does a Worker exist" check | Rely on the structural mutual exclusion of Invariant D-2 | Extra `Worker` objects already exist and are inert (D-O8, D-O9); a `$currentHttpWorker === null` check is always true on the D1 path and protects nothing |
| Guard boot and then log nothing | Always re-emit `(string)$throwable` to STDERR | Catching removes `SymfonyErrorHandler`'s own trace line — the one thing that works today (D-O5) |
| Take the app down in prod because a boot listener failed | Log + Sentry + keep serving | The kernel is serviceable; ADR-5 is the bundle's standing convention (D-O6) |
| Wrap `$worker->start()` in the Runner boot boundary | Wrap only boot + registry + `getWorker()` | Request-loop failures would be caught with the relay already in use (Invariant D-2) |
| Attempt a rescue from a shutdown handler at boot | Accept D3 as a documented limit | Blocking `waitRequest()` in shutdown can hang past `allocate_timeout` (Invariant D-5) |
| Use `error_log()` for Runner diagnostics | `@fwrite(\STDERR, '[roadrunner-symfony] ' … )` | The docker harness asserts that literal prefix; `Runner.php:31` is the only place breaking the convention |
| Explain the debug branch with a comment | Name it: `shouldServeBootFailurePage()` | CR1 — extract a named method instead of prose |

## 6.9 Test Case Specifications

### Unit (PHPUnit)

| Test ID | Component | Input | Expected | Edge |
|---------|-----------|-------|----------|------|
| TC-D01 | `WorkerErrorResponder` | `debug=true`, `\RuntimeException('x')` | `respond` once, body contains class + message; `error()` never | — |
| TC-D02 | `WorkerErrorResponder` | `debug=true`, renderer forced to throw | `respond` once with `MinimalErrorPage` (500, `text/html`) | — |
| TC-D03 | `WorkerErrorResponder` | `debug=false` | `respond` once, empty body, 500 | — |
| TC-D04 | `WorkerErrorResponder` | `respond()` throws | `getWorker()->error()` once; nothing escapes | `error()` also throws → still nothing escapes |
| TC-D05 | `HttpWorker::start` | boot listener throws, `debug=true` | `waitRequest` called once, `respond` once with the page, `kernel->handle()` never called, `start()` returns, **no shutdown handler registered**; `logError` contains `BOOT FAILURE` | — |
| TC-D06 | `HttpWorker::start` | boot listener throws, `debug=false` | `logError` contains `BOOT FAILURE`, Sentry captured, **loop runs**: a queued request is handled normally (200) | — |
| TC-D07 | `Runner::run` | `kernel->boot()` throws, `mode=http` | fallback worker created once, `respond` once, returns `1` | `waitRequest()` returns `null` → no frame, returns `1`; `waitRequest()` throws → no frame, returns `1` |
| TC-D08 | `Runner::run` | `kernel->boot()` throws, `mode=jobs` | **no** fallback worker created, `logError` names `mode=jobs`, returns `1` | — |
| TC-D09 | `Runner::run` | worker **construction** throws during the registry lookup, `mode=http` | fallback still responds once and returns `1`; Sentry attempted (the container survived) | proves the boundary covers more than `boot()` |
| TC-D10 | `JobsWorker` / `CentrifugoWorker` | boot listener throws | logged + Sentry, consumer loop still entered | identical in both envs |
| TC-D11 | `Runner::run` | healthy boot | unchanged: worker started once, returns `0`; unknown mode returns `1` **via `logError()`**, not `error_log()` | regression guard for step 0 |

### Integration — real RoadRunner (`tests/docker-validate-error-pages.sh`)

The harness gains a `BOOT_FAIL` env var read by the test kernel, with exactly three values:
- `none` — current behavior, all existing assertions unchanged;
- `listener` — a `WorkerBootingEvent` listener that throws `\RuntimeException('boot listener exploded')` (D2);
- `kernel` — the test kernel's `boot()` override throws before `parent::boot()`, so the failure lands inside `Runner::run()`'s try (D1).

| Test ID | Flow | Verification |
|---------|------|--------------|
| IT-REAL-D1 | `BOOT_FAIL=listener`, debug | HTTP 500 and the body contains the real exception message — **not** `EOF` |
| IT-REAL-D2 | `BOOT_FAIL=listener`, prod | `GET /ok` → **200** (degraded but serving) and `rr.log` contains `[roadrunner-symfony] BOOT FAILURE` |
| IT-REAL-D3 | `BOOT_FAIL=kernel`, debug then prod | debug: 500 + real message · prod: 500 with a 0-byte body |
| IT-REAL-D4 | recovery: `BOOT_FAIL=none` after a broken run | `GET /ok` → 200 |
| IT-REAL-D5 | `BOOT_FAIL=kernel`, prod, three consecutive requests | all three return 500 and RR logs no allocation failure — records the repeat behavior the §6.7 under-load row describes |

The boot-failure scenarios must skip the harness's `wait_ready()` gate: it curls `/ok` with `curl -s -o /dev/null --retry 40`, which exits 0 on a 500, so it would burn its `--max-time 40` and then pass regardless of the app being broken (`tests/docker-validate-error-pages.sh:182`).

*Floors: ≥5 unit (11) and ≥3 integration (5) — met.*

## 6.10 Assumptions & Open Questions

| # | Assumption | If wrong, then… |
|---|------------|-----------------|
| D-A1 | A worker that boots, fails, and only then reaches `waitRequest()` is protocol-indistinguishable from a slow boot, so RR delivers it a request normally. | Falsified by IT-REAL-D1/D3 returning RR's `EOF` body instead of the page. Grounded in: the PHP worker answers RR's PID probe only inside `waitPayload()` (`vendor/spiral/roadrunner-worker/src/Worker.php:95-118`), which a normal boot also reaches late. |
| D-A2 | Extracting `sendThrowableResponse()` into `WorkerErrorResponder` is behavior-preserving. | The existing `HttpWorkerErrorResponseTest` / `HttpWorkerExceptionTest` suites fail — they are the regression guard. |
| D-A3 | `$this->debug` (`kernel.debug`, wired at `config/services.php:78`) is the right dev/prod discriminator for D2. | Same knob §A-5 already settled for Buckets A/B. |
| D-A4 | **RESOLVED (real validation 2026-08-18, §6.11).** STDOUT is uncorrupted when the D1 fallback writes its frame. Supported, not assumed blindly: the boot throwable is **caught**, so the runtime's uncaught-exception printer never runs, and in the D-O4 run Symfony's own output went to STDERR (RR's `server` logger), not the relay. The residual risk is a PHP notice printed by `display_errors` before the frame, which §6.4 step 1 pins to `stderr`. | The client gets RR's `EOF` body instead of the page and `rr.log` shows a goridge **stdout-crc** error — the §5/IT-REAL-6 failure mode. **IT-REAL-D1 and IT-REAL-D3 are this assumption's blocking gate.** |

**Irreversibility (one item):** the extraction in §6.6 **removes the `protected HttpWorker::renderHtmlError()` seam**. It is a documented test-only seam — §4.4 states the three `protected` seams "exist purely for isolation in tests" — but it is still protected API a consumer could have overridden. Mitigation: record it in `UPGRADE.md` with the replacement (override `getThrowableResponder()` and return a `WorkerErrorResponder` subclass). Nothing else on the irreversibility list is touched: no public HTTP/DI contract changes, no migration, no security-model change, no destructive operation. `Runner` losing its `readonly` modifier widens what subclasses may do; it breaks nothing.

*No open question blocks execution: the two forks (D2 policy, D1 recovery mechanism) were decided by the user on 2026-08-18 and are recorded in §6.3. The operability trade-offs the prod choices carry are recorded as §6.7 limits, not as open questions.*

## 6.11 Validation results (real RoadRunner)

Run 2026-08-18, **RoadRunner 2025.1.15**, PHP 8.4, `pipes` relay, `pool.debug: true`, via `tests/docker-validate-error-pages.sh`. Every pre-existing Bucket A/B assertion in that harness passed unchanged in the same run.

| Gate | Scenario | Result |
|------|----------|--------|
| IT-REAL-D1 | `BOOT_FAIL=listener`, debug | ✅ HTTP 500, 87 306-byte Symfony page containing `boot listener exploded` (was: 4-byte `EOF`) |
| IT-REAL-D2 | `BOOT_FAIL=listener`, prod | ✅ `GET /ok` → **200** — degraded but serving; `rr.log` carries `[roadrunner-symfony] BOOT FAILURE` |
| IT-REAL-D3 | `BOOT_FAIL=kernel`, debug | ✅ HTTP 500, 67 654-byte page containing `kernel boot exploded` |
| IT-REAL-D3 | `BOOT_FAIL=kernel`, prod | ✅ HTTP 500, **0-byte body** (no disclosure) |
| IT-REAL-D4 | recovery, `BOOT_FAIL=none` | ✅ 200 `OK from worker` |
| IT-REAL-D5 | `BOOT_FAIL=kernel`, prod, 3 consecutive requests | ✅ all three 500; RR logged no allocation failure |

**D-A4 resolved:** the fallback's frame is accepted by RoadRunner for both D1 and D2, in debug and prod — no goridge stdout-crc, no `EOF` body. The §5/IT-REAL-6 corruption mode does not reach this path, because the boot throwable is caught before any handler prints.
