#!/bin/bash
EXECUTABLE=$(which docker 2>/dev/null || which podman 2>/dev/null || echo "Docker or Podman not found" && exit 1)
$EXECUTABLE build -t nadyui:latest -v $(pwd):/build/:Z --no-cache .
$EXECUTABLE rmi -f nadyui:latest
