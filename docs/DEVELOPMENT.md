# Development

```sh
make dev   # the stack with your code mounted, plus the Vite dev server
make exec vendor/bin/phpunit   # any command in the PHP container, quoted when it has options
make sdk   # regenerate the OpenAPI document and the frontend client
```

The backend is built on [Cube](https://github.com/yonis-savary/cube), a typed PHP 8 framework. The architecture, the
decisions behind it and what is planned are described in [ARCHITECTURE.md](../ARCHITECTURE.md) (in French).

| Part | Stack |
|---|---|
| Backend | PHP 8.5, Cube, MySQL |
| Frontend | Vue 3, PrimeVue, a client generated from the OpenAPI document |
| Connector | Python, shipped as a single zipapp |
| Real time | WebSocket |
