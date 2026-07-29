# Ruleset authoring

Each PHP file in this directory returns one complete published ruleset payload. The main
`config/hakoniwa.php` file lists authoring files explicitly; do not replace that list with
a filesystem glob because publication order must be reviewable and deterministic.

To make a balance change:

1. Copy the latest version file to a new, unique version key.
2. Edit values in the new file, preserving every unchanged key, unit, and catalog reference.
3. Run `php artisan hakoniwa:ruleset:validate --key=<new-key>`.
4. After review, publish the validated payload as a new immutable snapshot.
5. Move a World to that snapshot only through a separate, explicit operation.
6. Never overwrite an existing version file or published snapshot.

Add the new path explicitly to `config/hakoniwa.php`. Reusing a key is accepted only when
the entire saved snapshot and its definitions still match; duplicate authoring keys and
drift fail closed. Authoring validation does not publish, update a World, or apply a
ruleset. A World switch/apply command is intentionally not provided here.
