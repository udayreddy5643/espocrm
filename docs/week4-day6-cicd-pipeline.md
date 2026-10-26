# Week 4, Day 6: CI/CD Pipeline with GitHub Actions & Multi-Stage Docker

## Overview & Scope

Week 4 Day 6 implements professional **Continuous Integration and Continuous Deployment (CI/CD)** for the EspoCRM enterprise fork:
1. **Automated CI/CD Workflow (`.github/workflows/ci-cd.yml`)**: An automated GitHub Actions pipeline executing across three stages:
   - **Stage 1 (Test)**: Sets up PHP 8.4, caches dependencies, runs static analysis, and executes all 40 unit tests via PHPUnit.
   - **Stage 2 (Build & Push)**: Builds a multi-stage production Docker image using Docker Buildx, caches layers via GitHub Actions cache (`gha`), and publishes tagged images to GitHub Container Registry (`ghcr.io`) and Docker Hub.
   - **Stage 3 (Deploy & Healthcheck)**: Triggers production cloud deployment (Railway / PaaS webhook) upon merge to `main`/`master` and performs a live endpoint healthcheck.
2. **Multi-Stage Docker Architecture (`deploy/Dockerfile`)**: Decouples the build environment (Composer dependency resolution) from the lean runtime container (Alpine Linux + PHP 8.4 FPM + Nginx + Supervisord).
3. **Secrets Management & Zero-Leak Security**: Encrypted secrets configuration for container registries and cloud webhooks, safeguarded by GitHub Environment Protection Rules.
4. **Pipeline Failure Simulation**: Verified pipeline failure semantics ensuring broken commits immediately fail at the `test` stage and never trigger Docker builds or deployments.

---

## CI/CD Pipeline Architecture

```mermaid
graph TD
    Push["Git Push / PR to main"] --> TestJob["Job 1: test (PHPUnit & Static Analysis)"]
    TestJob -->|Pass: 40/40 Tests| BuildJob["Job 2: build-and-push (Docker Buildx)"]
    TestJob -->|Fail: Broken Assertion| FailHalt["Pipeline Halts (Exit 1) - Zero Deployment"]
    BuildJob --> Registry["GitHub Container Registry (ghcr.io) & Docker Hub"]
    Registry --> DeployCheck{"Branch == main / master?"}
    DeployCheck -->|Yes| DeployJob["Job 3: deploy (Railway / Cloud Webhook)"]
    DeployCheck -->|No (Feature Branch)| SkipDeploy["Skip Deployment (Artifacts Preserved)"]
    DeployJob --> Health["Verify Healthcheck (/api/v1/App/user)"]
```

---

## Workflow Anatomy (`.github/workflows/ci-cd.yml`)

### 1. Job 1: `test` (Automated Testing & Quality Gates)
- **Environment**: `ubuntu-latest` running PHP 8.4 via `shivammathur/setup-php@v2`.
- **Caching**: Utilizes `actions/cache@v4` on the `vendor/` directory keyed to `composer.lock` hash to accelerate build runs.
- **Test Execution**:
  ```bash
  vendor/bin/phpunit --bootstrap tests/bootstrap-unit.php tests/unit/Espo/Custom/
  ```
- **Failsafe**: If any assertion fails, the job exits with non-zero status code, immediately canceling downstream build and deployment jobs.

### 2. Job 2: `build-and-push` (Containerization)
- **Dependency**: Requires `test` (`needs: test`).
- **Builder**: `docker/setup-buildx-action@v3`.
- **Publishing**:
  - Automatically authenticates to GitHub Packages (`ghcr.io`) using the scoped ephemeral `GITHUB_TOKEN`.
  - Authenticates to Docker Hub if `DOCKER_USER` and `DOCKER_TOKEN` secrets are provided.
- **Tagging Strategy**:
  - Tags with short commit SHA: `ghcr.io/udayreddy5643/espocrm:sha-301d483`
  - Tags with `latest` on default branch pushes.
- **Build Cache**: Uses `type=gha` (GitHub Actions cache backend) for sub-minute build times.

### 3. Job 3: `deploy` (Production Release)
- **Dependency**: Requires `build-and-push` (`needs: build-and-push`).
- **Condition**: Only fires on pushes to `main` or `master`.
- **Trigger**: Dispatches deployment payload to Railway PaaS via `RAILWAY_DEPLOY_WEBHOOK` or cloud webhook URL.
- **Sanity Verification**: Runs a post-deployment `curl` request validating the `/api/v1/App/user` endpoint returns `401 Unauthorized` (confirming operational routing and security boundaries).

---

## Multi-Stage Dockerfile Analysis (`deploy/Dockerfile`)

A single-stage build carries compilers, package managers, and temporary build caches into production. Our multi-stage build cleanly isolates concerns:

### Stage 1: Build & Dependencies (`builder`)
```dockerfile
FROM composer:2.7 AS builder
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs
```
* **Purpose**: Resolves production PHP packages with an optimized classmap autoloader. Dev packages (PHPUnit, PHPStan) are stripped.

### Stage 2: Lean Production Runtime (`runner`)
```dockerfile
FROM php:8.4-fpm-alpine AS runner
# Installs runtime libraries and compiles required extensions (pdo_mysql, gd, intl, opcache)
COPY --from=builder /build/vendor /var/www/html/vendor
COPY --chown=www-data:www-data . /var/www/html
```
* **Benefits**:
  - **Minimal Attack Surface**: Build headers and dev packages (`.build-deps`) are deleted in the same layer (`apk del .build-deps`).
  - **OPcache Pre-compilation**: PHP files are cached in shared memory with `validate_timestamps=0` for near-instant execution.
  - **Process Management**: Supervisord manages PHP-FPM, Nginx, and background cron workers (`daemon.php`) in a single self-contained container.

---

## Secrets Management & Security Architecture

Credentials and API keys must **never** be committed to version control. The pipeline uses GitHub Encrypted Secrets:

| Secret Name | Purpose | Scope | Recommended Protection |
| :--- | :--- | :--- | :--- |
| `DOCKER_USER` | Docker Hub username | Container Registry Auth | Repository Secret |
| `DOCKER_TOKEN` | Docker Hub Personal Access Token (PAT) | Container Push Permissions | Repository Secret (Read/Write) |
| `RAILWAY_DEPLOY_WEBHOOK` | Cloud deployment webhook trigger | Production PaaS Deploy | Environment Secret (`production`) |
| `PRODUCTION_URL` | Live URL for post-deployment healthcheck | Verification | Environment Secret (`production`) |
| `GROQ_API_KEY` | Runtime AI timing & summary key | Application Config | Runtime Container Environment |

### Environment Protection Rules
To prevent unauthorized production releases:
1. Go to **Settings > Environments > New environment (`production`)**.
2. Enable **Required reviewers**: Require at least one team member to approve production deployments.
3. Restrict deployment branches to `main` and `master`.

---

## Pipeline Failure Simulation (Broken Commit Test)

To prove that the CI/CD pipeline prevents defective code from reaching production, we simulated a broken test scenario:

1. **Failure Injection**:
   Temporarily changed an assertion in `RuleEngineTest.php`:
   ```php
   $this->assertSame(999999, $res['reminders'][0]['seconds']); // Intentional failure
   ```
2. **Pipeline Execution Output**:
   ```text
   Running custom feature test suite...
   PHPUnit 11.5.57 by Sebastian Bergmann and contributors.
   ..................F................... 40 / 40 (100%)
   FAILURES!
   Tests: 40, Assertions: 159, Failures: 1.
   Error: Process completed with exit code 1.
   ```
3. **Pipeline Behavior**:
   - `test` job failed with Exit Code 1.
   - `build-and-push` job status: **Canceled / Skipped** (never triggered).
   - `deploy` job status: **Canceled / Skipped** (never triggered).
   - **Conclusion**: Broken code is prevented from building a Docker image or impacting production.

---

## AI Collaboration Log (CIA)

### Session 1: Full Stack Mentor Mode (CI/CD Pipeline Design)
* **Prompt**: *"What are the industry best practices for containerizing a PHP/EspoCRM application in a CI/CD pipeline with GitHub Actions?"*
* **CIA Guidance**:
  - Separate unit testing from image building: running PHPUnit inside GitHub Actions runner before Docker build is faster than building an image and running tests inside the container.
  - Use GitHub Actions Cache (`type=gha`) for Docker layer caching to prevent re-downloading alpine packages on every commit.
  - Use GitHub Container Registry (`ghcr.io`) as default since `GITHUB_TOKEN` is automatically available with zero setup.

### Session 2: Code Mode (Dockerfile Optimization)
* **Prompt**: *"Analyze `deploy/Dockerfile` for multi-stage efficiency and security."*
* **CIA Recommendations Implemented**:
  - Added explicit multi-stage separation (`composer:2.7 AS builder` and `php:8.4-fpm-alpine AS runner`).
  - Added temporary virtual package grouping (`apk add --virtual .build-deps ... && apk del .build-deps`) to keep final image size under 150 MB.
  - Configured `opcache.validate_timestamps=0` for production execution speed.

---

## Self-Review Checklist

- [x] **Branching**: Created `feat/cynaris-w4d6-uday-b` branched from `feat/cynaris-w4d5-uday-b`.
- [x] **Commit Convention**: Followed `type(scope): description` with minimum 2 distinct commits (`ci(actions)` and `docs(cicd)`).
- [x] **Zero Secrets**: No tokens or passwords hardcoded. All secrets use `${{ secrets.* }}`.
- [x] **Verification**: All 40 PHPUnit unit tests pass locally and in the CI workflow.
- [x] **Workflow Validated**: Workflow syntax passes GitHub Actions schema standards.

---

## Viva Questions & Answers

### Q1: Walk me through your CI/CD pipeline. What happens from `git push` to production deployment?
**Answer**:
When a developer pushes code:
1. **Trigger**: GitHub Actions detects the push event and starts the pipeline defined in `.github/workflows/ci-cd.yml`.
2. **Test Phase (`test`)**: A clean Ubuntu runner spins up, installs PHP 8.4, restores cached Composer dependencies, and executes our 40 automated unit tests via PHPUnit. If any test fails, the job exits with error and the pipeline halts.
3. **Build Phase (`build-and-push`)**: Upon test success, Docker Buildx compiles the multi-stage `deploy/Dockerfile`. Production dependencies are extracted from the `builder` stage, packaged into a lean Alpine runtime, and pushed to `ghcr.io` tagged with the commit SHA and `latest`.
4. **Deploy Phase (`deploy`)**: If the push is on `main`, the deployment job triggers the cloud deployment webhook (e.g. Railway) and validates application availability via an automated HTTP healthcheck.

### Q2: Why is multi-stage Docker building important, and how does it improve security?
**Answer**:
Multi-stage building divides the Docker build into distinct stages (e.g. `builder` and `runner`):
1. **Image Size**: Build tools (Composer, GCC, Git, development headers) are used only in the build stage and discarded. The final runtime container contains only the compiled binaries and production dependencies, reducing image size by up to 70%.
2. **Security Attack Surface**: By excluding compilers, package managers, and development utilities from the production image, attackers have fewer vectors to exploit if a container is breached.
3. **Layer Caching**: Code changes do not invalidate earlier dependency layers, making rebuilds significantly faster.

### Q3: How do GitHub Encrypted Secrets and Environment Protection Rules ensure safe deployments?
**Answer**:
- **Encrypted Secrets**: Credentials (API keys, container registry tokens, deploy webhooks) are encrypted at rest using libsodium before being stored in GitHub. They are masked in build logs and injected only as ephemeral environment variables during workflow execution.
- **Environment Protection Rules**: By binding sensitive secrets to a specific GitHub Environment (`production`), workflows cannot access deployment credentials without meeting predefined criteria, such as requiring manual peer approval or restricting execution exclusively to protected default branches (`main`/`master`).
