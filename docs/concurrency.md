# Concurrency

This page describes the `flock()`-based concurrency primitives of `mfonte/secure-image-converter`. See the
[README](../README.md) for an overview.

## Semaphore

Without a semaphore, nothing bounds the number of conversions that run at once. Give the builder a `Semaphore`
shared by every PHP process of the host:

```php
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Concurrency\Semaphore;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;

$locks = new LockDirectory('/run/image-converter/locks');

$converter = (new SecureConverterBuilder())
    ->withSemaphore(new Semaphore($locks, 'convert', 4))
    ->withConverterOptions((new ConverterOptions())->withSlotWaitSeconds(10.0))
    ->build();
```

When no slot frees up within the wait (30 seconds by default), `convert()` throws `ConcurrencyLimitException`
(`concurrency_limit`). Its `getRetryAfterSeconds()` is the wait rounded up to whole seconds, from 1 to 3600, ready for a
`Retry-After` header.

A semaphore has from 1 to 256 slots, each a lock file named `semaphore.<name>.<slot>.lock`. Every process that shares
it must use the same directory, name and number of slots. Each `acquire()` takes its own slot, also inside one
process, and `tryAcquire()` makes a single attempt and returns `null` when every slot is busy.

## KeyedLock

`KeyedLock` gives single-flight work, one holder per key, for example one conversion per output file:

```php
use Mfonte\SecureImageConverter\Concurrency\KeyedLock;

$lock = new KeyedLock($locks, 'outputs');
$lease = $lock->acquire($cacheKey, 10.0); // ConcurrencyLimitException after 10 seconds

try {
    // Check the cache again, then convert.
} finally {
    $lease->release();
}
```

The lock file of a key exists only while the key is held, so files do not accumulate with the number of keys.
`tryAcquire($key)` makes a single attempt.

Both are built on `flock()`. Keys are hashed into lock file names, so any string is a valid key. A lease is released
explicitly, on destruction, or by the kernel when the process dies. Waiting uses exponential backoff with jitter and
is not fair: keep leases short, and take one lease per request.

## Limitations

Limitations shared by both:

- One host, local file systems only (`flock()` on NFS is unreliable), POSIX systems only.
- `LockDirectory` checks every component of its path. Each parent must be owned by root or the effective user and
  not writable by others unless sticky, and the lock directory itself must be private to the effective user.
  `/run/<app>` (for example from systemd's `RuntimeDirectory=`) or `/var/lib/<app>/locks` are good locations.
- Lock files must not be deleted while the application runs: exclude the directory from temporary-file cleaners.
- Locks are not reentrant.
