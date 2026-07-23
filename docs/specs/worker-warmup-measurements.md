# Worker warmup — measurement evidence (2026-07-23)

Environment: strihacistrojky Sylius app, ddev (PHP 8.5.5 NTS cli), RR 2025.1.15, dedicated
single-worker pool on :8091, APP_ENV=prod APP_DEBUG=0, opcache.enable_cli=1
validate_timestamps=0 jit=disable. Timing measured INSIDE HttpWorker between
$worker->waitRequest() returning and respond() completing. DB = local Postgres (ddev).
Run-to-run variance on cold requests ±30% (OS/DB cache state); conclusions are stable.

## Baseline (bundle v6.1.0, early_router_initialization: true)
- worker ready: 201ms from process start (boot 50ms + dummy request ~70ms)
- GET / cold: 252ms [reached 5905 declared classes]; hot: 33-36ms
- GET PDP cold: 105ms; different PDP right after: 24ms (+0 classes -> archetype warmth)
- GET /kosik/: 29ms

## Decomposition
- CLI harness: kernel boot declares 237 classes, dummy request +481, first real homepage
  render +3442 classes / 384ms, first PDP +705, hot homepage +7 / 32ms.
- opcache preload (cli-guard-stripped Symfony preload file): cold / 252->91ms, PDP 105->69.
  Residual gap vs hot = runtime init, not compile.
- Symfony generated preload file is a NO-OP under RR: `if (in_array(PHP_SAPI, ['cli','phpdbg','embed']))
  return;` guard, RR workers are cli SAPI. (var/cache/prod/App_KernelProdContainer.preload.php:8)
- opcache SHM is per-process for cli workers -> ini preload == boot-time class compile, no
  cross-worker sharing either way. Boot-time warm needs no php.ini/deploy change.

## Generic service warmers (validated prototype, WorkerBootingEvent listener)
- router matcher+generate probe: 4ms
- doctrine getAllMetadata: 12-14ms; entity persisters (93): 0.4ms
- eventDispatcher getListeners (307 registrations): 13-60ms
- form registry resolve 258 form.type: 12-37ms
- twig runtimes (13): 4-9ms
- twig load ALL 4162 templates: 394-504ms — REJECTED (cost >> benefit, learned manifest covers used templates)
- container preload-list Preloader::preload (2965 classes parsed from generated preload file): 304-310ms
- Effect (warmers minus preloader/twig-all): ready 412-446ms, cold / 93-152ms, PDP 89-101ms

## Learned manifest (validated prototype)
- Learn: after each response, union get_declared_classes+interfaces+traits (filter @anonymous)
  + get_included_files() delta restricted to kernel.cache_dir into manifest.
- Warm at boot: Preloader::preload(~7200-7700 learned symbols) 244-246ms +
  opcache_compile_file(191 cache-dir files) 34ms. Ready ~600-630ms (pre-ready, invisible).
- Result: cold / 40.6-41.4ms ≈ hot (33-43); cold PDP 32.6-35.7 ≈ hot (25-30); cart 18-21ms.
  Stable across 2 repeat runs, no crashes.

## Traps discovered (must be in spec/docs)
1. opcache_compile_file() EARLY-BINDS top-level classes; multi-class files (e.g.
   symfony/messenger StackMiddleware.php declaring MiddlewareStack) later fatal with
   "Cannot redeclare class" when the autoloader includes the file. => classes must go through
   Symfony DI Preloader (class_exists-driven, idempotent); opcache_compile_file ONLY for
   non-autoloadable kernel.cache_dir files (cache-pool entries = anonymous classes, compiled
   Twig) which never early-bind. Also fatals for already-declared symbols (composer bootstrap,
   twig core function files) -> skip files already in get_included_files().
2. PHP warnings during preload/boot go to STDOUT in cli and corrupt the goridge protocol
   (worker allocate EOF, binary garbage in RR log). Worker php must run display_errors=0/stderr.
   (Observed with dompdf "Can't preload already declared class" warning.)
3. The empty dummy request (early_router_initialization) crashes host-based channel resolution
   (Sylius ChannelNotFoundException -> uncaught during boot -> worker dies, pool never
   allocates). The Sylius app carries App\EventListener\RoadRunnerWarmupRequestListener as a
   workaround, short-circuiting it with a 204. New design removes the whole failure class;
   that listener gets deleted.
4. Prod container never rebuilds on class changes (ConfigCache not debug) — irrelevant to
   bundle but relevant to test procedure: cache:clear needed between prototype edits.

## opcache.file_cache comparison (post Gate-3 challenge)

file_cache primed (/tmp/opcache-fc), generic warmers, no manifest:
- ready 132-144ms; cold / 49-50ms; cold PDP 43-46ms

file_cache + class manifest, compile-file step skipped:
- ready 194ms; cold / 43.6ms; cold PDP 44.6ms

file_cache + FULL manifest incl. opcache_compile_file of cache-dir files (forbidden combo):
- ready 185-257ms; cold / 102-111ms; cold PDP 68-77ms  -> 2x degradation, reproducible
  (4 runs); mechanism unverified (labeled assumption A1 in spec); isolated to the
  compile-file step by ablation (manifest-classes-only run above is clean).

Control (manifest, no file_cache) re-run in same system state: cold / 41.3-41.7ms,
cold PDP 35.3-35.6ms — reproducible.

## Summary matrix (cold homepage / cold PDP / worker-ready time)

| configuration | / | PDP | ready |
|---|---|---|---|
| baseline v6.1.0 (early_router_initialization) | 252 | 105 | 201ms |
| generic warmers only | 93-152 | 89-101 | 412-446ms |
| warmers + learned manifest (zero-config default) | 41 | 33-36 | 604ms |
| file_cache + warmers | 49-50 | 43-47 | 132-144ms |
| file_cache + warmers + class manifest (no compile-file) | 44 | 45 | 194ms |
| file_cache + full manifest (forbidden) | 102-111 | 68-77 | 185-257ms |
| steady-state (hot) reference | 33-43 | 24-30 | — |

## Note on class-count series

The CLI-harness series (boot 237 / dummy +481 / first render +3442) counts
get_declared_classes() only; the in-worker series ([5485..6998]) counts classes AFTER the
prototype warmers ran and, in later runs, includes interfaces+traits in the manifest
dumps but classes-only in the bracketed request log. The two series are internally
consistent but not comparable to each other; use the in-worker bracketed numbers for
worker-state claims.

## Re-validation after the RouterWarmer `router.default` change

Same harness, Sylius app, 2026-07-23. RouterWarmer rewired from the `router` alias
(resolved to Sylius `LocaleStrippingRouter` → previously fell into the removed
`match('/')` fallback) to `router.default` (`getMatcher()` + `getGenerator()`,
verified in the compiled container factory).

- Seed boot (no manifest): first request 128.0 ms, hot 27–44 ms.
- 5 fresh boots with learned manifest — cold first requests: 46.3 / 42.3 / 42.9 /
  42.3 / 42.4 ms → median 42.4 ms; hot `/` median 33.7 ms.
- Acceptance: 42.4 / 33.7 = **1.26× ≤ 1.5×** — unchanged within run-to-run variance
  vs the pre-change 41.7 / 33.8 = 1.23×. No regression from dropping the fabricated
  match; decorated apps now warm the compiled generator as well.
