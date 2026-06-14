#!/usr/bin/env bash
set -e

# Define binary name
BINARY_NAME="audio-edit-cli"
DOCKER_IMAGE="audio-edit-cli-builder"
CONTAINER_NAME="audio-edit-cli-temp"

echo "=== Building standalone FrankenPHP static binary (Linux x86_64) ==="

# Build the docker container
docker build -t "$DOCKER_IMAGE" -f static-build.Dockerfile .

# Clean up any leftover temp container
docker rm -f "$CONTAINER_NAME" 2>/dev/null || true

# Create temporary container to copy the binary out
docker create --name "$CONTAINER_NAME" "$DOCKER_IMAGE"

# Copy the static binary out of the container to the local workspace
echo "Extracting binary..."
docker cp "$CONTAINER_NAME:/go/src/app/dist/frankenphp-linux-x86_64" "./$BINARY_NAME"

# Clean up temp container
docker rm -f "$CONTAINER_NAME"

echo "=== Build Complete ==="
echo "Standalone binary created: ./$BINARY_NAME"
echo "You can run commands using: ./$BINARY_NAME php-cli bin/audio-edit process <files>"
