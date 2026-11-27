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

Format 20 executes the same individual expressions in the same order in source and prepared collections. The former combined-expression path is intentionally removed: under restrictive PCRE limits a combined expression can succeed while an individual candidate fails. Collections with indistinguishable literal segments can still have linear candidate scans. Compare resource-limit observations separately from normal timings; the prepared path optimizes construction and artifact loading, not a different matching contract.

For a fixed-width template such as `/items/{id}/s123/{value}`, a guaranteed interior literal also discriminates candidates. This avoids the otherwise linear scan of routes sharing the same prefix and having no literal suffix. Optional or multi-segment templates are not eligible for that optimization.

In addition to source/prepared equivalence, `tests/IndexOracleTest.php` checks candidate selection against direct matching of individual compiler-produced PCRE expressions (and literal equality for static routes). This avoids using the shared runtime matcher as its own correctness oracle.

The deep-URI miss subject exercises an input with 20,000 short segments and an unmatched first literal. Both paths bound the prefix walk by the longest registered literal prefix for the method. This prevents excessive substring creation and hashing for irrelevant deep request paths. It does not impose a request-length limit; deployments should still enforce their HTTP request limits separately.

`PreparedFingerprintBench` isolates the integrity check and in-memory map loading for 1,000 and 10,000 dynamic routes with no float defaults, a single float default, or a float default on every route. Run `vendor/bin/phpbench run --filter=PreparedFingerprintBench --report=aggregate`. It deliberately excludes the initial artifact parsing cost. Unchanged nested arrays retain PHP copy-on-write sharing during normalization; the format-16 fingerprint bytes are unchanged. Peak process memory during file parsing is a separate measurement.

Format 18 keeps the literal prefix only when an omitted optional parameter cannot merge the following text into its final segment; otherwise the parent prefix is indexed. The oracle includes these cases for all four built-in syntaxes.

`OverlappingPrefixBench` measures hot matching and 405 responses with 4, 64, 256 and 512 simultaneously eligible literal prefixes in both source and prepared collections. Run `vendor/bin/phpbench run --filter=OverlappingPrefixBench --report=aggregate`. The first candidate intentionally fails and the next registration succeeds, so the benchmark exercises candidate merging without measuring an unrelated full sequential PCRE miss.

Format 20 retains the index layout and corrects inline PCRE option scope, including repeated positive x settings in a single option header. The format version changes so that artifacts produced by the old lexer cannot silently retain its behavior.
