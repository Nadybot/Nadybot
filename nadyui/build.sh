#!/bin/bash
EXECUTABLE=$(which podman 2>/dev/null || echo "Podman not found" && exit 1)
$EXECUTABLE build -t nadyui:latest -v $(pwd):/build/:Z --no-cache .
$EXECUTABLE rmi -f nadyui:latest
