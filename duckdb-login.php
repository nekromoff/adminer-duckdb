<?php
namespace Adminer;

/**
 * Login-form helper for the DuckDB driver.
 *
 * DuckDB is file-based like SQLite: the "Server" field is really a database-file
 * path and username/password are ignored. Adminer already hides the Server row
 * for SQLite — but it does so in COMPILED JAVASCRIPT with a hard-coded test:
 *
 *     // adminer/static/editing.js
 *     function loginDriver(driver) {
 *         const disabled = /sqlite/.test(selectValue(driver));  // only "sqlite"
 *         alterClass(trs[1], 'hidden', disabled);               // hides Server row
 *         ...
 *     }
 *
 * Because "duckdb" doesn't match `/sqlite/`, that logic never fires for us, and
 * no `loginFormField()` / `credentials()` PHP hook can change it — the hiding is
 * client-side. So this plugin injects a small script on the login page that
 * re-applies the same show/hide behaviour for the `duckdb` driver and relabels
 * the file field. It reuses Adminer's own global JS helpers (parentTag,
 * selectValue, alterClass), which are loaded before the form renders.
 *
 * Must extend Adminer\Plugin: Adminer only auto-instantiates a plugin whose
 * unqualified class name starts with "Adminer" OR which subclasses
 * Adminer\Plugin. This file is namespaced `Adminer`, so get_declared_classes()
 * reports "Adminer\AdminerDuckDbLogin" and the namespace separator breaks the
 * name match — the subclass check is what registers us.
 *
 * Drop this file next to `duckdb-driver.php` in `adminer-plugins/`.
 */
class AdminerDuckDbLogin extends Plugin {
	/** Runs inside <head> on every page, including the login page. */
	function head($dark = null) {
		echo "<script" . nonce() . ">
(function () {
	function duckdbLogin() {
		var sel = document.querySelector('select[name=\"auth[driver]\"]');
		if (!sel) return;
		var table = sel.closest('table');
		if (!table) return;
		var isDuck = sel.value === 'duckdb';

		var rows = table.rows;
		// Row order in Adminer's login form: 0 System, 1 Server, 2 Username, 3 Password, 4 Database.
		var serverRow = rows[1], userRow = rows[2], passRow = rows[3];

		if (isDuck) {
			// Relabel Server -> Database file and give it a useful placeholder.
			var th = serverRow.cells[0];
			th.textContent = 'Database file';
			var input = serverRow.getElementsByTagName('input')[0];
			if (input) {
				input.placeholder = '/path/to/data.duckdb  or  :memory:';
				input.title = 'Path to a DuckDB database file, or :memory: for a temporary in-memory database. Username and password are ignored.';
				input.disabled = false;
			}
			// Username/Password are meaningless for DuckDB: disable them.
			[userRow, passRow].forEach(function (r) {
				if (!r) return;
				var i = r.getElementsByTagName('input')[0];
				if (i) { i.disabled = true; i.placeholder = 'not used by DuckDB'; }
			});
		} else {
			// Restore defaults when switching away from DuckDB.
			if (serverRow.cells[0].textContent === 'Database file') {
				serverRow.cells[0].textContent = 'Server';
				var si = serverRow.getElementsByTagName('input')[0];
				if (si) { si.placeholder = 'localhost'; si.title = ''; }
			}
			[userRow, passRow].forEach(function (r) {
				if (!r) return;
				var i = r.getElementsByTagName('input')[0];
				if (i) { i.disabled = false; i.placeholder = ''; }
			});
		}
	}

	function bind() {
		var sel = document.querySelector('select[name=\"auth[driver]\"]');
		if (sel) {
			sel.addEventListener('change', duckdbLogin);
			duckdbLogin(); // apply on initial load
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bind);
	} else {
		bind();
	}
})();
</script>\n";
		return false; // don't suppress Adminer's own head output
	}

	/**
	 * Permit passwordless login for DuckDB.
	 *
	 * DuckDB is file-based and ignores username/password, so the Server field
	 * carries a file path and the password is blank. Adminer otherwise refuses
	 * an empty password ("accessing a database without a password"). Returning
	 * true here authorises the login — but only for the duckdb driver, so other
	 * drivers keep Adminer's normal password requirement.
	 */
	function login($login, $password) {
		$driver = $_POST["auth"]["driver"] ?? (defined("Adminer\\DRIVER") ? DRIVER : "");
		if ($driver === "duckdb") {
			return true;
		}
		return null; // defer to Adminer's default handling for other drivers
	}
}
