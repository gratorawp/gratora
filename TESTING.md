# Running the tests

## One-time setup

```
composer install
composer test:setup dono_tests root '' 127.0.0.1 latest
```

`test:setup` downloads WordPress and its test library into
`~/.dono-wp-tests`, then creates the test database. Every Dono repo on the
machine finds it there, so you only do this once.

It needs a MySQL/MariaDB you can create databases on. The arguments are
`<db-name> <db-user> <db-pass> [db-host] [wp-version]`, and it **drops and
recreates `<db-name>`** on every run, so give it a database you do not care
about. Point `WP_TESTS_DIR` somewhere else if you want the library elsewhere.

## Running

```
composer test:unit          # no WordPress, milliseconds
composer test:integration   # boots WordPress, hits a real database
composer test                # everything
```

The suites never touch your Local site or its database: they run against the
`wptests_` prefix in the test database created above.

## When it will not start

The bootstrap prints the exact command if the test library is missing. If it
cannot connect, check the DB host and credentials you passed to `test:setup`;
on a Mac using Local's bundled MySQL you will need a socket host, and on CI
`127.0.0.1` is usually right.
