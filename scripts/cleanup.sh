#!/bin/bash
set -e

echo "Cleaning old CodeDeploy revisions..."

DEPLOY_ROOT="/opt/codedeploy-agent/deployment-root"

# Keep only the 2 newest deployment groups
find "$DEPLOY_ROOT" \
    -mindepth 1 -maxdepth 1 \
    -type d \
    ! -name "deployment-logs" \
    ! -name "deployment-instructions" \
    ! -name "ongoing-deployment" \
    -printf '%T@ %p\n' \
| sort -n \
| head -n -2 \
| cut -d' ' -f2- \
| while read dir; do
    echo "Removing $dir"
    sudo rm -rf "$dir"
done

echo "Cleaning apt cache..."
sudo apt clean

echo "Cleaning journal logs..."
sudo journalctl --vacuum-size=100M

echo "Cleanup complete."