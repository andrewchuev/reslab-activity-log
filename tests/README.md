# Running the test suite

Integration tests against a real WordPress + MySQL install (via `wp-phpunit/wp-phpunit`), run inside the project's own `php` container.

## One-time setup

1. Create a dedicated test database (never point this at the site's real DB — the suite installs/truncates tables):

   ```
   docker compose exec db mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e \
     "CREATE DATABASE IF NOT EXISTS reslab_al_wp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      GRANT ALL PRIVILEGES ON reslab_al_wp_test.* TO '$MYSQL_USER'@'%'; FLUSH PRIVILEGES;"
   ```

2. Install Composer and the dev dependencies inside the `php` container:

   ```
   docker compose exec php sh -c "curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer"
   docker compose exec -w /var/www/html/wp-content/plugins/reslab-activity-log php composer install
   ```

## Running

```
docker compose exec -w /var/www/html/wp-content/plugins/reslab-activity-log php vendor/bin/phpunit
```

`tests/wp-tests-config.php` reads DB credentials from the `MYSQL_USER`/`MYSQL_PASSWORD` env vars the `php` container already gets from the stack's `.env` (via `env_file:` in docker-compose.yml), and always targets the `reslab_al_wp_test` database — never the real site DB.

## Notes

- `tests/Test_Tracker_Autosave_Guard.php` shells out to `tests/isolated/check-autosave-guard.php` as its own PHP process instead of using PHPUnit's `@runInSeparateProcess`: `DOING_AUTOSAVE` is a real, one-way-definable PHP constant, and PHPUnit's process isolation can't serialize WordPress's closure-based hook callbacks across the fork.
- WooCommerce is loaded the same way it would be as an active plugin (see `tests/bootstrap.php`), so `tests/Test_Tracker_WooCommerce.php` exercises the real WooCommerce CRUD API rather than mocks.
