# Foundation query builder: design

Revised September 25, 2026 after review. Accepted design for the bounded first implementation in `src/Database/Query`. It replaces the native Doctrine `QueryBuilder` currently returned by `Table::query()` with a Laravel-shaped Foundation builder over Foundation's existing managed connection.

The method reference records future API candidates inspired by Laravel and `stellarwp/db`, using familiar names where their semantics fit. It is not a promise of full parity or a release checklist. The bounded first slice below is the current implementation scope; review complete classes before expanding it.

## Summary

**Architecture.** The builder collects query intent, collects validated conditional fragments, and compiles SQL with ordered positional bindings. Execution goes through the injected Doctrine `Connection`, so every query runs inside Foundation's connection integration: the borrowed WordPress mysqli session, managed transactions, terminal-failure tracking, and commit acknowledgement. Foundation owns those guarantees, built on DBAL; the builder must preserve them, not re-implement them.

Compiling SQL ourselves rather than driving Doctrine's `QueryBuilder` is a hypothesis for the slice to test. The motivation is that several required features have no Doctrine builder equivalent (multi-row insert, upsert, escaped `LIKE`, one condition model shared by every statement type), so part of the compiler is ours either way.

| Component | Responsibility |
| --- | --- |
| `Query`, `WhereGroup`, `JoinClause` | Collect valid query intent; reject invalid combinations at the call |
| `WhereGroup` | Share conditional grammar across query filters, groups, and join-value predicates |
| `Compiler` | Produce SQL and ordered bindings from query state |
| Dialect strategy | Handle demonstrated MySQL and MariaDB syntax differences |
| `Executor` | Execute through the injected managed connection |

**DBAL compatibility research.** Foundation 2.0 requires DBAL 4.4 on PHP 8.3. DBAL 3 support remains deferred until the Rector downgrade work. This comparison informs that later work; it does not establish support. Execution and fetching have the same call shapes in DBAL 3.10 and 4.4. The differences stay in `Executor` and the dialect:

| API | DBAL 3.10 | DBAL 4.4 | Handling |
| --- | --- | --- | --- |
| `executeQuery`, `executeStatement` | Same parameters | Same parameters | Native affected rows stay `int\|string`; bounded insert/upsert totals are `int` |
| `fetchAllAssociative`, `fetchAssociative`, `fetchOne`, `fetchFirstColumn` | Same | Same, natively typed | None |
| `ParameterType` | Class of `int` constants | Enum | Same source syntax works on both; the type appears only in PHPDoc |
| `ArrayParameterType` | Class of `int` constants | Enum | Not used; the compiler expands `IN (?, ?, ?)` itself |
| `lastInsertId()` | Optional name; may return `false` | No argument; throws when no identity exists | Wrapped once in `Executor` |
| `quoteSingleIdentifier()`, `escapeStringForLike()` | Present | Present | None |
| MariaDB 10.5.2+ platform | `MariaDb1052Platform` | `MariaDB1052Platform` | Case-insensitive class names; checks live in the dialect strategy |

The fluent query surface primarily uses Foundation types and native PHP values. Explicit Doctrine type conversions use the shared connection; table conveniences delegate to the fluent query implementation. Exception names are shared across both researched majors. Proving the PHP 7.4 release requires running the Rector-transformed code on PHP 7.4, not only DBAL 3 on PHP 8.

**Packaging.** The builder belongs in `foundation-database`. `Table::query()` returns it, it adds no dependency, and it cannot run without Database's connection. A later model or ORM layer, with its own lifecycle and scope, would justify `foundation-models` on top.

## Consumer code

The portal's ordinary candidate lookup and atomic replacement keep their existing schema and business behavior. The repository still receives its concrete `Entries_Table` and the shared Doctrine `Connection` through its constructor.

```php
/**
 * @param list<string> $tokens
 * @return list<array<string, mixed>>
 */
public function find_candidates( array $tokens, int $limit = 800 ): array {
	if ( $tokens === [] ) {
		return [];
	}

	return $this->entries->query()
		->select( 'ent_num', 'name', 'name_normalized', 'sdn_type', 'program', 'source' )
		->whereContainsAny( 'name_normalized', $tokens )
		->limit( $limit )
		->get();
}

/**
 * Replace validated import rows without changing truncation or transaction behavior.
 *
 * @param list<array<string, mixed>> $rows
 */
public function replace( array $rows ): void {
	$this->db->transactional( function () use ( $rows ): void {
		$this->entries->deleteAll();

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$values = [];

			foreach ( $chunk as $row ) {
				$values[] = [
					'ent_num'         => $row['ent_num'],
					'name'            => mb_substr( $row['name'], 0, 350 ),
					'name_normalized' => mb_substr( $row['name_normalized'], 0, 191 ),
					'sdn_type'        => mb_substr( $row['sdn_type'], 0, 30 ),
					'program'         => mb_substr( $row['program'], 0, 255 ),
					'source'          => $row['source'],
				];
			}

			$this->entries->query()->insert( $values );
		}
	} );
}
```

The deployed entries table has no `updated_at` column and no unique key on `ent_num`. This example does not introduce either. Upsert is a separate query capability for tables with appropriate existing primary or unique keys, not a replacement for this import.

## Package layout

The bounded implementation belongs in `src/Database/Query`. `Database` lives in that namespace, and `TableReference` lives under `Query\ValueObjects`. `Table::query()` returns a fresh `Query`. `Table::insert()` and `insertGetId()` delegate to the same query operations. `insert()` accepts one row or many, returns an integer count, and treats empty input as no work. `insertGetId()` accepts exactly one row, inserts database defaults for empty data, and preserves its generated ID as `int|string`. Explicit Doctrine insert types remain available through the shared connection. Upsert belongs on the query.

The consumer surface comprises `Database`, `Query`, `WhereGroup`, `JoinClause`, `TableReference`, and existing `Table` methods. SQL compilation, naming helpers, execution adapters, and compiled values are implementation details marked `@internal`.

## Representative implementation shape

An injected `StellarWP\Foundation\Database\Query\Database` resolves `table( Table|string|TableReference $table, ?string $alias = null )` into a fresh query. A table object provides the same query through `query( ?string $alias = null )`. Name resolution captures the current scope before execution; the shared connection still owns transactions and terminal failures.

`Query` owns mutable query intent for one consumer operation. Terminal operations compile without changing it, so `count()` followed by `get()` observes the same conditions and row shape. They call the injected Doctrine connection's ordinary query or statement execution methods and preserve native execution exceptions.

`WhereGroup` owns the conditional grammar: two- and three-argument comparisons, associative equalities, nested closures, IN, NULL, and escaped substring predicates. It validates operators before handling null, compiles nested groups to immutable `Statement` fragments, and preserves binding order. A nested group's later mutation cannot alter an already captured statement. `JoinClause` adds column-to-column ON conditions and reuses that same value-condition grammar.

This bounded design needs neither a separate class per predicate nor an independently replaceable execution service merely to forward a Doctrine method. Extract collaborators only when they own naming, compilation, dialect policy, or another demonstrated responsibility. Future predicates can extend the grammar without requiring a condition-node hierarchy now.

## Composition rules

Terminal operations follow one policy based on whether the query shapes its result rows. Nothing a caller supplied is silently dropped.

**Row-shaping state** includes `distinct()`, `groupBy()`, `having()`, `limit()`, and `offset()`. Treat every `selectRaw()` projection as row-shaping too: opaque SQL may contain an aggregate or another expression that changes cardinality. Preserve it without parsing SQL. Calling `select()` replaces the complete projection and clears that raw-projection state. Future union support would also be row-shaping. Ordering is row-shaping only in combination with `limit()` or `offset()`, because it then decides which rows are kept.

### Reads that return rows

`get()` and `first()` run the query as written. `value()` and `pluck()` are future candidates. `first()` sets `LIMIT 1` on the outermost query and keeps any offset.

### Aggregates and existence

1. **Without row-shaping state or raw projections**, the aggregate runs directly against the query's tables, joins, and conditions. The selection is replaced by the aggregate, and ordering is omitted because it cannot change the result.

   ```sql
   SELECT COUNT(*) FROM `wp_entries` WHERE `source` = ?
   ```

2. **With row-shaping state**, the complete original query becomes a derived table, and the aggregate runs over it. Selection, ordering, limit, offset, grouping, having, and unions are all kept inside.

   ```php
   $query->select( 'amount as payment' )->orderBy( 'payment', 'desc' )->limit( 10 )->max( 'payment' );
   ```

   ```sql
   SELECT MAX(`payment`) FROM (
    SELECT `amount` AS `payment` FROM `wp_payments` ORDER BY `payment` DESC LIMIT 10
   ) AS `aggregate`
   ```

   Preserve every explicit projection, including on a query shaped only by limit or offset. An `orderBy()` may depend on its selected alias. An implicit projection can be reduced to the aggregate's required column, or `1` for count, only when doing so preserves the query's meaning.

   With `distinct()`, `groupBy()`, `having()`, or `selectRaw()`, the projection defines the rows. A column aggregate must refer to an output column or alias of the derived table. Structured selections can be checked where unambiguous; raw SQL remains opaque, and the database reports a missing output column. Do not implement a SQL parser to infer raw expression aliases. A shaped join needs an explicit projection to avoid duplicate derived-table column names.

3. **`exists()`** wraps the original query unchanged: `SELECT EXISTS( <query> )`. Without row-shaping state or raw projections, the inner selection is reduced to `1`. In particular, `selectRaw( 'COUNT(*) AS total' )->exists()` is true even for an empty source table: the selected aggregate produces one result row. Replacing that projection with `1` would incorrectly return false. With any row-shaping state, the selection stays, so a `having()` that refers to a selected alias still works:

   ```php
   $query->selectRaw( 'program, COUNT(*) AS total' )
    ->groupBy( 'program' )
    ->having( 'total', '>', 1 )
    ->exists();
   ```

   ```sql
   SELECT EXISTS(
    SELECT program, COUNT(*) AS total FROM `wp_entries` GROUP BY `program` HAVING `total` > ?
   )
   ```

This deliberately differs from Laravel, whose aggregate keeps `LIMIT` on the outer query, so `count()` ignores it and returns only the first group's count after `groupBy()`.

### Writes

`update()` and `delete()` compile to single-table statements. They keep conditions, ordering, and limit, which MySQL supports on single-table writes. They throw when the query has joins, an explicit selection, `distinct()`, `groupBy()`, `having()`, `union()`, or `offset()`, because no single-table write can honor them.

Insert and upsert reject accumulated conditions, explicit projections, joins, distinct, grouping, having, ordering, limits, and offsets, including for empty input. They must not silently discard query intent. Begin a fresh query for these operations.

## Semantics

Implemented behavior becomes a 2.x compatibility commitment at release. Methods outside the first slice remain design candidates.

| Call | Behavior |
| --- | --- |
| `where( 'col', $value )` | Equality |
| `where( 'col', $operator, $value )` | Operator from a closed list: `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`. Anything else throws at the call |
| `where( 'col', null )` or `'='`, `null` | `IS NULL` |
| `where( 'col', '!=', null )` or `'<>'` | `IS NOT NULL` |
| `where( 'col', '>', null )` and other ordering operators | Throws, naming `whereNull()` and `whereNotNull()` |
| `whereIn( 'col', [] )` | Matches nothing; `whereNotIn( 'col', [] )` matches everything |
| `whereContains`, `whereStartsWith`, `whereEndsWith`, `whereContainsAny`, `whereContainsAll` | Needle wildcards escaped; an empty needle list matches nothing for `Any` and everything for `All` |
| `whereLike()` | Laravel semantics: the value is a pattern, and escaping it is the caller's responsibility |
| Identifiers | Column and table arguments accept `name`, `qualifier.name`, `qualifier.*`, and `name as alias`, where each part matches `[A-Za-z_][A-Za-z0-9_]*`. Every part is quoted. Anything else throws, so a request-supplied sort column cannot inject SQL |
| `bool` values | Bound as integers. A PHP `false` bound as a string becomes `''`, which strict MySQL rejects for integer columns |
| `DateTimeInterface` values | Formatted as `Y-m-d H:i:s.u`. A column with less precision than the value adjusts it: MySQL rounds unless `TIME_TRUNCATE_FRACTIONAL` is set, so a `DATETIME(0)` column may round up a second, while MariaDB truncates |
| Decimals | Pass decimal strings. Floats are bound through PHP's float-to-string conversion, which cannot represent every decimal exactly; the docs say to use strings for money and other exact values |
| `null` values | SQL `NULL` |
| `limit()`, `offset()` | Validated non-negative integers rendered as literals |
| Affected-row counts | `int\|string`, as DBAL and the current `Table` return |
| `update()`, `delete()` without conditions | Throw. This guards against an omitted filter; it does not prove a write is bounded, because `whereNotIn( 'id', [] )` explicitly matches everything. Full-table deletes use `Table::deleteAll()`; `truncate()` remains separate because it resets identity and is not transactional |
| `insert( [] )`, `upsert( [] )` | Return 0 without querying |
| Multi-row `insert()` and `upsert()` | Every row must have the same column set, in any order; columns are normalized to one order, and a mismatch throws before any SQL |
| Large multi-row writes | Split into statements under MySQL's 65,535-placeholder limit. Each statement is atomic; the set is not. A failure in a later statement leaves earlier ones committed unless the caller wraps the write in `transactional()`, and the docs say so beside the method |
| `upsert()` | See below |

### Upsert

`upsert()` compiles to one `INSERT ... ON DUPLICATE KEY UPDATE` per statement and follows native MySQL and MariaDB semantics: a row conflicts when it matches any existing primary or unique key, and the database, not the caller, chooses which. With a primary key `id` and a unique `email`, a row whose `id` already exists updates that row even if its `email` is new.

Laravel 13's signature is `upsert( $values, $uniqueBy, $update = null )`. Its MySQL grammar never reads `$uniqueBy`, yet Laravel 13 throws when it is empty; with `$update` omitted it updates every column, including the unique ones.

Accepted Foundation signature: `upsert( array $values, array $update )`. There is no conflict-target parameter, because none of the supported engines honors one, and `$update` is required, because updating every column including keys is rarely intended. This is a deliberate break from Laravel parity (decision 3). A true conflict-target guarantee, such as checking the target key before writing, would be a separate design, not a validation added here.

`VALUES( col )` in the update clause works on MySQL 5.7 through 8.4 and every supported MariaDB version; MySQL 8.0.20+ deprecates it in favor of row aliases, which MariaDB does not support. That difference is the first concrete case for the dialect strategy.

## Naming boundary

Joins and the `Database` entry point make WordPress core tables ordinary targets. The builder distinguishes three kinds of name, chosen by how the name enters, never inferred from the string:

| Kind | How it enters | Resolution |
| --- | --- | --- |
| Application table | A `Table` object, or an unprefixed name such as `'your_plugin_orders as o'` | `TableNameResolver` with the current `DatabaseScope`, exactly as today |
| WordPress core table | A `TableReference` from `$database->wordpress( 'users' )` | WordPress's own `$wpdb` table properties, which apply the site or network prefix and honor `CUSTOM_USER_TABLE` and `CUSTOM_USER_META_TABLE` |
| Resolved physical name | Carried inside a `TableReference` | Validated and quoted, never re-prefixed |

`wordpress()` returns a `TableReference`: an immutable value holding the resolved physical name, with `as( string $alias )` returning a copy carrying an alias. It is not a query. It resolves when called, against the current site, which matches the existing rule that built queries keep the names resolved when they were built. Unknown core names throw.

`Database::table()` accepts an application `Table`, an unprefixed application name, or a `TableReference`. `join()` also accepts a `Table`, an unprefixed application name, or a `TableReference`. Pass a table object directly; its `name()` is already physical and must not be passed back as an unprefixed string.

Querying a core table:

```php
$drafts = $this->database->table( $this->database->wordpress( 'posts' ) )
	->where( 'post_status', 'draft' )
	->count();
```

Joining application and core tables:

```php
$orders = $this->database->table( 'your_plugin_orders as o' )
	->join( $this->database->wordpress( 'users' )->as( 'u' ), 'u.ID', '=', 'o.user_id' )
	->leftJoin( 'your_plugin_refunds as r', static fn ( JoinClause $join ) => $join
		->on( 'r.order_id', '=', 'o.id' )
		->where( 'r.status', 'approved' ) )
	->select( 'o.id', 'o.total', 'u.user_email', 'r.amount as refunded' )
	->where( 'o.status', 'paid' )
	->orderBy( 'o.created_at', 'desc' )
	->get();
```

On a multisite subsite, `wordpress( 'users' )` resolves to the network's shared users table, or to `CUSTOM_USER_TABLE` when defined, while the two application tables resolve with the subsite's prefix.

## Method reference

This comparison is a design backlog, not the implemented API or a promise of full Laravel compatibility. Only the first-slice table below is authorized for the current implementation. Laravel's names and argument order inform candidate APIs; Foundation's documented semantics govern its own methods. `stellarwp/db` contributes vocabulary only; none of its implementation is reused.

Status meanings:

- **Core**: candidate for later expansion; only the first-slice table identifies current scope.
- **Later**: deferred candidate requiring its own design and verification.
- **Excluded**: deliberately not offered, with the reason.

### Entry points

| Laravel | `stellarwp/db` | Foundation | Status |
| --- | --- | --- | --- |
| `DB::table( 'posts' )` | `DB::table( 'posts', 'alias' )` | `$database->table( 'your_plugin_reports as r' )` for application tables; `$database->table( $database->wordpress( 'posts' ) )` for core tables | Core |
| Model or repository query | none | `$table->query( 'alias' )` | Core, existing |
| `from()` | `from( $table, $alias )` | `from()` | Core |
| `fromSub()`, `fromRaw()` | none | Same names | Later |

`Database` is an ordinary injected object, not a static facade.

### Selecting

| Laravel | `stellarwp/db` | Status |
| --- | --- | --- |
| `select()`, `addSelect()`, `distinct()` | `select()`, `distinct()` | Core |
| `selectRaw( $sql, $bindings )` | `selectRaw( $sql, ...$args )` | Core, raw policy |
| `selectSub( $query, $as )` | none | Later |

### Conditions

Every method has Laravel's `or` twin.

| Laravel | `stellarwp/db` | Status |
| --- | --- | --- |
| `where()` with a value, an operator and value, a Closure, or an array | `where( $column, $value, $operator )` with the operator last | Core, Laravel's order |
| `whereNot( Closure )` | none | Core |
| `whereIn()`, `whereNotIn()` | same | Core; subquery form Later |
| `whereNull()`, `whereNotNull()` | `whereIsNull()`, `whereIsNotNull()` | Core, Laravel names |
| `whereBetween( $column, $bounds )`, `whereNotBetween()` | min and max as separate arguments | Core, Laravel's array form |
| `whereBetweenColumns()`, `whereNotBetweenColumns()`, `whereColumn()` | none | Core |
| `whereLike( $column, $pattern, $caseSensitive = false )`, `whereNotLike()` | `whereLike()`, which wraps the value in `%` unless it contains one | Core, Laravel semantics |
| none | none | Foundation additions, Core: `whereContains()`, `whereStartsWith()`, `whereEndsWith()`, `whereContainsAny()`, `whereContainsAll()`, escaped |
| `whereAny()`, `whereAll()`, `whereNone()` | none | Core |
| `whereExists()`, `whereNotExists()` | same | Core |
| `whereRaw( $sql, $bindings )` | `whereRaw( $sql, ...$args )` | Core, raw policy |
| `whereDate()`, `whereYear()`, `whereMonth()`, `whereDay()`, `whereTime()` | none | Core |
| `whereBinary()`, `whereNotBinary()` | none | Later. Laravel 13; byte-exact comparison regardless of collation |
| `whereValueBetween()`, `whereNullSafeEquals()`, `whereRowValues()` | none | Later |
| `whereJsonContains()` and the JSON family | none | Later. MariaDB stores JSON as `LONGTEXT`; needs per-engine tests |
| `whereFullText()` | none | Later. Requires a full-text index |
| `wherePast()`, `whereToday()`, and the relative-date family | none | Excluded for now. Depends on the WordPress site timezone versus UTC storage |
| `whereIntegerInRaw()` | none | Excluded. A Laravel performance workaround |
| `whereVector...()` | none | Excluded. PostgreSQL only |
| Dynamic `whereEmail()` | none | Excluded. Defeats autocompletion |

### Joins

| Laravel | `stellarwp/db` | Status |
| --- | --- | --- |
| `join( 'posts as p', 'p.ID', '=', 'o.post_id' )` | `innerJoin( $table, $column1, $column2, $alias )` | Core, Laravel's shape |
| `leftJoin()`, `rightJoin()`, `crossJoin()` | `leftJoin()`, `rightJoin()` | Core |
| `join( $table, Closure )` with `on()`, `orOn()`, `where()` | `join( Closure )` | Core |
| `joinWhere()`, `leftJoinWhere()`, `rightJoinWhere()` | none | Core |
| `straightJoin()`, `straightJoinWhere()`, `straightJoinSub()` | none | Later. Laravel 13; MySQL optimizer join-order hint |
| `joinSub()`, `leftJoinSub()`, `rightJoinSub()`, `crossJoinSub()` | none | Later. Derived-table reports |
| none | `joinRaw( $sql, ...$args )` | Later, raw policy |
| `joinLateral()` | none | Excluded. MySQL 8.0.14+ only |

### WordPress meta

| Laravel | `stellarwp/db` | Status |
| --- | --- | --- |
| none | `attachMeta()`, `configureMetaTable()` | Later. Pivot meta keys into columns through joins, with a freshly designed API |

### Grouping

| Laravel | `stellarwp/db` | Status |
| --- | --- | --- |
| `groupBy()`, `groupByRaw()` | `groupBy()` | Core; raw form under the raw policy |
| `having()`, `havingNull()`, `havingNotNull()`, `havingBetween()`, `havingNotBetween()` | `having()` | Core |
| `havingRaw( $sql, $bindings )` | `havingRaw()` | Core, raw policy |
| none | `havingCount()`, `havingSum()`, `havingAvg()`, `havingMin()`, `havingMax()` | Excluded. Written as `selectRaw()` plus `having()` |
| `groupLimit()` | none | Excluded. Needs window functions, absent in MySQL 5.7 |

### Ordering and paging

| Laravel | `stellarwp/db` | Status |
| --- | --- | --- |
| `orderBy()`, `orderByDesc()`, `latest()`, `oldest()`, `reorder()`, `reorderDesc()`, `inRandomOrder()` | `orderBy()` | Core |
| `orderByRaw()` | none | Core, raw policy |
| `limit()`, `take()`, `offset()`, `skip()`, `forPage()`, `forPageAfterId()`, `forPageBeforeId()` | `limit()`, `offset()` | Core |
| `useIndex()`, `forceIndex()`, `ignoreIndex()` | none | Later |
| `orderByVectorDistance()` | none | Excluded |

### Unions

| Laravel | `stellarwp/db` | Status |
| --- | --- | --- |
| `union()`, `unionAll()` | same | Core |

### Reading results

| Laravel | `stellarwp/db` | Status |
| --- | --- | --- |
| `get()`, all rows | `getAll()`; its `get()` returns one row | Core, Laravel meaning |
| `first()`, `firstOrFail()`, `sole()`, `find( $id )` | `get()` | Core |
| `value()`, `soleValue()`, `pluck()`, `implode()` | none | Core |
| `exists()`, `doesntExist()`, `existsOr()`, `doesntExistOr()` | none | Core |
| `count()`, `min()`, `max()`, `sum()`, `avg()`, `average()`, `aggregate()` | same five aggregates | Core, with Foundation's composition rules |
| `chunk()`, `chunkById()`, `chunkByIdDesc()`, `each()`, `eachById()` | none | Core |
| `lazy()`, `lazyById()`, `lazyByIdDesc()` | none | Core. Each issues bounded, separate queries |
| `cursor()` | none | Excluded. DBAL's mysqli driver buffers every result with `store_result()`, so a single-query generator saves no memory; `lazyById()` is the bounded alternative |
| `paginate()`, `simplePaginate()`, `cursorPaginate()` | none | Excluded. Laravel's paginators depend on its request and URL generation |
| `fetchUsing()` | none | Excluded. Laravel 13; PDO fetch modes |

### Writing

| Laravel | `stellarwp/db` | Status |
| --- | --- | --- |
| `insert( $row )` or `insert( $rows )` | `insert()` through `wpdb::insert()` | Core, Laravel's forms, returning affected rows |
| `insertGetId()`, `insertOrIgnore()`, `insertUsing()`, `insertOrIgnoreUsing()` | none | Core |
| `upsert()` | `upsert( $data, $match )`, a read then a write | Core, Foundation signature; see Upsert |
| `update()`, `increment()`, `decrement()`, `incrementEach()`, `decrementEach()` | `update()` through `wpdb::update()` | Core |
| `delete()` | `delete()` | Core |
| Laravel has none | none | `Table::deleteAll()`, existing: the explicit, transactional full-table delete |
| `truncate()` | none | Core, existing on `Table` |
| `insertOrIgnoreReturning()` | none | Excluded. Laravel 13; MySQL has no `RETURNING` |
| `updateOrInsert()` | none | Excluded. A non-atomic read then write |
| `updateFrom()` | none | Excluded. PostgreSQL only |

### Locking

| Laravel | `stellarwp/db` | Status |
| --- | --- | --- |
| `lockForUpdate()`, `sharedLock()`, `lock()` | none | Later. Meaningful only inside a managed transaction |

### Composition and inspection

| Laravel | `stellarwp/db` | Status |
| --- | --- | --- |
| `when()`, `unless()`, `tap()`, `pipe()` | none | Core |
| `clone()`, `cloneWithout()`, `newQuery()` | none | Core |
| `toSql()`, `toRawSql()`, `getBindings()` | `getSQL()` | Core; `toRawSql()` for debugging only |
| `dump()`, `dd()`, `dumpRawSql()`, `ddRawSql()` | none | Excluded. Log `toRawSql()` through the application's logger |
| `raw()` expressions, `macro()`, `beforeQuery()`, `afterQuery()`, `timeout()`, `useWritePdo()` | none | Excluded |

### Raw SQL policy

The first slice implements `selectRaw()` only. The future candidates are `whereRaw()`, `havingRaw()`, `orderByRaw()`, and `groupByRaw()`. They keep Laravel's `( string $sql, array $bindings = [] )` signature, every value goes through `$bindings`, and the docs present them after the safe alternatives. Laravel's `DB::raw()` expression objects stay excluded.

## Differences from Laravel

- Results are plain PHP arrays, not `Collection` objects, keeping `illuminate/support` and its global helpers out of public plugins.
- Identifiers are validated and always quoted.
- Aggregates and `exists()` follow the composition rules above: queries with row-shaping state are wrapped whole, rather than aggregated in the outer query as Laravel does.
- `upsert()` has no conflict-target argument and requires the update column list.
- `update()` and `delete()` without conditions throw; `deleteAll()` is the explicit full-table delete.
- `cursor()` is excluded because the underlying driver buffers results.

## Migrating from `stellarwp/db`

These calls keep a familiar name but change meaning:

| `stellarwp/db` call | Meaning there | Foundation equivalent |
| --- | --- | --- |
| `get()` | First row | `first()` |
| `getAll()` | All rows | `get()` |
| `whereLike( 'col', 'term' )` | Contains `term` | `whereContains( 'col', 'term' )` |
| `where( 'col', $value, '>' )` | Operator last | `where( 'col', '>', $value )` |
| `leftJoin( $table, $a, $b, $alias )` | Implicit `=` | `leftJoin( "{$table} as {$alias}", $a, '=', $b )` |
| `whereIsNull( 'col' )` | | `whereNull( 'col' )` |
| `havingCount( 'id', '>', 1 )` | | `selectRaw( 'COUNT(id) as total' )->having( 'total', '>', 1 )` |
| `upsert( $row, $match )` | Read, then write by `$match` | `upsert( $rows, $update )`, which matches by the table's own keys |
| `DB::table( 'posts' )` | Static facade, site prefix | `$this->database->table( $this->database->wordpress( 'posts' ) )` |

## Decisions before the first slice

Accepted decisions for the first slice; no further confirmation is required.

1. **Row shape: associative arrays.** They match DBAL and the current `Table`, need no conversion, and give array-shape docblocks something to describe. The `stellarwp/db` replacement needs a migration guide regardless, because `get()` changes meaning, so object rows would not remove that work.
2. **Write counts:** `insert()` and `upsert()` return integer totals rather than Laravel's insertion `bool`. Update/delete counts retain `int|string`. Generated IDs from `insertGetId()` retain `int|string` independently of affected-row counts.
3. **Upsert: `upsert( $rows, $update )`.** No conflict-target argument, because no supported engine honors one; the update list is required.
4. **Core tables: `$database->wordpress( $name )` returning a `TableReference`**, accepted by `table()` and `join()`.
5. **Chunked writes: split automatically**, with the caller-owned transaction boundary documented beside `insert()` and `upsert()`.

## Scope

The first slice is the current delivery scope. The broader method reference is a backlog, not a required parity project. Excluded methods remain excluded unless a demonstrated requirement changes the decision.

## First slice

The slice is small enough to review as complete classes, and wide enough to exercise every architectural boundary:

| Area | Methods |
| --- | --- |
| Entry | `$database->table()` with an application `Table`, an unprefixed name, and a `TableReference`; `wordpress()` for `posts` and `users` |
| Filtering | `where()` in all forms, nested closures, `orWhere()`, `whereIn()`, `whereNull()`, `whereContainsAny()` |
| Joins | `join()` against a core table, and `leftJoin()` with a closure `ON` clause |
| Shaping | `select()`, `selectRaw()` with bindings, `distinct()`, `groupBy()`, `having()`, `orderBy()`, `limit()`, `offset()` |
| Reading | `get()`, `first()`, `count()`, `max()`, `exists()` |
| Inspection | `toSql()`, `getBindings()` |
| Writing | single-row and multi-row `insert()`, single-row `insertGetId()`, `update()`, `delete()`, `upsert()` |

Unions, subqueries, chunking methods, and the rest of the method reference wait for expansion after review.

Implement this slice directly in `src/Database/Query` for unreleased Foundation 2.0 and return it from `Table::query()`. The design artifact remains under `dev/Experiments/Query`; it is not the runtime package location. Update existing consumers and compatibility tests directly, without an adapter preserving the abandoned native-builder return type.

Verification:

1. Port the portal's `Entry_Repository` as the consumer fixture, including its transactional replacement.
2. Unit-test the compiler's SQL and binding order, using Laravel 13's query-builder tests as the reference for grouping and null handling, and the composition rules as the specification where Foundation differs.
3. Test the composition combinations the slice implements: aggregates and `exists()` on a plain query, on a join, with `orderBy()` plus `limit()`, with `offset()`, with `distinct()`, and with `groupBy()` plus a `having()` on a selected alias. Include `max()` over an ordered, limited query with an explicit selected alias, an ungrouped raw aggregate over an empty source, resetting raw projection state through `select()`, and an unavailable aggregate output column. SQL reports missing outputs from opaque raw projections.
4. Integration-test on MariaDB and MySQL: escaped needles containing `%`, `_`, and `!`; empty lists; boolean binding under strict mode; fractional-second dates; a multi-row insert that fails in a later chunk, inside and outside a transaction; an upsert conflicting on the primary key while naming a new unique value; and a subsite join against `users`, including a `CUSTOM_USER_TABLE` install.
5. Test the architecture hypothesis: implement `get()` with conditions and one join a second time over Doctrine's `QueryBuilder`, and compare the two in the review.
6. Review the complete classes and repository code before expanding toward the method reference.

The PHP 7.4 lane is deferred until the separate Rector downgrade work; it is not a Foundation 2.0 release requirement.

## Changes this requires elsewhere

- **AGENTS.md Database section:** establish the Foundation builder as the ordinary query API and retain Doctrine for the shared connection, execution, schema tools, and advanced native operations.
- **Table surface:** `query()` changes its return type while keeping its alias argument, table insertion delegates to the fluent implementation with the same single-row/bulk semantics and value normalization. Both entry points expose `insertGetId()` for exactly one insertion, including defaults-only rows; explicit insert types use the native connection. `upsert()` lives on `Query`. `count()`, `deleteAll()`, and `truncate()` keep their current meaning. All of this lands before the 2.0 tag.
- **Docs:** `components/database/query-builder.mdx` rewritten around the new API.
- **Migrations:** unaffected; it uses the connection and schema tools directly.
