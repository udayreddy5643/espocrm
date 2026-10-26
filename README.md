# EspoCRM Enterprise Extension — Cynaris Internship Project

[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-Level%208-brightgreen)](https://github.com/udayreddy5643/espocrm)
[![PHPUnit 11](https://img.shields.io/badge/PHPUnit-11.5%20(40%20passed)-blue)](tests/)
[![Docker](https://img.shields.io/badge/Docker-Production%20Ready-2496ED?logo=docker&logoColor=white)](deploy/)
[![License: AGPLv3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](LICENSE.txt)

> **Internship Track**: Full Stack Development (EspoCRM Fork & Enterprise Extension)  
> **Author**: Uday Reddy  
> **Repository**: [udayreddy5643/espocrm](https://github.com/udayreddy5643/espocrm)  
> **Base Version**: EspoCRM 10.0.10 (PHP 8.4 / 8.5, MariaDB 10.11 / 11, Nginx)

---

## 🌟 Executive Summary & Features Added

This repository is an enterprise-grade extension of EspoCRM developed during the Cynaris Full Stack Internship. It enhances core CRM capabilities with automated AI conversation summaries, executive dashboard analytics, intelligent multi-channel reminder automation, per-user personalization preferences, and production containerization.

### Key Capabilities Built

| Feature Module | Scope & Architecture | Impact & Innovation |
| :--- | :--- | :--- |
| **1. AI Conversation Summarizer** | Background Job (`ConversationSummarizer.php`) + Event Hook (`ActivityConversationSummary.php`) | Automatically parses the 5 latest interactions (Calls, Meetings, Tasks, Emails) and maintains a concise 3-bullet AI summary on Account records via Groq with OpenRouter fallback. |
| **2. Activities per Account Dashboard** | REST API (`ActivitySummary.php`) + Dashlet (`ActivitySummary.json`) | Interactive 3-series bar chart visualizing team activities per Account over the last 30 days. Uses strict ACL and pre-ranked memory query optimization reducing database I/O by 98%. |
| **3. Smart Reminders Rule Engine** | Heuristic Engine (`RuleEngine.php`) + Service (`Service.php`) | Evaluates start lead times, event urgency/priority, and preparation keywords (deck, demo, review, prep, contract) to calculate multi-tier advance alerts (Popup & Email). |
| **4. AI Timing Suggester** | LLM Suggester (`AiSuggester.php`) + Controller (`SmartReminder.php`) | Analyzes meeting agendas and subject context to recommend optimal notification timing with zero-downtime fallback to deterministic rules. |
| **5. User Preference Controls** | Metadata (`Preferences.json`) + Hook Integration (`SmartReminderHook.php`) | Granular user settings (`smartReminderEnabled`, `smartReminderMode` [ai/rules/disabled], `smartReminderPreparationBuffer`) dynamically honored during entity saves and API requests. |
| **6. Interactive Frontend View** | AMD View (`custom:views/meeting/fields/smart-reminders`) | Custom Backbone.js field view for `Meeting`, `Call`, and `Task` featuring a **"Smart Timing"** button, dynamic loading state, source badge (`AI Suggested` vs `Rule Heuristic`), and automatic form populate. |
| **7. Production Deployment Suite** | `deploy/Dockerfile`, `docker-compose.prod.yml`, `railway.json`, `db-check.mjs` | Multi-stage production container with OPcache tuning, Supervisord process manager, automated database readiness checks, and Railway cloud deployment spec. |

---

## 🛠️ Technology Stack

* **Backend**: PHP 8.4 / 8.5 (Strict typing, PSR-4 autoloading, Dependency Injection, EspoCRM ORM)
* **Frontend**: Vanilla JavaScript (ES6+, AMD / RequireJS, Backbone.js, Marionette.js)
* **Styling & Layout**: EspoCRM Theme System, CSS3, FontAwesome 5
* **Database**: MariaDB 10.11 / MySQL 8.0, Node.js `mysql2/promise`
* **AI Providers**: Groq API (`openai/gpt-oss-120b`), OpenRouter API (Fallback), Zero-Leak Error Handling
* **Containerization**: Docker, Docker Compose, Alpine Linux, Nginx, PHP-FPM, Supervisord
* **Testing & Quality**: PHPUnit 11.5, PHPStan Level 8, Custom Docker Test Harness

---

## 🚀 Quick Start & Local Setup

### Prerequisites
* [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows / Linux / macOS)
* Git

### 1. Clone Your Fork
```bash
git clone https://github.com/udayreddy5643/espocrm.git
cd espocrm
```

### 2. Configure Environment Variables
Create a local `.env` file or export environment variables:
```bash
# CRM Database Configuration
ESPOCRM_DATABASE_HOST=espocrm-mariadb
ESPOCRM_DATABASE_NAME=espocrm
ESPOCRM_DATABASE_USER=espocrm
ESPOCRM_DATABASE_PASSWORD=espocrm_dev_password
ESPOCRM_SITE_URL=http://localhost:8080

# AI Provider Keys (Optional - gracefully falls back to rules if unset)
GROQ_API_KEY=your_groq_api_key_here
GROQ_MODEL=openai/gpt-oss-120b
OPENROUTER_API_KEY=your_openrouter_api_key_here
```

### 3. Launch Development Environment
```bash
# Start Docker containers
docker-compose up -d

# Clear cache and rebuild metadata
docker exec espocrm-php php /var/www/html/command.php rebuild
```

### 4. Access the CRM
* **Web UI**: Open [http://localhost:8080](http://localhost:8080)
* **Default Admin**: Log in or complete initial installer wizard.

---

## 🧪 Running Automated Tests

All unit tests are isolated and executable in Docker without altering host dependencies:

```bash
# Execute the full test suite (40 tests, 159 assertions)
docker run --rm -v "${PWD}:/app" --volumes-from espocrm-php -w /app php85-nginx-mariadb-espocrm-php php /var/www/html/phpunit.phar --bootstrap tests/bootstrap-unit.php tests/unit/Espo/Custom/
```

### Test Coverage Highlights
* `RuleEngineTest`: Immediate notice (<10m), short notice (10-60m), same-day notice, high-priority escalation, multi-day advance alerts, keyword buffer detection, and offset rounding clamping.
* `AiSuggesterTest`: Missing key fallback, JSON markdown fence stripping, channel filtering, and lead-time bounds clamping.
* `ServiceTest`: AI routing, rule fallback routing, explicit AI bypass, and past event skipping.
* `SmartReminderHookTest`: Entity filtering (`Meeting`, `Call`, `Task`), explicit reminder preservation, and automatic injection on save.
* `PreferencesIntegrationTest`: User preference disabling, mode switching, and buffer toggling.
* `ReportTest`: Strict ACL enforcement, empty report handling, deterministic secondary sorting by account name, and candidate pre-ranking query optimization.
* `SmartReminderControllerTest`: REST API parameter validation (400 Bad Request), scope authorization (403 Forbidden), user preference inheritance, and client overrides.

---

## 🚢 Production Deployment

### Option A: Railway Cloud Deployment
1. Connect this GitHub repository to [Railway](https://railway.app).
2. Railway detects [`deploy/railway.json`](deploy/railway.json) and builds using [`deploy/Dockerfile`](deploy/Dockerfile).
3. Add a MariaDB plugin and set the environment variables:
   - `ESPOCRM_DATABASE_HOST`: `${{MariaDB.RAILWAY_TCP_PROXY_DOMAIN}}`
   - `ESPOCRM_DATABASE_NAME`: `${{MariaDB.MYSQLDATABASE}}`
   - `ESPOCRM_DATABASE_USER`: `${{MariaDB.MYSQLUSER}}`
   - `ESPOCRM_DATABASE_PASSWORD`: `${{MariaDB.MYSQLPASSWORD}}`
   - `ESPOCRM_SITE_URL`: `https://${{RAILWAY_STATIC_URL}}`
4. The healthcheck endpoint automatically monitors `/api/v1/App/user`.

### Option B: Production Docker Compose
```bash
docker compose -f deploy/docker-compose.prod.yml up -d
```

---

## 📚 Technical Documentation & Architecture

For detailed architecture diagrams, API contracts, database schema models, and viva responses, refer to:
* **[ARCHITECTURE.md](ARCHITECTURE.md)**: Full system architecture, Mermaid data-flow diagrams, and ORM access control specifications.
* **[docs/week4-day1-smart-reminders.md](docs/week4-day1-smart-reminders.md)**: Automation Rule Engine & AI Suggester architecture.
* **[docs/week4-day2-smart-reminders-ui.md](docs/week4-day2-smart-reminders-ui.md)**: Frontend AMD View and User Preferences design.
* **[docs/week4-day3-debug-sprint.md](docs/week4-day3-debug-sprint.md)**: Query optimization, benchmark results, and root cause analyses.
* **[docs/week4-day4-deployment-architecture.md](docs/week4-day4-deployment-architecture.md)**: Production deployment breakdown and core directory analysis.
* **[docs/week4-day5-final-submission.md](docs/week4-day5-final-submission.md)**: Final PR submission template, video walkthrough script, and checklist.

---

## 📹 Demo Video

* **Video Walkthrough (YouTube / Drive)**: `https://youtu.be/DEMO_VIDEO_PLACEHOLDER` *(Unlisted walkthrough demonstrating environment setup, Activity Dashboard, Smart Reminders UI, AI timing suggestions, and automated test execution)*.

---

## 📄 License & Attribution

This project is licensed under the **GNU Affero General Public License v3.0 (AGPLv3)**. Original codebase copyright © EspoCRM, Inc. All custom features and extensions developed by Uday Reddy under the Cynaris Full Stack Development Internship.
