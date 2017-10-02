# IronMQ Laravel Queue Driver

This package provides a IronMQ (~4.0 SDK) driver for the Laravel queue system and matches the driver that was found in Laravel 5.1.

## Installation
- composer require laravelcollective/iron-queue
- Service Provider registration is done with [Package Auto-Discovery](https://medium.com/@taylorotwell/package-auto-discovery-in-laravel-5-5-ea9e3ab20518)
- Configure your `iron` queue driver in your `config/queue.php` the same as it would have been configured for Laravel 5.1.

Sample Configuration:

```php
'iron' => [
    'driver'  => 'iron',
    'host'    => 'mq-aws-us-east-1-1.iron.io',
    'token'   => 'your-token',
    'project' => 'your-project-id',
    'queue'   => 'your-queue-name',
    'encrypt' => true,
    'timeout' => 60
],
```
