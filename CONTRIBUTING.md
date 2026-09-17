# Contributing

Open an issue or pull request at https://github.com/trafficops-io/tops-events.
Use PHP 8.4+ and Composer 2, run `composer install` at the repository root, then `composer check`.
Keep changes in the appropriate `packages/` module and include a regression test for changed behavior.
Run `composer format` when PHP formatting changes are needed. Document public API changes in the module README.
The modules are released together; edit the root Composer manifest for dependencies and autoloading.
Avoid application-specific service bindings, credentials or private data in examples and fixtures.
See the root README for PostgreSQL tests and the maintainer release procedure.
