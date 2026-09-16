<p align="center">
    <a href="https://doppar.com" target="_blank">
        <img src="https://raw.githubusercontent.com/doppar/doppar/7138fb0e72cd55256769be6947df3ac48c300700/public/logo.png" width="400">
    </a>
</p>

<p align="center">
<a href="https://github.com/doppar/orion/actions/workflows/tests.yml"><img src="https://github.com/doppar/orion/actions/workflows/tests.yml/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/doppar/orion"><img src="https://img.shields.io/packagist/dt/doppar/orion" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/doppar/orion"><img src="https://img.shields.io/packagist/v/doppar/orion" alt="Latest Stable Version"></a>
<a href="https://github.com/doppar/orion/blob/main/LICENSE"><img src="https://img.shields.io/github/license/doppar/orion" alt="License"></a>
</p>

## About Doppar Orion

Doppar Processes provides a powerful and expressive abstraction for running and managing system-level commands and scripts from within your PHP application. Built on top of the Symfony Process Component, it gives you a fluent interface for executing commands, handling their output, managing timeouts, and controlling processes without having to work directly with the underlying process APIs.

Processes supports both synchronous and asynchronous execution, allowing you to choose whether your application should wait for a command to finish or continue working while a long-running process executes. Output can be captured, streamed in real time, or disabled entirely when it is not needed, giving you control over both process behavior and resource usage.

For applications that need to execute multiple commands, Doppar provides command pipelines and concurrent process execution. Pipelines allow the output of one command to flow into the next, while process pools make it possible to run multiple independent commands in parallel with configurable concurrency limits. Asynchronous processes can also be monitored while running, allowing applications to inspect incremental output, detect conditions, and enforce execution timeouts.

## Contributing

Thank you for considering contributing to the Doppar framework! The contribution guide can be found in the [Doppar documentation](https://doppar.com/versions/4.x/contributions).

## Code of Conduct

In order to ensure that the Doppar community is welcoming to all, please review and abide by the [Code of Conduct](https://doppar.com/versions/4.x/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/doppar/framework/security/policy) on how to report security vulnerabilities.

## License

The Doppar framework is open-sourced software licensed under the [MIT license](LICENSE.md).
