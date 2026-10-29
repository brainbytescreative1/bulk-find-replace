=== Bulk Find Replace ===
Requires at least: 5.6
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPL-2.0-or-later

Upload a CSV of find/replace pairs and run them across your database, with a dry-run preview first.

== Description ==

Tools > Bulk Find Replace.

1. Upload a CSV with two columns: find, replace. A "find,replace" header row is optional. Comma, semicolon and tab delimiters are detected. See sample.csv.
2. Pick the tables (all site tables are selected by default) and options.
3. Run a dry run. It lists, per table, how many rows and cells would change, with before/after examples. Nothing is written.
4. Run the replacements for real. The button unlocks only after a dry run, and by default only goes through the tables where the dry run found changes. Untick the option under the buttons to scan every selected table again.

Details:

* Serialized data (widgets, options, post meta, page builder data) is unpacked, replaced and re-serialized so string lengths stay valid, including serialized data nested inside serialized data. Objects whose class is not loaded are left untouched and reported.
* "Match whole URLs only" is on by default. A find value only matches when it isn't the start or end of a longer URL, so /houston replaces links to /houston without touching /houston/ or /houston/car-accident-lawyer/. Query strings (?utm=...) and anchors (#top) after a match are kept. Turn it off to replace plain text anywhere.
* Pairs run in CSV order, top to bottom, on each value. The upload screen warns when one pair's replacement contains a later pair's find text, and about duplicate rows.
* The guid column is skipped by default. Any other columns can be skipped too.
* Only text columns (char, varchar, text types) in tables with a single-column primary key are processed. Other tables are listed as skipped.
* Work runs in batches of 250 rows over AJAX, so large sites don't hit PHP timeouts.
* Each uploaded CSV can be run live once, to avoid applying the same replacements twice by accident.
* Object cache is flushed after a live run.
* Restricted to administrators (super admins on multisite).

Always take a database backup before a live run.

== Changelog ==

= 1.3.0 =
* The real run only processes the tables where the dry run found changes, with an option to scan all selected tables instead.
