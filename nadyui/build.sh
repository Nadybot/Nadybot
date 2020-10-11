#!/bin/bash
EXECUTABLE=$(which docker || which podman)
$EXECUTABLE build -t nadyui:latest -v $(pwd):/build/:Z --no-cache .
$EXECUTABLE rmi -f nadyui:latest
