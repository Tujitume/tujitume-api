#!/bin/bash
set -e

echo "===== CodeDeploy Cleanup Started ====="

DEPLOY_ROOT="/opt/codedeploy-agent/deployment-root"

# Process each deployment group
find "$DEPLOY_ROOT" \
    -mindepth 1 -maxdepth 1 \
    -type d \
    ! -name "deployment-logs" \
    ! -name "deployment-instructions" \
    ! -name "ongoing-deployment" \
| while read group; do

    echo "Checking deployment group: $group"

    # Keep newest 2 deployment revisions (d-*)
    find "$group" \
        -mindepth 1 -maxdepth 1 \
        -type d \
        -name "d-*" \
        -printf "%T@ %p\n" \
    | sort -nr \
    | tail -n +3 \
    | cut -d' ' -f2- \
    | while read revision; do
        echo "Removing old revision: $revision"
        rm -rf "$revision"
    done

done

echo "Cleaning apt cache..."
apt-get clean

echo "Cleaning old journal logs..."
journalctl --vacuum-size=100M || true

echo "===== Cleanup Complete ====="