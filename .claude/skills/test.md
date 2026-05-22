---
name: test
description: Run all tests across admin and service projects, with TDD workflow
---
# Testing (TDD)

Run tests across both admin and service projects.

## Quick run

```bash
# Admin tests (48 tests)
cd admin && php vendor/bin/phpunit -c phpunit.xml

# Service tests (88 tests)
cd service && php vendor/bin/phpunit -c phpunit.xml
```

## TDD workflow

1. **Red** — Write a failing test in the appropriate `tests/` directory
2. **Green** — Write the minimum code to make it pass
3. **Refactor** — Clean up without changing behavior
4. **Verify** — Run the full suite

## Test structure

```
admin/tests/           service/tests/
├── Common/            ├── Common/
│   ├── HashidServiceTest.php   (14 tests)
│   ├── ResponseTest.php        (10 tests)
│   └── SnowflakeTest.php       (6 tests)
├── Notification/      ├── Notification/
│   └── NotificationDispatcherTest.php (5 tests)
├── Payment/           ├── Payment/
│   ├── PaymentRouterTest.php   (5 tests)
│   └── StripeChannelTest.php   (19 tests)
└── Provisioning/      └── Provisioning/
    └── RetryLogicTest.php      (8 tests)
```

## Writing new tests

- Extend `Tests\TestCase` (service) or `PHPUnit\Framework\TestCase` (admin)
- Use `#[DataProvider]` for parameterized tests
- Use Reflection to inject dependencies into static singletons (e.g. HashidsManager)
- bcmath for precise financial assertions: `bcadd()`, `bcmul()`

## Key conventions

- No mocking of database — integration tests hit real DB
- Hashids encoding tested via `hashids_encode()` / `hashids_decode()` helpers
- Snowflake IDs must be unique, monotonic, 64-bit range
- Stripe amounts must handle zero-decimal currencies (JPY, KRW, VND, etc.)
