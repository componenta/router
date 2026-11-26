# Routing lifecycle benchmarks

The fixture contains an equal number of static and dynamic routes, with GET/POST on dynamic routes. Three layouts distinguish separate prefixes, a shared first segment, and a shared complete prefix with different literal suffixes. Route counts: 100, 1,000 and 10,000.

Run PHPBench separately for lifecycle initialization, cache preparation and hot requests:

```sh
vendor/bin/phpbench run --filter=RouteLifecycleBench --report=aggregate
```

PHPBench's fresh-collection subject measures new collection construction plus the first match **inside an already initialized PHP process**. It is not a process-cold startup measurement. The hot subjects warm the collections in setup and measure successful static/dynamic matching, 404, 405 and URL generation. File creation and cache compilation have separate subjects.

For process-level measurements, create the artifact once outside the measured process, then repeat each command in independent processes (at least five times; report the median):

```sh
CACHE=$(mktemp)
php tools/route-lifecycle.php prepare 10000 broad "$CACHE"
php -d opcache.enable_cli=0 tools/route-lifecycle.php source 10000 broad "$CACHE"
php -d opcache.enable_cli=0 tools/route-lifecycle.php cached 10000 broad "$CACHE"
php -d opcache.enable_cli=1 -d opcache.file_update_protection=0 -d opcache.memory_consumption=256 tools/route-lifecycle.php opcache 10000 broad "$CACHE"
php tools/route-lifecycle.php parity 10000 broad "$CACHE"
rm -f "$CACHE"
```

The OPcache subject explicitly precompiles the artifact before the timer and checks that it is cached. This isolates the benefit of avoiding map parsing; it does not model all of PHP-FPM startup, application discovery or HTTP dispatch. The source and cached commands report initialization and first matching separately. Run the parity command for each shape before comparing timings. It compares result metadata, parameters, exceptions and generation, not wall-clock equality.

Keep PHP version, PCRE/JIT configuration, OPcache, hardware, fixture size and layout fixed when comparing revisions. Do not use timing-based assertions in the correctness suite. Complex custom PCRE constraints and templates without guaranteed literal segments may still require sequential candidates.

Format 15 executes the same individual expressions in the same order in source and prepared collections. The former combined-expression path is intentionally removed: under restrictive PCRE limits a combined expression can succeed while an individual candidate fails. Collections with indistinguishable literal segments can still have linear candidate scans. Compare resource-limit observations separately from normal timings; the prepared path optimizes construction and artifact loading, not a different matching contract.
