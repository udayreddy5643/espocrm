# Week 3 Day 1: EspoCRM Architecture Notes

1. **SPA frontend with a PHP REST API backend.** The UI is a single-page application that communicates with a PHP API, so frontend views and backend API/controller code have distinct responsibilities. See `frontend/` and `application/Espo/Core/Api/`.
2. **Dependency injection and interfaces organize backend services.** Core services are designed around interfaces and dependency injection, which helps keep components replaceable and testable. Start with EspoCRM's developer documentation on dependency injection and inspect `application/Espo/Core/`.
3. **Metadata drives extensibility.** Entity and UI behavior is described through metadata, with JSON Schema definitions supporting validation and IDE completion. Inspect `application/Espo/Entities/` and the metadata under `application/Espo/Resources/`.

These notes are based on the project README's Architecture and Development sections and a first pass through the repository layout.

References:

- [EspoCRM README](https://github.com/espocrm/espocrm/blob/master/README.md)
- [EspoCRM developer documentation](https://docs.espocrm.com/development/)
