#!/bin/bash
cd "$(dirname "$0")" || exit 1
PESAN="${1:-Update $(date '+%Y-%m-%d %H:%M')}"
git add -A
if git diff --cached --quiet; then
  echo "Tidak ada perubahan untuk di-commit."
else
  git commit -m "$PESAN" || exit 1
fi
git branch -M main
git push -u origin main && echo "Berhasil push ke origin/main" || echo "Push gagal. Cek login GitHub."
