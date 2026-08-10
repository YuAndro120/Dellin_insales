#!/usr/bin/env bash
# Тянет актуальный main в корень репозитория на сервере (git fetch + reset
# --hard). Предполагает, что рабочая директория — полный git-клон этого
# репозитория (не только public/, как при ручной первой заливке).
#
# Запуск: вручную (`bash deploy/pull-deploy.sh`) или из POST /deploy/webhook
# (см. public/index.php). .env и var/ — untracked (см. .gitignore),
# reset --hard их не трогает: он затрагивает только файлы, отслеживаемые git.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BRANCH="${DEPLOY_BRANCH:-main}"
LOG_FILE="${DEPLOY_LOG_FILE:-$REPO_DIR/var/deploy.log}"

cd "$REPO_DIR"

{
  echo "=== $(date '+%Y-%m-%d %H:%M:%S') deploy start (branch: ${BRANCH}) ==="
  git fetch origin "${BRANCH}"
  git reset --hard "origin/${BRANCH}"
  echo "commit: $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"
  echo "=== $(date '+%Y-%m-%d %H:%M:%S') deploy done ==="
} >> "$LOG_FILE" 2>&1

echo "DEPLOY_OK $(git rev-parse --short HEAD)"
