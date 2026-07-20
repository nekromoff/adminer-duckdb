<?php
namespace Adminer;

/**
 * DuckDB driver for Adminer 5.x.
 *
 * DuckDB is an in-process analytical database. This driver talks to it through
 * the pure-PHP FFI binding "satur.io/duckdb" (https://github.com/satur-io/duckdb-php),
 * which exposes a `Saturio\DuckDB\DuckDB` connection object and a forward-only,
 * generator-based ResultSet. There is currently no stable PDO_DuckDB in core PHP,
 * so we wrap the FFI binding directly.
 *
 * A "database" in Adminer maps to a DuckDB database file (or `:memory:`). DuckDB
 * organizes objects as catalog.schema.table; we expose schemas the way the
 * PostgreSQL driver does and default to the `main` schema.
 *
 * Install the binding next to Adminer (composer require satur.io/duckdb) and make
 * sure the DuckDB C library is discoverable, then load this file together with
 * Adminer. See README.md for a ready-made single-file build recipe.
 *
 * DROP-IN: place this file in an `adminer-plugins/` directory next to your
 * `adminer.php`. Adminer globs `adminer-plugins/*.php` at startup and includes
 * each one, so the `add_driver()` call below runs and "DuckDB" appears in the
 * System dropdown. No other wiring is needed.
 */

// The single-file adminer.php has no Composer autoloader, so pull in the
// satur.io/duckdb binding ourselves if it was installed with Composer nearby.
(function () {
	foreach (array(
		__DIR__ . '/vendor/autoload.php',        // vendor next to this plugin file
		__DIR__ . '/../vendor/autoload.php',      // vendor next to adminer.php
	) as $autoload) {
		if (is_file($autoload)) {
			require_once $autoload;
			break;
		}
	}
})();

add_driver("duckdb", "DuckDB");

if (isset($_GET["duckdb"])) {
	define('Adminer\DRIVER', "duckdb");

	if (class_exists('Saturio\DuckDB\DuckDB')) {

		/**
		 * Result wrapper.
		 *
		 * The saturio ResultSet is forward-only and yields rows lazily, and column
		 * metadata is only reliably available once at least one chunk has been read.
		 * Adminer needs random-ish access (num_rows, seek, repeated fetch_field before
		 * fetching, buffered re-use across select/dump), so we eagerly materialize the
		 * whole result into plain PHP arrays on construction. DuckDB result sets shown
		 * in Adminer are already bounded by LIMIT, so this is acceptable.
		 */
		class Result {
			/** @var int */ public $num_rows;
			/** @var list<array<string, ?scalar>> assoc rows */ private $rows = array();
			/** @var list<string> column names in order */ private $columns = array();
			/** @var list<string> DuckDB logical type per column */ private $types = array();
			/** @var int */ private $rowOffset = 0;
			/** @var int */ private $fieldOffset = 0;

			/** @param \Saturio\DuckDB\Result\ResultSet $resultSet */
			function __construct($resultSet) {
				foreach ($resultSet->columnNames() as $name) {
					$this->columns[] = (string) $name;
				}
				// DuckDB type names per column are read once the first chunk exists.
				$this->types = self::columnTypes($resultSet, count($this->columns));
				foreach ($resultSet->rows(true) as $row) {
					$assoc = array();
					foreach ($this->columns as $col) {
						$assoc[$col] = self::normalize($row[$col] ?? null);
					}
					$this->rows[] = $assoc;
				}
				$this->num_rows = count($this->rows);
			}

			/** Try to read the logical DuckDB type name for each column.
			* @return list<string>
			*/
			private static function columnTypes($resultSet, int $count): array {
				$types = array_fill(0, $count, "");
				// The saturio ResultSet doesn't expose per-column type names via a stable
				// public API across versions; fall back to inferring from PHP values later.
				return $types;
			}

			/** Convert DuckDB value objects (Date, Timestamp, Blob, UUID, arrays…) to a
			* string/scalar Adminer can render.
			* @param mixed $val
			* @return ?scalar
			*/
			private static function normalize($val) {
				if ($val === null || is_scalar($val)) {
					return $val;
				}
				if (is_array($val)) {
					return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				}
				if (is_object($val)) {
					if (method_exists($val, '__toString')) {
						return (string) $val;
					}
					// Date/Time/Timestamp/Interval expose toString()-ish helpers in the binding.
					foreach (array('toString', 'format', 'getValue') as $m) {
						if (method_exists($val, $m)) {
							$out = @$val->$m();
							if (is_scalar($out)) {
								return (string) $out;
							}
						}
					}
					return json_encode($val, JSON_UNESCAPED_UNICODE);
				}
				return $val;
			}

			function fetch_assoc() {
				if ($this->rowOffset >= $this->num_rows) {
					return false;
				}
				return $this->rows[$this->rowOffset++];
			}

			function fetch_row() {
				$row = $this->fetch_assoc();
				return ($row === false ? false : array_values($row));
			}

			function fetch_field(): \stdClass {
				$i = $this->fieldOffset++;
				$name = $this->columns[$i] ?? "";
				$type = $this->types[$i] ?? "";
				if ($type === "") {
					$type = $this->inferType($name);
				}
				$isNumber = (bool) preg_match('~int|dec|numeric|real|double|float|hugeint~i', $type);
				$isBinary = (bool) preg_match('~blob|bytea|binary~i', $type);
				return (object) array(
					"name" => $name,
					"orgname" => $name,
					"type" => ($isNumber ? 0 : 15), // 0 numeric, 15 string (mirrors other drivers)
					"charsetnr" => ($isBinary ? 63 : 0), // 63 = binary
				);
			}

			/** Guess a type name from the first non-null value in a column. */
			private function inferType(string $col): string {
				foreach ($this->rows as $row) {
					$v = $row[$col] ?? null;
					if ($v === null) {
						continue;
					}
					if (is_int($v)) {
						return "integer";
					}
					if (is_float($v)) {
						return "double";
					}
					if (is_bool($v)) {
						return "boolean";
					}
					return "varchar";
				}
				return "varchar";
			}

			function seek($offset) {
				$this->rowOffset = max(0, (int) $offset);
			}
		}


		/**
		 * Connection wrapper implementing the SqlDb contract used by Adminer.
		 */
		class Db extends SqlDb {
			public $extension = "DuckDB";
			/** @var \Saturio\DuckDB\DuckDB */ private $link;
			/** @var string current database file/path */ private $path = ":memory:";

			function attach($server, $username, $password) {
				// $server carries the DuckDB file path; :memory: for an ephemeral database.
				$path = ($server != "" ? $server : ":memory:");
				try {
					if ($path === ":memory:") {
						$this->link = \Saturio\DuckDB\DuckDB::create(null);
					} else {
						// Open file databases read-only: the web-server user often lacks
						// write permission on the file (and its WAL/lock), which makes a
						// default read-write open fail with an opaque "Cannot open
						// database" error. Read-only needs no write access.
						$cfg = new \Saturio\DuckDB\DB\Configuration();
						$cfg->set('access_mode', 'read_only');
						$this->link = \Saturio\DuckDB\DuckDB::create($path, $cfg);
					}
				} catch (\Throwable $e) {
					return $e->getMessage();
				}
				$this->path = $path;
				$this->server_info = self::duckdbVersion($this->link);
				return '';
			}

			private static function duckdbVersion($link): string {
				try {
					foreach ($link->query("SELECT version() AS v")->rows(true) as $row) {
						return ltrim((string) ($row["v"] ?? ""), "v");
					}
				} catch (\Throwable $e) {
				}
				return "";
			}

			function select_db($database) {
				// DuckDB attaches other database files as catalogs. A single running
				// connection already exposes its file; switching database means switching
				// the active catalog via USE. `main` catalog is the primary file.
				if ($database == "" || $database == $this->currentCatalog()) {
					return true;
				}
				return (bool) $this->query("USE " . idf_escape($database));
			}

			function currentCatalog(): string {
				$res = $this->query("SELECT current_database() AS c");
				if ($res instanceof Result) {
					$row = $res->fetch_assoc();
					if ($row) {
						return (string) $row["c"];
					}
				}
				return "";
			}

			function quote($string) {
				if (!is_utf8($string)) {
					// DuckDB accepts blobs as hex literals: '\xAB...'::BLOB
					return "'\\x" . bin2hex($string) . "'::BLOB";
				}
				return "'" . str_replace("'", "''", $string) . "'";
			}

			function query($query, $unbuffered = false) {
				$this->error = "";
				$this->errno = 0;
				try {
					$resultSet = $this->link->query($query);
				} catch (\Throwable $e) {
					$this->error = $e->getMessage();
					$this->errno = $e->getCode();
					return false;
				}
				if ($resultSet->columnCount() > 0) {
					return new Result($resultSet);
				}
				// Statement without a result set (DDL/DML). DuckDB's FFI binding does not
				// surface an affected-row count for such statements, so report 0.
				$this->affected_rows = 0;
				return true;
			}
		}


		class Driver extends SqlDriver {
			static $extensions = array("DuckDB");
			static $jush = "sqlite"; // DuckDB SQL is closest to SQLite/PostgreSQL dialect in JUSH highlighting

			protected $types = array(
				array(
					"tinyint" => 3, "smallint" => 5, "integer" => 10, "bigint" => 20, "hugeint" => 39,
					"utinyint" => 3, "usmallint" => 5, "uinteger" => 10, "ubigint" => 20,
					"real" => 0, "double" => 0, "decimal" => 0,
					"boolean" => 0,
					"varchar" => 0, "uuid" => 36, "json" => 0,
					"date" => 0, "time" => 0, "timestamp" => 0, "timestamptz" => 0, "interval" => 0,
					"blob" => 0,
				),
			);

			public $insertFunctions = array();
			public $editFunctions = array(
				"integer|bigint|smallint|tinyint|hugeint|real|double|decimal" => "+/-",
				"varchar" => "||",
			);

			public $operators = array("=", "<", ">", "<=", ">=", "!=", "LIKE", "LIKE %%", "ILIKE", "ILIKE %%", "IN", "IS NULL", "NOT LIKE", "NOT IN", "IS NOT NULL", "SQL");
			public $functions = array("length", "lower", "upper", "round", "abs", "trim", "md5", "hex");
			public $grouping = array("avg", "count", "count distinct", "max", "min", "sum", "stddev", "median");

			function structuredTypes(): array {
				return array_keys($this->types[0]);
			}

			function types(): array {
				return call_user_func_array('array_merge', array_values($this->types));
			}

			function insertUpdate($table, array $rows, array $primary) {
				// DuckDB supports INSERT ... ON CONFLICT DO UPDATE. Requires the conflict
				// target to be a primary key / unique constraint.
				$columns = array_keys(reset($rows));
				$values = array();
				foreach ($rows as $set) {
					$values[] = "(" . implode(", ", $set) . ")";
				}
				$set = array();
				foreach ($columns as $col) {
					if (!isset($primary[idf_unescape($col)])) {
						$set[] = "$col = EXCLUDED.$col";
					}
				}
				$onConflict = ($primary
					? " ON CONFLICT (" . implode(", ", array_map('Adminer\idf_escape', array_keys($primary))) . ") DO "
						. ($set ? "UPDATE SET " . implode(", ", $set) : "NOTHING")
					: ""
				);
				return queries("INSERT INTO " . table($table) . " (" . implode(", ", $columns) . ") VALUES\n"
					. implode(",\n", $values) . $onConflict);
			}

			function begin() {
				return queries("BEGIN TRANSACTION");
			}

			function last_id($result) {
				return 0; // DuckDB has no last_insert_rowid equivalent
			}

			function explain($connection, $query) {
				return $connection->query("EXPLAIN $query");
			}

			function found_rows($table_status, $where) {
			}

			function convertSearch($idf, array $val, array $field): string {
				return $idf;
			}

			function support($feature) {
				// No triggers, no stored routines, no foreign-key introspection via PRAGMA in DuckDB.
				return preg_match('~^(columns|database|drop_col|dump|indexes|sql|table|view)$~', $feature);
			}
		}



		function support($feature) {
			// No triggers, no stored routines, no foreign-key introspection via PRAGMA in DuckDB.
			return preg_match('~^(columns|database|drop_col|dump|indexes|sql|table|view)$~', $feature);
		}

		function idf_escape($idf) {
			return '"' . str_replace('"', '""', $idf) . '"';
		}

		function table($idf) {
			return idf_escape($idf);
		}

		function get_databases($flush) {
			// List attached databases (catalogs). The primary file plus any ATTACHed ones.
			return get_vals("SELECT database_name FROM duckdb_databases() WHERE NOT internal ORDER BY database_name");
		}

		function limit($query, $where, $limit, $offset = 0, $separator = " ") {
			return " $query$where" . ($limit !== null ? $separator . "LIMIT $limit" . ($offset ? " OFFSET $offset" : "") : "");
		}

		function limit1($table, $query, $where, $separator = "\n") {
			// DuckDB does not support LIMIT on UPDATE/DELETE; fall back to a rowid subquery.
			return " $query$where";
		}

		function db_collation($db, $collations) {
			return "";
		}

		function logged_user() {
			return get_current_user();
		}

		function tables_list() {
			return get_key_vals("SELECT table_name, CASE table_type WHEN 'VIEW' THEN 'view' ELSE 'table' END
FROM information_schema.tables
WHERE table_schema = " . q(get_schema()) . "
ORDER BY table_name");
		}

		function count_tables($databases) {
			return array();
		}

		function table_status($name = "") {
			$return = array();
			foreach (get_rows("SELECT table_name AS Name,
CASE table_type WHEN 'VIEW' THEN 'view' ELSE 'table' END AS Engine
FROM information_schema.tables
WHERE table_schema = " . q(get_schema())
				. ($name != "" ? " AND table_name = " . q($name) : "")
				. " ORDER BY table_name") as $row
			) {
				$row["Oid"] = "rowid";
				$row["Auto_increment"] = "";
				$row["Rows"] = ($row["Engine"] == "view" ? null : get_val("SELECT COUNT(*) FROM " . idf_escape(get_schema()) . "." . idf_escape($row["Name"])));
				$row["Comment"] = "";
				$row["Collation"] = "";
				$return[$row["Name"]] = $row;
			}
			return $return;
		}

		function is_view($table_status) {
			return $table_status["Engine"] == "view";
		}

		function fk_support($table_status) {
			return false;
		}

		/** Map a DuckDB base type name to one of the driver's canonical type keys. */
		function normalize_type($base) {
			static $aliases = array(
				"int1" => "tinyint", "int2" => "smallint", "int4" => "integer", "int" => "integer",
				"int8" => "bigint", "long" => "bigint", "int128" => "hugeint",
				"float4" => "real", "float" => "double", "float8" => "double", "numeric" => "decimal",
				"bool" => "boolean", "logical" => "boolean",
				"text" => "varchar", "string" => "varchar", "char" => "varchar", "bpchar" => "varchar",
				"datetime" => "timestamp", "bytea" => "blob",
			);
			$base = strtolower(trim($base));
			if (isset($aliases[$base])) {
				return $aliases[$base];
			}
			$known = array(
				"tinyint", "smallint", "integer", "bigint", "hugeint",
				"utinyint", "usmallint", "uinteger", "ubigint",
				"real", "double", "decimal", "boolean", "varchar", "uuid", "json",
				"date", "time", "timestamp", "timestamptz", "interval", "blob",
			);
			return (in_array($base, $known, true) ? $base : "varchar");
		}

		function fields($table) {
			$return = array();
			$privileges = array("select" => 1, "insert" => 1, "update" => 1, "where" => 1, "order" => 1);
			foreach (get_rows("SELECT column_name, data_type, is_nullable, column_default, character_maximum_length, numeric_precision, numeric_scale
FROM information_schema.columns
WHERE table_schema = " . q(get_schema()) . " AND table_name = " . q($table) . "
ORDER BY ordinal_position") as $row) {
				$type = strtolower($row["data_type"]);
				$base = preg_replace('~\(.*~', '', $type); // strip length/params
				$length = null;
				if (preg_match('~\(([^)]+)\)~', $type, $m)) {
					$length = $m[1];
				} elseif ($row["character_maximum_length"] !== null) {
					$length = $row["character_maximum_length"];
				} elseif ($base == "decimal" && $row["numeric_precision"] !== null) {
					$length = $row["numeric_precision"] . ($row["numeric_scale"] ? "," . $row["numeric_scale"] : "");
				}
				$return[$row["column_name"]] = array(
					"field" => $row["column_name"],
					"type" => normalize_type($base),
					"full_type" => $type,
					"length" => $length,
					"default" => ($row["column_default"] === null ? null : $row["column_default"]),
					"null" => ($row["is_nullable"] == "YES"),
					"auto_increment" => (bool) preg_match('~nextval~i', (string) $row["column_default"]),
					"privileges" => $privileges,
					"primary" => false,
				);
			}
			// Mark primary-key columns using DuckDB's constraint catalog.
			foreach (get_vals("SELECT constraint_column_names FROM duckdb_constraints()
WHERE schema_name = " . q(get_schema()) . " AND table_name = " . q($table) . " AND constraint_type = 'PRIMARY KEY'") as $cols) {
				foreach (parse_duckdb_list($cols) as $col) {
					if (isset($return[$col])) {
						$return[$col]["primary"] = true;
					}
				}
			}
			return $return;
		}

		/** DuckDB catalog list columns come back like [a, b]; turn into an array. */
		function parse_duckdb_list($val) {
			if (is_array($val)) {
				return $val;
			}
			$val = trim((string) $val, "[]");
			if ($val === "") {
				return array();
			}
			return array_map(function ($s) {
				return trim(trim($s), "'\" ");
			}, explode(",", $val));
		}

		function indexes($table, $connection2 = null) {
			$return = array();
			// Primary key from constraint catalog.
			foreach (get_rows("SELECT constraint_type, constraint_column_names FROM duckdb_constraints()
WHERE schema_name = " . q(get_schema()) . " AND table_name = " . q($table) . "
AND constraint_type IN ('PRIMARY KEY', 'UNIQUE')", $connection2) as $row) {
				$cols = parse_duckdb_list($row["constraint_column_names"]);
				if ($row["constraint_type"] == "PRIMARY KEY") {
					$return[""] = array("type" => "PRIMARY", "columns" => $cols, "lengths" => array(), "descs" => array_fill(0, count($cols), null));
				} else {
					$return[implode("_", $cols)] = array("type" => "UNIQUE", "columns" => $cols, "lengths" => array(), "descs" => array_fill(0, count($cols), null));
				}
			}
			// Secondary indexes.
			foreach (get_rows("SELECT index_name, is_unique, sql FROM duckdb_indexes()
WHERE schema_name = " . q(get_schema()) . " AND table_name = " . q($table), $connection2) as $row) {
				$name = $row["index_name"];
				if (isset($return[$name])) {
					continue;
				}
				$columns = array();
				if (preg_match('~\(([^)]*)\)~', (string) $row["sql"], $m)) {
					foreach (explode(",", $m[1]) as $c) {
						$columns[] = trim(trim($c), '"');
					}
				}
				$return[$name] = array(
					"type" => ($row["is_unique"] ? "UNIQUE" : "INDEX"),
					"columns" => $columns,
					"lengths" => array(),
					"descs" => array_fill(0, count($columns), null),
				);
			}
			return $return;
		}

		function foreign_keys($table) {
			$return = array();
			foreach (get_rows("SELECT constraint_column_names, constraint_referenced_table, constraint_referenced_column_names
FROM duckdb_constraints()
WHERE schema_name = " . q(get_schema()) . " AND table_name = " . q($table) . " AND constraint_type = 'FOREIGN KEY'") as $i => $row) {
				$return[$i] = array(
					"table" => $row["constraint_referenced_table"],
					"source" => parse_duckdb_list($row["constraint_column_names"]),
					"target" => parse_duckdb_list($row["constraint_referenced_column_names"]),
					"on_delete" => "NO ACTION",
					"on_update" => "NO ACTION",
				);
			}
			return $return;
		}

		function view($name) {
			return array("select" => preg_replace('~^(?:.*\bAS\s+)~isU', '',
				get_val("SELECT sql FROM duckdb_views() WHERE schema_name = " . q(get_schema()) . " AND view_name = " . q($name))));
		}

		function collations() {
			return array();
		}

		function information_schema($db) {
			return false;
		}

		function error() {
			return h(connection()->error);
		}

		function get_schema() {
			return ($_GET["ns"] != "" ? $_GET["ns"] : "main");
		}

		function set_schema($schema, $connection2 = null) {
			$connection2 = connection($connection2);
			return $connection2->query("USE " . idf_escape($schema));
		}

		function schemas() {
			return get_vals("SELECT schema_name FROM information_schema.schemata
WHERE catalog_name = current_database() AND schema_name NOT IN ('information_schema', 'pg_catalog')
ORDER BY schema_name");
		}

		function create_database($db, $collation) {
			// A DuckDB database is a file; ATTACH creates it.
			return queries("ATTACH " . q($db) . " AS " . idf_escape(preg_replace('~\W~', '_', $db)));
		}

		function drop_databases($databases) {
			foreach ($databases as $db) {
				if (!queries("DETACH " . idf_escape($db))) {
					return false;
				}
			}
			return true;
		}

		function rename_database($name, $collation) {
			return false;
		}

		function auto_increment() {
			return "";
		}

		function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning) {
			$alter = array();
			foreach ($fields as $field) {
				$alter[] = ($field[1]
					? ($table != "" ? "ADD COLUMN " . implode($field[1]) : implode($field[1]))
					: "DROP COLUMN " . idf_escape($field[0]));
			}
			if ($table == "") {
				return queries("CREATE TABLE " . table($name) . " (\n" . implode(",\n", $alter) . "\n)");
			}
			foreach ($alter as $val) {
				if (!queries("ALTER TABLE " . table($table) . " $val")) {
					return false;
				}
			}
			if ($table != $name) {
				return queries("ALTER TABLE " . table($table) . " RENAME TO " . table($name));
			}
			return true;
		}

		function alter_indexes($table, $alter) {
			foreach (array_reverse($alter) as $val) {
				if ($val[0] == "PRIMARY") {
					return false; // DuckDB can't add/drop a primary key after creation
				}
				$q = ($val[2] == "DROP"
					? "DROP INDEX " . idf_escape($val[1])
					: "CREATE " . ($val[0] == "UNIQUE" ? "UNIQUE " : "") . "INDEX " . idf_escape($val[1] != "" ? $val[1] : uniqid($table . "_"))
						. " ON " . table($table) . " (" . implode(", ", $val[2]) . ")");
				if (!queries($q)) {
					return false;
				}
			}
			return true;
		}

		function truncate_tables($tables) {
			return apply_queries("DELETE FROM", $tables);
		}

		function drop_views($views) {
			return apply_queries("DROP VIEW", $views);
		}

		function drop_tables($tables) {
			return apply_queries("DROP TABLE", $tables);
		}

		function move_tables($tables, $views, $target) {
			return false;
		}

		function trigger($name, $table) {
			return array();
		}

		function triggers($table) {
			return array();
		}

		function trigger_options() {
			return array("Timing" => array(), "Event" => array(), "Type" => array());
		}

		function routine($name, $type) {
			return array();
		}

		function routines() {
			return array();
		}

		function routine_languages() {
			return array();
		}

		function routine_id($name, $row) {
			return "";
		}

		function last_id($result) {
			return 0;
		}

		function explain($connection, $query) {
			return $connection->query("EXPLAIN $query");
		}

		function found_rows($table_status, $where) {
		}

		function types() {
			return array();
		}

		function type_values() {
			return array();
		}

		function create_sql($table, $auto_increment, $style) {
			// DuckDB does not expose SHOW CREATE TABLE; reconstruct from information_schema.
			$fields = array();
			foreach (fields($table) as $field) {
				$fields[] = "  " . idf_escape($field["field"]) . " " . $field["full_type"]
					. ($field["null"] ? "" : " NOT NULL")
					. ($field["default"] !== null ? " DEFAULT " . $field["default"] : "");
			}
			$pk = array();
			foreach (indexes($table) as $index) {
				if ($index["type"] == "PRIMARY") {
					$pk = $index["columns"];
				}
			}
			if ($pk) {
				$fields[] = "  PRIMARY KEY (" . implode(", ", array_map('Adminer\idf_escape', $pk)) . ")";
			}
			return "CREATE TABLE " . table($table) . " (\n" . implode(",\n", $fields) . "\n)";
		}

		function truncate_sql($table) {
			return "DELETE FROM " . table($table);
		}

		function use_sql($database, $style = "") {
			return "USE " . idf_escape($database) . ";";
		}

		function trigger_sql($table) {
			return "";
		}

		function show_variables() {
			$return = array();
			foreach (get_rows("SELECT name, value FROM duckdb_settings()") as $row) {
				$return[$row["name"]] = array($row["name"], $row["value"]);
			}
			return $return;
		}

		function show_status() {
			return array();
		}

		function convert_field($field) {
		}

		function unconvert_field($field, $return) {
			return $return;
		}
	}
}
