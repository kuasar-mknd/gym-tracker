---
name: ci-verified-ship
description: Runs the full CI suite (Pint, PHPStan, Insights, Pest, Dusk) before committing and pushing changes. Use when the user wants to finalize a task and ensures code quality meets project standards.
---

# CI Verified Ship

This skill automates the verification process before pushing changes to the repository. It ensures that all local quality gates pass, matching the GitHub Actions requirements.

## Workflow

When triggered by a request to commit or push changes, follow this exact sequence:

1.  **Code Style**: Run `vendor/bin/sail bin pint --dirty` to fix any formatting issues in modified files.
2.  **Static Analysis**: Run `vendor/bin/sail bin phpstan analyse --memory-limit=2G` to ensure type safety.
3.  **Architecture & Quality**: Run `vendor/bin/sail artisan insights --no-interaction --min-quality=100 --min-complexity=100 --min-architecture=100 --min-style=90`.
4.  **Feature & Unit Tests**: Run `vendor/bin/sail artisan test --compact`.
5.  **Browser Tests (E2E)**: Run `vendor/bin/sail artisan dusk`.

### Success Path
If ALL steps pass successfully:
- Stage all changes: `git add .`
- Prompt for a commit message or use one provided by the user.
- Commit: `git commit -m "..."`
- Push: `git push`

### Failure Path
If ANY step fails:
- Stop the process immediately.
- Report the specific failure(s) to the user.
- DO NOT commit or push until the issues are resolved.

## Usage
Triggers: "ship it", "commit and push", "finalize and push", "run ci then commit".
