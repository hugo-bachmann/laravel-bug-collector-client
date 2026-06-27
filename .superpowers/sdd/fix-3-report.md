# Fix-3 Report: Outbox cap + de-duplication

## Changes

### `src/Outbox.php`
- Added `public function __construct(private int $maxEntries = 100) {}` — backward-compatible default preserves all existing `new Outbox()` call sites.
- Added `private array $seenKeys = []` set to track seen dedup keys within a flush window.
- `record()` now computes a dedup key then:
  1. Returns early if key is already in `$seenKeys` (duplicate).
  2. Returns early if `count($this->pending) >= $this->maxEntries` (cap reached — silent drop).
  3. Otherwise stores the key and appends the entry.
- `flush()` drains both `$pending` and `$seenKeys` to local variables, clears both, then sends — ensuring the next flush window starts fresh.

### `config/bug-collector.php`
- Added `'max_buffer' => env('BUG_COLLECTOR_MAX_BUFFER', 100)` with French comment: *max payloads bufferisés par cycle, garde-fou anti N+1*.

### `src/BugCollectorServiceProvider.php`
- Changed `$this->app->singleton(Outbox::class)` to a closure that injects the config value:
  ```php
  $this->app->singleton(Outbox::class, function ($app) {
      return new Outbox((int) $app['config']->get('bug-collector.max_buffer', 100));
  });
  ```

## Dedup-key rule

| Payload fields present | Key formula |
|---|---|
| `kind` + `label` | `"{$path}\|{$payload['kind']}\|{$payload['label']}"` |
| `message` | `"{$path}\|{$payload['message']}\|{$payload['file']??''}\|{$payload['line']??''}"` |
| anything else | `$path.'|'.md5(serialize($payload))` |

Perf events collapse by `kind+label` regardless of `duration_ms` (targets N+1 identical slow queries). Error events collapse by `message+file+line`. Fallback uses a content hash.

## New tests (`tests/Unit/OutboxTest.php`)

Five new cases added to the existing two:

1. `test_dedup_perf_same_kind_and_label_collapses_to_one` — two perf payloads with same kind+label, different duration_ms → pending count = 1.
2. `test_dedup_perf_distinct_labels_are_not_deduped` — two perf payloads with different labels → pending count = 2.
3. `test_dedup_error_same_message_file_line_collapses_to_one` — two identical error payloads → pending count = 1.
4. `test_cap_drops_entries_beyond_max` — `new Outbox(2)`, 3 distinct payloads → pending count = 2.
5. `test_flush_resets_dedup_so_same_payload_is_accepted_again` — record A, flush, record A again → pending count = 1 (seen-set was reset by flush).

## Test commands + output

```
$ vendor/bin/phpunit --filter OutboxTest
PHPUnit 12.5.30
Runtime: PHP 8.4.16
.......                                                             7 / 7 (100%)
OK (7 tests, 12 assertions)

$ vendor/bin/phpunit
PHPUnit 12.5.30
Runtime: PHP 8.4.16
..................................................                50 / 50 (100%)
OK (50 tests, 102 assertions)
```

## Pint result

```
$ vendor/bin/pint        → passed
$ vendor/bin/pint --test → passed
```

## Commit

See git log for commit hash (committed after report was written).
