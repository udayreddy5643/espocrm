# Week 3 Day 1: Local Setup Record

- Repository fork: `https://github.com/udayreddy5643/espocrm`
- Lesson branch: `feat/cynaris-w3d1-uday-b`
- Docker development environment: the official `espocrm/espocrm-dev-environment` repository, using `development/php8.5-nginx-mariadb`.
- Local URL after the containers and installer are available: `http://localhost:8080`.

The Docker Engine was unavailable during this setup attempt, so the local application and installer still need to be started and confirmed. Start Docker Desktop, then follow the environment repository's README from the selected environment directory. Do not run `docker compose down --volumes` if you need to preserve local database data.

The lesson's React references do not describe EspoCRM's UI: EspoCRM uses a custom single-page application framework with some TypeScript, and a PHP REST API backend.

References:

- [EspoCRM development Docker environments](https://github.com/espocrm/espocrm-dev-environment)
- [Official Docker installation guide](https://docs.espocrm.com/administration/docker/installation/)
